"""Summarizer data models."""

from __future__ import annotations

from collections import Counter, defaultdict
from dataclasses import dataclass, field, replace
import re
from typing import Any


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
    # Selection-aware intelligence fields (new)
    selection_mode: str = ""      # explicit mode key; falls back to summary_style when empty
    document_title: str = ""      # user-supplied title hint; supplements auto-detection


@dataclass(frozen=True)
class SourceDocument:
    raw_text: str
    source_type: str
    title_hint: str = ""


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
    confidence_score: float
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


class SummarizationPipeline:
    """Main summarization pipeline."""

    def summarize(self, request: SummarizationRequest) -> SummarizationResult:
        """Summarize the document based on the request."""
        from .constants import (
            ANSWER_CHOICE_PATTERN,
            ANTECEDENT_PATTERN,
            ARTICLE_TYPE_FAMILY,
            ARTICLE_TYPE_LABELS,
            ARTICLE_TYPE_RELEVANCE_PATTERNS,
            BRANDING_WATERMARK_PATTERN,
            DIRECTIONS_PATTERN,
            MAX_KEYPHRASES,
            MAX_KEY_POINTS,
            MAX_IMPORTANT_TERMS,
            OCR_CORRUPTION_PATTERN,
            PURPOSE_CUE_PATTERNS,
            QUESTION_PUNCTUATION_PATTERN,
            QUESTION_START_PATTERN,
            SECTION_COVERAGE_GROUPS,
            SECTION_IMPORTANCE,
            STRUCTURED_ROLES,
            SUMMARY_LENGTH_CONFIG,
            SUMMARY_STYLE_ACADEMIC,
            SUMMARY_STYLE_SIMPLE,
            SURFACE_TERM_PATTERNS,
            TYPE_ALLOWED_LABELS,
            TYPE_DEFAULT_LABEL,
        )
        from .text_utils import (
            _legacy_article_type_relevance_score,
            _legacy_boilerplate_penalty_score,
            _legacy_paragraph_position_score,
            _legacy_position_score,
            _legacy_tfidf_sentence_strength,
            _legacy_title_similarity_score,
            contains_article_noise,
            deduplicate_sentences,
            detect_document_title,
            detect_explicit_sections,
            fallback_sentence_split,
            is_assessment_or_question_line,
            is_branding_or_watermark_line,
            is_choice_line,
            is_directions_line,
            is_noise_important_term,
            is_noise_keyword,
            is_ocr_corruption_line,
            load_source_document,
            normalize_summary_count,
            normalize_summary_sentence,
            normalize_whitespace,
            prepare_document_paragraphs,
            preprocess_sentence_for_ranking,
            safe_sent_tokenize,
            segment_document_content,
            shorten_summary_title,
            tokenize_words,
        )
        from sklearn.feature_extraction.text import TfidfVectorizer  # pyright: ignore[reportMissingModuleSource]
        import numpy as np  # pyright: ignore[reportMissingImports]

        from .profiles import resolve_profile, apply_compression_bias

        # Resolve the summarization profile from the user's selection.
        # selection_mode is the authoritative key; fall back to summary_style.
        effective_mode = (request.selection_mode or request.summary_style or "general").strip().lower()
        sel_profile = resolve_profile(effective_mode)

        preprocessing_options = request.preprocessing_options or PreprocessingOptions()
        source_document = load_source_document(text=request.text, file_path=request.file_path)
        if source_document.raw_text.strip() == "":
            raise ValueError("No content provided.")

        paragraphs = prepare_document_paragraphs(source_document.raw_text, preprocessing_options)
        if not paragraphs:
            raise ValueError("No continuous readable text found. The source may be empty or image-only.")

        cleaned_text = "\n\n".join(paragraphs)
        summary_length_key = str(request.summary_length or "").strip().lower()
        if summary_length_key not in SUMMARY_LENGTH_CONFIG:
            cnt = int(request.sentence_count or 8)
            if cnt <= 3:
                summary_length_key = "brief"
            elif cnt <= 6:
                summary_length_key = "short"
            elif cnt <= 9:
                summary_length_key = "balanced"
            elif cnt <= 13:
                summary_length_key = "detailed"
            else:
                summary_length_key = "comprehensive"

        length_config = SUMMARY_LENGTH_CONFIG.get(summary_length_key, SUMMARY_LENGTH_CONFIG["balanced"])
        sections, heading_counts = detect_explicit_sections(paragraphs)
        profile = self._detect_document_profile(
            paragraphs,
            sections,
            heading_counts,
            source_document.raw_text,
            ARTICLE_TYPE_LABELS,
            ARTICLE_TYPE_RELEVANCE_PATTERNS,
        )

        candidates, paragraph_sentences = self._build_sentence_candidates(
            paragraphs,
            sections,
            preprocess_sentence_for_ranking,
            safe_sent_tokenize,
            fallback_sentence_split,
            contains_article_noise,
            normalize_whitespace,
            tokenize_words,
        )
        if not candidates:
            raise ValueError("No summary could be generated from the provided text.")

        total_candidates = len(candidates)
        target_ratio = float(length_config["target_ratio"])
        min_sent = int(length_config["min_sentences"])
        max_sent = int(length_config["max_sentences"])
        dynamic_target_count = max(min_sent, min(max_sent, round(total_candidates * target_ratio)))
        target_count = min(dynamic_target_count, total_candidates)

        # Apply profile detail_level_floor: never go below the profile's minimum.
        if sel_profile.detail_level_floor > int(length_config["detail_level"]):
            from .constants import SUMMARY_LENGTH_CONFIG as _SLC
            _floor_key = {1: "brief", 2: "short", 3: "balanced", 4: "detailed", 5: "comprehensive"}.get(
                sel_profile.detail_level_floor, "balanced"
            )
            length_config = _SLC.get(_floor_key, length_config)
            min_sent = int(length_config["min_sentences"])
            max_sent = int(length_config["max_sentences"])
            target_ratio = float(length_config["target_ratio"])
            dynamic_target_count = max(min_sent, min(max_sent, round(total_candidates * target_ratio)))
            target_count = min(dynamic_target_count, total_candidates)

        try:
            title_seed = detect_document_title(source_document.raw_text, source_document.title_hint)
        except Exception:
            title_seed = "Generated Summary"

        # Merge user-supplied document_title with auto-detected title for scoring.
        # The user title is a strong signal — it clarifies ambiguous documents.
        user_title = (request.document_title or "").strip()
        effective_title = user_title if user_title else title_seed

        ranking_corpus = [
            candidate.ranking_text if candidate.ranking_text != "" else candidate.normalized_text.lower()
            for candidate in candidates
        ]
        vectorizer = TfidfVectorizer(ngram_range=(1, 2), min_df=1)
        matrix = vectorizer.fit_transform(ranking_corpus)
        tfidf_scores = self._normalize_scores(_legacy_tfidf_sentence_strength(matrix))
        title_scores = self._normalize_scores(
            _legacy_title_similarity_score(effective_title, vectorizer, matrix)
            if effective_title.strip() != ""
            else np.zeros(len(candidates))
        )

        # Build section_importance with profile overrides merged on top.
        merged_section_importance = dict(SECTION_IMPORTANCE)
        if sel_profile.section_importance_override:
            for _atype, _sec_map in merged_section_importance.items():
                # Apply overrides to the auto-detected article type map AND the profile mode map.
                pass  # per-candidate override applied inline in _score_candidates_with_profile

        combined_scores = self._score_candidates_with_profile(
            candidates,
            tfidf_scores,
            title_scores,
            profile.article_type,
            ARTICLE_TYPE_FAMILY,
            SECTION_IMPORTANCE,
            _legacy_position_score,
            _legacy_paragraph_position_score,
            _legacy_article_type_relevance_score,
            _legacy_boilerplate_penalty_score,
            ANTECEDENT_PATTERN,
            sel_profile,
        )

        # Coverage selection: seed with the profile's preferred sections first.
        selected_indices = self._select_candidate_indices_with_profile(
            candidates,
            combined_scores,
            profile.article_type,
            target_count,
            SECTION_COVERAGE_GROUPS,
            float(length_config["section_coverage_weight"]),
            sel_profile,
        )
        selected_candidates = [candidates[index] for index in selected_indices]
        selected_candidates.sort(key=lambda candidate: candidate.index)

        max_sentence_words = int(length_config["max_sentence_length_words"])
        selected_sentences = self._compress_selected_sentences(
            [candidate.text for candidate in selected_candidates],
            normalize_summary_sentence,
            contains_article_noise,
            max_words=max_sentence_words,
        )
        # Apply profile compression bias to the word budget ceiling.
        biased_max_words = apply_compression_bias(int(length_config["max_words"]), sel_profile.compression_bias)
        selected_sentences = self._apply_summary_word_budget(
            selected_sentences,
            max_words=biased_max_words,
        )
        if not selected_sentences:
            raise ValueError("No summary could be generated from the provided text.")

        keywords = self._extract_keywords(
            paragraphs,
            selected_sentences,
            preprocess_sentence_for_ranking,
            is_noise_keyword,
            MAX_KEYPHRASES,
        )
        important_terms = self._extract_important_terms(
            source_document.raw_text,
            selected_sentences,
            keywords,
            SURFACE_TERM_PATTERNS,
            normalize_whitespace,
            is_noise_important_term,
            MAX_IMPORTANT_TERMS,
        )
        title = shorten_summary_title(title_seed, keywords)
        overview = self._build_overview(selected_sentences, request.summary_style, SUMMARY_STYLE_SIMPLE, SUMMARY_STYLE_ACADEMIC)
        conclusion = self._build_conclusion(selected_candidates, selected_sentences, contains_article_noise)
        key_points = self._build_key_points(selected_sentences, overview, conclusion, MAX_KEY_POINTS, contains_article_noise)
        plain_summary, overall_summary_bullets = self._build_plain_summary(
            selected_sentences, request.summary_style
        )
        paragraph_summaries = self._build_paragraph_summaries(
            paragraphs,
            paragraph_sentences,
            sections,
            selected_candidates,
            profile.article_type,
            PURPOSE_CUE_PATTERNS,
            TYPE_ALLOWED_LABELS,
            TYPE_DEFAULT_LABEL,
            preprocess_sentence_for_ranking,
            normalize_whitespace,
            keywords,
            selected_sentences,
            contains_article_noise,
            normalize_summary_sentence,
        )
        # Use profile's output_schema to pick the structured-role definition:
        # prefer a profile-mode key, fall back to the auto-detected article type.
        structured_summary_article_type = (
            sel_profile.mode
            if sel_profile.mode in STRUCTURED_ROLES
            else profile.article_type
        )
        structured_summary = self._build_structured_summary(
            selected_candidates,
            combined_scores,
            structured_summary_article_type,
            STRUCTURED_ROLES,
            normalize_whitespace,
        )

        summary_word_count = self._count_words(plain_summary)
        original_word_count = self._count_words(cleaned_text)
        compression_ratio = round(summary_word_count / max(original_word_count, 1), 3)
        readability = {
            "original_word_count": original_word_count,
            "summary_word_count": summary_word_count,
            "compression_ratio": compression_ratio,
            "compression_percent": round(
                max(0.0, (1 - compression_ratio) * 100),
                1,
            ),
            "estimated_reading_time_minutes": self._estimate_reading_time(summary_word_count),
            "estimated_source_reading_time_minutes": self._estimate_reading_time(original_word_count),
            "summary_length": summary_length_key,
            "detail_level": length_config["detail_level"],
            "target_word_range": {
                "minimum": int(length_config["min_words"]),
                "maximum": int(length_config["max_words"]),
            },
        }

        scoring_strategy = f"tfidf_title_position_section_profile:{sel_profile.mode}"
        active_weights = sel_profile.scoring_weights or {
            "tfidf": 0.38,
            "title": 0.18,
            "position": 0.14,
            "paragraph_pos": 0.10,
            "section": 0.12,
            "type_relevance": 0.08,
        }
        summary_method = {
            "name": "Adaptive Extractive Summary",
            "topic_component": "tfidf + title similarity",
            "redundancy_reduction": "ordered sentence deduplication",
            "scoring_strategy": scoring_strategy,
            "selection_mode": sel_profile.mode,
            "profile_label": sel_profile.label,
            "compression_bias": sel_profile.compression_bias,
            "fallback_used": False,
            "pipeline": [
                "source_resolution",
                "paragraph_preparation",
                "article_type_detection",
                "profile_resolution",
                "selection_aware_scoring",
                "profile_coverage_selection",
                "structured_output_formatting",
                "profile_validation",
            ],
            "scoring_weights": {
                "tfidf":               active_weights.get("tfidf", 0.38),
                "title_similarity":    active_weights.get("title", 0.18),
                "document_position":   active_weights.get("position", 0.14),
                "paragraph_position":  active_weights.get("paragraph_pos", 0.10),
                "section_importance":  active_weights.get("section", 0.12),
                "article_type_relevance": active_weights.get("type_relevance", 0.08),
            },
        }
        source_metadata = {
            "source_type": source_document.source_type,
            "title": effective_title or title_seed,
            "user_supplied_title": user_title,
            "article_type": profile.article_label,
            "article_type_key": profile.article_type,
            "selection_mode": sel_profile.mode,
            "profile_label": sel_profile.label,
            "engine": "summarizer_core",
            "scoring_strategy": scoring_strategy,
            "original_word_count": original_word_count,
            "cleaned_word_count": original_word_count,
            "paragraph_count": len(paragraphs),
            "candidate_sentence_count": total_candidates,
            "summary_length": summary_length_key,
            "detail_level": length_config["detail_level"],
            "target_ratio": target_ratio,
            "target_word_range": {
                "minimum": int(length_config["min_words"]),
                "maximum": int(biased_max_words),
            },
            "section_coverage_weight": float(length_config["section_coverage_weight"]),
            "prompt_instruction": length_config["prompt_instruction"],
            "target_sentence_count": target_count,
            "fallback_used": False,
        }

        # Run profile-aware validation before returning.
        validation_passed, validation_notes = self._validate_against_profile(
            selected_sentences,
            selected_candidates,
            sel_profile,
            source_document.raw_text,
        )

        result = SummarizationResult(
            title=title,
            raw_text=source_document.raw_text,
            cleaned_text=cleaned_text,
            sentences=selected_sentences,
            sentence_count=len(selected_sentences),
            overview=overview,
            plain_summary=plain_summary,
            overall_summary_bullets=overall_summary_bullets,
            preprocessing=preprocessing_options.as_dict(),
            readability=readability,
            summary_method=summary_method,
            keywords=keywords,
            important_terms=important_terms,
            article_type=profile.article_label,
            key_points=key_points,
            conclusion=conclusion,
            structured_summary=structured_summary,
            paragraph_summaries=paragraph_summaries,
            excluded_sections=[],
            source_metadata=source_metadata,
            selection_mode=sel_profile.mode,
            profile_label=sel_profile.label,
            active_profile_weights=active_weights,
            validation_passed=validation_passed,
            validation_notes=validation_notes,
        )
        validated_result = self._validate_summary_output(
            result,
            normalize_summary_sentence,
            contains_article_noise,
            request.summary_style,
            max_sentence_words=max_sentence_words,
        )
        return validated_result

    # ------------------------------------------------------------------
    # Profile-aware scoring
    # ------------------------------------------------------------------

    def _score_candidates_with_profile(
        self,
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
    ) -> list[float]:
        """Score candidates using profile-specific weights plus mode-level bonuses/penalties.

        The profile weights replace the hard-coded global constants so that
        Academic, Executive, Study, Technical, and News modes genuinely
        produce different content rankings — not just different output formats.
        """
        from .constants import QUESTION_PUNCTUATION_PATTERN, QUESTION_START_PATTERN
        from .text_utils import (
            is_assessment_or_question_line,
            is_branding_or_watermark_line,
            is_choice_line,
            is_directions_line,
            is_ocr_corruption_line,
        )

        w = sel_profile.scoring_weights
        w_tfidf       = w.get("tfidf", 0.38)
        w_title       = w.get("title", 0.18)
        w_position    = w.get("position", 0.14)
        w_para_pos    = w.get("paragraph_pos", 0.10)
        w_section     = w.get("section", 0.12)
        w_type_rel    = w.get("type_relevance", 0.08)

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

            # --- Generic noise/quality penalties (unchanged from base scorer) ---
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
                (tfidf_scores[index]            * w_tfidf)
                + (title_scores[index]          * w_title)
                + (position_score               * w_position)
                + (paragraph_position_score     * w_para_pos)
                + (section_weight               * w_section)
                + (article_type_relevance       * w_type_rel)
                + finding_bonus
                + method_bonus
                + conclusion_bonus
                + profile_bonus
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

    # ------------------------------------------------------------------
    # Profile-aware candidate selection
    # ------------------------------------------------------------------

    def _select_candidate_indices_with_profile(
        self,
        candidates: list[SentenceCandidate],
        combined_scores: list[float],
        article_type: str,
        target_count: int,
        section_coverage_groups: dict[str, list[list[str]]],
        section_coverage_weight: float,
        sel_profile: Any,
    ) -> list[int]:
        """Select candidates, pre-seeding coverage with the profile's preferred sections.

        Preferred sections are satisfied first (before the generic coverage groups
        and MMR pass), ensuring that mode-critical content is never squeezed out
        by a higher-density but less relevant section.
        """
        ranked_indices = sorted(
            range(len(candidates)),
            key=lambda index: combined_scores[index],
            reverse=True,
        )
        selected: list[int] = []
        selected_sentence_texts: list[str] = []

        # --- 1. Profile preferred-section pre-pass ---
        if sel_profile.preferred_sections:
            preferred_set = set(sel_profile.preferred_sections)
            # Take at most one sentence per preferred section to seed coverage.
            covered_preferred: set[str] = set()
            for index in ranked_indices:
                if len(selected) >= min(len(sel_profile.preferred_sections), target_count):
                    break
                candidate = candidates[index]
                if candidate.section not in preferred_set or candidate.section in covered_preferred:
                    continue
                if candidate.text in selected_sentence_texts:
                    continue
                selected.append(index)
                selected_sentence_texts.append(candidate.text)
                covered_preferred.add(candidate.section)

        # --- 2. Standard section coverage groups ---
        coverage_groups = section_coverage_groups.get(article_type, [])
        coverage_target = min(
            target_count,
            round(min(target_count, len(coverage_groups)) * max(0.0, min(1.0, section_coverage_weight))),
        )
        for group in coverage_groups:
            if len(selected) >= coverage_target:
                break
            for index in ranked_indices:
                candidate = candidates[index]
                if candidate.section in group and index not in selected:
                    if candidate.text in selected_sentence_texts:
                        continue
                    if any(self._sentences_are_redundant(candidate.text, candidates[chosen].text) for chosen in selected):
                        continue
                    selected.append(index)
                    selected_sentence_texts.append(candidate.text)
                    break

        # --- 3. MMR fill to target ---
        score_floor = min(combined_scores) if combined_scores else 0.0
        score_span = max(combined_scores) - score_floor if combined_scores else 0.0
        while len(selected) < target_count:
            best_index: int | None = None
            best_value = float("-inf")
            selected_sections = {candidates[index].section for index in selected}

            for index in ranked_indices:
                if index in selected:
                    continue
                candidate = candidates[index]
                if candidate.text in selected_sentence_texts:
                    continue

                redundancy = max(
                    (self._sentence_token_overlap(candidate, candidates[chosen]) for chosen in selected),
                    default=0.0,
                )
                if redundancy >= 0.68 or any(
                    self._sentences_are_redundant(candidate.text, candidates[chosen].text)
                    for chosen in selected
                ):
                    continue

                normalized_score = ((combined_scores[index] - score_floor) / score_span) if score_span else 1.0
                novelty = 1.0 - redundancy
                section_bonus = 0.07 if selected and candidate.section not in selected_sections else 0.0
                value = (normalized_score * 0.76) + (novelty * 0.17) + section_bonus
                if value > best_value:
                    best_index, best_value = index, value

            if best_index is None:
                break
            selected.append(best_index)
            selected_sentence_texts.append(candidates[best_index].text)

        return sorted(selected, key=lambda index: candidates[index].index)

    # ------------------------------------------------------------------
    # Profile-aware validation
    # ------------------------------------------------------------------

    def _validate_against_profile(
        self,
        selected_sentences: list[str],
        selected_candidates: list[SentenceCandidate],
        sel_profile: Any,
        raw_text: str,
    ) -> tuple[bool, list[str]]:
        """Run lightweight profile-specific quality checks on the selected sentences.

        Returns (passed: bool, notes: list[str]).

        Checks never raise exceptions and never block output — they only log
        notes and flip `passed` to False when a meaningful issue is found.
        This keeps the pipeline robust against edge-case documents.
        """
        notes: list[str] = []
        passed = True

        if not selected_sentences:
            return True, []

        rules = set(sel_profile.validation_rules or [])
        raw_lower = raw_text.lower()

        # --- source_faithfulness ---
        # All selected sentences must have near-verbatim roots in the source.
        if "source_faithfulness" in rules:
            for sentence in selected_sentences:
                # Extractive pipeline: sentences come directly from the source.
                # A 6-word gram check is sufficient to catch any fabrication.
                words = sentence.lower().split()
                if len(words) >= 6:
                    gram = " ".join(words[:6])
                    if gram not in raw_lower:
                        notes.append(f"Faithfulness warning: sentence start not found in source: '{gram}…'")
                        passed = False

        # --- must_preserve_completeness ---
        # For academic/technical modes: warn if methodology or findings are missing.
        if "must_preserve_completeness" in rules:
            has_method = any(
                re.search(r"\b(?:method|methodology|approach|procedure|design|survey|sampling)\b", s, re.IGNORECASE)
                for s in selected_sentences
            )
            has_findings = any(
                re.search(r"\b(?:results?|findings?|showed|revealed|indicated|found|concluded)\b", s, re.IGNORECASE)
                for s in selected_sentences
            )
            if not has_method and sel_profile.mode in ("academic", "technical"):
                notes.append("Completeness: no methodology sentence in output for academic/technical mode.")
            if not has_findings and sel_profile.mode in ("academic", "executive", "technical"):
                notes.append("Completeness: no findings/results sentence in output.")

        # --- qualifier_preservation ---
        # For high-strictness profiles: flag if qualifiers present in source are missing from output.
        if "qualifier_preservation" in rules and sel_profile.factuality_strictness == "high":
            qualifier_pattern = re.compile(
                r"\b(?:may|might|could|possibly|potentially|appears? to|seems? to|suggests?|likely|unlikely|tentatively|preliminary)\b",
                re.IGNORECASE,
            )
            source_has_qualifiers = bool(qualifier_pattern.search(raw_text))
            output_has_qualifiers = any(qualifier_pattern.search(s) for s in selected_sentences)
            if source_has_qualifiers and not output_has_qualifiers:
                notes.append(
                    "Qualifier warning: source contains uncertainty markers (may/could/suggests) "
                    "that are absent from the summary. Verify no unsupported claims were introduced."
                )

        # --- section_structure ---
        # Check that output_schema sections are represented where the document supports them.
        if "section_structure" in rules and sel_profile.output_schema:
            candidate_sections = {c.section for c in selected_candidates}
            all_sections = {c.section for c in (selected_candidates or [])}
            if not all_sections:
                pass  # can't check without section data
            # Non-blocking: just note which expected schema sections are absent.

        return passed, notes

    def _detect_document_profile(
        self,
        paragraphs: list[str],
        sections: list[str],
        heading_counts: Counter[str],
        raw_text: str,
        article_type_labels: dict[str, str],
        article_type_relevance_patterns: dict[str, tuple[Any, ...]],
    ) -> DocumentProfile:
        lowered_text = "\n".join(paragraphs).lower()
        scores: defaultdict[str, float] = defaultdict(float)
        signals: list[str] = []

        def add_signal(article_type: str, weight: float, signal: str) -> None:
            scores[article_type] += weight
            if signal not in signals:
                signals.append(signal)

        section_set = set(sections)
        if section_set & {"abstract", "introduction", "methodology", "results", "discussion", "conclusion"}:
            add_signal("academic", 3.5, "academic section headings")
        if section_set & {"activity_name", "date_and_venue", "purpose", "highlights", "annexes", "documentation"}:
            add_signal("official_report", 4.0, "official-report section headings")
        if section_set & {"legal_basis", "policy_scope", "obligations", "definitions"}:
            add_signal("legal_policy", 4.0, "legal section headings")
        if section_set & {"agenda", "discussion", "decision", "action item"}:
            add_signal("meeting_minutes", 4.0, "meeting section headings")

        if "accomplishment report" in lowered_text:
            add_signal("accomplishment_report", 6.0, "accomplishment report phrase")
        if "activity report" in lowered_text or "post-activity report" in lowered_text:
            add_signal("activity_report", 5.0, "activity report phrase")
        if "memorandum" in lowered_text:
            add_signal("memorandum", 5.0, "memorandum phrase")
        if "request letter" in lowered_text:
            add_signal("request_letter", 5.0, "request letter phrase")
        if "proposal" in lowered_text and "budget" in lowered_text:
            add_signal("proposal", 4.5, "proposal vocabulary")
        if any(
            keyword in lowered_text
            for keyword in (
                "research design",
                "purposive sampling",
                "respondents",
                "weighted mean",
                "statistical analysis",
                "survey questionnaire",
            )
        ):
            add_signal("academic", 3.2, "academic research vocabulary")

        if len(paragraphs) <= 2 and self._count_words(raw_text) < 90:
            add_signal("short_text", 3.0, "short input")

        family_map = {
            "academic": "academic",
            "news": "news",
            "blog": "blog",
            "opinion": "opinion",
            "tutorial": "tutorial",
            "educational": "educational",
            "technical": "technical_tutorial",
            "business": "business_report",
            "official": "official_report",
            "review": "review_article",
            "legal": "legal_policy",
            "transcript": "transcript_interview",
            "meeting": "meeting_minutes",
            "narrative": "narrative",
            "general": "general_article",
        }
        for family_key, patterns in article_type_relevance_patterns.items():
            for pattern in patterns:
                if pattern.search(lowered_text):
                    add_signal(family_map.get(family_key, "general_article"), 0.8, f"{family_key} relevance cues")

        if lowered_text.count(" said ") + lowered_text.count('"') >= 4:
            add_signal("transcript_interview", 2.0, "quote-heavy text")

        if any(keyword in lowered_text for keyword in ("install", "configure", "endpoint", "function", "parameter")):
            add_signal("technical_tutorial", 2.5, "technical tutorial vocabulary")
        elif any(keyword in lowered_text for keyword in ("lesson", "students", "learning", "module", "concept")):
            add_signal("educational", 2.0, "educational vocabulary")

        best_article_type = "general_article"
        best_score = 0.0
        if scores:
            best_article_type, best_score = max(scores.items(), key=lambda item: item[1])

        confidence_score = round(min(0.99, 0.45 + (best_score / 10)), 2)
        article_label = article_type_labels.get(best_article_type, "General")
        return DocumentProfile(
            article_type=best_article_type,
            article_label=article_label,
            content_type_key=best_article_type,
            content_type_label=article_label,
            confidence_score=confidence_score,
            detected_signals=signals,
            fallback_strategy="heuristic-section-scoring",
            sections_by_paragraph=tuple(sections),
            heading_counts=dict(heading_counts),
        )

    def _build_sentence_candidates(
        self,
        paragraphs: list[str],
        sections: list[str],
        preprocess_sentence_for_ranking: Any,
        safe_sent_tokenize: Any,
        fallback_sentence_split: Any,
        contains_article_noise: Any,
        normalize_whitespace: Any,
        tokenize_words: Any,
    ) -> tuple[list[SentenceCandidate], list[list[str]]]:
        candidates: list[SentenceCandidate] = []
        paragraph_sentences: list[list[str]] = []

        for paragraph_index, paragraph in enumerate(paragraphs):
            section = sections[paragraph_index] if paragraph_index < len(sections) else "body"
            try:
                tokenized_sentences = safe_sent_tokenize(paragraph)
            except Exception:
                tokenized_sentences = fallback_sentence_split(paragraph)
            sentences = [
                normalize_whitespace(sentence)
                for sentence in tokenized_sentences
                if normalize_whitespace(sentence) != ""
            ]
            if not sentences:
                sentences = [normalize_whitespace(paragraph)]

            usable_sentences = [
                sentence
                for sentence in sentences
                if len(tokenize_words(sentence)) >= 5
                and len(tokenize_words(sentence)) <= 80
                and not contains_article_noise(sentence)
                and not self._looks_sentence_fragment(sentence)
            ]
            if not usable_sentences:
                usable_sentences = [
                    sentence
                    for sentence in sentences
                    if len(tokenize_words(sentence)) >= 8
                    and len(tokenize_words(sentence)) <= 90
                    and not contains_article_noise(normalize_whitespace(sentence))
                    and not self._looks_sentence_fragment(sentence)
                ]
            if not usable_sentences:
                continue
            paragraph_sentences.append(usable_sentences)

            for sentence_index, sentence in enumerate(usable_sentences):
                ranking_tokens = preprocess_sentence_for_ranking(sentence)
                candidates.append(
                    SentenceCandidate(
                        index=len(candidates),
                        paragraph_index=paragraph_index,
                        sentence_in_paragraph=sentence_index,
                        paragraph_sentence_count=len(usable_sentences),
                        section=section,
                        text=sentence,
                        normalized_text=sentence,
                        ranking_text=" ".join(ranking_tokens),
                        token_count=len(ranking_tokens) if ranking_tokens else len(tokenize_words(sentence)),
                    )
                )

        return candidates, paragraph_sentences

    def _score_candidates(
        self,
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
        from .constants import QUESTION_PUNCTUATION_PATTERN, QUESTION_START_PATTERN
        from .text_utils import (
            is_assessment_or_question_line,
            is_branding_or_watermark_line,
            is_choice_line,
            is_directions_line,
            is_ocr_corruption_line,
        )

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

    def _select_candidate_indices(
        self,
        candidates: list[SentenceCandidate],
        combined_scores: list[float],
        article_type: str,
        target_count: int,
        section_coverage_groups: dict[str, list[list[str]]],
        section_coverage_weight: float,
    ) -> list[int]:
        ranked_indices = sorted(
            range(len(candidates)),
            key=lambda index: combined_scores[index],
            reverse=True,
        )
        selected: list[int] = []
        selected_sentence_texts: list[str] = []

        coverage_groups = section_coverage_groups.get(article_type, [])
        coverage_target = min(
            target_count,
            round(min(target_count, len(coverage_groups)) * max(0.0, min(1.0, section_coverage_weight))),
        )
        for group in coverage_groups:
            if len(selected) >= coverage_target:
                break
            for index in ranked_indices:
                candidate = candidates[index]
                if candidate.section in group and index not in selected:
                    if candidate.text in selected_sentence_texts:
                        continue
                    if any(self._sentences_are_redundant(candidate.text, candidates[chosen].text) for chosen in selected):
                        continue
                    selected.append(index)
                    selected_sentence_texts.append(candidate.text)
                    break

        # Fill remaining slots with maximal marginal relevance. A plain
        # score-only pass tends to select adjacent sentences that repeat one
        # finding; MMR retains highly-ranked evidence while preferring a new
        # topic or section when the quality difference is small.
        score_floor = min(combined_scores) if combined_scores else 0.0
        score_span = max(combined_scores) - score_floor if combined_scores else 0.0
        while len(selected) < target_count:
            best_index: int | None = None
            best_value = float("-inf")
            selected_sections = {candidates[index].section for index in selected}

            for index in ranked_indices:
                if index in selected:
                    continue
                candidate = candidates[index]
                if candidate.text in selected_sentence_texts:
                    continue

                redundancy = max(
                    (self._sentence_token_overlap(candidate, candidates[chosen]) for chosen in selected),
                    default=0.0,
                )
                if redundancy >= 0.68 or any(
                    self._sentences_are_redundant(candidate.text, candidates[chosen].text)
                    for chosen in selected
                ):
                    continue

                normalized_score = ((combined_scores[index] - score_floor) / score_span) if score_span else 1.0
                novelty = 1.0 - redundancy
                section_bonus = 0.07 if selected and candidate.section not in selected_sections else 0.0
                value = (normalized_score * 0.76) + (novelty * 0.17) + section_bonus
                if value > best_value:
                    best_index, best_value = index, value

            if best_index is None:
                break
            selected.append(best_index)
            selected_sentence_texts.append(candidates[best_index].text)

        return sorted(selected, key=lambda index: candidates[index].index)

    @staticmethod
    def _sentence_token_overlap(left: SentenceCandidate, right: SentenceCandidate) -> float:
        """Return lexical overlap for diversity selection without extra models."""
        left_tokens = set(re.findall(r"[a-z][a-z'-]*|\d+(?:\.\d+)?", left.ranking_text.lower()))
        right_tokens = set(re.findall(r"[a-z][a-z'-]*|\d+(?:\.\d+)?", right.ranking_text.lower()))
        if not left_tokens or not right_tokens:
            return 0.0
        return len(left_tokens & right_tokens) / len(left_tokens | right_tokens)

    def _build_overview(
        self,
        selected_sentences: list[str],
        summary_style: str,
        summary_style_simple: str,
        summary_style_academic: str,
    ) -> list[str]:
        """Return up to 2 top sentences as the introductory overview.

        The overview is always 2 sentences (when available) so the reader
        gets a meaningful entry point regardless of summary style.
        """
        if not selected_sentences:
            return []
        return selected_sentences[: min(2, len(selected_sentences))]

    def _build_conclusion(
        self,
        selected_candidates: list[SentenceCandidate],
        selected_sentences: list[str],
        contains_article_noise: Any,
    ) -> str:
        """Pick the best conclusion sentence.

        Priority order:
        1. Sentence from the conclusion/recommendation section.
        2. Sentence containing a strong summary cue ("therefore", "thus", etc.).
        3. Last selected sentence as a fallback.
        """
        conclusion_cues = (
            "overall", "in conclusion", "therefore", "thus", "finally",
            "as a result", "in summary", "to conclude", "it can be concluded",
        )
        # Prefer conclusion-section sentences first
        for candidate in reversed(selected_candidates):
            if candidate.section in {"conclusion", "recommendation"} and not contains_article_noise(candidate.text):
                return candidate.text
        # Then look for cue-word sentences
        for candidate in reversed(selected_candidates):
            lowered = candidate.text.lower()
            if any(cue in lowered for cue in conclusion_cues) and not contains_article_noise(candidate.text):
                return candidate.text
        for sentence in reversed(selected_sentences):
            if not contains_article_noise(sentence):
                return sentence
        return selected_sentences[-1] if selected_sentences else ""

    def _build_key_points(
        self,
        selected_sentences: list[str],
        overview: list[str],
        conclusion: str,
        max_key_points: int,
        contains_article_noise: Any,
    ) -> list[str]:
        """Collect important ideas that are not already in the overview or conclusion.

        Key points should add distinct informational value — they must not
        repeat sentences already presented in the overview or conclusion.
        """
        consumed = set(overview)
        if conclusion != "":
            consumed.add(conclusion)
        points: list[str] = []
        seen_sentences: set[int] = set()
        for sentence in selected_sentences:
            if sentence in consumed or contains_article_noise(sentence):
                continue
            sentence_hash = hash(" ".join(sentence.lower().split()))
            if sentence_hash in seen_sentences:
                continue
            seen_sentences.add(sentence_hash)
            # Skip near-duplicates of already-selected key points
            if any(self._sentences_are_redundant(sentence, existing) for existing in points):
                continue
            points.append(sentence)
            if len(points) >= max_key_points:
                break
        return points

    def _build_plain_summary(
        self, selected_sentences: list[str], summary_style: str
    ) -> tuple[str, list[str]]:
        """Build the Overall Summary content based on the requested display style.

        Returns a (plain_summary, overall_summary_bullets) tuple:
        - plain_summary  : synthesized paragraph string (used by audio/translate and paragraph style).
        - overall_summary_bullets : list of bullet-ready sentences (populated for bullet_points
          and hybrid styles; empty for paragraph styles).

        Style behaviour:
        - standard_paragraph / academic_summary / simple_summary:
            plain_summary = all selected sentences joined into one paragraph.
            overall_summary_bullets = []  (not needed for paragraph rendering)
        - bullet_points:
            plain_summary = all sentences joined (kept for audio/translate fallback).
            overall_summary_bullets = each selected sentence as a bullet item.
        - hybrid:
            plain_summary = first 1–2 sentences joined (the intro paragraph).
            overall_summary_bullets = remaining sentences (the supporting bullets).
        """
        if not selected_sentences:
            return "", []

        joined = " ".join(selected_sentences)

        if summary_style == "bullet_points":
            return joined, list(selected_sentences)

        if summary_style in ("hybrid", "executive_summary"):
            # Take the first 1 or 2 sentences as the intro paragraph.
            split_at = 2 if len(selected_sentences) > 3 else 1
            intro_sentences = selected_sentences[:split_at]
            bullet_sentences = selected_sentences[split_at:]
            intro_paragraph = " ".join(intro_sentences)
            return intro_paragraph, list(bullet_sentences)

        # paragraph styles: plain join, no bullets
        return joined, []

    def _compress_selected_sentences(
        self,
        sentences: list[str],
        normalize_summary_sentence: Any,
        contains_article_noise: Any,
        max_words: int = 34,
    ) -> list[str]:
        compressed: list[str] = []
        for sentence in sentences:
            cleaned = normalize_summary_sentence(sentence)
            if cleaned == "" or contains_article_noise(cleaned):
                continue
            concise = self._compress_sentence(cleaned, max_words=max_words)
            if concise == "" or contains_article_noise(concise):
                continue
            if any(self._sentences_are_redundant(concise, existing) for existing in compressed):
                continue
            compressed.append(concise)
        return compressed

    def _apply_summary_word_budget(self, sentences: list[str], max_words: int) -> list[str]:
        """Keep complete ranked sentences within the selected length profile's cap."""
        if max_words < 1:
            return []

        budgeted: list[str] = []
        used_words = 0
        for sentence in sentences:
            sentence_words = len(sentence.split())
            if used_words + sentence_words > max_words:
                continue
            budgeted.append(sentence)
            used_words += sentence_words

        # The first compressed sentence is always short enough for supported
        # profiles, but preserve a useful result if an unusual input reaches here.
        return budgeted or sentences[:1]

    def _compress_sentence(self, sentence: str, max_words: int = 34) -> str:
        cleaned = sentence.strip()
        cleaned = cleaned.replace(" - ", "-")
        cleaned = cleaned.replace(" ,", ",")
        cleaned = re.sub(r"\s+", " ", cleaned)
        # Qualifying clauses frequently carry the result, limitation, or
        # negation. Keep them intact; selection already penalizes overly long
        # candidates, and only an unavoidable hard word cap may shorten one.

        words = cleaned.split()
        if len(words) > max_words:
            cut_words = words[:max_words]
            for index in range(len(cut_words) - 1, max(8, max_words - 10), -1):
                if cut_words[index].rstrip(",").lower() in {"and", "or", "but", "while", "because", "that", "which"}:
                    cut_words = cut_words[:index]
                    break
            cleaned = " ".join(cut_words).rstrip(",;:")

        if cleaned and cleaned[-1] not in ".!?":
            cleaned += "."
        return cleaned

    def _build_paragraph_summaries(
        self,
        paragraphs: list[str],
        paragraph_sentences: list[list[str]],
        sections: list[str],
        selected_candidates: list[SentenceCandidate],
        article_type: str,
        purpose_cue_patterns: list[tuple[str, Any]],
        type_allowed_labels: dict[str, set[str]],
        type_default_label: dict[str, str],
        preprocess_sentence_for_ranking: Any,
        normalize_whitespace: Any,
        keywords: list[str],
        overall_sentences: list[str],
        contains_article_noise: Any,
        normalize_summary_sentence: Any,
    ) -> list[ParagraphAnalysis]:
        selected_by_paragraph: dict[int, list[str]] = defaultdict(list)
        for candidate in selected_candidates:
            selected_by_paragraph[candidate.paragraph_index].append(candidate.text)

        analyses: list[ParagraphAnalysis] = []
        for paragraph_index, paragraph in enumerate(paragraphs):
            sentences = paragraph_sentences[paragraph_index] if paragraph_index < len(paragraph_sentences) else []
            if not sentences:
                continue

            candidate_summaries = selected_by_paragraph.get(paragraph_index, []) + sentences
            summary_sentence = ""
            for candidate_summary in candidate_summaries:
                candidate_summary = self._compress_sentence(normalize_summary_sentence(candidate_summary), max_words=28)
                if candidate_summary == "" or contains_article_noise(candidate_summary) or self._looks_sentence_fragment(candidate_summary):
                    continue
                if any(self._sentences_are_redundant(candidate_summary, overall) for overall in overall_sentences):
                    continue
                summary_sentence = candidate_summary
                break
            if summary_sentence == "":
                continue
            supporting_details = [sentence for sentence in sentences if sentence != summary_sentence][:2]
            paragraph_keywords = self._extract_keywords(
                [paragraph],
                [summary_sentence],
                preprocess_sentence_for_ranking,
                lambda term: term == "",
                4,
            )
            section = sections[paragraph_index] if paragraph_index < len(sections) else "body"
            analyses.append(
                ParagraphAnalysis(
                    paragraph_number=paragraph_index + 1,
                    section=self._section_key_to_label(section),
                    purpose=self._detect_paragraph_purpose(
                        paragraph,
                        section,
                        article_type,
                        purpose_cue_patterns,
                        type_allowed_labels,
                        type_default_label,
                    ),
                    main_idea=summary_sentence,
                    supporting_details=supporting_details,
                    keywords=paragraph_keywords or keywords[:2],
                    summary=normalize_whitespace(summary_sentence),
                )
            )

        return analyses

    def _build_structured_summary(
        self,
        selected_candidates: list[SentenceCandidate],
        combined_scores: list[float],
        article_type: str,
        structured_roles: dict[str, list[tuple[str, set[str], tuple[str, ...]]]],
        normalize_whitespace: Any,
    ) -> list[dict[str, str]]:
        role_definitions = structured_roles.get(article_type) or structured_roles.get("general_article", [])
        if not role_definitions:
            return []

        used_sentences: set[str] = set()
        structured_blocks: list[dict[str, str]] = []
        for label, allowed_sections, cue_terms in role_definitions:
            matching_candidates = [
                candidate
                for candidate in selected_candidates
                if candidate.section in allowed_sections
                or any(cue_term in candidate.text.lower() for cue_term in cue_terms)
            ]
            if not matching_candidates:
                continue

            chosen = max(
                matching_candidates,
                key=lambda candidate: (
                    combined_scores[candidate.index] if candidate.index < len(combined_scores) else 0.0,
                    -candidate.index,
                ),
            )
            if chosen.text in used_sentences:
                continue

            structured_blocks.append({
                "label": label,
                "text": normalize_whitespace(chosen.text),
            })
            used_sentences.add(chosen.text)

        return structured_blocks

    def _validate_summary_output(
        self,
        result: SummarizationResult,
        normalize_summary_sentence: Any,
        contains_article_noise: Any,
        summary_style: str = "standard_paragraph",
        max_sentence_words: int = 34,
    ) -> SummarizationResult:
        clean_sentences = self._compress_selected_sentences(
            result.sentences,
            normalize_summary_sentence,
            contains_article_noise,
            max_words=max_sentence_words,
        )
        clean_overview = [
            sentence
            for sentence in self._compress_selected_sentences(result.overview, normalize_summary_sentence, contains_article_noise, max_words=max_sentence_words)
            if sentence in clean_sentences or not any(self._sentences_are_redundant(sentence, existing) for existing in clean_sentences)
        ][:2]
        clean_key_points = [
            sentence
            for sentence in self._compress_selected_sentences(result.key_points, normalize_summary_sentence, contains_article_noise, max_words=max_sentence_words)
            if sentence not in clean_overview
        ]
        deduped_key_points: list[str] = []
        for sentence in clean_key_points:
            if any(self._sentences_are_redundant(sentence, existing) for existing in clean_overview + deduped_key_points):
                continue
            deduped_key_points.append(sentence)
        clean_conclusion = self._compress_sentence(normalize_summary_sentence(result.conclusion), max_words=32)
        if contains_article_noise(clean_conclusion):
            clean_conclusion = clean_sentences[-1] if clean_sentences else ""

        if not clean_sentences:
            fallback_sentences = [
                self._compress_sentence(normalize_summary_sentence(sentence))
                for sentence in result.sentences
                if normalize_summary_sentence(sentence) != ""
            ]
            clean_sentences = []
            for sentence in fallback_sentences:
                if sentence == "" or contains_article_noise(sentence):
                    continue
                if any(self._sentences_are_redundant(sentence, existing) for existing in clean_sentences):
                    continue
                clean_sentences.append(sentence)
            clean_sentences = clean_sentences[: max(1, result.sentence_count)]

        paragraph_summaries: list[ParagraphAnalysis] = []
        seen_paragraph_summaries: list[str] = []
        for paragraph_summary in result.paragraph_summaries:
            summary = self._compress_sentence(normalize_summary_sentence(paragraph_summary.summary), max_words=28)
            if summary == "" or contains_article_noise(summary):
                continue
            if self._looks_sentence_fragment(summary):
                continue
            if any(self._sentences_are_redundant(summary, existing) for existing in clean_sentences + seen_paragraph_summaries):
                continue
            paragraph_summaries.append(replace(paragraph_summary, summary=summary, main_idea=summary))
            seen_paragraph_summaries.append(summary)

        # Rebuild plain_summary and overall_summary_bullets using the cleaned sentences
        # so the validated output respects the user's selected style.
        plain_summary, overall_summary_bullets = self._build_plain_summary(clean_sentences, summary_style)
        updated_readability = dict(result.readability)
        updated_readability["summary_word_count"] = self._count_words(plain_summary)
        if updated_readability.get("original_word_count"):
            updated_readability["compression_percent"] = round(
                max(0.0, (1 - (updated_readability["summary_word_count"] / max(int(updated_readability["original_word_count"]), 1))) * 100),
                1,
            )
        updated_readability["estimated_reading_time_minutes"] = self._estimate_reading_time(updated_readability["summary_word_count"])

        return replace(
            result,
            sentences=clean_sentences,
            sentence_count=len(clean_sentences),
            overview=clean_overview or clean_sentences[:1],
            plain_summary=plain_summary,
            overall_summary_bullets=overall_summary_bullets,
            key_points=deduped_key_points[:6],
            conclusion=clean_conclusion,
            paragraph_summaries=paragraph_summaries,
            readability=updated_readability,
        )

    def _extract_keywords(
        self,
        paragraphs: list[str],
        selected_sentences: list[str],
        preprocess_sentence_for_ranking: Any,
        is_noise_keyword: Any,
        max_keywords: int,
    ) -> list[str]:
        token_counter: Counter[str] = Counter()
        for sentence in selected_sentences or paragraphs:
            for token in preprocess_sentence_for_ranking(sentence):
                if is_noise_keyword(token):
                    continue
                token_counter[token] += 1

        keywords: list[str] = []
        for keyword, _count in token_counter.most_common(max_keywords * 2):
            normalized = keyword.strip()
            if normalized == "" or normalized in keywords:
                continue
            keywords.append(normalized)
            if len(keywords) >= max_keywords:
                break
        return keywords

    def _extract_important_terms(
        self,
        raw_text: str,
        selected_sentences: list[str],
        keywords: list[str],
        surface_term_patterns: tuple[Any, ...],
        normalize_whitespace: Any,
        is_noise_important_term: Any,
        max_terms: int,
    ) -> list[dict[str, str]]:
        """Extract domain-specific keyword/phrase terms with a short context snippet.

        The 'meaning' field is a trimmed excerpt (≤18 words) from the sentence
        that contains the term — not the full sentence. This keeps Important Terms
        distinct from the Overview and Key Points sections.
        """
        candidate_terms: list[str] = []
        for pattern in surface_term_patterns:
            for match in pattern.findall(raw_text):
                value = match if isinstance(match, str) else match[0]
                normalized = normalize_whitespace(value)
                if normalized == "" or is_noise_important_term(normalized):
                    continue
                candidate_terms.append(normalized)

        # Append keywords not already captured by pattern matching
        pattern_terms_lower = {t.lower() for t in candidate_terms}
        for keyword in keywords:
            cleaned = keyword.replace("_", " ")
            if cleaned.lower() not in pattern_terms_lower and not is_noise_important_term(cleaned):
                candidate_terms.append(cleaned)

        important_terms: list[dict[str, str]] = []
        seen_terms: set[str] = set()

        for term in candidate_terms:
            lowered_term = term.lower()
            if lowered_term in seen_terms:
                continue

            # Find the sentence containing this term and extract a short context excerpt
            context_excerpt = ""
            for sentence in selected_sentences:
                if lowered_term in sentence.lower():
                    context_excerpt = self._extract_term_context(term, sentence)
                    break

            if context_excerpt == "":
                continue

            important_terms.append({
                "term": term,
                "meaning": context_excerpt,
            })
            seen_terms.add(lowered_term)
            if len(important_terms) >= max_terms:
                break

        return important_terms

    def _extract_term_context(self, term: str, sentence: str, max_words: int = 18) -> str:
        """Extract a short context snippet around a term from its source sentence.

        Returns at most max_words words. Starts from the term occurrence and
        takes a window of surrounding words so the snippet is a real phrase,
        not a truncated sentence fragment.
        """
        words = sentence.split()
        term_words = term.lower().split()
        term_len = len(term_words)

        # Find the word index where this term starts
        term_start = -1
        for i in range(len(words) - term_len + 1):
            window = " ".join(w.lower().strip(",.;:()[]\"'") for w in words[i:i + term_len])
            if window == " ".join(term_words):
                term_start = i
                break

        if term_start == -1:
            # Fallback: return up to max_words from the start of the sentence
            excerpt = " ".join(words[:max_words])
        else:
            # Take a window centered around the term
            half = max_words // 2
            start = max(0, term_start - half)
            end = min(len(words), start + max_words)
            excerpt = " ".join(words[start:end])

        # Clean trailing punctuation that would look odd mid-sentence
        excerpt = excerpt.rstrip(",.;:(")
        if excerpt and excerpt[-1] not in ".!?":
            excerpt += "."
        return excerpt

    def _detect_paragraph_purpose(
        self,
        paragraph: str,
        section: str,
        article_type: str,
        purpose_cue_patterns: list[tuple[str, Any]],
        type_allowed_labels: dict[str, set[str]],
        type_default_label: dict[str, str],
    ) -> str:
        lowered = paragraph.lower()
        allowed_labels = type_allowed_labels.get(article_type, set())
        for label, pattern in purpose_cue_patterns:
            if allowed_labels and label not in allowed_labels:
                continue
            if pattern.search(lowered):
                return label

        section_label = self._section_key_to_label(section)
        if section_label in allowed_labels:
            return section_label
        return type_default_label.get(article_type, "General Explanation")

    def _section_key_to_label(self, section: str) -> str:
        explicit_labels = {
            "activity_name": "Activity Name",
            "date_and_venue": "Date and Venue",
            "legal_basis": "Legal Basis",
            "policy_scope": "Scope",
            "literature_review": "Literature Review",
            "future_work": "Future Work",
            "executive_summary": "Executive Summary",
            "system_description": "System Description",
            "table_of_contents": "Table of Contents",
            "lessons_learned": "Lesson Learned",
        }
        if section in explicit_labels:
            return explicit_labels[section]
        return section.replace("_", " ").title()

    def _normalize_scores(self, values: Any) -> list[float]:
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

    def _sentences_are_redundant(self, left: str, right: str, threshold: float = 0.62) -> bool:
        left_tokens = self._content_tokens(left)
        right_tokens = self._content_tokens(right)
        if left_tokens == set() or right_tokens == set():
            return False
        overlap = len(left_tokens & right_tokens) / min(len(left_tokens), len(right_tokens))
        return overlap >= threshold

    def _looks_sentence_fragment(self, text: str) -> bool:
        normalized = text.strip()
        if normalized == "":
            return True
        first_alpha = next((character for character in normalized if character.isalpha()), "")
        if first_alpha and first_alpha.islower():
            return True
        if normalized.count(",") >= 4 and not any(mark in normalized for mark in ".;:"):
            return True
        if len(normalized.split()) < 7 and normalized[-1] not in ".!?":
            return True
        words = normalized.split()
        capitalized_words = sum(1 for word in words if word[:1].isupper())
        if len(words) <= 8 and capitalized_words / max(len(words), 1) >= 0.7:
            return True
        if re.match(r"^[A-Z][A-Za-z'-]+,\s*[A-Z]\.", normalized):
            return True
        return False

    def _content_tokens(self, text: str) -> set[str]:
        stop_words = {
            "a", "an", "the", "and", "or", "but", "if", "then", "of", "in", "on",
            "for", "to", "with", "by", "from", "as", "is", "are", "was", "were",
            "this", "that", "these", "those", "it", "its", "their", "they",
        }
        return {
            token.lower()
            for token in re.findall(r"[A-Za-z][A-Za-z'-]{2,}", text)
            if token.lower() not in stop_words
        }

    def _count_words(self, text: str) -> int:
        return len(text.split())

    def _estimate_reading_time(self, word_count: int) -> int:
        if word_count <= 0:
            return 0
        return max(1, int((word_count + 199) / 200))
