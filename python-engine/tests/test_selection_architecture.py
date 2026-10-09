from __future__ import annotations

import subprocess
import sys
import unittest
from pathlib import Path

# Add python-engine directory to sys.path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import summarizer_core.pipeline as pipeline_mod
import summarizer_core.selection as selection_mod
from summarizer_core.models import SentenceCandidate
from summarizer_core.selection import (
    detect_topic_clusters,
    ensure_depth_coverage,
    select_candidate_indices,
    select_candidate_indices_with_profile,
)


class TestSelectionArchitecture(unittest.TestCase):
    """Architectural boundary tests for Phase 8 selection engine extraction."""

    def test_selection_canonical_exports(self):
        """Verify selection.py defines and exports canonical selection functions."""
        self.assertTrue(callable(detect_topic_clusters))
        self.assertTrue(callable(select_candidate_indices_with_profile))
        self.assertTrue(callable(ensure_depth_coverage))
        self.assertTrue(callable(select_candidate_indices))
        self.assertEqual(
            set(selection_mod.__all__),
            {
                "detect_topic_clusters",
                "select_candidate_indices_with_profile",
                "ensure_depth_coverage",
                "select_candidate_indices",
            },
        )

    def test_pipeline_delegates_to_selection(self):
        """Verify pipeline methods delegate directly to selection functions."""
        pipeline = pipeline_mod.SummarizationPipeline()

        candidates = [
            SentenceCandidate(
                0,
                0,
                0,
                1,
                "introduction",
                "First introductory candidate sentence discussing machine learning.",
                "First introductory candidate sentence discussing machine learning.",
                "first introductory candidate sentence discussing machine learning",
                7,
            ),
            SentenceCandidate(
                1,
                1,
                0,
                1,
                "results",
                "Second empirical results candidate sentence confirming accuracy.",
                "Second empirical results candidate sentence confirming accuracy.",
                "second empirical results candidate sentence confirming accuracy",
                7,
            ),
        ]
        combined_scores = [0.85, 0.75]

        # Verify base selection delegation
        pipe_selected = pipeline._select_candidate_indices(
            candidates,
            combined_scores,
            "general_article",
            2,
            {},
            0.0,
        )
        mod_selected = select_candidate_indices(
            candidates,
            combined_scores,
            "general_article",
            2,
            {},
            0.0,
        )
        self.assertEqual(pipe_selected, mod_selected)

        # Verify depth coverage delegation
        pipe_depth = pipeline._ensure_depth_coverage(
            [0],
            candidates,
            combined_scores,
            2,
            include_limitations=False,
            include_conclusion=False,
        )
        mod_depth = ensure_depth_coverage(
            [0],
            candidates,
            combined_scores,
            2,
            include_limitations=False,
            include_conclusion=False,
        )
        self.assertEqual(pipe_depth, mod_depth)

    def test_selection_does_not_import_pipeline(self):
        """Verify selection.py does not import pipeline.py either statically or at runtime."""
        script = """
import sys
sys.path.insert(0, 'python-engine')
import summarizer_core.selection
assert 'summarizer_core.pipeline' not in sys.modules, 'selection should NEVER import pipeline'
"""
        result = subprocess.run([sys.executable, "-c", script], capture_output=True, text=True)
        self.assertEqual(
            result.returncode,
            0,
            f"Acyclic check failed (selection imported pipeline): {result.stderr}",
        )

    def test_scoring_does_not_import_selection(self):
        """Verify scoring.py does not import selection.py."""
        script = """
import sys
sys.path.insert(0, 'python-engine')
import summarizer_core.scoring
assert 'summarizer_core.selection' not in sys.modules, 'scoring should not import selection'
"""
        result = subprocess.run([sys.executable, "-c", script], capture_output=True, text=True)
        self.assertEqual(
            result.returncode,
            0,
            f"Acyclic check failed (scoring imported selection): {result.stderr}",
        )

    def test_models_does_not_import_selection(self):
        """Verify models.py does not import selection.py."""
        script = """
import sys
sys.path.insert(0, 'python-engine')
import summarizer_core.models
assert 'summarizer_core.selection' not in sys.modules, 'models should not import selection'
"""
        result = subprocess.run([sys.executable, "-c", script], capture_output=True, text=True)
        self.assertEqual(
            result.returncode,
            0,
            f"Acyclic check failed (models imported selection): {result.stderr}",
        )

    def test_independent_cold_process_imports(self):
        """Verify selection module imports cleanly in cold isolated sub-processes."""
        scripts = [
            "import sys; sys.path.insert(0, 'python-engine'); import summarizer_core.selection",
            "import sys; sys.path.insert(0, 'python-engine'); from summarizer_core.selection import detect_topic_clusters",
            "import sys; sys.path.insert(0, 'python-engine'); from summarizer_core.selection import select_candidate_indices_with_profile",
            "import sys; sys.path.insert(0, 'python-engine'); from summarizer_core.selection import ensure_depth_coverage",
            "import sys; sys.path.insert(0, 'python-engine'); from summarizer_core.selection import select_candidate_indices",
        ]
        for s in scripts:
            result = subprocess.run([sys.executable, "-c", s], capture_output=True, text=True)
            self.assertEqual(
                result.returncode,
                0,
                f"Isolated import failed for script: {s}\nStderr: {result.stderr}",
            )

    def test_selection_edge_cases(self):
        """Verify edge-case handling in selection algorithms."""
        # 1. Empty candidates
        self.assertEqual(detect_topic_clusters([], None, []), [])
        self.assertEqual(
            select_candidate_indices([], [], "general_article", 2, {}, 0.0),
            [],
        )

        # 2. Single candidate
        cand = SentenceCandidate(
            0,
            0,
            0,
            1,
            "body",
            "Single candidate sentence in document.",
            "Single candidate sentence in document.",
            "single candidate sentence in document",
            5,
        )
        self.assertEqual(detect_topic_clusters([cand], None, [0.5]), [[0]])
        self.assertEqual(
            select_candidate_indices([cand], [0.5], "general_article", 2, {}, 0.0),
            [0],
        )

        # 3. Target count 0
        self.assertEqual(
            select_candidate_indices([cand], [0.5], "general_article", 0, {}, 0.0),
            [],
        )

        # 4. Target count exceeds candidate count
        cands = [
            cand,
            SentenceCandidate(
                1,
                1,
                0,
                1,
                "body",
                "Experimental temperature measured at twenty degrees celsius.",
                "Experimental temperature measured at twenty degrees celsius.",
                "experimental temperature measured twenty degrees celsius",
                7,
            ),
        ]
        self.assertEqual(
            select_candidate_indices(cands, [0.6, 0.4], "general_article", 5, {}, 0.0),
            [0, 1],
        )

    def test_depth_coverage_requirement_enforcement(self):
        """Verify ensure_depth_coverage prioritizes limitation and conclusion slots."""
        cands = [
            SentenceCandidate(
                0,
                0,
                0,
                1,
                "introduction",
                "Introduction to the major architectural framework.",
                "Introduction to the major architectural framework.",
                "introduction to the major architectural framework",
                6,
            ),
            SentenceCandidate(
                1,
                1,
                0,
                1,
                "results",
                "Core findings and experimental data metrics.",
                "Core findings and experimental data metrics.",
                "core findings and experimental data metrics",
                6,
            ),
            SentenceCandidate(
                2,
                2,
                0,
                1,
                "limitations",
                "One key limitation of this approach is memory bandwidth.",
                "One key limitation of this approach is memory bandwidth.",
                "one key limitation of this approach is memory bandwidth",
                9,
            ),
        ]
        scores = [0.90, 0.85, 0.50]
        # Initially only highest 2 were selected [0, 1]
        # Enforcing limitations should reserve a slot for candidate 2
        covered = ensure_depth_coverage(
            [0, 1],
            cands,
            scores,
            target_count=2,
            include_limitations=True,
            include_conclusion=False,
        )
        self.assertIn(2, covered)
        self.assertEqual(len(covered), 2)


if __name__ == "__main__":
    unittest.main()
