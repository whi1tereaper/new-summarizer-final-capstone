"""Statistical fixtures below are synthetic unit data, never evaluation results."""
from __future__ import annotations

import json
import math
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from evaluation.statistics import (build_statistical_report, descriptive_statistics,
                                   holm_adjust, krippendorff_alpha, paired_comparison)


class EvaluationStatisticsTests(unittest.TestCase):
    def test_missing_nonfinite_and_single_document_are_explicit(self):
        result = descriptive_statistics([1, None, math.nan, math.inf, "bad"], bootstrap_samples=100)
        self.assertEqual((result["n_attempted"], result["n_valid"], result["n_missing"]), (5, 1, 4))
        self.assertIsNone(result["ci_95"])
        self.assertIsNone(result["sd"])
        json.dumps(result, allow_nan=False)

    def test_document_cluster_weighting_does_not_treat_raters_as_documents(self):
        result = descriptive_statistics([1, 1, 1, 5], cluster_ids=["A", "A", "A", "B"],
                                        seed=42, bootstrap_samples=100)
        self.assertEqual(result["mean"], 3)
        self.assertEqual(result["n_documents"], 2)
        self.assertEqual(result["n_valid"], 4)
        self.assertAlmostEqual(result["sd"], math.sqrt(8))
        self.assertEqual(result, descriptive_statistics([1, 1, 1, 5], cluster_ids=["A", "A", "A", "B"],
                                                        seed=42, bootstrap_samples=100))

    def test_no_data_is_not_a_zero_score(self):
        result = descriptive_statistics([None, None], bootstrap_samples=100)
        self.assertEqual(result["status"], "no_data")
        self.assertIsNone(result["mean"])
        self.assertIsNone(result["ci_95"])

    def test_holm_step_down_known_family(self):
        actual = holm_adjust([0.01, 0.04, 0.03, None, 0.2])
        for value, expected in zip(actual, [0.04, 0.09, 0.09, None, 0.2]):
            if expected is None:
                self.assertIsNone(value)
            else:
                self.assertAlmostEqual(value, expected)
        with self.assertRaises(ValueError):
            holm_adjust([1.2])

    def test_wilcoxon_matches_published_scipy_example(self):
        # Original Wilcoxon corn-plant example, reproduced in SciPy docs.
        differences = [6, 8, 14, 16, 23, 24, 28, 29, 41, -48, 49, 56, 60, -67, 75]
        left = dict(enumerate(differences))
        result = paired_comparison(left, dict.fromkeys(left, 0), bootstrap_samples=100)
        self.assertEqual(result["status"], "ok")
        self.assertEqual(result["statistic"], 24)
        self.assertAlmostEqual(result["p_value"], 0.041259765625)
        self.assertAlmostEqual(result["effect_size"], (96 - 24) / 120)

    def test_pairing_uses_ids_and_exposes_lost_pairs(self):
        result = paired_comparison({"A": 3, "B": 2, "C": None}, {"B": 1, "A": 1, "D": 5}, bootstrap_samples=100)
        self.assertEqual(result["n_paired"], 2)
        self.assertEqual(result["n_unpaired"], 2)
        self.assertEqual(result["mean_difference"], 1.5)

    def test_failed_in_both_systems_remains_in_attempted_pair_count(self):
        rows = [{"document_id": doc, "output_id": f"{system}-{doc}", "system": system,
                 "mode": "brief", "metric": "redundancy", "value": value,
                 "status": "ok" if value is not None else "unavailable", "dataset_split": "validation"}
                for system in ["proposed", "lead_n"] for doc, value in [(1, 0.1), (2, 0.2), (3, None)]]
        result = build_statistical_report({"metric_rows": rows, "bootstrap_samples": 100})
        comparison = result["comparisons"][0]
        self.assertEqual(comparison["n_paired"], 2)
        self.assertEqual(comparison["n_candidate_documents"], 3)
        self.assertEqual(comparison["n_unpaired"], 1)

    def test_ties_zeros_and_all_equal_have_documented_treatment(self):
        result = paired_comparison({1: 2, 2: 2, 3: 1}, {1: 1, 2: 1, 3: 1}, bootstrap_samples=100)
        self.assertEqual(result["n_nonzero_pairs"], 2)
        self.assertIn("permutations", result["p_method"])
        self.assertEqual(result["p_value"], 0.5)
        same = paired_comparison({1: 2, 2: 1}, {1: 2, 2: 1}, bootstrap_samples=100)
        self.assertEqual(same["status"], "no_difference")
        self.assertEqual(same["p_value"], 1.0)

    def test_missing_scipy_reports_unavailable(self):
        with patch.dict(sys.modules, {"scipy": None}):
            result = paired_comparison({1: 2, 2: 3}, {1: 1, 2: 1}, bootstrap_samples=100)
        self.assertEqual(result["status"], "unavailable")
        self.assertIsNone(result["p_value"])

    def test_ordinal_alpha_matches_krippendorff_published_missing_data_example(self):
        # Computing Krippendorff's Alpha-Reliability, pp. 8-9, A-D x 12 units.
        raters = [
            [1, 2, 3, 3, 2, 1, 4, 1, 2, None, None, None],
            [1, 2, 3, 3, 2, 2, 4, 1, 2, 5, None, 3],
            [None, 3, 3, 3, 2, 3, 4, 2, 2, 5, 1, None],
            [1, 2, 3, 3, 2, 4, 4, 1, 2, 5, 1, None],
        ]
        matrix = list(map(list, zip(*raters)))
        ordinal = krippendorff_alpha(matrix)
        interval = krippendorff_alpha(matrix, level="interval")
        self.assertAlmostEqual(ordinal["value"], 0.815, places=3)
        self.assertAlmostEqual(interval["value"], 0.849, places=3)
        self.assertEqual(ordinal["n_pairable_ratings"], 40)
        self.assertEqual(ordinal["missing_ratings"], 7)
        self.assertEqual(ordinal["n_pairable_items"], 11)

    def test_constant_ratings_are_undefined_and_singletons_are_excluded(self):
        result = krippendorff_alpha([[4, 4], [4, 4], [1, None]])
        self.assertEqual(result["status"], "undefined")
        self.assertIsNone(result["value"])
        self.assertEqual(result["n_pairable_ratings"], 4)

    def test_split_and_implementation_isolation_and_missingness_in_report(self):
        rows = []
        for split in ["validation", "test"]:
            for version in ["v1", "v2"]:
                for system in ["proposed", "lead_n"]:
                    for doc in range(3):
                        rows.append({"document_id": doc, "output_id": f"{split}-{version}-{system}-{doc}",
                                     "system": system, "mode": "brief", "metric": "rouge_l",
                                     "value": 0.7 if system == "proposed" else 0.5, "status": "ok",
                                     "version": version, "dataset_split": split})
        rows[0]["status"] = "unavailable"
        result = build_statistical_report({"metric_rows": rows, "bootstrap_samples": 100})
        self.assertEqual(len(result["automatic"]), 8)
        self.assertEqual(len(result["comparisons"]), 4)
        self.assertEqual(sum(row["n_missing"] for row in result["automatic"]), 1)
        self.assertEqual(result["human"], [])
        json.dumps(result, allow_nan=False)

    def test_blind_human_report_does_not_export_rater_identifiers(self):
        rows = []
        for item in range(2):
            for rater, value in [("private-rater-123", 4), ("private-rater-456", None)]:
                rows.append({"document_id": item, "output_id": item, "evaluator_id": rater,
                             "system": "proposed", "mode": "brief", "criterion": "relevance",
                             "rating": value, "dataset_split": "validation"})
        result = build_statistical_report({"rating_rows": rows, "bootstrap_samples": 100})
        self.assertEqual(result["human"][0]["n_missing"], 2)
        self.assertEqual(result["agreement"][0]["unsubmitted_assigned_ratings"], 2)
        self.assertNotIn("private-rater", json.dumps(result))

    def test_duplicate_ratings_and_invalid_likert_are_rejected(self):
        row = {"document_id": 1, "output_id": 1, "evaluator_id": 1, "criterion": "relevance", "rating": 4}
        with self.assertRaises(ValueError):
            build_statistical_report({"rating_rows": [row, row], "bootstrap_samples": 100})
        with self.assertRaises(ValueError):
            build_statistical_report({"rating_rows": [{**row, "rating": 6}], "bootstrap_samples": 100})


if __name__ == "__main__":
    unittest.main()
