"""Local, extractive academic summarization with a stable JSON contract.

This module deliberately contains no network or generative-model integration.
spaCy is used when the small English model is installed; deterministic regex
sentence splitting remains available for minimal local installations.
"""

from __future__ import annotations

from dataclasses import dataclass
import re
from typing import Any

import numpy as np
from sklearn.cluster import AgglomerativeClustering
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics.pairwise import cosine_similarity

try:
    import networkx as nx
except ImportError:  # pragma: no cover - exercised only before dependency setup
    nx = None

try:
    import spacy
except ImportError:  # pragma: no cover - exercised only before dependency setup
    spacy = None


@dataclass(frozen=True)
class _Sentence:
    text: str
    start_idx: int
    vector_index: int


_SECTION_TAGS = (
    ("BACKGROUND & OBJECTIVE", re.compile(r"\b(?:background|introduction|objective|purpose|problem)\b", re.I)),
    ("METHODOLOGY & FINDINGS", re.compile(r"\b(?:method|methodology|sample|survey|results?|findings?|analysis)\b", re.I)),
    ("IMPLICATIONS & CONCLUSION", re.compile(r"\b(?:conclusion|implication|recommend|future work|therefore)\b", re.I)),
)
_HEADING_RE = re.compile(r"^\s*(?:[IVX]+|\d+(?:\.\d+)*)?[\s.)-]*(?:abstract|introduction|background|methodology|methods?|results?|discussion|conclusion|references?|acknowledg(?:e)?ments?)\s*:?\s*$", re.I)
_CITATION_RE = re.compile(r"\[\s*\d+(?:\s*[-,]\s*\d+)*\s*\]|\([A-Z][A-Za-z-]+(?:\s+et al\.)?,\s*\d{4}[a-z]?\)")
_NOISE_RE = re.compile(
    r"(?:\b(?:issn|isbn|doi|orcid)\b|https?://|www\.|all rights reserved|"
    r"\bpage\s+\d+\b|\bfigure\s+\d+\b|\btable\s+\d+\b|^\s*\d+\s*$)",
    re.I,
)
_STAT_RE = re.compile(
    r"\b(?:N|n)\s*=\s*\d+\b|\bWM\s*=\s*\d+(?:\.\d+)?\b|"
    r"\b\d+(?:\.\d+)?\s*%|\b\d+(?:\.\d+)?\s+(?:participants|respondents|students|subjects|years?|months?)\b",
    re.I,
)
_WORD_RE = re.compile(r"[A-Za-z][A-Za-z'-]*")
_SECTION_STOP_RE = re.compile(
    r"^\s*(?:acknowledg(?:e)?ments?|references?|bibliography|appendix)\b",
    re.I,
)
_BROKEN_TAIL_RE = re.compile(r"\(\s*(?:wm\s*:\s*\d*\.?|e\.)\s*$", re.I)
_ORPHAN_ROW_RE = re.compile(r"^\s*\d+\s+(?:agree|disagree|neutral|yes|no)\b", re.I)


def _nlp() -> Any | None:
    if spacy is None:
        return None
    try:
        return spacy.load("en_core_web_sm")
    except (OSError, IOError):
        try:
            pipeline = spacy.blank("en")
            pipeline.add_pipe("sentencizer")
            return pipeline
        except (ValueError, OSError):
            return None


def _clean(text: str) -> str:
    value = text.replace("\r\n", "\n").replace("\r", "\n")
    value = re.sub(r"\S+@\S+", " ", value)
    value = _CITATION_RE.sub(" ", value)
    lines: list[str] = []
    for raw_line in value.splitlines():
        line = re.sub(r"\s+", " ", raw_line).strip()
        if not line:
            continue
        if _SECTION_STOP_RE.match(line):
            break
        if _HEADING_RE.fullmatch(line) or _NOISE_RE.search(line) or _ORPHAN_ROW_RE.match(line):
            continue
        if len(_WORD_RE.findall(line)) < 6:
            continue
        lines.append(line)
    cleaned = " ".join(lines)
    return re.sub(r"\s+", " ", cleaned).strip()


def _extract_title(raw_text: str, explicit_title: str = "") -> str:
    if explicit_title.strip() and explicit_title.strip().lower() != "academic document":
        return explicit_title.strip()

    candidates: list[str] = []
    for raw_line in raw_text.replace("\r\n", "\n").replace("\r", "\n").splitlines()[:15]:
        line = re.sub(r"\s+", " ", raw_line).strip()
        if not line:
            continue
        if re.search(r"\b(?:abstract|issn)\b", line, re.I):
            break
        if _NOISE_RE.search(line) or re.fullmatch(r"(?:IJSRED|volume\s+\d+.*|\d+)", line, re.I):
            continue
        if _HEADING_RE.fullmatch(line) or len(_WORD_RE.findall(line)) < 5:
            continue
        words = line.split()
        letters = [char for char in line if char.isalpha()]
        capitalized_words = sum(1 for word in words if word[:1].isupper())
        title_case = (
            sum(char.isupper() for char in letters) >= max(1, len(letters) * 0.45)
            or capitalized_words >= max(2, len(words) * 0.55)
        )
        if title_case:
            candidates.append(line.strip(" -*"))

    if not candidates:
        return "Academic Document"
    return max(candidates, key=lambda value: (len(_WORD_RE.findall(value)), -candidates.index(value)))


def _sentences(cleaned: str) -> list[_Sentence]:
    pipeline = _nlp()
    if pipeline is not None:
        doc = pipeline(cleaned)
        values = [(sentence.text.strip(), sentence.start_char) for sentence in doc.sents]
        if len(values) <= 1 and len(re.findall(r"[.!?]", cleaned)) > 1:
            values = [(match.group(0).strip(), match.start()) for match in re.finditer(r"[^.!?]+[.!?]", cleaned)]
            tail = cleaned[values[-1][1] + len(values[-1][0]):].strip() if values else cleaned.strip()
            if tail:
                values.append((tail, cleaned.rfind(tail)))
    else:
        values = [(match.group(0).strip(), match.start()) for match in re.finditer(r"[^.!?]+[.!?]", cleaned)]
        tail = cleaned[values[-1][1] + len(values[-1][0]):].strip() if values else cleaned.strip()
        if tail:
            values.append((tail, cleaned.rfind(tail)))
    result: list[_Sentence] = []
    for text, start in values:
        normalized = re.sub(r"\s+", " ", text).strip()
        if (
            len(_WORD_RE.findall(normalized)) >= 6
            and not _NOISE_RE.search(normalized)
            and not _BROKEN_TAIL_RE.search(normalized)
            and not _ORPHAN_ROW_RE.match(normalized)
        ):
            if normalized[-1] not in ".!?":
                normalized += "."
            result.append(_Sentence(normalized, start, len(result)))
    return result


def _pagerank(similarity: np.ndarray) -> np.ndarray:
    if len(similarity) == 1:
        return np.ones(1)
    matrix = np.maximum(similarity, 0.0).copy()
    np.fill_diagonal(matrix, 0.0)
    if nx is not None:
        graph = nx.from_numpy_array(matrix)
        scores = nx.pagerank(graph, weight="weight", max_iter=200)
        return np.array([scores[index] for index in range(len(matrix))])
    row_totals = matrix.sum(axis=1)
    transition = np.divide(matrix, row_totals[:, None], out=np.zeros_like(matrix), where=row_totals[:, None] != 0)
    scores = np.full(len(matrix), 1 / len(matrix))
    for _ in range(100):
        scores = 0.15 / len(matrix) + 0.85 * transition.T.dot(scores)
    return scores


def _mmr(vectors: np.ndarray, relevance: np.ndarray, limit: int, lambda_value: float = 0.6) -> list[int]:
    chosen: list[int] = []
    remaining = set(range(len(relevance)))
    similarities = cosine_similarity(vectors)
    while remaining and len(chosen) < limit:
        if not chosen:
            selected = max(remaining, key=lambda index: float(relevance[index]))
        else:
            selected = max(
                remaining,
                key=lambda index: lambda_value * float(relevance[index])
                - (1 - lambda_value) * max(float(similarities[index, old]) for old in chosen),
            )
        chosen.append(selected)
        remaining.remove(selected)
    return chosen


def _cluster_labels(vectors: np.ndarray, count: int) -> np.ndarray:
    if len(vectors) <= 1 or count <= 1:
        return np.zeros(len(vectors), dtype=int)
    count = min(count, len(vectors))
    try:
        model = AgglomerativeClustering(n_clusters=count, metric="cosine", linkage="average")
    except TypeError:  # scikit-learn < 1.2
        model = AgglomerativeClustering(n_clusters=count, affinity="cosine", linkage="average")
    return model.fit_predict(vectors)


def _tag(content: str) -> str:
    for label, pattern in _SECTION_TAGS:
        if pattern.search(content):
            return label
    return "METHODOLOGY & FINDINGS"


def _headline(content: str) -> str:
    words = _WORD_RE.findall(content)
    return " ".join(words[:10]).strip().capitalize() + ("..." if len(words) > 10 else "")


def _findings(sentences: list[_Sentence], cleaned: str) -> list[dict[str, str]]:
    findings: list[dict[str, str]] = []
    seen: set[str] = set()
    metric_sentence_hashes: set[int] = set()
    for sentence in sentences:
        matches = [
            re.sub(r"\s+", " ", match.group(0)).strip()
            for match in _STAT_RE.finditer(sentence.text)
        ]
        if matches:
            sentence_hash = hash(re.sub(r"\W+", " ", sentence.text.lower()).strip())
            if sentence_hash in seen:
                continue
            seen.add(sentence_hash)
            metric_sentence_hashes.add(sentence_hash)
            findings.append({
                "label": "Metric",
                "value": ", ".join(dict.fromkeys(matches)),
                "context": sentence.text,
            })
    pipeline = _nlp()
    if pipeline is not None and getattr(pipeline, "pipe_names", None) and "ner" in pipeline.pipe_names:
        for entity in pipeline(cleaned).ents:
            entity_sentence_hash = hash(re.sub(r"\W+", " ", entity.sent.text.lower()).strip())
            if (
                entity.label_ in {"PERCENT", "CARDINAL", "QUANTITY"}
                and entity.text.lower() not in seen
                and entity_sentence_hash not in metric_sentence_hashes
                and not _STAT_RE.search(entity.sent.text)
            ):
                seen.add(entity.text.lower())
                findings.append({"label": entity.label_, "value": entity.text, "context": entity.sent.text.strip()})
    return findings[:12]


def summarize_to_contract(text: str, title: str = "") -> dict[str, Any]:
    """Return the exact standardized academic summary payload."""
    raw = str(text or "").strip()
    cleaned = _clean(raw)
    sentences = _sentences(cleaned)
    if not sentences:
        raise ValueError("The document does not contain enough readable sentences.")

    documents = [sentence.text for sentence in sentences]
    matrix = TfidfVectorizer(stop_words="english", ngram_range=(1, 2)).fit_transform(documents).toarray()
    scores = _pagerank(cosine_similarity(matrix))
    chosen = _mmr(matrix, scores, min(max(6, len(sentences)), 12))
    chosen.sort(key=lambda index: sentences[index].start_idx)
    labels = _cluster_labels(matrix[chosen], min(3, max(1, len(chosen) // 2)))
    groups: dict[int, list[_Sentence]] = {}
    for position, label in enumerate(labels):
        groups.setdefault(int(label), []).append(sentences[chosen[position]])

    paragraphs: list[dict[str, Any]] = []
    assigned_indices = {chosen_position for chosen_position in chosen}
    used_indices: set[int] = set()
    for paragraph_id, group in enumerate(sorted(groups.values(), key=lambda items: min(item.start_idx for item in items)), 1):
        group.sort(key=lambda item: item.start_idx)
        content = " ".join(item.text for item in group)
        if len(_WORD_RE.findall(content)) < 60:
            for candidate in sentences:
                if candidate.vector_index in assigned_indices or candidate.vector_index in used_indices:
                    continue
                if candidate.start_idx < min(item.start_idx for item in group):
                    continue
                if len(_WORD_RE.findall(content)) >= 60:
                    break
                group.append(candidate)
                group.sort(key=lambda item: item.start_idx)
                content = " ".join(item.text for item in group)
        if len(re.findall(r"[.!?]", content)) < 2:
            continue
        used_indices.update(item.vector_index for item in group)
        paragraphs.append({
            "paragraph_id": paragraph_id,
            "tag": _tag(content),
            "headline": _headline(content),
            "content": content.strip(),
        })

    if not paragraphs and len(chosen) >= 2:
        fallback_group = [sentences[index] for index in sorted(chosen, key=lambda index: sentences[index].start_idx)]
        paragraphs.append({
            "paragraph_id": 1,
            "tag": _tag(" ".join(item.text for item in fallback_group)),
            "headline": _headline(" ".join(item.text for item in fallback_group)),
            "content": " ".join(item.text for item in fallback_group).strip(),
        })

    overview = " ".join(sentences[index].text for index in sorted(chosen, key=lambda index: scores[index], reverse=True)[:2])
    source_words = max(1, len(_WORD_RE.findall(raw)))
    summary_words = max(1, len(_WORD_RE.findall(" ".join(item["content"] for item in paragraphs))))
    reduction = max(0.0, (1 - summary_words / source_words) * 100)
    return {
        "summary_meta": {
            "title": _extract_title(raw, title),
            "doc_type": "academic",
            "reading_time_reduction": f"{reduction:.1f}%",
        },
        "executive_overview": overview,
        "thematic_paragraphs": paragraphs,
        "key_findings": _findings(sentences, cleaned),
        "actionables_or_recommendations": [
            sentence.text
            for sentence in sentences
            if re.search(r"\b(?:recommend|should|advisable|suggest)\b", sentence.text, re.I)
        ][:6],
    }
