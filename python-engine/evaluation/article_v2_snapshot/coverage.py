"""Article representations and nonnegative concept coverage.

Concept annotations are extracted from source text only. Evaluation annotations,
article identifiers and reference summaries never enter this module.
"""
from __future__ import annotations
from dataclasses import dataclass, replace
from functools import lru_cache
import json
from pathlib import Path
import re
from time import perf_counter

import numpy as np
from scipy.sparse import csr_matrix, hstack
from sklearn.decomposition import TruncatedSVD
from sklearn.feature_extraction.text import TfidfVectorizer, CountVectorizer, ENGLISH_STOP_WORDS
from sklearn.preprocessing import normalize

from .preprocessing import Sentence, TOKEN, NOISE, HEADINGS, _tokenizer

QUANTITY = re.compile(r"(?<![\w])(?:[$£€]\s*)?\d+(?:,\d{3})*(?:\.\d+)?(?:\s*(?:%|percent|million|billion|participants?|responses?|people|hectares|minutes?|hours?|days?|years?|meters?|liters?))?", re.I)
QUALIFICATION = re.compile(r"\b(?:not|no|neither|cannot|may|might|could|unclear|uncertain|although|however|conditional|unless|if|suggest\w*|according to|said|argue\w*|believe\w*)\b", re.I)
ROLES = {
    "objective": r"\b(?:aim\w*|objective|purpose|examined whether|investigat\w*)\b",
    "method": r"\b(?:sample|participants|responses|survey|randomized|regression|measured|compared|method\w*)\b",
    "finding": r"\b(?:found|findings|result\w*|reported|increased|decreased|reduced|improvement|declined)\b",
    "limitation": r"\b(?:limitation\w*|cannot establish|not significant\w*|no significant|unclear|uncertain|may not|not been|not yet|observational|cross-sectional)\b",
    "consequence": r"\b(?:because|caused|affected|damaged|impact|risk\w*|delay\w*|cost\w*)\b",
    "recommendation": r"\b(?:should|recommend\w*|conclud\w*|therefore|needed|next steps)\b",
    "mechanism": r"\b(?:uses?|works?|mechanism|protocol|architecture|acknowledge|delivery|encryption)\b",
    "event": r"\b(?:announced|opened|closed|launched|approved|officials|on Monday|on Tuesday)\b",
    "attribution": r"\b(?:said|according to|argue\w*|believe\w*|critics|supporters|opponents|suggest\w*)\b",
}
REFERENCE = re.compile(r"^(?:(?:it|they|these|those|such)\b|this\s+(?!(?:study|article|research|experiment|analysis)\b)|however\b|but\b|therefore\b|thus\b|moreover\b|that concern\b)", re.I)
LAYOUT = re.compile(r"\b(?:image|figure|table|photo)\s+(?:above|below)|\b(?:left|right)\s+image\b", re.I)
FOLLOWING_CAVEAT = re.compile(r"\b(?:not statistically significant|not significant|no significant|cannot establish|does not establish|remains unclear|observational|cross-sectional|could explain|may not generalize)\b", re.I)
DEFAULT_PARAMETERS = {
    "version": "coverage-1", "lexical": .25, "topic": .15, "section": .05,
    "role": .10, "quantity": .30, "entity": .05, "qualification": .10,
    "lambda": 1.0, "cost_power": .5, "rank_mix": 0.0,
}


@lru_cache(maxsize=1)
def parameters():
    path = Path(__file__).with_name("coverage_parameters.json")
    if path.is_file():
        value = json.loads(path.read_text(encoding="utf-8"))["selected_parameters"]
        for key in DEFAULT_PARAMETERS:
            if key != "version" and (not isinstance(value.get(key), (int, float)) or not 0 <= value[key] <= 1):
                raise ValueError("Invalid article coverage parameter artifact.")
        return dict(value)
    return dict(DEFAULT_PARAMETERS)


def article_sentences(text):
    """Keep source-exact multiline spans and generic section headings.

    Separate from detector tokenization: detector behavior stays frozen.
    """
    tokenizer, status = _tokenizer()
    records = []
    section, paragraph = "Article", 0
    body_start = body_end = None

    def flush():
        nonlocal paragraph, body_start, body_end
        if body_start is None:
            return
        block = text[body_start:body_end]
        if tokenizer:
            spans = list(tokenizer.span_tokenize(block))
        else:
            from summarizer_core.tokenization import fallback_sentence_split
            spans, cursor = [], 0
            for value in fallback_sentence_split(block):
                match = re.search(r"\s+".join(re.escape(w) for w in value.split()), block[cursor:])
                if match:
                    a, b = cursor + match.start(), cursor + match.end()
                    spans.append((a, b))
                    cursor = b
        for a, b in spans:
            raw = block[a:b]
            value = raw.strip()
            if len(TOKEN.findall(value)) < 2 or not re.search(r"[.!?][\"'’”)]*$", value):
                continue
            a += len(raw) - len(raw.lstrip())
            start = body_start + a
            records.append(Sentence(len(records), value, start, start + len(value), paragraph, section))
        paragraph += 1
        body_start = body_end = None

    for line in re.finditer(r"[^\r\n\f]*(?:\r?\n|\f|$)", text):
        value = line.group().strip()
        if not value or NOISE.fullmatch(value):
            flush()
            continue
        words = value.split()
        title_case = sum(w[:1].isupper() for w in words) >= max(1, len(words) * .65)
        heading = ((bool(HEADINGS.fullmatch(value)) or title_case or value.isupper())
                   and len(words) <= 10 and len(value) <= 100
                   and not re.search(r"[.!?][\"'’”)]*$", value)
                   and not re.match(r"(?:[-*•]|\d+[.)])\s", value))
        if heading:
            flush()
            if records:
                section = value.rstrip(":")
            continue
        if body_start is None:
            body_start = line.start() + len(line.group()) - len(line.group().lstrip())
        body_end = line.end()
    flush()
    return records, status


def _unit(values):
    values = np.asarray(values, dtype=float)
    return values / max(float(values.max(initial=0)), 1e-12)


@dataclass
class Analysis:
    matrix: csr_matrix
    vectorizer: object
    relevance: np.ndarray
    lsa: np.ndarray
    keywords: list
    phrases: list
    entities: list
    status: str
    projections: np.ndarray
    svd: object
    lexical: list
    concept_matrix: csr_matrix
    concept_weights: np.ndarray
    concepts: list
    protected: list
    timings: dict

    def __getitem__(self, index):
        return (self.matrix, self.vectorizer, self.relevance, self.lsa,
                self.keywords, self.phrases, self.entities, self.status)[index]


def analyze(records, *, enhanced=True, use_tfidf=True, use_lsa=True, structural=True,
            config=None):
    started = perf_counter()
    config = dict(config or parameters())
    lexical = [s.text for s in records]
    entities, phrases = [[] for _ in records], []
    status = "disabled_lightweight" if not enhanced else "unavailable_local_model"
    if enhanced:
        from summarizer_core.tokenization import load_optional_spacy_pipeline
        nlp = load_optional_spacy_pipeline()
        if nlp is not None:
            try:
                for i, doc in enumerate(nlp.pipe(lexical, batch_size=32)):
                    lexical[i] = " ".join(t.lemma_ or t.text for t in doc if not t.is_punct and not t.is_space)
                    entities[i] = [{"text": e.text, "label": e.label_} for e in doc.ents]
                    phrases.extend(c.text for c in doc.noun_chunks if len(c.text.split()) <= 6)
                status = "spacy_pos_lemma_ner"
            except Exception:
                lexical, entities, phrases = [s.text for s in records], [[] for _ in records], []
                status = "unavailable_annotation_error"
    annotation_seconds = perf_counter() - started
    t0 = perf_counter()
    vectorizer = (TfidfVectorizer(stop_words="english", ngram_range=(1, 2), max_features=20000,
                                   sublinear_tf=True) if use_tfidf else
                  CountVectorizer(stop_words="english", ngram_range=(1, 2), max_features=20000))
    matrix = csr_matrix(vectorizer.fit_transform(lexical), dtype=float)
    if not use_tfidf:
        matrix = csr_matrix(normalize(matrix))
    center = np.asarray(matrix.mean(axis=0)).ravel()
    relevance = _unit(matrix @ center)
    names = vectorizer.get_feature_names_out()
    keywords = [str(names[i]) for i in np.argsort(-center, kind="stable")[:10]]
    vector_seconds = perf_counter() - t0
    t0 = perf_counter()
    dimension = min(4, matrix.shape[0] - 1, matrix.shape[1] - 1)
    svd = None
    projections = np.empty((len(records), 0))
    if use_lsa and dimension >= 1 and float(np.linalg.norm(center)) < 1 - 1e-10:
        svd = TruncatedSVD(n_components=dimension, random_state=17)
        projections = svd.fit_transform(matrix)
    lsa = _unit(np.linalg.norm(projections, axis=1)) if projections.size else relevance.copy()
    lsa_seconds = perf_counter() - t0
    t0 = perf_counter()
    # Bound the feature universe while retaining each sentence's local themes.
    features = set(np.argsort(-center, kind="stable")[:100].tolist())
    for row in range(len(records)):
        local = matrix.getrow(row)
        order = np.argsort(-local.data, kind="stable")[:6]
        features.update(local.indices[order].tolist())
    features = sorted(features, key=lambda i: (-center[i], i))[:2000]
    blocks, weights, labels = [], [], []

    def add_block(kind, block, importance, local_labels):
        block = csr_matrix(block, dtype=float)
        if not block.shape[1] or not config.get(kind, 0):
            return
        importance = np.maximum(0, np.asarray(importance, dtype=float))
        mass = float(importance.sum())
        if mass <= 0:
            return
        blocks.append(block)
        weights.extend((config[kind] * importance / mass).tolist())
        labels.extend((kind + ":" + str(label)) for label in local_labels)

    lex = matrix[:, features]
    maxima = np.asarray(lex.max(axis=0).toarray()).ravel()
    lex = lex.multiply(1 / np.maximum(maxima, 1e-12))
    add_block("lexical", lex, center[features], names[features])
    if projections.size:
        topics = np.abs(projections)
        topics /= np.maximum(topics.max(axis=0), 1e-12)
        add_block("topic", topics, svd.singular_values_ ** 2, range(topics.shape[1]))
    dictionaries = {name: {} for name in ["section", "role", "quantity", "entity", "qualification"]}
    protected = [set() for _ in records]

    def concept(kind, key, index, importance=1.0):
        dictionary = dictionaries[kind]
        if key not in dictionary:
            dictionary[key] = {"rows": [], "weight": importance}
        dictionary[key]["rows"].append(index)
        dictionary[key]["weight"] = max(dictionary[key]["weight"], importance)

    entity_counts = {}
    for values in entities:
        for key in {e["text"].casefold() for e in values}:
            entity_counts[key] = entity_counts.get(key, 0) + 1
    for i, sentence in enumerate(records):
        roles = [role for role, pattern in ROLES.items() if re.search(pattern, sentence.text, re.I)]
        if structural:
            concept("section", sentence.section.casefold(), i)
            for role in roles:
                concept("role", role, i)
        tokens = [w.lower() for w in TOKEN.findall(sentence.text) if w.lower() not in ENGLISH_STOP_WORDS]
        for match in QUANTITY.finditer(sentence.text):
            if match.start() and sentence.text[match.start()-1] == "[":
                continue
            quantity = re.sub(r"\s+", " ", match.group().casefold())
            # Quantity importance is conditional on explanatory/claim content,
            # not merely the existence of any digit or identifier.
            if roles or relevance[i] >= float(np.median(relevance)):
                concept("quantity", quantity, i, .5 + .5 * relevance[i])
                protected[i].add("quantity:" + quantity)
        if QUALIFICATION.search(sentence.text):
            signature = " ".join(tokens[:10])
            concept("qualification", signature, i, .5 + .5 * relevance[i])
            protected[i].add("qualification:" + signature)
        for entity in entities[i]:
            key = entity["text"].casefold()
            if entity_counts.get(key, 0) >= 2 or (i == 0 and relevance[i] >= .25):
                concept("entity", key, i, relevance[i])
    for kind, dictionary in dictionaries.items():
        rr, cc = [], []
        keys = sorted(dictionary)
        for col, key in enumerate(keys):
            for row in set(dictionary[key]["rows"]):
                rr.append(row)
                cc.append(col)
        block = csr_matrix((np.ones(len(rr)), (rr, cc)), shape=(len(records), len(keys)))
        add_block(kind, block, [dictionary[key]["weight"] for key in keys], keys)
    concepts = csr_matrix(hstack(blocks, format="csr")) if blocks else csr_matrix((len(records), 0))
    weights = np.asarray(weights)
    if weights.size:
        weights /= weights.sum()
    timings = {"annotation_seconds": annotation_seconds, "vectorization_seconds": vector_seconds,
               "lsa_seconds": lsa_seconds, "concept_seconds": perf_counter() - t0}
    return Analysis(matrix, vectorizer, relevance, lsa, keywords, list(dict.fromkeys(phrases))[:30],
                    entities, status, projections, svd, lexical, concepts, weights, labels, protected, timings)


def coverage_value(analysis, selected):
    if not selected or analysis.concept_matrix.shape[1] == 0:
        return 0.0
    values = np.asarray(analysis.concept_matrix[selected].max(axis=0).toarray()).ravel()
    return float(values @ analysis.concept_weights)
