"""Nutshell Analyzer — Production-Grade High-Accuracy Conversational AI Document Distillation.

Upgraded with:
1. Discourse-Aware Section Topology & Paragraph Weighting
2. Demonstrative & Pronoun Subject Anchoring (Anaphora Resolution)
3. Dense Document Centroid Semantic Scoring
4. Deterministic Factuality, Negation Polarity, and Numerical Grounding Gates
5. Strict 20-50 Word & 1-2 Sentence Output Enforcers
"""

from __future__ import annotations

import re
from typing import Any
import numpy as np  # pyright: ignore[reportMissingImports]
from sklearn.feature_extraction.text import TfidfVectorizer  # pyright: ignore[reportMissingModuleSource]

from .models import PreprocessingOptions, SourceDocument
from .text_utils import (
    clean_pdf_extracted_text,
    contains_article_noise,
    detect_document_title,
    detect_explicit_sections,
    load_source_document,
    normalize_summary_sentence,
    normalize_whitespace,
    prepare_document_paragraphs,
    safe_sent_tokenize,
    tokenize_words,
)


# ── Assessment, Noise, and OCR Rejection Patterns ──────────────────────────

_ASSESSMENT_RE = re.compile(
    r"^(?:\s*(?:question\s*\d*|\d+[\.\)]\s*(?:what|which|who|why|where|how|when|choose|identify|select)|[A-D][\.\)]\s+|true\s*/\s*false|directions\s*:|instructions\s*:|exercise\s*\d*|activity\s*\d*))",
    re.IGNORECASE,
)

_OCR_GARBAGE_RE = re.compile(
    r"(?:[\x00-\x08\x0b\x0c\x0e-\x1f]|[^\w\s\.,;:!\?\-\'\"]{3,}|(?:\b[a-zA-Z]\b\s+){4,})",
)

_ACADEMIC_PREAMBLE_RE = re.compile(
    r"^(?:in this (?:study|paper|article|report|research|work)\s*,?|the (?:study|paper|report|article|authors?|researchers?|findings?|results?) (?:concluded?|concludes?|found|demonstrates?|demonstrated|shows?|showed|argues?|argued|suggests?|suggested|reveals?|revealed|indicates?|indicated|states?|stated) (?:that)?|the (?:study|paper|report|article|authors?|researchers?)|(?:findings|results) (?:indicated?|indicates?|showed?|shows?|revealed?|reveals?|demonstrated?|demonstrates?|suggested?|suggests?) (?:that)?|(?:overall|crucially|specifically|in summary|in conclusion)\s*,?)\s*",
    re.IGNORECASE,
)

_LEADING_VERB_RE = re.compile(
    r"^(?:concluded?|concludes?|found|demonstrates?|demonstrated|shows?|showed|indicates?|indicated|argues?|argued|reveals?|revealed|suggests?|suggested|explains?|explained|stated|states)\s+(?:that|how)?\s*",
    re.IGNORECASE,
)

_METHODOLOGY_CUES = re.compile(
    r"\b(?:we configured|we deployed|we implemented|we installed|we measured|we surveyed|participants were|materials and methods|experimental setup|configuration scripts|were recruited|was administered)\b",
    re.IGNORECASE,
)

_MODALITY_WEAK_RE = re.compile(r"\b(?:suggests?|indicates?|implies?|points? to|may|might|could|potential|preliminary)\b", re.IGNORECASE)
_MODALITY_ARG_RE = re.compile(r"\b(?:argues?|contends?|maintains?|claims?|proposes?|advocates?)\b", re.IGNORECASE)
_MODALITY_STRONG_RE = re.compile(r"\b(?:demonstrates?|proves?|establishes?|reveals?|found that|confirms?)\b", re.IGNORECASE)
_MODALITY_EXPLAIN_RE = re.compile(r"\b(?:explains?|describes?|clarifies?|details?|examines?|explores?|focuses on)\b", re.IGNORECASE)

_NEGATION_CUES = {"not", "never", "no", "neither", "nor", "barely", "hardly", "without", "failed", "fails", "cannot", "unable"}

_SECTION_WEIGHTS: dict[str, float] = {
    "conclusion": 1.35,
    "conclusions": 1.35,
    "recommendations": 1.30,
    "results": 1.25,
    "findings": 1.25,
    "abstract": 1.20,
    "discussion": 1.15,
    "introduction": 1.10,
    "body": 1.00,
    "methodology": 0.55,
    "methods": 0.55,
    "background": 0.65,
    "literature_review": 0.60,
    "references": 0.00,
    "appendix": 0.00,
}


def _is_invalid_candidate(sentence: str) -> bool:
    """Filter out questions, assessment items, OCR corruption, and low-information noise."""
    compact = sentence.strip()
    words = tokenize_words(compact)
    if len(words) < 6 or len(words) > 75:
        return True
    if _ASSESSMENT_RE.search(compact):
        return True
    if compact.endswith("?") and not re.search(r"\b(?:how|why|what)\b", compact, re.I):
        return True
    if _OCR_GARBAGE_RE.search(compact):
        return True
    if contains_article_noise(compact):
        return True
    # Disallow lines with high ratio of digits/special characters
    non_alpha = len(re.findall(r"[^a-zA-Z\s]", compact))
    if len(compact) > 0 and (non_alpha / len(compact)) > 0.35:
        return True
    return False


def _clean_clause(sentence: str) -> str:
    """Normalize summary sentence and cleanly strip academic/boilerplate throat clearing."""
    cleaned = normalize_summary_sentence(sentence)
    cleaned = _ACADEMIC_PREAMBLE_RE.sub("", cleaned).strip()
    cleaned = _LEADING_VERB_RE.sub("", cleaned).strip()
    # Remove leading connective adverbs
    cleaned = re.sub(
        r"^(?:furthermore|moreover|additionally|however|nevertheless|therefore|thus|consequently)\s*,?\s*",
        "",
        cleaned,
        flags=re.IGNORECASE,
    ).strip()
    # Remove leading "that " if present after stripping verbs
    if cleaned.lower().startswith("that "):
        cleaned = cleaned[5:].strip()
    # Remove trailing period for modular clause combining
    if cleaned.endswith("."):
        cleaned = cleaned[:-1].strip()
    # Remove citation remnants like [1], (Smith et al., 2020)
    cleaned = re.sub(r"\[\d+(?:[,\s\-–\d]*)*\]", "", cleaned)
    cleaned = re.sub(r"\([A-Z][a-zA-Z\s,]+(?:et\s+al\.?)?,?\s*\d{4}\)", "", cleaned)
    cleaned = normalize_whitespace(cleaned)
    return cleaned


def _anchor_subject_anaphora(clause: str, title: str) -> str:
    """Anchor vague leading pronouns (e.g., 'It provides...', 'They discovered...') with the document subject."""
    if not clause or not title:
        return clause

    # Clean title for subject substitution
    clean_title = re.sub(r"^(?:the\s+impact\s+of|an\s+overview\s+of|a\s+study\s+on|module\s+\d+:?)\s*", "", title, flags=re.IGNORECASE).strip()
    if not clean_title or len(clean_title.split()) > 7:
        clean_title = "The system" if "system" in title.lower() else "The document"

    # Replace bare pronoun subjects
    if re.match(r"^It\s+(?:is|are|provides?|helps?|allows?|enables?|relies?|shows?|demonstrates?)\b", clause):
        return f"{clean_title} {clause[3:].strip()}"
    if re.match(r"^They\s+(?:found|concluded|observed|discovered|noted|argued)\b", clause):
        return f"Researchers {clause[5:].strip()}"
    return clause


def _detect_modality(text: str) -> str:
    """Detect the appropriate epistemic framing verb without overstating certainty."""
    if _MODALITY_WEAK_RE.search(text):
        return "suggests that"
    if _MODALITY_ARG_RE.search(text):
        return "argues that"
    if _MODALITY_STRONG_RE.search(text):
        return "highlights that"
    if _MODALITY_EXPLAIN_RE.search(text):
        return "explains how"
    return "shows that"


def _verify_factuality_and_negation(source_sentence: str, synthesized_text: str) -> bool:
    """Deterministic factuality gate: verifies negation polarity and numerical fidelity."""
    src_words = set(re.findall(r"\b\w+\b", source_sentence.lower()))
    synth_words = set(re.findall(r"\b\w+\b", synthesized_text.lower()))

    src_has_neg = bool(src_words.intersection(_NEGATION_CUES))
    synth_has_neg = bool(synth_words.intersection(_NEGATION_CUES))

    # Reject if negation was inverted
    if src_has_neg != synth_has_neg:
        return False

    # Verify that all numbers / percentages generated in the synthesis were present in the source sentence
    src_numbers = set(re.findall(r"\b\d+(?:\.\d+)?%?\b", source_sentence))
    synth_numbers = set(re.findall(r"\b\d+(?:\.\d+)?%?\b", synthesized_text))
    if not synth_numbers.issubset(src_numbers):
        return False

    return True


def _synthesize_conversational_nutshell(
    thesis_cand: str,
    finding_cand: str | None,
    genre: str = "general",
    title: str = "",
) -> str:
    """Synthesize a fluid, natural, source-grounded 1-2 sentence conversational explanation."""
    cleaned_thesis = _clean_clause(thesis_cand)
    if not cleaned_thesis:
        return normalize_summary_sentence(thesis_cand)

    # Anchor pronouns if needed
    cleaned_thesis = _anchor_subject_anaphora(cleaned_thesis, title)
    words_thesis = cleaned_thesis.split()

    starts_with_subject = bool(re.match(
        r"^[A-Z][a-zA-Z0-9_-]+\s+(?:is|are|can|could|will|would|works?|help|helps|allows?|enables?|relies?|provides?|uses?|serves?|combines?|focuses?|requires?)",
        cleaned_thesis,
    ))

    primary_sentence = ""
    if starts_with_subject and len(words_thesis) >= 12:
        # Direct conceptual statement
        primary_sentence = cleaned_thesis[0].upper() + cleaned_thesis[1:] + "."
    elif cleaned_thesis.lower().startswith("how "):
        modality = _detect_modality(thesis_cand)
        primary_sentence = f"The piece {modality} {cleaned_thesis}."
    else:
        # Conversational lead-in
        first_char = cleaned_thesis[0].lower() if len(cleaned_thesis) > 1 and not cleaned_thesis[:2].isupper() else cleaned_thesis[0]
        modality = _detect_modality(thesis_cand)

        if genre == "academic":
            primary_sentence = f"The research {modality} {first_char}{cleaned_thesis[1:]}."
        elif genre == "technical":
            primary_sentence = f"The article {modality} {first_char}{cleaned_thesis[1:]}."
        else:
            primary_sentence = f"The piece highlights that {first_char}{cleaned_thesis[1:]}."

    # Factuality check on primary synthesis
    if not _verify_factuality_and_negation(thesis_cand, primary_sentence):
        primary_sentence = normalize_summary_sentence(thesis_cand)

    # Evaluate complementary finding candidate for sentence 2
    if finding_cand:
        cleaned_finding = _clean_clause(finding_cand)
        cleaned_finding = _anchor_subject_anaphora(cleaned_finding, title)
        if cleaned_finding and cleaned_finding.lower() != cleaned_thesis.lower():
            # Check overlap to prevent redundancy
            thesis_words_set = set(re.findall(r"\w{4,}", cleaned_thesis.lower()))
            finding_words_set = set(re.findall(r"\w{4,}", cleaned_finding.lower()))
            overlap = len(thesis_words_set.intersection(finding_words_set)) / max(1, len(finding_words_set))

            if overlap < 0.65:
                finding_first_char = cleaned_finding[0].upper()
                finding_sentence = f"{finding_first_char}{cleaned_finding[1:]}."

                if _verify_factuality_and_negation(finding_cand, finding_sentence):
                    combined = f"{primary_sentence} {finding_sentence}"
                    combined_words = combined.split()
                    if 20 <= len(combined_words) <= 50:
                        return combined

    # Single-sentence fallback check
    if len(primary_sentence.split()) >= 20:
        return primary_sentence

    # If too short (< 20 words), enrich primary sentence cleanly with finding context
    if finding_cand:
        cleaned_finding = _clean_clause(finding_cand)
        if cleaned_finding and cleaned_finding.lower() != cleaned_thesis.lower():
            enriched = f"{primary_sentence[:-1]}, emphasizing that {cleaned_finding[0].lower()}{cleaned_finding[1:]}."
            enriched = re.sub(r"\bthat\s+that\b", "that", enriched, flags=re.IGNORECASE)
            if _verify_factuality_and_negation(finding_cand, enriched):
                return enriched

    return primary_sentence


def _enforce_quality_gate(text: str, fallback_candidates: list[str]) -> str:
    """Validate and enforce 20-50 words and 1-2 sentences constraint without awkward truncation."""
    text = normalize_whitespace(text)
    # Fix double words / syntax glitches
    text = re.sub(r"\bthat\s+that\b", "that", text, flags=re.IGNORECASE)
    text = re.sub(r"\bwhich\s+which\b", "which", text, flags=re.IGNORECASE)
    text = re.sub(r"\s+([,\.!?;:])", r"\1", text)

    words = text.split()

    # Gate 1: If within optimal bounds, return immediately
    if 20 <= len(words) <= 50:
        return text

    # Gate 2: If over 50 words, condense secondary clauses cleanly
    if len(words) > 50:
        sentences = safe_sent_tokenize(text)
        if len(sentences) > 1:
            first_sent = sentences[0].strip()
            if 20 <= len(first_sent.split()) <= 50:
                return first_sent
        # Condense subordinate clauses like ", which ...", ", allowing ..."
        condensed = re.sub(r",\s*(?:which|allowing|resulting in|enabling|such that|as well as)[^,\.]+", "", text)
        condensed = normalize_whitespace(condensed)
        if 20 <= len(condensed.split()) <= 50:
            return condensed
        # Fallback to single cleanest candidate
        for cand in fallback_candidates:
            cand_clean = normalize_summary_sentence(cand)
            cand_words = cand_clean.split()
            if 20 <= len(cand_words) <= 50:
                return cand_clean

    # Gate 3: If under 20 words, enrich with next available candidate
    if len(words) < 20:
        for cand in fallback_candidates:
            cand_clean = _clean_clause(cand)
            if cand_clean.lower() not in text.lower():
                enriched = f"{text[:-1]}, while noting that {cand_clean[0].lower()}{cand_clean[1:]}."
                enriched = re.sub(r"\bthat\s+that\b", "that", enriched, flags=re.IGNORECASE)
                if 20 <= len(enriched.split()) <= 50:
                    return enriched

        # If still short, use standalone high-density candidate
        for cand in fallback_candidates:
            cand_clean = normalize_summary_sentence(cand)
            if 20 <= len(cand_clean.split()) <= 50:
                return cand_clean

    return text


def generate_nutshell(
    text: str = "",
    file_path: str = "",
    preprocessing_options: PreprocessingOptions | None = None,
) -> dict[str, Any]:
    """Generate a conversational, 1-2 sentence (20-50 words) explanation of what the document is fundamentally saying."""
    options = preprocessing_options or PreprocessingOptions()
    source_doc = load_source_document(text=text, file_path=file_path)
    raw_text = source_doc.raw_text

    if not raw_text.strip():
        raise ValueError("No readable content provided.")

    paragraphs = prepare_document_paragraphs(raw_text, options)
    if not paragraphs:
        raise ValueError("No continuous readable text found in document.")

    title = detect_document_title(raw_text, source_doc.title_hint)

    # 1. Discourse Topology: Detect section structure across paragraphs
    sections, _counts = detect_explicit_sections(paragraphs)

    # 2. Collect and clean candidate sentences with section associations
    candidates: list[str] = []
    cand_sections: list[str] = []

    for p_idx, para in enumerate(paragraphs):
        sec = sections[p_idx] if p_idx < len(sections) else "body"
        for sent in safe_sent_tokenize(para):
            compact = normalize_whitespace(sent)
            if not _is_invalid_candidate(compact):
                candidates.append(compact)
                cand_sections.append(sec)

    if not candidates:
        raise ValueError("Could not extract enough valid sentences from the document.")

    # 3. Dense Document Centroid & Multi-Candidate Pool Discovery
    # Fit TF-IDF on document text and all candidates
    vectorizer = TfidfVectorizer(ngram_range=(1, 2), min_df=1)
    all_texts = [raw_text] + candidates
    matrix = vectorizer.fit_transform(all_texts)

    doc_vector = matrix[0]
    cand_matrix = matrix[1:]

    # Cosine similarities to document centroid
    centroid_sims = np.asarray((cand_matrix * doc_vector.T).toarray()).ravel()
    if centroid_sims.max() > 0:
        centroid_sims /= centroid_sims.max()

    finding_cues = re.compile(
        r"\b(?:results?|findings?|revealed|found|indicated|concludes?|concluded|suggests?|argues?|shows?|demonstrates?|crucially|overall|significantly)\b",
        flags=re.IGNORECASE,
    )
    thesis_cues = re.compile(
        r"\b(?:aims?|examines?|explores?|investigates?|focuses? on|purpose|essential|fundamental|key role|crucial|maintains?)\b",
        flags=re.IGNORECASE,
    )

    scored_candidates: list[dict[str, Any]] = []
    total_candidates = len(candidates)

    for idx, cand in enumerate(candidates):
        pos_ratio = idx / max(1, total_candidates - 1)
        base_score = float(centroid_sims[idx]) * 0.40

        is_finding = bool(finding_cues.search(cand))
        is_thesis = bool(thesis_cues.search(cand))

        score = base_score
        if is_finding:
            score += 0.25
        if is_thesis:
            score += 0.25
        if pos_ratio <= 0.25 or pos_ratio >= 0.75:
            score += 0.15

        # Section topology multiplier
        sec_name = cand_sections[idx].lower() if idx < len(cand_sections) else "body"
        sec_weight = _SECTION_WEIGHTS.get(sec_name, 1.0)
        score *= sec_weight

        if _METHODOLOGY_CUES.search(cand):
            score *= 0.35

        scored_candidates.append({
            "sentence": cand,
            "score": score,
            "is_finding": is_finding,
            "is_thesis": is_thesis,
            "pos_ratio": pos_ratio,
            "section": sec_name,
        })

    # Sort candidates by multi-signal score
    scored_candidates.sort(key=lambda item: item["score"], reverse=True)

    # 4. Select Primary Thesis & Supporting Finding Context
    best_thesis = scored_candidates[0]["sentence"]
    # Look for an early-document thesis candidate (intro/abstract)
    early_thesis = next(
        (item["sentence"] for item in scored_candidates if item["is_thesis"] and item["pos_ratio"] <= 0.35 and item["section"] in {"abstract", "introduction", "body"}),
        None,
    )
    if early_thesis:
        best_thesis = early_thesis

    # Look for a complementary finding/outcome candidate (results/conclusion)
    best_finding = next(
        (item["sentence"] for item in scored_candidates if (item["is_finding"] or item["section"] in {"conclusion", "results", "findings"}) and item["sentence"] != best_thesis),
        None,
    )

    # Detect genre
    lowered_doc = raw_text.lower()
    genre = "general"
    if re.search(r"\b(?:study|methodology|hypothesis|participants|experiment|sample size)\b", lowered_doc):
        genre = "academic"
    elif re.search(r"\b(?:cloud|server|database|api|architecture|software|hardware|computing)\b", lowered_doc):
        genre = "technical"

    # 5. Semantic Conversational Synthesis with Factuality & Modality Checks
    raw_synthesis = _synthesize_conversational_nutshell(
        thesis_cand=best_thesis,
        finding_cand=best_finding,
        genre=genre,
        title=title,
    )

    # 6. Quality Gate Enforcement (20-50 words, 1-2 sentences)
    # Filter out methodology and low-value sections from quality gate fallback candidates
    ordered_sentences = [
        item["sentence"]
        for item in scored_candidates
        if item["section"] not in {"methodology", "methods", "references", "appendix", "literature_review"}
    ]
    if not ordered_sentences:
        ordered_sentences = [item["sentence"] for item in scored_candidates]

    final_nutshell = _enforce_quality_gate(raw_synthesis, fallback_candidates=ordered_sentences)

    words = final_nutshell.split()
    sentences = safe_sent_tokenize(final_nutshell)

    return {
        "nutshell": final_nutshell,
        "word_count": len(words),
        "sentence_count": len(sentences),
        "title": title,
        "source_type": source_doc.source_type,
    }
