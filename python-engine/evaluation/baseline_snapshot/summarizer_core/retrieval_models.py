"""Request-local data contracts for source retrieval."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any


@dataclass(frozen=True)
class RetrievalChunk:
    chunk_id: str
    text: str
    paragraph_index: int
    section: str
    candidate_indices: tuple[int, ...]
    word_count: int


@dataclass(frozen=True)
class RetrievalHit:
    chunk_id: str
    chunk_index: int
    relevance_score: float
    rank: int


@dataclass(frozen=True)
class RetrievalOutcome:
    metadata: dict[str, Any]
    score_adjustments: tuple[float, ...]
    chunks: tuple[RetrievalChunk, ...] = ()
    hits: tuple[RetrievalHit, ...] = ()
    relevance_scores: tuple[float, ...] = ()
