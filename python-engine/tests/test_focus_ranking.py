from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from evaluation.focus_ranking import (
    FOCUS_EVALUATION_CASES,
    aggregate_ranking_metrics,
    ranking_metrics,
)
from light_engine.selection import calculate_focus_query_scores
from summarizer_core.scoring import calculate_focus_relevance_scores


def _legacy_scores(case) -> list[float]:
    return calculate_focus_relevance_scores(
        list(case.candidates),
        case.query,
        list(case.context_texts) if case.context_texts else None,
    )


def _v2_scores(case) -> list[float]:
    return calculate_focus_query_scores(
        list(case.candidates),
        case.query,
        list(case.context_texts) if case.context_texts else None,
    ).tolist()


def _rank(scores: list[float]) -> list[int]:
    return sorted(
        range(len(scores)),
        key=lambda index: (scores[index], -index),
        reverse=True,
    )


class FocusRankingEvaluationTests(unittest.TestCase):
    def test_evaluation_cases_are_valid_and_have_a_separate_holdout(self):
        self.assertGreaterEqual(len(FOCUS_EVALUATION_CASES), 10)
        self.assertIn("development", {case.split for case in FOCUS_EVALUATION_CASES})
        self.assertIn("holdout", {case.split for case in FOCUS_EVALUATION_CASES})

        for case in FOCUS_EVALUATION_CASES:
            with self.subTest(case=case.name):
                self.assertEqual(len(case.candidates), len(case.relevance_grades))
                self.assertTrue(all(grade in {0, 1, 2} for grade in case.relevance_grades))
                if case.context_texts:
                    self.assertEqual(len(case.context_texts), len(case.candidates))

    def test_development_cases_rank_relevant_evidence_first_in_both_engines(self):
        cases = tuple(
            case for case in FOCUS_EVALUATION_CASES if case.split == "development"
        )
        for engine_name, scorer in (
            ("legacy", _legacy_scores),
            ("v2", _v2_scores),
        ):
            with self.subTest(engine=engine_name):
                reciprocal_ranks = []
                recall_at_one = []
                ndcg_at_five = []
                for case in cases:
                    with self.subTest(case=case.name):
                        scores = scorer(case)
                        ranked_indices = _rank(scores)
                        if any(case.relevance_grades):
                            metrics = ranking_metrics(
                                ranked_indices,
                                case.relevance_grades,
                                scores,
                            )
                            reciprocal_ranks.append(metrics["reciprocal_rank"])
                            recall_at_one.append(metrics["recall_at_1"])
                            ndcg_at_five.append(metrics["ndcg_at_5"])
                            self.assertIn(
                                ranked_indices[0],
                                {
                                    index
                                    for index, grade in enumerate(case.relevance_grades)
                                    if grade > 0
                                },
                                f"{case.name} ranked {ranked_indices} with scores {scores}",
                            )
                        else:
                            self.assertTrue(all(score == 0.0 for score in scores))

                self.assertEqual(sum(reciprocal_ranks) / len(reciprocal_ranks), 1.0)
                self.assertEqual(sum(recall_at_one) / len(recall_at_one), 1.0)
                self.assertEqual(sum(ndcg_at_five) / len(ndcg_at_five), 1.0)

    def test_holdout_metrics_are_reported_for_both_engines(self):
        cases = tuple(
            case for case in FOCUS_EVALUATION_CASES if case.split == "holdout"
        )
        engine_results = {}
        for engine_name, scorer in (
            ("legacy", _legacy_scores),
            ("v2", _v2_scores),
        ):
            scores = [scorer(case) for case in cases]
            ranked_orders = [_rank(case_scores) for case_scores in scores]
            engine_results[engine_name] = aggregate_ranking_metrics(
                cases,
                ranked_orders,
                scores,
            )

        self.assertEqual(set(engine_results), {"legacy", "v2"})
        for metrics in engine_results.values():
            self.assertEqual(set(metrics), {
                "reciprocal_rank",
                "recall_at_1",
                "ndcg_at_5",
                "no_evidence_nonzero_score_at_1",
            })
            self.assertGreaterEqual(metrics["ndcg_at_5"], 0.0)
            self.assertLessEqual(metrics["ndcg_at_5"], 1.0)
            self.assertGreaterEqual(metrics["reciprocal_rank"], 0.80)
            self.assertGreaterEqual(metrics["ndcg_at_5"], 0.85)

    def test_ranking_metrics_handle_lower_ranks_and_no_evidence(self):
        self.assertEqual(
            ranking_metrics([2, 0, 1], (2, 0, 0)),
            {
                "reciprocal_rank": 0.5,
                "recall_at_1": 0.0,
                "ndcg_at_5": 0.6309297535714574,
                "no_evidence_nonzero_score_at_1": 0.0,
            },
        )
        no_evidence = ranking_metrics([0, 1], (0, 0), [0.0, 0.0])
        self.assertEqual(no_evidence["no_evidence_nonzero_score_at_1"], 0.0)
        false_positive = ranking_metrics([0, 1], (0, 0), [0.2, 0.0])
        self.assertEqual(false_positive["no_evidence_nonzero_score_at_1"], 1.0)


if __name__ == "__main__":
    unittest.main()
