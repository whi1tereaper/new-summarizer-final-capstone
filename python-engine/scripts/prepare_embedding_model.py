"""Prepare the local semantic retrieval model once, outside request handling."""

from __future__ import annotations

import argparse
from pathlib import Path


DEFAULT_MODEL = "sentence-transformers/all-MiniLM-L6-v2"
DEFAULT_OUTPUT = Path(__file__).resolve().parents[2] / "storage" / "models" / "all-MiniLM-L6-v2"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--model", default=DEFAULT_MODEL)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()

    from sentence_transformers import SentenceTransformer

    args.output.mkdir(parents=True, exist_ok=True)
    model = SentenceTransformer(args.model, device="cpu")
    model.save(str(args.output))
    print(f"Prepared local embedding model at {args.output}")
    print(f"Set SUMMARIZER_LOCAL_EMBEDDING_MODEL={args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
