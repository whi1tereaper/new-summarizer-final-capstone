"""Offline sentence embeddings for semantic relevance and redundancy scoring."""

from __future__ import annotations

import logging
import os
from pathlib import Path
from typing import Any

import numpy as np

_LOGGER = logging.getLogger("light_engine.semantic")
_MODEL: Any | None = None
_MODEL_INITIALIZED = False
_MODEL_STATUS = "not_initialized"


def encode_sentences(texts: list[str]) -> tuple[np.ndarray | None, str]:
    """Encode text only from a configured local model; never fetch model files."""
    global _MODEL, _MODEL_INITIALIZED, _MODEL_STATUS
    if not texts:
        return np.empty((0, 0), dtype=float), "enabled"

    if not _MODEL_INITIALIZED:
        configured = os.environ.get("SUMMARIZER_LOCAL_EMBEDDING_MODEL", "").strip()
        project_root = Path(__file__).resolve().parents[2]
        default_path = project_root / "storage" / "models" / "all-MiniLM-L6-v2"
        model_path = Path(configured).expanduser() if configured else default_path
        if not model_path.is_absolute():
            model_path = project_root / model_path
        _MODEL_INITIALIZED = True
        if not model_path.is_dir():
            _MODEL_STATUS = "disabled_model_directory_missing"
            _LOGGER.warning(
                "Local semantic model is not provisioned; using lexical ranking only."
            )
        else:
            try:
                from sentence_transformers import SentenceTransformer

                _MODEL = SentenceTransformer(
                    str(model_path),
                    device="cpu",
                    local_files_only=True,
                )
                _MODEL_STATUS = f"enabled:{model_path.name}"
            except Exception as exc:
                _MODEL_STATUS = f"disabled_model_load_error:{type(exc).__name__}"
                _LOGGER.warning(
                    "Local semantic model could not be loaded; using lexical ranking only (%s).",
                    type(exc).__name__,
                )

    if _MODEL is None:
        return None, _MODEL_STATUS
    try:
        vectors = _MODEL.encode(
            texts,
            convert_to_numpy=True,
            normalize_embeddings=True,
            show_progress_bar=False,
        )
        result = np.asarray(vectors, dtype=float)
        if result.ndim != 2 or result.shape[0] != len(texts) or not np.isfinite(result).all():
            raise ValueError("Embedding model returned invalid vectors.")
        return result, _MODEL_STATUS
    except Exception as exc:
        _LOGGER.warning(
            "Local semantic model could not encode this document; using lexical ranking only (%s).",
            type(exc).__name__,
        )
        return None, f"disabled_encoding_error:{type(exc).__name__}"
