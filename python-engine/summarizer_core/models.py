"""Summarizer data models."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, TYPE_CHECKING

if TYPE_CHECKING:
    from .pipeline import SummarizationPipeline


@dataclass(frozen=True)
class PreprocessingOptions:
    lowercase: bool = False
    remove_punctuation: bool = False
    remove_stopwords: bool = False
    tokenize: bool = False
    lemmatize: bool = False
    remove_visual_artifacts: bool = True
    remove_email_addresses: bool = True
    remove_known_noise: bool = True
    normalize_whitespace: bool = True

    def as_dict(self) -> dict[str, bool]:
        return {
            "lowercase": self.lowercase,
            "remove_punctuation": self.remove_punctuation,
            "remove_stopwords": self.remove_stopwords,
            "tokenize": self.tokenize,
            "lemmatize": self.lemmatize,
            "remove_visual_artifacts": self.remove_visual_artifacts,
            "remove_email_addresses": self.remove_email_addresses,
            "remove_known_noise": self.remove_known_noise,
            "normalize_whitespace": self.normalize_whitespace,
        }

    @classmethod
    def from_mapping(cls, raw_options: Any) -> "PreprocessingOptions":
        if not isinstance(raw_options, dict):
            return cls()

        allowed = set(cls.__dataclass_fields__.keys())
        normalized = {
            key: bool(value)
            for key, value in raw_options.items()
            if key in allowed and isinstance(value, bool)
        }
        return cls(**normalized)


@dataclass(frozen=True)
class SummarizationRequest:
    text: str = ""
    file_path: str = ""
    sentence_count: int = 5
    preprocessing_options: PreprocessingOptions | None = None
    summary_style: str = "standard_paragraph"
    summary_length: str = "balanced"
    # Canonical semantic depth. summary_length remains as a compatibility alias.
    summary_depth: str = ""
    # Selection-aware intelligence fields (new)
    selection_mode: str = ""      # explicit mode key; falls back to summary_style when empty
    document_title: str = ""      # user-supplied title hint; supplements auto-detection
    analysis_mode: str = ""       # canonical profile key; kept separate from presentation
    output_format: str = ""       # paragraph, bullets, or structured
    use_llm_synthesis: bool = False # per-request opt-in; server configuration must also enable it
    target_word_budget: int = 0     # optional evaluation-only comparison budget


@dataclass(frozen=True)
class SourceDocument:
    raw_text: str
    source_type: str
    title_hint: str = ""
    # Page text is retained when the extractor can provide it (currently PDF).
    page_texts: tuple[str, ...] = ()


@dataclass(frozen=True)
class SourceInput:
    text: str = ""
    file_path: str = ""


@dataclass(frozen=True)
class CleanedDocument:
    source_document: SourceDocument
    paragraphs: list[str]
    cleaned_text: str


@dataclass(frozen=True)
class DocumentProfile:
    article_type: str
    article_label: str
    content_type_key: str
    content_type_label: str
    detected_signals: list[str]
    fallback_strategy: str
    sections_by_paragraph: tuple[str, ...]
    heading_counts: dict[str, int]


@dataclass(frozen=True)
class SentenceCandidate:
    index: int
    paragraph_index: int
    sentence_in_paragraph: int
    paragraph_sentence_count: int
    section: str
    text: str
    normalized_text: str
    ranking_text: str
    token_count: int
    source_sentence_id: str = ""
    section_id: str = ""
    page_number: int | None = None
    claim_type: str = "unknown"
    claim_status: str = "UNKNOWN"
    # Extended Provenance Model
    sentence_id: str = ""
    subsection: str = ""
    sentence_index: int = 0
    epistemic_status: str = "UNKNOWN"
    source_role: str = "unknown"
    attribution: str = ""
    document_stage: str = "unknown"
    numbers: tuple[str, ...] = ()
    qualifiers: tuple[str, ...] = ()
    negation: bool = False
    confidence: float = 0.0

    def __post_init__(self) -> None:
        sid = self.sentence_id or self.source_sentence_id or f"s_{self.index}"
        if not self.sentence_id:
            object.__setattr__(self, "sentence_id", sid)
        if not self.source_sentence_id:
            object.__setattr__(self, "source_sentence_id", sid)
        if self.sentence_index == 0 and self.index != 0:
            object.__setattr__(self, "sentence_index", self.index)
        elif self.index == 0 and self.sentence_index != 0:
            object.__setattr__(self, "index", self.sentence_index)
        if self.claim_status != "UNKNOWN" and self.epistemic_status == "UNKNOWN":
            mapped_status = {
                "ACTUAL_RESULT": "CURRENT_RESULT",
                "LITERATURE_FINDING": "PRIOR_STUDY_RESULT",
            }.get(self.claim_status, self.claim_status)
            object.__setattr__(self, "epistemic_status", mapped_status)
        elif self.epistemic_status != "UNKNOWN" and self.claim_status == "UNKNOWN":
            mapped_status = {
                "CURRENT_RESULT": "ACTUAL_RESULT",
                "PRIOR_STUDY_RESULT": "LITERATURE_FINDING",
            }.get(self.epistemic_status, self.epistemic_status)
            object.__setattr__(self, "claim_status", mapped_status)


@dataclass(frozen=True)
class DocumentStageDetection:
    document_stage: str
    confidence: float
    supporting_evidence: list[str] = field(default_factory=list)


@dataclass(frozen=True)
class QualityMetrics:
    coverage_score: float = 1.0
    redundancy_score: float = 0.0
    source_support_rate: float = 1.0
    attribution_preservation_rate: float = 1.0
    numeric_preservation_rate: float = 1.0
    qualifier_preservation_rate: float = 1.0
    section_coverage: float = 1.0
    dangling_reference_count: int = 0
    heading_leak_count: int = 0
    method: str = "deterministic_provenance_and_coverage_v1"

    def as_dict(self) -> dict[str, Any]:
        return {
            "coverage_score": round(self.coverage_score, 4),
            "redundancy_score": round(self.redundancy_score, 4),
            "source_support_rate": round(self.source_support_rate, 4),
            "attribution_preservation_rate": round(self.attribution_preservation_rate, 4),
            "numeric_preservation_rate": round(self.numeric_preservation_rate, 4),
            "qualifier_preservation_rate": round(self.qualifier_preservation_rate, 4),
            "section_coverage": round(self.section_coverage, 4),
            "dangling_reference_count": self.dangling_reference_count,
            "heading_leak_count": self.heading_leak_count,
            "method": self.method,
        }



@dataclass(frozen=True)
class ParagraphAnalysis:
    paragraph_number: int
    section: str
    purpose: str
    main_idea: str
    supporting_details: list[str]
    keywords: list[str]
    summary: str


@dataclass(frozen=True)
class SentenceScoringResult:
    vectorizer: Any
    matrix: Any
    similarity: Any
    combined_scores: Any
    keywords: list[str]
    noun_phrases: list[str]
    scoring_strategy: str
    fallback_used: bool = False


@dataclass(frozen=True)
class StructuredSummaryOutput:
    selected_sentences: list[str]
    overview: list[str]
    plain_summary: str
    keywords: list[str]
    important_terms: list[dict[str, str]]
    key_points: list[str]
    conclusion: str
    structured_summary: list[dict[str, str]]
    paragraph_summaries: list[ParagraphAnalysis]
    excluded_sections: list[str]


@dataclass(frozen=True)
class SummarizationResult:
    title: str
    raw_text: str
    cleaned_text: str
    sentences: list[str]
    sentence_count: int
    overview: list[str] = field(default_factory=list)
    plain_summary: str = ""
    overall_summary_bullets: list[str] = field(default_factory=list)
    preprocessing: dict[str, bool] = field(default_factory=dict)
    readability: dict[str, int | float | str] = field(default_factory=dict)
    summary_method: dict[str, object] = field(default_factory=dict)
    keywords: list[str] = field(default_factory=list)
    important_terms: list[dict[str, str]] = field(default_factory=list)
    article_type: str = "General"
    key_points: list[str] = field(default_factory=list)
    conclusion: str = ""
    structured_summary: list[dict[str, str]] = field(default_factory=list)
    paragraph_summaries: list[ParagraphAnalysis] = field(default_factory=list)
    excluded_sections: list[str] = field(default_factory=list)
    source_metadata: dict[str, Any] = field(default_factory=dict)
    method: str = "local"
    fallback_used: bool = False
    # Selection-aware profile fields (new)
    selection_mode: str = "general"
    profile_label: str = "General Summary"
    active_profile_weights: dict[str, float] = field(default_factory=dict)
    validation_passed: bool = True
    validation_notes: list[str] = field(default_factory=list)
    summary_depth: str = "balanced"
    analysis_mode: str = "general"
    output_format: str = "paragraph"
    retrieval_metadata: dict[str, Any] = field(default_factory=dict)
    evidence: list[dict[str, Any]] = field(default_factory=list)


def __getattr__(name: str) -> Any:
    """Backward-compatible attribute lookup for legacy imports."""
    if name == "SummarizationPipeline":
        from .pipeline import SummarizationPipeline
        return SummarizationPipeline
    raise AttributeError(f"module '{__name__}' has no attribute '{name}'")


def __dir__() -> list[str]:
    return sorted(list(globals().keys()) + ["SummarizationPipeline"])


__all__ = [
    "CleanedDocument",
    "DocumentProfile",
    "DocumentStageDetection",
    "ParagraphAnalysis",
    "PreprocessingOptions",
    "QualityMetrics",
    "SentenceCandidate",
    "SentenceScoringResult",
    "SourceDocument",
    "SourceInput",
    "StructuredSummaryOutput",
    "SummarizationPipeline",
    "SummarizationRequest",
    "SummarizationResult",
]

