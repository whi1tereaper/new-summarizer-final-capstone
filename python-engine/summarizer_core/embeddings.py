"""Local embedding adapters with an offline TF-IDF fallback."""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from sklearn.feature_extraction.text import TfidfVectorizer


@dataclass(frozen=True)
class EmbeddingOutput:
    vectors: Any
    backend: str
    fallback_reason: str = ""


def embed_texts(texts: list[str]) -> EmbeddingOutput:
    """Embed text locally; never resolve a model identifier over the network.

    Dense embeddings are enabled only when
    ``SUMMARIZER_LOCAL_EMBEDDING_MODEL`` points at an existing local model
    directory and sentence-transformers is installed. Otherwise TF-IDF is
    fitted to this request's texts.
    """
    if not texts or any(not isinstance(text, str) for text in texts):
        raise ValueError("Embedding input must be a non-empty list of strings.")

    model_path = os.environ.get("SUMMARIZER_LOCAL_EMBEDDING_MODEL", "").strip()
    fallback_reason = ""
    if model_path:
        local_path = Path(model_path).expanduser()
        if local_path.is_dir():
            try:
                from sentence_transformers import SentenceTransformer  # pyright: ignore[reportMissingImports]

                encoder = SentenceTransformer(
                    str(local_path),
                    device="cpu",
                    model_kwargs={"local_files_only": True},
                    trust_remote_code=False,
                )
                vectors = encoder.encode(
                    texts,
                    convert_to_numpy=True,
                    normalize_embeddings=True,
                    show_progress_bar=False,
                    batch_size=32,
                )
                return EmbeddingOutput(vectors=vectors, backend="sentence_transformers_local")
            except Exception as exc:
                fallback_reason = f"local_model_unavailable:{type(exc).__name__}"
        else:
            fallback_reason = "local_model_path_not_found"
    else:
        fallback_reason = "local_model_not_configured"

    vectors = TfidfVectorizer(ngram_range=(1, 2), min_df=1, norm="l2").fit_transform(texts)
    return EmbeddingOutput(
        vectors=vectors,
        backend="tfidf",
        fallback_reason=fallback_reason,
    )
