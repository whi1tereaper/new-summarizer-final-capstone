"""Budgeted marginal coverage, optional MMR, and query-conditioned concepts."""
from __future__ import annotations
import re
from time import perf_counter
import numpy as np
from .coverage import LAYOUT, ROLES, TOKEN, parameters, coverage_value
from .validation import evidence_bundle
from .focus import focus_scores, FOCUS_ROLES
from .core import core_scores, core_parameters

def select(records, analysis, count, budget, mode, focus, *, variant="hybrid", config=None,
           guard=True, title='', core_config=None):
    started = perf_counter()
    explicit_config = config is not None
    config = dict(config or parameters())
    core_config = core_config or core_parameters()
    if not explicit_config and variant == 'hybrid':
        config['lambda'] = core_config.get('mmr_lambda',1.0)
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
    core = None
    if not focus and bundles and core_config.get('enabled', True):
        # Stage A is not divided by sentence length: doing so is precisely what
        # displaced the longer central claim in v2's shortest summaries.
        central = core_scores(records, analysis, title, config=core_config)
        index = max(bundles, key=lambda i: (central[i], -i))
        bundle, cols, values = bundles[index]
        chosen.update(bundle)
        used = sum(word_counts[j] for j in bundle)
        protected.update(set().union(*(analysis.protected[j] for j in bundle)))
        covered[cols] = np.maximum(covered[cols], values)
        core = {'source_index': index, 'bundle_indices': sorted(bundle), 'score': float(central[index]),
                'cost': used, 'method': 'development_calibrated_central_claim_seed'}
        if lam < 1:
            t0 = perf_counter()
            similarities = analysis.matrix @ analysis.matrix[sorted(bundle)].T
            maximum_similarity = np.asarray(similarities.max(axis=1).toarray()).ravel()
            redundancy_seconds += perf_counter() - t0
        bundles = {i: value for i, value in bundles.items() if i not in chosen}
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
                                          "lambda": lam, "cost_power": config["cost_power"], 'core_selection': core}
