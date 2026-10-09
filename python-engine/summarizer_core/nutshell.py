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
_LOW_PRIORITY_CUES = re.compile(
    r"\b(?:public consultation|meetings? (?:in|across)|online survey|survey responses?|earlier products?|clinic locations?|staff training|recruitment process|historical background|will review|reviews? .* after)\b",
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

ALGORITHM_VERSION = "nutshell-extractive-v2"
SUPPORTED_ANALYSIS_MODES = {"general", "academic", "executive", "technical", "study", "news"}
_MODE_CUES: dict[str, re.Pattern[str]] = {
    "general": re.compile(r"\b(?:main|central|overall|key|result|finding|conclusion|because|therefore)\b", re.I),
    "academic": re.compile(r"\b(?:research question|hypothesis|evidence|findings?|results?|conclusion|implications?|significant|significance)\b", re.I),
    "executive": re.compile(r"\b(?:decision|recommend(?:s|ed|ation)?|risk|cost|revenue|impact|action|next step|outcome|priority)\b", re.I),
    "technical": re.compile(r"\b(?:architecture|architectures|system|implementation|parameter|constraint|latency|throughput|performance|failure|protocol|algorithm|cloud)\b", re.I),
    "study": re.compile(r"\b(?:defines?|definition|concept|principle|means|refers to|relationship|causes?|key idea|demonstrates?)\b", re.I),
    "news": re.compile(r"\b(?:announced|reported|according to|officials?|company|government|today|yesterday|on \w+ \d{1,2}|\d{4})\b", re.I),
}
_QUALIFIER_CUES = re.compile(r"\b(?:not|never|no|neither|nor|failed|without|may|might|could|possibly|likely|unlikely|approximately|about|at least|up to|only|statistically significant|not significant)\b", re.I)
_NUMBER_CUES = re.compile(r"(?:\b\d+(?:\.\d+)?\s*(?:%|percent|percentage points|years?|months?|days?|participants?|patients?|cases?|units?|mg|kg|km|\$|million|billion)?\b|\b(?:19|20)\d{2}\b)", re.I)


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
    analysis_mode: str = "general",
    primary_summary_word_count: int = 0,
) -> dict[str, Any]:
    options = preprocessing_options or PreprocessingOptions()
    source_doc = load_source_document(text=text, file_path=file_path)
    raw_text = source_doc.raw_text

    if not raw_text.strip():
        return {"status": "insufficient_content", "reason": "empty_source", "nutshell": "", "word_count": 0, "source_word_count": 0, "primary_summary_word_count": max(0, int(primary_summary_word_count)), "compression_ratio": 0.0, "analysis_mode": _normalize_analysis_mode(analysis_mode), "format": "paragraph", "algorithm_version": ALGORITHM_VERSION}

    primary_words = max(0, int(primary_summary_word_count))
    if primary_words and primary_words <= 20:
        return {"status": "not_needed", "reason": "primary_summary_already_concise", "nutshell": "", "word_count": 0, "source_word_count": len(tokenize_words(raw_text)), "primary_summary_word_count": primary_words, "compression_ratio": 0.0, "analysis_mode": _normalize_analysis_mode(analysis_mode), "format": "paragraph", "algorithm_version": ALGORITHM_VERSION}

    paragraphs = prepare_document_paragraphs(raw_text, options)
    if not paragraphs:
        return {"status": "insufficient_content", "reason": "no_readable_content", "nutshell": "", "word_count": 0, "source_word_count": 0, "primary_summary_word_count": max(0, int(primary_summary_word_count)), "compression_ratio": 0.0, "analysis_mode": _normalize_analysis_mode(analysis_mode), "format": "paragraph", "algorithm_version": ALGORITHM_VERSION}

    title = detect_document_title(raw_text, source_doc.title_hint)
    sections, _counts = detect_explicit_sections(paragraphs)

    candidates: list[str] = []
    cand_sections: list[str] = []
    # The title detector can mistake a complete lead sentence for a title.
    # Keep a sentence-shaped lead available as a candidate instead of silently
    # dropping the document's central proposition.
    title_lead = normalize_whitespace(title)
    if title_lead.endswith((".", "!", "?")) and not _is_invalid_candidate(title_lead):
        candidates.append(title_lead)
        cand_sections.append("abstract")
    for p_idx, para in enumerate(paragraphs):
        sec = sections[p_idx] if p_idx < len(sections) else "body"
        for sent in safe_sent_tokenize(para):
            compact = normalize_whitespace(sent)
            if not _is_invalid_candidate(compact):
                candidates.append(compact)
                cand_sections.append(sec)

    if not candidates:
        return {"status": "insufficient_content", "reason": "no_complete_sentences", "nutshell": "", "word_count": 0, "source_word_count": len(tokenize_words(raw_text)), "primary_summary_word_count": max(0, int(primary_summary_word_count)), "compression_ratio": 0.0, "analysis_mode": _normalize_analysis_mode(analysis_mode), "format": "paragraph", "algorithm_version": ALGORITHM_VERSION}

    vectorizer = TfidfVectorizer(ngram_range=(1, 2), min_df=1)
    all_texts = [raw_text] + candidates
    matrix = vectorizer.fit_transform(all_texts)
    doc_vector = matrix[0]
    cand_matrix = matrix[1:]

    centroid_sims = np.asarray((cand_matrix * doc_vector.T).toarray()).ravel()
    if centroid_sims.max() > 0:
        centroid_sims /= centroid_sims.max()

    mode = _normalize_analysis_mode(analysis_mode)
    finding_cues = re.compile(
        r"\b(?:results?|findings?|revealed|found|indicated|concludes?|concluded|suggests?|argues?|shows?|demonstrates?|crucially|overall|significantly|fund(?:s|ed|ing)?|provide(?:s|d)?|deliver(?:s|ed)?|increase|decrease|raise|reduce|delay|cancel|approve|reject)\b",
        flags=re.IGNORECASE,
    )
    thesis_cues = re.compile(
        r"\b(?:aims?|examines?|explores?|investigates?|focuses? on|purpose|essential|fundamental|key role|crucial|maintains?|enables?|provides?|allows?|supports?)\b",
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
            score += 0.04
        if pos_ratio <= 0.10:
            score += 0.15

        # Preserve central facts and meaning-changing qualifiers through compression.
        if _NUMBER_CUES.search(cand):
            score += 0.22
        if _QUALIFIER_CUES.search(cand):
            score += 0.42
        if _QUALIFIER_CUES.search(cand) and finding_cues.search(cand):
            score += 0.38
        if _MODE_CUES[mode].search(cand):
            score += 0.48

        sec_name = cand_sections[idx].lower() if idx < len(cand_sections) else "body"
        sec_weight = _SECTION_WEIGHTS.get(sec_name, 1.0)
        score *= sec_weight
        if _METHODOLOGY_CUES.search(cand):
            score *= 0.35
        if _LOW_PRIORITY_CUES.search(cand):
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

    best_finding = next(
        (item["sentence"] for item in scored_candidates if (item["is_finding"] or item["section"] in {"conclusion", "results", "findings"}) and item["sentence"] != best_thesis),
        None,
    )

    source_word_count = len(tokenize_words(raw_text))
    density = min(1.0, (len(set(re.findall(r"\b\d+(?:\.\d+)?%?\b", raw_text))) + len(re.findall(r"\b(?:however|although|because|therefore|resulted|increased|decreased|significant)\b", raw_text, re.I))) / 16)
    category_factor = 1.12 if mode in {"academic", "technical"} else (0.92 if mode == "executive" else 1.0)
    target_words = min(50, max(36, round(source_word_count * (0.14 + 0.04 * density) * category_factor)))
    if source_word_count <= 40:
        target_words = max(8, min(target_words, round(source_word_count * 0.65)))
    if primary_words:
        target_words = max(8, min(target_words, primary_words - 1))

    selected: list[str] = []
    selected_signatures: set[str] = set()
    used_words = 0
    for item in scored_candidates:
        sentence = normalize_whitespace(item["sentence"])
        if item["section"] in {"references", "appendix", "literature_review"}:
            continue
        count = len(tokenize_words(sentence))
        signature = re.sub(r"[^a-z0-9]+", " ", sentence.lower()).strip()
        if not signature or signature in selected_signatures or count < 6:
            continue
        if used_words + count > target_words and selected:
            continue
        if count > 50:
            continue
        selected.append(sentence)
        selected_signatures.add(signature)
        used_words += count
        if len(selected) >= 3:
            break

    # Meaning-changing qualifiers outrank the nominal budget when they fit
    # within the absolute compact-output ceiling.
    if used_words < 50 and not _QUALIFIER_CUES.search(" ".join(selected)):
        for item in scored_candidates:
            candidate = normalize_whitespace(item["sentence"])
            count = len(tokenize_words(candidate))
            if _QUALIFIER_CUES.search(candidate) and candidate not in selected and used_words + count <= 50:
                selected.append(candidate)
                used_words += count
                break

    # Keep an essential cohort-size fact alongside an outcome when the source
    # states it explicitly and the concise budget has room.
    if used_words < 50 and not re.search(r"\b(?:\d[\d,.]*\s*(?:participants?|patients?|adults?|children|people|subjects?))\b", " ".join(selected), re.I):
        for item in scored_candidates:
            candidate = normalize_whitespace(item["sentence"])
            count = len(tokenize_words(candidate))
            if (re.search(r"\b\d[\d,.]*\s*(?:participants?|patients?|adults?|children|people|subjects?)\b", candidate, re.I)
                    and candidate not in selected and used_words + count <= 50):
                selected.append(candidate)
                used_words += count
                break

    if not selected:
        return {"status": "insufficient_content", "reason": "no_complete_sentence_fits_meaningful_budget", "nutshell": "", "word_count": 0, "source_word_count": source_word_count, "primary_summary_word_count": primary_words, "compression_ratio": 0.0, "analysis_mode": mode, "format": "paragraph", "algorithm_version": ALGORITHM_VERSION}

    final_nutshell = normalize_whitespace(" ".join(selected))
    words = tokenize_words(final_nutshell)
    sentences = safe_sent_tokenize(final_nutshell)
    if not final_nutshell or re.search(r"<\s*/?\s*[a-z][^>]*>", final_nutshell, re.I) or len(words) >= source_word_count:
        return {"status": "insufficient_content", "reason": "quality_validation_failed", "nutshell": "", "word_count": 0, "source_word_count": source_word_count, "primary_summary_word_count": primary_words, "compression_ratio": 0.0, "analysis_mode": mode, "format": "paragraph", "algorithm_version": ALGORITHM_VERSION}
    if primary_words and len(words) >= primary_words:
        return {"status": "not_needed", "reason": "not_shorter_than_primary_summary", "nutshell": "", "word_count": 0, "source_word_count": source_word_count, "primary_summary_word_count": primary_words, "compression_ratio": 0.0, "analysis_mode": mode, "format": "paragraph", "algorithm_version": ALGORITHM_VERSION}

    return {
        "nutshell": final_nutshell,
        "word_count": len(words),
        "sentence_count": len(sentences),
        "title": title,
        "source_type": source_doc.source_type,
        "status": "completed",
        "source_word_count": source_word_count,
        "primary_summary_word_count": primary_words,
        "compression_ratio": round(len(words) / max(1, source_word_count), 4),
        "analysis_mode": mode,
        "format": "paragraph",
        "algorithm_version": ALGORITHM_VERSION,
    }


def _normalize_analysis_mode(value: str) -> str:
    normalized = str(value or "general").strip().lower()
    return normalized if normalized in SUPPORTED_ANALYSIS_MODES else "general"
