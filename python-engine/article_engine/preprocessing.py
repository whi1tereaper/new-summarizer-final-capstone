"""Conservative sentence spans; ranking normalization never edits evidence."""
from __future__ import annotations

from dataclasses import dataclass
from functools import lru_cache
import re

TOKEN = re.compile(r"\b[\w'-]+\b", re.UNICODE)
HEADINGS = re.compile(r"^(abstract|introduction|background|methods?|methodology|results?|findings?|discussion|limitations?|conclusions?|recommendations?|overview|risks?|outlook)\s*:?$", re.I)
NOISE = re.compile(r"^(?:home|menu|log ?in|register|subscribe|accept cookies|privacy policy|all rights reserved|advertisement|share this article)[\s.!]*$", re.I)


@dataclass(frozen=True)
class Sentence:
    index: int
    text: str
    start: int
    end: int
    paragraph: int
    section: str


@lru_cache(maxsize=1)
def _tokenizer():
    import nltk
    try:
        return nltk.data.load("tokenizers/punkt/english.pickle"), "nltk_punkt"
    except LookupError:
        return None, "boundary_fallback"


def sentences(text: str) -> tuple[list[Sentence], str]:
    tokenizer, status = _tokenizer()
    records: list[Sentence] = []
    section = "Article"
    paragraph = 0
    # Newlines preserve headings in PDF/DOCX and pasted text alike. Every span
    # still points into the original extracted text, including its punctuation.
    for block in re.finditer(r"[^\r\n\f]+", text):
        line = block.group().strip()
        start = block.start() + len(block.group()) - len(block.group().lstrip())
        if not line or NOISE.fullmatch(line):
            continue
        if HEADINGS.fullmatch(line):
            section = line.rstrip(":")
            paragraph += 1
            continue
        if not re.search(r"[.!?][\"')\]]*$", line) and len(TOKEN.findall(line)) <= 16:
            paragraph += 1
            continue
        if tokenizer:
            spans = list(tokenizer.span_tokenize(line))
        else:
            # Reuse the established abbreviation/decimal safeguards.
            from summarizer_core.tokenization import fallback_sentence_split
            spans = []
            cursor = 0
            for value in fallback_sentence_split(line):
                found = line.find(value, cursor)
                if found >= 0:
                    spans.append((found, found + len(value)))
                    cursor = found + len(value)
        for a, b in spans:
            value = line[a:b].strip()
            if len(TOKEN.findall(value)) < 4 or NOISE.fullmatch(value):
                continue
            offset = line.find(value, a, b)
            records.append(Sentence(len(records), value, start + offset,
                                    start + offset + len(value), paragraph, section))
        paragraph += 1
    return records, status
