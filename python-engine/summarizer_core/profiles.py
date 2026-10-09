"""Selection-specific summarization profiles.

Each SummarizationProfile encodes *how* a user's mode selection should
influence the entire pipeline — scoring weights, sentence bonuses/penalties,
preferred section coverage, compression bias, output schema, and validation
rules.

This is deliberately kept separate from constants.py so profiles can be
added or adjusted without touching the core scoring logic.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any


@dataclass(frozen=True)
class SummarizationProfile:
    """Behavioral specification for a single summarization mode.

    All scoring and structural decisions should be derived from these values
    rather than hard-coded inside the pipeline methods.
    """

    mode: str
    label: str

    # --- Scoring weights (must sum to ~1.0; pipeline normalises) ---
    scoring_weights: dict[str, float] = field(default_factory=dict)

    # --- Per-mode section weight overrides (merged on top of SECTION_IMPORTANCE) ---
    section_importance_override: dict[str, float] = field(default_factory=dict)

    # --- Sentence-level signals: list of (compiled_pattern, delta) ---
    importance_bonuses: list[tuple[Any, float]] = field(default_factory=list)
    importance_penalties: list[tuple[Any, float]] = field(default_factory=list)

    # --- Compression ---
    # "tight" = -15 % word budget, "generous" = +15 %, "balanced" = 0 %
    compression_bias: str = "balanced"

    # Minimum detail_level regardless of user's length choice (1-5)
    detail_level_floor: int = 1

    # --- Coverage ---
    preferred_sections: list[str] = field(default_factory=list)
    must_preserve_labels: list[str] = field(default_factory=list)

    # --- Output ---
    # Ordered list of structured-role section labels for this mode
    output_schema: list[str] = field(default_factory=list)

    # --- Validation ---
    factuality_strictness: str = "medium"   # "high" | "medium"
    validation_rules: list[str] = field(default_factory=list)

    # --- Phase 1/2 Faithfulness & Coherence weights ---
    # Multiplier for faithfulness checks (qualifier, negation, numeric)
    faithfulness_weight: float = 1.0
    # Toggle for negation preservation
    negation_preservation: bool = True
    # Toggle for numeric fact protection
    numeric_fact_protection: bool = True
    # Multiplier for discourse coherence bonuses
    coherence_weight: float = 1.0


# ---------------------------------------------------------------------------
# Helper: compile a pattern once and store alongside its delta
# ---------------------------------------------------------------------------

def _bp(pattern: str, delta: float) -> tuple[re.Pattern[str], float]:
    """Bonus pattern: compile and return (pattern, delta)."""
    return re.compile(pattern, re.IGNORECASE), delta


def _pp(pattern: str, delta: float) -> tuple[re.Pattern[str], float]:
    """Penalty pattern: compile and return (pattern, delta)."""
    return re.compile(pattern, re.IGNORECASE), delta


# ---------------------------------------------------------------------------
# Profile definitions
# ---------------------------------------------------------------------------

GENERAL_PROFILE = SummarizationProfile(
    mode="general",
    label="General Summary",
    scoring_weights={
        "tfidf":           0.38,
        "title":           0.18,
        "position":        0.14,
        "paragraph_pos":   0.10,
        "section":         0.12,
        "type_relevance":  0.08,
    },
    compression_bias="balanced",
    detail_level_floor=1,
    preferred_sections=["introduction", "overview", "body", "conclusion"],
    output_schema=["Overview", "Key Points", "Conclusion"],
    factuality_strictness="medium",
    validation_rules=["source_faithfulness"],
    faithfulness_weight=1.0,
    negation_preservation=True,
    numeric_fact_protection=True,
    coherence_weight=1.0,
)

GENERAL_BULLETS_PROFILE = SummarizationProfile(
    mode="general_bullets",
    label="Bullet Points Summary",
    scoring_weights={
        "tfidf":           0.38,
        "title":           0.18,
        "position":        0.14,
        "paragraph_pos":   0.10,
        "section":         0.12,
        "type_relevance":  0.08,
    },
    compression_bias="balanced",
    detail_level_floor=1,
    preferred_sections=["introduction", "overview", "body", "conclusion"],
    output_schema=["Key Points"],
    factuality_strictness="medium",
    validation_rules=["source_faithfulness"],
    faithfulness_weight=1.0,
    negation_preservation=True,
    numeric_fact_protection=True,
    coherence_weight=1.0,
)

GENERAL_HYBRID_PROFILE = SummarizationProfile(
    mode="general_hybrid",
    label="Hybrid Summary",
    scoring_weights={
        "tfidf":           0.38,
        "title":           0.18,
        "position":        0.15,
        "paragraph_pos":   0.10,
        "section":         0.11,
        "type_relevance":  0.08,
    },
    compression_bias="balanced",
    detail_level_floor=1,
    preferred_sections=["introduction", "overview", "body", "conclusion"],
    output_schema=["Overview", "Key Points"],
    factuality_strictness="medium",
    validation_rules=["source_faithfulness"],
    faithfulness_weight=1.0,
    negation_preservation=True,
    numeric_fact_protection=True,
    coherence_weight=1.0,
)

ACADEMIC_PROFILE = SummarizationProfile(
    mode="academic",
    label="Academic / Research Summary",
    scoring_weights={
        "tfidf":           0.30,
        "title":           0.16,
        "position":        0.10,
        "paragraph_pos":   0.08,
        "section":         0.22,  # heavily boost section weight for academic structure
        "type_relevance":  0.14,
    },
    section_importance_override={
        "abstract":        1.00,
        "methodology":     0.96,
        "results":         1.00,
        "discussion":      0.96,
        "conclusion":      1.00,
        "limitations":     0.80,
        "recommendations": 0.88,
        "literature_review": 0.62,
        "future_work":     0.55,
        "references":      0.00,
        "acknowledgment":  0.00,
    },
    importance_bonuses=[
        _bp(r"\b(?:results?|findings?)\s+(?:show|showed|reveal|revealed|indicate|indicated|suggest|suggested)\b", 0.12),
        _bp(r"\b(?:significantly|statistically|p\s*[<>=]\s*0\.\d+|r\s*=\s*[-\d.]+)\b", 0.10),
        _bp(r"\b(?:this study|the study|this research|this paper)\s+(?:aims?|shows?|found|reveals?|concludes?)\b", 0.10),
        _bp(r"\b(?:methodology|research design|data collection|sampling)\b", 0.08),
        _bp(r"\b(?:in conclusion|it is concluded|the study concludes|overall the study)\b", 0.09),
        _bp(r"\b(?:limitation|limitations|subject to|constrained by|caveat)\b", 0.07),
    ],
    importance_penalties=[
        _pp(r"\b(?:future research|further study|future work should|follow-up)\b", 0.10),
        _pp(r"\b(?:weighted mean|verbal interpretation|cluster\s+\d+)\b", 0.15),
        _pp(r"\b(?:this section|this chapter|this paper is organized)\b", 0.10),
    ],
    compression_bias="generous",
    detail_level_floor=2,
    preferred_sections=[
        "abstract", "introduction", "methodology",
        "results", "discussion", "conclusion", "limitations",
    ],
    must_preserve_labels=["Purpose", "Methodology", "Key Findings", "Conclusion"],
    output_schema=[
        "Purpose", "Methodology", "Key Findings", "Conclusion", "Recommendation",
    ],
    factuality_strictness="high",
    validation_rules=[
        "source_faithfulness",
        "must_preserve_completeness",
        "qualifier_preservation",
        "section_structure",
    ],
    faithfulness_weight=1.5,
    negation_preservation=True,
    numeric_fact_protection=True,
    coherence_weight=1.3,
)

EXECUTIVE_PROFILE = SummarizationProfile(
    mode="executive",
    label="Executive Summary",
    scoring_weights={
        "tfidf":           0.28,
        "title":           0.14,
        "position":        0.20,  # lead sentences matter more in executive mode
        "paragraph_pos":   0.14,
        "section":         0.16,
        "type_relevance":  0.08,
    },
    section_importance_override={
        "executive_summary": 1.00,
        "introduction":      0.80,
        "conclusion":        1.00,
        "recommendations":   1.00,
        "results":           0.96,
        "discussion":        0.88,
        "methodology":       0.45,  # de-emphasise method detail in executive output
        "limitations":       0.50,
        "future_work":       0.35,
        "references":        0.00,
        "acknowledgment":    0.00,
    },
    importance_bonuses=[
        _bp(r"\b(?:decision|decided|approved|action item|next step|priority|strategic)\b", 0.12),
        _bp(r"\b(?:risk|risks|mitigation|impact|implication|consequence)\b", 0.10),
        _bp(r"\b(?:revenue|cost|profit|savings|budget|investment|ROI|market share)\b", 0.10),
        _bp(r"\b(?:recommend|recommendation|it is recommended|we recommend)\b", 0.10),
        _bp(r"\b(?:key finding|major finding|primary outcome|core result)\b", 0.08),
        _bp(r"\b(?:in summary|in conclusion|overall|bottom line|net result)\b", 0.08),
    ],
    importance_penalties=[
        _pp(r"\b(?:methodology|research design|data collection|survey instrument|questionnaire)\b", 0.14),
        _pp(r"\b(?:literature review|previous studies|cited|et al\.)\b", 0.12),
        _pp(r"\b(?:background context|theoretical framework|conceptual framework)\b", 0.10),
    ],
    compression_bias="tight",
    detail_level_floor=1,
    preferred_sections=[
        "executive_summary", "introduction", "conclusion",
        "recommendations", "results",
    ],
    must_preserve_labels=["Summary", "Key Findings", "Recommendation"],
    output_schema=["Summary", "Key Findings", "Recommendation"],
    factuality_strictness="high",
    validation_rules=[
        "source_faithfulness",
        "qualifier_preservation",
        "section_structure",
    ],
    faithfulness_weight=1.3,
    negation_preservation=True,
    numeric_fact_protection=True,
    coherence_weight=1.2,
)

STUDY_PROFILE = SummarizationProfile(
    mode="study",
    label="Study / Learning Summary",
    scoring_weights={
        "tfidf":           0.32,
        "title":           0.20,  # title-topic alignment helps focus study content
        "position":        0.12,
        "paragraph_pos":   0.10,
        "section":         0.16,
        "type_relevance":  0.10,
    },
    section_importance_override={
        "introduction":    0.90,
        "background":      0.88,
        "body":            0.90,
        "overview":        0.90,
        "conclusion":      0.88,
        "references":      0.00,
        "acknowledgment":  0.00,
    },
    importance_bonuses=[
        _bp(r"\b(?:defined?|definition|refers? to|is known as|is called|means?|term)\b", 0.12),
        _bp(r"\b(?:for example|for instance|such as|to illustrate|consider|imagine)\b", 0.10),
        _bp(r"\b(?:important|key|fundamental|essential|core|central|critical)\s+(?:concept|idea|principle|point)\b", 0.10),
        _bp(r"\b(?:therefore|thus|as a result|consequently|this means|which means)\b", 0.08),
        _bp(r"\b(?:remember|note that|keep in mind|it is worth noting|takeaway)\b", 0.08),
    ],
    importance_penalties=[
        _pp(r"\b(?:statistical significance|p-value|confidence interval|regression)\b", 0.10),
        _pp(r"\b(?:methodology|data collection|survey instrument|research design)\b", 0.08),
        _pp(r"\b(?:citation|cited|et al\.|ibid|op\. cit\.)\b", 0.10),
    ],
    compression_bias="balanced",
    detail_level_floor=2,
    preferred_sections=[
        "introduction", "background", "overview", "body", "conclusion",
    ],
    must_preserve_labels=["Topic", "Core Explanation", "Example", "Takeaway"],
    output_schema=["Overview", "Core Concepts", "Examples", "Key Takeaways"],
    factuality_strictness="medium",
    validation_rules=[
        "source_faithfulness",
        "qualifier_preservation",
    ],
    faithfulness_weight=1.0,
    negation_preservation=True,
    numeric_fact_protection=True,
    coherence_weight=1.1,
)

TECHNICAL_PROFILE = SummarizationProfile(
    mode="technical",
    label="Technical Summary",
    scoring_weights={
        "tfidf":           0.32,
        "title":           0.14,
        "position":        0.12,
        "paragraph_pos":   0.08,
        "section":         0.20,
        "type_relevance":  0.14,
    },
    section_importance_override={
        "overview":           0.96,
        "system_description": 1.00,
        "introduction":       0.84,
        "body":               0.94,
        "conclusion":         0.88,
        "recommendations":    0.88,
        "references":         0.00,
        "acknowledgment":     0.00,
    },
    importance_bonuses=[
        _bp(r"\b(?:architecture|component|module|service|interface|api|endpoint|protocol)\b", 0.12),
        _bp(r"\b(?:requires?|depends? on|prerequisite|constraint|limitation)\b", 0.10),
        _bp(r"\b(?:configured?|install|setup|deploy|parameter|option|flag|config)\b", 0.10),
        _bp(r"\b(?:error|exception|warning|fail|failure|timeout|retry|fallback)\b", 0.08),
        _bp(r"\b(?:performance|latency|throughput|scalable|bottleneck|capacity)\b", 0.08),
        _bp(r"\b(?:security|authentication|authorization|permission|role|access control)\b", 0.08),
    ],
    importance_penalties=[
        _pp(r"\b(?:marketing|branding|testimonial|customer quote)\b", 0.12),
        _pp(r"\b(?:in my opinion|i believe|i think|personally)\b", 0.10),
    ],
    compression_bias="generous",
    detail_level_floor=2,
    preferred_sections=[
        "overview", "system_description", "body", "conclusion",
    ],
    must_preserve_labels=["Overview", "Implementation Steps", "Expected Result"],
    output_schema=["Overview", "Architecture", "Implementation Steps", "Expected Result"],
    factuality_strictness="high",
    validation_rules=[
        "source_faithfulness",
        "must_preserve_completeness",
        "section_structure",
    ],
    faithfulness_weight=1.4,
    negation_preservation=True,
    numeric_fact_protection=True,
    coherence_weight=1.2,
)

NEWS_PROFILE = SummarizationProfile(
    mode="news",
    label="News / Events Summary",
    scoring_weights={
        "tfidf":           0.30,
        "title":           0.20,
        "position":        0.24,  # lead/opening sentences carry the story
        "paragraph_pos":   0.12,
        "section":         0.08,
        "type_relevance":  0.06,
    },
    section_importance_override={
        "lead":         1.00,
        "body":         0.85,
        "outcome":      0.92,
        "background":   0.72,
        "conclusion":   0.88,
        "references":   0.00,
        "acknowledgment": 0.00,
    },
    importance_bonuses=[
        _bp(r"\b(?:reported|announced|confirmed|announced|declared|signed|launched|arrested|released)\b", 0.12),
        _bp(r"\b(?:who|what|when|where|why|how)\b", 0.06),
        _bp(r"\b(?:today|yesterday|this week|on (?:monday|tuesday|wednesday|thursday|friday|saturday|sunday))\b", 0.08),
        _bp(r"\b(?:killed|injured|died|affected|displaced|evacuated|rescued)\b", 0.10),
        _bp(r"\b(?:according to|said|stated|told reporters?|told journalists?)\b", 0.08),
        _bp(r"\b(?:impact|affected|consequences|aftermath|result of|caused by)\b", 0.08),
    ],
    importance_penalties=[
        _pp(r"\b(?:methodology|research design|conceptual framework|theoretical)\b", 0.14),
        _pp(r"\b(?:in my opinion|the author believes|it can be argued)\b", 0.12),
    ],
    compression_bias="tight",
    detail_level_floor=1,
    preferred_sections=["lead", "body", "outcome"],
    must_preserve_labels=["Who / What", "When / Where", "Why It Matters"],
    output_schema=["Who / What", "When / Where", "Key Developments", "Why It Matters", "Outcome / Status"],
    factuality_strictness="high",
    validation_rules=[
        "source_faithfulness",
        "qualifier_preservation",
        "section_structure",
    ],
    faithfulness_weight=1.2,
    negation_preservation=True,
    numeric_fact_protection=True,
    coherence_weight=1.1,
)


# ---------------------------------------------------------------------------
# Profile registry and resolution
# ---------------------------------------------------------------------------

_PROFILE_REGISTRY: dict[str, SummarizationProfile] = {
    # Current summary_style values
    "standard_paragraph": GENERAL_PROFILE,
    "bullet_points":       GENERAL_BULLETS_PROFILE,
    "hybrid":              GENERAL_HYBRID_PROFILE,
    "executive_summary":   EXECUTIVE_PROFILE,
    "academic_summary":    ACADEMIC_PROFILE,
    "simple_summary":      STUDY_PROFILE,
    # New selection modes
    "technical_summary":   TECHNICAL_PROFILE,
    "news_summary":        NEWS_PROFILE,
    # Canonical mode keys (used when selection_mode is sent separately)
    "general":             GENERAL_PROFILE,
    "academic":            ACADEMIC_PROFILE,
    "executive":           EXECUTIVE_PROFILE,
    "study":               STUDY_PROFILE,
    "technical":           TECHNICAL_PROFILE,
    "news":                NEWS_PROFILE,
}

_DEFAULT_PROFILE = GENERAL_PROFILE


def resolve_profile(selection: str) -> SummarizationProfile:
    """Return the matching SummarizationProfile for the given selection key.

    Falls back to GENERAL_PROFILE for unknown or empty values so that
    existing summaries are never broken by a missing profile.
    """
    key = (selection or "").strip().lower()
    return _PROFILE_REGISTRY.get(key, _DEFAULT_PROFILE)


def apply_compression_bias(max_words: int, bias: str) -> int:
    """Adjust a word-budget ceiling according to the profile's compression_bias."""
    if bias == "generous":
        return round(max_words * 1.15)
    if bias == "tight":
        return round(max_words * 0.85)
    return max_words


__all__ = [
    "SummarizationProfile",
    "GENERAL_PROFILE",
    "GENERAL_BULLETS_PROFILE",
    "GENERAL_HYBRID_PROFILE",
    "ACADEMIC_PROFILE",
    "EXECUTIVE_PROFILE",
    "STUDY_PROFILE",
    "TECHNICAL_PROFILE",
    "NEWS_PROFILE",
    "resolve_profile",
    "apply_compression_bias",
]
