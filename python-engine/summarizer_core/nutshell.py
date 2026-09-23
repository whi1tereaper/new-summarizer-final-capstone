"""Nutshell Analyzer for coherent document-level synthesis."""

from __future__ import annotations

import re
from typing import Any

import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer

from .models import PreprocessingOptions
from .text_utils import (
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

_NEGATION_CUES = {"not", "never", "no", "neither", "nor", "barely", "hardly", "without", "failed", "fails", "cannot", "unable"}


def _is_invalid_candidate(sentence: str) -> bool:
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
    non_alpha = len(re.findall(r"[^a-zA-Z\s]", compact))
    if len(compact) > 0 and (non_alpha / len(compact)) > 0.35:
        return True
    return False


def _clean_clause(sentence: str) -> str:
    cleaned = normalize_summary_sentence(sentence)
    cleaned = _ACADEMIC_PREAMBLE_RE.sub("", cleaned).strip()
    cleaned = _LEADING_VERB_RE.sub("", cleaned).strip()
    cleaned = re.sub(
        r"^(?:furthermore|moreover|additionally|however|nevertheless|therefore|thus|consequently)\s*,?\s*",
        "",
        cleaned,
        flags=re.IGNORECASE,
    ).strip()
    if cleaned.lower().startswith("that "):
        cleaned = cleaned[5:].strip()
    if cleaned.endswith("."):
        cleaned = cleaned[:-1].strip()
    cleaned = re.sub(r"\[\d+(?:[,\s\-–\d]*)*\]", "", cleaned)
    cleaned = re.sub(r"\([A-Z][a-zA-Z\s,]+(?:et\s+al\.? )?,?\s*\d{4}\)", "", cleaned)
    cleaned = normalize_whitespace(cleaned)
    return cleaned


def _anchor_subject_anaphora(clause: str, title: str) -> str:
    if not clause or not title:
        return clause
    clean_title = re.sub(r"^(?:the\s+impact\s+of|an\s+overview\s+of|a\s+study\s+on|module\s+\d+:?)\s*", "", title, flags=re.IGNORECASE).strip()
    if not clean_title or len(clean_title.split()) > 7:
        clean_title = "The system" if "system" in title.lower() else "The document"
    if re.match(r"^It\s+(?:is|are|provides?|helps?|allows?|enables?|relies?|shows?|demonstrates?)\b", clause):
        return f"{clean_title} {clause[3:].strip()}"
    if re.match(r"^They\s+(?:found|concluded|observed|discovered|noted|argued)\b", clause):
        return f"Researchers {clause[5:].strip()}"
    return clause


def _select_diverse_summary_candidates(scored_candidates: list[dict[str, Any]], limit: int = 4) -> list[str]:
    selected: list[str] = []
    seen_signatures: set[str] = set()

    for item in scored_candidates:
        sentence = _clean_clause(item["sentence"])
        if not sentence:
            continue
        section = (item.get("section") or "body").lower()
        if section in {"references", "appendix", "literature_review"}:
            continue
        if len(sentence.split()) < 8:
            continue

        signature = re.sub(r"[^a-z0-9]+", " ", sentence.lower()).strip()
        if not signature or signature in seen_signatures:
            continue
        seen_signatures.add(signature)
        selected.append(sentence)
        if len(selected) >= limit:
            break

    return selected


def _rewrite_topic_sentence(thesis: str, title: str = "") -> str:
    cleaned = _clean_clause(thesis)
    if not cleaned:
        return "The document presents a focused overview of the key ideas and arguments."

    cleaned = _anchor_subject_anaphora(cleaned, title)
    cleaned = re.sub(r"^(?:the document|this paper|the paper|this article|the article|the report|the study)\s+", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"^(?:examines?|explores?|investigates?|analyzes?|outlines?|describes?|presents?|focuses on|addresses?)\s+", "", cleaned, flags=re.IGNORECASE)

    if cleaned and cleaned[0].islower():
        cleaned = cleaned[0].upper() + cleaned[1:]

    if re.search(r"\b(?:is|are|focuses|examines|explores|investigates|analyzes|outlines|describes|addresses|presents|considers|reviews)\b", cleaned, re.IGNORECASE):
        if not re.match(r"^The document\b", cleaned, re.IGNORECASE):
            return f"The document {cleaned}."
        return f"{cleaned}."

    if title and title.strip() and len(title.split()) <= 12:
        return f"The document examines {cleaned}."

    return f"The document presents {cleaned}."


def _rewrite_supporting_clause(text: str) -> str:
    cleaned = _clean_clause(text)
    if not cleaned:
        return ""

    cleaned = _anchor_subject_anaphora(cleaned, "")
    cleaned = re.sub(r"^(?:the document|this paper|the paper|this article|the article|the report|the study|it|this)\s+", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"^(?:examines?|explores?|investigates?|analyzes?|outlines?|describes?|presents?|focuses on|addresses?|reviews?|covers?)\s+", "", cleaned, flags=re.IGNORECASE)

    if re.search(r"\b(?:should|recommend|recommends|concludes|concluded|suggests?|argues?|highlights?|illustrates?|compares?)\b", cleaned, flags=re.IGNORECASE):
        return cleaned[0].upper() + cleaned[1:] + "." if not cleaned.endswith(".") else cleaned

    if cleaned.endswith("."):
        return cleaned
    return f"It also examines {cleaned}."


def _synthesize_conversational_nutshell(thesis_cand: str, supporting_candidates: list[str], title: str = "") -> str:
    summary_sentences: list[str] = []

    thesis_sentence = _rewrite_topic_sentence(thesis_cand, title)
    summary_sentences.append(thesis_sentence)

    support_details: list[str] = []
    seen_support_signatures: set[str] = set()
    for cand in supporting_candidates:
        rewritten = _rewrite_supporting_clause(cand)
        signature = re.sub(r"[^a-z0-9]+", " ", rewritten.lower()).strip()
        if rewritten and signature and signature not in seen_support_signatures:
            seen_support_signatures.add(signature)
            support_details.append(rewritten)
        if len(support_details) >= 3:
            break

    if support_details:
        summary_sentences.append(" ".join(support_details[:2]))

    paragraph = normalize_whitespace(" ".join(summary_sentences))
    paragraph = re.sub(r"\s+([,\.!?;:])", r"\1", paragraph)
    paragraph = re.sub(r"\s{2,}", " ", paragraph).strip()
    if not paragraph.endswith("."):
        paragraph += "."
    return paragraph


def _detect_target_word_range(text: str) -> tuple[int, int]:
    """Use a wider range for document sets that span multiple sections and brand attributes."""
    brand_markers = re.findall(r"\b(?:brand|logo|audience|tone|personality|identity|campaign|marketing|visual|typography|health-conscious)\b", text, re.IGNORECASE)
    if len(set(marker.lower() for marker in brand_markers)) >= 2:
        return (80, 160)
    return (20, 50)


def _enforce_quality_gate(text: str, fallback_candidates: list[str], target_range: tuple[int, int] | None = None) -> str:
    text = normalize_whitespace(text)
    text = re.sub(r"\bthat\s+that\b", "that", text, flags=re.IGNORECASE)
    text = re.sub(r"\bwhich\s+which\b", "which", text, flags=re.IGNORECASE)
    text = re.sub(r"\s+([,\.!?;:])", r"\1", text)

    if target_range is None:
        target_range = (20, 50)
    min_words, max_words = target_range

    words = text.split()
    if min_words <= len(words) <= max_words:
        return text

    if len(words) > max_words:
        sentences = safe_sent_tokenize(text)
        if sentences:
            for sentence in sentences:
                sentence_text = normalize_whitespace(sentence)
                if min_words <= len(sentence_text.split()) <= max_words:
                    return sentence_text
            for sentence in sentences:
                sentence_text = normalize_whitespace(sentence)
                if len(sentence_text.split()) < max_words:
                    return sentence_text
            trimmed = " ".join(sentences[:2])
            if min_words <= len(trimmed.split()) <= max_words:
                return normalize_whitespace(trimmed)
        for cand in fallback_candidates:
            cand_clean = normalize_summary_sentence(cand)
            if min_words <= len(cand_clean.split()) <= max_words:
                return cand_clean

    if len(words) < min_words:
        for cand in fallback_candidates:
            cand_clean = normalize_summary_sentence(cand)
            if cand_clean and cand_clean.lower() not in text.lower():
                enriched = f"{text.rstrip('.')}. {cand_clean}"
                if min_words <= len(enriched.split()) <= max_words:
                    return normalize_whitespace(enriched)
        for cand in fallback_candidates:
            cand_clean = normalize_summary_sentence(cand)
            if min_words <= len(cand_clean.split()) <= max_words:
                return cand_clean

    return text


def generate_nutshell(
    text: str = "",
    file_path: str = "",
    preprocessing_options: PreprocessingOptions | None = None,
) -> dict[str, Any]:
    options = preprocessing_options or PreprocessingOptions()
    source_doc = load_source_document(text=text, file_path=file_path)
    raw_text = source_doc.raw_text

    if not raw_text.strip():
        raise ValueError("No readable content provided.")

    paragraphs = prepare_document_paragraphs(raw_text, options)
    if not paragraphs:
        raise ValueError("No continuous readable text found in document.")

    title = detect_document_title(raw_text, source_doc.title_hint)
    sections, _counts = detect_explicit_sections(paragraphs)

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

    vectorizer = TfidfVectorizer(ngram_range=(1, 2), min_df=1)
    all_texts = [raw_text] + candidates
    matrix = vectorizer.fit_transform(all_texts)
    doc_vector = matrix[0]
    cand_matrix = matrix[1:]

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

    scored_candidates.sort(key=lambda item: item["score"], reverse=True)

    best_thesis = scored_candidates[0]["sentence"]
    early_thesis = next(
        (item["sentence"] for item in scored_candidates if item["is_thesis"] and item["pos_ratio"] <= 0.35 and item["section"] in {"abstract", "introduction", "body"}),
        None,
    )
    if early_thesis:
        best_thesis = early_thesis

    if re.search(r"\b(?:brand|logo|audience|tone|personality|platform|identity|visual|campaign|marketing|strategy)\b", raw_text, re.IGNORECASE):
        brand_thesis = next(
            (
                item["sentence"]
                for item in scored_candidates
                if item["section"] in {"abstract", "introduction", "body"} and re.search(r"\b(?:brand|logo|audience|tone|personality|platform|identity|visual|campaign|marketing|strategy)\b", item["sentence"], re.IGNORECASE)
            ),
            None,
        )
        if brand_thesis:
            best_thesis = brand_thesis

    best_finding = next(
        (item["sentence"] for item in scored_candidates if (item["is_finding"] or item["section"] in {"conclusion", "results", "findings"}) and item["sentence"] != best_thesis),
        None,
    )

    target_range = _detect_target_word_range(raw_text)
    supporting_candidates = _select_diverse_summary_candidates(scored_candidates)
    if best_finding and best_finding not in supporting_candidates:
        supporting_candidates.insert(0, best_finding)

    if target_range == (80, 160):
        brand_candidates = [
            item["sentence"]
            for item in sorted(scored_candidates, key=lambda item: item["pos_ratio"])
            if re.search(r"\b(?:logo|palette|identity|tone|personality|audience|platform|visual|typography|voice|lifestyle|engagement)\b", item["sentence"], re.IGNORECASE)
        ]
        supporting_candidates = list(dict.fromkeys(brand_candidates))

    raw_synthesis = _synthesize_conversational_nutshell(
        thesis_cand=best_thesis,
        supporting_candidates=supporting_candidates,
        title=title,
    )

    ordered_sentences = [
        item["sentence"]
        for item in scored_candidates
        if item["section"] not in {"methodology", "methods", "references", "appendix", "literature_review"}
    ]
    if not ordered_sentences:
        ordered_sentences = [item["sentence"] for item in scored_candidates]

    final_nutshell = _enforce_quality_gate(raw_synthesis, ordered_sentences, target_range=target_range)
    words = final_nutshell.split()
    sentences = safe_sent_tokenize(final_nutshell)

    return {
        "nutshell": final_nutshell,
        "word_count": len(words),
        "sentence_count": len(sentences),
        "title": title,
        "source_type": source_doc.source_type,
    }
