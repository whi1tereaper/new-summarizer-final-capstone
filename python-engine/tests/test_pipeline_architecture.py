from __future__ import annotations

import subprocess
import sys
import unittest
from pathlib import Path

# Add python-engine directory to sys.path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import summarizer_core
import summarizer_core.models as models_mod
import summarizer_core.pipeline as pipeline_mod
import summarizer_core.text_utils as text_utils_mod
from summarizer_core import SummarizationPipeline, summarize_document
from summarizer_core.models import (
    CleanedDocument,
    DocumentProfile,
    ParagraphAnalysis,
    PreprocessingOptions,
    SentenceCandidate,
    SentenceScoringResult,
    SourceDocument,
    SourceInput,
    StructuredSummaryOutput,
    SummarizationRequest,
    SummarizationResult,
)
from summarizer_core.pipeline import SummarizationPipeline as CanonicalPipeline


class TestPipelineArchitecture(unittest.TestCase):
    """Architectural boundary tests for Phase 5 summarizer pipeline extraction."""

    def test_canonical_pipeline_location(self):
        """Verify SummarizationPipeline is canonically defined in pipeline.py."""
        self.assertIs(SummarizationPipeline, CanonicalPipeline)
        self.assertEqual(CanonicalPipeline.__module__, "summarizer_core.pipeline")

    def test_models_dataclass_identity(self):
        """Verify dataclasses defined in models.py are the identical types used across modules."""
        candidate = SentenceCandidate(
            index=0,
            paragraph_index=0,
            sentence_in_paragraph=0,
            paragraph_sentence_count=1,
            section="body",
            text="Architecture verification.",
            normalized_text="architecture verification.",
            ranking_text="architecture verification",
            token_count=2,
        )
        self.assertIsInstance(candidate, models_mod.SentenceCandidate)
        self.assertIsInstance(candidate, SentenceCandidate)

    def test_backward_compatibility_reexport(self):
        """Verify models.py exports SummarizationPipeline via PEP 562 __getattr__ without cycle."""
        legacy_pipeline_cls = getattr(models_mod, "SummarizationPipeline")
        self.assertIs(legacy_pipeline_cls, CanonicalPipeline)

    def test_models_purity(self):
        """Verify models.py contains data contracts and no algorithmic orchestration methods."""
        expected_contracts = {
            "CleanedDocument",
            "DocumentProfile",
            "ParagraphAnalysis",
            "PreprocessingOptions",
            "SentenceCandidate",
            "SentenceScoringResult",
            "SourceDocument",
            "SourceInput",
            "StructuredSummaryOutput",
            "SummarizationPipeline",
            "SummarizationRequest",
            "SummarizationResult",
        }
        for name in expected_contracts:
            self.assertTrue(hasattr(models_mod, name), f"models missing contract: {name}")

        # Ensure pipeline execution methods are NOT defined on models.py
        self.assertFalse(hasattr(models_mod, "summarize_document"))
        self.assertFalse(hasattr(models_mod, "_score_candidates"))
        self.assertFalse(hasattr(models_mod, "_select_sentences"))

    def test_independent_cold_process_imports(self):
        """Verify each module can be imported in a completely fresh Python interpreter."""
        sub_tests = [
            "import sys; sys.path.insert(0, 'python-engine'); import summarizer_core.models",
            "import sys; sys.path.insert(0, 'python-engine'); import summarizer_core.pipeline",
            "import sys; sys.path.insert(0, 'python-engine'); import summarizer_core.text_utils",
            "import sys; sys.path.insert(0, 'python-engine'); from summarizer_core import SummarizationPipeline",
            "import sys; sys.path.insert(0, 'python-engine'); from summarizer_core.models import SummarizationPipeline",
        ]
        for script in sub_tests:
            result = subprocess.run([sys.executable, "-c", script], capture_output=True, text=True)
            self.assertEqual(
                result.returncode,
                0,
                f"Cold import failed for '{script}': {result.stderr}",
            )

    def test_acyclic_import_graph(self):
        """Verify that models.py does not import pipeline or text_utils at module load."""
        script = """
import sys
sys.path.insert(0, 'python-engine')
import summarizer_core.models
assert 'summarizer_core.pipeline' not in sys.modules, 'models should not eagerly import pipeline'
assert 'summarizer_core.text_utils' not in sys.modules, 'models should not eagerly import text_utils'
"""
        result = subprocess.run([sys.executable, "-c", script], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, f"Acyclic check failed: {result.stderr}")


if __name__ == "__main__":
    unittest.main()
