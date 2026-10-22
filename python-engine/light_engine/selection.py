"""One bounded TF-IDF/MMR sentence selection strategy."""

from __future__ import annotations

import re
from typing import Any

import numpy as np
from scipy.sparse import csr_matrix
from sklearn.feature_extraction.text import TfidfVectorizer

from .content_type import ContentTypeAnalysis, section_importance
from .contract import SummaryRequest
from .extraction import SourceSentence, starts_with_context_reference
from .semantic import encode_sentences

_DEPTHS = {
    "very_brief": (0.10, 2, 70),
    "brief": (0.16, 3, 110),
    "short": (0.24, 5, 190),
    "balanced": (0.34, 10, 340),
    "detailed": (0.52, 15, 650),
    "comprehensive": (0.75, 24, 1200),
}
_MODE_CUES = {
    "research": re.compile(r"\b(?:study|research|sample|participants?|method|results?|findings?|significant|limitations?|conclusion)\b", re.I),
    "academic": re.compile(r"\b(?:study|research|sample|participants?|method|results?|findings?|significant|limitation|conclusion)\b", re.I),
    "scientific": re.compile(r"\b(?:measured|observed|experiment|significant|trend|result|finding|confidence interval|p\s*[<>=])\b", re.I),
    "executive": re.compile(r"\b(?:decision|cost|revenue|risk|impact|recommendation|priority|outcome|action)\b", re.I),
    "study": re.compile(r"\b(?:defined?|definition|concept|principle|example|means?|important|fundamental)\b", re.I),
    "technical": re.compile(r"\b(?:architecture|component|module|service|api|protocol|deploy|configuration|dependency|constraint|security)\b", re.I),
    "news": re.compile(r"\b(?:announced?|reported?|confirmed?|officials?|said|affected?|closed|opened|today|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b", re.I),
    "legal": re.compile(r"\b(?:shall|must|may not|prohibited|authorized|regulation|obligation|liability|jurisdiction|penalty|under section)\b", re.I),
    "business_report": re.compile(r"\b(?:decision|cost|revenue|risk|impact|recommendation|priority|outcome|action|forecast)\b", re.I),
    "opinion": re.compile(r"\b(?:argue|believe|in my view|in our view|should|ought to|case for|case against)\b", re.I),
    "educational": re.compile(r"\b(?:defined?|definition|concept|principle|example|means?|important|fundamental)\b", re.I),
}
_FACT_CUE = re.compile(
    r"\b\d+(?:,\d{3})*(?:\.\d+)?(?:%| percent| years?| months?| days?| hours?| km| miles?)?\b"
    r"|[$€£]\s?\d+(?:,\d{3})*(?:\.\d+)?"
    r"|\b(?:not|never|cannot|may|might|could|suggests?|approximately|at least|compared with)\b",
    re.I,
)


def _normalize(values: np.ndarray) -> np.ndarray:
    if values.size == 0:
        return values
    span = float(values.max() - values.min())
    return np.zeros_like(values, dtype=float) if span <= 1e-12 else (values - values.min()) / span


def combine_focus_query_scores(
    query_similarity: np.ndarray,
    context_similarity: np.ndarray,
    context_reference_flags: list[bool],
) -> np.ndarray:
    """Blend direct query matches with limited context for reference-led sentences."""
    if not (
        query_similarity.size == context_similarity.size == len(context_reference_flags)
    ):
        raise ValueError("Candidate, query, and context score counts must match.")
    context_weights = np.array([
        0.55 if is_reference else 0.15
        for is_reference in context_reference_flags
    ])
    return (
        (1.0 - context_weights) * _normalize(query_similarity)
        + context_weights * _normalize(context_similarity)
    )


def calculate_focus_query_scores(
    candidate_texts: list[str],
    focus_query: str,
    context_texts: list[str] | None = None,
) -> np.ndarray:
    """Return the V2 lexical query relevance scores used by sentence selection."""
    if not candidate_texts:
        return np.zeros(0, dtype=float)
    contexts = context_texts or [""] * len(candidate_texts)
    if len(contexts) != len(candidate_texts):
        raise ValueError("Context text count must match candidate text count.")
    if not focus_query.strip():
        return np.zeros(len(candidate_texts), dtype=float)

    vectorizer_corpus = candidate_texts + [text for text in contexts if text]
    vectorizer = TfidfVectorizer(
        lowercase=True,
        stop_words="english",
        ngram_range=(1, 2),
        max_features=20000,
        sublinear_tf=True,
    )
    try:
        vectorizer.fit(vectorizer_corpus)
    except ValueError:
        vectorizer = TfidfVectorizer(
            lowercase=True,
            token_pattern=r"(?u)\b\w+\b",
        )
        try:
            vectorizer.fit(vectorizer_corpus)
        except ValueError:
            return np.zeros(len(candidate_texts), dtype=float)

    query_vector = vectorizer.transform([focus_query])
    candidate_matrix = vectorizer.transform(candidate_texts)
    context_matrix = vectorizer.transform(contexts)
    query_similarity = np.asarray(
        candidate_matrix.multiply(query_vector).sum(axis=1)
    ).ravel()
    context_similarity = np.asarray(
        context_matrix.multiply(query_vector).sum(axis=1)
    ).ravel()
    reference_flags = [
        starts_with_context_reference(text)
        for text in candidate_texts
    ]
    return combine_focus_query_scores(
        query_similarity,
        context_similarity,
        reference_flags,
    )


def _budget(request: SummaryRequest, sentence_count: int, source_words: int) -> tuple[int, int]:
    count_ratio, depth_limit, depth_words = _DEPTHS[request.summary_depth]
    word_ratio = min(0.75, count_ratio + 0.04)
    count = max(1, min(depth_limit, request.sentence_count, int(np.ceil(sentence_count * count_ratio))))
    if request.summary_depth == "comprehensive" and source_words <= depth_words:
        count = min(depth_limit, request.sentence_count, sentence_count)
    words = max(45, min(depth_words, int(round(source_words * word_ratio))))
    if request.target_word_budget:
        words = min(words, request.target_word_budget)
    return count, words


def select_sentences(
    candidates: list[SourceSentence],
    request: SummaryRequest,
    source_words: int,
    content_type: ContentTypeAnalysis,
) -> tuple[list[SourceSentence], dict[str, Any], list[str]]:
    if not candidates:
        return [], {"strategy": "adaptive_tfidf_mmr_v2", "candidates": 0, "selected": 0}, []

    corpus = [candidate.text for candidate in candidates]
    context_corpus = []
    for index, candidate in enumerate(candidates):
        context_parts = []
        section = candidate.section.strip()
        if section and section.casefold() not in {"body", "document"}:
            context_parts.append(section)
        for neighbor_index in (index - 1, index + 1):
            if not 0 <= neighbor_index < len(candidates):
                continue
            neighbor = candidates[neighbor_index]
            if neighbor.paragraph_index == candidate.paragraph_index:
                context_parts.append(neighbor.text)
        context_corpus.append(" ".join(context_parts))

    vectorizer = TfidfVectorizer(
        lowercase=True,
        stop_words="english",
        ngram_range=(1, 2),
        max_features=20000,
        sublinear_tf=True,
    )
    vectorizer_corpus = corpus + [
        context for context in context_corpus if context
    ] if request.focus_query else corpus
    matrix: csr_matrix | None
    try:
        vectorizer.fit(vectorizer_corpus)
        matrix = csr_matrix(vectorizer.transform(corpus))
    except ValueError:
        vectorizer = TfidfVectorizer(lowercase=True, token_pattern=r"(?u)\b\w+\b")
        try:
            vectorizer.fit(vectorizer_corpus)
            matrix = csr_matrix(vectorizer.transform(corpus))
        except ValueError:
            matrix = None

    if matrix is None:
        document_relevance = np.ones(len(candidates), dtype=float)
        query_relevance = np.zeros(len(candidates), dtype=float)
        similarities = None
        keywords: list[str] = []
    else:
        centroid = np.asarray(matrix.mean(axis=0)).ravel()
        document_relevance = _normalize(np.asarray(matrix @ centroid).ravel())
        if request.focus_query:
            query_vector = vectorizer.transform([request.focus_query])
            query_similarity = np.asarray(
                matrix.multiply(query_vector).sum(axis=1)
            ).ravel()
            context_matrix = vectorizer.transform(context_corpus)
            context_similarity = np.asarray(
                context_matrix.multiply(query_vector).sum(axis=1)
            ).ravel()
            query_relevance = combine_focus_query_scores(
                query_similarity,
                context_similarity,
                [
                    starts_with_context_reference(candidate.text)
                    for candidate in candidates
                ],
            )
        else:
            query_relevance = np.zeros(len(candidates), dtype=float)
        similarities: csr_matrix | None = matrix
        feature_importance = centroid
        top_indices = np.argsort(feature_importance)[::-1][:10]
        keywords = [
            str(vectorizer.get_feature_names_out()[i])
            for i in top_indices
            if feature_importance[i] > 0
        ]

    semantic_vectors, semantic_model_status = encode_sentences(corpus)
    semantic_similarity = (
        semantic_vectors @ semantic_vectors.T
        if semantic_vectors is not None
        else None
    )
    if semantic_vectors is not None:
        semantic_center = semantic_vectors.mean(axis=0)
        semantic_center /= max(float(np.linalg.norm(semantic_center)), 1e-12)
        semantic_relevance = _normalize(semantic_vectors @ semantic_center)
        document_relevance = (
            0.65 * document_relevance + 0.35 * semantic_relevance
        )
        if request.focus_query:
            query_vectors, _ = encode_sentences([request.focus_query])
            if query_vectors is not None:
                semantic_query = semantic_vectors @ query_vectors[0]
                query_relevance = (
                    0.65 * query_relevance
                    + 0.35 * _normalize(semantic_query)
                )

    relevance = (
        0.40 * document_relevance + 0.60 * query_relevance
        if request.focus_query
        else document_relevance
    )

    explicit_profile = request.analysis_mode != "general"
    detected_profile = (
        content_type.category
        if content_type.confidence >= 0.36
        else "general"
    )
    selection_profile = request.analysis_mode if explicit_profile else detected_profile
    section_profile = {
        "academic": "research",
        "scientific": "research",
        "study": "educational",
        "executive": "business_report",
    }.get(selection_profile, selection_profile)
    mode_pattern = _MODE_CUES.get(selection_profile)
    lexical = np.array([
        min(1.0, len(_FACT_CUE.findall(item.text)) / 3) * 0.10
        + (0.08 if mode_pattern and mode_pattern.search(item.text) else 0.0)
        + min(0.24, section_importance(section_profile, item.section))
        for item in candidates
    ])
    position = np.array([
        0.025 * (1.0 - item.index / max(1, len(candidates) - 1))
        for item in candidates
    ])
    base_scores = 0.72 * _normalize(relevance) + lexical + position
    target_count, word_budget = _budget(request, len(candidates), source_words)
    selected: list[int] = []
    remaining = set(range(len(candidates)))
    selected_words = 0
    while remaining and len(selected) < target_count:
        best_index: int | None = None
        best_score = float("-inf")
        for index in remaining:
            candidate = candidates[index]
            word_count = len(candidate.text.split())
            if selected_words + word_count > word_budget and selected:
                continue
            if starts_with_context_reference(candidate.text):
                prior_context_selected = any(
                    candidates[chosen].paragraph_index == candidate.paragraph_index
                    and candidates[chosen].index == candidate.index - 1
                    for chosen in selected
                )
                if not prior_context_selected and candidate.index > 0:
                    continue
            redundancy = 0.0
            if selected and similarities is not None:
                overlap = similarities.getrow(index) @ similarities[selected].transpose()
                redundancy = float(np.asarray(overlap.toarray()).max(initial=0.0))
            if selected and semantic_similarity is not None:
                semantic_overlap = float(semantic_similarity[index, selected].max())
                redundancy = max(redundancy, semantic_overlap)
            mmr = 0.78 * float(base_scores[index]) - 0.22 * redundancy
            if any(abs(index - chosen) == 1 for chosen in selected):
                mmr += 0.06
            if mmr > best_score:
                best_score = mmr
                best_index = index
        if best_index is None:
            break
        selected.append(best_index)
        selected_words += len(candidates[best_index].text.split())
        remaining.remove(best_index)

    if not selected:
        selected = [int(np.argmax(base_scores))]
    chosen = [candidates[index] for index in sorted(selected)]
    metadata = {
        "strategy": "adaptive_tfidf_mmr_v2",
        "candidates": len(candidates),
        "selected": len(chosen),
        "word_budget": word_budget,
        "requested_word_budget": request.target_word_budget,
        "max_sentences": target_count,
        "depth": request.summary_depth,
        "detected_content_type": content_type.category,
        "classification_confidence": round(content_type.confidence, 3),
        "selection_profile": selection_profile,
        "selection_profile_source": "user" if explicit_profile else (
            "detected" if detected_profile != "general" else "general_fallback"
        ),
        "semantic_model_status": semantic_model_status,
    }
    return chosen, metadata, keywords
