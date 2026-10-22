"""Selection optimizations must preserve clustering and factual distinctions."""
from __future__ import annotations

import json
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

import numpy as np
from scipy.sparse import csr_matrix
from sklearn.feature_extraction.text import TfidfVectorizer

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import summarizer_core.selection as selection
from summarizer_core.constants import DEPTH_CONFIG
from summarizer_core.models import SentenceCandidate
from summarizer_core.profiles import resolve_profile
from test_configuration_contracts import DEPTHS, MODES, annotated_candidates


def _legacy_clusters(matrix, scores):
    """Frozen pre-optimization dense reference, including stable score ties."""
    similarities = (matrix * matrix.T).toarray()
    order = sorted(range(matrix.shape[0]), key=lambda index: scores[index], reverse=True)
    clusters, assigned = [], set()
    for seed in order:
        if seed in assigned:
            continue
        cluster = [seed]
        assigned.add(seed)
        for other in order:
            if other not in assigned and similarities[seed, other] >= .22:
                cluster.append(other)
                assigned.add(other)
        clusters.append(cluster)
    return clusters


def _candidates(texts):
    return [SentenceCandidate(i, i, 0, 1, "body", text, text, text.lower(), len(text.split()))
            for i, text in enumerate(texts)]


def check_sparse_seed_rows_match_dense_reference_without_dense_square_allocation():
    rng = np.random.default_rng(47)
    raw = rng.uniform(size=(181, 71))
    raw[raw < .82] = 0
    norms = np.sqrt((raw * raw).sum(axis=1))
    matrix = csr_matrix(raw / np.maximum(norms[:, None], 1e-12))
    scores = [float(index % 7) for index in range(len(raw))]
    expected = _legacy_clusters(matrix, scores)
    original_toarray = csr_matrix.toarray
    allocations = []

    def bounded_toarray(value, *args, **kwargs):
        allocations.append(value.shape)
        assert value.shape[0] == 1, "Clustering materialized multiple dense source rows"
        return original_toarray(value, *args, **kwargs)

    with patch.object(csr_matrix, "toarray", bounded_toarray):
        actual = selection.detect_topic_clusters(_candidates([f"Sentence {i}." for i in range(len(raw))]), matrix, scores)
    assert actual == expected
    assert len(allocations) == len(expected)


def check_pair_features_preserve_contrasting_claims_and_are_request_local():
    candidates = _candidates([
        "The treatment produced a statistically significant improvement in outcomes.",
        "The treatment produced no significant improvement in outcomes.",
        "The treatment produced a statistically significant improvement in outcomes.",
    ])
    real_redundant = selection.sentences_are_redundant
    real_overlap = selection.sentence_token_overlap
    with (
        patch.object(selection, "sentences_are_redundant", wraps=real_redundant) as redundant,
        patch.object(selection, "sentence_token_overlap", wraps=real_overlap) as overlap,
    ):
        features = selection._SelectionFeatures(candidates)
        assert not features.redundant(0, 1)
        assert not features.redundant(1, 0)
        assert features.overlap(0, 1) <= .5
        assert features.overlap(1, 0) <= .5
        assert features.redundant(0, 2)
        assert redundant.call_count == 2
        assert overlap.call_count == 1
        new_request = selection._SelectionFeatures(candidates)
        assert new_request.redundant(0, 2)
        assert redundant.call_count == 3
    features._PAIR_LIMIT = 1
    features.redundancy.clear()
    features.overlaps.clear()
    features.redundant(0, 1)
    features.redundant(0, 2)
    features.overlap(0, 1)
    features.overlap(0, 2)
    assert len(features.redundancy) == len(features.overlaps) == 1


def check_strategy_selection_matches_frozen_preoptimization_output(mode, depth):
    fixture = json.loads((Path(__file__).parent / "fixtures" / "selection_performance_baseline.json").read_text(encoding="utf-8"))
    candidates = annotated_candidates()
    matrix = TfidfVectorizer(ngram_range=(1, 2)).fit_transform([candidate.ranking_text for candidate in candidates])
    scores = [.5 + ((index * 7) % 11) / 30 for index in range(len(candidates))]
    clusters = selection.detect_topic_clusters(candidates, matrix, scores)
    target = (1, 2, 3, 4, 6, 8)[DEPTHS.index(depth)]
    indices, metadata = selection.generate_and_rank_summary_candidates(
        candidates, scores, "general_article", target, {}, .65, resolve_profile(mode),
        topic_clusters=clusters, depth_key=depth, depth_config=DEPTH_CONFIG[depth],
    )
    assert {"indices": indices, "metadata": metadata} == fixture["cases"][f"{mode}/{depth}"]


class TestSelectionPerformance(unittest.TestCase):
    def test_sparse_clustering(self):
        check_sparse_seed_rows_match_dense_reference_without_dense_square_allocation()

    def test_pair_cache(self):
        check_pair_features_preserve_contrasting_claims_and_are_request_local()


def _strategy_test(mode, depth):
    def run(self):
        check_strategy_selection_matches_frozen_preoptimization_output(mode, depth)
    return run


for _mode in MODES:
    for _depth in DEPTHS:
        setattr(TestSelectionPerformance, f"test_baseline_{_mode}_{_depth}", _strategy_test(_mode, _depth))


if __name__ == "__main__":
    unittest.main()
