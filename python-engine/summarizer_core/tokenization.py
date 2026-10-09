"""Sentence and word tokenization, POS tagging, and lemmatization helpers."""

from __future__ import annotations

from functools import lru_cache
from contextvars import ContextVar
import importlib
import logging
import math
import re
from typing import Any, cast

import nltk
from nltk.corpus import stopwords, wordnet
from nltk.stem import WordNetLemmatizer

from .constants import (
    PUNCT_SPLIT_PATTERN,
    READING_WORDS_PER_MINUTE,
    TOKEN_PATTERN,
    URL_GUARD_PATTERN,
)
from .models import PreprocessingOptions
from .normalization import normalize_whitespace

LOGGER = logging.getLogger(__name__)
# Offline evaluation scopes resource lookup to already installed data. Ordinary
# requests retain their existing resource preparation behavior.
OFFLINE_RESOURCES: ContextVar[bool] = ContextVar("summarizer_offline_resources", default=False)

def try_ensure_nltk_resource(resource: str | tuple[str, ...], package: str) -> bool:
    resource_names = (resource,) if isinstance(resource, str) else resource

    for resource_name in resource_names:
        try:
            nltk.data.find(resource_name)
            return True
        except (LookupError, OSError, Exception):
            continue

    if OFFLINE_RESOURCES.get():
        return False

    try:
        nltk.download(package, quiet=True)
    except Exception:
        return False

    for resource_name in resource_names:
        try:
            nltk.data.find(resource_name)
            return True
        except (LookupError, OSError, Exception):
            continue

    return False


def protect_fallback_sentence_boundaries(text: str) -> tuple[str, dict[str, str]]:
    replacements: dict[str, str] = {}
    counter = 0

    def remember(value: str) -> str:
        nonlocal counter
        placeholder = f"__SPLITGUARD{counter}__"
        replacements[placeholder] = value
        counter += 1
        return placeholder

    protected = text
    protected = URL_GUARD_PATTERN.sub(lambda match: remember(match.group(0)), protected)
    protected = re.sub(r"(?<=\d)\.(?=\d)", lambda match: remember(match.group(0)), protected)
    protected = re.sub(
        r"\b(?:[A-Z]\.){2,}",
        lambda match: remember(match.group(0)),
        protected,
    )
    protected = re.sub(
        r"\b(?:Mr|Mrs|Ms|Dr|Prof|Sr|Jr|St|Mt|No|Nos|Fig|Figs|Eq|Eqs|Ref|Refs|Sec|Secs|Dept|Univ|Inc|Ltd|Co|Corp|vs|etc|e\.g|i\.e|al|approx|govt|est|avg)\.",
        lambda match: remember(match.group(0)),
        protected,
        flags=re.IGNORECASE,
    )

    return protected, replacements


def restore_fallback_sentence_boundaries(text: str, replacements: dict[str, str]) -> str:
    restored = text
    for placeholder, original in replacements.items():
        restored = restored.replace(placeholder, original)
    return restored


def fallback_sentence_split(text: str) -> list[str]:
    protected_text, replacements = protect_fallback_sentence_boundaries(normalize_whitespace(text))
    return [
        normalize_whitespace(restore_fallback_sentence_boundaries(sentence, replacements))
        for sentence in PUNCT_SPLIT_PATTERN.split(protected_text)
        if normalize_whitespace(restore_fallback_sentence_boundaries(sentence, replacements)) != ""
    ]


def optional_module(module_name: str) -> Any | None:
    if importlib.util.find_spec(module_name) is None:
        return None
    try:
        return importlib.import_module(module_name)
    except Exception:
        return None


def load_optional_spacy_pipeline() -> Any | None:
    spacy_module = optional_module("spacy")
    if spacy_module is None:
        return None
    try:
        return spacy_module.load("en_core_web_sm")
    except Exception:
        return None


def optional_spacy_sent_tokenize(text: str) -> list[str]:
    nlp = load_optional_spacy_pipeline()
    if nlp is None:
        return []
    try:
        doc = nlp(text)
        return [
            normalize_whitespace(sent.text)
            for sent in doc.sents
            if normalize_whitespace(sent.text) != ""
        ]
    except Exception:
        return []


def safe_sent_tokenize(text: str) -> list[str]:
    if text.strip() == "":
        return []

    spacy_sentences = optional_spacy_sent_tokenize(text)
    if spacy_sentences:
        return spacy_sentences

    # ASSUMPTION: if spaCy is unavailable locally, NLTK punkt remains the safest offline fallback.
    # Try punkt_tab first (better boundary detection in NLTK 3.9+), then punkt
    for resource, package in (
        ("tokenizers/punkt_tab", "punkt_tab"),
        ("tokenizers/punkt", "punkt"),
    ):
        if try_ensure_nltk_resource(resource, package):
            try:
                return [
                    normalize_whitespace(sentence)
                    for sentence in nltk.sent_tokenize(text)
                    if normalize_whitespace(sentence) != ""
                ]
            except Exception:
                continue

    return fallback_sentence_split(text)


def tokenize_words(text: str) -> list[str]:
    return TOKEN_PATTERN.findall(text)


def safe_pos_tag(tokens: list[str]) -> list[tuple[str, str]]:
    if tokens == []:
        return []

    tagger_ok = try_ensure_nltk_resource(
        ("taggers/averaged_perceptron_tagger", "taggers/averaged_perceptron_tagger_eng"),
        "averaged_perceptron_tagger_eng",
    )
    if not tagger_ok:
        return [(token, "NN") for token in tokens]

    try:
        return nltk.pos_tag(tokens)
    except Exception:
        return [(token, "NN") for token in tokens]


def penn_to_wordnet(tag: str) -> str | None:
    if tag.startswith("J"):
        return "a"
    if tag.startswith("N"):
        return "n"
    if tag.startswith("R"):
        return "r"
    if tag.startswith("V"):
        return "v"
    return None


def lemmatize_tokens(tokens: list[str]) -> list[str]:
    lowered = [token.lower() for token in tokens]
    if lowered == []:
        return []

    wordnet_ok = try_ensure_nltk_resource(("corpora/wordnet", "corpora/wordnet.zip"), "wordnet")
    if not wordnet_ok:
        return lowered

    lemmatizer = WordNetLemmatizer()
    tagged_tokens = safe_pos_tag(lowered)
    lemmas: list[str] = []
    for token, tag in tagged_tokens:
        wordnet_tag = penn_to_wordnet(tag)
        try:
            if wordnet_tag is None:
                lemmas.append(lemmatizer.lemmatize(token))
            else:
                lemmas.append(lemmatizer.lemmatize(token, wordnet_tag))
        except Exception:
            lemmas.append(token)
    return lemmas


def _legacy_english_stop_words() -> set[str]:
    if try_ensure_nltk_resource("corpora/stopwords", "stopwords"):
        try:
            return set(stopwords.words("english"))
        except Exception:
            pass

    return {
        "a",
        "an",
        "and",
        "are",
        "as",
        "at",
        "be",
        "by",
        "for",
        "from",
        "in",
        "is",
        "it",
        "of",
        "on",
        "or",
        "that",
        "the",
        "to",
        "with",
    }


def english_stop_words() -> set[str]:
    """Return a set of common English stop words."""
    try:
        return set(stopwords.words("english"))
    except Exception:
        # fallback if nltk data not available
        return {
            "a", "an", "the", "and", "or", "but", "if", "then", "else", "when",
            "at", "by", "for", "with", "about", "against", "between", "into",
            "through", "during", "before", "after", "above", "below", "to",
            "from", "up", "down", "in", "out", "on", "off", "over", "under",
            "again", "further", "then", "once", "here", "there", "when", "where",
            "why", "how", "all", "any", "both", "each", "few", "more", "most",
            "other", "some", "such", "no", "nor", "not", "only", "own", "same",
            "so", "than", "too", "very", "s", "t", "can", "will", "just", "don",
            "should", "now"
        }


def preprocess_sentence_for_ranking(sentence: str) -> list[str]:
    tokens = [token.lower() for token in tokenize_words(sentence)]
    alphabetic_tokens = [token for token in tokens if re.search(r"[A-Za-z]", token)]
    if alphabetic_tokens == []:
        return []

    lemmas = lemmatize_tokens(alphabetic_tokens)
    stop_words = english_stop_words()
    return [token for token in lemmas if token not in stop_words and len(token) > 1]


def count_words(text: str) -> int:
    return len(TOKEN_PATTERN.findall(text))


def estimate_reading_time_minutes(word_count: int) -> int:
    if word_count <= 0:
        return 0
    return max(1, math.ceil(word_count / READING_WORDS_PER_MINUTE))


def normalized_sentence_tokens(text: str) -> set[str]:
    return {
        token
        for token in preprocess_sentence_for_ranking(text)
        if token != ""
    }


def sentence_overlap_ratio(left: str, right: str) -> float:
    left_tokens = normalized_sentence_tokens(left)
    right_tokens = normalized_sentence_tokens(right)
    if not left_tokens or not right_tokens:
        return 0.0

    intersection = left_tokens & right_tokens
    return len(intersection) / min(len(left_tokens), len(right_tokens))


def sentences_are_similar(left: str, right: str, threshold: float = 0.78) -> bool:
    normalized_left = normalize_whitespace(left).lower()
    normalized_right = normalize_whitespace(right).lower()
    if normalized_left == "" or normalized_right == "":
        return False
    if normalized_left == normalized_right:
        return True
    if normalized_left in normalized_right or normalized_right in normalized_left:
        return True
    return sentence_overlap_ratio(normalized_left, normalized_right) >= threshold


def deduplicate_sentences(sentences: list[str], threshold: float = 0.78) -> list[str]:
    unique_sentences: list[str] = []
    for sentence in sentences:
        normalized = normalize_whitespace(sentence)
        if normalized == "":
            continue
        if any(sentences_are_similar(normalized, existing, threshold) for existing in unique_sentences):
            continue
        unique_sentences.append(normalized)
    return unique_sentences


__all__ = ['_legacy_english_stop_words', 'count_words', 'deduplicate_sentences', 'english_stop_words', 'estimate_reading_time_minutes', 'fallback_sentence_split', 'lemmatize_tokens', 'load_optional_spacy_pipeline', 'normalized_sentence_tokens', 'optional_module', 'optional_spacy_sent_tokenize', 'penn_to_wordnet', 'preprocess_sentence_for_ranking', 'protect_fallback_sentence_boundaries', 'restore_fallback_sentence_boundaries', 'safe_pos_tag', 'safe_sent_tokenize', 'sentence_overlap_ratio', 'sentences_are_similar', 'tokenize_words', 'try_ensure_nltk_resource']
