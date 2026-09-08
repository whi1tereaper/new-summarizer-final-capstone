"""
Hybrid extractive summarization engine for general article types.

The implementation keeps the existing PHP/Python summarize contract intact while
generalizing the NLP pipeline for academic articles, news, blogs, reports,
case studies, reviews, and plain-text articles.
"""

from __future__ import annotations

# Re-export the public API from the refactored summarizer_core package
from summarizer_core import (
    PreprocessingOptions,
    SummarizationPipeline,
    SummarizationRequest,
    SummarizationResult,
    summarize_document,
)
from summarizer_core.text_utils import extract_pdf_text, load_source_text

# Legacy aliases for backward compatibility
Summarizer = SummarizationPipeline

__all__ = [
    "PreprocessingOptions",
    "SummarizationPipeline",
    "SummarizationRequest",
    "SummarizationResult",
    "Summarizer",
    "extract_pdf_text",
    "load_source_text",
    "summarize_document",
]