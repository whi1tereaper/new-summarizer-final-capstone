"""TF-IDF and truncated LSA with source-order, context-aware evidence selection."""
from __future__ import annotations

import re
import numpy as np
from sklearn.decomposition import TruncatedSVD
from sklearn.feature_extraction.text import TfidfVectorizer

from .preprocessing import TOKEN

QUALIFIER = re.compile(r"\b(not|no evidence|cannot|may|might|could|uncertain|limitations?|however|although|according to|said|argues?|believes?)\b", re.I)
REFERENCE = re.compile(r"^(?:(?:this|these|those|it|they|such)\s+(?!study\b|article\b|experiment\b)|however\b|but\b|therefore\b|thus\b|moreover\b)", re.I)
LAYOUT = re.compile(r"\b(?:image|figure|table|photo)\s+(?:above|below)|\b(?:left|right)\s+image\b", re.I)
MODES = {
    "academic": r"study|method|participants|results|findings|limitations|conclusion",
    "scientific": r"experiment|measured|observed|results|evidence|uncertainty",
    "technical": r"protocol|architecture|mechanism|system|algorithm|risk",
    "news": r"said|announced|officials|reported|closed|affected",
    "executive": r"cost|revenue|risk|decision|forecast|profit",
    "study": r"definition|concept|principle|example|means",
    "legal": r"shall|must|prohibited|obligation|liability",
    "general": r"findings|results|conclusion|limitations|explains",
}


def normalized(values):
    values = np.asarray(values, dtype=float)
    maximum = float(values.max(initial=0))
    return values / maximum if maximum else np.zeros_like(values)


def analyze(records):
    from summarizer_core.tokenization import load_optional_spacy_pipeline
    nlp = load_optional_spacy_pipeline()  # Shared cached loader; no second model.
    lexical = [record.text for record in records]
    entities = [[] for _ in records]
    phrases = []
    status = "unavailable_local_model"
    if nlp is not None:
        try:
            for i, doc in enumerate(nlp.pipe(lexical, batch_size=32)):
                lexical[i] = " ".join(token.lemma_ or token.text for token in doc
                                      if not token.is_space and not token.is_punct)
                entities[i] = [{"text": ent.text, "label": ent.label_} for ent in doc.ents]
                phrases.extend(chunk.text for chunk in doc.noun_chunks if len(chunk.text.split()) <= 6)
            status = "spacy_pos_lemma_ner"
        except Exception:
            lexical = [record.text for record in records]
            entities = [[] for _ in records]
            phrases = []
            status = "unavailable_annotation_error"
    vectorizer = TfidfVectorizer(stop_words="english", ngram_range=(1, 2),
                                 max_features=20_000, sublinear_tf=True)
    matrix = vectorizer.fit_transform(lexical)
    center = np.asarray(matrix.mean(axis=0)).ravel()
    relevance = normalized(matrix @ center)
    dimension = min(4, matrix.shape[0] - 1, matrix.shape[1] - 1)
    lsa = relevance.copy()
    if dimension >= 1:
        # Sparse truncated SVD avoids the dense term x sentence Sumy matrix.
        projections = TruncatedSVD(n_components=dimension, random_state=17).fit_transform(matrix)
        lsa = normalized(np.linalg.norm(projections, axis=1))
    names = vectorizer.get_feature_names_out()
    keywords = [str(names[i]) for i in np.argsort(-center, kind="stable")[:10]]
    phrases = list(dict.fromkeys(phrases))[:30]
    return matrix, vectorizer, relevance, lsa, keywords, phrases, entities, status


def select(records, analysis, count, budget, mode, focus):
    matrix, vectorizer, relevance, lsa, _, _, entities, _ = analysis
    profile = re.compile(r"\b(?:" + MODES[mode] + r")\b", re.I)
    cues = np.array([min(1, len(profile.findall(s.text)) / 2) for s in records])
    evidence = np.array([min(1, (bool(re.search(r"\d", s.text)) +
                                  bool(QUALIFIER.search(s.text)) + bool(entities[i])) / 3)
                         for i, s in enumerate(records)])
    scores = .55 * relevance + .20 * lsa + .15 * cues + .10 * evidence
    # Small section/lead bonuses do not exclude findings in the body or ending.
    scores += np.array([.06 if re.search(r"results|findings|conclusion|limitations", s.section, re.I)
                        else .03 if i == 0 else 0 for i, s in enumerate(records)])
    focus_status = "not_requested"
    allowed = set(range(len(records)))
    if focus:
        query_text = focus
        if analysis[7] == "spacy_pos_lemma_ner":
            from summarizer_core.tokenization import load_optional_spacy_pipeline
            nlp = load_optional_spacy_pipeline()
            if nlp is not None:
                query_text = " ".join(token.lemma_ or token.text for token in nlp(focus))
        query = vectorizer.transform([query_text])
        query_terms = {term for term in vectorizer.build_analyzer()(query_text) if " " not in term}
        matched_terms = query_terms.intersection(vectorizer.vocabulary_)
        if not query_terms or len(matched_terms) * 2 < len(query_terms):
            return [], "unsupported"
        similarities = np.asarray((matrix @ query.T).toarray()).ravel()
        # Require content-word evidence. A zero-overlap query cannot fall back to
        # a general summary masquerading as an answer to an unsupported focus.
        allowed = {i for i, value in enumerate(similarities) if value > 0}
        if not allowed:
            return [], "unsupported"
        scores = .30 * scores + .70 * normalized(similarities)
        focus_status = "lexically_supported"
    chosen = []
    used = 0
    remaining = set(allowed)
    while remaining and len(chosen) < count:
        ranked = []
        for i in sorted(remaining):
            sentence = records[i]
            if LAYOUT.search(sentence.text):
                continue
            bundle = [i]
            if REFERENCE.search(sentence.text):
                previous = i - 1
                while previous >= 0 and records[previous].paragraph == sentence.paragraph:
                    if previous not in chosen:
                        bundle.insert(0, previous)
                    if not REFERENCE.search(records[previous].text):
                        break
                    previous -= 1
                if previous < 0 or records[previous].paragraph != sentence.paragraph:
                    continue
            if any(LAYOUT.search(records[j].text) for j in bundle):
                continue
            if len(chosen) + len(bundle) > count:
                continue
            size = sum(len(TOKEN.findall(records[j].text)) for j in bundle)
            # Retain one complete first evidence bundle when it exceeds a small
            # budget; never cut qualifiers/numbers off at an arbitrary word.
            if chosen and used + size > budget:
                continue
            overlap = float((matrix[i] @ matrix[chosen].T).max()) if chosen else 0.0
            if overlap > .90:
                continue
            novelty = .05 if sentence.section not in {records[j].section for j in chosen} else 0
            ranked.append((float(scores[i]) - .22 * overlap + novelty, -i, bundle, size))
        if not ranked:
            break
        _, _, bundle, size = max(ranked, key=lambda row: (row[0], row[1]))
        chosen.extend(j for j in bundle if j not in chosen)
        remaining.difference_update(bundle)
        used += size
    return sorted(chosen), focus_status
