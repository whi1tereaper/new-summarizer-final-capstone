"""Candidate scoring engine and feature scoring calculations."""

from __future__ import annotations

import math
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
    looks_like_author_or_affiliation_line,
    looks_like_publication_noise,
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
    "legal": (
        re.compile(
            r"\b(?:shall\s+be|hereby|pursuant\s+to|under\s+section|statutory|regulation|compliance|obligation|liabilit(?:y|ies)|prohibited|authorized|jurisdiction|enforceable|governed\s+by|penalty|fine)\b",
            re.IGNORECASE,
        ),
    ),
    "scientific": (
        re.compile(
            r"\b(?:hypothes(?:is|es)|experimental|control\s+group|treatment|empirical|clinical\s+trial|statistically\s+significant|p\s*[<>=]\s*0\.\d+|confidence\s+interval|findings?|measured|observed|effect\s+size)\b",
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

FOCUS_QUERY_STOP_WORDS: frozenset[str] = STOP_WORDS | frozenset({
    "what", "how", "why", "who", "which", "where", "when", "do", "does",
    "did", "can", "could", "would", "should", "please", "summarize",
    "summary", "summarization", "focus", "topic", "question", "i",
})
FOCUS_NEGATION_PATTERN = re.compile(
    r"\b(?:no|not|never|without|cannot|can't|didn't|doesn't|don't|"
    r"isn't|aren't|wasn't|weren't|failed|fails|unable)\b",
    re.IGNORECASE,
)
FOCUS_CONTEXT_REFERENCE_PATTERN = re.compile(
    r"^(?:it|they|this|these|those|such|however|therefore|moreover|thus)\b",
    re.IGNORECASE,
)


def _focus_token_variants(token: str) -> frozenset[str]:
    variants = {token}
    if len(token) > 4 and token.endswith("ies"):
        variants.add(token[:-3] + "y")
    if len(token) > 3 and token.endswith("s") and not token.endswith("ss"):
        variants.add(token[:-1])
    if len(token) > 4 and token.endswith("ed"):
        stem = token[:-2]
        if len(stem) > 2 and stem[-1] == stem[-2]:
            stem = stem[:-1]
        variants.update((stem, stem + "e"))
        if stem.endswith("i"):
            variants.add(stem[:-1] + "y")
    if len(token) > 5 and token.endswith("ing"):
        stem = token[:-3]
        if len(stem) > 2 and stem[-1] == stem[-2]:
            stem = stem[:-1]
        variants.update((stem, stem + "e"))
    return frozenset(variants)


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


def calculate_focus_relevance_scores(
    candidate_texts: list[str],
    focus_query: str,
    context_texts: list[str] | None = None,
) -> list[float]:
    """Score candidate text by query-term coverage and phrase alignment in [0, 1]."""
    query_tokens = list(dict.fromkeys(
        token
        for token in re.findall(r"[\w'-]+", focus_query.casefold(), flags=re.UNICODE)
        if token not in FOCUS_QUERY_STOP_WORDS
    ))
    if not candidate_texts or not query_tokens:
        return [0.0] * len(candidate_texts)

    query_variants = [_focus_token_variants(token) for token in query_tokens]
    candidate_tokens = [
        [
            token
            for token in re.findall(r"[\w'-]+", text.casefold(), flags=re.UNICODE)
        ]
        for text in candidate_texts
    ]
    filtered_candidate_tokens = [
        [token for token in tokens if token not in FOCUS_QUERY_STOP_WORDS]
        for tokens in candidate_tokens
    ]
    candidate_variants = [
        [_focus_token_variants(token) for token in tokens]
        for tokens in filtered_candidate_tokens
    ]
    document_frequency = [
        sum(
            any(query_form & token_form for token_form in tokens)
            for tokens in candidate_variants
        )
        for query_form in query_variants
    ]
    document_count = len(candidate_texts)
    term_weights = {
        token: math.log1p(
            (document_count - frequency + 0.5) / (frequency + 0.5)
        )
        for token, frequency in zip(query_tokens, document_frequency, strict=True)
    }
    total_weight = sum(term_weights.values())

    average_candidate_length = max(
        1.0,
        sum(len(tokens) for tokens in filtered_candidate_tokens) / len(candidate_texts),
    )
    query_bigrams = list(zip(query_variants, query_variants[1:]))
    query_is_negated = bool(FOCUS_NEGATION_PATTERN.search(focus_query))
    scores: list[float] = []
    for tokens, token_forms in zip(
        filtered_candidate_tokens,
        candidate_variants,
        strict=True,
    ):
        token_counts = [
            sum(bool(query_form & token_form) for token_form in token_forms)
            for query_form in query_variants
        ]
        length_norm = 1.2 * (
            0.25 + 0.75 * len(tokens) / average_candidate_length
        )
        coverage = sum(
            weight * (count * 2.2 / (count + length_norm))
            for count, weight in zip(token_counts, term_weights.values(), strict=True)
        ) / total_weight
        candidate_bigrams = list(zip(token_forms, token_forms[1:]))
        bigram_coverage = (
            sum(
                any(
                    query_left & candidate_left
                    and query_right & candidate_right
                    for candidate_left, candidate_right in candidate_bigrams
                )
                for query_left, query_right in query_bigrams
            )
            / len(query_bigrams)
            if query_bigrams
            else 0.0
        )
        phrase_match = any(
            all(
                query_form & token_form
                for query_form, token_form in zip(
                    query_variants,
                    token_forms[index:index + len(query_variants)],
                    strict=True,
                )
            )
            for index in range(len(tokens) - len(query_tokens) + 1)
        )
        order_score = bigram_coverage if query_bigrams else coverage
        score = 0.70 * coverage + 0.15 * order_score + (0.15 if phrase_match else 0.0)
        candidate_is_negated = bool(
            FOCUS_NEGATION_PATTERN.search(" ".join(tokens))
        )
        if query_is_negated != candidate_is_negated:
            score -= 0.20 if query_is_negated else 0.15
        scores.append(max(0.0, min(1.0, score)))

    if context_texts is None:
        return scores
    if len(context_texts) != len(candidate_texts):
        raise ValueError("Context text count must match candidate text count.")
    contextual_scores = calculate_focus_relevance_scores(
        context_texts,
        focus_query,
    )
    blended_scores = []
    for text, score, context_score in zip(
        candidate_texts,
        scores,
        contextual_scores,
        strict=True,
    ):
        context_weight = (
            0.55 if FOCUS_CONTEXT_REFERENCE_PATTERN.match(text) else 0.15
        )
        blended_scores.append(
            (1.0 - context_weight) * score + context_weight * context_score
        )
    return blended_scores


def _focus_context_texts(candidates: list[SentenceCandidate]) -> list[str]:
    contexts: list[str] = []
    for index, candidate in enumerate(candidates):
        parts = []
        section = candidate.section.strip()
        if section and section.casefold() not in {"body", "document"}:
            parts.append(section)
        for neighbor_index in (index - 1, index + 1):
            if not 0 <= neighbor_index < len(candidates):
                continue
            neighbor = candidates[neighbor_index]
            if neighbor.paragraph_index == candidate.paragraph_index:
                parts.append(neighbor.text)
        contexts.append(" ".join(parts))
    return contexts


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
    focus_query: str = "",
    document_analysis: dict[str, Any] | None = None,
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
    if depth_key in {"very_brief", "brief"}:
        w_tfidf_eff = w_tfidf * (1.20 if depth_key == "very_brief" else 1.15)
        w_title_eff = w_title * (1.30 if depth_key == "very_brief" else 1.25)
        w_position_eff = w_position * (1.35 if depth_key == "very_brief" else 1.25)
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
    primary_finding_indices = set(
        document_analysis.get("primary_finding_indices", [])
        if document_analysis is not None
        else []
    )
    primary_finding_lead = (
        document_analysis.get("primary_finding_indices", [None])[0]
        if document_analysis is not None
        and document_analysis.get("primary_finding_indices")
        else None
    )
    primary_finding_support = (
        document_analysis.get("primary_finding_indices", [None, None])[1]
        if document_analysis is not None
        and len(document_analysis.get("primary_finding_indices", [])) > 1
        else None
    )
    focus_relevance_scores = calculate_focus_relevance_scores(
        [candidate.text for candidate in candidates],
        focus_query,
        _focus_context_texts(candidates) if focus_query else None,
    )

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

        # --- Query-focused summarization bonus ---
        query_bonus = 0.35 * focus_relevance_scores[index]

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

        # --- Generic noise/quality penalties and core rhetorical roles ---
        is_prior_lit_cand = (
            candidate.section in {"literature_review", "related_work"}
            or getattr(candidate, "source_role", "") == "prior_study"
            or getattr(candidate, "claim_status", "") in {"LITERATURE_FINDING", "PRIOR_STUDY_RESULT"}
        )

        finding_bonus = 0.0
        if not is_prior_lit_cand and re.search(
            r"\b(?:"
            r"results? (?:show|shows|indicate|indicates|reveal|reveals|demonstrate|demonstrates|confirm|confirms|suggest|suggests)|"
            r"findings? (?:show|shows|indicate|indicates|reveal|reveals|demonstrate|demonstrates|suggest|suggests)|"
            r"(?:our|this) (?:study|analysis|research|paper) (?:found|observed|revealed|identified|demonstrated|confirms)|"
            r"(?:we|the authors) (?:found|observed|conclude|concluded|identified|demonstrated|report)|"
            r"(?:annual|seasonal|average|mean|total) (?:temperature|precipitation|humidity|sunshine|rainfall|radiation).*(?:increased|decreased|increasing|decreasing|trend|statistically significant)|"
            r"(?:the|an) overall trend (?:was|indicated|showed)|"
            r"(?:chief|major|primary|key) (?:change|trend|finding|outcome)|"
            r"significantly (?:increased|decreased|higher|lower|reduced)|"
            r"(?:statistically )?significant (?:increase|decrease|reduction|difference|correlation|trend)|"
            r"not statistically significant|non-significant|"
            r"in conclusion|it was concluded that|the study concludes that"
            r")\b",
            lowered_text,
        ):
            finding_bonus = 0.38
        elif not is_prior_lit_cand and article_type == "academic" and re.search(r"\b(?:results|findings|revealed|found|indicated|reported)\b", lowered_text):
            finding_bonus = 0.12

        # Research objective and primary scope bonus
        if re.search(
            r"\b(?:"
            r"the (?:objective|purpose|aim|goal) of this (?:study|paper|research)|"
            r"this (?:study|paper|research) (?:investigates|analyzes|examines|focuses on|aims to|was conducted to)|"
            r"the content of this study is mainly based on|"
            r"we (?:investigate|analyze|examine|study) the (?:trend|impact|effect|relationship) of"
            r")\b",
            lowered_text,
        ):
            finding_bonus = max(finding_bonus, 0.25)

        # Prior study and literature citation penalty: do not let external works displace current paper findings
        prior_study_penalty = 0.0
        if is_prior_lit_cand:
            prior_study_penalty = 0.50
        elif re.search(r"\b(?:et\s+al\.?|tal\.?|\b(?:previous|prior|earlier|other|past)\s+(?:studies|research|authors|findings|literature|reports)|\baccording\s+to\s+[A-Z]|\(\s*[A-Z][a-z]+.*?\d{4}\))\b", candidate.text):
            prior_study_penalty = 0.50

        # Methodological procedural detail penalty for balanced/brief summaries
        methodology_penalty = 0.0
        if sel_profile.mode != "technical" and re.search(
            r"\b(?:data (?:was|were) (?:collected|gathered|retrieved|obtained)|"
            r"using (?:the )?(?:software|tool|package|algorithm|formula|model|equation|xlstat)|"
            r"(?:test|survey|questionnaire) was (?:used|applied|administered|conducted)|"
            r"sample consisted of \d+|were analyzed using (?:the )?(?:mann-kendall|regression))\b",
            lowered_text,
        ):
            methodology_penalty = 0.18

        # Document metadata, acknowledgment boilerplate, and publication noise disqualification
        metadata_penalty = 0.0
        if (
            looks_like_author_or_affiliation_line(candidate.text)
            or looks_like_publication_noise(candidate.text)
            or re.search(
                r"\b(?:"
                r"would like to thank|express my (?:heartfelt )?gratitude|grateful to (?:my|all)|"
                r"without their support|thanks to my supervisor|sincere thanks|"
                r"financial support from|funded by|supported by (?:grant|many people)|"
                r"helped to normalize them|fathers? dream|passed away|comfort to me"
                r")\b",
                lowered_text,
            )
        ):
            metadata_penalty = 1.0

        method_bonus = 0.0
        if article_type == "academic" and re.search(r"\b(?:survey|sampling|questionnaire|respondents|study employed)\b", lowered_text):
            method_bonus = 0.04
        future_work_penalty = 0.14 if re.search(r"\b(?:future research|follow-up qualitative|focus group|further research)\b", lowered_text) else 0.0
        table_penalty = 0.18 if re.search(r"\b(?:weighted mean|verbal interpretation|cluster\s+\d+)\b", lowered_text) else 0.0
        organization_penalty = 0.12 if re.search(r"\b(?:findings are organized chronologically|this section utilized|this section presents|this section discusses)\b", lowered_text) else 0.0
        conclusion_bonus = 0.06 if re.search(r"\b(?:concludes?|concluded|therefore|thus|overall)\b", lowered_text) else 0.0

        # Information-value scoring: Prioritize main claims, actual outcomes, purpose, and conclusions.
        info_value_map = {
            "ACTUAL_RESULT": 0.12,
            "MAIN_FINDING": 0.14,
            "DECISION": 0.12,
            "RESEARCH_OBJECTIVE": 0.09,
            "RESEARCH_QUESTION": 0.06,
            "HYPOTHESIS": 0.06,
            "CONCLUSION": 0.08,
            "RECOMMENDATION": 0.05,
            "PROPOSED_METHOD": 0.04 if depth_key in {"detailed", "comprehensive"} else 0.01,
            "LITERATURE_FINDING": 0.02,
            "LIMITATION": 0.035 if depth_key in {"detailed", "comprehensive"} else 0.01,
            "BACKGROUND_INFORMATION": -0.03,
        }
        claim_status_bonus = info_value_map.get(candidate.claim_status, 0.0)
        if candidate.source_role == "result":
            claim_status_bonus = max(claim_status_bonus, 0.10)
        elif candidate.source_role == "objective":
            claim_status_bonus = max(claim_status_bonus, 0.08)
        elif candidate.source_role == "conclusion":
            claim_status_bonus = max(claim_status_bonus, 0.07)

        primary_finding_bonus = (
            0.28 if candidate.index == primary_finding_lead
            else 0.24 if candidate.index == primary_finding_support
            else 0.16 if candidate.index in primary_finding_indices
            else 0.0
        )

        # Meaningful comparison and quantitative relationships boost
        comparison_bonus = 0.0
        if re.search(r"\b(?:compared\s+(?:with|to)|higher\s+than|lower\s+than|greater\s+than|fewer\s+than|in\s+contrast|whereas|significant\s+difference)\b", lowered_text):
            comparison_bonus = 0.05

        # Procedural survey distribution / questionnaire mechanical boilerplate penalty
        procedural_penalty = 0.0
        if re.search(r"\b(?:purposive\s+sampling|stratified\s+random|questionnaires?\s+were\s+distributed|administered\s+to\s+respondents|spss\s+version|cronbach's\s+alpha)\b", lowered_text):
            procedural_penalty = 0.12 if depth_key in {"very_brief", "brief", "short", "balanced"} else 0.03

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
            + primary_finding_bonus
            + comparison_bonus
            + profile_bonus
            + focus_bonus
            + query_bonus
            + qualifier_bonus
            + negation_bonus
            + numeric_bonus
            + discourse_bonus
            + depth_bonus
            - (boilerplate_penalty * 0.10)
            - antecedent_penalty
            - prior_study_penalty
            - methodology_penalty
            - metadata_penalty
            - future_work_penalty
            - table_penalty
            - organization_penalty
            - procedural_penalty
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
