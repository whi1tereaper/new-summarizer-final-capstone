"""Offline evaluation JSON CLI. No database access and no web request handler.

Actions: capabilities, evaluate-item, statistics, stream. Stream accepts one
JSON object per line with action/payload; a persistent scorer cache is reused.
"""
from contextlib import redirect_stdout
import json
import os
import sys

# Apply thread limits before numpy/torch imports. Explicit single-process PHP
# queue ownership prevents unbounded concurrent heavyweight model loading.
for name in ("OMP_NUM_THREADS", "MKL_NUM_THREADS", "OPENBLAS_NUM_THREADS"):
    os.environ[name] = "1"
os.environ["TOKENIZERS_PARALLELISM"] = "false"
sys.dont_write_bytecode = True
for stream in (sys.stdout, sys.stderr):
    if hasattr(stream, "reconfigure"):
        stream.reconfigure(encoding="utf-8", errors="replace")


def dispatch(action, payload):
    if action == "statistics":
        from evaluation.statistics import build_statistical_report
        return build_statistical_report(payload)
    from evaluation.runner import capabilities, evaluate_item
    if action == "capabilities":
        return capabilities()
    if action == "evaluate-item":
        return evaluate_item(payload)
    raise ValueError("Unsupported evaluation action.")


def emit(action, raw):
    payload = json.loads(raw) if raw.strip() else {}
    if not isinstance(payload, dict):
        raise ValueError("JSON payload must be an object.")
    with redirect_stdout(sys.stderr):
        response = dispatch(action, payload)
    print(json.dumps(response, ensure_ascii=False, allow_nan=False))
    sys.stdout.flush()


def main():
    action = sys.argv[1] if len(sys.argv) == 2 else ""
    try:
        if action == "stream":
            for line in sys.stdin:
                try:
                    item = json.loads(line)
                    emit(item.get("action", "evaluate-item"), json.dumps(item.get("payload", item)))
                except (ValueError, TypeError, AttributeError) as exc:
                    print(json.dumps({"status": "failed", "error": str(exc)}), flush=True)
            return 0
        emit(action, sys.stdin.read(4_000_001))
        return 0
    except (ValueError, TypeError) as exc:
        print(json.dumps({"status": "failed", "error": str(exc)}), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
