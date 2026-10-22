"""Targeted cProfile and timing breakdown of pipeline.summarize."""

from __future__ import annotations

import cProfile
import json
import pstats
import sys
import time
from pathlib import Path

_script_dir = str(Path(__file__).resolve().parent)
if sys.path and sys.path[0] == _script_dir:
    sys.path.pop(0)

ENGINE_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ENGINE_ROOT))

CORPUS_PATH = ENGINE_ROOT / "evaluation" / "corpus" / "heldout_documents.json"


def run_cprofile(doc_id: str = "HO-RESEARCH-COMMUTE", depth: str = "balanced") -> None:
    corpus = json.loads(CORPUS_PATH.read_text(encoding="utf-8"))
    doc = next(d for d in corpus["documents"] if d["id"] == doc_id)

    from summarizer_core.models import SummarizationRequest
    from summarizer_core.pipeline import SummarizationPipeline

    pipeline = SummarizationPipeline()
    req = SummarizationRequest(
        text=doc["source_text"],
        analysis_mode=doc.get("profile", "general"),
        summary_length=depth,
        output_format="paragraph",
    )

    print("--- 1. Cold Run ---")
    t0 = time.perf_counter()
    res1 = pipeline.summarize(req)
    cold_sec = time.perf_counter() - t0
    print(f"Cold summarize(): {cold_sec:.3f}s")

    print("\n--- 2. Warm Run under cProfile ---")
    profiler = cProfile.Profile()
    profiler.enable()
    t0 = time.perf_counter()
    res2 = pipeline.summarize(req)
    warm_sec = time.perf_counter() - t0
    profiler.disable()
    print(f"Warm summarize(): {warm_sec:.3f}s")

    stats = pstats.Stats(profiler)
    stats.strip_dirs()
    stats.sort_stats("cumulative")
    print("\n--- Top 35 Functions by Cumulative Time (Warm Run) ---")
    stats.print_stats(35)


if __name__ == "__main__":
    run_cprofile()
