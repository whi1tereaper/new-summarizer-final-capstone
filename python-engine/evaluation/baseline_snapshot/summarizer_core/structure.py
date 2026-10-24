"""Document structure, heading recognition, and title detection."""

from __future__ import annotations

from collections import Counter
import logging
import re
from typing import Any, Iterable

from .constants import (
    GENERIC_HEADINGS,
    PAGE_NUMBER_PATTERN,
    SECTION_ALIASES,
)

from .normalization import normalize_source_newlines, normalize_whitespace
from .tokenization import safe_sent_tokenize, tokenize_words

LOGGER = logging.getLogger(__name__)

def normalize_heading_candidate(text: str) -> str:
    normalized = normalize_whitespace(text).strip(":- ")
    # Strip "CHAPTER"/"SECTION" prefix before numeral stripping
    normalized = re.sub(r"^(?:chapter|section|part)\s+", "", normalized, flags=re.IGNORECASE)
    # Strip roman numeral or decimal prefixes (uppercase and lowercase)
    normalized = re.sub(r"^(?:\d+(?:\.\d+)*|[IVXLCDMivxlcdm]+)[\)\.\s-]+", "", normalized)
    normalized = normalized.strip(":- ")
    return normalized.lower()


def match_section_heading(text: str) -> str | None:
    raw_heading = normalize_whitespace(text).strip(":- ")
    tokens = tokenize_words(raw_heading)
    if not tokens or len(tokens) > 8:
        return None
    normalized = normalize_heading_candidate(text)
    if normalized == "":
        return None
    for section_key, aliases in SECTION_ALIASES.items():
        for alias in aliases:
            alias_tokens = len(alias.split())
            if normalized == alias:
                return section_key
            if normalized.startswith(alias + " ") and len(tokens) <= alias_tokens + 3:
                return section_key
    if normalized == "results and discussion" or (normalized.startswith("results and discussion") and len(tokens) <= 6):
        return "discussion"
    if normalized.startswith("discussion and conclusion") and len(tokens) <= 6:
        return "conclusion"
    if len(tokens) <= 6:
        uppercase_letters = sum(character.isupper() for character in raw_heading if character.isalpha())
        alpha_letters = sum(character.isalpha() for character in raw_heading)
        if alpha_letters and (uppercase_letters / alpha_letters) >= 0.75:
            for section_key, aliases in SECTION_ALIASES.items():
                if any(alias in normalized for alias in aliases if len(alias.split()) >= 2):
                    return section_key
    return None


def _legacy_match_section_heading(text: str) -> str | None:
    candidate = normalize_heading_candidate(text)
    if candidate == "":
        return None

    for section_name, aliases in SECTION_ALIASES.items():
        for alias in aliases:
            if candidate == alias:
                return section_name
            if candidate.startswith(alias + " ") and len(tokenize_words(candidate)) <= len(alias.split()) + 3:
                return section_name

    if candidate.startswith("results and discussion") and len(tokenize_words(candidate)) <= 6:
        return "discussion"
    if candidate.startswith("discussion and conclusion") and len(tokenize_words(candidate)) <= 6:
        return "conclusion"

    return None


def is_possible_section_heading(text: str) -> bool:
    candidate = normalize_heading_candidate(text)
    if candidate == "":
        return False

    token_count = len(tokenize_words(candidate))
    if token_count == 0 or token_count > 9:
        return False

    for aliases in SECTION_ALIASES.values():
        for alias in aliases:
            if candidate == alias:
                return True
            if candidate.startswith(alias + " ") and token_count <= len(alias.split()) + 2:
                return True

    return False


def is_heading_only_text(text: str) -> bool:
    from .cleaning import is_section_number_noise
    compact = normalize_whitespace(text).strip(":- ")
    if compact == "":
        return True
    if is_section_number_noise(compact):
        return True
    if match_section_heading(compact) is not None and len(tokenize_words(compact)) <= 8:
        return True
    norm = normalize_heading_candidate(compact)
    if any(norm == h.lower() for h in GENERIC_HEADINGS) and len(tokenize_words(compact)) <= 8:
        return True
    return norm in {
        "abstract",
        "introduction",
        "methodology",
        "methods",
        "results",
        "results and discussion",
        "discussion",
        "conclusion",
        "recommendation",
        "recommendations",
        "references",
        "keywords",
        "acknowledgment",
    }


def extract_structural_heading(text: str) -> tuple[str | None, str]:
    """Detect and decouple leading structural headings from paragraph text.

    Returns (heading_metadata, prose_text).
    """
    compact = normalize_whitespace(text)
    if not compact:
        return None, ""

    prefix_match = re.match(
        r"^(?:(?:chapter|section|part)\s+\d+[:.\s-]+)?(?:[IVXLCDMivxlcdm]+|\d+(?:\.\d+)*)[\)\.\s-]+",
        compact,
        flags=re.IGNORECASE,
    )
    stripped = compact
    if prefix_match:
        stripped = compact[prefix_match.end():].strip()

    # 1. Check against GENERIC_HEADINGS sorted by length descending
    for heading in sorted(GENERIC_HEADINGS, key=len, reverse=True):
        escaped = re.escape(heading)
        if len(heading.split()) == 1:
            m = re.match(rf"^{escaped}(?:\s*[:]\s*|\s+[-–—]\s+|\s{{2,}})(.*)$", stripped, flags=re.IGNORECASE)
        else:
            m = re.match(rf"^{escaped}(?:\s*[:]\s*|\s+[-–—]\s+|\s{{2,}}|\s+(?=[A-Z0-9]))(.*)$", stripped, flags=re.IGNORECASE)
        if m:
            prose = m.group(1).strip()
            if prose:
                if prose[0].islower():
                    prose = prose[0].upper() + prose[1:]
                return heading, prose

    # 2. Dynamic heuristic detection for structural headings
    dynamic_match = re.match(
        r"^([A-Z][A-Za-z0-9/&', -]{2,45}?)(?:\s*[:]\s*|\s+[-–—]\s+|\s{2,})(.*)$",
        stripped,
    )
    if dynamic_match:
        candidate_heading = dynamic_match.group(1).strip()
        prose = dynamic_match.group(2).strip()
        words = candidate_heading.split()
        if 1 <= len(words) <= 6:
            lowered_words = [w.lower() for w in words]
            finite_verbs = {
                "is", "are", "was", "were", "will", "would", "has", "have", "had",
                "can", "could", "shall", "should", "does", "did", "do",
                "used", "uses", "using", "found", "finds", "showed", "shows",
                "conducted", "collected", "analyzed", "determined",
            }
            if not any(v in lowered_words for v in finite_verbs):
                if prose and prose[0].islower():
                    prose = prose[0].upper() + prose[1:]
                return candidate_heading, prose

    return None, compact


def strip_leading_heading_label(paragraph: str) -> str:
    heading, prose = extract_structural_heading(paragraph)
    if heading and prose:
        return normalize_whitespace(prose)

    compact = normalize_whitespace(paragraph)
    compact = re.sub(r"^(?:(?:chapter|section|part)\s+\d+[:.\s-]+)?(?:[IVXLCDMivxlcdm]+|\d+(?:\.\d+)*)[\)\.\s-]+", "", compact, flags=re.IGNORECASE)

    all_headings = set(GENERIC_HEADINGS) | {
        "perceived impact on coding skills and logical thinking",
        "ai utilization patterns",
        "autonomous learning and application",
        "coding performance and efficiency",
        "cognitive development",
    }
    multi_headings = [h for h in all_headings if len(h.split()) >= 2]
    pattern_str = "|".join(re.escape(h) for h in sorted(multi_headings, key=len, reverse=True))
    m = re.match(rf"^({pattern_str})(?:\s*[:\-–—]\s*|\s+)(.*)$", compact, re.IGNORECASE)
    if m:
        _heading, rest = m.group(1), m.group(2).strip()
        if rest:
            if rest[0].islower():
                rest = rest[0].upper() + rest[1:]
            compact = rest

    starter_match = re.search(
        r"\b(?:The|This|These|In|With|Results|Findings|Students|Researchers|Artificial|AI)\b",
        compact,
    )
    if starter_match and starter_match.start() >= 20:
        prefix = compact[:starter_match.start()]
        if prefix.count(",") >= 2 and not any(mark in prefix for mark in ".!?"):
            compact = compact[starter_match.start():]
    return normalize_whitespace(compact)


def restore_inline_section_breaks(text: str) -> str:
    normalized = normalize_source_newlines(text)
    multi_word_headings = [h for h in GENERIC_HEADINGS if len(h.split()) >= 2]
    multi_word_headings.extend([
        "Research Design",
        "Research Instrument",
        "Statistical Treatment",
        "Profile of the Respondents",
        "Perceived Impact on Coding Skills and Logical Thinking",
        "Autonomous Learning and Application",
        "AI Utilization Patterns",
    ])
    multi_pattern = "|".join(re.escape(h) for h in sorted(set(multi_word_headings), key=len, reverse=True))
    normalized = re.sub(
        rf"(?<=[.!?])\s+(?=(?:{multi_pattern})\b(?:\s*[:\-–—]|\s+[A-Z]))",
        "\n\n",
        normalized,
    )
    single_word_headings = [h for h in GENERIC_HEADINGS if len(h.split()) == 1]
    single_pattern = "|".join(re.escape(h) for h in sorted(set(single_word_headings), key=len, reverse=True))
    normalized = re.sub(
        rf"(?<=[.!?])\s+(?=(?:{single_pattern})\s*[:\-–—]\s*)",
        "\n\n",
        normalized,
    )
    return normalized



def looks_like_document_title_line(text: str) -> bool:
    compact = normalize_whitespace(text).strip(":- ")
    words = tokenize_words(compact)
    if len(words) < 6 or len(words) > SUMMARY_TITLE_MAX_WORDS:
        return False
    if compact.endswith((".", "!", "?", ":", ";")):
        return False
    if any(character.isdigit() for character in compact):
        return False
    capitalized_words = sum(
        1
        for word in compact.split()
        if word[:1].isupper() or word.lower() in {"of", "and", "the", "in", "on", "for", "to"}
    )
    return capitalized_words / max(len(compact.split()), 1) >= 0.72


def is_plausible_title(text: str) -> bool:
    normalized = normalize_whitespace(text)
    if normalized == "":
        return False
    if len(normalized) < 15 or len(normalized) > SUMMARY_TITLE_MAX_CHARS:
        return False
    if is_possible_section_heading(normalized):
        return False
    if "@" in normalized:
        return False
    if PAGE_NUMBER_PATTERN.fullmatch(normalized):
        return False
    word_count = len(tokenize_words(normalized))
    if word_count < 4 or word_count > SUMMARY_TITLE_MAX_WORDS:
        return False
    if normalized.endswith("."):
        return False
    if normalized.count(",") > 3:
        return False
    return True


def detect_document_title(raw_text: str, title_hint: str = "") -> str:
    from .cleaning import (
        is_page_number_line,
        looks_like_author_or_affiliation_line,
        looks_like_metadata_line,
        looks_like_navigation_noise,
        looks_like_publication_noise,
        looks_like_table_of_contents_entry,
    )
    if is_plausible_title(title_hint):
        return normalize_whitespace(title_hint)

    lines = []
    for line in normalize_source_newlines(raw_text).split("\n"):
        normalized = normalize_whitespace(line)
        if normalized == "":
            continue
        if is_page_number_line(normalized):
            continue
        if looks_like_metadata_line(normalized) or looks_like_navigation_noise(normalized):
            continue
        if looks_like_table_of_contents_entry(normalized):
            continue
        if looks_like_publication_noise(normalized) or looks_like_author_or_affiliation_line(normalized):
            continue
        lines.append(normalized)
    if lines == []:
        return "Generated Summary"

    cutoff = len(lines)
    for index, line in enumerate(lines[:12]):
        if match_section_heading(line) in {"abstract", "introduction", "overview", "executive_summary"}:
            cutoff = index
            break

    early_lines = lines[:max(1, cutoff)]
    plausible_lines = [line for line in early_lines[:6] if is_plausible_title(line)]
    if len(plausible_lines) >= 2:
        combined = normalize_whitespace(f"{plausible_lines[0]} {plausible_lines[1]}")
        # ASSUMPTION: article titles can wrap across two adjacent top lines.
        if is_plausible_title(combined) and len(tokenize_words(combined)) <= SUMMARY_TITLE_MAX_WORDS:
            return combined
    if plausible_lines:
        return plausible_lines[0]

    fallback_sentences = safe_sent_tokenize(lines[0])
    for sentence in fallback_sentences:
        candidate = normalize_whitespace(sentence)
        if candidate == "":
            continue
        if is_possible_section_heading(candidate):
            continue
        if len(tokenize_words(candidate)) < 4:
            continue
        return candidate[:180]

    return "Generated Summary"


def detect_explicit_sections(paragraphs: list[str], raw_text: str | None = None) -> tuple[list[str], Counter[str]]:
    sections: list[str] = []
    counts: Counter[str] = Counter()
    current_section = "body"

    for paragraph in paragraphs:
        matched_section = match_section_heading(paragraph)
        if matched_section is not None:
            current_section = matched_section

        sections.append(current_section)
        counts[current_section] += 1

    # When paragraphs had standalone headings removed, recover sections from raw document structure
    if raw_text and (not counts or set(sections) == {"body"} or len(counts) <= 1):
        raw_lines = raw_text.splitlines()
        line_sections: list[tuple[str, str]] = []
        curr = "body"
        for line in raw_lines:
            line_str = line.strip()
            if not line_str:
                continue
            matched = match_section_heading(line_str)
            if matched is not None:
                curr = matched
            else:
                line_sections.append((curr, line_str))

        recovered_sections: list[str] = []
        for p in paragraphs:
            p_head = p[:50].strip()
            p_sec = "body"
            for sec, l_text in line_sections:
                if p_head and (p_head in l_text or l_text in p):
                    p_sec = sec
                    break
            recovered_sections.append(p_sec)

        if any(s != "body" for s in recovered_sections):
            sections = recovered_sections
            counts = Counter(sections)

    return sections, counts


def rebuild_paragraphs_from_lines(lines: Iterable[str], *, repair_fragments: bool = True) -> list[str]:
    from .cleaning import normalize_pdf_line
    """Combine extracted lines into meaningful paragraphs, preserving section boundaries."""
    paragraphs: list[str] = []
    current: list[str] = []

    def flush_current() -> None:
        if not current:
            return
        paragraph = normalize_whitespace(" ".join(current))
        if paragraph != "":
            paragraphs.append(paragraph)
        current.clear()

    for raw_line in lines:
        line = normalize_pdf_line(raw_line) if repair_fragments else normalize_whitespace(raw_line)
        if line == "":
            flush_current()
            continue

        # If a line looks like a section heading, it should always start a new paragraph
        if is_possible_section_heading(line):
            flush_current()
            paragraphs.append(line)
            continue

        if looks_like_document_title_line(line):
            flush_current()
            paragraphs.append(line)
            continue

        if current and current[-1].endswith("-"):
            current[-1] = current[-1][:-1] + line
        else:
            current.append(line)

    flush_current()
    return paragraphs


def looks_like_continuation_paragraph(paragraph: str) -> bool:
    compact = normalize_whitespace(paragraph)
    if compact == "":
        return False
    first_alpha = next((character for character in compact if character.isalpha()), "")
    if first_alpha and first_alpha.islower():
        return True
    return compact.lower().startswith((
        "and ",
        "but ",
        "or ",
        "while ",
        "because ",
        "therefore ",
        "thus ",
        "however ",
        "furthermore ",
        "moreover ",
        "instead ",
        "additionally ",
    ))


def merge_continuation_paragraphs(paragraphs: list[str]) -> list[str]:
    merged: list[str] = []
    for paragraph in paragraphs:
        compact = normalize_whitespace(paragraph)
        if compact == "":
            continue
        if merged and looks_like_continuation_paragraph(compact):
            merged[-1] = normalize_whitespace(f"{merged[-1]} {compact}")
            continue
        merged.append(compact)
    return merged


SUMMARY_TITLE_MIN_WORDS = 2
SUMMARY_TITLE_MAX_WORDS = 32
SUMMARY_TITLE_MAX_CHARS = 240


def shorten_summary_title(title: str, keywords: list[str]) -> str:
    """Keep a source title only when it is supported by the generated summary topics."""
    normalized = normalize_whitespace(title)
    title_is_usable = (
        normalized != ""
        and normalized.lower() != "generated summary"
        and not is_possible_section_heading(normalized)
        and SUMMARY_TITLE_MIN_WORDS <= len(tokenize_words(normalized)) <= SUMMARY_TITLE_MAX_WORDS
        and len(normalized) <= SUMMARY_TITLE_MAX_CHARS
    )

    # A detected heading can be an unrelated cover title or extraction artifact.
    # Compare it with topics extracted from selected summary sentences before keeping it.
    content_words = {
        word.lower()
        for phrase in keywords[:5]
        for word in tokenize_words(phrase)
        if len(word) > 2
    }
    title_words = {word.lower() for word in tokenize_words(normalized) if len(word) > 2}
    overlap = len(content_words & title_words)
    aligned = not content_words or overlap >= min(2, max(1, len(content_words) // 4))

    if title_is_usable and aligned:
        return normalized.rstrip(",:;- ")

    # Keywords come from selected summary sentences, so they better reflect the
    # summary's focus when the detected source heading does not match.
    for phrase in keywords:
        candidate = normalize_whitespace(phrase)
        if len(tokenize_words(candidate)) >= SUMMARY_TITLE_MIN_WORDS:
            return candidate.rstrip(",:;- ")[:1].upper() + candidate.rstrip(",:;- ")[1:]
    if keywords:
        candidate = normalize_whitespace(keywords[0])
        if candidate:
            return candidate[:1].upper() + candidate[1:]
    return "Generated Summary"


__all__ = [
    '_legacy_match_section_heading',
    'detect_document_title',
    'detect_explicit_sections',
    'extract_structural_heading',
    'is_heading_only_text',
    'is_plausible_title',
    'is_possible_section_heading',
    'looks_like_continuation_paragraph',
    'looks_like_document_title_line',
    'match_section_heading',
    'merge_continuation_paragraphs',
    'normalize_heading_candidate',
    'rebuild_paragraphs_from_lines',
    'restore_inline_section_breaks',
    'shorten_summary_title',
    'strip_leading_heading_label',
]

