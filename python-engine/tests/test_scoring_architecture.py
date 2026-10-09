from __future__ import annotations

import subprocess
import sys
import unittest
from pathlib import Path

# Add python-engine directory to sys.path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import summarizer_core.models as models_mod
import summarizer_core.pipeline as pipeline_mod
import summarizer_core.scoring as scoring_mod
import summarizer_core.text_utils as text_utils_mod
from summarizer_core.models import SentenceCandidate
from summarizer_core.scoring import (
    MODE_FOCUS_PATTERNS,
    NEGATION_CUES,
    NUMERIC_PATTERN,
    QUALIFIER_PATTERN,
    calculate_candidate_scores,
    calculate_candidate_scores_with_profile,
    extract_content_tokens,
    normalize_scores,
    sentence_token_overlap,
    sentences_are_redundant,
)


class TestScoringArchitecture(unittest.TestCase):
    """Architectural boundary tests for Phase 6 scoring engine extraction."""

    def test_scoring_canonical_exports(self):
        """Verify scoring.py defines and exports canonical scoring functions."""
        self.assertTrue(callable(calculate_candidate_scores_with_profile))
        self.assertTrue(callable(calculate_candidate_scores))
        self.assertTrue(callable(normalize_scores))
        self.assertTrue(callable(sentence_token_overlap))
        self.assertTrue(callable(sentences_are_redundant))
        self.assertTrue(callable(extract_content_tokens))

    def test_pipeline_delegates_to_scoring(self):
        """Verify pipeline methods delegate to scoring functions."""
        pipeline = pipeline_mod.SummarizationPipeline()
        self.assertIs(pipeline._MODE_FOCUS_PATTERNS, MODE_FOCUS_PATTERNS)
        self.assertIs(pipeline._NEGATION_CUES, NEGATION_CUES)
        self.assertIs(pipeline._QUALIFIER_PATTERN, QUALIFIER_PATTERN)
        self.assertIs(pipeline._NUMERIC_PATTERN, NUMERIC_PATTERN)

        # Verify normalization delegation
        scores = [1.0, 3.0, 5.0]
        self.assertEqual(pipeline._normalize_scores(scores), normalize_scores(scores))

        # Verify redundancy delegation
        s1 = "This is a primary research finding."
        s2 = "This is a primary research finding."
        self.assertEqual(
            pipeline._sentences_are_redundant(s1, s2),
            sentences_are_redundant(s1, s2),
        )

    def test_scoring_does_not_import_pipeline(self):
        """Verify scoring.py does not import pipeline.py either statically or at runtime."""
        script = """
import sys
sys.path.insert(0, 'python-engine')
import summarizer_core.scoring
assert 'summarizer_core.pipeline' not in sys.modules, 'scoring should NEVER import pipeline'
"""
        result = subprocess.run([sys.executable, "-c", script], capture_output=True, text=True)
        self.assertEqual(
            result.returncode,
            0,
            f"Acyclic check failed (scoring imported pipeline): {result.stderr}",
        )

    def test_models_does_not_import_scoring(self):
        """Verify models.py does not eagerly import scoring."""
        script = """
import sys
sys.path.insert(0, 'python-engine')
import summarizer_core.models
assert 'summarizer_core.scoring' not in sys.modules, 'models should not eagerly import scoring'
"""
        result = subprocess.run([sys.executable, "-c", script], capture_output=True, text=True)
        self.assertEqual(
            result.returncode,
            0,
            f"Acyclic check failed (models imported scoring): {result.stderr}",
        )

    def test_independent_cold_process_imports(self):
        """Verify scoring module imports cleanly in isolation."""
        scripts = [
            "import sys; sys.path.insert(0, 'python-engine'); import summarizer_core.scoring",
            "import sys; sys.path.insert(0, 'python-engine'); from summarizer_core.scoring import normalize_scores",
            "import sys; sys.path.insert(0, 'python-engine'); from summarizer_core.scoring import calculate_candidate_scores_with_profile",
        ]
        for s in scripts:
            result = subprocess.run([sys.executable, "-c", s], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, f"Cold import failed for '{s}': {result.stderr}")

    def test_normalize_scores_edge_cases(self):
        """Verify normalize_scores handles empty lists, single items, and identical values."""
        self.assertEqual(normalize_scores([]), [])
        self.assertEqual(normalize_scores([5.0]), [0.0])
        self.assertEqual(normalize_scores([2.0, 2.0, 2.0]), [0.0, 0.0, 0.0])
        self.assertEqual(normalize_scores([0.0, 10.0]), [0.0, 1.0])
        self.assertEqual(normalize_scores([10.0, 20.0, 30.0]), [0.0, 0.5, 1.0])

    def test_token_overlap_edge_cases(self):
        """Verify sentence_token_overlap handles empty ranking text."""
        c1 = SentenceCandidate(0, 0, 0, 1, "body", "Hello world.", "hello world.", "hello world", 2)
        c2 = SentenceCandidate(1, 0, 1, 1, "body", "Hello there.", "hello there.", "hello there", 2)
        c3 = SentenceCandidate(2, 0, 2, 1, "body", "...", "...", "", 0)

        overlap = sentence_token_overlap(c1, c2)
        self.assertGreater(overlap, 0.0)
        self.assertLess(overlap, 1.0)
        self.assertEqual(sentence_token_overlap(c1, c3), 0.0)


if __name__ == "__main__":
    unittest.main()
