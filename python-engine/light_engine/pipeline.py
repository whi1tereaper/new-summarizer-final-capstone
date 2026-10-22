"""Independent, extractive summarization pipeline and legacy-compatible output."""

from __future__ import annotations

from collections import OrderedDict
import re
from typing import Any

from .content_type import analyze_content_type
from .contract import CONTRACT_VERSION, SummaryRequest
from .extraction import ExtractedDocument, SourceSentence, load_document, split_sentences
from .selection import select_sentences
from .validation import validate_candidates

_LABELS = {
    "general": "General Summary",
    "academic": "Academic Summary",
    "scientific": "Scientific Summary",
    "executive": "Executive Summary",
    "study": "Study Summary",
    "technical": "Technical Summary",
    "news": "News Summary",
    "legal": "Legal Summary",
    "general_article": "General Article Summary",
    "general_document": "General Document Summary",
    "business_report": "Business Report Summary",
    "opinion": "Opinion Summary",
    "educational": "Educational Summary",
}


def _word_count(text: str) -> int:
    return len(re.findall(r"\b[\w'-]+\b", text, re.UNICODE))


def _build_plain_summary(sentences: list[SourceSentence], output_format: str) -> tuple[str, list[str]]:
    texts = [sentence.text for sentence in sentences]
    bullets = list(texts) if output_format in {"bullets", "hybrid", "key_takeaways"} else []
    paragraph = " ".join(texts)
    if output_format in {"bullets", "key_takeaways"}:
        return "\n".join(f"• {text}" for text in texts), bullets
    if output_format == "hybrid":
        return paragraph + "\n\n" + "\n".join(f"• {text}" for text in texts), bullets
    return paragraph, bullets


def _group_sections(sentences: list[SourceSentence]) -> list[dict[str, str]]:
    groups: OrderedDict[str, list[str]] = OrderedDict()
    for sentence in sentences:
        groups.setdefault(sentence.section or "Document", []).append(sentence.text)
    return [
        {"label": label, "text": " ".join(texts)}
        for label, texts in groups.items()
        if texts
    ]


def _evidence(sentences: list[SourceSentence]) -> list[dict[str, Any]]:
    return [
        {
            "section": sentence.section or "Document",
            "excerpt": sentence.text,
            "supports": sentence.text,
            "source_sentence": sentence.text,
            "source_sentence_id": f"source-{sentence.index:06d}",
            "source_sentence_indices": [sentence.index],
            "summary_sentence_index": output_index,
            "paragraph_index": sentence.paragraph_index,
            **({"page": sentence.page} if sentence.page is not None else {}),
        }
        for output_index, sentence in enumerate(sentences)
    ]


def _extract_title(document: ExtractedDocument, request: SummaryRequest) -> str:
    if request.document_title:
        return request.document_title[:255]
    return document.title or "Generated Summary"


def summarize(payload: Any) -> dict[str, Any]:
    request = SummaryRequest.parse(payload)
    document = load_document(request.text, request.file_path)
    candidates = split_sentences(document.text)
    if not candidates:
        from .contract import ContractError

        raise ContractError("no_sentences", "No complete source sentences could be extracted.")

    source_words = _word_count(document.text)
    content_type = analyze_content_type(document.text, candidates, document.title)
    selected, selection_metadata, keywords = select_sentences(
        candidates,
        request,
        source_words,
        content_type,
    )
    if not selected:
        from .contract import ContractError

        raise ContractError("no_summary", "No supported summary sentences could be selected.")
    source_guard, grammar_diagnostics = validate_candidates(selected, document.text)

    plain_summary, bullets = _build_plain_summary(selected, request.output_format)
    summary_words = _word_count(plain_summary)
    sections = list(dict.fromkeys(sentence.section for sentence in selected))
    readable_minutes = max(1, round(summary_words / 200))
    source_minutes = max(1, round(source_words / 200))
    fact_note = "All summary sentences are verbatim source excerpts; no paraphrase was generated."
    metadata: dict[str, Any] = {
        "engine_version": "2.0.0",
        "engine": "light_nlp",
        "summary_schema_version": CONTRACT_VERSION,
        "source_type": document.source_type,
        "analysis_mode": request.analysis_mode,
        "summary_depth": request.summary_depth,
        "output_format": request.output_format,
        "original_word_count": source_words,
        "cleaned_word_count": source_words,
        "candidate_sentence_count": len(candidates),
        "target_sentence_count": len(selected),
        "sections_detected": len(set(sentence.section for sentence in candidates)),
        "sections_represented": len(sections),
        "covered_sections": sections,
        "fact_validation": {
            "status": "exact_extractive_provenance",
            "method": "source_substring_validation",
            "sentences_checked": len(selected),
            "interpretation": fact_note,
            "issues": [],
        },
        "extraction_warnings": list(document.warnings),
        "semantic_model_status": selection_metadata["semantic_model_status"],
        "source_guard": source_guard,
        "grammar_diagnostics": grammar_diagnostics,
        "document_analysis": {
            **content_type.as_dict(request.analysis_mode),
            "document_type": content_type.category,
            "document_type_confidence": content_type.confidence,
            "confidence_method": content_type.method,
            "classification_signals": list(content_type.cues),
            "detected_structure": [
                {
                    "heading": section,
                    "role": "section",
                    "source_sentence_ids": [],
                    "paragraph_ids": [],
                }
                for section in content_type.section_counts
            ],
        },
        "synthesis": {
            "requested": request.use_llm_synthesis,
            "enabled": False,
            "status": "disabled_local_extractive_engine",
        },
        "quality_selection": selection_metadata,
    }

    if request.output_format == "structured":
        structured = _group_sections(selected)
    else:
        structured = []

    return {
        "schema_version": CONTRACT_VERSION,
        "title": _extract_title(document, request),
        "raw_text": document.text,
        "cleaned_text": document.text,
        "sentences": [sentence.text for sentence in selected],
        "sentence_count": len(selected),
        "overview": [selected[0].text],
        "plain_summary": plain_summary,
        "overall_summary_bullets": bullets,
        "preprocessing": request.preprocessing,
        "readability": {
            "original_word_count": source_words,
            "summary_word_count": summary_words,
            "compression_percent": round(max(0.0, (1 - summary_words / max(1, source_words)) * 100), 1),
            "estimated_reading_time_minutes": readable_minutes,
            "estimated_source_reading_time_minutes": source_minutes,
        },
        "summary_method": {
            "name": "adaptive_extractive_tfidf_mmr",
            "pipeline": [
                "local_extraction",
                "content_type_analysis",
                "sentence_segmentation",
                "tfidf_ranking",
                "profile_weighted_mmr_selection",
                "exact_source_validation",
            ],
            "scoring_strategy": selection_metadata["strategy"],
            "fallback_used": False,
        },
        "keywords": keywords,
        "important_terms": [],
        "article_type": _LABELS.get(content_type.category, _LABELS[request.analysis_mode]),
        "key_points": [sentence.text for sentence in selected[:5]],
        "conclusion": "",
        "structured_summary": structured,
        "paragraph_summaries": [],
        "excluded_sections": [],
        "source_metadata": metadata,
        "method": "light_nlp_v2",
        "fallback_used": False,
        "selection_mode": request.analysis_mode,
        "profile_label": _LABELS[request.analysis_mode],
        "active_profile_weights": {},
        "validation_passed": True,
        "validation_notes": [fact_note],
        "summary_depth": request.summary_depth,
        "analysis_mode": request.analysis_mode,
        "output_format": request.output_format,
        "retrieval_metadata": {},
        "evidence": _evidence(selected),
        "focus_query": request.focus_query,
        "multi_document_metadata": {"detected": False, "document_count": 1},
    }
