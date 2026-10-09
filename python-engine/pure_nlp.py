"""Compatibility adapter for the historical pure-NLP summary payload.

All summarization work is delegated to the authoritative summarizer_core
pipeline. This module keeps the old helper import and response keys available
for in-repository integrations without maintaining a second ranking engine.
"""

from __future__ import annotations

import re
from typing import Any

from summarizer_core.models import SummarizationRequest
from summarizer_core.pipeline import SummarizationPipeline

_NUMBER = re.compile(r"(?<!\w)[+-]?\d[\d,]*(?:\.\d+)?\s*(?:%|percent|p\s*[<>=]\s*0?\.\d+)?", re.I)
_RECOMMENDATION = re.compile(r"\b(?:recommend(?:s|ed|ation)?|should consider|should use|advises?)\b", re.I)
_FINDING = re.compile(r"\b(?:findings?|results?|indicated?|showed?|revealed?|reported?|demonstrated?|observed?|suggested?)\b", re.I)
_WORD = re.compile(r"[\w'-]+", re.UNICODE)


def summarize_to_contract(text: str, title: str = "") -> dict[str, Any]:
    """Return the legacy key shape using source-grounded pipeline output."""
    raw = str(text or "").strip()
    result = SummarizationPipeline().summarize(SummarizationRequest(
        text=raw,
        document_title=str(title or ""),
        analysis_mode="academic",
        summary_depth="comprehensive",
        output_format="structured",
    ))

    paragraphs = []
    for analysis in result.paragraph_summaries:
        content = analysis.summary.strip()
        if not content:
            continue
        content = content if content.endswith((".", "!", "?")) else content + "."
        headline_words = _WORD.findall(content)
        headline = " ".join(headline_words[:10]).strip()
        if len(headline_words) > 10:
            headline += "..."
        paragraphs.append({
            "paragraph_id": len(paragraphs) + 1,
            "tag": analysis.purpose or analysis.section or "Source-supported content",
            "headline": headline[:1].upper() + headline[1:] if headline else "",
            "content": content,
        })

    if not paragraphs and result.plain_summary:
        paragraphs.append({
            "paragraph_id": 1,
            "tag": "Source-supported summary",
            "headline": "Document Summary",
            "content": result.plain_summary,
        })

    findings: list[dict[str, str]] = []
    seen_contexts: set[str] = set()
    recommendations: list[str] = []
    for evidence in result.evidence:
        context = str(evidence.get("source_sentence", "")).strip()
        if not context or context.casefold() in seen_contexts:
            continue
        seen_contexts.add(context.casefold())
        numbers = list(dict.fromkeys(match.group(0).strip() for match in _NUMBER.finditer(context)))
        status = str(evidence.get("claim_status", ""))
        if numbers:
            findings.append({"label": "Metric", "value": ", ".join(numbers), "context": context})
        elif status == "ACTUAL_RESULT" or _FINDING.search(context):
            findings.append({"label": "Finding", "value": "Source-reported result", "context": context})
        elif status == "RECOMMENDATION" or _RECOMMENDATION.search(context):
            findings.append({"label": "Implication", "value": "Recommendation", "context": context})
            recommendations.append(context)
        if len(findings) >= 8:
            break

    source_words = max(1, len(_WORD.findall(raw)))
    summary_words = max(1, len(_WORD.findall(" ".join(row["content"] for row in paragraphs))))
    reduction = max(0.0, (1 - summary_words / source_words) * 100)
    document_analysis = result.source_metadata.get("document_analysis", {})
    document_type = str(document_analysis.get("document_type", "unknown"))

    return {
        "summary_meta": {
            "title": result.title,
            "doc_type": document_type,
            "reading_time_reduction": f"{reduction:.1f}%",
            "engine_version": "2",
        },
        "executive_overview": " ".join(result.overview) or result.plain_summary,
        "thematic_paragraphs": paragraphs,
        "key_findings": findings,
        "actionables_or_recommendations": recommendations[:6],
    }


__all__ = ["summarize_to_contract"]
