"""
Reusable NLP preprocessing utilities.

The default options preserve summarization quality by keeping sentence casing,
punctuation, and stop words unless a caller explicitly enables those steps.
"""

from __future__ import annotations

from dataclasses import dataclass
import re
from typing import Any

import nltk
from nltk.corpus import stopwords
from nltk.stem import WordNetLemmatizer

NOISE_PATTERNS = (
    "International Journal",
    "Scientific Research",
    "Engineering Development",
    "ISSN:",
    "www.",
    "Volume",
    "Issue",
    "Page",
    "Available at",
    "OPEN ACCESS",
)


@dataclass(frozen=True)
class PreprocessingOptions:
    lowercase: bool = False
    remove_punctuation: bool = False
    remove_stopwords: bool = False
    tokenize: bool = False
    lemmatize: bool = False
    remove_visual_artifacts: bool = True
    remove_email_addresses: bool = True
    remove_known_noise: bool = True
    normalize_whitespace: bool = True

    @classmethod
    def from_mapping(cls, raw_options: Any) -> "PreprocessingOptions":
        if not isinstance(raw_options, dict):
            return cls()

        allowed = set(cls.__dataclass_fields__.keys())
        normalized = {
            key: bool(value)
            for key, value in raw_options.items()
            if key in allowed and isinstance(value, bool)
        }
        return cls(**normalized)

    def as_dict(self) -> dict[str, bool]:
        return {
            "lowercase": self.lowercase,
            "remove_punctuation": self.remove_punctuation,
            "remove_stopwords": self.remove_stopwords,
            "tokenize": self.tokenize,
            "lemmatize": self.lemmatize,
            "remove_visual_artifacts": self.remove_visual_artifacts,
            "remove_email_addresses": self.remove_email_addresses,
            "remove_known_noise": self.remove_known_noise,
            "normalize_whitespace": self.normalize_whitespace,
        }


def preprocess_text(
    text: str,
    options: PreprocessingOptions | None = None,
) -> str:
    pipeline_options = options or PreprocessingOptions()
    cleaned_text = text

    if pipeline_options.remove_visual_artifacts:
        cleaned_text = remove_visual_artifacts(cleaned_text)
    if pipeline_options.remove_email_addresses:
        cleaned_text = remove_email_addresses(cleaned_text)
    if pipeline_options.remove_known_noise:
        cleaned_text = remove_known_noise_patterns(cleaned_text)
    if pipeline_options.lowercase:
        cleaned_text = cleaned_text.lower()
    if pipeline_options.remove_punctuation:
        cleaned_text = remove_punctuation_characters(cleaned_text)
    if pipeline_options.remove_stopwords:
        cleaned_text = remove_stopwords(cleaned_text)
    if pipeline_options.lemmatize:
        cleaned_text = lemmatize_text(cleaned_text)
    if pipeline_options.tokenize:
        cleaned_text = " ".join(tokenize_words(cleaned_text))
    if pipeline_options.normalize_whitespace:
        cleaned_text = normalize_whitespace(cleaned_text)

    return cleaned_text


def remove_visual_artifacts(text: str) -> str:
    return re.sub(r"[*_\-]{2,}", " ", text)


def remove_email_addresses(text: str) -> str:
    return re.sub(r"\S+@\S+", "", text)


def remove_known_noise_patterns(text: str) -> str:
    cleaned_text = text
    for pattern in NOISE_PATTERNS:
        cleaned_text = re.sub(re.escape(pattern), "", cleaned_text, flags=re.IGNORECASE)
    return cleaned_text


def remove_punctuation_characters(text: str) -> str:
    return re.sub(r"[^\w\s]", " ", text, flags=re.UNICODE)


def normalize_whitespace(text: str) -> str:
    return " ".join(text.split()).strip()


def tokenize_words(text: str) -> list[str]:
    return re.findall(r"\b\w+\b|[^\w\s]", text, flags=re.UNICODE)


def remove_stopwords(text: str, language: str = "english") -> str:
    ensure_nltk_resource("corpora/stopwords", "stopwords")
    stop_words = set(stopwords.words(language))
    tokens = [
        token
        for token in tokenize_words(text)
        if token.lower() not in stop_words
    ]
    return " ".join(tokens)


def lemmatize_text(text: str) -> str:
    ensure_nltk_resource(("corpora/wordnet", "corpora/wordnet.zip"), "wordnet")
    lemmatizer = WordNetLemmatizer()
    tokens = [
        lemmatizer.lemmatize(token)
        if re.match(r"^\w+$", token, flags=re.UNICODE)
        else token
        for token in tokenize_words(text)
    ]
    return " ".join(tokens)


def ensure_nltk_resource(resource_paths: str | tuple[str, ...], package_name: str) -> None:
    paths = (resource_paths,) if isinstance(resource_paths, str) else resource_paths
    for resource_path in paths:
        try:
            nltk.data.find(resource_path)
            return
        except LookupError:
            continue

    nltk.download(package_name, quiet=True)
