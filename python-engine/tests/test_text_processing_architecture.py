from __future__ import annotations

import subprocess
import sys
import unittest
from pathlib import Path

# Add python-engine directory to sys.path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import summarizer_core.cleaning as cleaning_mod
import summarizer_core.extraction as extraction_mod
import summarizer_core.normalization as normalization_mod
import summarizer_core.structure as structure_mod
import summarizer_core.text_utils as text_utils_mod
import summarizer_core.tokenization as tokenization_mod


class TestTextProcessingArchitecture(unittest.TestCase):
    """Architectural boundary tests for Phase 7 text processing decomposition."""

    def test_canonical_submodule_exports(self):
        """Verify each decomposed submodule exports its respective canonical functions."""
        # normalization
        self.assertTrue(callable(normalization_mod.normalize_whitespace))
        self.assertTrue(callable(normalization_mod.normalize_source_newlines))
        self.assertTrue(callable(normalization_mod.normalize_pdf_line_breaks))
        self.assertTrue(callable(normalization_mod.normalize_summary_count))
        self.assertTrue(callable(normalization_mod.log_summary_event))

        # tokenization
        self.assertTrue(callable(tokenization_mod.safe_sent_tokenize))
        self.assertTrue(callable(tokenization_mod.tokenize_words))
        self.assertTrue(callable(tokenization_mod.english_stop_words))
        self.assertTrue(callable(tokenization_mod.deduplicate_sentences))
        self.assertTrue(callable(tokenization_mod.count_words))
        self.assertTrue(callable(tokenization_mod.sentence_overlap_ratio))

        # structure
        self.assertTrue(callable(structure_mod.match_section_heading))
        self.assertTrue(callable(structure_mod.is_possible_section_heading))
        self.assertTrue(callable(structure_mod.is_heading_only_text))
        self.assertTrue(callable(structure_mod.detect_document_title))
        self.assertTrue(callable(structure_mod.detect_explicit_sections))
        self.assertTrue(callable(structure_mod.rebuild_paragraphs_from_lines))
        self.assertTrue(callable(structure_mod.shorten_summary_title))

        # cleaning
        self.assertTrue(callable(cleaning_mod.prepare_document_paragraphs))
        self.assertTrue(callable(cleaning_mod.clean_paragraph))
        self.assertTrue(callable(cleaning_mod.repair_fragmented_tokens))
        self.assertTrue(callable(cleaning_mod.clean_pdf_extracted_text))
        self.assertTrue(callable(cleaning_mod.is_page_number_line))
        self.assertTrue(callable(cleaning_mod.should_drop_line))

        # extraction
        self.assertTrue(callable(extraction_mod.load_source_document))
        self.assertTrue(callable(extraction_mod.extract_pdf_source))
        self.assertTrue(callable(extraction_mod.validate_public_url_for_fetch))
        self.assertTrue(callable(extraction_mod.is_readable_extracted_text))

    def test_text_utils_facade_backward_compatibility(self):
        """Verify text_utils facade re-exports canonical functions and retains legacy helpers."""
        # Re-exported functions from decomposed modules
        self.assertIs(text_utils_mod.normalize_whitespace, normalization_mod.normalize_whitespace)
        self.assertIs(text_utils_mod.safe_sent_tokenize, tokenization_mod.safe_sent_tokenize)
        self.assertIs(text_utils_mod.tokenize_words, tokenization_mod.tokenize_words)
        self.assertIs(text_utils_mod.detect_document_title, structure_mod.detect_document_title)
        self.assertIs(text_utils_mod.match_section_heading, structure_mod.match_section_heading)
        self.assertIs(text_utils_mod.prepare_document_paragraphs, cleaning_mod.prepare_document_paragraphs)
        self.assertIs(text_utils_mod.load_source_document, extraction_mod.load_source_document)

        # Retained legacy scoring and similarity functions in text_utils
        self.assertTrue(callable(text_utils_mod._legacy_normalize_scores))
        self.assertTrue(callable(text_utils_mod._legacy_position_score))
        self.assertTrue(callable(text_utils_mod._legacy_score_textrank))
        self.assertTrue(callable(text_utils_mod._legacy_score_lsa))
        self.assertTrue(callable(text_utils_mod.section_similarity_ratio))
        self.assertTrue(callable(text_utils_mod.max_sentence_similarity))
        self.assertTrue(callable(text_utils_mod.build_readability_info))

    def test_acyclic_imports_submodules_do_not_import_pipeline(self):
        """Verify no text-processing submodule imports pipeline.py."""
        modules_to_test = [
            "normalization",
            "tokenization",
            "structure",
            "cleaning",
            "extraction",
            "text_utils",
        ]
        for mod in modules_to_test:
            script = f"""
import sys
sys.path.insert(0, 'python-engine')
import summarizer_core.{mod}
assert 'summarizer_core.pipeline' not in sys.modules, '{mod} should NEVER import pipeline'
"""
            result = subprocess.run([sys.executable, "-c", script], capture_output=True, text=True)
            self.assertEqual(
                result.returncode,
                0,
                f"Acyclic check failed ({mod} imported pipeline): {result.stderr}",
            )

    def test_independent_cold_process_imports(self):
        """Verify each decomposed submodule imports cleanly in an isolated Python process."""
        modules = [
            "normalization",
            "tokenization",
            "structure",
            "cleaning",
            "extraction",
            "text_utils",
        ]
        for mod in modules:
            script = f"import sys; sys.path.insert(0, 'python-engine'); import summarizer_core.{mod}"
            result = subprocess.run([sys.executable, "-c", script], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, f"Cold import failed for '{mod}': {result.stderr}")

    def test_normalization_edge_cases(self):
        """Verify whitespace normalization handles edge cases."""
        self.assertEqual(normalization_mod.normalize_whitespace(""), "")
        self.assertEqual(normalization_mod.normalize_whitespace("   \t  \n  "), "")
        self.assertEqual(normalization_mod.normalize_whitespace("Hello   \n\t  World"), "Hello World")
        self.assertEqual(normalization_mod.normalize_source_newlines("Line 1\r\nLine 2\rLine 3"), "Line 1\nLine 2\nLine 3")

    def test_tokenization_edge_cases(self):
        """Verify sentence tokenization handles empty and complex text."""
        self.assertEqual(tokenization_mod.safe_sent_tokenize(""), [])
        self.assertEqual(tokenization_mod.safe_sent_tokenize("   "), [])
        sentences = tokenization_mod.safe_sent_tokenize("Dr. Smith visited Washington, D.C. He had fun.")
        self.assertGreaterEqual(len(sentences), 1)

    def test_structure_heading_recognition(self):
        """Verify heading recognition identifies canonical section headers."""
        self.assertEqual(structure_mod.match_section_heading("1. Introduction"), "introduction")
        self.assertEqual(structure_mod.match_section_heading("Methodology"), "methodology")
        self.assertEqual(structure_mod.match_section_heading("Results and Discussion"), "results")
        self.assertTrue(structure_mod.is_heading_only_text("References"))
        self.assertFalse(structure_mod.is_heading_only_text("This is an ordinary sentence describing the methodology."))


if __name__ == "__main__":
    unittest.main()
