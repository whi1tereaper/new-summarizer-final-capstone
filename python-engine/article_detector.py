"""Explainable article screening with a development-set calibrated threshold.

The score is a rule score, not a calibrated probability. Titles, file types and
single keywords cannot independently trigger article routing.
"""
from __future__ import annotations

from dataclasses import asdict, dataclass
from functools import lru_cache
import json
from pathlib import Path
import re

from article_engine.preprocessing import HEADINGS, TOKEN, sentences

DETECTOR_VERSION = "article-rules-2"
CALIBRATION = Path(__file__).parent / "article_engine" / "detection_calibration.json"
CUES = {
    "news": r"\b(announced|reported|officials|said|Monday|Tuesday|Wednesday|Thursday|Friday|yesterday|according to)\b",
    "research": r"\b(study|researchers|participants|sample|regression|survey|findings|cross-sectional)\b",
    "scientific": r"\b(experiment|measured|observations|hypothesis|temperature|species|laboratory|evidence)\b",
    "technical": r"\b(algorithm|protocol|software|architecture|encryption|processor|database|system)\b",
    "business": r"\b(revenue|profit|market|investors|earnings|sales|financial|inflation)\b",
    "opinion": r"\b(I argue|we argue|in my view|should|critics|believe|supporters|opponents)\b",
    "educational": r"\b(means|defined|example|concept|principle|explains|learning|understand)\b",
    "feature": r"\b(community|residents|years|journey|interview|neighborhood|families|history)\b",
}
NON_ARTICLE = re.compile(r"(?im)^\s*(invoice|receipt|curriculum vitae|resume|purchase order|balance sheet|meeting minutes|annual operating review|executive summary|action items|configuration|deployment runbook)\b")
CODE = re.compile(r"(?m)^\s*(?:def \w+\(|class \w+[:(]|import \w+|from \w+ import|<\?php|function \w+\(|\{\s*$|[\w.-]+\s*=\s*[^=])")


@dataclass(frozen=True)
class Detection:
    is_article: bool
    article_type: str
    confidence: float
    detected_features: dict
    classification_reason: str

    def as_dict(self):
        return asdict(self)


@lru_cache(maxsize=1)
def threshold() -> float:
    try:
        data = json.loads(CALIBRATION.read_text(encoding="utf-8"))
        value = data["threshold"]
        if data["detector_version"] == DETECTOR_VERSION and 0 <= value <= 1:
            return float(value)
    except (OSError, ValueError, KeyError, TypeError):
        pass
    return 1.01  # Missing/invalid calibration disables article decisions safely.


def detect_article(text: str, *, decision_threshold: float | None = None) -> Detection:
    if not isinstance(text, str):
        raise TypeError("Article detection requires extracted text.")
    # Cap detector work; very large inputs continue through the original path.
    if len(text) > 500_000:
        return Detection(False, "unknown", 0.0, {"oversized": True}, "outside_evaluated_size")
    records, tokenizer_status = sentences(text)
    words = TOKEN.findall(text)
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    complete = [s for s in records if re.search(r"[.!?][\"')\]]*$", s.text)]
    prose_words = sum(len(TOKEN.findall(s.text)) for s in complete)
    prose_ratio = min(1.0, prose_words / max(1, len(words)))
    patterns = {key: set(m.group().casefold() for m in re.finditer(pattern, text, re.I))
                for key, pattern in CUES.items()}
    category = max(patterns, key=lambda key: len(patterns[key]))
    cue_count = len(patterns[category])
    headings = sum(bool(HEADINGS.fullmatch(line)) for line in lines)
    title = bool(lines and not re.search(r"[.!?]$", lines[0]) and 3 <= len(TOKEN.findall(lines[0])) <= 18)
    paragraph_count = len({s.paragraph for s in complete})
    report_sections = sum(bool(re.fullmatch(r"current supply|demand forecast|options evaluated|strategic decisions|sales by department", line, re.I)) for line in lines)
    internal_report = report_sections >= 2 and bool(re.search(r"\b(board|management|authority)\b", text, re.I))
    negative = bool(NON_ARTICLE.search(text)) or internal_report
    code_ratio = len(CODE.findall(text)) / max(1, len(lines))
    list_ratio = sum(bool(re.match(r"^(?:[-*•]|\d+[.)])\s", line)) for line in lines) / max(1, len(lines))
    # Independent structural, linguistic and content signals; no title-only gate.
    score = (0.26 * min(1, len(complete) / 8) + 0.20 * prose_ratio
             + 0.16 * min(1, paragraph_count / 4) + 0.08 * title
             + 0.20 * min(1, cue_count / 4) + 0.10 * min(1, headings / 3))
    score -= 0.6 * negative + 0.8 * code_ratio + 0.3 * (list_ratio > 0.6)
    score = round(max(0.0, min(1.0, score)), 6)
    qualified = (len(complete) >= 5 and len(words) >= 100 and prose_ratio >= 0.65
                 and (paragraph_count >= 2 or (len(complete) >= 8 and cue_count >= 4)) and cue_count >= 2
                 and not negative and code_ratio < 0.15 and list_ratio < 0.65)
    cutoff = threshold() if decision_threshold is None else decision_threshold
    confirmed = qualified and score >= cutoff
    features = {"complete_sentences": len(complete), "words": len(words),
                "prose_ratio": round(prose_ratio, 4), "paragraphs": paragraph_count,
                "headings": headings, "title": title, "category_cues": cue_count,
                "negative_document_marker": negative, "code_ratio": round(code_ratio, 4),
                "list_ratio": round(list_ratio, 4), "eligible": qualified,
                "threshold": cutoff, "tokenizer": tokenizer_status,
                "detector_version": DETECTOR_VERSION}
    return Detection(confirmed, category if cue_count >= 2 else "unknown", score, features,
                     "article_structure_and_language" if confirmed else
                     "non_article_structure" if negative or code_ratio >= 0.15 else "uncertain")
