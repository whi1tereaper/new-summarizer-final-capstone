"""Request-local profile-aware retrieval and evidence mapping."""

from __future__ import annotations

import math
import re
from collections import defaultdict
from typing import Any

import numpy as np
from sklearn.metrics.pairwise import cosine_similarity

from .chunking import chunk_candidates
from .embeddings import embed_texts
from .models import SentenceCandidate
from .retrieval_models import RetrievalChunk, RetrievalHit, RetrievalOutcome

MIN_RETRIEVAL_WORDS = 250
MIN_RETRIEVAL_CHUNKS = 3
MAX_RETRIEVAL_CHUNKS = 24
MAX_RETRIEVAL_SCORE_ADJUSTMENT = 0.06

_PROFILE_QUERIES = {
    "academic": "research methods methodology study results findings evidence limitations conclusion",
    "executive": "decisions business impact budget risks recommendations priorities outcomes",
    "technical": "system architecture implementation components dependencies constraints performance",
    "news": "events people organizations dates locations developments impact status",
    "study": "key concepts definitions principles explanations examples and learning outcomes",
    "general": "central ideas key facts findings causes outcomes decisions evidence and conclusions",
}
_DEPTH_BREADTH = {
    "brief": 1.0,
    "short": 1.2,
    "balanced": 1.45,
    "detailed": 1.7,
    "comprehensive": 2.0,
}
_TOKEN_PATTERN = re.compile(r"\b\w+\b", re.UNICODE)


def retrieve_candidates(
    candidates: list[SentenceCandidate],
    *,
    analysis_mode: str,
    summary_depth: str,
    document_title: str,
    target_count: int,
    source_word_count: int,
    compression_bias: str,
) -> RetrievalOutcome:
    """Rank request-local chunks and produce bounded candidate-score adjustments."""
    chunks = chunk_candidates(candidates)
    empty_adjustments = tuple(0.0 for _ in candidates)
    metadata: dict[str, Any] = {
        "status": "bypassed",
        "backend": "none",
        "fallback_used": False,
        "fallback_reason": "",
        "chunk_count": len(chunks),
        "retrieved_chunk_count": 0,
        "retrieval_breadth": 0,
        "profile": analysis_mode,
        "summary_depth": summary_depth,
        "semantic_score_adjustment_limit": MAX_RETRIEVAL_SCORE_ADJUSTMENT,
        "coverage_ratio": 0.0,
        "retrieved_sections": [],
        "evidence_mapping_status": "unavailable",
    }

    if not chunks:
        metadata["fallback_reason"] = "no_retrievable_sentences"
        return RetrievalOutcome(metadata=metadata, score_adjustments=empty_adjustments)
    if source_word_count < MIN_RETRIEVAL_WORDS or len(chunks) < MIN_RETRIEVAL_CHUNKS:
        metadata["fallback_reason"] = (
            "short_document"
            if source_word_count < MIN_RETRIEVAL_WORDS
            else "insufficient_chunk_breadth"
        )
        return RetrievalOutcome(
            metadata=metadata,
            score_adjustments=empty_adjustments,
            chunks=tuple(chunks),
        )

    profile_key = analysis_mode.strip().lower()
    query_parts = (
        document_title.strip(),
        _PROFILE_QUERIES.get(profile_key, _PROFILE_QUERIES["general"]),
    )
    query = " ".join(part for part in query_parts if part)
    embedding_output = embed_texts([query, *(chunk.text for chunk in chunks)])
    vectors = _validate_vectors(embedding_output.vectors, len(chunks) + 1)
    relevance = np.asarray(cosine_similarity(vectors[0:1], vectors[1:])[0], dtype=float)
    if relevance.shape != (len(chunks),) or not np.isfinite(relevance).all():
        raise ValueError("Retrieval backend returned malformed similarity scores.")
    relevance = np.clip(relevance, 0.0, 1.0)

    breadth = _retrieval_breadth(target_count, summary_depth, compression_bias, len(chunks))
    hit_indices = _select_diverse_chunks(chunks, relevance, vectors[1:], breadth)
    hits = tuple(
        RetrievalHit(
            chunk_id=chunks[index].chunk_id,
            chunk_index=index,
            relevance_score=float(relevance[index]),
            rank=rank,
        )
        for rank, index in enumerate(hit_indices, start=1)
    )

    candidate_to_retrieved_scores: dict[int, float] = {}
    for hit in hits:
        for candidate_index in chunks[hit.chunk_index].candidate_indices:
            candidate_to_retrieved_scores[candidate_index] = max(
                candidate_to_retrieved_scores.get(candidate_index, 0.0),
                hit.relevance_score,
            )
    score_adjustments = tuple(
        _bounded_score_adjustment(candidate_to_retrieved_scores.get(candidate.index, 0.0))
        for candidate in candidates
    )

    retrieved_paragraphs = {
        chunks[hit.chunk_index].paragraph_index for hit in hits
    }
    all_paragraphs = {chunk.paragraph_index for chunk in chunks}
    metadata.update({
        "status": "active",
        "backend": embedding_output.backend,
        "fallback_used": embedding_output.backend != "sentence_transformers_local",
        "fallback_reason": embedding_output.fallback_reason,
        "retrieved_chunk_count": len(hits),
        "retrieval_breadth": breadth,
        "coverage_ratio": round(
            len(retrieved_paragraphs) / max(1, len(all_paragraphs)),
            3,
        ),
        "retrieved_sections": list(dict.fromkeys(chunks[hit.chunk_index].section for hit in hits)),
    })
    return RetrievalOutcome(
        metadata=metadata,
        score_adjustments=score_adjustments,
        chunks=tuple(chunks),
        hits=hits,
        relevance_scores=tuple(float(score) for score in relevance),
    )


def map_selected_evidence(
    outcome: RetrievalOutcome,
    candidates: list[SentenceCandidate],
    selected_sentences: list[str],
) -> list[dict[str, Any]]:
    """Map each output sentence to its best source chunk and exact candidate."""
    if not outcome.chunks or not selected_sentences:
        return []

    chunks_by_candidate: dict[int, list[int]] = defaultdict(list)
    for chunk_index, chunk in enumerate(outcome.chunks):
        for candidate_index in chunk.candidate_indices:
            chunks_by_candidate[candidate_index].append(chunk_index)

    hit_rank = {hit.chunk_index: hit for hit in outcome.hits}
    evidence: list[dict[str, Any]] = []
    evidence_by_chunk: dict[str, dict[str, Any]] = {}
    used_candidates: set[int] = set()
    for summary_sentence in selected_sentences:
        candidate = _best_candidate_match(summary_sentence, candidates, used_candidates)
        if candidate is None:
            continue
        used_candidates.add(candidate.index)
        source_chunks = chunks_by_candidate.get(candidate.index, [])
        if not source_chunks:
            continue
        best_chunk_index = max(
            source_chunks,
            key=lambda index: (
                hit_rank[index].relevance_score if index in hit_rank else 0.0,
                -index,
            ),
        )
        chunk = outcome.chunks[best_chunk_index]
        existing = evidence_by_chunk.get(chunk.chunk_id)
        if existing is not None:
            supports = existing.get("supports", existing["summary_sentence"])
            if summary_sentence not in supports:
                existing["supports"] = f"{supports} {summary_sentence}"
            continue

        item = {
            "summary_sentence": summary_sentence,
            "supports": summary_sentence,
            "source_sentence": candidate.text,
            "excerpt": chunk.text,
            "chunk_id": chunk.chunk_id,
            "paragraph_index": chunk.paragraph_index,
            "section": chunk.section,
            "retrieved": best_chunk_index in hit_rank,
            "relevance_score": (
                hit_rank[best_chunk_index].relevance_score
                if best_chunk_index in hit_rank
                else 0.0
            ),
        }
        evidence_by_chunk[chunk.chunk_id] = item
        evidence.append(item)
    return evidence


def select_diverse_chunks(
    chunks: list[RetrievalChunk],
    relevance_scores: list[float],
    chunk_vectors: Any,
    breadth: int,
) -> list[int]:
    """Select relevant chunks with MMR novelty and a modest section-diversity bonus."""
    return _select_diverse_chunks(chunks, np.asarray(relevance_scores), chunk_vectors, breadth)


def _select_diverse_chunks(
    chunks: list[RetrievalChunk],
    relevance: np.ndarray,
    vectors: Any,
    breadth: int,
) -> list[int]:
    if len(chunks) != len(relevance):
        raise ValueError("Chunk and relevance-score counts do not match.")
    if breadth < 0:
        raise ValueError("Retrieval breadth cannot be negative.")
    if not chunks or breadth == 0:
        return []
    selected: list[int] = []
    selected_sections: set[str] = set()
    max_similarity_to_selected = np.zeros(len(chunks), dtype=float)
    while len(selected) < min(breadth, len(chunks)):
        best_index = -1
        best_value = float("-inf")
        for index, chunk in enumerate(chunks):
            if index in selected:
                continue
            novelty = 1.0 - float(max_similarity_to_selected[index])
            section_bonus = 0.06 if selected and chunk.section not in selected_sections else 0.0
            value = 0.76 * float(relevance[index]) + 0.24 * novelty + section_bonus
            if value > best_value:
                best_index, best_value = index, value
        if best_index < 0:
            break
        selected.append(best_index)
        selected_sections.add(chunks[best_index].section)
        new_similarities = np.asarray(
            cosine_similarity(vectors, vectors[best_index:best_index + 1])[:, 0],
            dtype=float,
        )
        max_similarity_to_selected = np.maximum(
            max_similarity_to_selected,
            np.clip(new_similarities, 0.0, 1.0),
        )
    return selected


def _validate_vectors(vectors: Any, expected_rows: int) -> Any:
    shape = getattr(vectors, "shape", None)
    if (
        not isinstance(shape, tuple)
        or len(shape) != 2
        or shape[0] != expected_rows
        or shape[1] < 1
    ):
        raise ValueError("Retrieval backend returned malformed embedding vectors.")
    values = getattr(vectors, "data", vectors)
    if not np.isfinite(np.asarray(values)).all():
        raise ValueError("Retrieval backend returned non-finite embedding vectors.")
    return vectors


def _retrieval_breadth(
    target_count: int,
    summary_depth: str,
    compression_bias: str,
    chunk_count: int,
) -> int:
    multiplier = _DEPTH_BREADTH.get(summary_depth, _DEPTH_BREADTH["balanced"])
    if compression_bias == "tight":
        multiplier *= 0.9
    elif compression_bias == "generous":
        multiplier *= 1.1
    requested = max(1, math.ceil(max(1, target_count) * multiplier))
    return min(chunk_count, MAX_RETRIEVAL_CHUNKS, requested)


def _bounded_score_adjustment(relevance: float) -> float:
    clamped = min(1.0, max(0.0, relevance))
    centered = (clamped - 0.5) * (2.0 * MAX_RETRIEVAL_SCORE_ADJUSTMENT)
    return min(MAX_RETRIEVAL_SCORE_ADJUSTMENT, max(-MAX_RETRIEVAL_SCORE_ADJUSTMENT, centered))


def _best_candidate_match(
    summary_sentence: str,
    candidates: list[SentenceCandidate],
    used_candidates: set[int],
) -> SentenceCandidate | None:
    summary_tokens = set(_TOKEN_PATTERN.findall(summary_sentence.casefold()))
    if not summary_tokens:
        return None
    best: SentenceCandidate | None = None
    best_overlap = 0.0
    for candidate in candidates:
        if candidate.index in used_candidates:
            continue
        candidate_tokens = set(_TOKEN_PATTERN.findall(candidate.text.casefold()))
        overlap = len(summary_tokens & candidate_tokens) / len(summary_tokens)
        if overlap > best_overlap:
            best, best_overlap = candidate, overlap
    return best if best_overlap >= 0.45 else None


__all__ = [
    "MAX_RETRIEVAL_SCORE_ADJUSTMENT",
    "MIN_RETRIEVAL_CHUNKS",
    "MIN_RETRIEVAL_WORDS",
    "map_selected_evidence",
    "retrieve_candidates",
    "select_diverse_chunks",
]
