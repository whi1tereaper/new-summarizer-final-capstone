"""Paragraph-aware chunking that retains sentence-to-source mappings."""

from __future__ import annotations

import re
from collections import defaultdict

from .models import SentenceCandidate
from .retrieval_models import RetrievalChunk

_WORD_PATTERN = re.compile(r"\b[\w'-]+\b", re.UNICODE)


def chunk_candidates(
    candidates: list[SentenceCandidate],
    *,
    max_words: int = 180,
    overlap_sentences: int = 1,
) -> list[RetrievalChunk]:
    """Build bounded paragraph-local chunks while preserving candidate indices."""
    if max_words < 1:
        raise ValueError("max_words must be positive.")
    if overlap_sentences < 0:
        raise ValueError("overlap_sentences cannot be negative.")

    by_paragraph: dict[int, list[SentenceCandidate]] = defaultdict(list)
    for candidate in candidates:
        by_paragraph[candidate.paragraph_index].append(candidate)

    chunks: list[RetrievalChunk] = []
    for paragraph_index in sorted(by_paragraph):
        paragraph_candidates = sorted(
            by_paragraph[paragraph_index],
            key=lambda candidate: candidate.sentence_in_paragraph,
        )
        current: list[SentenceCandidate] = []
        current_words = 0
        paragraph_chunk_number = 0

        for candidate in paragraph_candidates:
            sentence_words = len(_WORD_PATTERN.findall(candidate.text))
            if current and current_words + sentence_words > max_words:
                chunks.append(_make_chunk(paragraph_index, paragraph_chunk_number, current))
                paragraph_chunk_number += 1
                current = current[-overlap_sentences:] if overlap_sentences else []
                current_words = sum(
                    len(_WORD_PATTERN.findall(item.text)) for item in current
                )

            current.append(candidate)
            current_words += sentence_words

        if current:
            chunks.append(_make_chunk(paragraph_index, paragraph_chunk_number, current))

    return chunks


def _make_chunk(
    paragraph_index: int,
    paragraph_chunk_number: int,
    candidates: list[SentenceCandidate],
) -> RetrievalChunk:
    text = " ".join(
        candidate.text.strip()
        for candidate in candidates
        if candidate.text.strip()
    )
    section = next((candidate.section for candidate in candidates if candidate.section), "body")
    return RetrievalChunk(
        chunk_id=f"p{paragraph_index}-c{paragraph_chunk_number}",
        text=text,
        paragraph_index=paragraph_index,
        section=section,
        candidate_indices=tuple(candidate.index for candidate in candidates),
        word_count=len(_WORD_PATTERN.findall(text)),
    )
