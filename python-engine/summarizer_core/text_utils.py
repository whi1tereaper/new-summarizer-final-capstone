"""Text processing compatibility facade and legacy utility helpers.

This module re-exports the decomposed text processing submodules:
- normalization: whitespace and string normalization
- tokenization: sentence/word tokenization, POS tagging, lemmatization
- structure: title detection, explicit section detection, and headings
- cleaning: document cleaning, noise filters, and artifact removal
- extraction: PDF, DOCX, URL, and document source loaders
"""

from __future__ import annotations

import logging
import math
import re
from typing import Any

import numpy as np
from sklearn.decomposition import TruncatedSVD
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics.pairwise import cosine_similarity

from .constants import *
from .models import (
    CleanedDocument,
    DocumentProfile,
    ParagraphAnalysis,
    PreprocessingOptions,
    SentenceCandidate,
    SentenceScoringResult,
    SourceDocument,
    SourceInput,
    StructuredSummaryOutput,
    SummarizationRequest,
    SummarizationResult,
)
from .normalization import *
from .tokenization import *
from .structure import *
from .cleaning import *
from .extraction import *

LOGGER = logging.getLogger(__name__)

def _legacy_article_type_family(article_type: str) -> str:
    return ARTICLE_TYPE_FAMILY.get(article_type, "general")


def _legacy_is_objective_sentence(text: str) -> bool:
    return OBJECTIVE_SENTENCE_PATTERN.search(text) is not None


def _legacy_is_method_sentence(text: str) -> bool:
    return re.search(r"\b(?:methodology|method|survey|experiment|approach)\b", text, re.I) is not None


def _legacy_has_result_cue(text: str) -> bool:
    return RESULT_CUE_PATTERN.search(text) is not None


def _legacy_normalize_scores(scores: np.ndarray) -> np.ndarray:
    if scores.size == 0:
        return scores
    min_val = np.min(scores)
    max_val = np.max(scores)
    if max_val == min_val:
        return np.zeros_like(scores)
    return (scores - min_val) / (max_val - min_val)


def _legacy_position_score(candidate: SentenceCandidate, total_sentences: int) -> float:
    if total_sentences <= 1:
        return 1.0
    # prioritize early and late sentences (U-shaped)
    progress = candidate.index / (total_sentences - 1)
    if progress < 0.15:
        return 1.0
    if progress > 0.85:
        return 0.9
    return max(0.4, 1.0 - (0.8 * progress))


def _legacy_paragraph_position_score(candidate: SentenceCandidate) -> float:
    if candidate.paragraph_sentence_count <= 1:
        return 1.0
    # prioritize first sentence of paragraph
    if candidate.sentence_in_paragraph == 0:
        return 1.0
    return 0.72


def _legacy_boilerplate_penalty_score(text: str, article_type: str) -> float:
    lowered = text.lower()
    penalty = 0.0
    if re.search(r"\b(?:all rights reserved|click here|read more|copyright|follow us)\b", lowered):
        penalty += 0.8
    if article_type == "official_report" and re.search(r"\b(?:annex|documentation|signature|prepared by|noted by)\b", lowered):
        penalty += 0.5
    return min(1.0, penalty)


def _legacy_article_type_relevance_score(text: str, article_type: str) -> float:
    patterns = ARTICLE_TYPE_RELEVANCE_PATTERNS.get(article_type, ARTICLE_TYPE_RELEVANCE_PATTERNS["general"])
    score = 0.0
    lowered = text.lower()
    for pattern in patterns:
        if pattern.search(lowered):
            score += 0.5
    return min(1.0, score)


def _legacy_score_textrank(matrix: np.ndarray, damping: float = 0.85, iterations: int = 20) -> np.ndarray:
    size = matrix.shape[0]
    if size == 0:
        return np.array([])
    scores = np.ones(size) / size
    # simple iterative power method
    for _ in range(iterations):
        prev_scores = scores.copy()
        for i in range(size):
            sum_val = 0.0
            for j in range(size):
                if i != j and matrix[j, i] > 0:
                    sum_val += matrix[j, i] * prev_scores[j] / max(1e-6, np.sum(matrix[j, :]))
            scores[i] = (1 - damping) / size + damping * sum_val
    return scores


def _legacy_score_lsa(matrix: np.ndarray) -> np.ndarray:
    if matrix.size == 0:
        return np.array([])
    # n_components should be at most min(n_samples, n_features) - 1
    n_components = min(matrix.shape[0], matrix.shape[1], 5)
    if n_components < 1:
        return np.zeros(matrix.shape[0])
    svd = TruncatedSVD(n_components=n_components)
    svd.fit(matrix)
    # the first singular vector represents the main topic
    return np.abs(svd.components_[0]) if svd.components_.size > 0 else np.zeros(matrix.shape[0])


def _legacy_tfidf_sentence_strength(matrix: Any) -> np.ndarray:
    if matrix is None:
        return np.array([])
    return np.asarray(matrix.sum(axis=1)).ravel()


def _legacy_title_similarity_score(title: str, vectorizer: TfidfVectorizer, matrix: Any) -> np.ndarray:
    if not title or matrix is None:
        return np.zeros(matrix.shape[0])
    title_vec = vectorizer.transform([title])
    return cosine_similarity(matrix, title_vec).ravel()


def combined_section_text(items: list[str] | tuple[str, ...] | str) -> str:
    if isinstance(items, str):
        return normalize_whitespace(items)

    cleaned = [
        normalize_whitespace(item)
        for item in items
        if normalize_whitespace(item) != ""
    ]
    return normalize_whitespace(" ".join(deduplicate_sentences(cleaned, threshold=DEFAULT_SECTION_SIMILARITY_THRESHOLD)))


def section_similarity_ratio(left: list[str] | tuple[str, ...] | str, right: list[str] | tuple[str, ...] | str) -> float:
    return sentence_overlap_ratio(combined_section_text(left), combined_section_text(right))


def max_sentence_similarity(
    left_items: list[str] | tuple[str, ...] | str,
    right_items: list[str] | tuple[str, ...] | str,
) -> float:
    left_list = [left_items] if isinstance(left_items, str) else list(left_items)
    right_list = [right_items] if isinstance(right_items, str) else list(right_items)
    highest = 0.0
    for left in left_list:
        normalized_left = normalize_whitespace(left)
        if normalized_left == "":
            continue
        for right in right_list:
            normalized_right = normalize_whitespace(right)
            if normalized_right == "":
                continue
            highest = max(highest, sentence_overlap_ratio(normalized_left, normalized_right))
    return highest


def build_readability_info(source_text: str, summary_text: str) -> dict[str, int | float | str]:
    original_word_count = count_words(source_text)
    summary_word_count = count_words(summary_text)
    compression_percent = round(
        (summary_word_count / max(original_word_count, 1)) * 100,
        2,
    )

    return {
        "original_word_count": original_word_count,
        "summary_word_count": summary_word_count,
        "compression_percent": compression_percent,
        "estimated_reading_time_minutes": estimate_reading_time_minutes(summary_word_count),
        "estimated_source_reading_time_minutes": estimate_reading_time_minutes(original_word_count),
    }


