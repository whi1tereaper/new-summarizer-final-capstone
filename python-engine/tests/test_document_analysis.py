from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from summarizer_core.cleaning import normalize_summary_sentence
from summarizer_core.document_analysis import (
    analyze_document_structure,
    build_dynamic_summary_sections,
    build_source_evidence,
    build_summary_plan,
    classify_claim,
)
from summarizer_core.fact_ledger import build_fact_ledger, validate_summary_facts
from summarizer_core.models import SentenceCandidate, SummarizationPipeline
from summarizer_core.scoring import claims_are_contrasting, sentence_token_overlap, sentences_are_redundant


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
        **fields,
    )


class DocumentAnalysisTests(unittest.TestCase):
    def test_proposal_without_results_is_classified_and_missing_results_are_explicit(self):
        paragraphs = [
            "This study aims to determine whether price relates to students' cosmetic purchase decisions.",
            "Previous studies found mixed associations between price and purchase decisions (Smith, 2022).",
            "Researchers will use a descriptive-correlational design and administer a 5-point survey.",
        ]
        sections = ["introduction", "literature_review", "methodology"]
        candidates = [
            candidate(0, paragraphs[0], section=sections[0]),
            candidate(1, paragraphs[1], paragraph=1, section=sections[1]),
            candidate(2, paragraphs[2], paragraph=2, section=sections[2]),
        ]

        analysis, enriched = analyze_document_structure(paragraphs, sections, candidates)

        self.assertEqual(analysis["research_stage"], "proposal")
        self.assertFalse(analysis["results_available"])
        self.assertIn("empirical_results", analysis["missing_information"])
        self.assertEqual(enriched[0].claim_status, "RESEARCH_OBJECTIVE")
        self.assertEqual(enriched[1].claim_status, "LITERATURE_FINDING")
        self.assertEqual(enriched[2].claim_status, "PROPOSED_METHOD")
        self.assertTrue(all(claim["source_sentence_ids"] for claim in analysis["claims"]))

    def test_proposal_plan_omits_results_and_unstated_conclusion(self):
        paragraphs = [
            "The study aims to examine whether price is related to purchase decisions.",
            "Researchers will use a correlational design with 100 participants.",
        ]
        sections = ["purpose", "methodology"]
        claims = [
            candidate(0, paragraphs[0], section="purpose"),
            candidate(1, paragraphs[1], paragraph=1, section="methodology"),
        ]
        analysis, claims = analyze_document_structure(paragraphs, sections, claims)
        plan = build_summary_plan(analysis, claims, "academic", "detailed")
        blocks = build_dynamic_summary_sections(claims, plan)
        labels = {block["label"] for block in blocks}

        self.assertIn("Proposed Methodology", labels)
        self.assertNotIn("Actual Findings", labels)
        self.assertNotIn("Conclusion", labels)

    def test_completed_paper_separates_actual_findings_from_methods(self):
        paragraphs = [
            "Researchers used a cross-sectional survey with 100 participants.",
            "Results showed a positive but non-significant association between price and purchase decisions.",
            "The authors conclude that a larger sample is needed to confirm the association.",
        ]
        sections = ["methodology", "results", "conclusion"]
        claims = [candidate(i, text, paragraph=i, section=sections[i]) for i, text in enumerate(paragraphs)]
        analysis, enriched = analyze_document_structure(paragraphs, sections, claims)
        plan = build_summary_plan(analysis, enriched, "academic", "balanced")

        self.assertEqual(analysis["research_stage"], "completed")
        self.assertTrue(analysis["results_available"])
        self.assertTrue(analysis["conclusion_available"])
        self.assertEqual(enriched[0].claim_status, "AUTHOR_CLAIM")
        self.assertEqual(enriched[1].claim_status, "ACTUAL_RESULT")
        self.assertIn("Actual Findings", {item["label"] for item in plan["planned_sections"]})

    def test_future_methods_do_not_become_completed_methods(self):
        claim_type, status = classify_claim(
            "Researchers will administer a questionnaire and will test the stated hypothesis.",
            "methodology",
        )
        self.assertEqual((claim_type, status), ("method", "PROPOSED_METHOD"))

    def test_research_question_and_hypothesis_have_distinct_statuses(self):
        self.assertEqual(classify_claim("Does price relate to purchase decisions?", "introduction")[1], "RESEARCH_QUESTION")
        self.assertEqual(classify_claim("The researchers hypothesize that quality predicts choice.", "introduction")[1], "HYPOTHESIS")

    def test_literature_findings_remain_attributed(self):
        _claim_type, status = classify_claim(
            "Previous studies reported a positive association between affordability and purchase decisions (Lee, 2021).",
            "introduction",
        )
        self.assertEqual(status, "LITERATURE_FINDING")

    def test_conflicting_significance_and_direction_are_not_deduplicated(self):
        positive = "Price had a significant positive association with purchase decisions among students."
        mixed = "Price had a non-significant association with purchase decisions among students."

        self.assertTrue(claims_are_contrasting(positive, mixed))
        self.assertFalse(sentences_are_redundant(positive, mixed))
        self.assertLessEqual(sentence_token_overlap(candidate(0, positive), candidate(1, mixed)), 0.50)

    def test_negation_and_qualifier_loss_is_flagged_by_fact_ledger(self):
        source = candidate(
            0,
            "The treatment may not significantly reduce symptoms in this group.",
            claim_status="ACTUAL_RESULT",
            claim_type="result",
        )
        report = validate_summary_facts(
            ["The treatment significantly reduces symptoms in this group."],
            build_fact_ledger([source]),
        )
        issues = {issue["issue"] for issue in report["issues"]}
        self.assertIn("possible_negation_omission", issues)
        self.assertIn("possible_qualifier_omission", issues)

    def test_source_guard_restores_proposed_method_after_tense_shift(self):
        source_text = "Researchers will administer a 5-point questionnaire to 120 participants and will compare their responses across the study groups."
        source = candidate(
            0,
            source_text,
            section="methodology",
            claim_type="method",
            claim_status="PROPOSED_METHOD",
            source_sentence_id="sentence-000001",
            section_id="section-001-methodology",
        )
        guarded, metadata = SummarizationPipeline()._guard_summary_sentences(
            ["Researchers administered a 5-point questionnaire to 120 participants and compared their responses across the study groups."],
            [source],
        )

        self.assertEqual(guarded, [source_text])
        self.assertEqual(metadata["sentences_restored_to_source"], 1)

    def test_summary_without_source_match_is_omitted(self):
        source = candidate(0, "Researchers will use Pearson correlation for the proposed analysis.", section="methodology")
        guarded, metadata = SummarizationPipeline()._guard_summary_sentences(
            ["The completed trial proved the treatment is effective for every patient."],
            [source],
        )
        self.assertEqual(guarded, [])
        self.assertEqual(metadata["sentences_omitted_without_source_match"], 1)

    def test_numbers_statistical_notation_and_plus_sign_survive_normalization(self):
        source = "The survey used a 5-point scale; significance was set at p < 0.05 and LGBTQ+ participants could select multiple responses."
        normalized = normalize_summary_sentence(source, preserve_statistical_details=True)
        self.assertIn("5-point", normalized)
        self.assertIn("p < 0.05", normalized)
        self.assertIn("LGBTQ+", normalized)

    def test_pdf_page_and_sentence_traceability_are_retained_when_available(self):
        text = "The intervention reduced symptoms by 27 percent after twelve weeks."
        source = candidate(0, text, page_number=None)
        analysis, enriched = analyze_document_structure(
            [text], ["results"], [source], page_texts=("First page has unrelated text.", text)
        )
        evidence = build_source_evidence([text], enriched)

        self.assertEqual(enriched[0].page_number, 2)
        self.assertEqual(evidence[0]["source_sentence_id"], "sentence-000001")
        self.assertEqual(evidence[0]["source_paragraph_id"], "paragraph-000001")
        self.assertEqual(evidence[0]["page"], 2)
        self.assertEqual(analysis["detected_structure"][0]["role"], "result")

    def test_document_types_use_content_structure_and_unknown_is_allowed(self):
        news, _news_candidates = analyze_document_structure(
            ["City officials said the bridge will reopen on Monday after repairs."],
            ["lead"],
            [],
        )
        technical, _technical_candidates = analyze_document_structure(
            ["The API endpoint reads configuration settings and validates database dependencies."],
            ["system_description"],
            [],
        )
        unknown, _unknown_candidates = analyze_document_structure(["A short note."], ["body"], [])
        self.assertEqual(news["document_type"], "news_article")
        self.assertEqual(technical["document_type"], "technical_document")
        self.assertEqual(unknown["document_type"], "unknown")
        self.assertEqual(unknown["document_type_confidence"], 0.0)

    def test_general_article_can_be_detected_without_filename_signals(self):
        paragraph = "The article describes how public libraries expanded access to digital collections. " * 15
        analysis, _candidates = analyze_document_structure([paragraph, paragraph, paragraph], ["body"] * 3, [])
        self.assertEqual(analysis["document_type"], "general_article")
        self.assertEqual(analysis["confidence_method"], "measured_structural_and_lexical_signal_share_not_calibrated_probability")

    def test_short_document_analysis_degrades_to_unknown_without_invented_structure(self):
        analysis, enriched = analyze_document_structure(["One short sentence."], ["body"], [candidate(0, "One short sentence.")])
        self.assertEqual(analysis["document_type"], "unknown")
        self.assertEqual(analysis["detected_structure"][0]["role"], "other")
        self.assertEqual(len(enriched), 1)

    def test_long_analysis_tracks_claims_without_pairwise_sentence_comparison(self):
        count = 1200
        paragraphs = [f"Paragraph {index} describes a distinct documented process and source observation." for index in range(count)]
        sections = ["body"] * count
        candidates = [candidate(index, paragraphs[index], paragraph=index) for index in range(count)]
        analysis, enriched = analyze_document_structure(paragraphs, sections, candidates)
        self.assertEqual(analysis["claim_count"], count)
        self.assertEqual(len(enriched), count)
        self.assertEqual(analysis["detected_structure"][0]["paragraph_ids"][0], "paragraph-000001")


if __name__ == "__main__":
    unittest.main()
