"""Meaningful coverage, budget and factual-context regressions; synthetic sources."""
from dataclasses import replace
from pathlib import Path
import sys
import unittest
from unittest.mock import patch
import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from article_engine.coverage import article_sentences, analyze, coverage_value, DEFAULT_PARAMETERS
from article_engine.selection import select, focus_scores
from article_engine.validation import validate_selected, evidence_bundle
from article_engine.summarizer import summarize
from article_detector import detect_article
from summarizer_core.models import SourceDocument
from failure_contract import ProcessingFailure

SOURCE = """A trial of evening exercise

Researchers investigated whether evening exercise improved sleep in adults. The study enrolled 500 participants across four centers.

Results
The study reported a 12 percent improvement in reported sleep quality. However, the difference was not statistically significant after adjustment.

Limitations
The observational design cannot establish that exercise caused the improvement. Follow-up lasted only six weeks.

Conclusion
The researchers suggested that exercise may improve reported sleep quality. They recommended a randomized trial before changing clinical advice.
"""


class CoverageTests(unittest.TestCase):
    def prepare(self, text=SOURCE, **kwargs):
        records, _ = article_sentences(text)
        return records, analyze(records, enhanced=False, **kwargs)

    def result(self, text=SOURCE, **kwargs):
        request = {"text": text, "summary_depth": "comprehensive", "sentence_count": 32,
                   "target_word_budget": 160, "output_format": "paragraph", **kwargs}
        with patch("summarizer_core.tokenization.load_optional_spacy_pipeline", return_value=None):
            return summarize(request, SourceDocument(text, "text"), detect_article(text))

    def test_concepts_are_nonnegative_bounded_and_weights_normalized(self):
        _, a = self.prepare()
        self.assertGreaterEqual(a.concept_matrix.data.min(), 0)
        self.assertLessEqual(a.concept_matrix.data.max(), 1 + 1e-10)
        self.assertTrue(np.all(a.concept_weights >= 0))
        self.assertAlmostEqual(a.concept_weights.sum(), 1)

    def test_coverage_matches_exact_weighted_maximum_formula(self):
        _, a = self.prepare()
        chosen = [0, 2]
        expected = np.asarray(a.concept_matrix[chosen].max(axis=0).toarray()).ravel() @ a.concept_weights
        self.assertAlmostEqual(coverage_value(a, chosen), expected)
        self.assertEqual(coverage_value(a, []), 0)

    def test_coverage_is_monotone_with_diminishing_marginal_returns(self):
        _, a = self.prepare()
        small, large, addition = [0], [0, 1, 2], 3
        small_gain = coverage_value(a, small + [addition]) - coverage_value(a, small)
        large_gain = coverage_value(a, large + [addition]) - coverage_value(a, large)
        self.assertGreaterEqual(small_gain + 1e-12, large_gain)
        self.assertGreaterEqual(large_gain, -1e-12)

    def test_unique_sample_and_result_quantities_are_distinct_concepts(self):
        _, a = self.prepare()
        labels = [s for s in a.concepts if s.startswith("quantity:")]
        self.assertTrue(any("500" in s for s in labels))
        self.assertTrue(any("12 percent" in s for s in labels))

    def test_factually_distinct_similar_sentences_survive_mmr(self):
        text = "The study reported results from 500 participants. The study reported results showing a 12 percent improvement."
        records, a = self.prepare(text)
        config = {**DEFAULT_PARAMETERS, "lambda": .85}
        selected, _, _ = select(records, a, 2, 40, "academic", "", config=config)
        self.assertEqual(selected, [0, 1])

    def test_exact_duplicate_has_no_additional_coverage(self):
        records, a = self.prepare("The trial enrolled 500 people. The trial enrolled 500 people.")
        self.assertAlmostEqual(coverage_value(a, [0]), coverage_value(a, [0, 1]))
        selected, _, _ = select(records, a, 2, 40, "academic", "", variant="coverage")
        self.assertEqual(len(selected), 1)

    def test_negation_and_uncertainty_are_not_rewritten(self):
        text = "The treatment did not significantly improve survival. The researchers suggested that pollution may contribute to the decline."
        result = self.result(text)
        self.assertIn("The treatment did not significantly improve survival.", result["sentences"])
        self.assertIn("The researchers suggested that pollution may contribute to the decline.", result["sentences"])
        self.assertNotIn("The treatment significantly improved survival.", result["plain_summary"])
        self.assertNotIn("Pollution caused the decline.", result["plain_summary"])

    def test_mutating_negation_in_evidence_is_rejected(self):
        records, _ = self.prepare("The treatment did not significantly improve survival.")
        changed = replace(records[0], text="The treatment significantly improved survival.")
        with self.assertRaises(ProcessingFailure):
            validate_selected([changed], records, "The treatment did not significantly improve survival.")

    def test_mutating_quantity_in_evidence_is_rejected(self):
        text = "The study enrolled 500 participants at four centers."
        records, _ = self.prepare(text)
        with self.assertRaises(ProcessingFailure):
            validate_selected([replace(records[0], text=text.replace("500", "800"))], records, text)

    def test_result_requires_immediate_statistical_qualification(self):
        records, _ = self.prepare()
        result_index = next(s.index for s in records if "12 percent" in s.text)
        caveat_index = next(s.index for s in records if "not statistically significant" in s.text)
        self.assertIn(caveat_index, evidence_bundle(records, result_index))
        with self.assertRaises(ProcessingFailure):
            validate_selected([records[result_index]], records, SOURCE)

    def test_longer_budget_does_not_remove_required_qualification(self):
        result = self.result(target_word_budget=90)
        joined = result["plain_summary"]
        if "12 percent" in joined:
            self.assertIn("not statistically significant", joined)

    def test_short_meaningful_sentence_is_preserved(self):
        records, _ = article_sentences("The experiment continued for six weeks. No improvement occurred.")
        self.assertEqual(records[-1].text, "No improvement occurred.")

    def test_cross_paragraph_conclusion_can_keep_its_predecessor(self):
        text = "The pilot reduced waiting times at busy stations.\n\nTherefore, operators should test the approach at smaller stations."
        records, a = self.prepare(text)
        selected, _, _ = select(records, a, 2, 40, "general", "")
        self.assertEqual(selected, [0, 1])

    def test_multiline_sentence_retains_source_exact_offsets(self):
        text = "Researchers examined whether the new service\nreduced waiting times at the hospital. The result was inconclusive."
        records, _ = article_sentences(text)
        self.assertEqual(len(records), 2)
        self.assertIn("\n", records[0].text)
        for s in records:
            self.assertEqual(text[s.start:s.end], s.text)

    def test_custom_headings_preserve_late_evidence_section(self):
        text = "The service processes customer requests during normal operation.\n\nOperational Constraints\nThe service has not been tested above 900 requests per second."
        records, _ = article_sentences(text)
        self.assertEqual(records[-1].section, "Operational Constraints")

    def test_late_numerical_finding_is_available_for_coverage(self):
        text = "\n\n".join(["The irrigation service coordinates the water supply across local fields."] * 8) + "\n\nFindings\nThe field study found a 27 percent reduction in water loss."
        result = self.result(text, target_word_budget=40)
        self.assertIn("27 percent", result["plain_summary"])

    def test_explicit_budget_overrides_ratio_sentence_underfill(self):
        result = self.result(target_word_budget=160, sentence_count=32)
        records, _ = article_sentences(SOURCE)
        self.assertEqual(len(result["sentences"]), len(records))

    def test_strict_word_budget_includes_context_cost(self):
        result = self.result(target_word_budget=40)
        self.assertLessEqual(result["readability"]["summary_word_count"], 40)

    def test_all_six_existing_depths_and_five_formats_are_supported(self):
        for depth in ["very_brief", "brief", "short", "balanced", "detailed", "comprehensive"]:
            selections = []
            for fmt in ["paragraph", "bullets", "hybrid", "structured", "key_takeaways"]:
                result = self.result(summary_depth=depth, output_format=fmt, target_word_budget=60)
                selections.append(result["sentences"])
            self.assertTrue(all(s == selections[0] for s in selections))

    def test_predefined_method_focus_uses_concepts_not_literal_heading_only(self):
        records, a = self.prepare()
        _, allowed, status = focus_scores(records, a, "study design")
        self.assertTrue(allowed)
        self.assertEqual(status, "concept_role_supported")
        self.assertTrue(any("500" in records[i].text for i in allowed))

    def test_focus_stemming_connects_inflected_query_and_source(self):
        records, a = self.prepare()
        _, allowed, _ = focus_scores(records, a, "participants")
        self.assertTrue(any("500" in records[i].text for i in allowed))

    def test_absent_and_partially_absent_focus_are_rejected(self):
        for query in ["volcanic seismology", "exercise volcanic seismology"]:
            result = self.result(focus_query=query)
            self.assertEqual(result["sentences"], [])
            self.assertEqual(result["source_metadata"]["article_analysis"]["focus_status"], "unsupported")

    def test_context_ablation_keeps_source_span_validation(self):
        records, _ = self.prepare()
        result_index = next(s.index for s in records if "12 percent" in s.text)
        validate_selected([records[result_index]], records, SOURCE, guard=False)
        with self.assertRaises(ProcessingFailure):
            validate_selected([replace(records[result_index], text="Invented facts.")], records, SOURCE, guard=False)

    def test_unprofiled_result_has_no_nondeterministic_timing_fields(self):
        result = self.result()
        self.assertNotIn("timings", result["source_metadata"]["article_analysis"])
        self.assertNotIn("selection_seconds", result["source_metadata"]["article_analysis"]["coverage_selection"])


if __name__ == "__main__":
    unittest.main()
