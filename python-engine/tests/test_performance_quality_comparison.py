from copy import deepcopy
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from evaluation.compare_performance_quality import compare_reports


class TestPerformanceQualityComparison(unittest.TestCase):
    def setUp(self):
        self.before = {"cases": [{"case": "example", "source_sha256": "source-a", "configuration": {"analysis_mode": "general"},
            "output": {"sentences": ["Results did not improve."], "source_metadata": {}},
            "automated_quality_metrics": [{"name": "factual_consistency", "status": "ok", "value": 1, "details": {"duration_seconds": 1}}],
            "runs": [{"state": "cold", "iteration": 0, "summarize_seconds": 2, "selected_output_sha256": "output-a", "quality": {"validation_passed": True}}]}]}

    def test_timing_metadata_only_is_ignored(self):
        after = deepcopy(self.before)
        after["cases"][0]["output"]["source_metadata"]["performance"] = {"seconds": .1}
        after["cases"][0]["automated_quality_metrics"][0]["details"]["duration_seconds"] = .01
        after["cases"][0]["runs"][0]["summarize_seconds"] = 1
        report = compare_reports([self.before], [after])
        self.assertTrue(report["all_outputs_identical"])
        self.assertTrue(report["all_automated_metrics_identical"])
        self.assertEqual(report["cases"][0]["runs"][0]["reduction_percent"], 50)

    def test_changed_wording_is_reported_despite_equal_proxy_score(self):
        after = deepcopy(self.before)
        after["cases"][0]["output"]["sentences"] = ["Results improved."]
        report = compare_reports([self.before], [after])
        self.assertFalse(report["all_outputs_identical"])
        self.assertEqual(report["cases"][0]["changed_output_paths"], ["sentences[0]"])

    def test_changed_sources_or_settings_cannot_form_a_comparison(self):
        for field, value in (("source_sha256", "source-b"), ("configuration", {"analysis_mode": "scientific"})):
            with self.subTest(field=field):
                after = deepcopy(self.before)
                after["cases"][0][field] = value
                report = compare_reports([self.before], [after])
                self.assertEqual(report["matched_case_count"], 0)
                self.assertFalse(report["all_outputs_identical"])

    def test_partial_case_coverage_cannot_claim_all_outputs_identical(self):
        after = deepcopy(self.before)
        extra = deepcopy(self.before["cases"][0])
        extra["case"] = "missing-after"
        self.before["cases"].append(extra)
        report = compare_reports([self.before], [after])
        self.assertEqual(report["matched_case_count"], 1)
        self.assertFalse(report["all_outputs_identical"])
        self.assertEqual(report["unmatched_before"], ["missing-after"])


if __name__ == "__main__":
    unittest.main()
