"""Summarization pipeline orchestration and execution engine."""

from __future__ import annotations

from collections import Counter, defaultdict
from dataclasses import replace
import logging
import re
from typing import Any

import numpy as np  # pyright: ignore[reportMissingImports]
from sklearn.feature_extraction.text import TfidfVectorizer  # pyright: ignore[reportMissingModuleSource]

from .constants import (
    ANTECEDENT_PATTERN,
    ARTICLE_TYPE_FAMILY,
    ARTICLE_TYPE_LABELS,
    ARTICLE_TYPE_RELEVANCE_PATTERNS,
    DEPTH_CONFIG,
    DISCOURSE_BOOST_PATTERNS,
    MAX_IMPORTANT_TERMS,
    MAX_KEY_POINTS,
    MAX_KEYPHRASES,
    PURPOSE_CUE_PATTERNS,
    QUESTION_PUNCTUATION_PATTERN,
    QUESTION_START_PATTERN,
    SECTION_COVERAGE_GROUPS,
    SECTION_IMPORTANCE,
    SUMMARY_STYLE_ACADEMIC,
    SUMMARY_STYLE_SIMPLE,
    SURFACE_TERM_PATTERNS,
    TYPE_ALLOWED_LABELS,
    TYPE_DEFAULT_LABEL,
)
from .models import (
    CleanedDocument,
    DocumentProfile,
    ParagraphAnalysis,
    PreprocessingOptions,
    SentenceCandidate,
    SentenceScoringResult,
    SourceDocument,
    SourceInput,
    StructuredSummaryOutput,
    SummarizationRequest,
    SummarizationResult,
)
from .fact_ledger import build_fact_ledger, validate_summary_facts
from .document_analysis import (
    analyze_document_structure,
    build_dynamic_summary_sections,
    build_source_evidence,
    build_summary_plan,
)
from .profiles import resolve_profile
from .retrieval import (
    MAX_RETRIEVAL_SCORE_ADJUSTMENT,
    map_selected_evidence,
    retrieve_candidates,
)
from .retrieval_models import RetrievalChunk, RetrievalHit, RetrievalOutcome
from .scoring import (
    MODE_FOCUS_PATTERNS,
    NEGATION_CUES,
    NUMERIC_PATTERN,
    QUALIFIER_PATTERN,
    calculate_candidate_scores,
    calculate_candidate_scores_with_profile,
    extract_content_tokens,
    normalize_scores,
    sentence_token_overlap,
    sentences_are_redundant,
)
from .selection import (
    detect_topic_clusters,
    ensure_depth_coverage,
    select_candidate_indices,
    select_candidate_indices_with_profile,
)
from .text_utils import (
    _legacy_article_type_relevance_score,
    _legacy_boilerplate_penalty_score,
    _legacy_paragraph_position_score,
    _legacy_position_score,
    _legacy_tfidf_sentence_strength,
    _legacy_title_similarity_score,
    contains_article_noise,
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
    normalize_summary_sentence,
    normalize_whitespace,
    prepare_document_paragraphs,
    preprocess_sentence_for_ranking,
    safe_sent_tokenize,
    shorten_summary_title,
    tokenize_words,
)


class SummarizationPipeline:
    """Main summarization pipeline."""

    _MODE_FOCUS_PATTERNS = MODE_FOCUS_PATTERNS
    _NEGATION_CUES = NEGATION_CUES
    _QUALIFIER_PATTERN = QUALIFIER_PATTERN
    _NUMERIC_PATTERN = NUMERIC_PATTERN
    _logger = logging.getLogger(__name__)

    def summarize(self, request: SummarizationRequest) -> SummarizationResult:
        """Summarize the document based on the request."""
        # Resolve the summarization profile from the user's selection.
        # selection_mode is the authoritative key; fall back to summary_style.
        effective_mode = (
            request.analysis_mode
            or request.selection_mode
            or request.summary_style
            or "general"
        ).strip().lower()
        sel_profile = resolve_profile(effective_mode)
        output_format = self._resolve_output_format(request.output_format, request.summary_style)
        summary_normalizer = lambda value: normalize_summary_sentence(
            value,
            preserve_statistical_details=sel_profile.mode == "technical",
        )

        preprocessing_options = request.preprocessing_options or PreprocessingOptions()
        source_document = load_source_document(text=request.text, file_path=request.file_path)
        if source_document.raw_text.strip() == "":
            raise ValueError("No content provided.")

        paragraphs = prepare_document_paragraphs(source_document.raw_text, preprocessing_options)
        if not paragraphs:
            raise ValueError("No continuous readable text found. The source may be empty or image-only.")

        cleaned_text = "\n\n".join(paragraphs)
        original_word_count = self._count_words(cleaned_text)

        raw_depth = str(
            request.summary_depth or request.summary_length or ""
        ).strip().lower()
        _NUMERIC_DEPTH_MAP = {
            "0": "brief",
            "1": "short",
            "2": "balanced",
            "3": "detailed",
            "4": "comprehensive",
        }
        if raw_depth in _NUMERIC_DEPTH_MAP:
            summary_length_key = _NUMERIC_DEPTH_MAP[raw_depth]
        elif raw_depth in DEPTH_CONFIG:
            summary_length_key = raw_depth
        else:
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

        length_config = DEPTH_CONFIG.get(summary_length_key, DEPTH_CONFIG["balanced"])
        sections, heading_counts = detect_explicit_sections(paragraphs, raw_text=source_document.raw_text)
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

        document_analysis, candidates = analyze_document_structure(
            paragraphs,
            sections,
            candidates,
            page_texts=source_document.page_texts,
        )
        summary_plan = build_summary_plan(
            document_analysis,
            candidates,
            sel_profile.mode,
            summary_length_key,
        )

        try:
            title_seed = detect_document_title(source_document.raw_text, source_document.title_hint)
        except Exception:
            title_seed = "Generated Summary"

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

        # Topic detection using TF-IDF representation (deterministic clustering)
        topic_clusters = self._detect_topic_clusters(candidates, matrix, tfidf_scores)
        detected_sections = {candidate.section for candidate in candidates if candidate.section}

        # Dynamic target calculation based on source length, candidates, topics, sections, depth, and profile
        target_count, dynamic_max_words = self._calculate_dynamic_target(
            source_words=original_word_count,
            candidate_count=len(candidates),
            detected_topics_count=len(topic_clusters),
            detected_sections_count=len(detected_sections),
            depth_key=summary_length_key,
            depth_config=length_config,
            profile_compression_bias=sel_profile.compression_bias,
        )

        retrieval_outcome = None
        retrieval_metadata: dict[str, Any]
        try:
            retrieval_outcome = retrieve_candidates(
                candidates,
                analysis_mode=sel_profile.mode,
                summary_depth=summary_length_key,
                document_title=effective_title,
                target_count=target_count,
                source_word_count=original_word_count,
                compression_bias=sel_profile.compression_bias,
            )
            if not isinstance(retrieval_outcome, RetrievalOutcome):
                raise TypeError("Retrieval returned an invalid result object.")
            if len(retrieval_outcome.score_adjustments) != len(candidates):
                raise ValueError("Retrieval score count did not match sentence candidates.")
            if not isinstance(retrieval_outcome.metadata, dict):
                raise TypeError("Retrieval returned invalid metadata.")
            if not all(
                isinstance(chunk, RetrievalChunk)
                for chunk in retrieval_outcome.chunks
            ) or not all(isinstance(hit, RetrievalHit) for hit in retrieval_outcome.hits):
                raise TypeError("Retrieval returned malformed chunks or hits.")
            if not all(
                isinstance(adjustment, (int, float))
                and np.isfinite(adjustment)
                and abs(adjustment) <= MAX_RETRIEVAL_SCORE_ADJUSTMENT
                for adjustment in retrieval_outcome.score_adjustments
            ):
                raise ValueError("Retrieval returned an invalid score adjustment.")
            retrieval_metadata = dict(retrieval_outcome.metadata)
        except Exception as exc:
            self._logger.exception("Request-local retrieval failed; continuing with legacy ranking.")
            retrieval_metadata = {
                "status": "fallback",
                "backend": "none",
                "fallback_reason": f"retrieval_error:{type(exc).__name__}",
                "chunk_count": 0,
                "retrieved_chunk_count": 0,
                "retrieval_breadth": 0,
                "profile": sel_profile.mode,
                "summary_depth": summary_length_key,
                "semantic_score_adjustment_limit": 0.0,
                "coverage_ratio": 0.0,
                "retrieved_sections": [],
                "evidence_mapping_status": "unavailable",
            }
            retrieval_outcome = None

        # Depth-aware candidate scoring
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
            depth_key=summary_length_key,
            depth_config=length_config,
        )
        for claim, score in zip(document_analysis["claims"], combined_scores, strict=True):
            claim["importance"] = round(float(score), 6)
        if retrieval_outcome is not None:
            combined_scores = [
                score + adjustment
                for score, adjustment in zip(
                    combined_scores,
                    retrieval_outcome.score_adjustments,
                    strict=True,
                )
            ]

        # Depth-aware candidate selection with topic coverage and section quota controls
        selected_indices = self._select_candidate_indices_with_profile(
            candidates,
            combined_scores,
            profile.article_type,
            target_count,
            SECTION_COVERAGE_GROUPS,
            float(length_config["section_coverage_weight"]),
            sel_profile,
            topic_clusters=topic_clusters,
            depth_key=summary_length_key,
            depth_config=length_config,
        )
        selected_indices = self._ensure_depth_coverage(
            selected_indices,
            candidates,
            combined_scores,
            target_count,
            include_limitations=bool(length_config.get("include_limitations", False)),
            include_conclusion=bool(length_config.get("include_conclusion", False)),
        )
        selected_candidates = [candidates[index] for index in selected_indices]
        selected_candidates = self._order_summary_candidates(
            selected_candidates,
            mode=sel_profile.mode,
            document_stage=document_analysis.get("document_stage", "unknown"),
        )

        max_sentence_words = int(length_config["max_sentence_length_words"])
        selected_sentences = self._compress_selected_sentences(
            [candidate.text for candidate in selected_candidates],
            summary_normalizer,
            contains_article_noise,
            max_words=max_sentence_words,
        )
        selected_sentences = self._apply_summary_word_budget(
            selected_sentences,
            max_words=dynamic_max_words,
        )
        from .fact_ledger import FactualityValidator
        validated_sentences, factuality_report = FactualityValidator.validate_and_repair(
            selected_sentences,
            candidates,
            document_stage=document_analysis.get("document_stage", "unknown"),
        )
        if validated_sentences:
            selected_sentences = validated_sentences
        if not selected_sentences:
            raise ValueError("No summary could be generated from the provided text.")

        # Synthesis is doubly opt-in: a request flag plus server-side provider config.
        # The default path stays fully local and extractive.
        from .synthesis import build_cited_evidence, synthesize_from_evidence

        requested_word_budget = request.target_word_budget
        if isinstance(requested_word_budget, int) and not isinstance(requested_word_budget, bool) and requested_word_budget > 0:
            dynamic_max_words = min(dynamic_max_words, min(50000, requested_word_budget))
        synthesis = synthesize_from_evidence(
            selected_candidates,
            requested=request.use_llm_synthesis,
            profile=sel_profile.mode,
            depth=summary_length_key,
            output_format=output_format,
            word_budget=dynamic_max_words,
            max_sentence_words=max_sentence_words,
            normalize_sentence=summary_normalizer,
        )
        synthesis_metadata = dict(synthesis["metadata"])
        synthesis_note = None
        if synthesis["accepted"]:
            selected_sentences = list(synthesis["sentences"])
        elif request.use_llm_synthesis:
            synthesis_note = (
                "Language-model synthesis was not accepted; the extractive summary was kept. "
                "Reason: " + str(synthesis_metadata.get("status", "unavailable"))
            )
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
        # Prefer the user's explicit title when it still matches topics present
        # in the final summary; otherwise use the detected source heading.
        title = shorten_summary_title(user_title or title_seed, keywords)
        overview = self._build_overview(
            selected_sentences,
            request.summary_style,
            SUMMARY_STYLE_SIMPLE,
            SUMMARY_STYLE_ACADEMIC,
            depth_key=summary_length_key,
        )
        conclusion = ""
        if document_analysis["conclusion_available"]:
            conclusion = self._build_conclusion(
                selected_candidates,
                selected_sentences,
                contains_article_noise,
                depth_key=summary_length_key,
                depth_config=length_config,
            )
        min_kp, max_kp = length_config.get("key_points_range", (3, 5))
        key_points = self._build_key_points(
            selected_sentences,
            overview,
            conclusion,
            min_key_points=int(min_kp),
            max_key_points=int(max_kp),
            contains_article_noise=contains_article_noise,
        )
        plain_summary, overall_summary_bullets = self._build_plain_summary(
            selected_sentences, output_format
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
            summary_normalizer,
        )
        structured_summary = build_dynamic_summary_sections(
            selected_candidates,
            summary_plan,
            max_per_section=1 if summary_length_key in {"brief", "short"} else 3,
        )

        covered_topic_indices = set()
        cand_to_cluster = {c_idx: cl_idx for cl_idx, cl in enumerate(topic_clusters) for c_idx in cl}
        for idx in selected_indices:
            if idx in cand_to_cluster:
                covered_topic_indices.add(cand_to_cluster[idx])
        covered_topics_count = len(covered_topic_indices)
        covered_sections = sorted({candidate.section for candidate in selected_candidates if candidate.section})

        summary_word_count = self._count_words(plain_summary)
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
            "summary_depth": summary_length_key,
            "detail_level": length_config["detail_level"],
            "topics_detected": len(topic_clusters),
            "topics_covered": covered_topics_count,
            "sections_detected": len(detected_sections),
            "sections_represented": len(covered_sections),
            "target_word_range": {
                "minimum": min(int(length_config["min_words"]), int(dynamic_max_words)),
                "maximum": int(dynamic_max_words),
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
            "name": "Evidence-Controlled Hybrid Summary" if synthesis["accepted"] else "Adaptive Extractive Summary",
            "topic_component": "tfidf + title similarity",
            "redundancy_reduction": "ordered sentence deduplication",
            "scoring_strategy": scoring_strategy,
            "selection_mode": sel_profile.mode,
            "analysis_mode": sel_profile.mode,
            "output_format": output_format,
            "summary_depth": summary_length_key,
            "profile_label": sel_profile.label,
            "compression_bias": sel_profile.compression_bias,
            "fallback_used": bool(request.use_llm_synthesis and not synthesis["accepted"]),
            "synthesis_status": synthesis_metadata.get("status", "not_requested"),
            "synthesis_model": synthesis_metadata.get("model", ""),
            "synthesis_verification": synthesis_metadata.get("verification", "not_applicable"),
            "retrieval": retrieval_metadata,
            "pipeline": [
                "source_resolution",
                "paragraph_preparation",
                "document_classification",
                "structural_segmentation",
                "claim_extraction_and_status_classification",
                "summary_planning",
                "article_type_detection",
                "profile_resolution",
                "request_local_retrieval",
                "selection_aware_scoring",
                "profile_coverage_selection",
                *( ["supported_evidence_package", "optional_llm_synthesis", "deterministic_synthesis_checks"] if synthesis["accepted"] else [] ),
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
            "engine_version": "2",
            "summary_schema_version": 2,
            "source_type": source_document.source_type,
            "title": effective_title or title_seed,
            "user_supplied_title": user_title,
            "article_type": profile.article_label,
            "article_type_key": profile.article_type,
            "selection_mode": sel_profile.mode,
            "analysis_mode": sel_profile.mode,
            "output_format": output_format,
            "profile_label": sel_profile.label,
            "engine": "summarizer_core",
            "scoring_strategy": scoring_strategy,
            "original_word_count": original_word_count,
            "cleaned_word_count": original_word_count,
            "paragraph_count": len(paragraphs),
            "candidate_sentence_count": len(candidates),
            "summary_length": summary_length_key,
            "summary_depth": summary_length_key,
            "detail_level": length_config["detail_level"],
            "target_ratio": float(length_config.get("target_ratio", 0.22)),
            "target_word_range": {
                "minimum": min(int(length_config["min_words"]), int(dynamic_max_words)),
                "maximum": int(dynamic_max_words),
            },
            "section_coverage_weight": float(length_config["section_coverage_weight"]),
            "prompt_instruction": length_config["prompt_instruction"],
            "target_sentence_count": target_count,
            "topic_coverage_target": int(length_config.get("max_topics", 6)),
            "topics_detected": len(topic_clusters),
            "topics_covered": covered_topics_count,
            "sections_detected": len(detected_sections),
            "sections_represented": len(covered_sections),
            "covered_sections": covered_sections,
            "coverage_ratio": round(min(
                1.0,
                len(covered_sections) / max(1, len(detected_sections)),
            ), 3),
            "evidence_level": str(length_config.get("evidence_level", "supporting")),
            "fallback_used": bool(request.use_llm_synthesis and not synthesis["accepted"]),
            "retrieval": retrieval_metadata,
            "evidence_count": 0,
            "synthesis": synthesis_metadata,
            "synthesis_status": synthesis_metadata.get("status", "not_requested"),
            "synthesis_model": synthesis_metadata.get("model", ""),
            "synthesis_verification": synthesis_metadata.get("verification", "not_applicable"),
            "document_analysis": {
                key: document_analysis[key]
                for key in (
                    "document_type", "document_type_confidence", "confidence_method",
                    "classification_signals", "detected_structure", "research_stage",
                    "results_section_available", "results_available", "conclusion_available",
                    "missing_information", "claim_count",
                )
            },
            "summary_plan": summary_plan,
        }

        # Run profile-aware validation before returning.
        validation_passed, validation_notes = self._validate_against_profile(
            selected_sentences,
            selected_candidates,
            sel_profile,
            source_document.raw_text,
        )
        if synthesis_note:
            validation_notes.append(synthesis_note)

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
            summary_depth=summary_length_key,
            analysis_mode=sel_profile.mode,
            output_format=output_format,
            profile_label=sel_profile.label,
            active_profile_weights=active_weights,
            validation_passed=validation_passed,
            validation_notes=validation_notes,
            retrieval_metadata=retrieval_metadata,
        )
        validated_result = self._validate_summary_output(
            result,
            summary_normalizer,
            contains_article_noise,
            output_format,
            depth_key=summary_length_key,
            depth_config=length_config,
        )
        guarded_sentences, source_guard = self._guard_summary_sentences(
            validated_result.sentences,
            candidates,
        )
        guarded_overview, _overview_guard = self._guard_summary_sentences(
            validated_result.overview,
            candidates,
        )
        guarded_key_points, _key_point_guard = self._guard_summary_sentences(
            validated_result.key_points,
            candidates,
        )
        guarded_conclusion_parts, _conclusion_guard = self._guard_summary_sentences(
            safe_sent_tokenize(validated_result.conclusion) if validated_result.conclusion else [],
            candidates,
        )
        guarded_conclusion = " ".join(guarded_conclusion_parts)
        guarded_plain_summary, guarded_bullets = self._build_plain_summary(
            guarded_sentences,
            output_format,
        )
        guarded_readability = dict(validated_result.readability)
        guarded_readability["summary_word_count"] = self._count_words(guarded_plain_summary)
        guarded_readability["estimated_reading_time_minutes"] = self._estimate_reading_time(
            guarded_readability["summary_word_count"]
        )
        if guarded_readability.get("original_word_count"):
            guarded_ratio = guarded_readability["summary_word_count"] / max(
                int(guarded_readability["original_word_count"]), 1
            )
            guarded_readability["compression_ratio"] = round(guarded_ratio, 3)
            guarded_readability["compression_percent"] = round(max(0.0, (1 - guarded_ratio) * 100), 1)
        validated_result = replace(
            validated_result,
            sentences=guarded_sentences,
            sentence_count=len(guarded_sentences),
            overview=guarded_overview or guarded_sentences[:1],
            plain_summary=guarded_plain_summary,
            overall_summary_bullets=guarded_bullets,
            key_points=guarded_key_points,
            conclusion=guarded_conclusion,
            readability=guarded_readability,
        )
        evidence: list[dict[str, Any]] = []
        if synthesis["accepted"]:
            try:
                evidence = build_cited_evidence(
                    validated_result.sentences,
                    synthesis["citations"],
                    candidates,
                    retrieval_outcome,
                )
                retrieval_metadata["evidence_mapping_status"] = "available" if evidence else "no_matches"
            except Exception as exc:
                self._logger.exception("Synthesized evidence mapping failed; retaining the validated summary without mapped passages.")
                retrieval_metadata["evidence_mapping_status"] = "unavailable"
                retrieval_metadata["evidence_mapping_error"] = type(exc).__name__
        elif retrieval_outcome is not None:
            try:
                evidence = map_selected_evidence(
                    retrieval_outcome,
                    candidates,
                    validated_result.sentences,
                )
                retrieval_metadata["evidence_mapping_status"] = (
                    "available" if evidence else "no_matches"
                )
            except Exception as exc:
                self._logger.exception(
                    "Evidence mapping failed; returning the summary without evidence."
                )
                retrieval_metadata["evidence_mapping_status"] = "unavailable"
                retrieval_metadata["evidence_mapping_error"] = type(exc).__name__

        # Source sentence provenance is available even when semantic retrieval
        # is disabled or fails; page numbers are included only when extraction
        # retained a reliable page mapping.
        try:
            direct_evidence = build_source_evidence(validated_result.sentences, candidates)
            mapped_indices = {
                int(item["summary_sentence_index"])
                for item in evidence
                if isinstance(item.get("summary_sentence_index"), int)
            }
            evidence.extend(
                item for item in direct_evidence
                if int(item["summary_sentence_index"]) not in mapped_indices
            )
            retrieval_metadata["evidence_mapping_status"] = "available" if evidence else "no_matches"
        except Exception as exc:
            self._logger.exception("Direct source provenance mapping failed.")
            if not evidence:
                retrieval_metadata["evidence_mapping_status"] = "unavailable"
                retrieval_metadata["evidence_mapping_error"] = type(exc).__name__

        updated_source_metadata = dict(validated_result.source_metadata)
        try:
            fact_validation = validate_summary_facts(
                validated_result.sentences,
                build_fact_ledger(candidates),
            )
        except Exception as exc:
            self._logger.exception("Fact-ledger diagnostics failed; returning the summary without diagnostics.")
            fact_validation = {
                "status": "unavailable",
                "sentences_checked": 0,
                "issues": [],
                "error": type(exc).__name__,
                "method": "literal_span_and_marker_checks_v1",
            }
        updated_source_metadata["fact_validation"] = fact_validation
        updated_source_metadata["source_guard"] = source_guard
        from .fact_ledger import calculate_quality_metrics
        quality_metrics = calculate_quality_metrics(
            validated_result.sentences,
            candidates,
            summary_plan=summary_plan,
            document_stage=document_analysis.get("document_stage", "unknown"),
        )
        updated_source_metadata["quality_metrics"] = {
            "coverage_score": quality_metrics.coverage_score,
            "redundancy_score": quality_metrics.redundancy_score,
            "source_support_rate": quality_metrics.source_support_rate,
            "attribution_preservation_rate": quality_metrics.attribution_preservation_rate,
            "numeric_preservation_rate": quality_metrics.numeric_preservation_rate,
            "qualifier_preservation_rate": quality_metrics.qualifier_preservation_rate,
            "section_coverage": quality_metrics.section_coverage,
            "dangling_reference_count": quality_metrics.dangling_reference_count,
            "heading_leak_count": quality_metrics.heading_leak_count,
        }
        updated_source_metadata["document_stage"] = document_analysis.get("document_stage", "unknown")
        updated_source_metadata["document_stage_confidence"] = document_analysis.get("document_stage_confidence", 0.0)
        updated_source_metadata["stage_evidence"] = document_analysis.get("stage_evidence", [])
        updated_source_metadata["retrieval"] = dict(retrieval_metadata)
        updated_source_metadata["evidence_count"] = len(evidence)
        updated_source_metadata["synthesis"] = synthesis_metadata
        updated_summary_method = dict(validated_result.summary_method)
        updated_summary_method["retrieval"] = dict(retrieval_metadata)
        updated_summary_method["synthesis"] = synthesis_metadata
        updated_summary_method["fact_validation"] = {
            "status": fact_validation["status"],
            "method": fact_validation["method"],
        }
        return replace(
            validated_result,
            source_metadata=updated_source_metadata,
            summary_method=updated_summary_method,
            retrieval_metadata=dict(retrieval_metadata),
            evidence=evidence,
        )

    def _order_summary_candidates(
        self,
        candidates: list[SentenceCandidate],
        mode: str,
        document_stage: str,
    ) -> list[SentenceCandidate]:
        """Order candidates for logical discourse flow rather than raw line numbers."""
        if len(candidates) <= 1:
            return list(candidates)

        def slot_rank(cand: SentenceCandidate) -> int:
            if mode == "academic":
                if cand.section in {"abstract", "overview", "introduction"} and cand.epistemic_status in {"BACKGROUND_INFORMATION", "UNKNOWN"}:
                    return 1
                if cand.epistemic_status in {"RESEARCH_OBJECTIVE", "RESEARCH_QUESTION", "HYPOTHESIS"} or cand.section in {"problem", "purpose", "hypotheses"}:
                    return 2
                if cand.epistemic_status == "THEORETICAL_CLAIM" or cand.section in {"framework", "theory"} or any(kw in (cand.subsection or "").lower() for kw in ("respondents", "framework", "variables", "population")):
                    return 3
                if cand.epistemic_status in {"PRIOR_STUDY_RESULT", "LITERATURE_FINDING"} or cand.section in {"literature_review", "related_work"} or cand.source_role == "prior_study":
                    return 4
                if cand.epistemic_status == "PROPOSED_METHOD" or cand.section in {"methodology", "methods"} or cand.claim_type == "method":
                    return 5
                if cand.epistemic_status in {"CURRENT_RESULT", "ACTUAL_RESULT"} or cand.section == "results":
                    return 6
                if cand.section == "discussion":
                    return 7
                if cand.epistemic_status == "LIMITATION" or cand.section in {"limitations", "scope"} or cand.claim_type == "limitation":
                    return 8
                if cand.epistemic_status == "RECOMMENDATION" or cand.section in {"conclusion", "recommendations"}:
                    return 9
                return 5
            elif mode == "executive":
                if cand.section in {"overview", "executive_summary", "introduction"}:
                    return 1
                if cand.claim_type == "decision" or cand.section in {"decisions", "strategy"}:
                    return 2
                if cand.claim_type == "risk" or cand.section in {"risks", "threats"}:
                    return 3
                if cand.section in {"results", "financial_results", "performance"}:
                    return 4
                return 5
            elif mode == "technical":
                if cand.section in {"overview", "abstract", "system_description"}:
                    return 1
                if cand.section in {"architecture", "components", "design"}:
                    return 2
                if cand.section in {"implementation", "methodology"}:
                    return 3
                if cand.section in {"results", "performance", "evaluation"}:
                    return 4
                if cand.section in {"limitations", "configuration", "deployment"}:
                    return 5
                return 6
            elif mode == "news":
                if cand.section in {"lead", "overview", "headline"}:
                    return 1
                if cand.section in {"body", "details"}:
                    return 2
                return 3
            else:
                if cand.section in {"abstract", "overview", "introduction"}:
                    return 1
                if cand.section in {"body", "methodology"}:
                    return 2
                if cand.section in {"results", "discussion"}:
                    return 3
                if cand.section in {"conclusion", "recommendations"}:
                    return 4
                return 5

        ordered = sorted(candidates, key=lambda c: (slot_rank(c), c.index))

        from .constants import DANGLING_ANTECEDENT_PATTERN
        if ordered and DANGLING_ANTECEDENT_PATTERN.search(ordered[0].text):
            lead_idx = next(
                (i for i, c in enumerate(ordered) if slot_rank(c) <= 2 and not DANGLING_ANTECEDENT_PATTERN.search(c.text)),
                None,
            )
            if lead_idx is not None and lead_idx > 0:
                lead_candidate = ordered.pop(lead_idx)
                ordered.insert(0, lead_candidate)

        return ordered

    def _detect_topic_clusters(
        self,
        candidates: list[SentenceCandidate],
        tfidf_matrix: Any,
        tfidf_scores: list[float],
    ) -> list[list[int]]:
        """Group candidate sentences into topic clusters deterministically using TF-IDF cosine similarity.

        Clusters are formed around highest-scoring seeds and ordered by relevance.
        """
        return detect_topic_clusters(candidates, tfidf_matrix, tfidf_scores)

    def _calculate_dynamic_target(
        self,
        source_words: int,
        candidate_count: int,
        detected_topics_count: int,
        detected_sections_count: int,
        depth_key: str,
        depth_config: dict[str, Any],
        profile_compression_bias: str,
    ) -> tuple[int, int]:
        """Dynamically compute target sentence count and maximum word budget.

        Target formula:
        target_length = f(source_length, depth, topic_count, section_count, candidate_count, article_profile)

        Never pad the summary simply to reach a percentage.
        Allow depth modes to converge naturally when source content is scarce.
        """
        if candidate_count <= 0:
            return 0, 0

        # Short document handling (Requirement 16):
        # When content is scarce, do not artificially inflate or force repetition.
        if source_words < 250 or candidate_count <= 6:
            convergence_targets = {
                "brief": min(2, candidate_count),
                "short": min(3, candidate_count),
                "balanced": min(4, candidate_count),
                "detailed": min(5, candidate_count),
                "comprehensive": candidate_count,
            }
            target_sentences = convergence_targets.get(depth_key, min(4, candidate_count))
            max_words = max(
                int(depth_config.get("min_words", 25)),
                target_sentences * int(depth_config.get("max_sentence_length_words", 32)),
                round(source_words * float(depth_config.get("compression_max", 0.28)) * 1.50),
            )
            return target_sentences, max_words

        # Standard / longer documents:
        comp_min = float(depth_config.get("compression_min", 0.18))
        comp_max = float(depth_config.get("compression_max", 0.28))
        target_ratio = (comp_min + comp_max) / 2.0

        # Target words from source length and compression ratio
        target_words = source_words * target_ratio

        # Estimated average candidate sentence length
        avg_candidate_len = max(12, min(36, round(source_words / max(1, candidate_count))))
        estimated_sentences = round(target_words / avg_candidate_len)

        # Structure adjustment: Section coverage requirement
        sec_cov_weight = float(depth_config.get("section_coverage", 0.65))
        min_for_sections = max(1, round(detected_sections_count * sec_cov_weight))
        if depth_key in ("detailed", "comprehensive"):
            estimated_sentences = max(estimated_sentences, min_for_sections)

        # Topic adjustment: Topic coverage requirement
        topic_cov_ratio = float(depth_config.get("topic_coverage_ratio", 0.65))
        min_for_topics = max(1, round(detected_topics_count * topic_cov_ratio))
        estimated_sentences = max(estimated_sentences, min_for_topics)

        # Profile compression bias
        if profile_compression_bias == "tight":
            estimated_sentences = round(estimated_sentences * 0.90)
        elif profile_compression_bias == "generous":
            estimated_sentences = round(estimated_sentences * 1.10)

        # Clamp within depth boundaries
        min_s = int(depth_config.get("min_sentences", 3))
        max_s = int(depth_config.get("max_sentences", 10))
        target_sentences = max(min_s, min(max_s, estimated_sentences))
        target_sentences = min(target_sentences, candidate_count)

        # Dynamic max words budget (generous ceiling to prevent premature cutoff, while respecting depth)
        dynamic_max_words = max(
            int(depth_config.get("min_words", 50)),
            target_sentences * int(depth_config.get("max_sentence_length_words", 36)),
            round(source_words * comp_max * 1.25),
        )
        if profile_compression_bias == "tight":
            dynamic_max_words = round(dynamic_max_words * 0.88)
        elif profile_compression_bias == "generous":
            dynamic_max_words = round(dynamic_max_words * 1.15)

        return target_sentences, dynamic_max_words

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
        depth_key: str = "balanced",
        depth_config: dict[str, Any] | None = None,
    ) -> list[float]:
        """Score candidates using profile-specific weights plus mode-level bonuses/penalties."""
        return calculate_candidate_scores_with_profile(
            candidates=candidates,
            tfidf_scores=tfidf_scores,
            title_scores=title_scores,
            article_type=article_type,
            article_type_family=article_type_family,
            section_importance=section_importance,
            position_score_fn=position_score_fn,
            paragraph_position_score_fn=paragraph_position_score_fn,
            article_type_relevance_fn=article_type_relevance_fn,
            boilerplate_penalty_fn=boilerplate_penalty_fn,
            antecedent_pattern=antecedent_pattern,
            sel_profile=sel_profile,
            depth_key=depth_key,
            depth_config=depth_config,
        )

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
        topic_clusters: list[list[int]] | None = None,
        depth_key: str = "balanced",
        depth_config: dict[str, Any] | None = None,
        topic_coverage_target: int = 3,
    ) -> list[int]:
        """Select candidates with depth-aware topic coverage and section quota controls.

        - Respects depth mode's target topic breadth without bloating repetition.
        - Preserves strict anti-redundancy checks across all depths.
        - Guarantees source chronological ordering upon return.
        """
        return select_candidate_indices_with_profile(
            candidates,
            combined_scores,
            article_type,
            target_count,
            section_coverage_groups,
            section_coverage_weight,
            sel_profile,
            topic_clusters=topic_clusters,
            depth_key=depth_key,
            depth_config=depth_config,
            topic_coverage_target=topic_coverage_target,
        )

    def _ensure_depth_coverage(
        self,
        selected_indices: list[int],
        candidates: list[SentenceCandidate],
        combined_scores: list[float],
        target_count: int,
        *,
        include_limitations: bool,
        include_conclusion: bool,
    ) -> list[int]:
        """Reserve scarce slots for depth-required sections when they exist."""
        return ensure_depth_coverage(
            selected_indices,
            candidates,
            combined_scores,
            target_count,
            include_limitations=include_limitations,
            include_conclusion=include_conclusion,
        )

    # ------------------------------------------------------------------
    # Profile-aware validation
    # ------------------------------------------------------------------

    # Faithfulness verification pattern aliases mapped to scoring.py

    # ------------------------------------------------------------------
    # Profile-aware validation
    # ------------------------------------------------------------------

    # Patterns for faithfulness verification (aliases mapped to scoring.py at class level)

    def _guard_summary_sentences(
        self,
        sentences: list[str],
        candidates: list[SentenceCandidate],
    ) -> tuple[list[str], dict[str, Any]]:
        """Restore source wording or omit output that cannot map to evidence."""
        guarded: list[str] = []
        restored = 0
        rejected = 0
        checked = 0
        issue_counts: Counter[str] = Counter()
        ledger_by_index = {entry.source_sentence_id: entry for entry in build_fact_ledger(candidates)}

        for value in sentences:
            parts = safe_sent_tokenize(value) or [value]
            for sentence in parts:
                sentence = normalize_whitespace(sentence)
                if sentence == "":
                    continue
                checked += 1
                mapped = build_source_evidence([sentence], candidates)
                if not mapped:
                    rejected += 1
                    issue_counts["no_source_match"] += 1
                    continue

                source_index = int(mapped[0]["source_sentence_indices"][0])
                source = next((item for item in candidates if item.index == source_index), None)
                ledger_entry = ledger_by_index.get(source_index)
                if source is None or ledger_entry is None:
                    rejected += 1
                    issue_counts["no_source_match"] += 1
                    continue

                report = validate_summary_facts([sentence], (ledger_entry,))
                issues = report.get("issues", [])
                if issues:
                    for issue in issues:
                        if isinstance(issue, dict) and isinstance(issue.get("issue"), str):
                            issue_counts[issue["issue"]] += 1
                    guarded.append(source.text)
                    restored += 1
                else:
                    guarded.append(sentence)

        return guarded, {
            "status": "checked",
            "method": "source_sentence_match_with_literal_fact_and_claim_status_checks",
            "sentences_checked": checked,
            "sentences_restored_to_source": restored,
            "sentences_omitted_without_source_match": rejected,
            "issues": dict(issue_counts),
            "semantic_entailment_measured": False,
        }

    def _verify_source_faithfulness(
        self,
        selected_sentences: list[str],
        raw_text: str,
        selected_candidates: list[SentenceCandidate] | None = None,
    ) -> tuple[bool, list[str]]:
        """
        Multi-layer faithfulness check:
        1. N-gram containment (existing 6-gram prefix check)
        2. Keyword/Entity overlap: key nouns/verbs from sentence must exist in source
        3. Numeric fact verification: all numbers in output must trace to source
        4. Negation scope verification: negation markers preserved in same scope
        5. Qualifier preservation: hedging language not strengthened
        """
        notes: list[str] = []
        passed = True

        if not selected_sentences:
            return True, []

        raw_lower = raw_text.lower()
        raw_tokens = set(self._content_tokens(raw_text))

        for sentence in selected_sentences:
            sentence = sentence.replace("\ufffd", "")
            sent_lower = sentence.lower()
            sent_tokens = self._content_tokens(sentence)

            # --- Layer 1: 6-gram prefix check (existing) ---
            words = sent_lower.split()
            if len(words) >= 6:
                gram = " ".join(words[:6])
                if gram not in raw_lower:
                    notes.append(f"Faithfulness warning: sentence start not found in source: '{gram}…'")
                    passed = False

            # --- Layer 2: Key content token overlap ---
            if sent_tokens:
                overlap = len(sent_tokens & raw_tokens) / len(sent_tokens)
                if overlap < 0.35:  # Less than 35% of content tokens found in source
                    notes.append(f"Faithfulness warning: low token overlap ({overlap:.0%}) for sentence: '{sentence[:80]}…'")
                    passed = False

            # --- Layer 3: Numeric fact verification ---
            sent_numbers = self._NUMERIC_PATTERN.findall(sentence)
            for num in sent_numbers:
                # Normalize the number for comparison (remove spaces, standardize decimals)
                num_normalized = re.sub(r"\s+", "", num).replace(",", ".")
                # Check if this numeric pattern exists in source
                if num_normalized not in raw_lower.replace(",", ".").replace(" ", ""):
                    # Try fuzzy match for the numeric value
                    num_digits = re.sub(r"[^\d.]", "", num_normalized)
                    if num_digits and num_digits not in re.sub(r"[^\d.]", "", raw_lower):
                        notes.append(f"Faithfulness warning: numeric fact '{num}' not found in source")
                        passed = False

            # --- Layer 4: Negation scope verification ---
            for neg_cue in self._NEGATION_CUES:
                if neg_cue in sent_lower:
                    # Check if negation cue exists in source near the sentence content
                    # Find the position of negation in sentence
                    neg_pos = sent_lower.find(neg_cue)
                    if neg_pos >= 0:
                        # Get context around negation (10 words before/after)
                        sent_words = sent_lower.split()
                        neg_word_idx = len(sent_lower[:neg_pos].split())
                        context_start = max(0, neg_word_idx - 5)
                        context_end = min(len(sent_words), neg_word_idx + 6)
                        context = " ".join(sent_words[context_start:context_end])
                        if context not in raw_lower:
                            notes.append(f"Faithfulness warning: negation scope '{neg_cue}' context not verifiable in source")
                            passed = False

            # --- Layer 5: Qualifier preservation (don't strengthen claims) ---
            source_qualifiers = set(self._QUALIFIER_PATTERN.findall(raw_text))
            sent_qualifiers = set(self._QUALIFIER_PATTERN.findall(sentence))
            # If source has qualifiers but output doesn't, it's a strengthening
            if source_qualifiers and not (sent_qualifiers & source_qualifiers):
                # Check if this sentence makes a strong claim without hedging
                strong_claim_pattern = re.compile(
                    r"\b(?:proves?|confirms?|demonstrates?|establishes?|shows? conclusively|"
                    r"definitely|certainly|absolutely|undeniably|without doubt)\b",
                    re.IGNORECASE,
                )
                if strong_claim_pattern.search(sentence):
                    notes.append(f"Faithfulness warning: source hedging lost, claim strengthened in: '{sentence[:80]}…'")
                    passed = False

        return passed, notes

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

        # --- source_faithfulness (enhanced) ---
        # All selected sentences must have near-verbatim roots in the source.
        if "source_faithfulness" in rules:
            faithful_passed, faithful_notes = self._verify_source_faithfulness(
                selected_sentences,
                raw_text,
                selected_candidates,
            )
            passed = passed and faithful_passed
            notes.extend(faithful_notes)

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

        article_label = article_type_labels.get(best_article_type, "General")
        return DocumentProfile(
            article_type=best_article_type,
            article_label=article_label,
            content_type_key=best_article_type,
            content_type_label=article_label,
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
            paragraph_sentences.append(usable_sentences)
            if not usable_sentences:
                continue

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
        return calculate_candidate_scores(
            candidates=candidates,
            tfidf_scores=tfidf_scores,
            title_scores=title_scores,
            article_type=article_type,
            article_type_family=article_type_family,
            section_importance=section_importance,
            position_score_fn=position_score_fn,
            paragraph_position_score_fn=paragraph_position_score_fn,
            article_type_relevance_fn=article_type_relevance_fn,
            boilerplate_penalty_fn=boilerplate_penalty_fn,
            antecedent_pattern=antecedent_pattern,
        )

    def _select_candidate_indices(
        self,
        candidates: list[SentenceCandidate],
        combined_scores: list[float],
        article_type: str,
        target_count: int,
        section_coverage_groups: dict[str, list[list[str]]],
        section_coverage_weight: float,
    ) -> list[int]:
        """Base candidate selection with section coverage groups and MMR fill."""
        return select_candidate_indices(
            candidates,
            combined_scores,
            article_type,
            target_count,
            section_coverage_groups,
            section_coverage_weight,
        )

    @staticmethod
    def _sentence_token_overlap(left: SentenceCandidate, right: SentenceCandidate) -> float:
        """Return lexical overlap for diversity selection without extra models."""
        return sentence_token_overlap(left, right)

    def _build_overview(
        self,
        selected_sentences: list[str],
        summary_style: str,
        summary_style_simple: str,
        summary_style_academic: str,
        depth_key: str = "balanced",
    ) -> list[str]:
        """Return introductory overview sentences scaled by summary depth.

        brief: 1 sentence
        short: up to 2 sentences
        balanced: 2 sentences
        detailed: up to 3 sentences
        comprehensive: up to 4 sentences
        """
        if not selected_sentences:
            return []
        if depth_key == "brief":
            count = 1
        elif depth_key in ("short", "balanced"):
            count = 2
        elif depth_key == "detailed":
            count = 3
        else:  # comprehensive
            count = 4
        return selected_sentences[: min(count, len(selected_sentences))]

    def _build_conclusion(
        self,
        selected_candidates: list[SentenceCandidate],
        selected_sentences: list[str],
        contains_article_noise: Any,
        depth_key: str = "balanced",
        depth_config: dict[str, Any] | None = None,
    ) -> str:
        """Build a conclusion synthesis whose depth, synthesis breadth, and length scale with summary_depth."""
        cfg = depth_config or {}
        conc_depth = cfg.get("conclusion_depth", "balanced")
        if depth_key == "brief" or conc_depth == "concise":
            max_cand_count = 1
            max_words = 30
        elif depth_key == "short" or conc_depth == "primary":
            max_cand_count = 1
            max_words = 36
        elif depth_key == "balanced" or conc_depth == "balanced":
            max_cand_count = 2
            max_words = 45
        elif depth_key == "detailed" or conc_depth == "detailed":
            max_cand_count = 2
            max_words = 60
        else:  # comprehensive
            max_cand_count = 3
            max_words = 80

        conclusion_candidates: list[str] = []
        for candidate in selected_candidates:
            if candidate.section == "conclusion" or re.search(
                r"\b(?:in conclusion|to conclude|we conclude|the study concludes|it can be concluded)\b",
                candidate.text,
                re.IGNORECASE,
            ):
                if not contains_article_noise(candidate.text) and candidate.text not in conclusion_candidates:
                    conclusion_candidates.append(candidate.text)

        # Synthesize up to max_cand_count distinct sentences
        if len(conclusion_candidates) > 1 and max_cand_count > 1:
            distinct_conclusions: list[str] = []
            for cand in conclusion_candidates:
                if not any(self._sentences_are_redundant(cand, existing) for existing in distinct_conclusions):
                    distinct_conclusions.append(cand)
                if len(distinct_conclusions) >= max_cand_count:
                    break

            per_sentence_words = max(24, max_words // len(distinct_conclusions))
            compressed = [self._compress_sentence(c, max_words=per_sentence_words) for c in distinct_conclusions]
            return " ".join(compressed)

        if conclusion_candidates:
            return self._compress_sentence(conclusion_candidates[0], max_words=max_words)
        return ""

    def _build_key_points(
        self,
        selected_sentences: list[str],
        overview: list[str],
        conclusion: str,
        max_key_points: int = 5,
        contains_article_noise: Any = None,
        min_key_points: int = 2,
    ) -> list[str]:
        """Collect important ideas that are not already in the overview or conclusion.

        Key points should add distinct informational value — they must not
        repeat sentences already presented in the overview or conclusion.
        """
        noise_filter = contains_article_noise if contains_article_noise is not None else (lambda s: False)
        consumed = set(overview)
        if conclusion != "":
            consumed.add(conclusion)
        points: list[str] = []
        seen_sentences: set[int] = set()
        for sentence in selected_sentences:
            if sentence in consumed or noise_filter(sentence):
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

        # If points are fewer than min_key_points because overview consumed sentences,
        # supplement with overview sentences (except conclusion) to provide helpful points
        if len(points) < min_key_points:
            for s in overview:
                if s != conclusion and not noise_filter(s):
                    if not any(self._sentences_are_redundant(s, existing) for existing in points):
                        points.append(s)
                        if len(points) >= min_key_points:
                            break

        return points[:max_key_points]

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

        if summary_style in ("bullets", "bullet_points"):
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

    def _resolve_output_format(self, requested: str, legacy_style: str) -> str:
        """Normalize presentation separately from the analysis profile.

        Legacy style values remain accepted so old clients keep rendering the
        same way while new clients can send an explicit output format.
        """
        allowed = {"paragraph", "bullets", "hybrid", "structured"}
        value = (requested or "").strip().lower()
        if value in allowed:
            return value
        legacy = (legacy_style or "").strip().lower()
        if legacy == "bullet_points":
            return "bullets"
        if legacy == "hybrid" or legacy == "executive_summary":
            return "hybrid"
        return "paragraph"

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

    def _compression_protection_enabled(self, feature: str) -> bool:
        """Production protections remain enabled; offline subclasses may ablate them."""
        return True

    def _compress_sentence(self, sentence: str, max_words: int = 34) -> str:
        """
        Syntax-aware sentence compression preserving:
        - Negation scopes (not, never, no, without, etc.)
        - Numeric facts (percentages, measurements, dates, currency, p-values)
        - Qualifier phrases (may, might, could, possibly, etc.)
        - Subordinate clause boundaries
        """
        cleaned = sentence.strip()
        cleaned = cleaned.replace(" - ", "-")
        cleaned = cleaned.replace(" ,", ",")
        cleaned = re.sub(r"\s+", " ", cleaned)

        words = cleaned.split()
        if len(words) <= max_words:
            if cleaned and cleaned[-1] not in ".!?":
                cleaned += "."
            return cleaned

        # Define protected patterns that should never be cut mid-span
        # These are (start_word_idx, end_word_idx) pairs in the original word list
        protected_spans: list[tuple[int, int]] = []

        # 1. Negation scopes: protect "not/never/no" + following verb phrase (up to 5 words)
        neg_cues = {"not", "never", "no", "neither", "nor", "barely", "hardly", "without", "failed", "fails", "cannot", "unable"}
        for i, word in enumerate(words):
            word_lower = word.lower().rstrip(",.;:")
            if self._compression_protection_enabled("negation_protection") and (word_lower in neg_cues or word_lower in {"didn't", "doesn't", "wasn't", "weren't", "haven't", "hasn't", "hadn't", "isn't", "aren't", "can't", "couldn't", "won't", "wouldn't", "shouldn't"}):
                end = min(len(words), i + 6)  # protect negation + up to 5 following words
                protected_spans.append((i, end))

        # 2. Numeric facts: protect full numeric expressions
        for i, word in enumerate(words):
            # Check if word contains numeric pattern
            if self._compression_protection_enabled("numerical_fact_protection") and self._NUMERIC_PATTERN.search(word):
                # Extend to include preceding currency symbol or following unit
                start = i
                end = i + 1
                # Check preceding word for currency symbol
                if i > 0 and re.match(r"^[$€£¥]$", words[i-1]):
                    start = i - 1
                # Check following words for units
                while end < len(words) and re.match(r"^(?:kg|km|m|cm|mm|MB|GB|KB|TB|ms|s|min|hr|hrs|year|years|month|months|week|weeks|day|days|%|percent)$", words[end].lower().rstrip(",.;:")):
                    end += 1
                protected_spans.append((start, end))

        # 3. Qualifier phrases: protect hedge words + following content (up to 4 words)
        qualifier_starts = {"may", "might", "could", "possibly", "potentially", "likely", "unlikely", "tentatively", "preliminary", "apparently", "presumably", "arguably", "roughly", "approximately"}
        for i, word in enumerate(words):
            word_lower = word.lower().rstrip(",.;:")
            if self._compression_protection_enabled("qualifier_protection") and word_lower in qualifier_starts:
                end = min(len(words), i + 5)
                protected_spans.append((i, end))
            # Check for "appears to", "seems to", "suggests that"
            if self._compression_protection_enabled("qualifier_protection") and i + 1 < len(words):
                bigram = (word_lower, words[i+1].lower().rstrip(",.;:"))
                if bigram in {("appears", "to"), ("seems", "to"), ("suggests", "that"), ("suggest", "that")}:
                    end = min(len(words), i + 6)
                    protected_spans.append((i, end))

        # Merge overlapping protected spans
        protected_spans.sort()
        merged_spans: list[tuple[int, int]] = []
        for span in protected_spans:
            if not merged_spans or span[0] > merged_spans[-1][1]:
                merged_spans.append(span)
            else:
                merged_spans[-1] = (merged_spans[-1][0], max(merged_spans[-1][1], span[1]))

        # Now perform compression respecting protected spans
        # Strategy: iterate from end toward beginning, find cut point that doesn't split protected spans
        cut_words = words[:max_words]

        # If the cut splits a protected span, try to move cut point before the span
        for start, end in merged_spans:
            if start < max_words < end:
                # Cut splits a protected span - move cut before the span
                max_words = start
                cut_words = words[:max_words]
                break

        # Additional clause-boundary aware trimming
        # Don't cut inside subordinate clauses (because, although, while, since, unless, if)
        subordinate_conj = {"because", "although", "though", "while", "since", "unless", "if", "when", "where", "whereas", "whereby"}
        for index in range(len(cut_words) - 1, max(8, max_words - 10), -1):
            word_clean = cut_words[index].rstrip(",.;:").lower()
            if word_clean in subordinate_conj:
                # Found subordinate conjunction - cut before it
                cut_words = cut_words[:index]
                break
            if word_clean in {"and", "or", "but", "that", "which", "who", "whose"}:
                cut_words = cut_words[:index]
                break

        # Never return a sentence fragment ending in a determiner, preposition,
        # auxiliary, or other word that requires the omitted continuation.
        incomplete_endings = {
            "a", "an", "the", "to", "of", "in", "on", "at", "by", "for",
            "from", "with", "as", "and", "or", "but", "that", "which",
            "who", "whose", "is", "are", "was", "were", "has", "have",
            "had", "than",
        }
        if cut_words and (
            cut_words[-1].rstrip(",;:").lower() in incomplete_endings
            or (cleaned.endswith((".", "!", "?")) and not re.search(r"[.!?]$", " ".join(cut_words)))
        ):
            boundary = max(
                (index for index, word in enumerate(cut_words) if word.endswith((",", ";", ":"))),
                default=-1,
            )
            if boundary >= 8:
                cut_words = cut_words[: boundary + 1]
            else:
                return cleaned

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

            # Build multi-sentence paragraph summary (up to 3 sentences)
            paragraph_summary_sentences: list[str] = []
            for candidate_summary in candidate_summaries:
                candidate_summary = self._compress_sentence(normalize_summary_sentence(candidate_summary), max_words=28)
                if candidate_summary == "" or contains_article_noise(candidate_summary) or self._looks_sentence_fragment(candidate_summary):
                    continue
                if any(self._sentences_are_redundant(candidate_summary, overall) for overall in overall_sentences):
                    continue
                if any(self._sentences_are_redundant(candidate_summary, existing) for existing in paragraph_summary_sentences):
                    continue
                paragraph_summary_sentences.append(candidate_summary)
                if len(paragraph_summary_sentences) >= 3:  # Allow up to 3 sentences per paragraph
                    break

            if not paragraph_summary_sentences:
                continue

            summary_text = " ".join(paragraph_summary_sentences)
            main_idea = paragraph_summary_sentences[0]

            # Supporting details from original sentences (not already used)
            used_texts = set(paragraph_summary_sentences)
            supporting_details = [
                sentence for sentence in sentences
                if sentence not in used_texts and not contains_article_noise(sentence)
            ][:2]

            paragraph_keywords = self._extract_keywords(
                [paragraph],
                [main_idea],
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
                    main_idea=main_idea,
                    supporting_details=supporting_details,
                    keywords=paragraph_keywords or keywords[:2],
                    summary=normalize_whitespace(summary_text),
                )
            )

        return analyses

    def _validate_summary_output(
        self,
        result: SummarizationResult,
        normalize_summary_sentence: Any,
        contains_article_noise: Any,
        summary_style: str = "standard_paragraph",
        max_sentence_words: int = 34,
        depth_key: str = "balanced",
        depth_config: dict[str, Any] | None = None,
    ) -> SummarizationResult:
        cfg = depth_config or {}
        eff_max_sentence_words = int(cfg.get("max_sentence_length_words", max_sentence_words))

        clean_sentences = self._compress_selected_sentences(
            result.sentences,
            normalize_summary_sentence,
            contains_article_noise,
            max_words=eff_max_sentence_words,
        )

        if depth_key == "brief":
            overview_limit = 1
            conclusion_max_words = 30
        elif depth_key == "short":
            overview_limit = 2
            conclusion_max_words = 36
        elif depth_key == "balanced":
            overview_limit = 2
            conclusion_max_words = 45
        elif depth_key == "detailed":
            overview_limit = 3
            conclusion_max_words = 60
        else:  # comprehensive
            overview_limit = 4
            conclusion_max_words = 80

        clean_overview = [
            sentence
            for sentence in self._compress_selected_sentences(result.overview, normalize_summary_sentence, contains_article_noise, max_words=eff_max_sentence_words)
            if sentence in clean_sentences or not any(self._sentences_are_redundant(sentence, existing) for existing in clean_sentences)
        ][:overview_limit]
        clean_key_points = [
            sentence
            for sentence in self._compress_selected_sentences(result.key_points, normalize_summary_sentence, contains_article_noise, max_words=eff_max_sentence_words)
            if sentence not in clean_overview
        ]
        deduped_key_points: list[str] = []
        for sentence in clean_key_points:
            if any(self._sentences_are_redundant(sentence, existing) for existing in clean_overview + deduped_key_points):
                continue
            deduped_key_points.append(sentence)
        clean_conclusion = self._compress_sentence(normalize_summary_sentence(result.conclusion), max_words=conclusion_max_words)
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

        kp_range = cfg.get("key_points_range", (3, 5))
        max_kp = int(kp_range[1]) if isinstance(kp_range, (tuple, list)) else 6

        return replace(
            result,
            sentences=clean_sentences,
            sentence_count=len(clean_sentences),
            overview=clean_overview or clean_sentences[:1],
            plain_summary=plain_summary,
            overall_summary_bullets=overall_summary_bullets,
            key_points=deduped_key_points[:max_kp],
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
        return normalize_scores(values)

    def _sentences_are_redundant(self, left: str, right: str, threshold: float = 0.62) -> bool:
        return sentences_are_redundant(left, right, threshold)

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
        return extract_content_tokens(text)

    def _count_words(self, text: str) -> int:
        return len(text.split())

    def _estimate_reading_time(self, word_count: int) -> int:
        if word_count <= 0:
            return 0
        return max(1, int((word_count + 199) / 200))

def summarize_document(
    text: str = "",
    file_path: str = "",
    sentence_count: int = 5,
    preprocessing_options: PreprocessingOptions | None = None,
    summary_style: str = "standard_paragraph",
    summary_length: str = "balanced",
    analysis_mode: str = "",
    output_format: str = "",
) -> SummarizationResult:
    pipeline = SummarizationPipeline()
    request = SummarizationRequest(
        text=text,
        file_path=file_path,
        sentence_count=sentence_count,
        preprocessing_options=preprocessing_options,
        summary_style=summary_style,
        summary_length=summary_length,
        analysis_mode=analysis_mode,
        output_format=output_format,
    )
    return pipeline.summarize(request)


__all__ = [
    "SummarizationPipeline",
    "summarize_document",
]
