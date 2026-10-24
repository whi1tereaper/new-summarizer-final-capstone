"""Reproducible detector calibration and three-system article comparison.

No human ratings are inferred from automatic proxies. All input/output text,
source hashes, settings, dependency versions and timings are retained.
"""
from __future__ import annotations

import argparse
from collections import defaultdict
from hashlib import sha256
from importlib.metadata import version
import json
import os
from pathlib import Path
import subprocess
import sys
import time

ENGINE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ENGINE))
# Avoid shadowing the standard-library statistics module when run as a file.
sys.path = [p for p in sys.path if p != str(Path(__file__).resolve().parent)]
import statistics
from evaluation.article_routing_fixtures import DEVELOPMENT, heldout
from article_detector import CALIBRATION, DETECTOR_VERSION, detect_article, threshold


def calibrate():
    rows = [(d, detect_article(d["text"], decision_threshold=0)) for d in DEVELOPMENT]
    candidates = sorted({a.confidence for _, a in rows if a.detected_features.get("eligible")})
    scored = []
    for cutoff in candidates:
        tp = fp = 0
        for d, a in rows:
            yes = a.detected_features.get("eligible", False) and a.confidence >= cutoff
            tp += int(yes and d["article"])
            fp += int(yes and not d["article"])
        # Optimize recall subject to zero observed development false positives;
        # ties favor the highest threshold. This is not a population guarantee.
        if fp == 0:
            scored.append((tp, cutoff))
    cutoff = max(scored)[1] if scored else 1.0
    artifact = {"detector_version": DETECTOR_VERSION, "threshold": cutoff,
                "method": "maximize_recall_zero_development_false_positives_highest_tie",
                "corpus_sha256": sha256(json.dumps(DEVELOPMENT, sort_keys=True).encode()).hexdigest(),
                "provenance": "engineering_authored_synthetic", "development_count": len(rows),
                "human_verified": False,
                "rows": [{"id": d["id"], "expected": d["article"], "score": a.confidence,
                          "eligible": a.detected_features.get("eligible", False)} for d, a in rows]}
    CALIBRATION.write_text(json.dumps(artifact, indent=2) + "\n", encoding="utf-8")
    threshold.cache_clear()
    return artifact


def quality_documents():
    synthetic = json.loads((ENGINE / "evaluation/corpus/heldout_documents.json").read_text(encoding="utf-8"))["documents"]
    accepted = {"research", "news", "technical", "essay", "contradictory"}
    docs = [{**d, "provenance": "engineering_authored_synthetic"} for d in synthetic if d["category"] in accepted]
    pilot = json.loads((ENGINE / "evaluation/corpus/independent_article_pilot_20261013.json").read_text(encoding="utf-8"))["documents"]
    docs += [{**d, "provenance": "existing_real_article_snapshot"} for d in pilot]
    for d in heldout():
        if d["article"] and d["id"].startswith("heldout-"):
            docs.append({"id": d["id"], "source_text": d["text"], "category": d["id"].split("-")[1],
                         "profile": "general", "provenance": "engineering_authored_synthetic",
                         "key_units": [], "critical_facts": []})
    return docs


def worker(system, budgets):
    import psutil
    from article_engine.baseline import guide_lsa
    from article_engine.summarizer import summarize
    from summarizer_core.models import SourceDocument
    from local_cli import _summarize_request
    process = psutil.Process()
    rows = []
    for doc in quality_documents():
        payload = {"text": doc["source_text"], "summary_depth": "comprehensive", "summary_length": "comprehensive",
                   "sentence_count": 8, "target_word_budget": 180, "output_format": "paragraph",
                   "analysis_mode": doc.get("profile", "general"), "automatic_article_routing": False}
        start = time.perf_counter()
        try:
            if system == "legacy":
                result = _summarize_request(payload)
            elif system == "guide_lsa":
                values = guide_lsa(payload["text"], budgets[doc["id"]]["sentences"])
                result = {"sentences": values, "plain_summary": " ".join(values)}
            else:
                payload["target_word_budget"] = budgets[doc["id"]]["words"]
                payload["sentence_count"] = budgets[doc["id"]]["sentences"]
                result = summarize(payload, SourceDocument(payload["text"], "text"), detect_article(payload["text"]))
            elapsed = time.perf_counter() - start
            values = result["sentences"]
            from evaluation.metrics import redundancy
            source = payload["text"]
            units = doc.get("key_units", [])
            covered = sum(any(all(str(token).casefold() in s.casefold() for token in unit["all_of"]) for s in values) for unit in units)
            facts = doc.get("critical_facts", [])
            preserved = sum(str(fact).casefold() in " ".join(values).casefold() for fact in facts)
            mem = process.memory_info()
            rows.append({"id": doc["id"], "system": system, "source_sha256": sha256(source.encode()).hexdigest(),
                         "settings": payload | {"text": "<retained_in_documents>"}, "sentences": values,
                         "summary": result["plain_summary"], "words": len(" ".join(values).split()),
                         "seconds": round(elapsed, 6), "rss_mb_after": round(mem.rss / 1048576, 2),
                         "process_peak_rss_mb": round(getattr(mem, "peak_wset", mem.rss) / 1048576, 2),
                         "exact_source_sentences": sum(s in source for s in values),
                         "sentence_count": len(values), "key_unit_covered": covered, "key_unit_count": len(units),
                         "fact_literal_preserved": preserved, "fact_literal_count": len(facts),
                         "redundancy": redundancy(" ".join(values))["score"],
                         "annotation_status": result.get("source_metadata", {}).get("article_analysis", {}).get("annotation_status"),
                         "error": None})
        except Exception as exc:
            rows.append({"id": doc["id"], "system": system, "error": type(exc).__name__})
    return rows


def evaluate():
    detector_rows = [{"id": d["id"], "expected": d["article"], **detect_article(d["text"]).as_dict()} for d in heldout()]
    counts = {"tp": 0, "tn": 0, "fp": 0, "fn": 0}
    for row in detector_rows:
        counts[("t" if row["expected"] == row["is_article"] else "f") + ("p" if row["is_article"] else "n")] += 1
    env = dict(os.environ, PYTHONIOENCODING="utf-8", HF_HUB_OFFLINE="1", TOKENIZERS_PARALLELISM="false",
               OPENBLAS_NUM_THREADS="1", OMP_NUM_THREADS="1")
    rows, systems, budgets = [], {}, {}
    for system in ["legacy", "guide_lsa", "article"]:
        started = time.perf_counter()
        run = subprocess.run([sys.executable, str(Path(__file__).resolve()), "--worker", system],
                             input=json.dumps(budgets), capture_output=True, text=True, encoding="utf-8",
                             env=env, timeout=300, check=True)
        outputs = json.loads(run.stdout)
        systems[system] = {"process_wall_seconds": round(time.perf_counter() - started, 4), "cases": len(outputs),
                           "failures": sum(bool(r["error"]) for r in outputs)}
        if system == "legacy":
            budgets = {r["id"]: {"sentences": r["sentence_count"], "words": r["words"]} for r in outputs if not r["error"]}
        rows.extend(outputs)
    grouped = defaultdict(list)
    for row in rows:
        if not row["error"]:
            grouped[row["system"]].append(row)
    for system, outputs in grouped.items():
        for key in ["seconds", "words", "redundancy"]:
            systems[system]["mean_" + key] = round(statistics.mean(r[key] for r in outputs), 4)
        for numerator, denominator in [("key_unit_covered", "key_unit_count"), ("fact_literal_preserved", "fact_literal_count")]:
            total = sum(r[denominator] for r in outputs)
            systems[system][numerator + "_rate"] = sum(r[numerator] for r in outputs) / total if total else None
    return {"detector_calibration": json.loads(CALIBRATION.read_text()), "heldout_detection": counts,
            "detection_rows": detector_rows, "documents": quality_documents(), "systems": systems, "rows": rows,
            "versions": {m: version(m) for m in ["numpy", "scipy", "scikit-learn", "nltk", "sumy", "pypdf"]},
            "human_review": {"completed": False, "ratings": [], "reason": "No independent reviewers supplied."},
            "limitations": ["Engineering-authored synthetic fixtures predominate; only two archived NASA articles.",
                            "Literal coverage and source matching are proxies, not factual entailment or human judgments.",
                            "Legacy first case includes initialization; process peaks are cumulative per system.",
                            "Matched sentence and word ceilings do not guarantee equal realized lengths.",
                            "No statistical superiority is claimed from this convenience sample."]}


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--calibrate", action="store_true")
    parser.add_argument("--out", type=Path)
    parser.add_argument("--worker", choices=["legacy", "guide_lsa", "article"])
    args = parser.parse_args()
    if args.worker:
        print(json.dumps(worker(args.worker, json.load(sys.stdin)), ensure_ascii=False))
    else:
        data = calibrate() if args.calibrate else evaluate()
        if args.out:
            args.out.parent.mkdir(parents=True, exist_ok=True)
            args.out.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        print(json.dumps(data if args.calibrate else {"heldout_detection": data["heldout_detection"], "systems": data["systems"]}, indent=2))
