from __future__ import annotations

import sys
import unittest
from pathlib import Path

# Add python-engine directory to sys.path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from summarizer_core.cleaning import (
    extract_valid_paragraphs,
    normalize_summary_sentence,
    prepare_document_paragraphs,
    repair_fragmented_tokens,
)
from summarizer_core.models import PreprocessingOptions, SummarizationPipeline
from summarizer_core.normalization import ensure_terminal_punctuation


class TestNLPNormalizationAndFragments(unittest.TestCase):
    """Rigorous regression test suite for conservative token repairs and sentence fragment detection."""

    def setUp(self) -> None:
        self.pipeline = SummarizationPipeline()

    # -------------------------------------------------------------------------
    # 1. Conservative Token Repair Tests (repair_fragmented_tokens)
    # -------------------------------------------------------------------------

    def test_preserves_ambiguous_dictionary_word_pairs(self) -> None:
        """Legitimate words must never be merged simply because their concatenation is in the dictionary."""
        # 'now' + 'here' -> 'nowhere' is a valid word, but must NOT merge
        self.assertEqual(repair_fragmented_tokens("now here"), "now here")
        self.assertEqual(
            repair_fragmented_tokens("The phenomenon is now here in our region."),
            "The phenomenon is now here in our region.",
        )

        # 'the' + 'rapist' -> 'therapist' is a valid word, but must NOT merge
        self.assertEqual(repair_fragmented_tokens("the rapist"), "the rapist")
        self.assertEqual(
            repair_fragmented_tokens("The evidence against the rapist was conclusive."),
            "The evidence against the rapist was conclusive.",
        )

        # Common short words that could concatenate into valid dictionary words
        self.assertEqual(repair_fragmented_tokens("in to"), "in to")
        self.assertEqual(repair_fragmented_tokens("on to"), "on to")
        self.assertEqual(repair_fragmented_tokens("at all"), "at all")
        self.assertEqual(repair_fragmented_tokens("a lot"), "a lot")
        self.assertEqual(repair_fragmented_tokens("we are"), "we are")
        self.assertEqual(repair_fragmented_tokens("he is"), "he is")
        self.assertEqual(repair_fragmented_tokens("it is"), "it is")

    def test_preserves_proper_nouns_names_and_abbreviations(self) -> None:
        """Proper nouns, names, citations, and abbreviations must never be merged."""
        self.assertEqual(repair_fragmented_tokens("New York"), "New York")
        self.assertEqual(repair_fragmented_tokens("John Doe"), "John Doe")
        self.assertEqual(repair_fragmented_tokens("San Francisco"), "San Francisco")
        self.assertEqual(repair_fragmented_tokens("pH value"), "pH value")
        self.assertEqual(repair_fragmented_tokens("DNA sample"), "DNA sample")
        self.assertEqual(repair_fragmented_tokens("RNA virus"), "RNA virus")

    def test_preserves_numbers_and_statistical_expressions(self) -> None:
        """Numbers, units, and statistical terms must remain uncorrupted."""
        self.assertEqual(repair_fragmented_tokens("p < 0.05"), "p < 0.05")
        self.assertEqual(repair_fragmented_tokens("p > 0.01"), "p > 0.01")
        self.assertEqual(repair_fragmented_tokens("10 kg"), "10 kg")
        self.assertEqual(repair_fragmented_tokens("25 mm"), "25 mm")
        self.assertEqual(repair_fragmented_tokens("Smith et al. (2020)"), "Smith et al. (2020)")

    def test_repairs_genuine_split_word_fragments(self) -> None:
        """Genuine non-word layout fragments from PDF extraction must be repaired safely."""
        # Non-word prefix or suffix
        self.assertEqual(repair_fragmented_tokens("temperat ure"), "temperature")
        self.assertEqual(repair_fragmented_tokens("sig nificant"), "significant")
        self.assertEqual(repair_fragmented_tokens("sta tistical"), "statistical")
        self.assertEqual(repair_fragmented_tokens("a nnual"), "annual")
        self.assertEqual(repair_fragmented_tokens("averag e"), "average")
        self.assertEqual(repair_fragmented_tokens("wa ter"), "water")
        self.assertEqual(repair_fragmented_tokens("st ations"), "stations")

    def test_in_context_paragraph_repair(self) -> None:
        """Repairs split words in context while maintaining sentence meaning and punctuation."""
        sample = (
            "The annual temperat ure increased over 44 years. "
            "However, the evidence is now here for all st ations."
        )
        expected = (
            "The annual temperature increased over 44 years. "
            "However, the evidence is now here for all stations."
        )
        self.assertEqual(repair_fragmented_tokens(sample), expected)

    def test_terminal_punctuation_respects_closing_quotes_and_brackets(self) -> None:
        self.assertEqual(
            ensure_terminal_punctuation('The participant answered "Always"'),
            'The participant answered "Always."',
        )
        self.assertEqual(ensure_terminal_punctuation("The report records (estimated)"), "The report records (estimated).")
        self.assertEqual(ensure_terminal_punctuation('The participant answered "Always."'), 'The participant answered "Always."')
        self.assertEqual(
            ensure_terminal_punctuation('The participant answered "Always.".'),
            'The participant answered "Always."',
        )

    def test_sentence_normalization_removes_duplicate_commas(self) -> None:
        self.assertEqual(
            normalize_summary_sentence("The policy,, there is a need for review."),
            "The policy, there is a need for review.",
        )

    def test_pdf_line_fragments_are_rejoined_before_invalid_paragraphs_are_filtered(self) -> None:
        source = "\n\n".join([
            "Education, Generative AI, Educational Technology The rapid integration of Artificial "
            "Intelligence (AI) into the educational sector has fundamentally transformed the "
            "landscape of academic",
            "programming. With the advent of Large Language",
            "Models (LLMs) and AI-assisted coding tools such as",
            "ChatGPT, GitHub Copilot, and Blackbox AI,",
            "students now have unprecedented access to instant coding assistance. In the fields "
            "of Computer Science and Information Technology (IT), these tools have redefined "
            "traditional learning methodologies.",
        ])

        paragraphs = extract_valid_paragraphs(source)
        joined = " ".join(paragraphs)

        self.assertIn(
            "academic programming. With the advent of Large Language Models (LLMs) and "
            "AI-assisted coding tools such as ChatGPT, GitHub Copilot, and Blackbox AI, "
            "students now have unprecedented access",
            joined,
        )
        self.assertNotIn("academic students now have", joined)

    def test_standalone_domain_headings_are_not_joined_to_prose(self) -> None:
        source = (
            "The order service polls the orders table every five seconds to find new records.\n\n"
            "Idempotency\n"
            "Because RabbitMQ provides at-least-once delivery, a message can be delivered more than once. "
            "Workers must record each processed idempotency key in a dedicated table with a unique constraint.\n\n"
            "Access\n"
            "Access to retained footage is limited to authorized personnel of the Traffic Enforcement Division.\n\n"
            "Disclosure to Third Parties\n"
            "Footage shall not be sold, licensed, or otherwise provided to commercial entities.\n\n"
            "Exceptional heat trends\n\n"
            "The temperatures of individual years can be influenced by natural climate fluctuations."
        )

        paragraphs = prepare_document_paragraphs(source, PreprocessingOptions())
        joined = " ".join(paragraphs)

        self.assertIn("Because RabbitMQ provides at-least-once delivery", joined)
        self.assertIn("Access to retained footage is limited", joined)
        self.assertIn("Footage shall not be sold", joined)
        self.assertIn("The temperatures of individual years can be influenced", joined)
        self.assertNotIn("Idempotency Because", joined)
        self.assertNotIn("Access Access", joined)
        self.assertNotIn("Disclosure to Third Parties Footage", joined)
        self.assertNotIn("Exceptional heat trends The", joined)

    # -------------------------------------------------------------------------
    # 2. Sentence Fragment Detection Tests (_looks_sentence_fragment)
    # -------------------------------------------------------------------------

    def test_accepts_valid_academic_and_scientific_findings(self) -> None:
        """Complete academic and scientific findings must NOT be rejected as fragments."""
        valid_sentences = [
            "A significant upward trend was observed in annual temperature.",
            "A substantial increase was detected across all meteorological stations.",
            "A slight decline occurred during the dry season.",
            "Recent investigations demonstrate that climate change alters hydrological cycles.",
            "The annual average precipitation was 1056.7 mm, showing no significant trend.",
            "Annual sunshine duration showed a significant decreasing trend at the rate of 57.65 h/10a.",
            "The relative humidity displayed a downward tendency over the 44-year observation period.",
            "Linear regression analysis indicated that warming was most pronounced in winter.",
            "The experiment yielded reliable measurements after calibration.",
            "Researchers evaluated sixty participants under strictly controlled conditions.",
        ]
        for s in valid_sentences:
            with self.subTest(sentence=s):
                self.assertFalse(
                    self.pipeline._looks_sentence_fragment(s),
                    f"Sentence falsely classified as fragment: '{s}'",
                )
        valid_sentences_tech = [
            "The system architecture consists of a frontend interface and a background worker daemon.",
            "Operating margins expanded by four percent during the fourth quarter.",
            "Engineers resolved the memory leak by releasing circular object references.",
            "The municipal council approved the public transportation modernization proposal.",
            "Users can access their account settings through the navigation header.",
        ]
        for s in valid_sentences_tech:
            with self.subTest(sentence=s):
                self.assertFalse(
                    self.pipeline._looks_sentence_fragment(s),
                    f"Statement falsely classified as fragment: '{s}'",
                )

    def test_rejects_demonstrably_malformed_fragments(self) -> None:
        """Malformed fragments lacking finite verbs or complete predication must be rejected."""
        malformed_fragments = [
            # Dangling participial phrase without subject or auxiliary
            "Showing a significant trend in the recorded meteorological dataset.",
            "Indicating that temperature increases consistently across regions.",
            # Isolated subordinate clause
            "Because the temperature increased throughout the entire region.",
            "Although relative humidity decreased steadily over the observation period.",
            # Isolated relative clause
            "Which demonstrates a notable shift in seasonal climate patterns.",
            # Prepositional fragment
            "According to the authors and their recent findings.",
            "In particular, during the dry summer months.",
            # Bare noun phrase lacking predicate
            "The substantial difference between the experimental cohort and control group.",
        ]
        for s in malformed_fragments:
            with self.subTest(fragment=s):
                self.assertTrue(
                    self.pipeline._looks_sentence_fragment(s),
                    f"Fragment failed to be detected: '{s}'",
                )


if __name__ == "__main__":
    unittest.main()
