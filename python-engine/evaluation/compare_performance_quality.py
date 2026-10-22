"""Compare saved performance outputs without rerunning models or judging prose.

Consumes benchmark_performance.py artifacts, including the project's existing
evaluation.metrics results. Full output equality excludes only opt-in timing
metadata; proxy metrics are never described as human linguistic evaluation.
"""
from __future__ import annotations

import argparse
from copy import deepcopy
import json
from pathlib import Path


def _key(case):
    return case["case"], case["source_sha256"], json.dumps(case["configuration"], sort_keys=True)


def _output(case):
    value = deepcopy(case["output"])
    value.get("source_metadata", {}).pop("performance", None)
    return value


def _without_metric_duration(value):
    if isinstance(value, dict):
        return {key: _without_metric_duration(item) for key, item in value.items() if key != "duration_seconds"}
    if isinstance(value, list):
        return [_without_metric_duration(item) for item in value]
    return value


def _differences(left, right, path=""):
    if type(left) is not type(right):
        return [path or "$root"]
    if isinstance(left, dict):
        paths = []
        for key in sorted(left.keys() | right.keys()):
            child = f"{path}.{key}" if path else str(key)
            paths.extend([child] if key not in left or key not in right else _differences(left[key], right[key], child))
        return paths
    if isinstance(left, list):
        if len(left) != len(right):
            return [f"{path}.length"]
        return [child for index, (a, b) in enumerate(zip(left, right)) for child in _differences(a, b, f"{path}[{index}]")]
    return [] if left == right else [path]


def compare_reports(before_reports, after_reports):
    before = {_key(case): case for report in before_reports for case in report["cases"]}
    after = {_key(case): case for report in after_reports for case in report["cases"]}
    rows = []
    for key in sorted(before.keys() & after.keys()):
        old, new = before[key], after[key]
        changed = _differences(_output(old), _output(new))
        old_metrics = _without_metric_duration(old.get("automated_quality_metrics", []))
        new_metrics = _without_metric_duration(new.get("automated_quality_metrics", []))
        old_runs = {(run["state"], run["iteration"]): run for run in old["runs"]}
        new_runs = {(run["state"], run["iteration"]): run for run in new["runs"]}
        timings = []
        for run_key in sorted(old_runs.keys() & new_runs.keys()):
            a, b = old_runs[run_key], new_runs[run_key]
            elapsed_before, elapsed_after = a["summarize_seconds"], b["summarize_seconds"]
            timings.append({
                "state": run_key[0], "iteration": run_key[1],
                "before_seconds": elapsed_before, "after_seconds": elapsed_after,
                "reduction_percent": (1 - elapsed_after / elapsed_before) * 100 if elapsed_before else None,
                "selected_output_identical": a["selected_output_sha256"] == b["selected_output_sha256"],
                "quality_diagnostics_identical": a["quality"] == b["quality"],
            })
        rows.append({
            "case": key[0], "source_sha256": key[1], "configuration": old["configuration"],
            "full_output_identical_except_performance": not changed,
            "changed_output_paths": changed,
            "automated_metrics_identical_except_duration": old_metrics == new_metrics,
            "metrics": [{"name": metric["name"], "status": metric["status"], "value": metric.get("value")}
                        for metric in new_metrics],
            "runs": timings,
        })
    return {
        "schema": "light-performance-quality-comparison-v1",
        "comparison_policy": "Identical source hash and selected settings required. Full output excludes only source_metadata.performance. Existing automated proxy metrics exclude only duration_seconds.",
        "human_linguistic_evaluation": "Not performed by this automated comparison; exact equality establishes preservation of existing output, not independent quality certification.",
        "matched_case_count": len(rows),
        "unmatched_before": [key[0] for key in sorted(before.keys() - after.keys())],
        "unmatched_after": [key[0] for key in sorted(after.keys() - before.keys())],
        "all_outputs_identical": bool(rows) and before.keys() == after.keys() and all(row["full_output_identical_except_performance"] for row in rows),
        "all_automated_metrics_identical": bool(rows) and before.keys() == after.keys() and all(row["automated_metrics_identical_except_duration"] for row in rows),
        "cases": rows,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--before", nargs="+", required=True)
    parser.add_argument("--after", nargs="+", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    read = lambda paths: [json.loads(Path(path).read_text(encoding="utf-8")) for path in paths]
    report = compare_reports(read(args.before), read(args.after))
    path = Path(args.output)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps({key: report[key] for key in ("matched_case_count", "all_outputs_identical", "all_automated_metrics_identical", "unmatched_before", "unmatched_after")}))


if __name__ == "__main__":
    main()
