"""Cheap 8 x 5 x 6 contracts over real mode, depth and evidence renderers.

These tests reuse annotated evidence. They intentionally do not represent 240
full-document NLP or browser runs; representative end-to-end runs cover those.
"""
from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import summarizer_core.pipeline as pipeline_module
from summarizer_core.constants import DEPTH_CONFIG
from summarizer_core.document_analysis import build_dynamic_summary_sections, build_summary_plan
from summarizer_core.models import SentenceCandidate, SummarizationRequest
from summarizer_core.fact_ledger import FactualityValidator
from summarizer_core.pipeline import SUPPORTED_OUTPUT_FORMATS, SummarizationPipeline
from summarizer_core.profiles import SUPPORTED_ANALYSIS_MODES, resolve_profile


MODES = SUPPORTED_ANALYSIS_MODES
FORMATS = SUPPORTED_OUTPUT_FORMATS
DEPTHS = tuple(DEPTH_CONFIG)

EVIDENCE = (
    ("background", "background", "The regional project examined transportation reliability across three cities."),
    ("objectives", "research_objective", "Researchers aimed to measure whether new traffic signals improved journey times."),
    ("definitions", "definition", "Reliability means that passengers can predict when each bus will arrive."),
    ("methods", "method", "Engineers installed synchronized controllers with encrypted network connections."),
    ("results", "result", "The study measured a 12 percent improvement among 240 monitored journeys."),
    ("decision", "decision", "The council approved a $2 million budget for the second deployment stage."),
    ("limitations", "limitation", "The observational design cannot establish that the signals caused the improvement."),
    ("risks", "risk", "Network outages could interrupt coordination unless backup systems remain available."),
    ("conclusion", "conclusion", "The results suggest that coordinated signals may improve transport reliability."),
    ("recommendations", "recommendation", "Operators should publish independent measurements before expanding the program."),
    ("compliance", "method", "Under section 12, operators must retain maintenance records for five years."),
    ("results", "result", "Officials announced the findings on Monday after the independent assessment ended."),
)


def annotated_candidates() -> list[SentenceCandidate]:
    return [
        SentenceCandidate(
            index=index, paragraph_index=index, sentence_in_paragraph=0,
            paragraph_sentence_count=1, section=section, text=text,
            normalized_text=text, ranking_text=text.lower(), token_count=len(text.split()),
            claim_type=claim, source_role=claim,
            claim_status="ACTUAL_RESULT" if claim == "result" else "UNKNOWN",
        )
        for index, (section, claim, text) in enumerate(EVIDENCE)
    ]


def check_configuration_contract(mode: str, output_format: str, depth: str) -> None:
    pipeline = SummarizationPipeline()
    request = SummarizationRequest(analysis_mode=mode, output_format=output_format, summary_depth=depth)
    profile = resolve_profile(request.analysis_mode)
    assert profile.mode == mode
    assert profile.scoring_weights
    assert pipeline._resolve_output_format(request.output_format, request.summary_style) == output_format
    config = DEPTH_CONFIG[request.summary_depth]
    count, budget = pipeline._calculate_dynamic_target(
        source_words=3600, candidate_count=120, detected_topics_count=12,
        detected_sections_count=8, depth_key=depth, depth_config=config,
        profile_compression_bias=profile.compression_bias,
    )
    assert config["min_sentences"] <= count <= config["max_sentences"]
    assert budget >= config["min_words"]
    candidates = annotated_candidates()
    evidence = candidates[:min(count, len(candidates))]
    sentences = [candidate.text for candidate in evidence]
    plan = build_summary_plan({"results_available": True, "conclusion_available": True}, candidates, mode, depth)
    assert (plan["mode"], plan["depth"]) == (mode, depth)
    overview = pipeline._build_overview(sentences, "", "", "", depth)
    assert len(overview) <= (1 if depth in {"very_brief", "brief"} else 2 if depth in {"short", "balanced"} else 3 if depth == "detailed" else 4)

    profile_passed, _ = pipeline._validate_against_profile(
        sentences, evidence, profile, " ".join(candidate.text for candidate in candidates)
    )
    assert profile_passed
    guarded, factuality_report = FactualityValidator.validate_and_repair(sentences, evidence)
    assert guarded == sentences
    assert factuality_report["status"] == "validated_and_repaired"

    # Formatting must consume the evidence contract, with no source reload,
    # sentence segmentation, model calls, or repeat candidate generation.
    with (
        patch.object(pipeline_module, "load_source_document", side_effect=AssertionError("source reloaded")),
        patch.object(pipeline_module, "safe_sent_tokenize", side_effect=AssertionError("source segmented")),
        patch.object(pipeline, "_build_sentence_candidates", side_effect=AssertionError("evidence regenerated")),
        patch("summarizer_core.embeddings.embed_texts", side_effect=AssertionError("format embedded text")),
    ):
        text, bullets = pipeline._build_plain_summary(
            sentences,
            output_format,
            takeaway_sentences=([*overview[:1], *sentences[-1:]] if output_format == "key_takeaways" else None),
            max_takeaways=2,
        )
        structured = build_dynamic_summary_sections(evidence, plan)
    assert text
    assert all(sentence.endswith(".") for sentence in sentences)
    if output_format == "bullets":
        assert bullets == sentences
    elif output_format == "key_takeaways":
        assert bullets
        assert len(bullets) <= 2
        assert text == " ".join(bullets)
    elif output_format == "hybrid":
        split = 2 if len(sentences) > 3 else 1
        assert text == " ".join(sentences[:split])
        assert bullets == sentences[split:]
    else:
        assert text == " ".join(sentences)
        assert bullets == []
    if output_format == "structured":
        assert structured
        assert all(block["label"] and block["text"] for block in structured)


def check_depths_keep_distinct_information_budgets() -> None:
    pipeline = SummarizationPipeline()
    budgets = [
        pipeline._calculate_dynamic_target(3600, 120, 12, 8, depth, DEPTH_CONFIG[depth], "balanced")
        for depth in DEPTHS
    ]
    assert [count for count, _ in budgets] == sorted({count for count, _ in budgets})
    assert [words for _, words in budgets] == sorted({words for _, words in budgets})


def check_modes_keep_distinct_evidence_rules() -> None:
    signatures = {
        (tuple(sorted(profile.scoring_weights.items())), tuple(profile.preferred_sections),
         tuple((pattern.pattern, value) for pattern, value in profile.importance_bonuses))
        for profile in (resolve_profile(mode) for mode in MODES)
    }
    assert len(signatures) == len(MODES)


class TestConfigurationContracts(unittest.TestCase):
    def test_depth_budgets(self):
        check_depths_keep_distinct_information_budgets()

    def test_mode_evidence_rules(self):
        check_modes_keep_distinct_evidence_rules()

    def test_key_takeaways_select_a_distinct_supported_subset(self):
        pipeline = SummarizationPipeline()
        sentences = [
            "The system uses encrypted transport between its services.",
            "The system requires a verified certificate before deployment.",
            "The deployment supports 240 concurrent connections.",
        ]

        takeaways, takeaway_bullets = pipeline._build_plain_summary(
            sentences,
            "key_takeaways",
            takeaway_sentences=[sentences[1], sentences[2]],
            max_takeaways=2,
        )

        self.assertEqual(takeaway_bullets, sentences[1:])
        self.assertEqual(takeaways, " ".join(sentences[1:]))
        self.assertNotEqual(takeaway_bullets, sentences)
        self.assertTrue(all(item in sentences for item in takeaway_bullets))

    def test_key_takeaways_remain_a_subset_after_final_validation(self):
        source = " ".join(text for _, _, text in EVIDENCE)
        result = SummarizationPipeline().summarize(SummarizationRequest(
            text=source,
            analysis_mode="general",
            summary_depth="comprehensive",
            output_format="key_takeaways",
        ))

        self.assertEqual(result.output_format, "key_takeaways")
        self.assertGreater(len(result.sentences), len(result.overall_summary_bullets))
        self.assertLessEqual(
            len(result.overall_summary_bullets),
            DEPTH_CONFIG["comprehensive"]["key_points_range"][1],
        )
        self.assertTrue(all(item in result.sentences for item in result.overall_summary_bullets))
        self.assertTrue(result.key_points)
        self.assertTrue(any(item in result.key_points for item in result.overall_summary_bullets))

    def test_hybrid_readability_counts_overview_and_supporting_bullets(self):
        source = " ".join(text for _, _, text in EVIDENCE)
        pipeline = SummarizationPipeline()
        result = pipeline.summarize(SummarizationRequest(
            text=source,
            analysis_mode="general",
            summary_depth="comprehensive",
            output_format="hybrid",
        ))
        visible_text = " ".join([result.plain_summary, *result.overall_summary_bullets])

        self.assertEqual(result.output_format, "hybrid")
        self.assertTrue(result.plain_summary)
        self.assertTrue(result.overall_summary_bullets)
        self.assertEqual(
            result.readability["summary_word_count"],
            pipeline._count_words(visible_text),
        )

    def test_document_title_is_not_merged_into_summary_prose(self):
        source = (
            "System Reliability and Recovery\n\n"
            "Abstract\n"
            "This report describes a service that retries failed requests after a network interruption. "
            "The service stores each completed request in a durable queue. "
            "Operators can configure the retry interval from five to thirty seconds. "
            "A failed worker returns unfinished messages to the queue. "
            "The design prevents a second confirmation from being sent for the same request. "
            "The report recommends monitoring queue depth during deployment."
        )
        result = SummarizationPipeline().summarize(SummarizationRequest(
            text=source,
            analysis_mode="technical",
            summary_depth="balanced",
            output_format="paragraph",
        ))

        self.assertEqual(result.title, "System Reliability and Recovery")
        self.assertTrue(result.sentences)
        self.assertTrue(all(not sentence.startswith(result.title) for sentence in result.sentences))

    def test_invalid_explicit_settings_are_rejected_before_source_processing(self):
        pipeline = SummarizationPipeline()
        invalid_requests = (
            SummarizationRequest(text="source text", analysis_mode="unsupported"),
            SummarizationRequest(text="source text", output_format="unsupported"),
            SummarizationRequest(text="source text", summary_depth="unsupported"),
        )
        for request in invalid_requests:
            with self.subTest(request=request), patch.object(
                pipeline_module, "load_source_document", side_effect=AssertionError("source processing started")
            ):
                with self.assertRaises(ValueError) as raised:
                    pipeline.summarize(request)
                self.assertEqual(raised.exception.failure_code, "SETTINGS_INVALID")

    def test_very_brief_overview_is_one_sentence(self):
        pipeline = SummarizationPipeline()
        sentences = [
            "The system uses encrypted transport between its services.",
            "The system requires a verified certificate before deployment.",
            "The deployment supports 240 concurrent connections.",
        ]

        overview = pipeline._build_overview(sentences, "", "", "", "very_brief")

        self.assertEqual(overview, sentences[:1])


def _configuration_test(mode, output_format, depth):
    def run(self):
        check_configuration_contract(mode, output_format, depth)
    return run


for _mode in MODES:
    for _format in FORMATS:
        for _depth in DEPTHS:
            setattr(TestConfigurationContracts, f"test_{_mode}_{_format}_{_depth}",
                    _configuration_test(_mode, _format, _depth))


if __name__ == "__main__":
    unittest.main()
