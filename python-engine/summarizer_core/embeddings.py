"""Local embedding adapters with an offline TF-IDF fallback."""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from threading import Lock
from typing import Any

from sklearn.feature_extraction.text import TfidfVectorizer
from .runtime import request_cache, stage, timed


@dataclass(frozen=True)
class EmbeddingOutput:
    vectors: Any
    backend: str
    fallback_reason: str = ""


_CACHED_ENCODER: Any = None
_CACHED_MODEL_PATH: str | None = None
_MODEL_LOCK = Lock()


@timed("embedding_generation")
def embed_texts(texts: list[str]) -> EmbeddingOutput:
    """Embed text locally; never resolve a model identifier over the network.

    Dense embeddings are enabled only when
    ``SUMMARIZER_LOCAL_EMBEDDING_MODEL`` points at an existing local model
    directory and sentence-transformers is installed. Otherwise TF-IDF is
    fitted to this request's texts.
    """
    global _CACHED_ENCODER, _CACHED_MODEL_PATH

    if not texts or any(not isinstance(text, str) for text in texts):
        raise ValueError("Embedding input must be a non-empty list of strings.")

    model_path = os.environ.get("SUMMARIZER_LOCAL_EMBEDDING_MODEL", "").strip()
    fallback_reason = ""
    if model_path:
        local_path = Path(model_path).expanduser()
        if local_path.is_dir():
            try:
                model_key = str(local_path.resolve())
                with _MODEL_LOCK:
                    if _CACHED_ENCODER is None or _CACHED_MODEL_PATH != model_key:
                        with stage("sentence_transformers_initialization"):
                            from sentence_transformers import SentenceTransformer  # pyright: ignore[reportMissingImports]

                            encoder = SentenceTransformer(
                                model_key,
                                device="cpu",
                                model_kwargs={"local_files_only": True},
                                trust_remote_code=False,
                            )
                        _CACHED_ENCODER = encoder
                        _CACHED_MODEL_PATH = model_key
                    encoder = _CACHED_ENCODER

                import numpy as np

                # Only this request owns text/vectors. Deduplicate each batch and
                # keep its complete result even when the bounded cache is full.
                cache = request_cache("embeddings", entries=2048, units=16 * 1024 * 1024)
                found: dict[str, Any] = {}
                missing_texts: list[str] = []
                for text in dict.fromkeys(texts):
                    cached = cache.get((model_key, text)) if cache is not None else None
                    if cached is None:
                        missing_texts.append(text)
                    else:
                        found[text] = cached
                if missing_texts:
                    new_vectors = encoder.encode(
                        missing_texts,
                        convert_to_numpy=True,
                        normalize_embeddings=True,
                        show_progress_bar=False,
                        batch_size=32,
                    )
                    for text, vec in zip(missing_texts, new_vectors, strict=True):
                        found[text] = vec
                        if cache is not None:
                            cache.put((model_key, text), vec.copy(), len(text.encode("utf-8")) + vec.nbytes)

                # Assemble complete array from cache
                vectors = np.array([found[t] for t in texts], dtype=np.float32)
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
