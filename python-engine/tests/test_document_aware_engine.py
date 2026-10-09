"""Regression and acceptance test suite for document-aware, attribution-safe summarization.

Tests verify:
- Document stage detection (proposal, completed_research, technical_report, news, general)
- Sentence provenance and attribution safety (current study vs prior study)
- Proposal safety (no completed results invented, prospective verbs preserved)
- Structural heading extraction without leaking into prose
- Discourse coherence ordering and dangling demonstrative suppression
- Redundancy control with preservation of distinct metrics and mixed literature
- Deterministic quality metrics calculation
- Real academic proposal acceptance test (NEUST Peñaranda cosmetic purchase decision study)
"""

from __future__ import annotations

import sys
from pathlib import Path
import unittest
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from summarizer_core.constants import DANGLING_ANTECEDENT_PATTERN, GENERIC_HEADINGS
from summarizer_core.document_analysis import (
    analyze_document_structure,
    build_summary_plan,
    classify_claim_with_provenance,
    detect_document_stage,
)
from summarizer_core.fact_ledger import FactualityValidator, calculate_quality_metrics
from summarizer_core.models import SentenceCandidate, SummarizationRequest
from summarizer_core.pipeline import SummarizationPipeline
from summarizer_core.structure import extract_structural_heading, strip_leading_heading_label


REAL_ACADEMIC_PROPOSAL_TEXT = """
Assessment of Price and Product Quality on Cosmetic Purchase Decisions among Female and LGBTQ+ Students of NEUST Peñaranda Off-Campus

Introduction
The cosmetics industry has experienced rapid growth among tertiary students, yet the specific determinants driving purchase choices in rural and suburban branch campuses remain underexplored. This proposed study investigates the influence of price and product quality on the cosmetic purchase decisions of female and LGBTQ+ students at the Nueva Ecija University of Science and Technology (NEUST) Peñaranda Off-Campus. The primary objective is to determine whether product affordability or perceived formulation quality serves as the primary predictor of brand selection within this student population.

Conceptual Framework
The research is anchored on the Theory of Reasoned Action and Consumer Value Theory, positing that perceived monetary sacrifice and perceived product excellence jointly shape behavioral intention. The independent variables comprise price perception and product quality standards, while the dependent variable is consumer purchase decision.

Review of Related Literature
Prior studies report mixed effects of price and product quality across diverse student cohorts. Indah and Liana (2019) found a significant positive relationship between affordable pricing and product repurchase intentions among university consumers. Conversely, Smith et al. (2021) observed that price sensitivity was weak among younger buyers when brand reputation and ingredient safety were established. Furthermore, Lee (2020) demonstrated that cosmetic purchasing among gender-diverse youth is heavily influenced by inclusive marketing and product efficacy rather than promotional discounting. These contrasting findings indicate that contextual differences warrant an empirical investigation in a local Philippine campus setting.

Research Methodology
The study will employ a quantitative descriptive-correlational research design to examine the relationships among the identified variables.

Research Locale
The investigation will be conducted at the NEUST Peñaranda Off-Campus situated in Peñaranda, Nueva Ecija, Philippines.

Respondents of the Study
The target respondents will consist of 150 officially enrolled female and LGBTQ+ undergraduate students across all academic year levels.

Sampling Technique
The researchers will utilize stratified random sampling to ensure proportionate representation across diverse degree programs.

Research Instrument
A structured five-point Likert scale survey questionnaire will serve as the primary data collection instrument. The questionnaire will measure respondents' agreement regarding price sensitivity, product safety indicators, and actual purchasing frequency.

Data Gathering Procedure
The researchers will secure administrative approvals from campus authorities before distributing the survey instruments to prospective respondents.

Statistical Treatment
Frequency counts, weighted means, and Pearson product-moment correlation coefficients will be computed to evaluate the hypotheses.

Scope and Delimitations
The study will be delimited to female and LGBTQ+ undergraduate students currently enrolled at NEUST Peñaranda Off-Campus during Academic Year 2026-2027. Consequently, the findings will not be generalizable to urban university populations or non-student demographics.
"""


def candidate(index: int, text: str, *, paragraph: int = 0, section: str = "body", **fields) -> SentenceCandidate:
    return SentenceCandidate(
        index=index,
        paragraph_index=paragraph,
        sentence_in_paragraph=0,
        paragraph_sentence_count=1,
        section=section,
        text=text,
        normalized_text=text,
        ranking_text=text.casefold(),
        token_count=len(text.split()),
        source_sentence_id=f"sentence-{index+1:06d}",
        **fields,
    )


class DocumentAwareEngineTests(unittest.TestCase):
    """Test suite for document-aware engine improvements."""

    def setUp(self) -> None:
        self.pipeline = SummarizationPipeline()

    def test_document_stage_detection_identifies_proposal(self) -> None:
        """Stage detector should identify proposals with supporting evidence."""
        paragraphs = [p.strip() for p in REAL_ACADEMIC_PROPOSAL_TEXT.strip().split("\n\n") if p.strip()]
        sections = [
            "overview", "introduction", "framework", "literature_review",
            "methodology", "methodology", "methodology", "methodology",
            "methodology", "methodology", "methodology", "limitations"
        ]
        candidates = [
            candidate(i, p, paragraph=i, section=sections[i])
            for i, p in enumerate(paragraphs)
        ]
        analysis, enriched = analyze_document_structure(paragraphs, sections, candidates)
        stage_info = detect_document_stage(
            REAL_ACADEMIC_PROPOSAL_TEXT, sections, enriched, analysis["claims"], "academic_research"
        )

        self.assertEqual(stage_info.document_stage, "proposal")
        self.assertGreaterEqual(stage_info.confidence, 0.70)
        self.assertTrue(any("verbs found" in ev.lower() or "prospective" in ev.lower() for ev in stage_info.supporting_evidence))
        self.assertEqual(analysis["research_stage"], "proposal")
        self.assertFalse(analysis["results_available"])

    def test_structural_heading_extraction_prevents_prose_leaks(self) -> None:
        """Headings must not leak into summary prose and should be decoupled into metadata."""
        cases = [
            ("Research Locale The investigation will be conducted at Peñaranda.", "Research Locale", "The investigation will be conducted at Peñaranda."),
            ("Sampling Technique The researchers will utilize stratified random sampling.", "Sampling Technique", "The researchers will utilize stratified random sampling."),
            ("Statistical Treatment Pearson product-moment correlation coefficients will be computed.", "Statistical Treatment", "Pearson product-moment correlation coefficients will be computed."),
            ("Research Instrument: A structured five-point Likert scale questionnaire will be used.", "Research Instrument", "A structured five-point Likert scale questionnaire will be used."),
            ("Scope and Delimitations - The study will be delimited to enrolled students.", "Scope and Delimitations", "The study will be delimited to enrolled students."),
        ]
        for raw, expected_heading, expected_prose in cases:
            heading, prose = extract_structural_heading(raw)
            self.assertEqual(heading, expected_heading)
            self.assertEqual(prose, expected_prose)
            stripped = strip_leading_heading_label(raw)
            self.assertEqual(stripped, expected_prose)

    def test_current_study_vs_prior_study_provenance(self) -> None:
        """Literature citations must be classified as prior_study and retain attribution."""
        rrl_sentence = "Indah and Liana (2019) found a significant positive relationship between affordable pricing and product repurchase intentions."
        claim_type, epistemic, role, attr, nums, quals, neg, conf = classify_claim_with_provenance(
            rrl_sentence, section="literature_review"
        )
        self.assertEqual(role, "prior_study")
        self.assertEqual(epistemic, "PRIOR_STUDY_RESULT")
        self.assertIn("Indah and Liana", attr)

        comp_sentence = "Smith et al. (2021) observed that price sensitivity was weak among younger buyers."
        _t, epistemic2, role2, attr2, _n, _q, _neg, _c = classify_claim_with_provenance(
            comp_sentence, section="literature_review"
        )
        self.assertEqual(role2, "prior_study")
        self.assertEqual(epistemic2, "PRIOR_STUDY_RESULT")
        self.assertIn("Smith et al.", attr2)

        # Prospective current study method must be current_study and PROPOSED_METHOD
        method_sentence = "The researchers will utilize stratified random sampling to ensure proportionate representation."
        _t3, epistemic3, role3, _a3, _n3, _q3, _neg3, _c3 = classify_claim_with_provenance(
            method_sentence, section="methodology"
        )
        self.assertEqual(role3, "current_study")
        self.assertEqual(epistemic3, "PROPOSED_METHOD")

    def test_proposal_safety_prevents_invented_findings(self) -> None:
        """Proposal summaries must not invent empirical results or upgrade future actions."""
        req = SummarizationRequest(
            text=REAL_ACADEMIC_PROPOSAL_TEXT,
            analysis_mode="academic",
            summary_depth="balanced",
            output_format="paragraph",
        )
        result = self.pipeline.summarize(req)
        summary_text = result.plain_summary

        # Must not claim the current study determined or established empirical results
        lowered = summary_text.lower()
        self.assertNotIn("the study determined that price significantly", lowered)
        self.assertNotIn("results revealed that students preferred", lowered)
        self.assertNotIn("the researchers found that cosmetic quality", lowered)

        # Headings must not leak into prose
        for heading in ("Research Locale The", "Sampling Technique The", "Statistical Treatment Pearson", "Research Instrument A"):
            self.assertNotIn(heading, summary_text)

        # Summary must establish purpose and methodology
        self.assertTrue(
            any(kw in lowered for kw in ("investigates", "examine", "influence of price", "objective", "study")),
            "Summary must cover research purpose/topic",
        )
        self.assertTrue(
            any(kw in lowered for kw in ("quantitative", "sampling", "questionnaire", "likert", "correlation", "descriptive")),
            "Summary must cover research methodology",
        )

    def test_dangling_demonstrative_suppression_at_summary_start(self) -> None:
        """Summary opener must not begin with dangling dependent phrases."""
        text_with_dangling_start = """
        These findings indicate that student purchasing habits vary significantly across institutions.
        The purpose of this investigation is to evaluate cosmetic purchasing determinants among local college students.
        The study will utilize a descriptive survey method.
        """
        req = SummarizationRequest(
            text=text_with_dangling_start,
            analysis_mode="academic",
            summary_depth="brief",
            output_format="paragraph",
        )
        result = self.pipeline.summarize(req)
        summary = result.plain_summary
        first_sentence = summary.split(".")[0].strip()

        # Opener should establish topic/purpose and not start with "These findings..."
        self.assertFalse(
            bool(DANGLING_ANTECEDENT_PATTERN.match(first_sentence)),
            f"Summary opened with a dangling reference: '{first_sentence}'",
        )

    def test_mixed_literature_findings_preservation(self) -> None:
        """Conflicting evidence in literature must be preserved rather than merged into false consensus."""
        sent_pos = "Indah and Liana (2019) found a significant positive relationship between price and purchase decisions."
        sent_neg = "Smith et al. (2021) observed that price sensitivity was non-significant among younger buyers."
        from summarizer_core.scoring import claims_are_contrasting, sentences_are_redundant

        self.assertTrue(claims_are_contrasting(sent_pos, sent_neg))
        self.assertFalse(sentences_are_redundant(sent_pos, sent_neg))

    def test_deterministic_quality_metrics_calculation(self) -> None:
        """Quality metrics must be computed deterministically with valid ranges."""
        req = SummarizationRequest(
            text=REAL_ACADEMIC_PROPOSAL_TEXT,
            analysis_mode="academic",
            summary_depth="balanced",
            output_format="paragraph",
        )
        result = self.pipeline.summarize(req)
        metrics = result.source_metadata.get("quality_metrics")

        self.assertIsInstance(metrics, dict)
        self.assertIn("coverage_score", metrics)
        self.assertIn("redundancy_score", metrics)
        self.assertIn("source_support_rate", metrics)
        self.assertIn("attribution_preservation_rate", metrics)
        self.assertIn("numeric_preservation_rate", metrics)
        self.assertIn("qualifier_preservation_rate", metrics)
        self.assertIn("section_coverage", metrics)
        self.assertIn("dangling_reference_count", metrics)
        self.assertIn("heading_leak_count", metrics)

        self.assertGreaterEqual(metrics["coverage_score"], 0.0)
        self.assertLessEqual(metrics["coverage_score"], 1.0)
        self.assertGreaterEqual(metrics["redundancy_score"], 0.0)
        self.assertLessEqual(metrics["redundancy_score"], 1.0)
        self.assertGreaterEqual(metrics["source_support_rate"], 0.80)
        self.assertEqual(metrics["heading_leak_count"], 0)
        self.assertEqual(metrics["dangling_reference_count"], 0)

    def test_real_document_acceptance_balanced_academic_paragraph(self) -> None:
        """Real document acceptance test for the PEÑARANDA COSMETIC PURCHASE DECISION proposal."""
        req = SummarizationRequest(
            text=REAL_ACADEMIC_PROPOSAL_TEXT,
            analysis_mode="academic",
            summary_depth="balanced",
            output_format="paragraph",
        )
        result = self.pipeline.summarize(req)
        summary = result.plain_summary
        lowered = summary.lower()

        # 1. Topic and core variables: price and product quality
        self.assertTrue("price" in lowered and "product quality" in lowered)

        # 2. Population: female / LGBTQ+ students / Peñaranda
        self.assertTrue(any(pop in lowered for pop in ("peñaranda", "students", "female", "lgbtq+")))

        # 3. Purpose established
        self.assertTrue(any(p in lowered for p in ("investigates", "determine", "influence", "objective", "study")))

        # 4. Method / design established
        self.assertTrue(any(m in lowered for m in ("quantitative", "sampling", "questionnaire", "likert", "correlation", "descriptive")))

        # 5. Must NOT claim current study completed empirical results
        self.assertNotIn("results showed that price affected", lowered)
        self.assertNotIn("findings revealed a significant", lowered)

        # 6. Must NOT leak headings
        for h in ("Research Locale", "Respondents of the Study", "Sampling Technique", "Research Instrument", "Statistical Treatment"):
            self.assertNotIn(f"{h} The", summary)

        # 7. Document stage is proposal
        self.assertEqual(result.source_metadata.get("document_stage"), "proposal")

    def test_completed_research_preserves_findings_and_status(self) -> None:
        """Completed research paper must retain findings and report completed_research stage."""
        completed_text = """
        The Impact of Retrieval Augmented Generation on Code Synthesis Accuracy

        Abstract
        This study evaluated retrieval-augmented code generation across 300 benchmark software engineering problems.

        Methodology
        The researchers implemented a vector-indexed repository and compared baseline code completion models with augmented completions.

        Results
        Results showed that retrieval augmentation improved first-pass compilation accuracy by 24% (p < 0.01). Furthermore, syntax errors declined from 18% to 6% when repository context was supplied.

        Conclusion
        The authors conclude that contextual retrieval significantly boosts LLM coding accuracy in domain-specific tasks.
        """
        req = SummarizationRequest(
            text=completed_text,
            analysis_mode="academic",
            summary_depth="balanced",
            output_format="paragraph",
        )
        result = self.pipeline.summarize(req)
        summary = result.plain_summary

        self.assertEqual(result.source_metadata.get("document_stage"), "completed_research")
        self.assertTrue("24%" in summary or "improved" in summary.lower())

    def test_technical_report_mode_prioritizes_system_and_architecture(self) -> None:
        """Technical document stage and mode should prioritize architecture and constraints."""
        tech_text = """
        Distributed Telemetry Processing Service Architecture

        System Description
        The ingestion pipeline collects telemetry events from 1,200 edge microservices through high-throughput gRPC connections.

        Architecture and Components
        Incoming events are partitioned by tenant identifier and streamed through an Apache Kafka cluster to stateful streaming consumers. Storage persistence utilizes a distributed LSM-tree database with asynchronous compaction.

        Performance and Constraints
        End-to-end latency remains below 45 ms under a sustained load of 50,000 requests per second. However, network partition events require client-side buffer spooling up to a maximum limit of 500 MB per agent.

        Deployment Configuration
        Services run across multi-region Kubernetes clusters with automated horizontal pod autoscaling.
        """
        req = SummarizationRequest(
            text=tech_text,
            analysis_mode="technical",
            summary_depth="balanced",
            output_format="paragraph",
        )
        result = self.pipeline.summarize(req)
        summary = result.plain_summary.lower()

        self.assertEqual(result.source_metadata.get("document_stage"), "technical_report")
        self.assertTrue(any(term in summary for term in ("pipeline", "architecture", "telemetry", "kafka", "grpc", "latency")))

    def test_news_article_mode_prioritizes_lead_and_actors(self) -> None:
        """News article stage and mode should prioritize lead events and actors."""
        news_text = """
        Department of Transportation Announces Expansion of Urban Rail Corridor

        MANILA, Philippines — Transportation officials on Monday announced a major 25-kilometer expansion of the light rail transit corridor to reduce commuting congestion.

        Agency Secretary Maria Santos confirmed that civil construction will begin in November with initial funding of 15 billion pesos. The project aims to serve an estimated 250,000 passengers daily once completed in 2029.

        Commuter advocacy groups welcomed the announcement but urged transparent contractor bidding and minimal disruption to existing surface transit lines during construction.
        """
        req = SummarizationRequest(
            text=news_text,
            analysis_mode="news",
            summary_depth="balanced",
            output_format="paragraph",
        )
        result = self.pipeline.summarize(req)
        summary = result.plain_summary.lower()

        self.assertEqual(result.source_metadata.get("document_stage"), "news")
        self.assertTrue(any(term in summary for term in ("transportation", "rail", "expansion", "corridor", "manila")))


if __name__ == "__main__":
    unittest.main()
