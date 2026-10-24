"""Article-specific extractive pipeline returning LIGHT's existing contract."""
from __future__ import annotations

from collections import OrderedDict
import math
import os
import re
from time import perf_counter
from . import ENGINE_VERSION
from .preprocessing import TOKEN
from .coverage import analyze, article_sentences, QUALIFICATION as QUALIFIER
from .selection import select
from .validation import validate_selected
from failure_contract import ProcessingFailure


def summarize(request: dict, source_document, detection, *, variant="hybrid", config=None,
              enhanced=None, ablation=None, core_config=None) -> dict:
    started = perf_counter()
    text = source_document.raw_text
    records, tokenizer = article_sentences(text)
    if not records or len(records) > 1000:
        raise ProcessingFailure("NLP_NO_VALID_CANDIDATES")
    mode = request.get("analysis_mode") or request.get("selection_mode") or "general"
    from .ranking import MODES
    if mode not in MODES:
        raise ProcessingFailure("SETTINGS_INVALID")
    depth = request.get("summary_depth") or request.get("summary_length") or "balanced"
    # Existing depths, adapted for source-exact article sentences. Evaluation may
    # supply a matched budget; it remains a ceiling rather than a fill target.
    depths = {"very_brief": (.10, 2, 80), "brief": (.18, 4, 150),
              "short": (.26, 6, 280), "balanced": (.38, 10, 500),
              "detailed": (.55, 18, 850), "comprehensive": (.75, 32, 1600)}
    if depth not in depths:
        raise ProcessingFailure("SETTINGS_INVALID")
    formats = {"standard_paragraph": "paragraph", "bullet_points": "bullets", "hybrid": "hybrid"}
    output_format = request.get("output_format") or formats.get(request.get("summary_style"), "paragraph")
    if output_format not in {"paragraph", "bullets", "hybrid", "structured", "key_takeaways"}:
        raise ProcessingFailure("SETTINGS_INVALID")
    if request.get("use_llm_synthesis"):
        raise ProcessingFailure("SETTINGS_INVALID")
    ratio, limit, maximum = depths[depth]
    source_words = len(TOKEN.findall(text))
    count = min(limit, max(1, int(request.get("sentence_count", 8))),
                len(records))
    budget = min(maximum, max(45, round(source_words * ratio)))
    target = request.get("target_word_budget", 0)
    if isinstance(target, int) and not isinstance(target, bool) and target > 0:
        budget = min(maximum, target)
    ablation = ablation or {}
    if enhanced is None:
        enhanced = os.environ.get('LIGHT_ARTICLE_NLP_ENHANCED', 'true').lower() not in {'0','false','no'}
    analysis = analyze(records, enhanced=enhanced, config=config,
                       use_tfidf=not ablation.get("tfidf"), use_lsa=not ablation.get("lsa"),
                       structural=not ablation.get("structure"))
    focus = str(request.get("focus_query") or "").strip()
    first_source_line = next((line.strip() for line in text.splitlines() if line.strip()), '')
    source_title = source_document.title_hint or (first_source_line if len(first_source_line) <= 180
                                                 and not re.search(r'[.!?]$', first_source_line) else '')
    chosen, focus_status, selection = select(records, analysis, count, budget, mode, focus,
                                            variant=variant, config=config,
                                            guard=not ablation.get("factual_context"), title=source_title,
                                            core_config=core_config)
    selected = [records[i] for i in chosen]
    if not selected and focus_status != "unsupported":
        raise ProcessingFailure("NLP_NO_VALID_CANDIDATES")
    validation = validate_selected(selected, records, text, guard=not ablation.get("factual_context"))
    values = [s.text for s in selected]
    paragraph = " ".join(values)
    if focus_status == "unsupported":
        paragraph = "The article does not contain enough information about the selected focus."
    bullets = values if output_format in {"bullets", "hybrid", "key_takeaways"} else []
    plain = "\n".join("• " + v for v in values) if bullets and output_format != "hybrid" else paragraph
    # Hybrid uses an overview plus the remaining evidence, rather than repeating
    # every selected sentence twice in the plain/exported representation.
    if output_format == "hybrid" and values:
        plain = values[0] + ("\n\n" + "\n".join("• " + v for v in values[1:]) if len(values) > 1 else "")
    groups = OrderedDict()
    for s in selected:
        groups.setdefault(s.section, []).append(s.text)
    first = next((line.strip() for line in text.splitlines() if line.strip()), "Generated Summary")
    title = request.get("document_title") or source_document.title_hint or (first if len(first) <= 180 else "Article Summary")
    evidence = [{"source_sentence_id": f"article-{s.index:06d}", "source_sentence_indices": [s.index],
                 "summary_sentence_index": i, "source_sentence": s.text, "excerpt": s.text,
                 "supports": s.text, "section": s.section, "paragraph_index": s.paragraph,
                 "start": s.start, "end": s.end} for i, s in enumerate(selected)]
    summary_words = sum(len(TOKEN.findall(s)) for s in values)
    metadata = {"engine": "recipe_article", "engine_version": ENGINE_VERSION,
                "source_type": source_document.source_type, "summary_depth": depth,
                "original_word_count": source_words, "cleaned_word_count": source_words,
                "candidate_sentence_count": len(records), "target_sentence_count": len(selected),
                "source_guard": validation,
                "fact_validation": {"status": "exact_extractive_provenance", "method": "source_span",
                                    "sentences_checked": len(selected), "issues": [],
                                    "interpretation": "Exact excerpts establish provenance, not factual completeness."},
                "article_analysis": {"topic_keywords": analysis[4], "noun_phrases": analysis[5],
                                     "annotation_status": analysis[7], "tokenizer": tokenizer,
                                     "entities": [{"source_sentence_id": f"article-{i:06d}", "values": e}
                                                  for i, e in enumerate(analysis[6]) if e],
                                     "qualifier_sentence_ids": [f"article-{s.index:06d}" for s in records if QUALIFIER.search(s.text)],
                                     "focus_status": focus_status, "word_budget": budget,
                                     "coverage_selection": selection,
                                     "timings": {**analysis.timings, **{k: v for k, v in selection.items() if k.endswith("seconds")},
                                                 "total_seconds": perf_counter() - started}},
                "synthesis": {"requested": False, "enabled": False, "status": "extractive_only"}}
    if request.get("performance_profile") is not True:
        metadata["article_analysis"].pop("timings", None)
        for key in ["selection_seconds", "redundancy_seconds"]:
            selection.pop(key, None)
    return {"title": title, "raw_text": text, "cleaned_text": text, "sentences": values,
            "sentence_count": len(values), "overview": values[:1], "plain_summary": plain,
            "overall_summary_bullets": bullets, "preprocessing": request.get("preprocessing", {}),
            "readability": {"original_word_count": source_words, "summary_word_count": summary_words,
                            "compression_percent": round(100 * max(0, 1 - summary_words / max(1, source_words)), 1),
                            "estimated_reading_time_minutes": max(1, math.ceil(summary_words / 200))},
            "summary_method": {"name": "coverage_aware_hybrid_" + variant, "fallback_used": False},
            "keywords": analysis[4], "important_terms": [], "article_type": detection.article_type,
            "key_points": values[:6], "conclusion": "", "paragraph_summaries": [], "excluded_sections": [],
            "structured_summary": [{"label": k, "text": " ".join(v)} for k, v in groups.items()] if output_format == "structured" else [],
            "source_metadata": metadata, "method": "recipe_article", "fallback_used": False,
            "selection_mode": mode, "analysis_mode": mode, "profile_label": mode.title() + " Summary",
            "active_profile_weights": {}, "validation_passed": True,
            "validation_notes": ["Source-exact excerpts; completeness requires review."],
            "summary_depth": depth, "output_format": output_format, "retrieval_metadata": {},
            "evidence": evidence, "focus_query": focus}
