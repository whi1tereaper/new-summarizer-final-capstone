"""Safe failures shared by both engines, the CLI, and the persistent worker.

Only explicitly classified exceptions carry a public cause. Arbitrary exception
messages are never returned to a caller or interpreted using string matching.
"""
from __future__ import annotations

from functools import lru_cache
import json
import logging
import math
from pathlib import Path
from typing import Any
from uuid import uuid4


class ProcessingFailure(ValueError):
    def __init__(self, code: str, diagnostic: str = "") -> None:
        super().__init__(diagnostic or code)
        self.failure_code = code


@lru_cache(maxsize=1)
def _catalog() -> dict[str, dict[str, Any]]:
    path = Path(__file__).resolve().parent.parent / "app" / "config" / "error_catalog.json"
    try:
        return json.loads(path.read_text(encoding="utf-8-sig"))
    except (OSError, ValueError):
        # A missing deployment asset must still produce a safe, honest failure.
        return {"PROCESSING_FAILED": {
            "title": "We couldn't finish processing.",
            "explanation": "LIGHT could not complete this request. The cause could not be confirmed.",
            "recovery": "Try again. If this continues, share the reference with support.",
            "retryable": True, "category": "processing", "http_status": 500,
        }}


@lru_cache(maxsize=1)
def _diagnostic_logger() -> logging.Logger:
    logger = logging.getLogger("light.failures")
    logger.propagate = False
    logger.setLevel(logging.WARNING)
    if not logger.handlers:
        try:
            directory = Path(__file__).resolve().parent.parent / "storage" / "logs"
            directory.mkdir(parents=True, exist_ok=True)
            handler = logging.FileHandler(directory / "processing-errors.log", encoding="utf-8")
            handler.setFormatter(logging.Formatter("%(asctime)s %(message)s"))
            logger.addHandler(handler)
        except OSError:
            logger.addHandler(logging.NullHandler())
    return logger


def failure_response(error: BaseException | str, **flags: Any) -> dict[str, Any]:
    code = error if isinstance(error, str) else getattr(
        error, "failure_code", getattr(error, "code", "PROCESSING_FAILED")
    )
    catalog = _catalog()
    if not isinstance(code, str) or code not in catalog:
        code = "PROCESSING_FAILED"
    failure = dict(catalog[code])
    failure.update(code=code, reference=uuid4().hex)
    if code == "TRANSLATION_RATE_LIMITED":
        try:
            retry_after = float(getattr(error, "retry_after", None))
        except (TypeError, ValueError, OverflowError):
            retry_after = 0.0
        if math.isfinite(retry_after) and 0 < retry_after <= 9007199254740991:
            failure["retry_after_seconds"] = math.ceil(retry_after)
    if isinstance(error, BaseException):
        # Avoid document text, file paths, credentials, and third-party messages.
        _diagnostic_logger().warning(
            "request_failed reference=%s code=%s exception_type=%s",
            failure["reference"], code, type(error).__name__,
        )
    return {"ok": False, "error": failure["explanation"],
            "message": failure["explanation"], "failure": failure, **flags}
