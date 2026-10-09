from __future__ import annotations

import unittest

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


if __name__ == "__main__":
    unittest.main()
