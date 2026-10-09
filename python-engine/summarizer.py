"""
Hybrid extractive summarization engine for general article types.

The implementation keeps the existing PHP/Python summarize contract intact while
generalizing the NLP pipeline for academic articles, news, blogs, reports,
case studies, reviews, and plain-text articles.
"""

from __future__ import annotations

from typing import Any

# Re-export the public API from the refactored summarizer_core package
from summarizer_core import (
    PreprocessingOptions,
    SummarizationPipeline,
    SummarizationRequest,
    SummarizationResult,
    summarize_document,
)
from summarizer_core.prompts import (
    ALLOWED_LENGTHS,
    ALLOWED_PROFILES,
    render_combination_prompt,
)
from summarizer_core.text_utils import extract_pdf_text, load_source_text
from pure_nlp import summarize_to_contract

# Legacy aliases for backward compatibility
Summarizer = SummarizationPipeline


def generate_summary(
    source_text: str,
    profile: str = "general",
    length: str = "balanced",
) -> dict[str, Any]:
    """Refactored combinable generation function: (sourceText, profile, length) -> { summary, metadata }.

    Produces exactly one specific summary for the requested profile and length pair.
    """
    clean_text = (source_text or "").strip()
    norm_profile = (profile or "").strip().lower()
    norm_length = (length or "").strip().lower()

    if norm_profile not in ALLOWED_PROFILES:
        raise ValueError(
            f"Invalid profile '{profile}'. Allowed profiles: {', '.join(ALLOWED_PROFILES)}."
        )
    if norm_length not in ALLOWED_LENGTHS:
        raise ValueError(
            f"Invalid length '{length}'. Allowed lengths: {', '.join(ALLOWED_LENGTHS)}."
        )

    words = clean_text.split()
    source_word_count = len(words)

    # Nonsense guard: very short sources cannot support expanded depth without inventing content
    if source_word_count < 10 or (source_word_count < 50 and norm_length == "comprehensive") or (source_word_count < 25 and norm_length == "detailed"):
        summary_text = "source is too short to expand"
    else:
        req = SummarizationRequest(
            text=clean_text,
            selection_mode=norm_profile,
            analysis_mode=norm_profile,
            summary_style=norm_profile,
            summary_depth=norm_length,
            summary_length=norm_length,
        )
        res = SummarizationPipeline().summarize(req)
        summary_text = (res.plain_summary or "").strip()
        if not summary_text and res.sentences:
            summary_text = " ".join(s.text for s in res.sentences)

    summary_word_count = len(summary_text.split()) if summary_text else 0

    if source_word_count > 0:
        ratio = 1.0 - (summary_word_count / source_word_count)
        compression_ratio = round(max(0.0, min(1.0, ratio)), 2)
    else:
        compression_ratio = 0.0

    reading_time_seconds = round((summary_word_count / 200.0) * 60)

    metadata = {
        "profile": norm_profile,
        "length": norm_length,
        "wordCount": summary_word_count,
        "sourceWordCount": source_word_count,
        "compressionRatio": compression_ratio,
        "estimatedReadingTimeSeconds": reading_time_seconds,
    }

    return {
        "summary": summary_text,
        "metadata": metadata,
    }


__all__ = [
    "ALLOWED_LENGTHS",
    "ALLOWED_PROFILES",
    "PreprocessingOptions",
    "SummarizationPipeline",
    "SummarizationRequest",
    "SummarizationResult",
    "Summarizer",
    "extract_pdf_text",
    "generate_summary",
    "load_source_text",
    "render_combination_prompt",
    "summarize_document",
    "summarize_to_contract",
]