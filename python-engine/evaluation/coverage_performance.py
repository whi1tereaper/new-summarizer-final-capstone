"""Isolated article-process measurements; synthetic scaling inputs, no quality claims."""
import argparse
import json
from pathlib import Path
import os
import subprocess
import sys
from time import perf_counter

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
sys.path = [p for p in sys.path if p != str(Path(__file__).resolve().parent)]


def source(size):
    rows = ["Regional environmental monitoring study", ""]
    for i in range(size):
        if i % 50 == 0:
            rows.extend(["", "Findings", ""])
        rows.append(f"The monitoring study reported {i+20} observations at station {i+1}, with a measured change of {i%19+1} percent during the survey period.")
    return "\n\n".join(rows)


def worker(size, enhanced):
    began = perf_counter()
    from evaluation.coverage_experiment import MemorySampler
    from article_engine.summarizer import summarize
    from summarizer_core.models import SourceDocument
    from article_detector import detect_article
    imports = perf_counter() - began
    text = source(size)
    rows = []
    for iteration in range(2):
        started = perf_counter()
        with MemorySampler() as memory:
            result = summarize({"sentence_count": 32, "summary_depth": "comprehensive",
                                "target_word_budget": 400, "performance_profile": True},
                               SourceDocument(text, "text"), detect_article(text), enhanced=enhanced)
        rows.append({"iteration": iteration, "seconds": perf_counter()-started,
                     "sampled_peak_rss_mb": memory.peak/1048576,
                     "timings": result["source_metadata"]["article_analysis"]["timings"],
                     "summary_words": result["readability"]["summary_word_count"],
                     "selected_sentence_count": result["sentence_count"]})
    return {"sentence_count": size, "enhanced": enhanced, "import_seconds": imports,
            "source_characters": len(text), "runs": rows}


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--size", type=int)
    parser.add_argument("--enhanced", action="store_true")
    args = parser.parse_args()
    if args.size:
        print(json.dumps(worker(args.size, args.enhanced)))
    else:
        rows = []
        env = dict(os.environ, OPENBLAS_NUM_THREADS="1", OMP_NUM_THREADS="1", PYTHONIOENCODING="utf-8", HF_HUB_OFFLINE="1")
        for size in [20, 200, 800]:
            for enhanced in [False, True]:
                command = [sys.executable, str(Path(__file__).resolve()), "--size", str(size)] + (["--enhanced"] if enhanced else [])
                result = subprocess.run(command, capture_output=True, text=True, encoding="utf-8", env=env, timeout=180, check=True)
                rows.append(json.loads(result.stdout))
        data = {"cases": rows, "limits": ["Synthetic scaling sources; no real-article quality inference.",
                "No OS memory cap enforced. Python RSS excludes Apache/PHP, workers, and container overhead.",
                "10-ms memory sampling may miss short peaks; no 512 MB deployment guarantee is made."]}
        out = ROOT.parent / "docs/research/coverage-performance.json"
        out.write_text(json.dumps(data, indent=2)+"\n", encoding="utf-8")
        print(json.dumps([{k: v for k,v in r.items() if k != "runs"} | {"runs": r["runs"]} for r in rows], indent=2))
