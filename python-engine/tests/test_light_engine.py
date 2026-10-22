from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

ENGINE_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ENGINE_ROOT))

from light_engine import ContractError, summarize
from light_engine.content_type import ContentTypeAnalysis
from light_engine.contract import CONTRACT_VERSION, SummaryRequest
from light_engine.extraction import SourceSentence, load_document, split_sentences
from light_engine.selection import select_sentences
from light_engine.validation import validate_candidates
from evaluation.compare_engines import _OPENER


def request(text: str, **overrides):
    payload = {
        "schema_version": CONTRACT_VERSION,
        "text": text,
        "analysis_mode": "general",
        "summary_depth": "balanced",
        "output_format": "paragraph",
        "sentence_count": 8,
        "preprocessing": {},
    }
    payload.update(overrides)
    return payload


class LightEngineTests(unittest.TestCase):
    def test_focus_query_changes_v2_selection_without_affecting_unfocused_ranking(self):
        texts = [
            (
                "Public transit funding and regional transportation "
                "planning shape city budgets."
            ),
            "Regional transportation planning also coordinates annual service routes.",
            "The policy did not reduce costs.",
        ]
        candidates = [
            SourceSentence(index, text, 0, len(text), index, "Document")
            for index, text in enumerate(texts)
        ]
        content_type = ContentTypeAnalysis("general", 0.0, "test", (), {})

        def choose(focus_query: str) -> SourceSentence:
            parsed_request = SummaryRequest.parse(
                request(
                    " ".join(texts),
                    focus_query=focus_query,
                    summary_depth="very_brief",
                    sentence_count=1,
                )
            )
            with patch(
                "light_engine.selection.encode_sentences",
                return_value=(None, "disabled_test"),
            ):
                selected, _, _ = select_sentences(
                    candidates,
                    parsed_request,
                    sum(len(text.split()) for text in texts),
                    content_type,
                )
            return selected[0]

        self.assertEqual(choose("").text, texts[0])
        self.assertEqual(
            choose("Why did the policy not reduce costs?").text,
            texts[2],
        )

    def test_focus_query_uses_section_heading_as_context_in_v2_selection(self):
        candidates = [
            SourceSentence(
                0,
                "The committee reviewed the figures at its regular meeting.",
                0,
                59,
                0,
                "Document",
            ),
            SourceSentence(
                1,
                "The cap was 5 percent.",
                60,
                83,
                1,
                "Annual Increase Limit",
            ),
        ]
        parsed_request = SummaryRequest.parse(
            request(
                "The committee reviewed the figures at its regular meeting. "
                "The cap was 5 percent.",
                focus_query="What was the annual increase limit?",
                summary_depth="very_brief",
                sentence_count=1,
            )
        )
        with patch(
            "light_engine.selection.encode_sentences",
            return_value=(None, "disabled_test"),
        ):
            selected, _, _ = select_sentences(
                candidates,
                parsed_request,
                16,
                ContentTypeAnalysis("general", 0.0, "test", (), {}),
            )

        self.assertEqual(selected[0].text, "The cap was 5 percent.")

    def test_response_sentences_are_exact_source_excerpts_with_provenance(self):
        source = (
            "Urban heat affects public health in dense cities. "
            "Researchers compared temperature records from 1990 to 2020. "
            "The study found that nighttime temperatures rose by 2.4 degrees. "
            "The authors said more research is needed to assess health effects."
        )
        result = summarize(request(source, summary_depth="comprehensive"))
        self.assertGreater(result["sentence_count"], 0)
        for sentence, evidence in zip(result["sentences"], result["evidence"], strict=True):
            self.assertIn(sentence, source)
            self.assertEqual(evidence["excerpt"], sentence)
            self.assertEqual(evidence["supports"], sentence)
            self.assertEqual(len(evidence["source_sentence_indices"]), 1)
        self.assertEqual(result["source_metadata"]["engine"], "light_nlp")
        self.assertTrue(result["source_metadata"]["semantic_model_status"])
        self.assertIn(
            result["source_metadata"]["grammar_diagnostics"]["status"],
            {"checked", "unavailable"},
        )

    def test_candidate_validation_rejects_source_offset_mismatch(self):
        source = "The new transit route begins on Monday."
        candidate = SourceSentence(
            index=0,
            text="The route begins on Tuesday.",
            start=0,
            end=len(source),
            paragraph_index=0,
            section="Document",
        )
        with self.assertRaisesRegex(ContractError, "not an exact source excerpt"):
            validate_candidates([candidate], source)

    def test_candidate_validation_records_local_grammar_diagnostics(self):
        source = "The new transit route begins on Monday."
        candidate = SourceSentence(
            index=0,
            text=source,
            start=0,
            end=len(source),
            paragraph_index=0,
            section="Document",
        )
        checker = Mock(available=True)
        checker.check.return_value = [{"rule_id": "STYLE_RULE"}]
        with patch("light_engine.validation._local_grammar_checker", return_value=checker):
            source_guard, diagnostics = validate_candidates([candidate], source)

        self.assertEqual(source_guard["status"], "exact_source_validated")
        self.assertEqual(diagnostics["status"], "checked")
        self.assertEqual(diagnostics["issue_count"], 1)
        self.assertEqual(diagnostics["issues_by_sentence"][0]["rule_ids"], ["STYLE_RULE"])
        self.assertEqual(candidate.text, source)
        checker.close.assert_called_once_with()

    def test_article_title_is_not_selected_as_a_summary_sentence(self):
        source = (
            "Harbor Bridge Closed After Inspectors Find Cable Corrosion\n\n"
            "The Harbor Street Bridge was closed after state inspectors found corrosion. "
            "Officials said the closure was a precaution while engineers assess the damage. "
            "The department has not said how long repairs will take."
        )
        result = summarize(request(source, summary_depth="comprehensive"))
        self.assertNotIn("Harbor Bridge Closed", " ".join(result["sentences"]))
        self.assertEqual(result["title"], "Harbor Bridge Closed After Inspectors Find Cable Corrosion")

    def test_dangling_opener_check_does_not_flag_a_complete_study_sentence(self):
        self.assertIsNone(_OPENER.search("This study examined whether the treatment reduced risks."))
        self.assertIsNotNone(_OPENER.search("These findings were reported in the next section."))

    def test_research_structure_and_findings_inform_document_type(self):
        source = (
            "A study of urban heat and public health.\n\n"
            "Abstract\nResearchers measured nighttime temperatures in 20 cities. "
            "The sample included 400 neighborhoods.\n\n"
            "Methods\nThe team compared temperature and health records from 2010 to 2020. "
            "Participants were selected using a standard protocol.\n\n"
            "Results\nNighttime temperatures increased by 2.4 degrees. "
            "The association was not statistically significant."
        )
        result = summarize(request(source, summary_depth="comprehensive"))
        analysis = result["source_metadata"]["document_analysis"]
        self.assertEqual(analysis["document_type"], "research")
        self.assertEqual(analysis["confidence_method"], "heading_and_language_cues")
        self.assertIn("2.4 degrees", " ".join(result["sentences"]))
        self.assertEqual(result["source_metadata"]["quality_selection"]["selection_profile"], "research")

    def test_unblanked_heading_lines_are_preserved_as_document_structure(self):
        source = (
            "Regional Water Assessment\n\n"
            "Introduction\nThe authority supplies water to 640,000 residents. "
            "The report assesses drought resilience.\n\n"
            "Demand Forecast\nDaily demand may reach 268 million liters by 2040. "
            "The forecast assumes conservation continues."
        )
        result = summarize(request(source, summary_depth="comprehensive"))
        analysis = result["source_metadata"]["document_analysis"]
        self.assertIn("introduction", analysis["section_counts"])
        self.assertIn("demand forecast", analysis["section_counts"])
        self.assertIn("268 million liters", " ".join(result["sentences"]))

    def test_news_and_technical_articles_use_distinct_detected_profiles(self):
        news = (
            "Harbor Bridge Closure\n\n"
            "The bridge was closed after inspectors found cable corrosion. "
            "Transportation officials said the closure was a precaution. "
            "The mayor announced three additional bus routes."
        )
        technical = (
            "Order Queue Migration\n\n"
            "Proposed Architecture\nThe checkout service publishes a message queue event. "
            "A consumer group processes each idempotency key. "
            "Workers acknowledge messages after the database transaction completes."
        )
        news_result = summarize(request(news, summary_depth="comprehensive"))
        technical_result = summarize(request(technical, summary_depth="comprehensive"))
        self.assertEqual(
            news_result["source_metadata"]["document_analysis"]["document_type"],
            "news",
        )
        self.assertEqual(
            technical_result["source_metadata"]["document_analysis"]["document_type"],
            "technical",
        )
        self.assertEqual(
            technical_result["source_metadata"]["quality_selection"]["selection_profile"],
            "technical",
        )

    def test_business_report_and_opinion_content_are_not_forced_into_research_profile(self):
        business = (
            "Regional Investment Assessment\n\n"
            "Key Findings\nOperating costs increased by 8 percent during the year. "
            "Revenue remained stable across the same period.\n\n"
            "Recommendations\nThe board should prioritize maintenance investment. "
            "The report recommends delaying expansion until costs are reviewed."
        )
        opinion = (
            "Why Public Libraries Still Matter\n\n"
            "Opinion\nI argue that local libraries remain essential public institutions. "
            "In my view, access to shared learning resources benefits every neighborhood. "
            "The case for libraries rests on both education and community support."
        )
        business_result = summarize(request(business, summary_depth="comprehensive"))
        opinion_result = summarize(request(opinion, summary_depth="comprehensive"))
        self.assertEqual(
            business_result["source_metadata"]["document_analysis"]["document_type"],
            "business_report",
        )
        self.assertEqual(
            opinion_result["source_metadata"]["document_analysis"]["document_type"],
            "opinion",
        )

    def test_explicit_user_mode_controls_selection_without_overriding_detection(self):
        source = (
            "A study of urban heat.\n\n"
            "Results\nResearchers measured temperatures in 20 cities. "
            "The temperature changed by 2.4 degrees. "
            "The analysis used a statistical model."
        )
        result = summarize(request(
            source,
            analysis_mode="technical",
            summary_depth="comprehensive",
        ))
        selection = result["source_metadata"]["quality_selection"]
        self.assertEqual(selection["detected_content_type"], "research")
        self.assertEqual(selection["selection_profile"], "technical")
        self.assertEqual(selection["selection_profile_source"], "user")

    def test_generic_article_with_low_type_confidence_uses_general_strategy(self):
        source = (
            "City gardens offer a useful way to bring neighbors together. "
            "Residents share tools and exchange ideas about growing food. "
            "Local groups say the gardens also create places for community events. "
            "Several neighborhoods are now planning new plots."
        )
        result = summarize(request(source))
        selection = result["source_metadata"]["quality_selection"]
        self.assertEqual(selection["selection_profile"], "general")
        self.assertEqual(selection["selection_profile_source"], "general_fallback")

    def test_depth_and_output_format_are_applied(self):
        source = " ".join(
            f"The report states that program {index} delivered a measurable outcome in 2025."
            for index in range(1, 16)
        )
        brief = summarize(request(source, summary_depth="very_brief", sentence_count=50))
        detailed = summarize(request(source, summary_depth="detailed", sentence_count=50))
        bullets = summarize(request(source, summary_depth="balanced", output_format="bullets"))
        self.assertLessEqual(brief["sentence_count"], detailed["sentence_count"])
        self.assertTrue(bullets["plain_summary"].startswith("• "))
        self.assertEqual(bullets["output_format"], "bullets")
        self.assertEqual(len(bullets["overall_summary_bullets"]), bullets["sentence_count"])

    def test_each_supported_output_format_has_a_valid_result_shape(self):
        from light_engine.contract import OUTPUT_FORMATS

        source = (
            "Service operations use a message queue. "
            "Workers acknowledge messages after processing completes. "
            "The migration reduces repeated database polling."
        )
        results = {
            output_format: summarize(request(
                source,
                summary_depth="comprehensive",
                output_format=output_format,
            ))
            for output_format in OUTPUT_FORMATS
        }
        self.assertTrue(results["paragraph"]["plain_summary"])
        self.assertTrue(results["bullets"]["plain_summary"].startswith("• "))
        self.assertIn("\n\n• ", results["hybrid"]["plain_summary"])
        self.assertTrue(results["structured"]["structured_summary"])
        self.assertTrue(results["key_takeaways"]["overall_summary_bullets"])

    def test_invalid_contract_and_settings_fail_explicitly(self):
        with self.assertRaisesRegex(ContractError, "Unsupported summarization request schema"):
            summarize({"schema_version": 99, "text": "Some source text."})
        with self.assertRaisesRegex(ContractError, "Unsupported analysis mode"):
            summarize(request("A source sentence with useful content.", analysis_mode="invalid"))
        with self.assertRaisesRegex(ContractError, "Language-model synthesis is unavailable"):
            summarize(request("A source sentence with useful content.", use_llm_synthesis=True))
        with self.assertRaisesRegex(ContractError, "Target word budget"):
            summarize(request("A source sentence with useful content.", target_word_budget=-1))

    def test_requested_target_word_budget_caps_selected_sentence_words(self):
        source = " ".join(
            f"Report section {index} describes measured results from the annual environmental review."
            for index in range(1, 13)
        )
        result = summarize(request(
            source,
            summary_depth="comprehensive",
            target_word_budget=24,
        ))
        self.assertLessEqual(len(" ".join(result["sentences"]).split()), 24)

    def test_sentence_offsets_point_to_exact_source(self):
        source = "The first sentence has useful detail. Another sentence preserves 18% exactly."
        for sentence in split_sentences(source):
            self.assertEqual(source[sentence.start:sentence.end], sentence.text)

    def test_state_abbreviation_does_not_split_parenthetical_sentence(self):
        source = (
            "The Sheyenne River near Kindred, N. Dak. "
            "(USGS station 05059000) had the largest range in sediment concentrations."
        )

        sentences = split_sentences(source)

        self.assertEqual([sentence.text for sentence in sentences], [source])
        self.assertEqual(source[sentences[0].start:sentences[0].end], sentences[0].text)

    def test_inline_section_heading_does_not_hide_document_definition(self):
        source = (
            "Introduction | An outlier is an observation that appears to deviate markedly from other observations in the sample. "
            "Identification of potential outliers is important for the following reasons.\n\n"
            "1. An outlier may indicate bad data. For example, the data may have been coded incorrectly or an experiment "
            "may not have been run correctly. If it can be determined that an outlying point is in fact erroneous, then "
            "the outlying value should be deleted from the analysis (or corrected if possible).\n\n"
            "2. In some cases, it may not be possible to determine if an outlying point is bad data. Outliers may be due "
            "to random variation or may indicate something scientifically interesting. In any event, we typically do not "
            "want to simply delete the outlying observation. However, if the data contains significant outliers, we may "
            "need to consider the use of robust statistical techniques."
        )

        result = summarize(request(
            source,
            analysis_mode="technical",
            summary_depth="comprehensive",
            output_format="bullets",
        ))

        self.assertTrue(any(
            sentence.startswith("An outlier is an observation")
            for sentence in result["sentences"]
        ))

    def test_file_path_must_be_inside_configured_upload_root(self):
        with tempfile.TemporaryDirectory() as temporary:
            outside = Path(temporary) / "outside.docx"
            outside.write_bytes(b"not-a-document")
            with patch.dict("os.environ", {"SUMMARIZER_UPLOADS_DIR": str(Path(temporary) / "allowed")}):
                with self.assertRaisesRegex(ContractError, "outside the upload directory"):
                    load_document("", str(outside))

    def test_server_owned_temporary_pdf_root_is_allowed_when_configured(self):
        with tempfile.TemporaryDirectory() as temporary:
            upload_root = Path(temporary) / "uploads"
            temporary_root = Path(temporary) / "tmp"
            upload_root.mkdir()
            temporary_root.mkdir()
            outside = Path(temporary) / "untrusted.docx"
            outside.write_bytes(b"not-a-document")
            with patch.dict("os.environ", {
                "SUMMARIZER_UPLOADS_DIR": str(upload_root),
                "SUMMARIZER_TMP_DIR": str(temporary_root),
            }):
                with self.assertRaisesRegex(ContractError, "outside the upload directory"):
                    load_document("", str(outside))

    def test_docx_extraction_keeps_paragraphs_and_section_provenance(self):
        from docx import Document

        with tempfile.TemporaryDirectory() as temporary:
            upload_root = Path(temporary) / "uploads"
            upload_root.mkdir()
            path = upload_root / "research.docx"
            document = Document()
            document.add_heading("Results", level=1)
            document.add_paragraph("The annual yield increased by 12 percent.")
            document.save(str(path))
            with patch.dict("os.environ", {"SUMMARIZER_UPLOADS_DIR": str(upload_root)}):
                result = summarize(request("", file_path=str(path), summary_depth="comprehensive"))
            self.assertEqual(result["source_metadata"]["source_type"], "docx")
            self.assertEqual(result["sentences"], ["The annual yield increased by 12 percent."])
            self.assertEqual(result["evidence"][0]["section"], "Results")

    def test_corrupt_pdf_fails_as_a_document_error(self):
        with tempfile.TemporaryDirectory() as temporary:
            upload_root = Path(temporary) / "uploads"
            upload_root.mkdir()
            path = upload_root / "corrupt.pdf"
            path.write_bytes(b"%PDF-not-a-valid-document")
            with patch.dict("os.environ", {"SUMMARIZER_UPLOADS_DIR": str(upload_root)}):
                with self.assertRaisesRegex(ContractError, "PDF parser rejected malformed data") as raised:
                    summarize(request("", file_path=str(path)))
            self.assertEqual(raised.exception.failure_code, "FILE_CORRUPTED")

    def test_short_comprehensive_source_preserves_qualifications_and_numeric_claims(self):
        source = (
            "The annual rainfall trend was not statistically significant. "
            "Spring precipitation declined by 11.8 millimeters per decade. "
            "The study did not establish that climate change caused the decline."
        )
        result = summarize(request(source, summary_depth="comprehensive"))
        self.assertEqual(len(result["sentences"]), 3)
        self.assertIn("not statistically significant", " ".join(result["sentences"]))
        self.assertIn("11.8 millimeters per decade", " ".join(result["sentences"]))
        self.assertIn("did not establish", " ".join(result["sentences"]))


if __name__ == "__main__":
    unittest.main()
