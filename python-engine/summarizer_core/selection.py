"""Candidate sentence selection, MMR diversity coordination, and section/topic coverage.

This module provides deterministic selection algorithms for the summarizer core:
- Profile-aware candidate selection with topic clustering and section quota controls.
- Maximal Marginal Relevance (MMR) novelty and diversity calculation.
- Depth coverage constraint enforcement (limitations, conclusions).
- Base unprofiled candidate selection for backward compatibility.
"""

from __future__ import annotations

import re
from typing import Any

from .constants import DANGLING_ANTECEDENT_PATTERN, DISCOURSE_BOOST_PATTERNS
from .models import SentenceCandidate
from .scoring import sentence_token_overlap, sentences_are_redundant


class _SelectionFeatures:
    """Bounded request-local pair results shared by ranking strategies.

    Candidate text is immutable throughout selection. Cache the existing
    predicates, including their contrasting-claim safeguards, without changing
    thresholds or retaining document text after the request completes.
    """

    _PAIR_LIMIT = 32768

    def __init__(self, candidates: list[SentenceCandidate]) -> None:
        self.candidates = candidates
        self.redundancy: dict[tuple[int, int], bool] = {}
        self.overlaps: dict[tuple[int, int], float] = {}
        self.has_discourse = [
            any(pattern.search(candidate.text) for pattern, _ in DISCOURSE_BOOST_PATTERNS.values())
            for candidate in candidates
        ]

    def redundant(self, left: int, right: int) -> bool:
        key = (min(left, right), max(left, right))
        if key not in self.redundancy:
            value = sentences_are_redundant(self.candidates[left].text, self.candidates[right].text)
            if len(self.redundancy) < self._PAIR_LIMIT:
                self.redundancy[key] = value
            return value
        return self.redundancy[key]

    def overlap(self, left: int, right: int) -> float:
        key = (min(left, right), max(left, right))
        if key not in self.overlaps:
            value = sentence_token_overlap(self.candidates[left], self.candidates[right])
            if len(self.overlaps) < self._PAIR_LIMIT:
                self.overlaps[key] = value
            return value
        return self.overlaps[key]


def candidate_has_unresolved_antecedent(index: int, candidates: list[SentenceCandidate], selected: set[int]) -> bool:
    """Return True if the candidate starts with an antecedent reference whose prior context is missing."""
    text = candidates[index].text
    if not DANGLING_ANTECEDENT_PATTERN.search(text):
        return False
    # If the immediately preceding sentence in the same paragraph is selected, antecedent is resolved
    if index > 0 and (index - 1) in selected and candidates[index - 1].paragraph_index == candidates[index].paragraph_index:
        return False
    return True


def detect_topic_clusters(
    candidates: list[SentenceCandidate],
    tfidf_matrix: Any,
    tfidf_scores: list[float],
) -> list[list[int]]:
    """Group candidate sentences into topic clusters deterministically using TF-IDF cosine similarity.

    Clusters are formed around highest-scoring seeds and ordered by relevance.
    """
    if not candidates:
        return []
    if len(candidates) == 1:
        return [[0]]

    # The greedy algorithm only consumes rows for unassigned seeds. Computing
    # those rows on demand preserves every score and tie while avoiding a dense
    # N x N allocation and similarities for candidates already assigned.
    transposed = tfidf_matrix.T
    ranked_by_tfidf = sorted(
        range(len(candidates)),
        key=lambda idx: tfidf_scores[idx] if idx < len(tfidf_scores) else 0.0,
        reverse=True,
    )

    clusters: list[list[int]] = []
    assigned = set()
    similarity_threshold = 0.22

    for seed in ranked_by_tfidf:
        if seed in assigned:
            continue
        cluster = [seed]
        assigned.add(seed)
        similarities = (tfidf_matrix[seed:seed + 1] * transposed).toarray()[0]
        for other in ranked_by_tfidf:
            if other not in assigned and similarities[other] >= similarity_threshold:
                cluster.append(other)
                assigned.add(other)
        clusters.append(cluster)

    return clusters


def select_candidate_indices_with_profile(
    candidates: list[SentenceCandidate],
    combined_scores: list[float],
    article_type: str,
    target_count: int,
    section_coverage_groups: dict[str, list[set[str]]],
    section_coverage_weight: float,
    sel_profile: Any,
    topic_clusters: list[list[int]] | None = None,
    depth_key: str = "balanced",
    depth_config: dict[str, Any] | None = None,
    topic_coverage_target: int = 3,
    redundancy_filtering: bool = True,
    _features: _SelectionFeatures | None = None,
    priority_indices: list[int] | None = None,
) -> list[int]:
    """Select candidates with depth-aware topic coverage and section quota controls.

    - Respects depth mode's target topic breadth without bloating repetition.
    - Preserves strict anti-redundancy checks across all depths.
    - Guarantees source chronological ordering upon return.
    """
    ranked_indices = sorted(
        range(len(candidates)),
        key=lambda index: combined_scores[index],
        reverse=True,
    )
    selected: list[int] = []
    selected_sentence_texts: list[str] = []
    features = _features if _features is not None else _SelectionFeatures(candidates)

    topic_clusters = topic_clusters or []
    cand_to_cluster = {c_idx: cl_idx for cl_idx, cl in enumerate(topic_clusters) for c_idx in cl}
    depth_cfg = depth_config or {}
    max_topics = int(depth_cfg.get("max_topics", 6))
    redundancy_penalty_factor = float(depth_cfg.get("redundancy_penalty", 0.90))

    priority_limit = min(
        target_count,
        len(priority_indices or []),
        2 if depth_key in {"very_brief", "brief"} else len(priority_indices or []),
    )
    for index in (priority_indices or [])[:priority_limit]:
        if index < 0 or index >= len(candidates) or index in selected:
            continue
        if not selected and candidate_has_unresolved_antecedent(index, candidates, set()):
            continue
        if redundancy_filtering and any(features.redundant(index, chosen) for chosen in selected):
            continue
        selected.append(index)
        selected_sentence_texts.append(candidates[index].text)

    # --- 1. Profile preferred-section pre-pass ---
    represented_clusters: set[int] = set()
    if sel_profile.preferred_sections:
        preferred_set = set(sel_profile.preferred_sections)
        # In brief mode, limit preferred seeding so dominant content dominates
        preferred_limit = min(
            len(sel_profile.preferred_sections),
            target_count,
            1 if depth_key == "brief" else max(1, max_topics),
        )
        covered_preferred: set[str] = set()
        for index in ranked_indices:
            if len(selected) >= preferred_limit:
                break
            candidate = candidates[index]
            if candidate.section not in preferred_set or candidate.section in covered_preferred:
                continue
            if not selected and candidate_has_unresolved_antecedent(index, candidates, set()):
                continue
            if redundancy_filtering and candidate.text in selected_sentence_texts:
                continue
            if redundancy_filtering and any(features.redundant(index, chosen) for chosen in selected):
                continue
            selected.append(index)
            selected_sentence_texts.append(candidate.text)
            covered_preferred.add(candidate.section)
            if index in cand_to_cluster:
                represented_clusters.add(cand_to_cluster[index])

    # --- 2. Standard section coverage groups ---
    coverage_groups = section_coverage_groups.get(article_type, [])
    coverage_target = min(
        target_count,
        round(min(target_count, len(coverage_groups)) * max(0.0, min(1.0, section_coverage_weight))),
    )
    for group in coverage_groups:
        if len(selected) >= coverage_target:
            break
        for index in ranked_indices:
            candidate = candidates[index]
            if not selected and candidate_has_unresolved_antecedent(index, candidates, set()):
                continue
            if candidate.section in group and index not in selected and (not redundancy_filtering or candidate.text not in selected_sentence_texts):
                if redundancy_filtering and any(features.redundant(index, chosen) for chosen in selected):
                    continue
                selected.append(index)
                selected_sentence_texts.append(candidate.text)
                if index in cand_to_cluster:
                    represented_clusters.add(cand_to_cluster[index])
                break

    # --- 3. Topic coverage representation seed pass ---
    # Seed representation from distinct topic clusters following candidate rank order up to max_topics
    for index in ranked_indices:
        if len(selected) >= target_count:
            break
        cand_cl = cand_to_cluster.get(index)
        if cand_cl is not None and cand_cl not in represented_clusters and len(represented_clusters) < max_topics:
            candidate = candidates[index]
            if not selected and candidate_has_unresolved_antecedent(index, candidates, set()):
                continue
            if redundancy_filtering and candidate.text in selected_sentence_texts:
                continue
            if redundancy_filtering and any(features.redundant(index, chosen) for chosen in selected):
                continue
            selected.append(index)
            selected_sentence_texts.append(candidate.text)
            represented_clusters.add(cand_cl)

    # --- 4. MMR fill to target ---
    score_floor = min(combined_scores) if combined_scores else 0.0
    score_span = max(combined_scores) - score_floor if combined_scores else 0.0

    has_discourse = features.has_discourse

    while len(selected) < target_count:
        best_index: int | None = None
        best_value = float("-inf")
        selected_sections = {candidates[index].section for index in selected}
        selected_set = set(selected)
        # Track cluster representation frequency to penalize over-concentrating on one topic
        cluster_counts: dict[int, int] = {}
        for sel_idx in selected:
            cl_id = cand_to_cluster.get(sel_idx, -1)
            cluster_counts[cl_id] = cluster_counts.get(cl_id, 0) + 1

        for index in ranked_indices:
            if index in selected_set:
                continue
            candidate = candidates[index]
            if redundancy_filtering and candidate.text in selected_sentence_texts:
                continue

            cand_cl = cand_to_cluster.get(index, -1)
            redundancy = max(
                (features.overlap(index, chosen) for chosen in selected),
                default=0.0,
            )
            if redundancy_filtering and (redundancy >= 0.68 or any(
                features.redundant(index, chosen)
                for chosen in selected
            )):
                continue

            normalized_score = ((combined_scores[index] - score_floor) / score_span) if score_span else 1.0
            novelty = 1.0 - redundancy if redundancy_filtering else 1.0
            section_bonus = 0.07 if selected and candidate.section not in selected_sections else 0.0

            discourse_bonus = 0.0
            if has_discourse[index] and hasattr(sel_profile, "coherence_weight") and sel_profile.coherence_weight > 0:
                discourse_bonus = 0.05 * sel_profile.coherence_weight

            # Topic cluster diversity penalty
            cl_freq = cluster_counts.get(cand_cl, 0)
            cl_penalty = cl_freq * 0.12 * redundancy_penalty_factor
            # In brief/short modes, penalize adding further topic dispersion once max_topics reached
            if len(represented_clusters) >= max_topics and cand_cl not in represented_clusters:
                cl_penalty += 0.20 * redundancy_penalty_factor

            # Unresolved antecedent penalty
            antecedent_penalty = 0.24 if candidate_has_unresolved_antecedent(index, candidates, selected_set) else 0.0

            value = (normalized_score * 0.76) + (novelty * 0.17) + section_bonus + discourse_bonus - cl_penalty - antecedent_penalty
            if value > best_value:
                best_index, best_value = index, value

        if best_index is None:
            break

        selected.append(best_index)
        selected_sentence_texts.append(candidates[best_index].text)
        if cand_to_cluster.get(best_index) is not None:
            represented_clusters.add(cand_to_cluster[best_index])

    return sorted(selected, key=lambda index: candidates[index].index)


def ensure_depth_coverage(
    selected_indices: list[int],
    candidates: list[SentenceCandidate],
    combined_scores: list[float],
    target_count: int,
    *,
    include_limitations: bool,
    include_conclusion: bool,
    include_methodology: bool = False,
    include_definition: bool = False,
    priority_indices: list[int] | None = None,
) -> list[int]:
    """Reserve scarce slots for depth-required sections when they exist."""
    required_patterns: list[tuple[set[str], re.Pattern[str]]] = []
    if include_limitations:
        required_patterns.append((
            {"limitations"},
            re.compile(r"\b(?:limitation|limitations|caveat|constraint)\b", re.IGNORECASE),
        ))
    if include_conclusion:
        required_patterns.append((
            {"conclusion"},
            re.compile(r"\b(?:in conclusion|concludes?|overall|therefore|recommend(?:ation)?s?)\b", re.IGNORECASE),
        ))

    selected = list(selected_indices)
    protected_indices = set(priority_indices or [])
    for sections, text_pattern in required_patterns:
        if any(
            candidates[index].section in sections or text_pattern.search(candidates[index].text)
            for index in selected
        ):
            continue
        replacement = next(
            (
                index for index in sorted(
                    range(len(candidates)),
                    key=lambda candidate_index: combined_scores[candidate_index],
                    reverse=True,
                )
                if index not in selected
                and (candidates[index].section in sections or text_pattern.search(candidates[index].text))
            ),
            None,
        )
        if replacement is None:
            continue
        if len(selected) < target_count:
            selected.append(replacement)
        else:
            replaceable = [
                index for index in selected
                if index not in protected_indices
            ]
            if replaceable:
                lowest = min(replaceable, key=lambda index: combined_scores[index])
                selected[selected.index(lowest)] = replacement

    if include_methodology and not any(
        candidates[index].section in {"methodology", "methods", "research_design"}
        for index in selected
    ):
        method_indices = [
            index for index, candidate in enumerate(candidates)
            if candidate.section in {"methodology", "methods", "research_design"}
        ]
        condition_pattern = re.compile(
            r"\b(?:before|after|prior to|following|during|for\s+\d+)\b",
            re.IGNORECASE,
        )
        condition_methods = [
            index for index in method_indices
            if condition_pattern.search(candidates[index].text)
            and re.search(
                r"\b(?:participant|group|trial|experiment|treatment|sample|condition|intervention)\b",
                candidates[index].text,
                re.IGNORECASE,
            )
        ]
        replacement = max(
            condition_methods or method_indices,
            key=lambda index: combined_scores[index],
            default=None,
        )
        if replacement is not None:
            if len(selected) < target_count:
                selected.append(replacement)
            else:
                replaceable = [index for index in selected if index not in protected_indices]
                if replaceable:
                    lowest = min(replaceable, key=lambda index: combined_scores[index])
                    selected[selected.index(lowest)] = replacement

    if include_definition and not any(
        candidates[index].claim_status == "DEFINITION" for index in selected
    ):
        replacement = max(
            (
                index for index, candidate in enumerate(candidates)
                if index not in selected and candidate.claim_status == "DEFINITION"
            ),
            key=lambda index: combined_scores[index],
            default=None,
        )
        if replacement is not None:
            if len(selected) < target_count:
                selected.append(replacement)
            else:
                replaceable = [index for index in selected if index not in protected_indices]
                if replaceable:
                    lowest = min(replaceable, key=lambda index: combined_scores[index])
                    selected[selected.index(lowest)] = replacement

    return sorted(set(selected), key=lambda index: candidates[index].index)


def select_candidate_indices(
    candidates: list[SentenceCandidate],
    combined_scores: list[float],
    article_type: str,
    target_count: int,
    section_coverage_groups: dict[str, list[set[str]]],
    section_coverage_weight: float,
) -> list[int]:
    """Base candidate selection with section coverage groups and MMR fill."""
    ranked_indices = sorted(
        range(len(candidates)),
        key=lambda index: combined_scores[index],
        reverse=True,
    )
    selected: list[int] = []
    selected_sentence_texts: list[str] = []

    coverage_groups = section_coverage_groups.get(article_type, [])
    coverage_target = min(
        target_count,
        round(min(target_count, len(coverage_groups)) * max(0.0, min(1.0, section_coverage_weight))),
    )
    for group in coverage_groups:
        if len(selected) >= coverage_target:
            break
        for index in ranked_indices:
            candidate = candidates[index]
            if candidate.section in group and index not in selected:
                if candidate.text in selected_sentence_texts:
                    continue
                if any(sentences_are_redundant(candidate.text, candidates[chosen].text) for chosen in selected):
                    continue
                selected.append(index)
                selected_sentence_texts.append(candidate.text)
                break

    score_floor = min(combined_scores) if combined_scores else 0.0
    score_span = max(combined_scores) - score_floor if combined_scores else 0.0
    while len(selected) < target_count:
        best_index: int | None = None
        best_value = float("-inf")
        selected_sections = {candidates[index].section for index in selected}

        for index in ranked_indices:
            if index in selected:
                continue
            candidate = candidates[index]
            if candidate.text in selected_sentence_texts:
                continue

            redundancy = max(
                (sentence_token_overlap(candidate, candidates[chosen]) for chosen in selected),
                default=0.0,
            )
            if redundancy >= 0.68 or any(
                sentences_are_redundant(candidate.text, candidates[chosen].text)
                for chosen in selected
            ):
                continue

            normalized_score = ((combined_scores[index] - score_floor) / score_span) if score_span else 1.0
            novelty = 1.0 - redundancy
            section_bonus = 0.07 if selected and candidate.section not in selected_sections else 0.0
            value = (normalized_score * 0.76) + (novelty * 0.17) + section_bonus
            if value > best_value:
                best_index, best_value = index, value

        if best_index is None:
            break
        selected.append(best_index)
        selected_sentence_texts.append(candidates[best_index].text)

    return sorted(selected, key=lambda index: candidates[index].index)


def generate_and_rank_summary_candidates(
    candidates: list[SentenceCandidate],
    combined_scores: list[float],
    article_type: str,
    target_count: int,
    section_coverage_groups: dict[str, list[set[str]]],
    section_coverage_weight: float,
    sel_profile: Any,
    topic_clusters: list[list[int]] | None = None,
    depth_key: str = "balanced",
    depth_config: dict[str, Any] | None = None,
    document_analysis: dict[str, Any] | None = None,
    effective_title: str = "",
) -> tuple[list[int], dict[str, Any]]:
    """Generate multiple candidate summaries using controlled ranking variations and select the best one.

    Explores controlled variations across:
    1. Balanced (Baseline profile-MMR weighting).
    2. High Information Density (boosts main claims, decisions, and quantitative findings).
    3. Broad Topic Coverage (guarantees wide representation across topic clusters).
    4. Coherence Prioritized (penalizes dangling antecedents and rewards discourse flow).
    5. Semantic Alignment (SentenceTransformers dense relevance against document title/purpose).

    Reranks candidates using an evidence-based objective quality function:
    - Semantic relevance to core subject
    - Topic cluster coverage breadth
    - Information density and claim status
    - Redundancy penalty (lexical + semantic)
    - Discourse coherence and antecedent integrity
    """
    if not candidates or target_count <= 0:
        return [], {"selected_strategy": "none", "composite_score": 0.0, "strategies_evaluated": []}

    if len(candidates) <= target_count:
        all_indices = list(range(len(candidates)))
        return all_indices, {
            "selected_strategy": "all_candidates",
            "composite_score": 1.0,
            "strategies_evaluated": ["all_candidates"],
            "strategy_scores": {"all_candidates": 1.0},
        }

    clusters = topic_clusters or []
    primary_finding_indices = (
        list(document_analysis.get("primary_finding_indices", []))
        if document_analysis is not None
        else []
    )
    depth_cfg = depth_config or {}
    cand_to_cluster = {c_idx: cl_idx for cl_idx, cl in enumerate(clusters) for c_idx in cl}
    include_limitations = bool(depth_cfg.get("include_limitations", False))
    include_conclusion = bool(depth_cfg.get("include_conclusion", False))
    include_methodology = bool(depth_cfg.get("include_methodology", False)) and getattr(
        sel_profile, "mode", ""
    ) == "academic"
    include_definition = (
        getattr(sel_profile, "mode", "") == "technical"
        and depth_key in {"very_brief", "brief", "short"}
    )
    features = _SelectionFeatures(candidates)

    # Pre-calculate dense semantic similarities if title or purpose is available
    dense_similarities: list[float] | None = None
    if effective_title and len(candidates) > 0:
        try:
            from .embeddings import embed_texts

            query_text = effective_title
            if document_analysis and document_analysis.get("purpose"):
                query_text += " " + str(document_analysis["purpose"])
            # Bound embedding workload to top scoring candidate pool to eliminate redundant dense passes
            max_dense_cands = min(len(candidates), 60)
            top_eval_indices = sorted(range(len(candidates)), key=lambda i: combined_scores[i], reverse=True)[:max_dense_cands]
            eval_texts = [candidates[idx].text for idx in top_eval_indices]
            emb = embed_texts([query_text, *eval_texts])
            if emb.backend == "sentence_transformers_local" and hasattr(emb.vectors, "shape"):
                import numpy as np

                query_vec = emb.vectors[0:1]
                cand_vecs = emb.vectors[1:]
                dots = list(np.asarray(np.dot(cand_vecs, query_vec.T).ravel(), dtype=float))
                dense_similarities = [0.0] * len(candidates)
                for rank_pos, idx in enumerate(top_eval_indices):
                    dense_similarities[idx] = dots[rank_pos]
        except Exception:
            dense_similarities = None

    # Candidate Strategy 1: Balanced (Standard profile & MMR settings)
    strat_balanced = select_candidate_indices_with_profile(
        candidates,
        combined_scores,
        article_type,
        target_count,
        section_coverage_groups,
        section_coverage_weight,
        sel_profile,
        topic_clusters=clusters,
        depth_key=depth_key,
        depth_config=depth_cfg,
        _features=features,
        priority_indices=primary_finding_indices,
    )
    strat_balanced = ensure_depth_coverage(
        strat_balanced, candidates, combined_scores, target_count,
        include_limitations=include_limitations,
        include_conclusion=include_conclusion,
        include_methodology=include_methodology,
        include_definition=include_definition,
        priority_indices=primary_finding_indices,
    )

    strategies: dict[str, list[int]] = {"balanced": strat_balanced}

    # Candidate Strategy 2: High Information Density (Claims, Findings, Decisions)
    density_scores = list(combined_scores)
    for idx, c in enumerate(candidates):
        if c.claim_status in {"MAIN_FINDING", "ACTUAL_RESULT", "DECISION", "RESEARCH_OBJECTIVE"}:
            density_scores[idx] += 0.14
        elif c.source_role in {"result", "objective", "decision"}:
            density_scores[idx] += 0.10
        elif c.section in {"background", "literature_review"}:
            density_scores[idx] -= 0.08
    strat_density = select_candidate_indices_with_profile(
        candidates,
        density_scores,
        article_type,
        target_count,
        section_coverage_groups,
        section_coverage_weight,
        sel_profile,
        topic_clusters=clusters,
        depth_key=depth_key,
        depth_config=depth_cfg,
        redundancy_filtering=True,
        _features=features,
        priority_indices=primary_finding_indices,
    )
    strat_density = ensure_depth_coverage(
        strat_density, candidates, density_scores, target_count,
        include_limitations=include_limitations,
        include_conclusion=include_conclusion,
        include_methodology=include_methodology,
        include_definition=include_definition,
        priority_indices=primary_finding_indices,
    )
    strategies["high_information_density"] = strat_density

    # Candidate Strategy 3: Broad Topic Coverage (Maximizes topic cluster breadth)
    if len(clusters) > 1:
        broad_cfg = dict(depth_cfg)
        broad_cfg["max_topics"] = max(int(depth_cfg.get("max_topics", 6)) + 2, len(clusters))
        strat_broad = select_candidate_indices_with_profile(
            candidates,
            combined_scores,
            article_type,
            target_count,
            section_coverage_groups,
            min(1.0, section_coverage_weight * 1.3),
            sel_profile,
            topic_clusters=clusters,
            depth_key=depth_key,
            depth_config=broad_cfg,
            _features=features,
            priority_indices=primary_finding_indices,
        )
        strat_broad = ensure_depth_coverage(
            strat_broad, candidates, combined_scores, target_count,
            include_limitations=include_limitations,
            include_conclusion=include_conclusion,
            include_methodology=include_methodology,
            include_definition=include_definition,
            priority_indices=primary_finding_indices,
        )
        strategies["broad_topic_coverage"] = strat_broad

    # Candidate Strategy 4: Coherence Prioritized (Strict antecedent resolution & discourse flow)
    # Its selection and coverage parameters are identical to balanced, whose
    # MMR already includes antecedent/discourse rules. Keep the public strategy
    # and quality metadata while reusing the identical selection.
    strategies["coherence_prioritized"] = list(strat_balanced)

    # Candidate Strategy 5: Semantic Alignment (Dense sentence transformer similarities)
    if dense_similarities is not None and len(dense_similarities) == len(candidates):
        semantic_scores = [
            cs + (0.22 * max(0.0, float(ds)))
            for cs, ds in zip(combined_scores, dense_similarities, strict=True)
        ]
        strat_semantic = select_candidate_indices_with_profile(
            candidates,
            semantic_scores,
            article_type,
            target_count,
            section_coverage_groups,
            section_coverage_weight,
            sel_profile,
            topic_clusters=clusters,
            depth_key=depth_key,
            depth_config=depth_cfg,
            _features=features,
            priority_indices=primary_finding_indices,
        )
        strat_semantic = ensure_depth_coverage(
            strat_semantic, candidates, semantic_scores, target_count,
            include_limitations=include_limitations,
            include_conclusion=include_conclusion,
            include_methodology=include_methodology,
            include_definition=include_definition,
            priority_indices=primary_finding_indices,
        )
        strategies["semantic_alignment"] = strat_semantic

    # Pre-extract token sets for vectorized set operations across graph and candidate evaluation
    cand_tokens = [set(c.normalized_text.lower().split()) for c in candidates]

    # Candidate Strategy 6: Graph Centrality (NetworkX TextRank / PageRank)
    if len(candidates) >= 4:
        try:
            import networkx as nx

            graph = nx.Graph()
            for i in range(len(candidates)):
                graph.add_node(i)

            for i in range(len(candidates)):
                s1 = cand_tokens[i]
                if not s1:
                    continue
                for j in range(i + 1, len(candidates)):
                    s2 = cand_tokens[j]
                    if not s2:
                        continue
                    union_len = len(s1 | s2)
                    if union_len == 0:
                        continue
                    weight = len(s1 & s2) / union_len
                    if weight >= 0.12:
                        graph.add_edge(i, j, weight=float(weight))

            if graph.number_of_edges() > 0:
                pagerank_scores = nx.pagerank(graph, alpha=0.85, max_iter=200, weight="weight")
                max_pr = max(pagerank_scores.values()) if pagerank_scores else 1.0

                graph_scores = [
                    cs + (0.20 * (pagerank_scores.get(idx, 0.0) / max(1e-6, max_pr)))
                    for idx, cs in enumerate(combined_scores)
                ]
                strat_graph = select_candidate_indices_with_profile(
                    candidates,
                    graph_scores,
                    article_type,
                    target_count,
                    section_coverage_groups,
                    section_coverage_weight,
                    sel_profile,
                    topic_clusters=clusters,
                    depth_key=depth_key,
                    depth_config=depth_cfg,
                    _features=features,
                    priority_indices=primary_finding_indices,
                )
                strat_graph = ensure_depth_coverage(
                    strat_graph, candidates, graph_scores, target_count,
                    include_limitations=include_limitations,
                    include_conclusion=include_conclusion,
                    include_methodology=include_methodology,
                    include_definition=include_definition,
                    priority_indices=primary_finding_indices,
                )
                strategies["graph_centrality"] = strat_graph
        except Exception:
            pass

    # Evidence-based multi-criteria candidate evaluation
    scored_candidates: dict[str, dict[str, Any]] = {}
    total_clusters = max(1, len(clusters))
    evaluated_cache: dict[tuple[int, ...], dict[str, Any]] = {}

    for strat_name, indices in strategies.items():
        if not indices:
            continue

        eval_key = tuple(indices)
        if eval_key in evaluated_cache:
            scored_candidates[strat_name] = evaluated_cache[eval_key]
            continue

        selected_cands = [candidates[i] for i in indices]

        # 1. Topic Cluster Coverage (Fraction of detected clusters covered)
        covered_clusters = {cand_to_cluster[i] for i in indices if i in cand_to_cluster}
        topic_coverage = len(covered_clusters) / total_clusters

        # 2. Information Density (Mean relevance score of selected candidates)
        info_density = sum(combined_scores[i] for i in indices) / len(indices)

        # 3. Redundancy (Max pairwise Jaccard overlap among selected candidates)
        max_overlap = 0.0
        for i_pos in range(len(indices)):
            s1 = cand_tokens[indices[i_pos]]
            for j_pos in range(i_pos + 1, len(indices)):
                s2 = cand_tokens[indices[j_pos]]
                u = len(s1 | s2)
                ov = (len(s1 & s2) / u) if u > 0 else 0.0
                if ov > max_overlap:
                    max_overlap = ov
        novelty_score = 1.0 - max_overlap

        # 4. Discourse Coherence & Antecedent Integrity
        first_cand = selected_cands[0]
        coherence = 1.0
        if candidate_has_unresolved_antecedent(indices[0], candidates, set()):
            coherence -= 0.30
        for pos in range(1, len(indices)):
            prev_idx = indices[pos - 1]
            curr_idx = indices[pos]
            if candidate_has_unresolved_antecedent(curr_idx, candidates, set(indices[:pos])):
                coherence -= 0.15

        # 5. Semantic Relevance
        semantic_rel = 0.5
        if dense_similarities is not None:
            semantic_rel = sum(dense_similarities[i] for i in indices) / len(indices)
            semantic_rel = max(0.0, min(1.0, (semantic_rel + 1.0) / 2.0))

        # Composite quality score
        composite_score = (
            (0.28 * topic_coverage)
            + (0.24 * info_density)
            + (0.22 * semantic_rel)
            + (0.16 * novelty_score)
            + (0.10 * max(0.0, coherence))
        )

        metrics_entry = {
            "indices": indices,
            "composite_score": round(float(composite_score), 4),
            "topic_coverage": round(float(topic_coverage), 4),
            "info_density": round(float(info_density), 4),
            "novelty_score": round(float(novelty_score), 4),
            "coherence_score": round(float(coherence), 4),
            "semantic_relevance": round(float(semantic_rel), 4),
        }
        scored_candidates[strat_name] = metrics_entry
        evaluated_cache[eval_key] = metrics_entry

    # Best strategy selection
    best_strategy = max(
        scored_candidates.keys(),
        key=lambda k: scored_candidates[k]["composite_score"],
    )
    best_indices = scored_candidates[best_strategy]["indices"]

    quality_meta = {
        "selected_strategy": best_strategy,
        "composite_score": scored_candidates[best_strategy]["composite_score"],
        "strategies_evaluated": list(scored_candidates.keys()),
        "strategy_scores": {
            k: v["composite_score"] for k, v in scored_candidates.items()
        },
        "quality_metrics": scored_candidates[best_strategy],
    }

    return best_indices, quality_meta


__all__ = [
    "detect_topic_clusters",
    "ensure_depth_coverage",
    "select_candidate_indices",
    "select_candidate_indices_with_profile",
]
