from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path
import shutil
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from summarizer_core.models import PreprocessingOptions, SourceDocument, SummarizationPipeline, SummarizationRequest
from summarizer_core.text_utils import (
    clean_pdf_extracted_text,
    contains_article_noise,
    extract_valid_paragraphs,
    load_source_document,
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
        self.assertGreaterEqual(word_count, 20)
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

        self.assertGreaterEqual(word_count, 20)
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

        self.assertGreaterEqual(word_count, 20)
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


if __name__ == "__main__":
    unittest.main()



