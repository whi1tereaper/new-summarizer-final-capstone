from __future__ import annotations

from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from summarizer_core.fact_ledger import build_fact_ledger, validate_summary_facts
from summarizer_core.models import SentenceCandidate


def candidate(text: str) -> SentenceCandidate:
    return SentenceCandidate(
        index=7,
        paragraph_index=2,
        sentence_in_paragraph=0,
        paragraph_sentence_count=1,
        section="results",
        text=text,
        normalized_text=text.lower(),
        ranking_text=text.lower(),
        token_count=len(text.split()),
    )


class FactLedgerTests(unittest.TestCase):
    def test_supported_numbers_dates_negation_and_qualifiers_have_no_warnings(self) -> None:
        source = "On May 4, 2024, 12.7% of participants did not report symptoms; results may be preliminary."
        result = validate_summary_facts([source], build_fact_ledger([candidate(source)]))
        self.assertEqual(result["status"], "checked_no_lexical_warnings")
        self.assertEqual(result["issues"], [])

    def test_reports_changed_number_and_omitted_qualifier_without_claiming_truth(self) -> None:
        source = "The treatment may reduce symptoms by 27% compared with placebo."
        output = "The treatment reduced symptoms by 32% compared with placebo."
        result = validate_summary_facts([output], build_fact_ledger([candidate(source)]))
        issues = {issue["issue"] for issue in result["issues"]}
        self.assertIn("unsupported_number_or_unit", issues)
        self.assertIn("possible_qualifier_omission", issues)
        self.assertIn("Heuristic diagnostics only", result["interpretation"])

    def test_reports_lost_negation(self) -> None:
        source = "The intervention did not significantly change mortality."
        output = "The intervention significantly changed mortality."
        result = validate_summary_facts([output], build_fact_ledger([candidate(source)]))
        self.assertIn(
            "possible_negation_omission",
            {issue["issue"] for issue in result["issues"]},
        )

    def test_reports_reversed_trend_direction_upward_to_downward(self) -> None:
        source = "A significant upward trend was observed in annual mean temperature (+0.28 °C per decade)."
        output = "A significant downward trend was observed in annual mean temperature (-0.28 °C per decade)."
        result = validate_summary_facts([output], build_fact_ledger([candidate(source)]))
        issues = {issue["issue"] for issue in result["issues"]}
        self.assertIn("reversed_trend_direction", issues)
        self.assertIn("reversed_numerical_sign", issues)

    def test_reports_statistical_significance_reversal(self) -> None:
        source = "Annual precipitation showed a statistically non-significant upward trend (+11.8 mm per decade, p > 0.05)."
        output = "Annual precipitation showed a statistically significant upward trend (+11.8 mm per decade)."
        result = validate_summary_facts([output], build_fact_ledger([candidate(source)]))
        issues = {issue["issue"] for issue in result["issues"]}
        self.assertIn("statistical_significance_reversal", issues)

    def test_reports_unsupported_numerical_value(self) -> None:
        source = "Annual relative humidity displayed a statistically significant downward trend (-0.68% per decade)."
        output = "Annual relative humidity displayed a statistically significant downward trend (-2.45% per decade)."
        result = validate_summary_facts([output], build_fact_ledger([candidate(source)]))
        issues = {issue["issue"] for issue in result["issues"]}
        self.assertIn("unsupported_numerical_value", issues)

    def test_factuality_validator_reverts_reversed_trend_to_source(self) -> None:
        from summarizer_core.fact_ledger import FactualityValidator
        cand = candidate("A significant upward trend was observed in annual mean temperature (+0.28 °C per decade).")
        hallucinated_sentence = "A significant downward trend was observed in annual mean temperature (-0.28 °C per decade)."
        
        repaired, report = FactualityValidator.validate_and_repair([hallucinated_sentence], [cand])
        
        # Must revert back to the exact source candidate text
        self.assertEqual(repaired[0], cand.text)
        issues = {issue["issue"] for issue in report["issues"]}
        self.assertIn("reversed_trend_direction_reverted_to_source", issues)

    def test_factuality_validator_reverts_unsupported_number_to_source(self) -> None:
        from summarizer_core.fact_ledger import FactualityValidator
        cand = candidate("Sunshine duration exhibited a pronounced decline of -42.4 hours per decade.")
        hallucinated_sentence = "Sunshine duration exhibited a pronounced decline of -85.0 hours per decade."
        
        repaired, report = FactualityValidator.validate_and_repair([hallucinated_sentence], [cand])
        
        self.assertEqual(repaired[0], cand.text)
        issues = {issue["issue"] for issue in report["issues"]}
        self.assertIn("unsupported_numerical_value_reverted_to_source", issues)

    def test_simplification_engine_rejects_trend_reversal(self) -> None:
        from summarizer_core.simplification import SimplificationEngine
        engine = SimplificationEngine()
        orig = "Annual precipitation exhibited an upward trend."
        # If someone attempted to rewrite upward to downward
        simp = "Annual precipitation exhibited a downward trend."
        is_ok, issues = engine.validate_factual_invariants(orig, simp)
        self.assertFalse(is_ok)
        self.assertTrue(any("trend_contradiction" in issue for issue in issues))


if __name__ == "__main__":
    unittest.main()
