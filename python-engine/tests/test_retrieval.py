from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import patch

import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from summarizer_core import pipeline as pipeline_module
from summarizer_core.chunking import chunk_candidates
from summarizer_core.embeddings import EmbeddingOutput, embed_texts
from summarizer_core.models import SentenceCandidate, SummarizationPipeline, SummarizationRequest
from local_cli import handle_summarize
from summarizer_core.retrieval import (
    MAX_RETRIEVAL_SCORE_ADJUSTMENT,
    _retrieval_breadth,
    map_selected_evidence,
    retrieve_candidates,
    select_diverse_chunks,
)
from summarizer_core.retrieval_models import RetrievalChunk


def make_candidate(
    index: int,
    text: str,
    *,
    paragraph: int = 0,
    sentence: int | None = None,
    section: str = "body",
) -> SentenceCandidate:
    return SentenceCandidate(
        index=index,
        paragraph_index=paragraph,
        sentence_in_paragraph=index if sentence is None else sentence,
        paragraph_sentence_count=20,
        section=section,
        text=text,
        normalized_text=text.casefold(),
        ranking_text=text.casefold(),
        token_count=len(text.split()),
    )


def long_candidates() -> list[SentenceCandidate]:
    groups = [
        ("methods", "Researchers used a randomized clinical trial with 420 adult participants across six hospitals."),
        ("results", "The treatment reduced symptom severity by 31 percent after twelve weeks of follow-up."),
        ("limitations", "The study did not include children and follow-up ended after twelve weeks."),
        ("conclusion", "The authors recommend a larger trial before the treatment is adopted in routine care."),
    ]
    candidates: list[SentenceCandidate] = []
    for paragraph, (section, text) in enumerate(groups):
        for sentence in range(4):
            candidates.append(
                make_candidate(
                    len(candidates),
                    f"{text} The report provides detailed evidence about {section} and patient outcomes.",
                    paragraph=paragraph,
                    sentence=sentence,
                    section=section,
                )
            )
    return candidates


class RetrievalTests(unittest.TestCase):
    def test_chunking_preserves_candidate_mapping_and_overlap(self):
        candidates = [
            make_candidate(0, "First evidence sentence contains enough words to make a useful source excerpt here.", sentence=0),
            make_candidate(1, "Second evidence sentence contains enough words to make a useful source excerpt here.", sentence=1),
            make_candidate(2, "Third evidence sentence contains enough words to make a useful source excerpt here.", sentence=2),
        ]

        chunks = chunk_candidates(candidates, max_words=16, overlap_sentences=1)

        self.assertGreaterEqual(len(chunks), 2)
        self.assertEqual(chunks[0].candidate_indices[-1], chunks[1].candidate_indices[0])
        self.assertEqual(
            {candidate.index for chunk in chunks for candidate in candidates if candidate.index in chunk.candidate_indices},
            {candidate.index for candidate in candidates},
        )
        self.assertTrue(all(chunk.paragraph_index == 0 for chunk in chunks))

    def test_tfidf_fallback_is_local_and_produces_finite_scores(self):
        candidates = long_candidates()
        with patch.dict("os.environ", {}, clear=True):
            result = retrieve_candidates(
                candidates,
                analysis_mode="academic",
                summary_depth="detailed",
                document_title="Clinical trial findings",
                target_count=5,
                source_word_count=400,
                compression_bias="generous",
            )

        self.assertEqual(result.metadata["status"], "active")
        self.assertEqual(result.metadata["backend"], "tfidf")
        self.assertEqual(result.metadata["fallback_reason"], "local_model_not_configured")
        self.assertTrue(all(np.isfinite(result.score_adjustments)))
        self.assertTrue(all(abs(score) <= MAX_RETRIEVAL_SCORE_ADJUSTMENT for score in result.score_adjustments))

    def test_short_document_bypasses_embedding_backend(self):
        candidates = [make_candidate(0, "A brief source sentence has enough words for an extractive summary.")]
        with patch("summarizer_core.retrieval.embed_texts", side_effect=AssertionError("must bypass")):
            result = retrieve_candidates(
                candidates,
                analysis_mode="general",
                summary_depth="brief",
                document_title="",
                target_count=1,
                source_word_count=12,
                compression_bias="balanced",
            )

        self.assertEqual(result.metadata["status"], "bypassed")
        self.assertEqual(result.metadata["fallback_reason"], "short_document")
        self.assertEqual(result.score_adjustments, (0.0,))
        self.assertEqual(result.metadata["retrieved_sections"], [])
        self.assertEqual(result.metadata["evidence_mapping_status"], "unavailable")

    def test_retrieval_state_is_isolated_per_call(self):
        first = long_candidates()
        second = [
            make_candidate(
                index,
                text.replace("clinical", "astronomical").replace("patient", "stellar"),
                paragraph=candidate.paragraph_index,
                sentence=candidate.sentence_in_paragraph,
                section=candidate.section,
            )
            for index, candidate in enumerate(first)
            for text in [candidate.text]
        ]
        first_result = retrieve_candidates(
            first,
            analysis_mode="academic",
            summary_depth="balanced",
            document_title="Clinical trial",
            target_count=4,
            source_word_count=400,
            compression_bias="balanced",
        )
        second_result = retrieve_candidates(
            second,
            analysis_mode="academic",
            summary_depth="balanced",
            document_title="Astronomical research",
            target_count=4,
            source_word_count=400,
            compression_bias="balanced",
        )

        self.assertNotEqual(first_result.chunks[0].text, second_result.chunks[0].text)
        self.assertFalse(any("clinical" in chunk.text for chunk in second_result.chunks))
        self.assertFalse(any("clinical" in hit.chunk_id for hit in second_result.hits))

    def test_diverse_selection_prefers_distinct_sections_and_content(self):
        chunks = [
            RetrievalChunk("a", "clinical trial treatment result", 0, "results", (0,), 4),
            RetrievalChunk("b", "clinical trial treatment outcome", 1, "results", (1,), 4),
            RetrievalChunk("c", "budget implementation recommendation", 2, "recommendations", (2,), 3),
        ]
        vectorizer = TfidfVectorizer()
        vectors = vectorizer.fit_transform([chunk.text for chunk in chunks])

        selected = select_diverse_chunks(chunks, [0.99, 0.97, 0.76], vectors, breadth=2)

        self.assertEqual(len(selected), 2)
        self.assertIn(0, selected)
        self.assertIn(2, selected)

    def test_evidence_maps_output_to_source_chunk(self):
        candidates = long_candidates()
        result = retrieve_candidates(
            candidates,
            analysis_mode="academic",
            summary_depth="balanced",
            document_title="Clinical trial",
            target_count=4,
            source_word_count=400,
            compression_bias="balanced",
        )
        source_sentence = candidates[0].text

        evidence = map_selected_evidence(result, candidates, [source_sentence])

        self.assertEqual(len(evidence), 1)
        self.assertEqual(evidence[0]["source_sentence"], source_sentence)
        self.assertIn(source_sentence, evidence[0]["excerpt"])
        self.assertEqual(evidence[0]["paragraph_index"], candidates[0].paragraph_index)

    def test_evidence_deduplicates_shared_source_chunks(self):
        candidates = long_candidates()
        result = retrieve_candidates(
            candidates,
            analysis_mode="academic",
            summary_depth="balanced",
            document_title="Clinical trial",
            target_count=4,
            source_word_count=400,
            compression_bias="balanced",
        )

        evidence = map_selected_evidence(
            result,
            candidates,
            [candidates[0].text, candidates[1].text],
        )

        self.assertEqual(len(evidence), 1)
        self.assertEqual(evidence[0]["chunk_id"], "p0-c0")
        self.assertEqual(evidence[0]["summary_sentence"], candidates[0].text)
        self.assertIn(candidates[0].text, evidence[0]["supports"])
        self.assertIn(candidates[1].text, evidence[0]["supports"])

    def test_malformed_embedding_output_is_rejected(self):
        candidates = long_candidates()
        malformed = EmbeddingOutput(vectors=np.ones((1, 2)), backend="test")
        with patch("summarizer_core.retrieval.embed_texts", return_value=malformed):
            with self.assertRaisesRegex(ValueError, "malformed embedding"):
                retrieve_candidates(
                    candidates,
                    analysis_mode="general",
                    summary_depth="balanced",
                    document_title="Research",
                    target_count=4,
                    source_word_count=400,
                    compression_bias="balanced",
                )

    def test_retrieval_exception_preserves_existing_summary(self):
        request = SummarizationRequest(
            text=(
                "Researchers studied coastal flooding after repeated winter storms. "
                "The survey measured household damage and recovery time across four towns. "
                "Local officials approved new drainage improvements and emergency planning."
            ),
            summary_depth="brief",
        )
        with patch.object(pipeline_module, "retrieve_candidates", side_effect=RuntimeError("retrieval unavailable")):
            result = SummarizationPipeline().summarize(request)

        self.assertTrue(result.plain_summary)
        self.assertGreater(result.sentence_count, 0)
        self.assertEqual(result.retrieval_metadata["status"], "fallback")
        self.assertEqual(result.retrieval_metadata["fallback_reason"], "retrieval_error:RuntimeError")
        self.assertEqual(result.source_metadata["retrieval"], result.retrieval_metadata)
        self.assertEqual(len(result.evidence), result.sentence_count)
        self.assertTrue(all(item["retrieved"] is False for item in result.evidence))
        self.assertTrue(all(item.get("source_sentence_id") for item in result.evidence))

    def test_malformed_retrieval_result_falls_back_to_existing_summary(self):
        request = SummarizationRequest(
            text=(
                "Researchers studied coastal flooding after repeated winter storms. "
                "The survey measured household damage and recovery time across four towns. "
                "Local officials approved new drainage improvements and emergency planning."
            ),
            summary_depth="brief",
        )
        with patch.object(pipeline_module, "retrieve_candidates", return_value=object()):
            result = SummarizationPipeline().summarize(request)

        self.assertTrue(result.plain_summary)
        self.assertEqual(result.retrieval_metadata["status"], "fallback")
        self.assertEqual(result.retrieval_metadata["fallback_reason"], "retrieval_error:TypeError")

    def test_long_document_integrates_retrieval_and_evidence_additively(self):
        candidates = long_candidates()
        paragraphs = []
        for paragraph_index in range(4):
            paragraph_candidates = [
                candidate for candidate in candidates
                if candidate.paragraph_index == paragraph_index
            ]
            paragraphs.append(" ".join(candidate.text for candidate in paragraph_candidates))
        request = SummarizationRequest(
            text="\n\n".join(paragraphs),
            analysis_mode="academic",
            summary_depth="detailed",
        )

        result = SummarizationPipeline().summarize(request)

        self.assertEqual(result.retrieval_metadata["status"], "active")
        self.assertIn(result.retrieval_metadata["backend"], {"tfidf", "sentence_transformers_local"})
        self.assertGreater(result.retrieval_metadata["retrieved_chunk_count"], 0)
        self.assertTrue(result.sentences)
        self.assertTrue(result.evidence)
        self.assertIn("retrieval", result.source_metadata)
        self.assertEqual(result.source_metadata["evidence_count"], len(result.evidence))
        self.assertTrue(all(item["source_sentence"] for item in result.evidence))
        self.assertTrue(all(item["summary_sentence"] in result.sentences for item in result.evidence))
        self.assertTrue(all(isinstance(item["retrieved"], bool) for item in result.evidence))
        self.assertEqual(
            result.retrieval_metadata["evidence_mapping_status"],
            "available",
        )

    def test_worker_serializes_stable_result_evidence_and_coverage_contract(self):
        paragraphs = []
        for section, text in [
            ("methods", "Researchers used a randomized clinical trial with 420 adult participants across six hospitals."),
            ("results", "The treatment reduced symptom severity by 31 percent after twelve weeks of follow-up."),
            ("limitations", "The study did not include children and follow-up ended after twelve weeks."),
            ("conclusion", "The authors recommend a larger trial before the treatment is adopted in routine care."),
        ]:
            paragraphs.append(
                f"{section.title()}\n{text} "
                f"The report provides detailed evidence about {section} and patient outcomes. "
                f"Researchers documented a separate {section} observation for clinical decision makers. "
                "Investigators recorded clinical response, safety indicators, and participant follow-up "
                "intervals using a predefined observation protocol. Study coordinators reviewed outcome "
                "trends with physicians before recording the final interpretation for each participating "
                "clinical site. The report compares those observations with baseline measurements and "
                f"notes how the evidence informs subsequent care planning. The {section} review "
                "documented additional safeguards for reliable clinical interpretation."
            )
        result = handle_summarize({
            "text": "\n\n".join(paragraphs),
            "analysis_mode": "academic",
            "summary_depth": "detailed",
        })

        self.assertEqual(result["retrieval_metadata"], result["source_metadata"]["retrieval"])
        self.assertEqual(result["retrieval_metadata"], result["summary_method"]["retrieval"])
        self.assertEqual(result["source_metadata"]["evidence_count"], len(result["evidence"]))
        self.assertEqual(result["retrieval_metadata"]["status"], "active")
        self.assertTrue({
            "status",
            "backend",
            "fallback_reason",
            "chunk_count",
            "retrieved_chunk_count",
            "retrieval_breadth",
            "profile",
            "summary_depth",
            "semantic_score_adjustment_limit",
            "coverage_ratio",
            "retrieved_sections",
            "evidence_mapping_status",
        }.issubset(result["retrieval_metadata"]))
        self.assertTrue(all(
            {
                "summary_sentence",
                "source_sentence",
                "excerpt",
                "chunk_id",
                "paragraph_index",
                "section",
                "retrieved",
                "relevance_score",
            }.issubset(item)
            for item in result["evidence"]
        ))

    def test_retrieval_evidence_mapping_failure_falls_back_to_source_sentence_provenance(self):
        request = SummarizationRequest(
            text=(
                "Researchers studied coastal flooding after repeated winter storms. "
                "The survey measured household damage and recovery time across four towns. "
                "Local officials approved new drainage improvements and emergency planning."
            ),
            summary_depth="brief",
        )
        with patch(
            "summarizer_core.pipeline.map_selected_evidence",
            side_effect=RuntimeError("mapping unavailable"),
        ):
            result = SummarizationPipeline().summarize(request)

        self.assertTrue(result.plain_summary)
        self.assertEqual(len(result.evidence), result.sentence_count)
        self.assertTrue(all(item["retrieved"] is False for item in result.evidence))
        self.assertEqual(result.retrieval_metadata["evidence_mapping_status"], "available")
        self.assertTrue(all("excerpt" in item and "source_sentence_id" in item for item in result.evidence))
        self.assertEqual(result.source_metadata["retrieval"], result.retrieval_metadata)

    def test_retrieval_breadth_uses_depth_and_profile_compression(self):
        brief_tight = _retrieval_breadth(4, "brief", "tight", 40)
        detailed_generous = _retrieval_breadth(4, "detailed", "generous", 40)

        self.assertLess(brief_tight, detailed_generous)

    def test_tfidf_embedding_output_contract(self):
        with patch.dict("os.environ", {}, clear=True):
            output = embed_texts(["local evidence", "local source"])
        self.assertEqual(output.backend, "tfidf")
        self.assertEqual(output.vectors.shape[0], 2)


if __name__ == "__main__":
    unittest.main()
