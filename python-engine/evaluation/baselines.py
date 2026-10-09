"""Whole-sentence Lead-N, TF-IDF centroid and TextRank baselines, version 1.

All consume the same valid sentence candidates as the proposed system. The
closest ranked prefix to the shared word target is selected, then restored to
source order. No sentence is truncated to make a baseline appear weaker.
"""
import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer

from .text import word_count

BASELINE_VERSION = "1.0.0"
BASELINES = ("lead_n", "tfidf", "textrank")
SYSTEMS = ("proposed", "hybrid_llm", *BASELINES)


def generate_baseline(candidates, system, target_words):
    if system not in BASELINES:
        raise ValueError("Unknown baseline.")
    if not candidates or target_words < 1:
        raise ValueError("A baseline requires valid sentences and a positive budget.")
    if len(candidates) > 2500:
        raise ValueError("Baseline sentence limit exceeded (2500). Split the source explicitly.")
    texts = [candidate.text if hasattr(candidate, "text") else str(candidate) for candidate in candidates]
    ranked = list(range(len(texts)))
    details = {"version": BASELINE_VERSION, "budget_policy": "closest ranked whole-sentence prefix", "sentence_count": len(texts)}
    if system != "lead_n":
        matrix = TfidfVectorizer(lowercase=True, ngram_range=(1, 1), norm="l2").fit_transform(texts)
        if system == "tfidf":
            centroid = np.asarray(matrix.mean(axis=0)).ravel()
            norm = np.linalg.norm(centroid)
            scores = np.asarray(matrix @ (centroid / norm if norm else centroid)).ravel()
            details["ranking"] = "TF-IDF cosine similarity to document centroid"
        else:
            # Weighted undirected cosine graph, diagonal removed; dangling mass
            # is redistributed uniformly, as in standard PageRank.
            graph = (matrix @ matrix.T).toarray()
            np.fill_diagonal(graph, 0)
            degrees = graph.sum(axis=1)
            transition = np.divide(graph, degrees[:, None], out=np.zeros_like(graph), where=degrees[:, None] != 0)
            scores = np.full(len(texts), 1 / len(texts))
            for iteration in range(200):
                updated = .15 / len(texts) + .85 * (transition.T @ scores + scores[degrees == 0].sum() / len(texts))
                if np.abs(updated - scores).sum() < 1e-8:
                    scores = updated
                    break
                scores = updated
            details.update(ranking="TextRank weighted TF-IDF cosine PageRank", damping=.85, tolerance=1e-8, iterations=iteration + 1)
        ranked.sort(key=lambda index: (-float(scores[index]), index))
    cumulative = 0
    prefixes = []
    for length, index in enumerate(ranked, 1):
        cumulative += word_count(texts[index])
        prefixes.append((abs(cumulative - target_words), length, cumulative))
        if cumulative >= target_words:
            break
    _, length, actual = min(prefixes)
    chosen = sorted(ranked[:length])
    details.update(target_words=target_words, actual_words=actual, budget_delta_words=actual - target_words)
    return " ".join(texts[index] for index in chosen), details
