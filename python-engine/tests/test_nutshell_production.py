from __future__ import annotations

import unittest
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from summarizer_core.nutshell import generate_nutshell


class NutshellProductionContractTests(unittest.TestCase):
    def test_preserves_negation_significance_sample_and_percentage(self) -> None:
        source = (
            "In a randomized trial, 420 adults received the intervention or usual care. "
            "The intervention did not significantly reduce mortality at 12 months, "
            "with a 4.2 percent reduction that was not statistically significant. "
            "The authors concluded that the treatment should not replace usual care. "
            "The report also describes recruitment, clinic locations, and staff training."
        )
        result = generate_nutshell(source, analysis_mode="academic")
        output = result["nutshell"].lower()
        self.assertEqual(result["status"], "completed")
        self.assertIn("not", output)
        self.assertIn("420", output)
        self.assertTrue("4.2" in output or "12 months" in output)
        self.assertLess(result["word_count"], result["source_word_count"])
        self.assertEqual(result["format"], "paragraph")

    def test_short_primary_summary_returns_not_needed(self) -> None:
        result = generate_nutshell("A sufficiently long source document. " * 20, primary_summary_word_count=18)
        self.assertEqual(result["status"], "not_needed")
        self.assertEqual(result["nutshell"], "")

    def test_modes_prioritize_relevant_facts(self) -> None:
        source = (
            "The report describes the history of the company and its earlier products. "
            "The research findings showed a 17 percent improvement among 240 participants. "
            "Executives should delay the launch because the remaining safety risk is high. "
            "The technical system reduced median latency to 35 milliseconds under peak load. "
            "The concept of feedback means information returned to guide a later action. "
            "Officials announced the agreement on March 4, 2025, according to the ministry."
        )
        by_mode = {mode: generate_nutshell(source, analysis_mode=mode)["nutshell"].lower()
                   for mode in ("general", "academic", "executive", "technical", "study", "news")}
        self.assertIn("17 percent", by_mode["academic"])
        self.assertIn("delay the launch", by_mode["executive"])
        self.assertIn("35 milliseconds", by_mode["technical"])
        self.assertIn("feedback", by_mode["study"])
        self.assertIn("march 4, 2025", by_mode["news"])

    def test_output_is_extractively_grounded_and_compact(self) -> None:
        source = (
            "Northstar Transit announced a 12 percent fare increase starting June 1, 2025. "
            "The agency said the increase will fund additional evening service. "
            "The proposal does not apply to student passes. "
            "The public consultation included meetings in four districts and an online survey. "
            "The agency will review ridership data after six months."
        )
        result = generate_nutshell(source, analysis_mode="news")
        output = result["nutshell"]
        self.assertTrue(output)
        self.assertEqual(output.count("\n"), 0)
        self.assertLess(result["word_count"], result["source_word_count"])
        self.assertEqual(result["compression_ratio"], round(result["word_count"] / result["source_word_count"], 4))
        self.assertTrue(any(sentence in source for sentence in output.split(". ") if sentence))
        self.assertIn("not", output.lower())
        self.assertIn("northstar transit", output.lower())
        self.assertIn("12 percent", output.lower())
        self.assertIn("june 1, 2025", output.lower())
        self.assertNotIn("public consultation", output.lower())

    def test_long_repetitive_document_stays_compact_and_deduplicates(self) -> None:
        central = "The city will not close the public clinic because it serves 18,000 residents each year."
        source = (central + " The proposal received no final approval. ") * 45
        result = generate_nutshell(source, analysis_mode="executive")
        self.assertEqual(result["status"], "completed")
        self.assertLess(result["word_count"], 50)
        self.assertLess(result["word_count"], result["source_word_count"] // 10)
        self.assertIn("not", result["nutshell"].lower())
        self.assertEqual(len(result["nutshell"].split(". ")), len(set(result["nutshell"].split(". "))))


if __name__ == "__main__":
    unittest.main()
