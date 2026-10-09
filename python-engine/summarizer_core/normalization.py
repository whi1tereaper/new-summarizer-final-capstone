"""Text whitespace and string normalization helpers."""

from __future__ import annotations

import logging
import re
from typing import Any, Callable, cast

LOGGER = logging.getLogger(__name__)

# Runtime nlp_pipeline binding if available
try:
    from nlp_pipeline import normalize_whitespace as _pipeline_normalize_whitespace
    from nlp_pipeline import preprocess_text as _pipeline_preprocess_text
except ImportError:
    def _fallback_normalize_whitespace(text: str) -> str:
        return re.sub(r"\s+", " ", str(text or "")).strip()
    _pipeline_normalize_whitespace = _fallback_normalize_whitespace
    _pipeline_preprocess_text = None

_runtime_normalize_whitespace = cast(Callable[[str], str], _pipeline_normalize_whitespace)

def log_summary_event(event_name: str, **details: Any) -> None:
    if details:
        detail_text = ", ".join(f"{key}={details[key]!r}" for key in sorted(details))
        LOGGER.debug("%s: %s", event_name, detail_text)
        return
    LOGGER.debug("%s", event_name)


def _fallback_normalize_whitespace(text: str) -> str:
    return re.sub(r"\s+", " ", str(text or "")).strip()


def normalize_whitespace(text: str) -> str:
    return _runtime_normalize_whitespace(text)


def normalize_source_newlines(text: str) -> str:
    normalized = text.replace("\r\n", "\n").replace("\r", "\n").replace("\u00ad", "")
    normalized = re.sub(r"(?<=\w)-\n(?=\w)", "", normalized)
    return normalized


def normalize_pdf_line_breaks(text: str) -> str:
    normalized = normalize_source_newlines(text)
    normalized = re.sub(r"[ \t]+\n", "\n", normalized)
    normalized = re.sub(r"\n[ \t]+", "\n", normalized)
    normalized = re.sub(r"(?<=[a-z])\s+-\s+(?=[a-z])", "-", normalized)
    normalized = re.sub(r"\b([A-Za-z])\s+-\s+([A-Za-z]{3,})\b", r"\1-\2", normalized)
    normalized = re.sub(r"\bAI\s+-\s+generated\b", "AI-generated", normalized, flags=re.IGNORECASE)
    normalized = re.sub(r"\bstudent'sindependent\b", "student's independent", normalized, flags=re.IGNORECASE)
    return normalized


def normalize_summary_count(sentence_count: int) -> int:
    return max(1, int(sentence_count))


__all__ = ['_fallback_normalize_whitespace', 'log_summary_event', 'normalize_pdf_line_breaks', 'normalize_source_newlines', 'normalize_summary_count', 'normalize_whitespace']
