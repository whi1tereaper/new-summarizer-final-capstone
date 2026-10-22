"""Targeted stage-level profiler for the Quality-First Summarizer Engine.
Profiles exact execution time and memory for each processing stage on a held-out document.
"""

from __future__ import annotations

import json
import os
import sys
import time
from pathlib import Path

# Ensure script dir does not shadow stdlib
_script_dir = str(Path(__file__).resolve().parent)
if sys.path and sys.path[0] == _script_dir:
    sys.path.pop(0)

ENGINE_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ENGINE_ROOT))

CORPUS_PATH = ENGINE_ROOT / "evaluation" / "corpus" / "heldout_documents.json"


def get_peak_memory_mb() -> float:
    try:
        import psutil

        info = psutil.Process().memory_info()
        peak = getattr(info, "peak_wset", None) or getattr(info, "rss", 0)
        return round(peak / (1024 * 1024), 2)
    except Exception:
        return 0.0


def profile_run(doc_id: str = "HO-RESEARCH-COMMUTE", depth: str = "balanced") -> dict[str, float]:
    corpus = json.loads(CORPUS_PATH.read_text(encoding="utf-8"))
    doc = next(d for d in corpus["documents"] if d["id"] == doc_id)

    timings: dict[str, float] = {}
    t0 = time.perf_counter()

    # Stage 0: Imports
    t_start = time.perf_counter()
    from summarizer_core.models import SummarizationRequest
    from summarizer_core.pipeline import SummarizationPipeline
    from summarizer_core.grammar_check import get_grammar_checker
    from summarizer_core.embeddings import embed_texts
    timings["import_overhead"] = round(time.perf_counter() - t_start, 4)

    # Stage 1: Pipeline Init
    t_start = time.perf_counter()
    pipeline = SummarizationPipeline()
    timings["pipeline_init"] = round(time.perf_counter() - t_start, 4)

    # Stage 2: First Execution (Cold Start)
    req = SummarizationRequest(
        text=doc["source_text"],
        analysis_mode=doc.get("profile", "general"),
        summary_length=depth,
        output_format="paragraph",
    )
    t_start = time.perf_counter()
    result = pipeline.summarize(req)
    timings["cold_run_total"] = round(time.perf_counter() - t_start, 4)
    timings["cold_peak_mem_mb"] = get_peak_memory_mb()

    # Stage 3: Second Execution (Warm Run)
    t_start = time.perf_counter()
    result_warm = pipeline.summarize(req)
    timings["warm_run_total"] = round(time.perf_counter() - t_start, 4)
    timings["warm_peak_mem_mb"] = get_peak_memory_mb()

    # Inspect source metadata
    meta = result.source_metadata
    timings["quality_strategy_selected"] = meta.get("quality_selection", {}).get("selected_strategy", "")
    timings["strategies_evaluated_count"] = len(meta.get("quality_selection", {}).get("strategies_evaluated", []))
    timings["grammar_issues_count"] = meta.get("grammar_diagnostics", {}).get("issues_count", 0)

    print(json.dumps(timings, indent=2))
    return timings


if __name__ == "__main__":
    profile_run()
