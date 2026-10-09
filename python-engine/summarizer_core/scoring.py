"""Candidate scoring engine and feature scoring calculations."""

from __future__ import annotations

import re
from typing import Any

from .constants import (
    DISCOURSE_BOOST_PATTERNS,
    QUESTION_PUNCTUATION_PATTERN,
    QUESTION_START_PATTERN,
)
from .models import SentenceCandidate
from .text_utils import (
    is_assessment_or_question_line,
    is_branding_or_watermark_line,
    is_choice_line,
    is_directions_line,
    is_ocr_corruption_line,
)

# ----------------------------------------------------------------------
# Scoring Patterns and Vocabulary
# ----------------------------------------------------------------------

MODE_FOCUS_PATTERNS: dict[str, tuple[re.Pattern[str], ...]] = {
    "academic": (
        re.compile(
            r"\b(?:study|research|method(?:ology)?|sample|participants?|survey|experiment|results?|findings?|significant|p[- ]?value|limitation)\b",
            re.IGNORECASE,
        ),
    ),
    "executive": (
        re.compile(
            r"\b(?:decision|approved?|budget|cost|revenue|risk|impact|implication|recommend(?:ation)?|priority|action|outcome)\b",
            re.IGNORECASE,
        ),
    ),
    "study": (
        re.compile(
            r"\b(?:defined?|definition|concept|principle|means?|example|for instance|how|why|important|fundamental)\b",
            re.IGNORECASE,
        ),
    ),
    "technical": (
        re.compile(
            r"\b(?:architecture|component|module|service|api|endpoint|protocol|deploy(?:ment)?|kubernetes|docker|configuration|dependency|constraint|latency|throughput|security)\b",
            re.IGNORECASE,
        ),
    ),
    "news": (
        re.compile(
            r"\b(?:announced?|reported?|confirmed?|officials?|yesterday|today|monday|tuesday|wednesday|thursday|friday|saturday|sunday|who|where|when|impact|affected?)\b",
            re.IGNORECASE,
        ),
    ),
}

NEGATION_CUES: frozenset[str] = frozenset({
    "not", "never", "no", "neither", "nor", "barely", "hardly",
    "without", "failed", "fails", "cannot", "unable",
    "did not", "does not", "didn't", "doesn't", "was not", "were not",
    "has not", "have not", "had not", "is not", "are not",
})

QUALIFIER_PATTERN: re.Pattern[str] = re.compile(
    r"\b(?:may|might|could|possibly|potentially|appears?(?:\s+to|\s+that)|seems?(?:\s+to|\s+that)|"
    r"suggests?|likely|unlikely|tentatively|preliminary|apparently|"
    r"presumably|arguably|roughly|approximately)\b",
    re.IGNORECASE,
)

NUMERIC_PATTERN: re.Pattern[str] = re.compile(
    r"\b\d+(?:[.,]\d+)?\s*%"
    r"|\b\d+(?:[.,]\d+)?\s*percent\b"
    r"|\b\d+(?:[.,]\d+)?\s*(?:kg|km|m|cm|mm|MB|GB|KB|TB|ms|s|min|hr|hrs|year|years|month|months|week|weeks|day|days)\b"
    r"|\b\d+(?:[.,]\d+)?[- ](?:kg|km|m|cm|mm|MB|GB|KB|TB|ms|s|min|hr|hrs|year|years|month|months|week|weeks|day|days)\b"
    r"|\b\d{1,2}[/-]\d{1,2}[/-]\d{2,4}\b"
    r"|[$€£¥]\s*\d+(?:,\d{3})*(?:\.\d+)?"
    r"|\bp\s*[<>=]\s*0\.\d+",
    re.IGNORECASE,
)

STOP_WORDS: frozenset[str] = frozenset({
    "a", "an", "the", "and", "or", "but", "if", "then", "of", "in", "on",
    "for", "to", "with", "by", "from", "as", "is", "are", "was", "were",
    "this", "that", "these", "those", "it", "its", "their", "they",
})


# ----------------------------------------------------------------------
# Pure Score Normalization & Lexical Overlap Helpers
# ----------------------------------------------------------------------

def normalize_scores(values: Any) -> list[float]:
    """Min-max normalize a sequence of score values to [0.0, 1.0]."""
    numeric_values = [float(value) for value in values]
    if not numeric_values:
        return []
    minimum = min(numeric_values)
    maximum = max(numeric_values)
    if maximum == minimum:
        return [0.0 for _value in numeric_values]
    return [
        (value - minimum) / (maximum - minimum)
        for value in numeric_values
    ]


def extract_content_tokens(text: str) -> set[str]:
    """Extract content words excluding standard stopwords."""
    return {
        token.lower()
        for token in re.findall(r"[A-Za-z][A-Za-z'-]{2,}", text)
        if token.lower() not in STOP_WORDS
    }


def sentence_token_overlap(left: SentenceCandidate, right: SentenceCandidate) -> float:
    """Return lexical Jaccard overlap for diversity selection without extra models."""
    left_tokens = set(re.findall(r"[a-z][a-z'-]*|\d+(?:\.\d+)?", left.ranking_text.lower()))
    right_tokens = set(re.findall(r"[a-z][a-z'-]*|\d+(?:\.\d+)?", right.ranking_text.lower()))
    if not left_tokens or not right_tokens:
        return 0.0
    overlap = len(left_tokens & right_tokens) / len(left_tokens | right_tokens)
    # Similar vocabulary can express opposing findings. Keep both eligible for
    # MMR selection when the source explicitly changes polarity/significance.
    return min(overlap, 0.50) if claims_are_contrasting(left.text, right.text) else overlap


def claims_are_contrasting(left: str, right: str) -> bool:
    """Recognize a narrow set of lexical contrasts before redundancy removal."""
    left_tokens = extract_content_tokens(left)
    right_tokens = extract_content_tokens(right)
    if not left_tokens or not right_tokens:
        return False
    shared = left_tokens & right_tokens
    if not shared:
        return False

    def markers(text: str) -> tuple[bool, bool, bool]:
        lowered = text.casefold()
        non_significant = bool(re.search(r"\b(?:non[- ]significant|not statistically significant|no significant|not significant|weak|no effect|no relationship)\b", lowered))
        significant = bool(re.search(r"\b(?:statistically significant|significant)\b", lowered)) and not non_significant
        negative_direction = bool(re.search(r"\b(?:negative|decrease[ds]?|decline[ds]?|worsen(?:ed|s)?|reduced|no (?:effect|relationship|association)|did not (?:affect|increase|improve))\b", lowered))
        positive_direction = bool(re.search(r"\b(?:positive|increase[ds]?|improv(?:e|ed|es)|benefit(?:ed|s)?)\b", lowered))
        return non_significant, negative_direction, positive_direction

    left_markers = markers(left)
    right_markers = markers(right)
    if left_markers[0] != right_markers[0] and (left_markers[0] or right_markers[0]):
        return True
    if left_markers[1] != right_markers[1] and left_markers[2] != right_markers[2]:
        return True
    return False


def sentences_are_redundant(
    left: str,
    right: str,
    threshold: float = 0.62,
    left_numbers: tuple[str, ...] | None = None,
    right_numbers: tuple[str, ...] | None = None,
) -> bool:
    """Check if two sentence texts exceed the content-token redundancy threshold.

    Contrasting claims, distinct numeric facts, or differing directions are preserved.
    """
    if claims_are_contrasting(left, right):
        return False
    if left_numbers and right_numbers and set(left_numbers) != set(right_numbers):
        return False
    left_tokens = extract_content_tokens(left)
    right_tokens = extract_content_tokens(right)
    if left_tokens == set() or right_tokens == set():
        return False
    overlap = len(left_tokens & right_tokens) / min(len(left_tokens), len(right_tokens))
    return overlap >= threshold


# ----------------------------------------------------------------------
# Candidate Scoring Engines
# ----------------------------------------------------------------------

def calculate_candidate_scores_with_profile(
    candidates: list[SentenceCandidate],
    tfidf_scores: list[float],
    title_scores: list[float],
    article_type: str,
    article_type_family: dict[str, str],
    section_importance: dict[str, dict[str, float]],
    position_score_fn: Any,
    paragraph_position_score_fn: Any,
    article_type_relevance_fn: Any,
    boilerplate_penalty_fn: Any,
    antecedent_pattern: Any,
    sel_profile: Any,
    depth_key: str = "balanced",
    depth_config: dict[str, Any] | None = None,
    disabled_features: frozenset[str] = frozenset(),
) -> list[float]:
    """Score candidates using profile-specific weights plus mode-level bonuses/penalties."""
    w = sel_profile.scoring_weights
    w_tfidf       = w.get("tfidf", 0.38)
    w_title       = w.get("title", 0.18)
    w_position    = w.get("position", 0.14)
    w_para_pos    = w.get("paragraph_pos", 0.10)
    w_section     = w.get("section", 0.12)
    w_type_rel    = w.get("type_relevance", 0.08)
    mode_focus_patterns = MODE_FOCUS_PATTERNS.get(sel_profile.mode, ())

    # Depth strategy adjustments
    if depth_key == "brief":
        w_tfidf_eff = w_tfidf * 1.15
        w_title_eff = w_title * 1.25
        w_position_eff = w_position * 1.25
        w_para_pos_eff = w_para_pos * 1.10
        w_section_eff = w_section * 0.90
    elif depth_key == "short":
        w_tfidf_eff = w_tfidf * 1.05
        w_title_eff = w_title * 1.10
        w_position_eff = w_position * 1.10
        w_para_pos_eff = w_para_pos * 1.05
        w_section_eff = w_section * 0.95
    elif depth_key == "balanced":
        w_tfidf_eff = w_tfidf
        w_title_eff = w_title
        w_position_eff = w_position
        w_para_pos_eff = w_para_pos
        w_section_eff = w_section
    elif depth_key == "detailed":
        w_tfidf_eff = w_tfidf * 0.95
        w_title_eff = w_title * 0.90
        w_position_eff = w_position * 0.85
        w_para_pos_eff = w_para_pos * 0.95
        w_section_eff = w_section * 1.15
    else:  # comprehensive
        w_tfidf_eff = w_tfidf * 0.90
        w_title_eff = w_title * 0.80
        w_position_eff = w_position * 0.70
        w_para_pos_eff = w_para_pos * 0.85
        w_section_eff = w_section * 1.30

    total_sentences = len(candidates)
    article_family = article_type_family.get(article_type, "general")

    # Merge profile section importance overrides on top of the detected type's weights.
    base_section_weights = section_importance.get(article_type) or section_importance.get("general_article", {})
    effective_section_weights = dict(base_section_weights)
    if sel_profile.section_importance_override:
        effective_section_weights.update(sel_profile.section_importance_override)

    combined_scores: list[float] = []

    for index, candidate in enumerate(candidates):
        lowered_text = candidate.text.lower()
        section_weight = effective_section_weights.get(
            candidate.section, effective_section_weights.get("body", 0.8)
        )
        article_type_relevance = article_type_relevance_fn(candidate.text, article_family)
        position_score = position_score_fn(candidate, total_sentences)
        paragraph_position_score = paragraph_position_score_fn(candidate)
        boilerplate_penalty = boilerplate_penalty_fn(candidate.text, article_family)
        antecedent_penalty = 0.18 if antecedent_pattern.search(candidate.text) else 0.0

        # --- Profile bonus/penalty pass ---
        profile_bonus = 0.0
        for pattern, delta in sel_profile.importance_bonuses:
            if pattern.search(candidate.text):
                profile_bonus += delta

        profile_penalty = 0.0
        for pattern, delta in sel_profile.importance_penalties:
            if pattern.search(candidate.text):
                profile_penalty += delta

        # Make the selected analysis mode materially different from the
        # general ranker, especially when several candidates are similar.
        focus_bonus = 0.0
        for pattern in mode_focus_patterns:
            if pattern.search(candidate.text):
                focus_bonus += 0.28

        # --- Faithfulness/Coherence bonuses ---
        # Qualifier preservation bonus: reward hedging language that preserves nuance
        qualifier_bonus = 0.0
        if hasattr(sel_profile, "faithfulness_weight") and sel_profile.faithfulness_weight > 0:
            if "qualifier_protection" not in disabled_features and QUALIFIER_PATTERN.search(candidate.text):
                qualifier_bonus = 0.05 * sel_profile.faithfulness_weight

        # Negation preservation bonus: reward sentences that correctly preserve negation
        negation_bonus = 0.0
        if "negation_protection" not in disabled_features and hasattr(sel_profile, "negation_preservation") and sel_profile.negation_preservation:
            for neg_cue in NEGATION_CUES:
                if neg_cue in lowered_text:
                    negation_bonus = 0.04 * (sel_profile.faithfulness_weight if hasattr(sel_profile, "faithfulness_weight") else 1.0)
                    break

        # Numeric fact protection bonus: reward sentences with verifiable numeric claims
        numeric_bonus = 0.0
        if "numerical_fact_protection" not in disabled_features and hasattr(sel_profile, "numeric_fact_protection") and sel_profile.numeric_fact_protection:
            if NUMERIC_PATTERN.search(candidate.text):
                numeric_bonus = 0.03 * (sel_profile.faithfulness_weight if hasattr(sel_profile, "faithfulness_weight") else 1.0)

        # Discourse connective bonus: reward sentences with discourse markers for flow
        discourse_bonus = 0.0
        if hasattr(sel_profile, "coherence_weight") and sel_profile.coherence_weight > 0:
            for pattern, _ in DISCOURSE_BOOST_PATTERNS.values():
                if pattern.search(candidate.text):
                    discourse_bonus = 0.03 * sel_profile.coherence_weight
                    break

        # Depth-specific sentence adjustments
        depth_bonus = 0.0
        is_evidence = bool(NUMERIC_PATTERN.search(candidate.text))
        if depth_key == "brief":
            if candidate.section in ("methodology", "limitations", "background", "literature_review"):
                depth_bonus -= 0.14
            elif candidate.section in ("abstract", "conclusion", "overview"):
                depth_bonus += 0.08
        elif depth_key == "detailed":
            if is_evidence:
                depth_bonus += 0.05
            if candidate.section in ("methodology", "discussion", "limitations"):
                depth_bonus += 0.05
        elif depth_key == "comprehensive":
            if is_evidence:
                depth_bonus += 0.07
            if candidate.section in ("methodology", "discussion", "limitations", "recommendations", "background"):
                depth_bonus += 0.08

        # --- Generic noise/quality penalties ---
        finding_bonus = 0.0
        if article_type == "academic" and re.search(r"\b(?:results|findings|revealed|found|indicated|reported)\b", lowered_text):
            finding_bonus = 0.08
        method_bonus = 0.0
        if article_type == "academic" and re.search(r"\b(?:survey|sampling|questionnaire|respondents|study employed)\b", lowered_text):
            method_bonus = 0.04
        future_work_penalty = 0.14 if re.search(r"\b(?:future research|follow-up qualitative|focus group|further research)\b", lowered_text) else 0.0
        table_penalty = 0.18 if re.search(r"\b(?:weighted mean|verbal interpretation|cluster\s+\d+)\b", lowered_text) else 0.0
        organization_penalty = 0.12 if re.search(r"\b(?:findings are organized chronologically|this section utilized|this section presents|this section discusses)\b", lowered_text) else 0.0
        conclusion_bonus = 0.06 if re.search(r"\b(?:concludes?|concluded|therefore|thus|overall)\b", lowered_text) else 0.0

        # Structure-aware claim status complements section and lexical weights.
        # These small deterministic adjustments prioritize what the source says
        # the study did while keeping proposed procedures distinct from findings.
        claim_status_bonus = 0.0
        if sel_profile.mode == "academic" or candidate.section in {
            "abstract", "introduction", "literature_review", "methodology",
            "results", "discussion", "conclusion", "limitations",
        }:
            claim_status_bonus = {
                "ACTUAL_RESULT": 0.10,
                "RESEARCH_OBJECTIVE": 0.07,
                "RESEARCH_QUESTION": 0.05,
                "PROPOSED_METHOD": 0.04 if depth_key in {"detailed", "comprehensive"} else 0.01,
                "LITERATURE_FINDING": 0.025,
                "LIMITATION": 0.035,
                "RECOMMENDATION": 0.025,
                "BACKGROUND_INFORMATION": -0.025,
            }.get(candidate.claim_status, 0.0)

        question_penalty = 0.60 if (
            QUESTION_PUNCTUATION_PATTERN.search(candidate.text)
            or QUESTION_START_PATTERN.search(candidate.text)
            or is_assessment_or_question_line(candidate.text)
        ) else 0.0
        directions_penalty = 0.60 if is_directions_line(candidate.text) else 0.0
        choice_penalty = 0.60 if is_choice_line(candidate.text) else 0.0
        branding_penalty = 0.60 if is_branding_or_watermark_line(candidate.text) else 0.0
        ocr_penalty = 0.60 if is_ocr_corruption_line(candidate.text) else 0.0

        sentence_length = max(1, len(candidate.text.split()))
        length_penalty = 0.0
        if sentence_length > 34:
            length_penalty = min(0.28, (sentence_length - 34) / 90)

        score = (
            (tfidf_scores[index]            * w_tfidf_eff)
            + (title_scores[index]          * w_title_eff)
            + (position_score               * w_position_eff)
            + (paragraph_position_score     * w_para_pos_eff)
            + (section_weight               * w_section_eff)
            + (article_type_relevance       * w_type_rel)
            + finding_bonus
            + method_bonus
            + conclusion_bonus
            + claim_status_bonus
            + profile_bonus
            + focus_bonus
            + qualifier_bonus
            + negation_bonus
            + numeric_bonus
            + discourse_bonus
            + depth_bonus
            - (boilerplate_penalty * 0.10)
            - antecedent_penalty
            - future_work_penalty
            - table_penalty
            - organization_penalty
            - length_penalty
            - profile_penalty
            - question_penalty
            - directions_penalty
            - choice_penalty
            - branding_penalty
            - ocr_penalty
        )
        combined_scores.append(score)

    return combined_scores


def calculate_candidate_scores(
    candidates: list[SentenceCandidate],
    tfidf_scores: list[float],
    title_scores: list[float],
    article_type: str,
    article_type_family: dict[str, str],
    section_importance: dict[str, dict[str, float]],
    position_score_fn: Any,
    paragraph_position_score_fn: Any,
    article_type_relevance_fn: Any,
    boilerplate_penalty_fn: Any,
    antecedent_pattern: Any,
) -> list[float]:
    """Base/legacy candidate scoring calculation without dynamic profile overrides."""
    total_sentences = len(candidates)
    article_family = article_type_family.get(article_type, "general")
    article_section_weights = section_importance.get(article_type) or section_importance.get("general_article", {})
    combined_scores: list[float] = []

    for index, candidate in enumerate(candidates):
        lowered_text = candidate.text.lower()
        section_weight = article_section_weights.get(candidate.section, article_section_weights.get("body", 0.8))
        article_type_relevance = article_type_relevance_fn(candidate.text, article_family)
        position_score = position_score_fn(candidate, total_sentences)
        paragraph_position_score = paragraph_position_score_fn(candidate)
        boilerplate_penalty = boilerplate_penalty_fn(candidate.text, article_family)
        antecedent_penalty = 0.18 if antecedent_pattern.search(candidate.text) else 0.0
        finding_bonus = 0.0
        if article_type == "academic" and re.search(r"\b(?:results|findings|revealed|found|indicated|reported)\b", lowered_text):
            finding_bonus = 0.08
        method_bonus = 0.0
        if article_type == "academic" and re.search(r"\b(?:survey|sampling|questionnaire|respondents|study employed)\b", lowered_text):
            method_bonus = 0.04
        future_work_penalty = 0.14 if re.search(r"\b(?:future research|follow-up qualitative|focus group|further research)\b", lowered_text) else 0.0
        table_penalty = 0.18 if re.search(r"\b(?:weighted mean|verbal interpretation|cluster\s+\d+)\b", lowered_text) else 0.0
        organization_penalty = 0.12 if re.search(r"\b(?:findings are organized chronologically|this section utilized|this section presents|this section discusses)\b", lowered_text) else 0.0
        conclusion_bonus = 0.06 if re.search(r"\b(?:concludes?|concluded|therefore|thus|overall)\b", lowered_text) else 0.0

        question_penalty = 0.60 if (
            QUESTION_PUNCTUATION_PATTERN.search(candidate.text)
            or QUESTION_START_PATTERN.search(candidate.text)
            or is_assessment_or_question_line(candidate.text)
        ) else 0.0
        directions_penalty = 0.60 if is_directions_line(candidate.text) else 0.0
        choice_penalty = 0.60 if is_choice_line(candidate.text) else 0.0
        branding_penalty = 0.60 if is_branding_or_watermark_line(candidate.text) else 0.0
        ocr_penalty = 0.60 if is_ocr_corruption_line(candidate.text) else 0.0

        sentence_length = max(1, len(candidate.text.split()))
        length_penalty = 0.0
        if sentence_length > 34:
            length_penalty = min(0.28, (sentence_length - 34) / 90)

        score = (
            (tfidf_scores[index] * 0.38)
            + (title_scores[index] * 0.18)
            + (position_score * 0.14)
            + (paragraph_position_score * 0.10)
            + (section_weight * 0.12)
            + (article_type_relevance * 0.08)
            + finding_bonus
            + method_bonus
            + conclusion_bonus
            - (boilerplate_penalty * 0.10)
            - antecedent_penalty
            - future_work_penalty
            - table_penalty
            - organization_penalty
            - length_penalty
            - question_penalty
            - directions_penalty
            - choice_penalty
            - branding_penalty
            - ocr_penalty
        )
        combined_scores.append(score)

    return combined_scores


__all__ = [
    "MODE_FOCUS_PATTERNS",
    "NEGATION_CUES",
    "NUMERIC_PATTERN",
    "QUALIFIER_PATTERN",
    "STOP_WORDS",
    "calculate_candidate_scores",
    "calculate_candidate_scores_with_profile",
    "extract_content_tokens",
    "normalize_scores",
    "sentence_token_overlap",
    "sentences_are_redundant",
]
