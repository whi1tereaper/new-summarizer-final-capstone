"""Evaluation measurements, failure handling and production non-regression."""
import json
import re
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from evaluation.baselines import generate_baseline
from evaluation.engine import ABLATIONS, EvaluationPipeline
from evaluation.facts import check_facts, extract_critical_facts, factual_consistency
from evaluation.metrics import bertscore, challenge, compression, compute_metrics, content_coverage, redundancy, rouge_pair
from evaluation.runner import canonical_hash, evaluate_item
from evaluation.text import word_count
from summarizer_core.models import SentenceCandidate, SummarizationRequest
from summarizer_core.pipeline import SummarizationPipeline
from summarizer_core.profiles import resolve_profile
from summarizer_core.scoring import calculate_candidate_scores_with_profile

SOURCE = """The city council evaluated a new transport programme in 2025.
The study followed 1,240 participants for six months and compared two routes.
The intervention did not significantly reduce travel times across the whole city.
The downtown route recorded a 17.4% increase in passenger use during weekdays.
These preliminary findings may indicate improved access for local residents.
The report recommends additional research before expanding the programme.
The project budget remained at 2.5 million dollars and no additional funding was approved.
"""


class TestMetricDefinitions(unittest.TestCase):
    def test_word_counts_and_zero_source(self):
        self.assertEqual(word_count("A 17.4% rise in 1,240 participants."), 6)
        self.assertIsNone(compression("", "Text.")["ratio"])
        self.assertEqual(compression("One two three four.", "One two.")["percentage"], 50)

    def test_summary_longer_than_source_not_clamped(self):
        self.assertEqual(compression("One.", "One two.")["percentage"], -100)

    def test_redundancy_counts_sentences_once(self):
        result = redundancy("The trial enrolled 20 patients. The trial enrolled 20 patients. A separate study enrolled children.")
        self.assertEqual(result["duplicate_count"], 1)
        self.assertAlmostEqual(result["score"], 1 / 3)

    def test_rouge_known_overlap(self):
        result = rouge_pair("alpha beta gamma", "alpha beta delta")
        self.assertAlmostEqual(result["rouge_1"]["f1"], 2 / 3)
        self.assertEqual(result["rouge_2"]["f1"], .5)
        self.assertAlmostEqual(result["rouge_l"]["f1"], 2 / 3)

    def test_rouge_clips_duplicate_ngrams(self):
        result = rouge_pair("alpha alpha alpha", "alpha")
        self.assertEqual(result["rouge_1"]["precision"], 1 / 3)
        self.assertEqual(result["rouge_1"]["recall"], 1)

    def test_fact_extraction_includes_units_entities_negation(self):
        kinds = {fact["kind"] for fact in extract_critical_facts("Maria Santos did not approve approximately 12.7 kg for NASA on May 2, 2025.")}
        self.assertTrue({"numbers", "entities", "negations", "qualifiers", "dates"}.issubset(kinds))

    def test_changed_number_flagged(self):
        result = check_facts("Accuracy increased by 17.4% in the experiment.", "Accuracy increased by 74% in the experiment.")
        self.assertEqual(result["altered_facts"], 1)
        screen = factual_consistency("Accuracy increased by 17.4% in the experiment.", "Accuracy increased by 74% in the experiment.", result)
        self.assertEqual(screen["score"], 0)

    def test_negation_loss_suspected_contradiction(self):
        result = check_facts("Treatment did not significantly reduce mortality.", "Treatment reduced mortality.")
        self.assertGreater(result["suspected_contradictions"], 0)

    def test_omission_not_automatically_contradiction(self):
        result = check_facts("The study involved 120 patients. The devices were independently calibrated.", "The devices were independently calibrated.")
        self.assertEqual(result["missing_facts"], 1)
        self.assertEqual(result["suspected_contradictions"], 0)

    def test_no_facts_no_rate(self):
        self.assertIsNone(check_facts("Water flows through the channel.", "Water flows through the channel.")["preservation_rate"])

    def test_fact_identity_with_ranges_ordinals_and_decimals(self):
        source = "NASA compared the 20th-century record with the 1951-1980 baseline and a 1.25% increase."
        result = check_facts(source, source)
        self.assertEqual(result["preservation_rate"], 100)
        self.assertEqual(result["altered_facts"], 0)

    def test_numeric_substring_is_not_preservation(self):
        from evaluation.facts import contains_phrase
        self.assertFalse(contains_phrase("120 patients", "12"))
        self.assertFalse(contains_phrase("12.7 percent", "12"))

    def test_invalid_annotation_explicit(self):
        result = check_facts("The study involved patients.", "The study involved patients.", [{"text": "999 patients"}])
        self.assertEqual(result["invalid_annotations"], 1)
        self.assertIsNone(result["preservation_rate"])

    def test_content_coverage_requires_human_verification(self):
        self.assertIsNone(content_coverage("The aim is safety.", [{"text": "safety"}])["value"])
        self.assertEqual(content_coverage("The aim is safety.", [{"text": "safety", "human_verified": True}])["value"], 1)

    def test_missing_reference_not_zero_score(self):
        records = compute_metrics(SOURCE, "The city council evaluated the programme.")
        rouge = next(row for row in records if row["name"] == "rouge_l")
        self.assertEqual(rouge["status"], "not_applicable")
        self.assertIsNone(rouge["value"])

    def test_optional_dependency_failure_does_not_break_metrics(self):
        with patch("evaluation.metrics._load_bert_scorer", side_effect=ImportError("test missing dependency")):
            records = compute_metrics(SOURCE, "The city council evaluated the programme.", references=[{"text": "The city evaluated the programme."}], options={"bertscore": True})
        bert = next(row for row in records if row["name"] == "bertscore")
        self.assertEqual(bert["status"], "unavailable")
        self.assertTrue(any(row["name"] == "rouge_l" and row["status"] == "ok" for row in records))

    def test_metric_failure_is_independent(self):
        with patch("evaluation.metrics.redundancy", side_effect=RuntimeError("test failed metric")):
            records = compute_metrics(SOURCE, "The city council evaluated the programme.")
        self.assertEqual(next(row for row in records if row["name"] == "redundancy")["status"], "error")
        self.assertEqual(next(row for row in records if row["name"] == "compression_ratio")["status"], "ok")

    def test_challenge_assertions(self):
        result = challenge("Mortality was reduced.", [{"text": "not reduced"}], ["was reduced"])
        self.assertEqual(result["value"], 0)
        self.assertFalse(result["details"]["passed"])

    def test_php_challenge_case_contract(self):
        result = evaluate_item({"text": SOURCE, "mode": "comprehensive", "challenge_cases": [{"id": 7, "category": "negation", "expected_facts": ["did not significantly reduce"], "prohibited_distortions": ["never appears in the source"]}]})
        record = next(row for row in result["metrics"] if row["name"] == "challenge")
        self.assertEqual(record["status"], "ok")
        self.assertEqual(record["details"]["cases_total"], 1)
        self.assertEqual(record["details"]["cases"][0]["id"], 7)


class TestEvaluationGeneration(unittest.TestCase):
    def test_real_scoring_bonus_ablations_remove_only_named_bonus(self):
        text = "The sample may not include all 24.0% of eligible participants."
        candidate = SentenceCandidate(0, 0, 0, 1, "body", text, text, text.lower(), 9)
        parameters = dict(candidates=[candidate], tfidf_scores=[.5], title_scores=[.2], article_type="general_article", article_type_family={}, section_importance={}, position_score_fn=lambda *_: .2, paragraph_position_score_fn=lambda *_: .3, article_type_relevance_fn=lambda *_: .1, boilerplate_penalty_fn=lambda *_: 0, antecedent_pattern=re.compile(r"^$"), sel_profile=resolve_profile("general"))
        full = calculate_candidate_scores_with_profile(**parameters)[0]
        for feature, bonus in (("qualifier_protection", .05), ("negation_protection", .04), ("numerical_fact_protection", .03)):
            score = calculate_candidate_scores_with_profile(**parameters, disabled_features=frozenset([feature]))[0]
            self.assertAlmostEqual(full - score, bonus)

    def test_offline_evaluation_never_downloads_nltk_resources(self):
        from summarizer_core.tokenization import OFFLINE_RESOURCES, try_ensure_nltk_resource
        token = OFFLINE_RESOURCES.set(True)
        try:
            with patch("nltk.data.find", side_effect=LookupError("missing")), patch("nltk.download") as download:
                self.assertFalse(try_ensure_nltk_resource("missing", "missing"))
                download.assert_not_called()
        finally:
            OFFLINE_RESOURCES.reset(token)

    def test_default_evaluation_matches_production(self):
        for mode in ("brief", "balanced", "detailed", "comprehensive"):
            with self.subTest(mode=mode):
                request = SummarizationRequest(text=SOURCE, analysis_mode="academic", summary_depth=mode)
                normal = SummarizationPipeline().summarize(request)
                evaluation = EvaluationPipeline().summarize(request)
                self.assertEqual(normal, evaluation)

    def test_all_ablations_generate_without_global_mutation(self):
        request = SummarizationRequest(text=SOURCE, analysis_mode="academic", summary_depth="detailed")
        original = SummarizationPipeline().summarize(request)
        for feature in ABLATIONS:
            with self.subTest(feature=feature):
                result = EvaluationPipeline(feature).summarize(request)
                self.assertTrue(result.plain_summary)
        self.assertEqual(original, SummarizationPipeline().summarize(request))

    def test_baselines_use_whole_sentences_shared_budget(self):
        candidates = ["Alpha beta gamma delta epsilon.", "Birds fly through the blue sky.", "The river flows through the valley."]
        for system in ("lead_n", "tfidf", "textrank"):
            summary, details = generate_baseline(candidates, system, 10)
            self.assertTrue(summary.endswith("."))
            self.assertLessEqual(abs(word_count(summary) - 10), max(word_count(sentence) for sentence in candidates))
            self.assertEqual(details["target_words"], 10)

    def test_hash_deterministic_and_sensitive(self):
        self.assertEqual(canonical_hash({"b": 1, "a": 2}), canonical_hash({"a": 2, "b": 1}))
        self.assertNotEqual(canonical_hash({"a": 2}), canonical_hash({"a": 3}))

    def test_failed_generation_protocol(self):
        result = evaluate_item({"text": ""})
        self.assertEqual(result["status"], "failed")
        self.assertTrue(result["error"])

    def test_success_real_metrics_and_timing(self):
        result = evaluate_item({"text": SOURCE, "profile": "academic", "mode": "balanced"})
        self.assertEqual(result["status"], "completed")
        self.assertTrue(result["summary"])
        self.assertEqual(result["word_count"], word_count(result["summary"]))
        self.assertGreater(result["timings"]["total_seconds"], 0)
        self.assertEqual(len(result["configuration_hash"]), 64)

    def test_protocol_invalid_json(self):
        command = [sys.executable, "-B", str(Path(__file__).resolve().parents[1] / "evaluation_cli.py"), "evaluate-item"]
        result = subprocess.run(command, input="{bad", capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 2)
        self.assertEqual(result.stdout, "")
        self.assertEqual(json.loads(result.stderr)["status"], "failed")


if __name__ == "__main__":
    unittest.main()
