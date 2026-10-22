"""
Local JSON-over-stdin CLI for summarize, translate, and TTS.

This replaces the need for the PHP app to call the Python runtime over HTTP.
"""

from __future__ import annotations

from contextlib import contextmanager
from dataclasses import asdict
from datetime import datetime, timezone
import errno
import json
import math
import os
from pathlib import Path
import re
import sys
import threading
import time
from email.utils import parsedate_to_datetime
from failure_contract import ProcessingFailure, failure_response

def load_project_env() -> None:
    env_path = Path(__file__).resolve().parent.parent / ".env"
    if not env_path.is_file():
        return

    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue

        name, value = line.split("=", 1)
        name = name.strip()
        if not name:
            continue

        cleaned = value.strip()
        if len(cleaned) >= 2 and cleaned[0] == cleaned[-1] and cleaned[0] in {'"', "'"}:
            cleaned = cleaned[1:-1]

        os.environ[name] = cleaned


load_project_env()

import logging
logging.getLogger("pypdf").setLevel(logging.ERROR)
logging.getLogger("urllib3").setLevel(logging.WARNING)

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

# Import each action's dependencies only when that action is requested. In
# particular summarization must not import the TTS/translation runtimes.


class PiperTtsUnavailable(RuntimeError):
    """CLI error category preserving the unavailable TTS exit status."""


class PiperTtsValidationError(ValueError):
    """CLI error category preserving the invalid TTS input exit status."""


def extract_pdf_text(file_path: str) -> str:
    from summarizer_core.extraction import extract_pdf_text as extract
    return extract(file_path)

_configured_translation_lang = (os.getenv("TRANSLATION_TARGET_LANG", "tl") or "tl").strip().lower()
DEFAULT_TARGET_LANGUAGE = "tl" if _configured_translation_lang in {"tl", "fil", "filipino", "tagalog"} else "en"
TRANSLATION_PROVIDER = (os.getenv("TRANSLATION_PROVIDER", "deep-translator") or "deep-translator").strip().lower()
SUPPORTED_TARGET_LANGUAGES = {"tl", "en"}
# Keep headroom below MyMemory's strict 500-character limit (its request
# encoding can add a few characters around chunk boundaries).
TRANSLATION_CHUNK_SIZE = 440
TRANSLATION_PLACEHOLDER_PREFIX = "ZXQKEEP"
TRANSLATION_PLACEHOLDER_PATTERN = re.compile(
    re.escape(TRANSLATION_PLACEHOLDER_PREFIX) + r"\d+TOKEN"
)
PROXY_ENV_KEYS = (
    "ALL_PROXY",
    "HTTP_PROXY",
    "HTTPS_PROXY",
    "NO_PROXY",
    "all_proxy",
    "http_proxy",
    "https_proxy",
    "no_proxy",
)
PROGRAMMING_KEYWORDS = {
    "class", "const", "continue", "def", "echo", "else", "elseif", "false",
    "for", "foreach", "from", "function", "if", "import", "interface", "null",
    "private", "protected", "public", "return", "static", "switch", "true",
    "try", "while",
}
PROTECTED_TEXT_PATTERNS = (
    re.compile(r"https?://\S+|www\.\S+", re.IGNORECASE),
    re.compile(r"`[^`\n]+`"),
    re.compile(r"\b[A-Za-z0-9._%+-]+\.(?:php|py|js|ts|json|yaml|yml|html|css|sql|txt|csv|md|pdf|docx|wav|mp3)\b"),
    re.compile(r"\[[0-9,\-\s]+\]"),
    re.compile(r"\([A-Z][A-Za-z]+(?:\s+et al\.)?,\s*\d{4}[a-z]?\)"),
    re.compile(r"\b[A-Z]{2,}(?:[-/][A-Z0-9]{2,})*\b"),
)
PROGRAMMING_KEYWORD_PATTERN = re.compile(
    r"\b(?:"
    + "|".join(sorted((re.escape(keyword) for keyword in PROGRAMMING_KEYWORDS), key=len, reverse=True))
    + r")\b"
)


class TranslationServiceError(RuntimeError):
    """Raised when the translation provider could not return text."""

    def __init__(self, message: str, *, code: str = "TRANSLATION_FAILED", provider: str | None = None, retry_after: float | None = None, retryable: bool = True):
        super().__init__(message)
        self.code = code
        self.provider = provider
        self.retry_after = retry_after
        self.retryable = retryable


_PROVIDER_COOLDOWN_SECONDS = {
    "google": 60.0,
    "mymemory": 30.0,
}
_PROVIDER_COOLDOWN_LOCK = threading.RLock()
_PROVIDER_COOLDOWN_FILE = Path(__file__).resolve().parent.parent / "storage" / "translation_provider_cooldown.json"
_PROVIDER_COOLDOWN_STATE: dict[str, float] = {}
_PROVIDER_REQUEST_HISTORY: dict[str, float] = {}
_PROVIDER_REQUEST_LOCKS = {
    name: threading.Lock() for name in _PROVIDER_COOLDOWN_SECONDS
}
_PROVIDER_REQUEST_LOCK_TIMEOUT_SECONDS = 10.0


@contextmanager
def _provider_state_lock(lock_path: Path | None = None, *, timeout: float | None = None):
    lock_path = lock_path or _PROVIDER_COOLDOWN_FILE.with_suffix(".lock")
    try:
        lock_path.parent.mkdir(parents=True, exist_ok=True)
    except OSError:
        pass

    handle = open(lock_path, "a+b")
    acquired = False
    try:
        if os.name == "nt":
            try:
                import msvcrt
            except ImportError:  # pragma: no cover
                msvcrt = None
            if msvcrt is not None:
                deadline = None if timeout is None else time.monotonic() + max(0.0, timeout)
                while True:
                    handle.seek(0)
                    try:
                        msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
                        acquired = True
                        break
                    except OSError as exc:
                        if exc.errno not in {errno.EACCES, errno.EAGAIN} and getattr(exc, "winerror", None) not in {33, 36}:
                            raise
                        if deadline is not None and time.monotonic() >= deadline:
                            raise TimeoutError("Timed out waiting for the provider request lock.") from exc
                        time.sleep(0.05)
        else:
            try:
                import fcntl
            except ImportError:  # pragma: no cover
                fcntl = None
            if fcntl is not None:
                if timeout is None:
                    fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
                    acquired = True
                else:
                    deadline = time.monotonic() + max(0.0, timeout)
                    while True:
                        try:
                            fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                            acquired = True
                            break
                        except OSError as exc:
                            if exc.errno not in {errno.EACCES, errno.EAGAIN}:
                                raise
                            if time.monotonic() >= deadline:
                                raise TimeoutError("Timed out waiting for the provider request lock.") from exc
                            time.sleep(0.05)
        yield
    finally:
        try:
            if acquired and os.name == "nt":
                try:
                    import msvcrt
                except ImportError:  # pragma: no cover
                    msvcrt = None
                if msvcrt is not None:
                    handle.seek(0)
                    msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)
            elif acquired:
                try:
                    import fcntl
                except ImportError:  # pragma: no cover
                    fcntl = None
                if fcntl is not None:
                    fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
        finally:
            handle.close()


@contextmanager
def _provider_request_lock(provider_name: str):
    if provider_name not in _PROVIDER_REQUEST_LOCKS:
        yield
        return

    process_lock = _PROVIDER_REQUEST_LOCKS[provider_name]
    lock_path = _PROVIDER_COOLDOWN_FILE.with_name(
        f"{_PROVIDER_COOLDOWN_FILE.stem}.{provider_name}.request.lock"
    )
    deadline = time.monotonic() + _PROVIDER_REQUEST_LOCK_TIMEOUT_SECONDS
    if not process_lock.acquire(timeout=_PROVIDER_REQUEST_LOCK_TIMEOUT_SECONDS):
        raise TimeoutError("Timed out waiting for the provider request lock.")
    try:
        with _provider_state_lock(lock_path, timeout=max(0.0, deadline - time.monotonic())):
            yield
    finally:
        process_lock.release()


def _read_provider_cooldown_state() -> dict[str, float]:
    path = _PROVIDER_COOLDOWN_FILE
    try:
        if not path.exists():
            return {}
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, TypeError, ValueError):
        return {}

    if not isinstance(payload, dict):
        return {}

    normalized: dict[str, float] = {}
    for name, until in payload.items():
        try:
            normalized[str(name)] = float(until)
        except (TypeError, ValueError):
            continue

    now = time.time()
    expired = [name for name, until in normalized.items() if until <= now]
    for name in expired:
        del normalized[name]
    return normalized


def _write_provider_cooldown_state(state: dict[str, float]) -> None:
    path = _PROVIDER_COOLDOWN_FILE
    path.parent.mkdir(parents=True, exist_ok=True)
    temp_path = path.with_suffix(path.suffix + ".tmp")
    temp_path.write_text(json.dumps(state, sort_keys=True), encoding="utf-8")
    os.replace(temp_path, path)

def _resolve_provider_name(translator: object) -> str:
    module_name = type(translator).__module__.lower()
    class_name = type(translator).__name__.lower()
    if "memory" in module_name or "memory" in class_name:
        return "mymemory"
    if "google" in module_name or "google" in class_name:
        return "google"
    return "unknown"


def _normalize_retry_after(value: object) -> float | None:
    if value is None:
        return None
    if isinstance(value, (int, float)):
        try:
            seconds = float(value)
        except (TypeError, ValueError, OverflowError):
            return None
        return seconds if math.isfinite(seconds) and seconds > 0 else None
    if isinstance(value, str):
        stripped = value.strip()
        if not stripped:
            return None
        try:
            seconds = float(stripped)
            return seconds if math.isfinite(seconds) and seconds > 0 else None
        except ValueError:
            try:
                retry_at = parsedate_to_datetime(stripped)
                now = datetime.now(retry_at.tzinfo or timezone.utc)
                seconds = (retry_at - now).total_seconds()
                return seconds if math.isfinite(seconds) and seconds > 0 else None
            except (TypeError, ValueError, OverflowError):
                return None
    return None


def _provider_cooldown_until(provider_name: str, *, now: float | None = None) -> float:
    if not provider_name:
        return 0.0
    now_value = time.time() if now is None else float(now)
    with _PROVIDER_COOLDOWN_LOCK:
        with _provider_state_lock():
            state = _read_provider_cooldown_state()
            _PROVIDER_COOLDOWN_STATE.clear()
            _PROVIDER_COOLDOWN_STATE.update(state)
            return float(state.get(provider_name, 0.0))


def _apply_provider_cooldown(provider_name: str, retry_after: float | None = None) -> float:
    if not provider_name:
        return 0.0
    base_delay = retry_after if retry_after is not None else _PROVIDER_COOLDOWN_SECONDS.get(provider_name, 0.0)
    window = max(0.0, float(base_delay))
    with _PROVIDER_COOLDOWN_LOCK:
        with _provider_state_lock():
            state = _read_provider_cooldown_state()
            now = time.time()
            cooldown_until = max(float(state.get(provider_name, 0.0)), now + window)
            state[provider_name] = cooldown_until
            _PROVIDER_COOLDOWN_STATE.clear()
            _PROVIDER_COOLDOWN_STATE.update(state)
            _PROVIDER_REQUEST_HISTORY[provider_name] = now
            _write_provider_cooldown_state(state)
    return cooldown_until


def _provider_is_cooling_down(provider_name: str) -> bool:
    if not provider_name:
        return False
    with _PROVIDER_COOLDOWN_LOCK:
        with _provider_state_lock():
            state = _read_provider_cooldown_state()
            _PROVIDER_COOLDOWN_STATE.clear()
            _PROVIDER_COOLDOWN_STATE.update(state)
            until = float(state.get(provider_name, 0.0))
    return time.time() < until


def _provider_retry_delay(provider_name: str) -> float:
    if not provider_name:
        return 0.0
    with _PROVIDER_COOLDOWN_LOCK:
        with _provider_state_lock():
            state = _read_provider_cooldown_state()
            _PROVIDER_COOLDOWN_STATE.clear()
            _PROVIDER_COOLDOWN_STATE.update(state)
            until = float(state.get(provider_name, 0.0))
    return max(0.0, until - time.time())


def _max_provider_retry_delay(provider_names: set[str] | list[str] | tuple[str, ...]) -> float:
    candidates = [name for name in provider_names if name]
    if not candidates:
        return 0.0
    return max(_provider_retry_delay(name) for name in set(candidates))


def _raise_provider_error(provider_name: str, exc: Exception, *, retry_after: float | None = None) -> None:
    name = provider_name.lower()
    if name in {"google", "mymemory"} and _provider_is_cooling_down(name):
        delay = _provider_retry_delay(name)
        raise TranslationServiceError(
            "Filipino translation is temporarily unavailable because the translation service is busy. Your original summary is safe. Please try again later.",
            code="TRANSLATION_RATE_LIMITED",
            provider=name,
            retry_after=delay,
            retryable=True,
        ) from exc
    if retry_after is not None:
        _apply_provider_cooldown(name, retry_after)
    if "rate" in str(type(exc)).lower() or "429" in str(exc) or "too many requests" in str(exc).lower():
        _apply_provider_cooldown(name, retry_after)
        raise TranslationServiceError(
            "Filipino translation is temporarily unavailable because the translation service is busy. Your original summary is safe. Please try again later.",
            code="TRANSLATION_RATE_LIMITED",
            provider=name,
            retry_after=retry_after,
            retryable=True,
        ) from exc
    if "timeout" in str(exc).lower() or "timed out" in str(exc).lower():
        raise TranslationServiceError("Translation timed out.", code="TRANSLATION_TIMEOUT", provider=name, retryable=True) from exc
    if "proxy" in str(exc).lower() or "network" in str(exc).lower():
        raise TranslationServiceError("Translation service is temporarily unavailable.", code="TRANSLATION_UNAVAILABLE", provider=name, retryable=True) from exc
    raise TranslationServiceError("Translation service is temporarily unavailable.", code="TRANSLATION_UNAVAILABLE", provider=name, retryable=True) from exc


def read_request() -> dict:
    raw = sys.stdin.read()
    if raw.strip() == "":
        return {}

    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError as exc:
        emit_error(ProcessingFailure("REQUEST_INVALID"), exit_code=2)

    if not isinstance(decoded, dict):
        emit_error(ProcessingFailure("REQUEST_INVALID"), exit_code=2)

    return decoded


def emit_json(payload: dict) -> None:
    json.dump(payload, sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")


def emit_error(error: BaseException, *, exit_code: int = 1) -> None:
    json.dump(failure_response(error), sys.stderr, ensure_ascii=False)
    sys.stderr.write("\n")
    raise SystemExit(exit_code)


@contextmanager
def temporary_proxy_bypass():
    original_values: dict[str, str | None] = {}

    for key in PROXY_ENV_KEYS:
        original_values[key] = os.environ.get(key)
        os.environ.pop(key, None)

    try:
        yield
    finally:
        for key, value in original_values.items():
            if value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = value


def protect_translation_segments(text: str) -> tuple[str, dict[str, str]]:
    replacements: dict[str, str] = {}
    counter = 0

    def remember(value: str) -> str:
        nonlocal counter
        token = f"{TRANSLATION_PLACEHOLDER_PREFIX}{counter}TOKEN"
        replacements[token] = value
        counter += 1
        return token

    protected_text = text
    for pattern in PROTECTED_TEXT_PATTERNS:
        protected_text = pattern.sub(lambda match: remember(match.group(0)), protected_text)

    protected_text = PROGRAMMING_KEYWORD_PATTERN.sub(
        lambda match: remember(match.group(0)),
        protected_text,
    )
    return protected_text, replacements


def restore_translation_segments(text: str, replacements: dict[str, str]) -> str:
    restored = text
    for token, original in replacements.items():
        restored = restored.replace(token, original)
    return restored


def chunk_translation_text(text: str, max_size: int = TRANSLATION_CHUNK_SIZE) -> list[str]:
    """Split protected text without breaking placeholder tokens."""
    if max_size < 1:
        raise ValueError("Translation chunk size must be positive.")

    chunks: list[str] = []
    current = ""

    def append_fragment(fragment: str, *, atomic: bool = False) -> None:
        nonlocal current
        if atomic and len(fragment) > max_size:
            raise ValueError("Translation placeholder exceeds the provider chunk limit.")

        while fragment:
            available = max_size - len(current)
            if available == 0 or (atomic and len(fragment) > available):
                if current:
                    chunks.append(current)
                    current = ""
                    available = max_size
                else:
                    raise ValueError("Translation placeholder exceeds the provider chunk limit.")

            piece = fragment[:available]
            current += piece
            fragment = fragment[len(piece):]
            if len(current) == max_size:
                chunks.append(current)
                current = ""

    position = 0
    for match in TRANSLATION_PLACEHOLDER_PATTERN.finditer(text):
        append_fragment(text[position:match.start()])
        append_fragment(match.group(0), atomic=True)
        position = match.end()
    append_fragment(text[position:])

    if current:
        chunks.append(current)
    return chunks


def _classify_translation_exception(provider_name: str, exc: Exception) -> TranslationServiceError:
    text = f"{type(exc).__name__}: {exc}".lower()
    headers = {}
    response = getattr(exc, "response", None)
    if response is not None:
        headers = getattr(response, "headers", {}) or {}
    retry_after = _normalize_retry_after(headers.get("Retry-After") or headers.get("retry-after"))
    if retry_after is None:
        retry_after = _normalize_retry_after(getattr(exc, "retry_after", None))

    if any(marker in text for marker in ("429", "too many requests", "rate limit", "quota exceeded", "exceeded quota", "rate_limited")):
        cooldown_until = _apply_provider_cooldown(provider_name, retry_after)
        remaining = max(0.0, cooldown_until - time.time())
        raise TranslationServiceError(
            "Filipino translation is temporarily unavailable because the translation service is busy. Your original summary is safe. Please try again later.",
            code="TRANSLATION_RATE_LIMITED",
            provider=provider_name,
            retry_after=remaining if remaining > 0 else None,
        ) from exc
    if any(marker in text for marker in ("timeout", "timed out", "read timed out")):
        raise TranslationServiceError("Translation timed out.", code="TRANSLATION_TIMEOUT", provider=provider_name, retry_after=retry_after, retryable=True) from exc
    if any(marker in text for marker in ("proxy", "network", "connection", "ssl", "dns")):
        raise TranslationServiceError("Translation service is temporarily unavailable.", code="TRANSLATION_NETWORK_FAILURE", provider=provider_name, retry_after=retry_after, retryable=True) from exc
    if any(marker in text for marker in ("json", "invalid response", "empty response", "not json", "decode")):
        raise TranslationServiceError("The translation service returned an invalid response.", code="TRANSLATION_INVALID_RESPONSE", provider=provider_name, retry_after=retry_after, retryable=True) from exc
    raise TranslationServiceError("Translation service is temporarily unavailable.", code="TRANSLATION_UNAVAILABLE", provider=provider_name, retry_after=retry_after, retryable=True) from exc


def translate_text(text: str, target_lang: str) -> str:
    from deep_translator import GoogleTranslator, MyMemoryTranslator
    from requests import exceptions as requests_exceptions
    normalized = (text or "").strip()
    if normalized == "":
        raise ValueError("Text is required for translation.")

    if target_lang not in SUPPORTED_TARGET_LANGUAGES:
        raise ValueError(f"Unsupported target language: {target_lang}")

    protected_text, replacements = protect_translation_segments(normalized)

    try:
        with temporary_proxy_bypass():
            google = GoogleTranslator(source="auto", target=target_lang)
            memory = MyMemoryTranslator(
                source="english",
                target="filipino" if target_lang == "tl" else target_lang,
            )
            translators = (memory, google) if target_lang == "tl" else (google, memory)
            cooldown_translators = [
                {"name": _resolve_provider_name(translator), "translator": translator}
                for translator in translators
            ]
            if all(_provider_is_cooling_down(item["name"]) for item in cooldown_translators if item["name"]):
                max_delay = _max_provider_retry_delay(item["name"] for item in cooldown_translators if item["name"])
                raise TranslationServiceError(
                    "Filipino translation is temporarily unavailable because the translation service is busy. Your original summary is safe. Please try again later.",
                    code="TRANSLATION_RATE_LIMITED",
                    provider="multiple",
                    retry_after=max_delay,
                    retryable=True,
                )
            translated_chunks = []
            last_error = None
            for chunk in chunk_translation_text(protected_text):
                translated_chunk = ""
                for translator in translators:
                    provider_name = _resolve_provider_name(translator)
                    if _provider_is_cooling_down(provider_name):
                        continue
                    try:
                        with _provider_request_lock(provider_name):
                            if _provider_is_cooling_down(provider_name):
                                continue
                            try:
                                translated_chunk = (translator.translate(chunk) or "").strip()
                            except Exception as exc:
                                last_error = exc
                                try:
                                    _classify_translation_exception(provider_name, exc)
                                except TranslationServiceError as classified:
                                    last_error = classified
                                continue
                            if translated_chunk:
                                break
                            raise TranslationServiceError(
                                "The translation service returned an invalid response.",
                                code="TRANSLATION_VALIDATION_ERROR",
                                provider=provider_name,
                                retryable=False,
                            )
                    except TimeoutError as exc:
                        last_error = TranslationServiceError(
                            "The translation service is taking too long to respond.",
                            code="TRANSLATION_TIMEOUT",
                            provider=provider_name,
                            retryable=True,
                        )
                        last_error.__cause__ = exc
                if not translated_chunk:
                    if last_error is not None:
                        if isinstance(last_error, TranslationServiceError):
                            raise last_error
                        raise TranslationServiceError("Translation returned empty text.", code="TRANSLATION_VALIDATION_ERROR", provider=None, retryable=False)
                    raise TranslationServiceError("Translation returned empty text.", code="TRANSLATION_VALIDATION_ERROR", provider=None, retryable=False)
                translated_chunks.append(translated_chunk)
            translated = " ".join(translated_chunks)
            restored = restore_translation_segments(translated, replacements).strip()
            if restored != "":
                return restored
            raise TranslationServiceError("Translation returned empty text.", code="TRANSLATION_VALIDATION_ERROR", provider=None, retryable=False)
    except requests_exceptions.ProxyError as exc:
        raise TranslationServiceError("Translation failed because the configured proxy is unreachable.", code="TRANSLATION_NETWORK_FAILURE", provider="proxy", retryable=True) from exc
    except requests_exceptions.Timeout as exc:
        raise TranslationServiceError("Translation timed out.", code="TRANSLATION_TIMEOUT", provider="network", retryable=True) from exc
    except requests_exceptions.RequestException as exc:
        raise TranslationServiceError("Translation service is temporarily unavailable.", code="TRANSLATION_UNAVAILABLE", provider="network", retryable=True) from exc
    except TranslationServiceError:
        raise
    except Exception as exc:
        raise TranslationServiceError("Translation failed.", code="TRANSLATION_WORKER_FAILURE", provider=None, retryable=False) from exc


def handle_summarize(request: dict) -> dict:
    text = request.get("text", "")
    file_path = request.get("file_path", "")
    if not isinstance(text, str) or not isinstance(file_path, str):
        raise ProcessingFailure("REQUEST_INVALID")
    if not text.strip() and not file_path.strip():
        raise ProcessingFailure("INPUT_EMPTY")
    from summarizer_core.runtime import request_scope, stage
    profile = request.get("performance_profile") is True
    with request_scope(profile=profile) as runtime:
        with stage("summary_request"):
            result = _summarize_request(request)
        if profile:
            result.setdefault("source_metadata", {})["performance"] = runtime.report()
        return result


def _summarize_request(request: dict) -> dict:
    """Run the single structured pipeline used by every output style.

    The previous compatibility path ignored the requested length and style,
    leaving the UI selection disconnected from the generated result.
    """
    from summarizer_core.runtime import stage
    with stage("nlp_library_imports"):
        from summarizer_core.pipeline import SummarizationPipeline
        from summarizer_core.models import PreprocessingOptions, SummarizationRequest
    raw_preprocessing = request.get("preprocessing")
    preprocessing = PreprocessingOptions.from_mapping(raw_preprocessing)
    try:
        sentence_count = int(request.get("sentence_count", 8))
    except (TypeError, ValueError):
        sentence_count = 8

    result = SummarizationPipeline().summarize(SummarizationRequest(
        text=str(request.get("text", "")),
        file_path=str(request.get("file_path", "")),
        sentence_count=max(3, min(15, sentence_count)),
        preprocessing_options=preprocessing,
        summary_style=str(request.get("summary_style", "standard_paragraph")),
        summary_length=str(request.get("summary_length", "balanced")),
        summary_depth=str(request.get("summary_depth", request.get("summary_length", "balanced"))),
        selection_mode=str(request.get("selection_mode", request.get("summary_style", "general"))),
        document_title=str(request.get("document_title", "")),
        analysis_mode=str(request.get("analysis_mode", request.get("selection_mode", ""))),
        output_format=str(request.get("output_format", "")),
        use_llm_synthesis=request.get("use_llm_synthesis") is True,
        focus_query=str(request.get("focus_query", "")),
        target_word_budget=(
            int(request.get("target_word_budget", 0))
            if isinstance(request.get("target_word_budget", 0), int)
            and not isinstance(request.get("target_word_budget", 0), bool)
            else 0
        ),
    ))
    with stage("result_serialization"):
        return asdict(result)


def handle_qa(request: dict) -> dict:
    """Answer questions strictly grounded in the document source text."""
    question = str(request.get("question", "")).strip()
    if not question:
        raise ValueError("A question is required.")

    raw_text = str(request.get("text", "")).strip()
    if not raw_text and request.get("file_path"):
        file_path = str(request.get("file_path", "")).strip()
        if file_path.lower().endswith(".pdf"):
            raw_text = extract_pdf_text(file_path)
        elif Path(file_path).is_file():
            raw_text = Path(file_path).read_text(encoding="utf-8", errors="replace")

    if not raw_text:
        raise ValueError("Source document text is required to answer questions.")

    from summarizer_core.text_utils import fallback_sentence_split, normalize_whitespace
    from sklearn.feature_extraction.text import TfidfVectorizer

    sentences = [normalize_whitespace(s) for s in fallback_sentence_split(raw_text)]
    candidate_sentences = [
        (idx, s) for idx, s in enumerate(sentences)
        if len(s.split()) >= 4 and not s.endswith("?")
    ]

    if not candidate_sentences:
        return {
            "found": False,
            "question": question,
            "answer": "The document does not contain sufficient text to answer this question.",
            "passages": [],
            "confidence": "insufficient_evidence",
            "message": "No candidate sentences could be extracted from the document.",
        }

    corpus = [question] + [s[1] for s in candidate_sentences]
    vectorizer = TfidfVectorizer(ngram_range=(1, 2), stop_words="english", min_df=1)
    tfidf_matrix = vectorizer.fit_transform(corpus)

    q_vec = tfidf_matrix[0:1]
    doc_vecs = tfidf_matrix[1:]

    sims = (doc_vecs * q_vec.T).toarray().ravel()

    q_tokens = set(re.findall(r"\b[a-zA-Z0-9_-]{3,}\b", question.lower()))
    scored_candidates = []
    for (orig_idx, sent_text), sim in zip(candidate_sentences, sims):
        sent_tokens = set(re.findall(r"\b[a-zA-Z0-9_-]{3,}\b", sent_text.lower()))
        overlap = len(q_tokens.intersection(sent_tokens)) / max(1, len(q_tokens))
        composite_score = float(sim) * 0.75 + float(overlap) * 0.25
        scored_candidates.append({
            "index": orig_idx,
            "text": sent_text,
            "score": composite_score,
            "sim": float(sim),
        })

    scored_candidates.sort(key=lambda x: x["score"], reverse=True)
    top_candidates = [c for c in scored_candidates if c["score"] >= 0.15]

    if not top_candidates:
        return {
            "found": False,
            "question": question,
            "answer": "The document does not contain information directly addressing this question.",
            "passages": [],
            "confidence": "insufficient_evidence",
            "message": "No sufficiently relevant passages were found in the source text.",
        }

    selected = top_candidates[:3]
    selected.sort(key=lambda x: x["index"])

    grounded_answer = " ".join(c["text"] for c in selected)
    top_score = top_candidates[0]["score"]

    return {
        "found": True,
        "question": question,
        "answer": grounded_answer,
        "passages": [
            {
                "index": c["index"],
                "text": c["text"],
                "score": round(c["score"], 4),
            }
            for c in selected
        ],
        "confidence": "high" if top_score >= 0.35 else "moderate",
        "message": "Answer grounded directly in source document passages.",
    }


def handle_extract_pdf(request: dict) -> dict:
    file_path = str(request.get("file_path", ""))
    if file_path.strip() == "":
        raise ProcessingFailure("FILE_REQUIRED")

    return {
        "raw_text": extract_pdf_text(file_path),
    }


def handle_translate(request: dict) -> dict:
    target_lang = str(request.get("target_lang", DEFAULT_TARGET_LANGUAGE) or DEFAULT_TARGET_LANGUAGE)
    translated = translate_text(str(request.get("text", "")), target_lang)
    return {
        "translated": translated,
        "target_lang": target_lang,
        "provider": TRANSLATION_PROVIDER or "deep-translator",
    }


def handle_self_test(_: dict) -> dict:
    return {
        "status": "ok",
        "python_version": sys.version.split()[0],
        "translation_provider": TRANSLATION_PROVIDER,
        "default_target_language": DEFAULT_TARGET_LANGUAGE,
        "worker": "local_cli",
    }


def handle_tts(request: dict) -> dict:
    from piper_tts import (
        PiperTtsUnavailable as Unavailable,
        PiperTtsValidationError as InvalidInput,
        generate_cached_audio,
        normalize_language_code,
    )
    try:
        language = normalize_language_code(str(request.get("language", "en") or "en"))
        result = generate_cached_audio(str(request.get("text", "")), language)
    except Unavailable as exc:
        raise PiperTtsUnavailable(str(exc)) from exc
    except InvalidInput as exc:
        raise PiperTtsValidationError(str(exc)) from exc
    return {
        "file_name": result.file_name,
        "cached": result.cached,
        "message": "Cached audio ready." if result.cached else "Audio generated successfully.",
        "language": language,
    }


def handle_nutshell(request: dict) -> dict:
    from summarizer_core.nutshell import generate_nutshell
    from summarizer_core.models import PreprocessingOptions
    preprocessing_options = PreprocessingOptions.from_mapping(request.get("preprocessing"))
    return generate_nutshell(
        text=str(request.get("text", "")),
        file_path=str(request.get("file_path", "")),
        preprocessing_options=preprocessing_options,
        analysis_mode=str(request.get("analysis_mode", "general")),
        primary_summary_word_count=int(request.get("primary_summary_word_count", 0) or 0),
    )


def handle_generate_summary(request: dict) -> dict:
    from summarizer import generate_summary
    return generate_summary(
        source_text=str(request.get("text", "")),
        profile=str(request.get("profile", request.get("selection_mode", "general"))),
        length=str(request.get("length", request.get("summary_length", "balanced"))),
    )


def main() -> int:
    if len(sys.argv) < 2:
        emit_error(ProcessingFailure("REQUEST_INVALID"), exit_code=2)

    action = sys.argv[1].strip().lower()
    request = read_request()

    try:
        if action == "summarize":
            emit_json(handle_summarize(request))
            return 0
        if action == "generate-summary":
            emit_json(handle_generate_summary(request))
            return 0
        if action == "nutshell":
            emit_json(handle_nutshell(request))
            return 0
        if action == "extract-pdf":
            emit_json(handle_extract_pdf(request))
            return 0
        if action == "translate":
            emit_json(handle_translate(request))
            return 0
        if action == "self-test":
            emit_json(handle_self_test(request))
            return 0
        if action == "tts-generate":
            emit_json(handle_tts(request))
            return 0
        if action == "qa":
            emit_json(handle_qa(request))
            return 0

        emit_error(ProcessingFailure("REQUEST_INVALID"), exit_code=2)
    except (ValueError, PiperTtsValidationError) as exc:
        emit_error(exc, exit_code=2)
    except TranslationServiceError as exc:
        emit_error(exc, exit_code=1)
    except PiperTtsUnavailable as exc:
        emit_error(exc, exit_code=3)
    except Exception as exc:
        emit_error(exc, exit_code=1)

    return 1


if __name__ == "__main__":
    raise SystemExit(main())
