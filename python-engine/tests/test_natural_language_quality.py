from __future__ import annotations

import os
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

# Add python-engine directory to sys.path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from summarizer_core.models import (
    PreprocessingOptions,
    SentenceCandidate,
    SummarizationPipeline,
    SummarizationRequest,
)
from summarizer_core.simplification import SimplificationEngine, simplify_summary_sentences


class TestNaturalLanguageSummarizationQuality(unittest.TestCase):
    """Rigorous verification suite for plain-language simplification, grammar validation,

    and factual preservation.
    """

    def setUp(self) -> None:
        self.engine = SimplificationEngine()
        self.pipeline = SummarizationPipeline()

    def test_language_tool_is_not_started_without_a_local_install(self) -> None:
        from summarizer_core.grammar_check import LocalGrammarChecker

        constructor = Mock()
        with (
            patch.dict(os.environ, {
                "SUMMARIZER_LANGUAGETOOL_DIR": str(Path.cwd() / "missing-languagetool"),
                "LOCALAPPDATA": str(Path.cwd() / "missing-appdata"),
            }),
            patch.object(Path, "is_file", return_value=False),
            patch.dict(sys.modules, {
                "language_tool_python": SimpleNamespace(LanguageTool=constructor),
            }),
        ):
            checker = LocalGrammarChecker.from_environment()

        self.assertFalse(checker.available)
        self.assertEqual(checker.unavailable_reason, "local_languagetool_not_prepared")
        constructor.assert_not_called()

    def test_user_illustrative_before_after_demonstration(self) -> None:
        """Verify the exact before-and-after demonstration from the user's specification:

        BEFORE:
        'The investigation was conducted to ascertain the factors influencing the purchasing behavior of respondents. The findings demonstrated that price and product quality constituted significant considerations in purchasing decisions.'

        AFTER:
        'The study looked at what influences people's buying decisions. It found that price and product quality were important factors in what they chose to buy.'
        """
        s1 = "The investigation was conducted to ascertain the factors influencing the purchasing behavior of respondents."
        s2 = "The findings demonstrated that price and product quality constituted significant considerations in purchasing decisions."

        simplified, audit = self.engine.simplify_summary([s1, s2], mode="general")

        self.assertEqual(len(simplified), 2)
        # Sentence 1: Mechanical investigation -> Natural study
        self.assertEqual(
            simplified[0],
            "The study looked at what influences people's buying decisions.",
        )
        # Sentence 2: Findings demonstrated -> It found that; significant considerations in purchasing decisions -> important factors in what they chose to buy
        self.assertEqual(
            simplified[1],
            "It found that price and product quality were important factors in what they chose to buy.",
        )

        # Audit verification
        self.assertTrue(audit[0]["modified"])
        self.assertTrue(audit[1]["modified"])

    def test_academic_research_document_preserves_methodological_facts(self) -> None:
        """Academic summaries must retain statistical rigor, p-values, sample sizes, and qualifiers."""
        academic_text = """
        The Effect of Sleep Deprivation on Cognitive Performance
        
        Abstract
        The study was conducted to examine the impact of acute sleep deprivation on working memory. Researchers evaluated 60 participants under controlled laboratory conditions. The findings demonstrated that sleep-deprived subjects exhibited a significant decrease in recall accuracy (p < 0.01). However, the evidence suggests that individual susceptibility may vary considerably.
        
        Methodology
        A quantitative randomized controlled trial was carried out with 60 healthy adults aged 20-30. Participants in the experimental group remained awake for 24 consecutive hours prior to testing.
        
        Results
        Participants in the sleep deprivation group showed a 28% reduction in memory test scores compared to the control group. Statistical analysis confirmed that response latencies increased significantly.
        
        Conclusion
        The investigation concludes that sleep loss significantly impairs working memory capacity. Future research should investigate recovery dynamics.
        """
        req = SummarizationRequest(
            text=academic_text,
            analysis_mode="academic",
            summary_length="balanced",
        )
        res = self.pipeline.summarize(req)

        summary_text = " ".join(res.sentences)
        # Preserves statistical numbers and p-values
        self.assertTrue("28%" in summary_text or "24" in summary_text)
        self.assertIn("p < 0.01", summary_text)
        # Verifies plain-language simplification
        self.assertIn("decreased significantly", summary_text.lower())
        self.assertIn("before testing", summary_text.lower())
        self.assertTrue(res.sentence_count >= 2)

    def test_news_article_produces_natural_active_flow(self) -> None:
        """News summaries should read like clean, natural reporting."""
        news_text = """
        City Council Approves New Green Transit Initiative
        
        The municipal council made a decision to approve a $45 million transit modernization plan on Tuesday. The initiative is aimed at transitioning the public bus fleet to zero-emission electric vehicles by 2030. Officials announced that federal grants will cover 60% of total capital expenditures.
        
        Mayor Elena Santos stated that cleaner transportation is essential for meeting climate targets. Council members voted unanimously in favor of the measure following three hours of public testimony.
        """
        req = SummarizationRequest(
            text=news_text,
            analysis_mode="news",
            summary_length="balanced",
        )
        res = self.pipeline.summarize(req)

        summary_text = " ".join(res.sentences)
        # Must retain critical entities and numbers
        self.assertIn("$45 million", summary_text)
        self.assertIn("2030", summary_text)
        # Nominalization 'made a decision to approve' simplified to 'decided to approve' or active verb
        self.assertTrue("decided" in summary_text.lower() or "approved" in summary_text.lower())

    def test_technical_documentation_preserves_exact_dependencies_and_terms(self) -> None:
        """Technical summaries must preserve API endpoints, dependencies, and configuration names."""
        tech_text = """
        API Gateway Architecture and Service Mesh Integration
        
        The service mesh utilizes Envoy proxy sidecars to intercept all incoming HTTP and gRPC traffic. In order to configure mutual TLS encryption, administrators must apply the security profile prior to cluster bootstrap. The gateway was designed to handle up to 50,000 requests per second with sub-10ms latency.
        
        System monitoring indicated that Redis cache clustering prevented downstream database saturation during peak load. Engineers concluded that horizontal pod autoscaling maintains throughput without service degradation.
        """
        req = SummarizationRequest(
            text=tech_text,
            analysis_mode="technical",
            summary_length="balanced",
        )
        res = self.pipeline.summarize(req)

        summary_text = " ".join(res.sentences)
        # Jargon preserved
        self.assertIn("Envoy", summary_text)
        self.assertIn("50,000", summary_text)
        # 'In order to' simplified to 'To', 'utilizes' to 'uses', 'prior to' to 'before'
        self.assertNotIn("In order to", summary_text)

    def test_source_faithfulness_accepts_candidate_supported_technical_paraphrase(self) -> None:
        source = (
            "Utilizing a researcher-made structured survey, data were collected from 225 "
            "predominantly novice (Beginner and Amateur) IT students."
        )
        summary = (
            "Using a researcher-made structured survey, data were collected from 225 "
            "predominantly novice (Beginner and Amateur) IT students."
        )
        candidate = SentenceCandidate(
            index=0,
            paragraph_index=0,
            sentence_in_paragraph=0,
            paragraph_sentence_count=1,
            section="methodology",
            text=source,
            normalized_text=source,
            ranking_text=source,
            token_count=len(source.split()),
        )

        passed, notes = self.pipeline._verify_source_faithfulness(
            [summary],
            source,
            [candidate],
        )

        self.assertTrue(passed, notes)
        self.assertEqual(notes, [])

    def test_source_faithfulness_does_not_treat_no_inside_novice_as_negation(self) -> None:
        source = "The study included novice IT students in the selected sample."
        summary = "A selected sample included novice IT students in the study."

        _, notes = self.pipeline._verify_source_faithfulness([summary], source)

        self.assertFalse(any("negation scope 'no'" in note for note in notes), notes)

    def test_business_report_prioritizes_outcomes_and_decisions(self) -> None:
        """Business reports should highlight revenue, strategic decisions, and operational impacts."""
        biz_text = """
        Quarterly Financial Performance and Strategic Review
        
        Executive Summary
        The enterprise achieved a 14% increase in operating revenue during the third quarter, reaching $1.2 billion. Management made a decision to expand European logistics hubs to ameliorate delivery timelines. Operating margins improved by 220 basis points due to automated fulfillment centers.
        
        Risk Factors
        Supply chain volatility in overseas shipping continues to pose operational risks. Executives confirmed that inventory reserves were increased by $80 million to mitigate disruption.
        """
        req = SummarizationRequest(
            text=biz_text,
            analysis_mode="executive",
            summary_length="balanced",
        )
        res = self.pipeline.summarize(req)

        summary_text = " ".join(res.sentences)
        self.assertIn("$1.2 billion", summary_text)
        self.assertIn("14%", summary_text)
        self.assertTrue(res.readability["summary_word_count"] > 0)

    def test_factual_protection_rejects_dropping_numbers(self) -> None:
        """Dropping or altering numerical findings must be rejected by factual protection."""
        orig = "The clinical trial enrolled 450 patients across 12 medical centers."
        rewritten = "The clinical trial enrolled patients across medical centers."

        ok, issues = self.engine.validate_factual_invariants(orig, rewritten)
        self.assertFalse(ok)
        self.assertTrue(any("missing_numbers" in issue for issue in issues))

    def test_factual_protection_rejects_dropping_negations(self) -> None:
        """Dropping a negative marker reverses factual polarity and must be rejected."""
        orig = "The algorithm did not converge within the designated epoch threshold."
        rewritten = "The algorithm converged within the designated epoch threshold."

        ok, issues = self.engine.validate_factual_invariants(orig, rewritten)
        self.assertFalse(ok)
        self.assertTrue(any("negation_polarity_mismatch" in issue for issue in issues))

    def test_factual_protection_rejects_dropping_uncertainty_qualifiers(self) -> None:
        """Upgrading an epistemic possibility ('may cause') to an absolute certainty ('causes') must be rejected."""
        orig = "Preliminary findings suggest that the compound may reduce inflammation."
        rewritten = "Findings show that the compound reduces inflammation."

        ok, issues = self.engine.validate_factual_invariants(orig, rewritten)
        self.assertFalse(ok)
        self.assertTrue(any("epistemic_qualifier_lost" in issue for issue in issues))

    def test_grammar_validation_rejects_sentence_fragments(self) -> None:
        """Fragments lacking a finite verb or subject must be rejected."""
        fragment_no_verb = "The substantial difference between the experimental cohort and control group."
        ok, issues = self.engine.validate_grammar(fragment_no_verb)
        self.assertFalse(ok)
        self.assertTrue(any("no_finite_root_verb" in issue for issue in issues))

    def test_grammar_validation_rejects_dangling_prepositions(self) -> None:
        """Malformed sentences ending in dangling prepositions must be rejected."""
        dangling = "The study examined what consumers were influenced by."
        # If terminal preposition is dangling without object, validate flags it
        dangling_prep = "The study examined factors influencing the purchasing behavior of."
        ok, issues = self.engine.validate_grammar(dangling_prep)
        self.assertFalse(ok)
        self.assertTrue(any("dangling_terminal_preposition" in issue for issue in issues))

    def test_document_understanding_metadata_population(self) -> None:
        """Document analysis must populate main_subject, purpose, key_findings, supporting_details, and conclusion."""
        doc = """
        Study on Renewable Energy Adoption in Manufacturing
        
        The purpose of this research is to evaluate renewable energy adoption barriers in mid-sized manufacturing firms.
        A survey was administered to 120 plant operators across three industrial corridors.
        The findings demonstrated that capital expense and grid interconnection delays constituted significant obstacles to solar installations.
        In conclusion, targeted subsidies and streamlined permitting are recommended to accelerate industrial decarbonization.
        """
        req = SummarizationRequest(text=doc, analysis_mode="general")
        res = self.pipeline.summarize(req)

        analysis = res.source_metadata.get("document_analysis", {})
        self.assertTrue("purpose" in analysis)
        self.assertTrue("key_findings" in analysis)
        self.assertTrue("conclusion" in analysis)
        self.assertTrue(len(analysis["purpose"]) > 0)


if __name__ == "__main__":
    unittest.main()
