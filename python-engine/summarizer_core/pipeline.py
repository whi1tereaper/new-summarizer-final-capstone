"""Compatibility pipeline exports for the refactored summarizer package."""

from __future__ import annotations

from .models import (
    PreprocessingOptions,
    SummarizationPipeline,
    SummarizationRequest,
    SummarizationResult,
)


def summarize_document(
    text: str = "",
    file_path: str = "",
    sentence_count: int = 5,
    preprocessing_options: PreprocessingOptions | None = None,
    summary_style: str = "standard_paragraph",
    summary_length: str = "balanced",
) -> SummarizationResult:
    pipeline = SummarizationPipeline()
    request = SummarizationRequest(
        text=text,
        file_path=file_path,
        sentence_count=sentence_count,
        preprocessing_options=preprocessing_options,
        summary_style=summary_style,
        summary_length=summary_length,
    )
    return pipeline.summarize(request)

