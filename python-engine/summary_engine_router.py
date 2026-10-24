"""Request-local automatic routing, extraction reuse and one safe fallback."""
from __future__ import annotations

import logging
import os

LOGGER = logging.getLogger("light.article_router")


def enabled(request):
    value = request.get("automatic_article_routing")
    return value if isinstance(value, bool) else os.environ.get("SUMMARIZER_AUTO_ARTICLE_ROUTING", "false").lower() in {"1", "true", "yes"}


def route_summary(request, original_engine):
    if not enabled(request):
        return original_engine(request, None)
    # Extraction errors must retain their original typed failure; they are not
    # engine errors and cannot be fixed by retrying summarization.
    from summarizer_core.extraction import load_source_document
    source = load_source_document(text=request.get("text", ""), file_path=request.get("file_path", ""))
    detection = None
    reason = "uncertain"
    failure = ""
    selected = "legacy"
    try:
        from article_detector import detect_article
        detection = detect_article(source.raw_text)
        if request.get("use_llm_synthesis"):
            reason = "existing_synthesis_request"
        elif detection.is_article:
            from article_engine.summarizer import summarize
            result = summarize(request, source, detection)
            if (not isinstance(result, dict) or not isinstance(result.get("sentences"), list)
                    or not isinstance(result.get("plain_summary"), str)
                    or not result["plain_summary"].strip()
                    or not isinstance(result.get("source_metadata"), dict)):
                raise TypeError("Article engine returned an invalid contract.")
            selected, reason = "recipe_article", "confirmed_article"
        else:
            reason = detection.classification_reason
    except Exception as exc:
        # No source, path, exception message, or user query in failure telemetry.
        failure = type(exc).__name__
        reason = "article_processing_failed"
        LOGGER.warning("article_route_fallback exception_type=%s", failure)
    if selected != "recipe_article":
        result = original_engine(request, source)
    metadata = result.setdefault("source_metadata", {})
    metadata["engine_routing"] = {"enabled": True, "selected_engine": selected,
                                  "reason": reason, "fallback_used": bool(failure),
                                  "failure_type": failure,
                                  "detection": detection.as_dict() if detection else {}}
    metadata.setdefault("engine", selected)
    if failure:
        result["fallback_used"] = True
        metadata["fallback_used"] = True
    return result
