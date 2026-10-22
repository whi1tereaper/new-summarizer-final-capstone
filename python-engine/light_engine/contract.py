"""Versioned request validation for the independent LIGHT NLP engine."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any
from failure_contract import ProcessingFailure

CONTRACT_VERSION = 1
MAX_TEXT_CHARACTERS = 2_000_000
MAX_UPLOAD_BYTES = 30 * 1024 * 1024

ANALYSIS_MODES = frozenset({
    "general", "academic", "executive", "study", "technical", "news", "legal", "scientific",
})
SUMMARY_DEPTHS = frozenset({
    "very_brief", "brief", "short", "balanced", "detailed", "comprehensive",
})
OUTPUT_FORMATS = frozenset({
    "paragraph", "bullets", "hybrid", "structured", "key_takeaways",
})


class ContractError(ProcessingFailure):
    """Input or output contract validation failure with a stable error code."""

    def __init__(self, code: str, message: str) -> None:
        stable_code = {
            "invalid_request": "REQUEST_INVALID", "unsupported_contract": "REQUEST_INVALID",
            "unsupported_setting": "SETTINGS_INVALID", "empty_source": "INPUT_EMPTY",
            "input_too_large": "INPUT_TOO_LARGE", "source_unavailable": "SOURCE_UNAVAILABLE",
            "source_path_rejected": "ACCESS_DENIED", "encrypted_document": "FILE_ENCRYPTED",
            "unreadable_document": "FILE_TEXT_UNAVAILABLE", "extraction_failed": "DOCUMENT_EXTRACTION_FAILED",
            "unsupported_file_type": "FILE_UNSUPPORTED", "no_sentences": "NLP_NO_VALID_CANDIDATES",
            "no_summary": "NLP_NO_VALID_CANDIDATES", "provenance_failure": "SUMMARY_QUALITY_FAILED",
        }.get(code, code)
        super().__init__(stable_code, message)
        # Existing evaluation consumers still use the original internal code.
        self.code = code


@dataclass(frozen=True)
class SummaryRequest:
    text: str
    file_path: str
    analysis_mode: str
    summary_depth: str
    output_format: str
    document_title: str
    focus_query: str
    sentence_count: int
    target_word_budget: int
    preprocessing: dict[str, bool]
    use_llm_synthesis: bool

    @classmethod
    def parse(cls, payload: Any) -> SummaryRequest:
        if not isinstance(payload, dict):
            raise ContractError("invalid_request", "Request must be a JSON object.")
        version = payload.get("schema_version")
        if version != CONTRACT_VERSION:
            raise ContractError("unsupported_contract", "Unsupported summarization request schema.")

        text = payload.get("text", "")
        file_path = payload.get("file_path", "")
        if not isinstance(text, str) or not isinstance(file_path, str):
            raise ContractError("invalid_request", "Text and file path must be strings.")
        if len(text) > MAX_TEXT_CHARACTERS:
            raise ContractError("input_too_large", "The document exceeds the supported text limit.")

        mode = payload.get("analysis_mode", payload.get("selection_mode", "general"))
        depth = payload.get("summary_depth", payload.get("summary_length", "balanced"))
        output_format = payload.get("output_format", "paragraph")
        for value, allowed, label in (
            (mode, ANALYSIS_MODES, "analysis mode"),
            (depth, SUMMARY_DEPTHS, "summary depth"),
            (output_format, OUTPUT_FORMATS, "output format"),
        ):
            if not isinstance(value, str) or value.strip().lower() not in allowed:
                raise ContractError("unsupported_setting", f"Unsupported {label}.")

        sentence_count = payload.get("sentence_count", 8)
        if isinstance(sentence_count, bool) or not isinstance(sentence_count, int) or not 1 <= sentence_count <= 50:
            raise ContractError("invalid_request", "Sentence count must be an integer from 1 to 50.")

        target_word_budget = payload.get("target_word_budget", 0)
        if (
            isinstance(target_word_budget, bool)
            or not isinstance(target_word_budget, int)
            or not 0 <= target_word_budget <= 50_000
        ):
            raise ContractError("invalid_request", "Target word budget must be an integer from 0 to 50000.")

        preprocessing = payload.get("preprocessing", {})
        if not isinstance(preprocessing, dict) or any(
            not isinstance(key, str) or not isinstance(value, bool)
            for key, value in preprocessing.items()
        ):
            raise ContractError("invalid_request", "Preprocessing options must be boolean values.")

        use_llm = payload.get("use_llm_synthesis", False)
        if not isinstance(use_llm, bool):
            raise ContractError("invalid_request", "Synthesis preference must be a boolean.")
        if use_llm:
            raise ContractError(
                "unsupported_setting",
                "Language-model synthesis is unavailable in the local extractive engine.",
            )

        focus_query = payload.get("focus_query", "")
        document_title = payload.get("document_title", "")
        if not isinstance(focus_query, str) or not isinstance(document_title, str):
            raise ContractError("invalid_request", "Title and focus query must be strings.")
        if len(focus_query) > 300 or len(document_title) > 255:
            raise ContractError("invalid_request", "Title or focus query exceeds its supported limit.")

        if not text.strip() and not file_path.strip():
            raise ContractError("empty_source", "Provide document text or an uploaded document.")

        return cls(
            text=text,
            file_path=file_path,
            analysis_mode=mode.strip().lower(),
            summary_depth=depth.strip().lower(),
            output_format=output_format.strip().lower(),
            document_title=document_title.strip(),
            focus_query=focus_query.strip(),
            sentence_count=sentence_count,
            target_word_budget=target_word_budget,
            preprocessing=dict(preprocessing),
            use_llm_synthesis=use_llm,
        )
