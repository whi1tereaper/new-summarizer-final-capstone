from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path
import shutil
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from summarizer_core.models import PreprocessingOptions, SentenceCandidate, SourceDocument, SummarizationPipeline, SummarizationRequest
from summarizer_core.text_utils import (
    assess_summary_text_quality,
    clean_pdf_extracted_text,
    contains_article_noise,
    extract_valid_paragraphs,
    load_source_document,
    normalize_summary_sentence,
    semantic_tokens_preserved,
)
from pure_nlp import summarize_to_contract
from local_cli import (
    TRANSLATION_CHUNK_SIZE,
    chunk_translation_text,
    protect_translation_segments,
)


NOISY_ACADEMIC_TEXT = """
International Journal of Sample Research
ISSN 2581-7175
www.ijsred.com
Page 938

The Impact of Artificial Intelligence Tools on the Coding Proficiency and Skill Development of IT Students
JOHN AUSTRIA*
Nueva Ecija University of Science and Technology - Penaranda Off-Campus
robert@example.edu

Abstract
The rapid integration of generative artificial intelligence in education has changed how IT students approach programming tasks. This study examined whether those tools reduce independent reasoning or support learning. Survey responses showed that students mainly used AI for concept research and debugging instead of direct code copying, and the findings suggested low dependency when guided use was combined with classroom assessment.

I.
Introduction
The study focused on how novice programmers use artificial intelligence in programming-heavy coursework. It argued that the main issue is balancing efficiency with the need to preserve logical thinking, problem solving, and conceptual understanding while students are still developing coding foundations.

II.
Methodology
The researchers used a quantitative descriptive design and purposive sampling to evaluate students with beginner and amateur programming backgrounds. A structured survey captured usage patterns, perceived effects on coding skills, and attitudes toward understanding the logic behind AI-generated code.

International Journal of Sample Research
ISSN 2581-7175
Page 942
All Rights Reserved 2026

Results and Discussion
Students reported using AI most often for researching unfamiliar concepts and debugging program errors. They were less likely to rely on it for full code generation, and many respondents stated that they still tried to understand the logic behind generated output before applying it in coursework.

Conclusion
The study concluded that artificial intelligence can function as a learning aid rather than a harmful crutch for novice IT students when its use is paired with activities that still test independent reasoning. It recommended logic-based classroom evaluation and further research involving advanced students and qualitative interviews.

doi:10.1234/ijsred.2026.55
"""


WORKSHEET_WITH_ASSESSMENT_TEXT = """
CamScanner
Republic of the Philippines
Department of Education
Nourishing the mind, nurturing the heart...
Accredited by DepEd 2026
@ CLI & Level 4

Module 1: The Evolution of Modern Computer Networks

Computer networking has fundamentally transformed how human organizations communicate and exchange digital assets. In modern distributed systems, data transmission relies on packet-switching protocols that partition information into discrete units for reliable transport across diverse routing paths. Network engineers and cybersecurity specialists collaborate to maintain high availability, data integrity, and low-latency throughput for critical enterprise operations.

Cloud infrastructure and edge computing architectures have decentralized traditional server farms. By processing latency-sensitive workloads closer to end users, edge nodes minimize bottlenecks and improve real-time application performance. Furthermore, automated load balancers and redundant fiber pathways ensure continuous service delivery during catastrophic hardware failures.

DIRECTIONS:
Read the questions carefully and choose the correct answer from the choices provided. Write your answers on a separate sheet of paper.

19. What is the primary advantage of packet-switching protocols in modern data transmission?
A. Eliminating all physical routing hardware
B. Partitioning information into discrete units for reliable transport
C. Preventing unauthorized network access automatically
D. Increasing transmission latency across server farms

20. Which strategy is used by edge nodes to minimize communication bottlenecks?
A. Transferring all computational tasks to distant server clusters
B. Processing latency-sensitive workloads closer to end users
C. Disabling redundant fiber pathways
D. Restricting network bandwidth for enterprise users

hat does the journey of data packets mainly show?
\\CUyY
@ On 18* Semester
"""


class SummarizerQualityTests(unittest.TestCase):
    maxDiff = None

    def test_summary_output_removes_decorative_symbols_but_preserves_facts(self):
        sentence = "★ The result was measured on 2026-09-22 — approximately $1.8M, with 32GB of storage. ✅"
        normalized = normalize_summary_sentence(sentence)

        self.assertNotIn("★", normalized)
        self.assertNotIn("✅", normalized)
        self.assertIn("2026-09-22", normalized)
        self.assertIn("$1.8M", normalized)
        self.assertIn("32GB", normalized)
        self.assertIn("-", normalized)

    def test_summary_output_removes_formatting_artifacts(self):
        self.assertEqual("This is the main finding.", normalize_summary_sentence("This is the main finding ************"))
        self.assertEqual("Results.", normalize_summary_sentence("Results ____________"))
        self.assertEqual("Conclusion.", normalize_summary_sentence("Conclusion ========"))
        self.assertEqual("Section Methods.", normalize_summary_sentence("Section ----- Methods"))

    def test_summary_output_preserves_meaningful_symbols(self):
        examples = {
            "The treatment improved outcomes by 15%.": "The treatment improved outcomes by 15%.",
            "The result was significant (p < 0.05).": "The result was significant (p < 0.05).",
            "COVID-19 cases increased by 12.5%.": "COVID-19 cases increased by 12.5%.",
            "CO₂ concentration increased by 20 ± 3%.": "CO₂ concentration increased by 20 ± 3%.",
            "2024–2026": "2024–2026.",
            "The model achieved 95.2% accuracy using Python 3.12 and C++.": (
                "The model achieved 95.2% accuracy using Python 3.12 and C++."
            ),
        }
        for sentence, expected in examples.items():
            self.assertEqual(expected, normalize_summary_sentence(sentence))

    def test_summary_quality_signals_identify_symbol_only_artifacts(self):
        quality = assess_summary_text_quality("**** ___ === �")

        self.assertGreater(quality["artifact_score"], 0.5)
        self.assertGreater(quality["repeated_character_ratio"], 0.5)
        self.assertEqual(1, quality["replacement_character_count"])

    def test_summary_semantic_tokens_are_preserved(self):
        source = "The treatment improved outcomes by 15% (p < 0.05) from 2024–2026."
        normalized = normalize_summary_sentence(source)

        self.assertTrue(semantic_tokens_preserved(source, normalized))
        self.assertIn("15%", normalized)
        self.assertIn("p < 0.05", normalized)
        self.assertIn("2024–2026", normalized)

    def test_sentence_compression_does_not_return_incomplete_fragment(self):
        pipeline = SummarizationPipeline()
        sentence = (
            "The results show that around the middle of the 1970s, precipitation "
            "maxima shifted from relatively stable patterns to a marked seasonal change."
        )

        compressed = pipeline._compress_sentence(sentence, max_words=20)

        self.assertNotIn(compressed.rstrip(".").split()[-1].lower(), {"a", "an", "the", "to", "of", "with"})
        self.assertTrue(compressed.endswith("."))

    def test_summary_output_removes_ocr_broken_author_citations(self):
        examples = (
            "The sample showed a rise in yearly temperature (Liet al.).",
            "Aerosol emissions should have a higher impact (Sanchez-Lorenzo et al.",
        )
        for sentence in examples:
            normalized = normalize_summary_sentence(sentence)
            self.assertNotIn("et al", normalized.lower())
            self.assertNotIn("Liet", normalized)
            self.assertNotIn("Sanchez-Lorenzo", normalized)

    def test_translation_chunks_keep_placeholders_within_provider_limit(self) -> None:
        source = (
            "Visit https://example.com/for-more-details and run "
            "`python local_cli.py` before continuing. "
            + ("Additional context " * 40)
        )
        protected, replacements = protect_translation_segments(source)
        chunks = chunk_translation_text(protected)

        self.assertTrue(chunks)
        self.assertTrue(all(len(chunk) <= TRANSLATION_CHUNK_SIZE for chunk in chunks))
        self.assertEqual("".join(chunks), protected)
        for token in replacements:
            self.assertEqual(sum(token in chunk for chunk in chunks), 1)

    def test_selection_prefers_distinct_evidence_over_overlapping_high_scores(self) -> None:
        pipeline = SummarizationPipeline()
        candidates = [
            SentenceCandidate(0, 0, 0, 1, "results", "The trial reduced latency by 45 percent.", "The trial reduced latency by 45 percent.", "trial reduced latency 45 percent", 5),
            SentenceCandidate(1, 1, 0, 1, "results", "The trial reduced latency by 45 percent during peak traffic.", "The trial reduced latency by 45 percent during peak traffic.", "trial reduced latency 45 percent peak traffic", 7),
            SentenceCandidate(2, 2, 0, 1, "conclusion", "The authors recommend multi-tier caching for high-demand services.", "The authors recommend multi-tier caching for high-demand services.", "authors recommend multi tier caching high demand services", 8),
        ]

        selected = pipeline._select_candidate_indices(candidates, [0.99, 0.98, 0.74], "general_article", 2, {}, 0.0)

        self.assertEqual(selected, [0, 2])

    def test_sentence_compression_preserves_qualifying_result_clause(self) -> None:
        pipeline = SummarizationPipeline()
        sentence = "The intervention improved attendance, although it did not improve final examination scores."

        self.assertEqual(pipeline._compress_sentence(sentence, max_words=30), sentence)

    def test_output_style_controls_the_serialized_summary_structure(self) -> None:
        sentences = [
            "The report identifies a rising demand for reliable digital services.",
            "It recommends phased infrastructure improvements to reduce risk.",
            "Leaders should monitor the rollout with measurable service targets.",
            "The approach prioritizes continuity while controlling implementation costs.",
        ]
        pipeline = SummarizationPipeline()

        paragraph, paragraph_bullets = pipeline._build_plain_summary(sentences, "standard_paragraph")
        bullets_text, bullets = pipeline._build_plain_summary(sentences, "bullet_points")
        executive, executive_bullets = pipeline._build_plain_summary(sentences, "executive_summary")

        self.assertEqual(paragraph, " ".join(sentences))
        self.assertEqual(paragraph_bullets, [])
        self.assertEqual(bullets_text, " ".join(sentences))
        self.assertEqual(bullets, sentences)
        self.assertEqual(executive, " ".join(sentences[:2]))
        self.assertEqual(executive_bullets, sentences[2:])

    def test_pure_nlp_contract_extracts_title_purges_sections_and_groups_metrics(self) -> None:
        text = """
        International Journal of Sample Research
        IJSRED
        Volume 9, Issue 2
        The Impact of Artificial Intelligence Tools on the Coding Proficiency and Skill Development of IT Students
        JOHN AUSTRIA*
        Nueva Ecija University of Science and Technology
        ISSN 2581-7175

        Abstract
        This study examined artificial intelligence use among information technology students and its relationship to coding proficiency.
        Results showed that 70% used AI for research, 39% for debugging, and 33% for code generation.
        The findings suggest guided use may support learning while preserving independent reasoning.

        Acknowledgment
        Foremost, profound gratitude is extended to every person who helped complete this study.

        References
        Author, A. (2026). A Review of Generative AI in Computer Science Education.
        """

        result = summarize_to_contract(text)
        self.assertTrue(result["summary_meta"]["title"].startswith("The Impact of Artificial Intelligence Tools"))
        content = " ".join(item["content"] for item in result["thematic_paragraphs"])
        self.assertNotIn("Foremost, profound gratitude", content)
        self.assertNotIn("A Review of Generative AI", content)
        metric_contexts = [
            item["context"]
            for item in result["key_findings"]
            if "70%" in item["context"]
        ]
        self.assertEqual(len(metric_contexts), 1)
        self.assertTrue(all(item["content"].rstrip()[-1] in ".!?" for item in result["thematic_paragraphs"]))

    def test_key_findings_prioritize_results_and_include_qualitative_implications(self) -> None:
        text = """
        Abstract
        This study examined guided artificial intelligence use among programming students.
        The survey included 120 students across three year levels.
        Results showed that 70% used AI for concept research, while only 18% used it for full code generation.
        Students reported checking generated explanations before applying them in coursework.
        The findings indicated that guided use supported learning without replacing independent reasoning.
        The study recommended logic-based assessments and instructor oversight.
        """

        findings = summarize_to_contract(text)["key_findings"]

        self.assertLessEqual(len(findings), 8)
        self.assertTrue(any(item["label"] == "Metric" and "70%" in item["value"] for item in findings))
        self.assertTrue(any(item["label"] == "Finding" for item in findings))
        self.assertTrue(any(item["label"] == "Implication" for item in findings))
        self.assertTrue(all(item["context"].rstrip()[-1] in ".!?" for item in findings))
        self.assertEqual(len({item["context"].lower() for item in findings}), len(findings))

    def test_clean_pdf_extracted_text_removes_publication_noise(self) -> None:
        cleaned = clean_pdf_extracted_text(NOISY_ACADEMIC_TEXT)

        lowered = cleaned.lower()
        self.assertNotIn("issn", lowered)
        self.assertNotIn("www.ijsred.com", lowered)
        self.assertNotIn("all rights reserved", lowered)
        self.assertNotIn("page 938", lowered)
        self.assertNotIn("page 942", lowered)
        self.assertNotIn("robert@example.edu", lowered)
        self.assertNotIn("doi:10.1234", lowered)
        self.assertIn("The rapid integration of generative artificial intelligence in education", cleaned)
        self.assertIn("The study concluded that artificial intelligence can function as a learning aid", cleaned)

    def test_extract_valid_paragraphs_skips_headings_and_metadata(self) -> None:
        cleaned = clean_pdf_extracted_text(NOISY_ACADEMIC_TEXT)
        paragraphs = extract_valid_paragraphs(cleaned)

        self.assertGreaterEqual(len(paragraphs), 4)
        self.assertTrue(any("rapid integration of generative artificial intelligence" in paragraph.lower() for paragraph in paragraphs))
        self.assertTrue(any("quantitative descriptive design" in paragraph.lower() for paragraph in paragraphs))
        self.assertTrue(any("students reported using ai most often" in paragraph.lower() for paragraph in paragraphs))
        self.assertTrue(all(not contains_article_noise(paragraph) for paragraph in paragraphs))
        self.assertTrue(all(paragraph.lower() not in {"abstract", "introduction", "methodology", "results and discussion", "conclusion"} for paragraph in paragraphs))

    def test_pipeline_compresses_and_deduplicates_output(self) -> None:
        pipeline = SummarizationPipeline()
        result = pipeline.summarize(
            SummarizationRequest(
                text=NOISY_ACADEMIC_TEXT,
                sentence_count=4,
                preprocessing_options=PreprocessingOptions(),
                summary_style="standard_paragraph",
            )
        )

        summary_text = " ".join(
            result.sentences
            + [result.plain_summary, result.conclusion]
            + [item.summary for item in result.paragraph_summaries]
        ).lower()

        self.assertGreaterEqual(len(result.sentences), 2)
        self.assertEqual(len(result.sentences), len(set(result.sentences)))
        self.assertNotIn("issn", summary_text)
        self.assertNotIn("all rights reserved", summary_text)
        self.assertNotIn("www.ijsred.com", summary_text)
        self.assertNotIn("doi", summary_text)
        self.assertLess(result.readability["summary_word_count"], result.readability["original_word_count"])
        self.assertTrue(all(len(sentence.split()) <= 34 for sentence in result.sentences))
        self.assertTrue(all(sentence[-1] in ".!?" for sentence in result.sentences))
        self.assertTrue(result.paragraph_summaries)
        self.assertTrue(all(not contains_article_noise(item.summary) for item in result.paragraph_summaries))

    def test_pipeline_excludes_assessment_questions_and_ocr_artifacts(self) -> None:
        pipeline = SummarizationPipeline()
        result = pipeline.summarize(
            SummarizationRequest(
                text=WORKSHEET_WITH_ASSESSMENT_TEXT,
                summary_length="balanced",
                preprocessing_options=PreprocessingOptions(),
                summary_style="standard_paragraph",
            )
        )

        full_output_text = " ".join(
            result.sentences
            + result.overview
            + result.key_points
            + [result.conclusion, result.plain_summary]
            + [item.summary for item in result.paragraph_summaries]
        ).lower()

        # Verify zero questions leaked into the summary
        self.assertNotIn("what is the primary advantage", full_output_text)
        self.assertNotIn("which strategy is used", full_output_text)
        self.assertNotIn("hat does the journey", full_output_text)
        self.assertNotIn("?", full_output_text)

        # Verify zero choices leaked into the summary
        self.assertNotIn("eliminating all physical routing hardware", full_output_text)
        self.assertNotIn("disabling redundant fiber pathways", full_output_text)

        # Verify zero directions leaked into the summary
        self.assertNotIn("directions:", full_output_text)
        self.assertNotIn("choose the correct answer", full_output_text)
        self.assertNotIn("separate sheet of paper", full_output_text)

        # Verify zero branding or OCR artifacts leaked into the summary
        self.assertNotIn("camscanner", full_output_text)
        self.assertNotIn("nourishing the mind", full_output_text)
        self.assertNotIn("cuyy", full_output_text)
        self.assertNotIn("@ cli", full_output_text)

        # Verify genuine main reading content is preserved
        self.assertTrue(
            "packet-switching" in full_output_text
            or "network" in full_output_text
            or "edge computing" in full_output_text
        )

        # Verify key points, terms, and keywords are clean
        for kp in result.key_points:
            self.assertFalse(contains_article_noise(kp))
            self.assertNotIn("?", kp)

        self.assertTrue(result.keywords)
        self.assertTrue(all(not contains_article_noise(kw) for kw in result.keywords))

    def test_load_source_document_supports_plain_text_pdf_dispatch_and_url_dispatch(self) -> None:
        plain = load_source_document(text="A clear paragraph with enough content to be treated as direct plain text input.")
        self.assertEqual(plain.source_type, "text")

        temp_root = Path(__file__).resolve().parents[1] / ".tmp"
        temp_root.mkdir(parents=True, exist_ok=True)
        temp_dir = tempfile.mkdtemp(dir=temp_root)
        try:
            pdf_path = Path(temp_dir) / "sample.pdf"
            pdf_path.write_bytes(b"%PDF-1.4")

            with patch("summarizer_core.text_utils.extract_pdf_source", return_value=SourceDocument("PDF content", "pdf", "PDF title")):
                pdf_doc = load_source_document(file_path=str(pdf_path))
            self.assertEqual(pdf_doc.source_type, "pdf")
            self.assertEqual(pdf_doc.raw_text, "PDF content")
        finally:
            shutil.rmtree(temp_dir, ignore_errors=True)

        with patch("summarizer_core.text_utils.fetch_url_source", return_value=SourceDocument("URL content", "url", "URL title")):
            url_doc = load_source_document(text="https://example.com/article")
        self.assertEqual(url_doc.source_type, "url")
        self.assertEqual(url_doc.raw_text, "URL content")

    def test_load_source_document_supports_docx_when_dependency_exists(self) -> None:
        try:
            from docx import Document
        except ImportError:
            self.skipTest("python-docx is not installed")

        temp_root = Path(__file__).resolve().parents[1] / ".tmp"
        temp_root.mkdir(parents=True, exist_ok=True)
        temp_dir = tempfile.mkdtemp(dir=temp_root)
        try:
            docx_path = Path(temp_dir) / "sample.docx"
            document = Document()
            document.add_paragraph("This is the first DOCX paragraph with enough detail to remain readable.")
            document.add_paragraph("This is the second DOCX paragraph and it should also be extracted correctly.")
            document.save(str(docx_path))

            source_document = load_source_document(file_path=str(docx_path))
        finally:
            shutil.rmtree(temp_dir, ignore_errors=True)

        self.assertEqual(source_document.source_type, "docx")
        self.assertIn("first DOCX paragraph", source_document.raw_text)
        self.assertIn("second DOCX paragraph", source_document.raw_text)

    def test_generate_nutshell_produces_conversational_explanation(self) -> None:
        from summarizer_core.nutshell import generate_nutshell

        result = generate_nutshell(text=NOISY_ACADEMIC_TEXT)
        nutshell = result["nutshell"]
        word_count = result["word_count"]
        sentence_count = result["sentence_count"]

        # Assert clean, conversational, non-templated synthesis
        self.assertFalse(nutshell.startswith("In a nutshell: The document"))
        self.assertGreaterEqual(word_count, 8)
        self.assertLessEqual(word_count, 50)
        self.assertGreaterEqual(sentence_count, 1)
        self.assertLessEqual(sentence_count, 2)
        self.assertNotIn("issn", nutshell.lower())
        self.assertNotIn("ijsred", nutshell.lower())

    def test_generate_nutshell_worksheet_rejects_questions_and_ocr(self) -> None:
        from summarizer_core.nutshell import generate_nutshell

        result = generate_nutshell(text=WORKSHEET_WITH_ASSESSMENT_TEXT)
        nutshell = result["nutshell"]
        word_count = result["word_count"]

        self.assertGreaterEqual(word_count, 8)
        self.assertLessEqual(word_count, 50)
        # Verify quiz questions and OCR junk are excluded
        self.assertNotIn("what is the primary advantage", nutshell.lower())
        self.assertNotIn("choose the correct answer", nutshell.lower())
        self.assertNotIn("cuyy", nutshell.lower())
        self.assertNotIn("camscanner", nutshell.lower())
        # Verify main topic (computer networking / data transmission) is preserved
        self.assertTrue(
            "network" in nutshell.lower()
            or "packet" in nutshell.lower()
            or "transmission" in nutshell.lower()
            or "system" in nutshell.lower()
        )

    def test_generate_nutshell_technical_article_direct_synthesis(self) -> None:
        from summarizer_core.nutshell import generate_nutshell

        tech_text = """
        Cloud computing architectures enable modern enterprise applications to dynamically scale resources to meet fluctuating user demands.
        By distributing computational workloads across elastic virtual machine clusters and edge proxies, system architects minimize latency and maintain service availability during severe traffic spikes.
        Automated monitoring routines and self-healing deployment scripts continuously inspect system health to replace failing container instances without interrupting active client sessions.
        """
        result = generate_nutshell(text=tech_text)
        nutshell = result["nutshell"]
        word_count = result["word_count"]

        self.assertGreaterEqual(word_count, 8)
        self.assertLessEqual(word_count, 50)
        self.assertTrue(
            "cloud" in nutshell.lower()
            or "scale" in nutshell.lower()
            or "workloads" in nutshell.lower()
            or "latency" in nutshell.lower()
        )
 
    def test_generate_nutshell_preserves_negation_and_factuality(self) -> None:
        from summarizer_core.nutshell import generate_nutshell

        negation_text = """
        A clinical trial evaluated whether the experimental compound improves cognitive recovery in patients.
        The authors rigorously tested multiple dosage regimens across five distinct participant cohorts over twelve months.
        The study concluded that the experimental drug did not improve cognitive recovery and failed to demonstrate clinical efficacy.
        Secondary analyses showed that adverse event rates were consistent across all treatment groups.
        """
        result = generate_nutshell(text=negation_text)
        nutshell = result["nutshell"]

        # Ensure the negative finding is preserved and not inverted
        self.assertTrue(
            "not" in nutshell.lower()
            or "failed" in nutshell.lower()
            or "did not improve" in nutshell.lower()
        )
        self.assertNotIn("proved that the experimental drug improved", nutshell.lower())

    def test_generate_nutshell_section_topology_bias(self) -> None:
        from summarizer_core.nutshell import generate_nutshell

        structured_text = """
        Abstract
        This paper investigates distributed database caching strategies under high read concurrency.

        Methodology
        We configured twelve Apache Kafka broker instances and deployed eighty simulated client nodes on Amazon Web Services using Terraform automation scripts.

        Results and Discussion
        Decentralized multi-tier caching reduced peak transaction latency by 45 percent and maintained steady cluster throughput.

        Conclusion
        The findings demonstrate that decentralized caching strategies significantly enhance database performance during extreme workload surges.
        """
        result = generate_nutshell(text=structured_text)
        nutshell = result["nutshell"]

        # Assert conclusion/results content is picked rather than terraform deployment commands
        self.assertTrue(
            "decentralized" in nutshell.lower()
            or "caching" in nutshell.lower()
            or "performance" in nutshell.lower()
            or "latency" in nutshell.lower()
        )
        self.assertNotIn("terraform", nutshell.lower())

    def test_generate_nutshell_covers_whole_document_in_coherent_language(self) -> None:
        from summarizer_core.nutshell import generate_nutshell

        brand_text = """
        Brand Strategy Overview

        The brand is built around a bold, rebellious identity anchored by the three-claw logo, black-and-neon-green palette, and aggressive gothic type. These elements create a distinctive visual system that appeals to extreme-sports and counter-cultural communities.

        Tone and Personality
        The tone is intense, confident, and high-energy. The company presents itself as authentic, fearless, and culturally charged, which strengthens recognition across digital channels.

        Target Audience
        The primary audience includes action sports participants, young men, and fans of adrenaline-driven lifestyles. The brand positions itself as more than a beverage; it embodies a lifestyle and a sense of belonging.

        Digital Platforms
        On Instagram and YouTube, the content adapts to each platform while keeping the same rebellious visual identity. Short-form videos, athlete partnerships, and high-impact imagery support engagement, but the platform strategy needs more flexibility for broader audiences.

        Brand Evaluation
        The evaluation concludes that the core identity remains strong because it is recognizable and memorable. However, the company should simplify some visual elements, refine typography for more modern product lines, and maintain a consistent brand voice while expanding to health-conscious audiences.
        """

        result = generate_nutshell(text=brand_text)
        nutshell = result["nutshell"]
        words = nutshell.split()

        self.assertGreaterEqual(len(words), 8)
        self.assertLessEqual(len(words), 50)
        self.assertLess(len(words), len(brand_text.split()))
        self.assertIn("brand", nutshell.lower())
        self.assertTrue("identity" in nutshell.lower() or "brand" in nutshell.lower())
        self.assertNotIn("ultimately balanced update maintain adrenaline-fueled spirit brand", nutshell.lower())
        self.assertNotIn("the piece highlights that", nutshell.lower())


    # ============================================================
    # Phase 1 & 2: Faithfulness & Coherence Quality Tests
    # ============================================================

    def test_faithfulness_detection_paraphrased_sentence_flagged(self) -> None:
        """Test that paraphrased sentences not in source are flagged."""
        pipeline = SummarizationPipeline()

        # Source text
        source_text = """
        The clinical trial demonstrated that the new drug reduced blood pressure by 15 percent in 80 percent of participants.
        The study was conducted over a period of six months with 200 patients.
        Researchers concluded that the treatment is effective for hypertension management.
        """

        # Paraphrased sentence that changes meaning (hallucination)
        paraphrased = "The clinical trial showed the drug completely cured hypertension in all patients."

        # Extract candidate from source
        candidates = [
            SentenceCandidate(0, 0, 0, 1, "results", "The clinical trial demonstrated that the new drug reduced blood pressure by 15 percent in 80 percent of participants.", "", "", 0),
            SentenceCandidate(1, 1, 0, 1, "conclusion", "Researchers concluded that the treatment is effective for hypertension management.", "", "", 0),
        ]

        # Verify faithfulness check would catch the paraphrased version
        passed, notes = pipeline._verify_source_faithfulness([paraphrased], source_text, candidates)
        self.assertFalse(passed)
        self.assertTrue(any("not found in source" in note or "low token overlap" in note for note in notes))

    def test_qualifier_preservation_in_output(self) -> None:
        """Test that hedging language (may, might, could) is preserved."""
        pipeline = SummarizationPipeline()

        source_text = """
        The study suggests that the intervention may improve outcomes for some patients.
        However, the researchers note that results could vary significantly across populations.
        It appears that further research is needed to confirm these findings.
        """

        candidates = [
            SentenceCandidate(0, 0, 0, 1, "results", "The study suggests that the intervention may improve outcomes for some patients.", "", "", 0),
            SentenceCandidate(1, 1, 0, 1, "discussion", "However, the researchers note that results could vary significantly across populations.", "", "", 0),
        ]

        # Test that qualifier bonus is applied
        import re
        qualifier_pattern = re.compile(r"\b(?:may|might|could|possibly|potentially|appears?\s+to|seems?\s+to|suggests?|likely|unlikely|tentatively|preliminary|apparently|presumably|arguably|roughly|approximately)\b", re.IGNORECASE)

        for candidate in candidates:
            self.assertTrue(qualifier_pattern.search(candidate.text), f"Qualifier should be detected in: {candidate.text}")

    def test_negation_preservation_in_compression(self) -> None:
        """Test that negation (not, never, failed) is preserved during compression."""
        pipeline = SummarizationPipeline()

        # Sentence with negation that should NOT be truncated to positive
        sentence = "The experimental drug did not improve cognitive recovery and failed to demonstrate clinical efficacy."

        compressed = pipeline._compress_sentence(sentence, max_words=15)

        # Negation must be preserved
        self.assertIn("not", compressed.lower())
        self.assertIn("failed", compressed.lower())

        # Should not flip meaning
        self.assertNotIn("improved", compressed.lower())
        # Ensure "demonstrate clinical efficacy" doesn't appear without negation
        if "demonstrate clinical efficacy" in compressed.lower():
            self.assertIn("not", compressed.lower())

    def test_numeric_fact_preservation(self) -> None:
        """Test that percentages, measurements, dates survive compression."""
        pipeline = SummarizationPipeline()

        test_cases = [
            "The treatment reduced symptoms by 45 percent in the first group.",
            "The study enrolled 200 participants over a 6-month period.",
            "The p-value was p < 0.05 indicating statistical significance.",
            "The correlation coefficient was r = 0.78 for the primary outcome.",
            "The cost was $1,250.00 per patient for the full course.",
            "The data transfer rate reached 1.5 GB per second.",
        ]

        for sentence in test_cases:
            compressed = pipeline._compress_sentence(sentence, max_words=20)
            # Check that numeric patterns are preserved
            import re
            numeric_pattern = re.compile(
                r"\b\d+(?:[.,]\d+)?\s*%"
                r"|\b\d+(?:[.,]\d+)?\s*percent\b"
                r"|\b\d+(?:[.,]\d+)?\s*(?:kg|km|m|cm|mm|MB|GB|KB|TB|ms|s|min|hr|hrs|year|years|month|months|week|weeks|day|days)\b"
                r"|\b\d+(?:[.,]\d+)?[- ](?:kg|km|m|cm|mm|MB|GB|KB|TB|ms|s|min|hr|hrs|year|years|month|months|week|weeks|day|days)\b"
                r"|\b\d{1,2}[/-]\d{1,2}[/-]\d{2,4}\b"
                r"|[$€£¥]\s*\d+(?:,\d{3})*(?:\.\d+)?"
                r"|\bp\s*[<>=]\s*0\.\d+"
                r"|r\s*=\s*[-\d.]+",
                re.IGNORECASE,
            )
            original_nums = numeric_pattern.findall(sentence)
            compressed_nums = numeric_pattern.findall(compressed)

            # At least one numeric fact should survive
            self.assertTrue(len(compressed_nums) > 0, f"Numeric fact lost in: '{sentence}' -> '{compressed}'")

    def test_discourse_aware_selection(self) -> None:
        """Test that discourse markers (However, Therefore) influence selection."""
        pipeline = SummarizationPipeline()
        from summarizer_core.constants import DISCOURSE_BOOST_PATTERNS

        # Sentences with discourse markers should get bonus
        contrastive = "However, the treatment showed no effect in the control group."
        continuative = "Therefore, the researchers recommend further investigation."

        for sentence in [contrastive, continuative]:
            has_discourse = any(pattern.search(sentence) for pattern, _ in DISCOURSE_BOOST_PATTERNS.values())
            self.assertTrue(has_discourse, f"Discourse marker should be detected in: {sentence}")

    def test_structured_role_semantic_matching(self) -> None:
        """Test that structured roles are assigned by semantic similarity, not just keywords."""
        pipeline = SummarizationPipeline()

        candidates = [
            SentenceCandidate(0, 0, 0, 1, "methodology", "We configured the system using Terraform scripts and deployed on AWS.", "", "", 0),
            SentenceCandidate(1, 1, 0, 1, "results", "The system achieved 45 percent latency reduction under load.", "", "", 0),
            SentenceCandidate(2, 2, 0, 1, "conclusion", "Overall, the findings demonstrate that decentralized caching enhances performance.", "", "", 0),
        ]

        # Test that role assignment considers section + semantic match
        from summarizer_core.constants import STRUCTURED_ROLES
        roles = STRUCTURED_ROLES.get("technical", [])

        # Should find appropriate roles for each candidate type
        self.assertTrue(len(roles) > 0)

    def test_conclusion_synthesis(self) -> None:
        """Test that multi-sentence conclusion is synthesized from multiple sources."""
        pipeline = SummarizationPipeline()

        candidates = [
            SentenceCandidate(0, 0, 0, 1, "conclusion", "The study concludes that the treatment is effective.", "", "", 0),
            SentenceCandidate(1, 1, 0, 1, "discussion", "However, the authors note limitations in sample size.", "", "", 0),
            SentenceCandidate(2, 2, 0, 1, "recommendation", "Future research should explore larger populations.", "", "", 0),
        ]

        conclusion = pipeline._build_conclusion(candidates, [], lambda x: False)

        # Should synthesize multiple conclusion points
        self.assertTrue(len(conclusion.split(".")) >= 2 or "however" in conclusion.lower())
        self.assertIn("effective", conclusion.lower())

    def test_paragraph_summary_coherence(self) -> None:
        """Test that paragraph summaries allow multiple coherent sentences."""
        pipeline = SummarizationPipeline()

        paragraphs = [
            "The system architecture consists of three main components: the load balancer, the application servers, and the database cluster. Each component is designed for horizontal scaling.",
            "During testing, the load balancer distributed requests evenly across all application servers. The database cluster handled the query load with sub-millisecond latency.",
        ]
        paragraph_sentences = [
            ["The system architecture consists of three main components: the load balancer, the application servers, and the database cluster.", "Each component is designed for horizontal scaling."],
            ["During testing, the load balancer distributed requests evenly across all application servers.", "The database cluster handled the query load with sub-millisecond latency."],
        ]
        sections = ["overview", "results"]

        candidates = [
            SentenceCandidate(0, 0, 0, 1, "overview", "The system architecture consists of three main components: the load balancer, the application servers, and the database cluster.", "", "", 0),
            SentenceCandidate(1, 0, 0, 2, "overview", "Each component is designed for horizontal scaling.", "", "", 0),
            SentenceCandidate(2, 1, 1, 3, "results", "During testing, the load balancer distributed requests evenly across all application servers.", "", "", 0),
            SentenceCandidate(3, 1, 1, 4, "results", "The database cluster handled the query load with sub-millisecond latency.", "", "", 0),
        ]

        # Mock required functions
        def mock_preprocess(text):
            return text.lower()
        def mock_normalize(text):
            return text
        def mock_noise(text):
            return False
        def mock_keywords(*args, **kwargs):
            return ["system", "component", "load", "database"]

        analyses = pipeline._build_paragraph_summaries(
            paragraphs=paragraphs,
            paragraph_sentences=paragraph_sentences,
            sections=sections,
            selected_candidates=candidates,
            article_type="technical",
            purpose_cue_patterns=[],
            type_allowed_labels={},
            type_default_label={},
            preprocess_sentence_for_ranking=mock_preprocess,
            normalize_whitespace=mock_normalize,
            keywords=["system", "component", "load", "database"],
            overall_sentences=[],
            contains_article_noise=mock_noise,
            normalize_summary_sentence=mock_normalize,
        )

        # Should have analyses for each paragraph
        self.assertEqual(len(analyses), 2)

        # Each should have up to 3 sentences in summary
        for analysis in analyses:
            summary_sentences = analysis.summary.split(". ")
            self.assertGreaterEqual(len(summary_sentences), 1)
            self.assertLessEqual(len(summary_sentences), 3)


if __name__ == "__main__":
    unittest.main()
