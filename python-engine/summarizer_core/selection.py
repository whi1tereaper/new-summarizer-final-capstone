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

    sim_matrix = (tfidf_matrix * tfidf_matrix.T).toarray()
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
        for other in ranked_by_tfidf:
            if other not in assigned and sim_matrix[seed, other] >= similarity_threshold:
                cluster.append(other)
                assigned.add(other)
        clusters.append(cluster)

    return clusters


def select_candidate_indices_with_profile(
    candidates: list[SentenceCandidate],
    combined_scores: list[float],
    article_type: str,
    target_count: int,
    section_coverage_groups: dict[str, list[list[str]]],
    section_coverage_weight: float,
    sel_profile: Any,
    topic_clusters: list[list[int]] | None = None,
    depth_key: str = "balanced",
    depth_config: dict[str, Any] | None = None,
    topic_coverage_target: int = 3,
    redundancy_filtering: bool = True,
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

    topic_clusters = topic_clusters or []
    cand_to_cluster = {c_idx: cl_idx for cl_idx, cl in enumerate(topic_clusters) for c_idx in cl}
    depth_cfg = depth_config or {}
    max_topics = int(depth_cfg.get("max_topics", 6))
    redundancy_penalty_factor = float(depth_cfg.get("redundancy_penalty", 0.90))

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
            if redundancy_filtering and any(sentences_are_redundant(candidate.text, candidates[chosen].text) for chosen in selected):
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
                if redundancy_filtering and any(sentences_are_redundant(candidate.text, candidates[chosen].text) for chosen in selected):
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
            if redundancy_filtering and any(sentences_are_redundant(candidate.text, candidates[chosen].text) for chosen in selected):
                continue
            selected.append(index)
            selected_sentence_texts.append(candidate.text)
            represented_clusters.add(cand_cl)

    # --- 4. MMR fill to target ---
    score_floor = min(combined_scores) if combined_scores else 0.0
    score_span = max(combined_scores) - score_floor if combined_scores else 0.0

    has_discourse = [any(pattern.search(candidates[i].text) for pattern, _ in DISCOURSE_BOOST_PATTERNS.values()) for i in range(len(candidates))]

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
            if index in selected:
                continue
            candidate = candidates[index]
            if redundancy_filtering and candidate.text in selected_sentence_texts:
                continue

            cand_cl = cand_to_cluster.get(index, -1)
            redundancy = max(
                (sentence_token_overlap(candidate, candidates[chosen]) for chosen in selected),
                default=0.0,
            )
            if redundancy_filtering and (redundancy >= 0.68 or any(
                sentences_are_redundant(candidate.text, candidates[chosen].text)
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
        elif selected:
            lowest = min(selected, key=lambda index: combined_scores[index])
            selected[selected.index(lowest)] = replacement

    return sorted(set(selected), key=lambda index: candidates[index].index)


def select_candidate_indices(
    candidates: list[SentenceCandidate],
    combined_scores: list[float],
    article_type: str,
    target_count: int,
    section_coverage_groups: dict[str, list[list[str]]],
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


__all__ = [
    "detect_topic_clusters",
    "ensure_depth_coverage",
    "select_candidate_indices",
    "select_candidate_indices_with_profile",
]
