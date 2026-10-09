from __future__ import annotations

import sys
from pathlib import Path
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from summarizer_core.models import SummarizationPipeline, SummarizationRequest
from summarizer_core.profiles import resolve_profile, apply_compression_bias


SAMPLE_MULTIFACETED_TEXT = """
Advancements in Neural Network Architectures for Medical Imaging.
In this study, our research team evaluated a lightweight convolutional transformer across 15,000 clinical radiology scans.
The proposed model achieved 96.4% diagnostic accuracy with a 0.05 p-value significance threshold.
From an infrastructure viewpoint, deployment requires Dockerized Kubernetes clusters with minimum 32GB VRAM GPU nodes.
From an executive perspective, the hospital board approved a $1.8M budget allocation for clinic deployment in Q4.
However, major regulatory risks include patient data sovereignty under strict healthcare privacy mandates.
The primary recommendation is to mandate secondary physician sign-offs on all algorithmic diagnoses.
Fundamentally, artificial intelligence models learn continuous pattern manifolds through non-linear activation functions.
Yesterday, government health regulators published mandatory compliance criteria for all diagnostic AI systems.
"""


class TestSelectionProfiles(unittest.TestCase):
    def setUp(self):
        self.pipeline = SummarizationPipeline()

    def test_profile_resolution_defaults(self):
        default_p = resolve_profile("")
        self.assertEqual(default_p.mode, "general")
        self.assertEqual(default_p.label, "General Summary")

        unknown_p = resolve_profile("non_existent_mode_xyz")
        self.assertEqual(unknown_p.mode, "general")

    def test_profile_resolution_canonical_and_style(self):
        acad_by_style = resolve_profile("academic_summary")
        acad_by_mode = resolve_profile("academic")
        self.assertEqual(acad_by_style.mode, "academic")
        self.assertEqual(acad_by_mode.mode, "academic")

        exec_p = resolve_profile("executive_summary")
        self.assertEqual(exec_p.mode, "executive")

        tech_p = resolve_profile("technical_summary")
        self.assertEqual(tech_p.mode, "technical")

        news_p = resolve_profile("news_summary")
        self.assertEqual(news_p.mode, "news")

        study_p = resolve_profile("simple_summary")
        self.assertEqual(study_p.mode, "study")

    def test_compression_bias(self):
        base_words = 100
        tight = apply_compression_bias(base_words, "tight")
        generous = apply_compression_bias(base_words, "generous")
        balanced = apply_compression_bias(base_words, "balanced")
        self.assertLess(tight, base_words)
        self.assertGreater(generous, base_words)
        self.assertEqual(balanced, base_words)

    def test_academic_summary_selection_pipeline(self):
        result = self.pipeline.summarize(SummarizationRequest(
            text=SAMPLE_MULTIFACETED_TEXT,
            summary_style="academic_summary",
            selection_mode="academic",
            document_title="Medical Imaging Neural Study",
        ))
        self.assertEqual(result.selection_mode, "academic")
        self.assertEqual(result.profile_label, "Academic / Research Summary")
        self.assertTrue(result.validation_passed)
        self.assertIn("tfidf", result.active_profile_weights)
        self.assertIn("section", result.active_profile_weights)
        self.assertTrue(len(result.structured_summary) > 0)

    def test_executive_summary_selection_pipeline(self):
        result = self.pipeline.summarize(SummarizationRequest(
            text=SAMPLE_MULTIFACETED_TEXT,
            summary_style="executive_summary",
            selection_mode="executive",
            document_title="Healthcare AI Strategic Investment",
        ))
        self.assertEqual(result.selection_mode, "executive")
        self.assertEqual(result.profile_label, "Executive Summary")
        self.assertTrue(result.validation_passed)
        labels = [b["label"] for b in result.structured_summary]
        self.assertTrue(any("decision" in l.lower() or "finding" in l.lower() or "risk" in l.lower() for l in labels))

    def test_technical_summary_selection_pipeline(self):
        result = self.pipeline.summarize(SummarizationRequest(
            text=SAMPLE_MULTIFACETED_TEXT,
            summary_style="technical_summary",
            selection_mode="technical",
            document_title="Kubernetes GPU Deployment Architecture",
        ))
        self.assertEqual(result.selection_mode, "technical")
        self.assertEqual(result.profile_label, "Technical Summary")
        self.assertTrue(result.validation_passed)

    def test_news_summary_selection_pipeline(self):
        result = self.pipeline.summarize(SummarizationRequest(
            text=SAMPLE_MULTIFACETED_TEXT,
            summary_style="news_summary",
            selection_mode="news",
            document_title="Regulators Publish AI Healthcare Rules",
        ))
        self.assertEqual(result.selection_mode, "news")
        self.assertEqual(result.profile_label, "News / Events Summary")
        self.assertTrue(result.validation_passed)

    def test_user_supplied_title_signal(self):
        # A title focusing on 'regulators' will boost regulator-related sentences
        result_news = self.pipeline.summarize(SummarizationRequest(
            text=SAMPLE_MULTIFACETED_TEXT,
            summary_style="news_summary",
            selection_mode="news",
            document_title="Regulators Publish Mandatory Compliance Criteria",
        ))
        plain = result_news.plain_summary.lower()
        self.assertTrue("regulator" in plain or "compliance" in plain or "mandatory" in plain)

    def test_analysis_modes_prioritize_different_content(self):
        text = """
        The research study evaluated a treatment with 120 participants and found a 24 percent improvement.
        The deployment architecture requires Kubernetes and 32GB VRAM for production operation.
        Executives approved a $1.8M budget and requested a risk review before rollout.
        Yesterday regulators announced new compliance criteria affecting diagnostic systems.
        The historical background explains how the field developed over the last decade.
        The implementation team documented routine maintenance procedures for operators.
        The project timeline includes training, procurement, and reporting milestones.
        The organization maintains a public archive of related publications.
        A separate team is reviewing user feedback from earlier deployments.
        The document also lists general terminology and introductory definitions.
        """
        outputs = {}
        for mode in ("academic", "technical", "executive", "news"):
            result = self.pipeline.summarize(SummarizationRequest(
                text=text,
                summary_length="brief",
                analysis_mode=mode,
                output_format="paragraph",
            ))
            outputs[mode] = result.plain_summary

        self.assertIn("research", outputs["academic"].lower())
        self.assertIn("Kubernetes", outputs["technical"])
        self.assertIn("budget", outputs["executive"])
        self.assertIn("Yesterday", outputs["news"])


if __name__ == "__main__":
    unittest.main()
