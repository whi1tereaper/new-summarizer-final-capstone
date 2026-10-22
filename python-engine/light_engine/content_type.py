"""Lightweight structure-and-language based document content classification."""

from __future__ import annotations

from dataclasses import dataclass
import re

from .extraction import SourceSentence

_MIN_CLASSIFICATION_CONFIDENCE = 0.36

_HEADING_CUES = {
    "research": re.compile(
        r"^(?:abstract|research question|method(?:s|ology)?|materials|results?|findings|"
        r"discussion|limitations?|conclusions?)$",
        re.I,
    ),
    "news": re.compile(r"^(?:breaking|latest developments?|what we know|timeline)$", re.I),
    "technical": re.compile(
        r"^(?:architecture|proposed architecture|system design|implementation|technical overview|"
        r"requirements|configuration|troubleshooting|api reference|current problems|idempotency|"
        r"rollout|data flow)$",
        re.I,
    ),
    "business_report": re.compile(
        r"^(?:executive summary|business impact|financial results?|risks?|recommendations?|"
        r"options evaluated|key findings)$",
        re.I,
    ),
    "opinion": re.compile(r"^(?:opinion|commentary|editorial|our view|the argument)$", re.I),
    "educational": re.compile(r"^(?:learning objectives|key concepts|glossary|examples|summary)$", re.I),
}
_LANGUAGE_CUES = {
    "research": re.compile(
        r"\b(?:participants?|sample size|randomi[sz]ed|confidence interval|statistically "
        r"significant|regression|clinical trial|we measured|limitations?|cross-sectional|"
        r"survey was|the study found|results showed|researchers measured|statistical model)\b",
        re.I,
    ),
    "news": re.compile(
        r"\b(?:officials? said|according to|announced|reported by|said at|the department said|"
        r"transportation said|on (?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)|"
        r"was injured|was arrested|was closed|will remain|inspectors? found|the mayor|"
        r"the commissioner)\b",
        re.I,
    ),
    "technical": re.compile(
        r"\b(?:architecture|api|protocol|configuration|deployment|dependency|interface|"
        r"implementation|system component|failure mode|constraint|message queue|consumer group|"
        r"idempotency key|database transaction|worker instances?)\b",
        re.I,
    ),
    "business_report": re.compile(
        r"\b(?:revenue|operating cost|recommendation|investment|forecast|quarterly|"
        r"market share|return on investment|risk assessment|should prioritize)\b",
        re.I,
    ),
    "opinion": re.compile(
        r"\b(?:i argue|we believe|in my view|in our view|should|must|ought to|"
        r"the case for|the case against)\b",
        re.I,
    ),
    "educational": re.compile(
        r"\b(?:is defined as|refers to|for example|in other words|step \d+|"
        r"to understand|learning objective)\b",
        re.I,
    ),
}
_LANGUAGE_CUES["business_report"] = re.compile(
    r"\b(?:recommend(?:s|ed|ation|ations)?|revenue|cost|forecast|risk|options evaluated|"
    r"capital expenditure|board of directors|operating budget)\b",
    re.I,
)
_SECTION_IMPORTANCE = {
    "research": {
        "abstract": 0.20, "results": 0.34, "findings": 0.34, "discussion": 0.12,
        "conclusion": 0.22, "limitations": 0.18, "methodology": 0.04, "methods": 0.04,
        "introduction": -0.06, "background": -0.04,
    },
    "news": {"developments": 0.13, "latest developments": 0.16, "what we know": 0.12},
    "technical": {
        "architecture": 0.18, "system design": 0.18, "implementation": 0.13,
        "requirements": 0.18, "constraints": 0.17, "risks": 0.12,
        "current problems": 0.11, "idempotency": 0.18, "rollout": 0.10,
    },
    "business_report": {
        "executive summary": 0.16, "key findings": 0.20, "financial results": 0.18,
        "risks": 0.15, "recommendations": 0.22, "conclusion": 0.10,
        "options evaluated": 0.17, "uncertainties": 0.13,
    },
    "opinion": {"opinion": 0.13, "commentary": 0.13, "editorial": 0.13, "argument": 0.12},
    "educational": {
        "learning objectives": 0.15, "key concepts": 0.17, "examples": 0.10, "summary": 0.12,
    },
}


@dataclass(frozen=True)
class ContentTypeAnalysis:
    category: str
    confidence: float
    method: str
    cues: tuple[str, ...]
    section_counts: dict[str, int]

    def as_dict(self, requested_mode: str) -> dict[str, object]:
        explicit = requested_mode != "general"
        profile = requested_mode if explicit else (
            self.category if self.confidence >= _MIN_CLASSIFICATION_CONFIDENCE else "general"
        )
        return {
            "category": self.category,
            "confidence": round(self.confidence, 3),
            "method": self.method,
            "cues": list(self.cues),
            "section_counts": self.section_counts,
            "selection_profile": profile,
            "selection_profile_source": "user" if explicit else (
                "detected" if profile != "general" else "general_fallback"
            ),
        }


def _canonical_heading(value: str) -> str:
    return re.sub(r"\s+", " ", value.strip().rstrip(":")).casefold()


def analyze_content_type(
    text: str,
    sentences: list[SourceSentence],
    title: str = "",
) -> ContentTypeAnalysis:
    """Choose a conservative content profile without overriding explicit user settings."""
    section_counts: dict[str, int] = {}
    for sentence in sentences:
        section = _canonical_heading(sentence.section)
        if section and section != "document":
            section_counts[section] = section_counts.get(section, 0) + 1

    content = f"{title}\n{text}".casefold()
    scores: dict[str, float] = {}
    evidence: dict[str, list[str]] = {}
    for category, pattern in _HEADING_CUES.items():
        matches = [heading for heading in section_counts if pattern.fullmatch(heading)]
        cue_pattern = _LANGUAGE_CUES[category]
        cue_count = len(cue_pattern.findall(content))
        structural_score = min(0.54, 0.22 * len(matches))
        language_score = min(0.5, 0.1 * cue_count)
        scores[category] = structural_score + language_score
        evidence[category] = [
            *(f"heading:{heading}" for heading in matches[:3]),
            *(["language_cues"] if cue_count else []),
        ]

    if len(sentences) >= 6 and not any(scores.values()):
        # Article-like prose is the primary fallback; heavily sectioned unknown
        # documents stay general rather than inheriting a news/research template.
        if len(section_counts) <= 1:
            scores["general_article"] = 0.43
            evidence["general_article"] = ["continuous_prose"]
        else:
            scores["general_document"] = 0.35
            evidence["general_document"] = ["unclassified_structure"]

    if not scores:
        return ContentTypeAnalysis("general_document", 0.0, "low_confidence_fallback", (), section_counts)

    category = max(scores, key=lambda item: scores[item])
    confidence = min(0.99, scores[category])
    if confidence < _MIN_CLASSIFICATION_CONFIDENCE:
        return ContentTypeAnalysis("general", confidence, "low_confidence_fallback", (), section_counts)
    return ContentTypeAnalysis(
        category,
        confidence,
        "heading_and_language_cues",
        tuple(evidence.get(category, ())),
        section_counts,
    )


def section_importance(profile: str, section: str) -> float:
    normalized = _canonical_heading(section)
    return _SECTION_IMPORTANCE.get(profile, {}).get(normalized, 0.0)
