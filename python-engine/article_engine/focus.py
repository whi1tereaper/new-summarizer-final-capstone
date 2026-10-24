"""Bounded local lexical-semantic Focus retrieval; no downloads or dense model."""
import re
import numpy as np
from nltk.stem import PorterStemmer
from sklearn.feature_extraction.text import ENGLISH_STOP_WORDS
from .coverage import ROLES, TOKEN, QUALIFICATION, REFERENCE

FOCUS_ROLES = {
    'methods': 'method', 'methodology': 'method', 'study design': 'method',
    'research methods': 'method', 'findings': 'finding', 'key findings': 'finding', 'results': 'finding',
    'limitations': 'limitation', 'risks': 'consequence', 'conclusion': 'recommendation',
    'recommendations': 'recommendation', 'main idea': 'objective',
}
HEAD_ROLES = {'method': 'method', 'design': 'method', 'finding': 'finding', 'result': 'finding',
              'limit': 'limitation', 'risk': 'consequence', 'threat': 'consequence',
              'hazard': 'consequence', 'danger': 'consequence', 'recommend': 'recommendation'}
GENERIC = set('entity abstraction object physical event state condition change activity act process whole thing person group relation location action possession'.split())
ROLE_EXTRA = {'finding': r'\b(?:fell|rose|grew|growth)\b',
              'consequence': ROLES['limitation'] + '|' + QUALIFICATION.pattern + r'|\b(?:harm\w*|loss|fail\w*)\b'}
METHOD_ACTION = re.compile(r'\b(?:randomiz\w*|assign\w*|enroll\w*|recruit\w*|followed|surveyed|sampled|collect\w*|regression|adjusted|compared|completed|excluded|procedure\w*|method\w*|design\w*|measur\w*)\b', re.I)


def _signatures(word, wordnet, stem):
    values = {stem(word)}
    # Limit senses and hierarchy depth to avoid spreading a specific query to
    # generic ancestors. This is a disclosed heuristic, not word-sense inference.
    for synset in wordnet.synsets(word)[:2]:
        nodes = [synset]
        for lemma in synset.lemmas():
            nodes.extend(v.synset() for v in lemma.pertainyms())
            nodes.extend(v.synset() for v in lemma.derivationally_related_forms()[:2])
        for _ in range(2):
            nodes += [parent for node in list(nodes) for parent in node.hypernyms()]
        for node in nodes:
            for lemma in node.lemma_names():
                values.update(stem(v) for v in lemma.split('_') if v not in GENERIC and len(v) > 2)
    return values - {stem(v) for v in GENERIC}


def focus_scores(records, analysis, query):
    if not query:
        return np.ones(len(records)), set(range(len(records))), 'not_requested'
    stem = PorterStemmer().stem
    words = list(dict.fromkeys(w.casefold() for w in TOKEN.findall(query) if w.casefold() not in ENGLISH_STOP_WORDS))
    terms = {stem(w) for w in words}
    if not terms:
        return np.zeros(len(records)), set(), 'unsupported'
    exact_role = FOCUS_ROLES.get(query.strip().casefold())
    head = next((t for t in reversed([stem(w) for w in words]) if t in HEAD_ROLES), None)
    role = exact_role or HEAD_ROLES.get(head)
    modifiers = terms - ({head} if head else set())
    vocabulary = [v for v in analysis.vectorizer.vocabulary_ if ' ' not in v]
    direct_words = {term: {v for v in vocabulary if stem(v) == term} for term in terms}
    semantic_words = {term: set() for term in terms}
    try:
        from nltk.corpus import wordnet
        from summarizer_core.tokenization import try_ensure_nltk_resource
        available = try_ensure_nltk_resource(('corpora/wordnet','corpora/wordnet.zip'), 'wordnet')
        if available and not exact_role:
            query_signatures = {stem(w): _signatures(w, wordnet, stem) for w in words}
            # Bound work using source TF-IDF importance, retaining all direct
            # matches even when the article vocabulary is very large.
            names = analysis.vectorizer.get_feature_names_out()
            center = np.asarray(analysis.matrix.mean(axis=0)).ravel()
            candidates = [str(names[i]) for i in np.argsort(-center, kind='stable') if ' ' not in names[i]][:512]
            for value in candidates:
                signatures = _signatures(value, wordnet, stem)
                for term in terms:
                    # A shared distant ancestor such as "science" is not
                    # evidence that an article about exercise covers seismology.
                    # Require a source/query anchor in the other's expansion.
                    if term in signatures or stem(value) in query_signatures[term]:
                        semantic_words[term].add(value)
    except (LookupError, OSError):
        pass
    matched = {t for t in terms if direct_words[t] or semantic_words[t]}
    # Generic category words do not excuse unsupported topical modifiers.
    if not exact_role and (len(matched) * 2 < len(terms)
                           or (role and modifiers and not modifiers.intersection(matched))):
        return np.zeros(len(records)), set(), 'unsupported'
    all_words = set().union(*(direct_words[t] | semantic_words[t] for t in terms))
    vector = analysis.vectorizer.transform([' '.join(sorted(all_words))])
    direct = np.asarray((analysis.matrix @ vector.T).toarray()).ravel()
    latent = np.zeros(len(records))
    if analysis.svd is not None and vector.nnz:
        projected = analysis.svd.transform(vector)[0]
        norms = np.linalg.norm(analysis.projections, axis=1) * np.linalg.norm(projected)
        latent = np.clip(analysis.projections @ projected / np.maximum(norms, 1e-12), 0, 1)
    role_pattern = ROLES.get(role, r'(?!)') + '|' + ROLE_EXTRA.get(role, r'(?!)')
    role_indices = {i for i,s in enumerate(records) if re.search(role_pattern, s.text, re.I)} if role else set()
    if role == 'method':
        role_indices = {i for i,s in enumerate(records) if METHOD_ACTION.search(s.text)
                        and not re.search(ROLES['finding'], s.text, re.I)
                        and not re.search(r'\b(?:not|never)\s+(?:measur\w*|collect\w*|perform\w*|test\w*|used)\b',s.text,re.I)}
        # A procedural afterthought requiring an outcome as its antecedent is
        # not a standalone design passage. Do not import that outcome merely
        # because it mentions "participants" or "responses".
        role_indices = {i for i in role_indices if not REFERENCE.search(records[i].text)
                        or (i > 0 and i-1 in role_indices)}
    if exact_role:
        # A predefined scope such as study design must not admit every sentence
        # merely because it repeats the document-wide word "study".
        allowed = role_indices
        score = np.array([1.0 if i in allowed else 0.0 for i in range(len(records))])
    else:
        modifier_words = set().union(*(direct_words[t] | semantic_words[t] for t in modifiers)) if modifiers else all_words
        modifier_rows = {i for i,s in enumerate(records) if {w.casefold() for w in TOKEN.findall(analysis.lexical[i])} & modifier_words}
        paragraphs = {records[i].paragraph for i in modifier_rows}
        allowed = {i for i,v in enumerate(direct) if v > 0}
        if role:
            allowed = {i for i in role_indices if i in modifier_rows or records[i].paragraph in paragraphs}
        else:
            allowed.update(i for i,s in enumerate(records) if s.paragraph in paragraphs and latent[i] >= .5)
        score = .5 * direct / max(float(direct.max(initial=0)), 1e-12) + .5 * latent
        for i in allowed & role_indices:
            score[i] = max(score[i], 1.0)
    if not allowed:
        return score, allowed, 'unsupported'
    semantic = any(semantic_words[t] - direct_words[t] for t in terms)
    return score, allowed, 'concept_role_supported' if exact_role else 'semantic_wordnet_supported' if semantic else 'lexical_lsa_supported'
