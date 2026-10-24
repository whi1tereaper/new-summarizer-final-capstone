"""Budgeted marginal coverage, optional MMR, and query-conditioned concepts."""
from __future__ import annotations
import re
from time import perf_counter
import numpy as np
from nltk.stem import PorterStemmer
from sklearn.feature_extraction.text import ENGLISH_STOP_WORDS
from .coverage import LAYOUT, ROLES, TOKEN, parameters, coverage_value
from .validation import evidence_bundle

FOCUS_ROLES = {
    "methods": "method", "methodology": "method", "study design": "method",
    "research methods": "method", "findings": "finding", "results": "finding",
    "limitations": "limitation", "risks": "consequence", "conclusion": "recommendation",
    "recommendations": "recommendation", "main idea": "objective",
}


def focus_scores(records, analysis, query):
    if not query:
        return np.ones(len(records)), set(range(len(records))), "not_requested"
    stem = PorterStemmer().stem
    terms = {stem(w.casefold()) for w in TOKEN.findall(query) if w.casefold() not in ENGLISH_STOP_WORDS}
    vocabulary = analysis.vectorizer.vocabulary_
    source_terms = {stem(word) for word in vocabulary if " " not in word}
    role = FOCUS_ROLES.get(query.strip().casefold())
    if not terms or (role is None and len(terms & source_terms) * 2 < len(terms)):
        return np.zeros(len(records)), set(), "unsupported"
    expanded = [word for word in vocabulary if " " not in word and stem(word) in terms]
    vector = analysis.vectorizer.transform([" ".join(expanded)])
    direct = np.asarray((analysis.matrix @ vector.T).toarray()).ravel()
    latent = np.zeros(len(records))
    if analysis.svd is not None and vector.nnz:
        projected = analysis.svd.transform(vector)[0]
        norms = np.linalg.norm(analysis.projections, axis=1) * np.linalg.norm(projected)
        latent = np.clip(analysis.projections @ projected / np.maximum(norms, 1e-12), 0, 1)
    score = .5 * direct / max(float(direct.max(initial=0)), 1e-12) + .5 * latent
    allowed = {i for i, value in enumerate(direct) if value > 0}
    # LSA can include a differently-worded sentence within a directly supported
    # paragraph. It cannot turn a wholly absent topic into a supported query.
    supported_paragraphs = {records[i].paragraph for i in allowed}
    allowed.update(i for i, s in enumerate(records) if s.paragraph in supported_paragraphs and latent[i] >= .5)
    if role:
        for i, s in enumerate(records):
            if re.search(ROLES[role], s.text, re.I):
                allowed.add(i)
                score[i] = max(score[i], 1.0)
    if not allowed:
        return score, allowed, "unsupported"
    return score, allowed, "concept_role_supported" if role else "lexical_lsa_supported"


def select(records, analysis, count, budget, mode, focus, *, variant="hybrid", config=None,
           guard=True):
    started = perf_counter()
    config = dict(config or parameters())
    query_scores, allowed, focus_status = focus_scores(records, analysis, focus)
    if not allowed:
        return [], focus_status, {"coverage": 0.0, "selection_seconds": perf_counter() - started}
    weights = analysis.concept_weights.copy()
    if focus:
        importance = np.asarray(analysis.concept_matrix.multiply(query_scores[:, None]).max(axis=0).toarray()).ravel()
        weights *= importance
        weights /= max(float(weights.sum()), 1e-12)
    from .ranking import MODES
    mode_cues = np.array([min(1, len(re.findall(r"\b(?:" + MODES[mode] + r")\b", s.text, re.I)) / 2) for s in records])
    rank = (analysis.relevance + analysis.lsa) / 2 + .1 * mode_cues
    if focus:
        rank *= query_scores
    rank /= max(float(rank.max(initial=0)), 1e-12)
    word_counts = [len(TOKEN.findall(s.text)) for s in records]
    bundles = {}
    # Close dependencies for successors that themselves require a predecessor.
    for i in sorted(allowed):
        bundle = evidence_bundle(records, i, guard=guard)
        if bundle is None:
            continue
        for _ in range(4):
            expanded = set(bundle)
            for j in bundle:
                required = evidence_bundle(records, j, guard=guard)
                if required is None:
                    expanded = None
                    break
                expanded.update(required)
            if expanded is None:
                bundle = None
                break
            if expanded == bundle:
                break
            bundle = expanded
        if (bundle is None or len(bundle) > count or sum(word_counts[j] for j in bundle) > budget
                or any(LAYOUT.search(records[j].text) for j in bundle)):
            continue
        vector = analysis.concept_matrix[sorted(bundle)].max(axis=0).tocoo()
        bundles[i] = (bundle, vector.col, vector.data)
    covered = np.zeros(len(weights))
    chosen = set()
    protected = set()
    used = 0
    maximum_similarity = np.zeros(len(records))
    redundancy_seconds = 0.0
    decisions = []
    lam = config["lambda"] if variant in {"coverage_mmr", "hybrid"} else 1.0
    mix = config["rank_mix"] if variant == "hybrid" else 0.0
    while bundles and len(chosen) < count:
        candidates = []
        for index, (bundle, cols, values) in bundles.items():
            extra = bundle - chosen
            cost = sum(word_counts[j] for j in extra)
            if not extra or len(chosen | bundle) > count or used + cost > budget:
                continue
            gain = float(np.maximum(values - covered[cols], 0) @ weights[cols])
            modular = mix * sum(rank[j] for j in extra) / max(1, count)
            if variant == "ranking":
                gain = sum(rank[j] for j in extra) / max(1, count)
            else:
                gain += modular
            new_protected = set().union(*(analysis.protected[j] for j in extra)) - protected
            # Coverage already models novelty. Residual MMR is applied only
            # without new protected quantities/qualifications, never as a second
            # penalty on a sentence introducing distinct factual evidence.
            overlap = max((maximum_similarity[j] for j in extra), default=0) if not new_protected else 0
            candidates.append((index, gain, cost, overlap, extra))
        if not candidates:
            break
        scale = max(max(row[1] for row in candidates), 1e-12)
        best = max(candidates, key=lambda row: ((lam * row[1] / scale - (1-lam) * row[3]) /
                                                max(1, row[2]) ** config["cost_power"], -row[0]))
        index, gain, cost, overlap, extra = best
        if gain <= 1e-12:
            break
        chosen.update(extra)
        used += cost
        protected.update(set().union(*(analysis.protected[j] for j in extra)))
        _, cols, values = bundles[index]
        covered[cols] = np.maximum(covered[cols], values)
        decisions.append({"source_indices": sorted(extra), "cost": cost, "marginal_gain": gain,
                          "mmr_similarity": float(overlap)})
        if lam < 1:
            t0 = perf_counter()
            similarities = analysis.matrix @ analysis.matrix[sorted(extra)].T
            maximum_similarity = np.maximum(maximum_similarity, np.asarray(similarities.max(axis=1).toarray()).ravel())
            redundancy_seconds += perf_counter() - t0
        bundles = {i: value for i, value in bundles.items() if i not in chosen}
    return sorted(chosen), focus_status, {"coverage": float(covered @ weights), "concept_count": len(weights),
                                          "word_budget": budget, "used_words": used, "variant": variant,
                                          "selection_seconds": perf_counter() - started,
                                          "redundancy_seconds": redundancy_seconds, "decisions": decisions,
                                          "lambda": lam, "cost_power": config["cost_power"]}
