"""Standalone JSON worker entry point for the independent LIGHT NLP engine."""

from __future__ import annotations

from failure_contract import ProcessingFailure, failure_response

import json
import sys

from light_engine import ContractError, summarize


def main() -> int:
    if len(sys.argv) != 2 or sys.argv[1] != "summarize":
        sys.stderr.write(json.dumps(failure_response("REQUEST_INVALID")) + "\n")
        return 2
    try:
        request = json.load(sys.stdin)
        response = summarize(request)
        sys.stdout.write(json.dumps(response, ensure_ascii=False, separators=(",", ":")) + "\n")
        return 0
    except (ProcessingFailure, json.JSONDecodeError) as exc:
        failure = exc if isinstance(exc, ProcessingFailure) else ProcessingFailure("REQUEST_INVALID")
        sys.stderr.write(json.dumps(failure_response(failure), ensure_ascii=False) + "\n")
        return 2
    except Exception as exc:
        sys.stderr.write(json.dumps(failure_response(exc)) + "\n")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
