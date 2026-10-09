"""Explicit, versioned evaluation tokenization (independent of engine metadata)."""
import re

TOKENIZER_VERSION = "unicode-words-v1"
WORD = re.compile(r"\b\w+(?:[.'’,-]\w+)*%?", re.UNICODE)
SENTENCE = re.compile(r"(?<=[.!?])\s+(?=[A-Z0-9\"'‘“])|\n+")


def words(text):
    return WORD.findall(text or "")


def word_count(text):
    return len(words(text))


def tokens(text):
    return [word.casefold() for word in words(text)]


def sentences(text):
    return [part.strip() for part in SENTENCE.split(text or "") if part.strip()]


def normalized(text):
    return " ".join(tokens(text))
