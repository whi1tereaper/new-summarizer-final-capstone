"""Summarizer core package providing data models and orchestration pipeline."""

from __future__ import annotations

from typing import Any

from .models import PreprocessingOptions, SummarizationRequest, SummarizationResult

__all__ = [
    "PreprocessingOptions",
    "SummarizationPipeline",
    "SummarizationRequest",
    "SummarizationResult",
    "summarize_document",
]


def __getattr__(name: str) -> Any:
    if name in {"SummarizationPipeline", "summarize_document"}:
        from . import pipeline

        return getattr(pipeline, name)
    raise AttributeError(f"module '{__name__}' has no attribute '{name}'")


def __dir__() -> list[str]:
    return sorted(list(globals().keys()) + __all__)
