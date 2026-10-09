"""
Local JSON-over-stdin CLI for summarize, translate, and TTS.

This replaces the need for the PHP app to call the Python runtime over HTTP.
"""

from __future__ import annotations

from contextlib import contextmanager
from dataclasses import asdict
import json
import os
from pathlib import Path
import re
import sys

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

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

try:
    from deep_translator import GoogleTranslator, MyMemoryTranslator
    from requests import exceptions as requests_exceptions
    from piper_tts import (
        PiperTtsUnavailable,
        PiperTtsValidationError,
        generate_cached_audio,
        normalize_language_code,
    )
    from summarizer import extract_pdf_text
    from summarizer_core.pipeline import SummarizationPipeline
    from summarizer_core.models import PreprocessingOptions, SummarizationRequest
except ImportError as exc:
    import sys
    sys.stderr.write(f"Import Error: {str(exc)}\n")
    sys.exit(1)
except Exception as exc:
    import sys
    import traceback
    sys.stderr.write(f"Initialization Error: {str(exc)}\n{traceback.format_exc()}\n")
    sys.exit(1)

_configured_translation_lang = (os.getenv("TRANSLATION_TARGET_LANG", "tl") or "tl").strip().lower()
DEFAULT_TARGET_LANGUAGE = "tl" if _configured_translation_lang in {"tl", "fil", "filipino", "tagalog"} else "en"
TRANSLATION_PROVIDER = (os.getenv("TRANSLATION_PROVIDER", "deep-translator") or "deep-translator").strip().lower()
SUPPORTED_TARGET_LANGUAGES = {"tl", "en"}
TRANSLATION_CHUNK_SIZE = 500
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


def read_request() -> dict:
    raw = sys.stdin.read()
    if raw.strip() == "":
        return {}

    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError as exc:
        emit_error(f"Invalid JSON request payload: {exc}", exit_code=2)

    if not isinstance(decoded, dict):
        emit_error("Request payload must be a JSON object.", exit_code=2)

    return decoded


def emit_json(payload: dict) -> None:
    json.dump(payload, sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")


def emit_error(message: str, *, exit_code: int = 1) -> None:
    json.dump({"error": message}, sys.stderr, ensure_ascii=False)
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


def translate_text(text: str, target_lang: str) -> str:
    normalized = (text or "").strip()
    if normalized == "":
        raise ValueError("Text is required for translation.")

    if target_lang not in SUPPORTED_TARGET_LANGUAGES:
        raise ValueError(f"Unsupported target language: {target_lang}")

    protected_text, replacements = protect_translation_segments(normalized)

    try:
        with temporary_proxy_bypass():
            translators = (
                GoogleTranslator(source="auto", target=target_lang),
                MyMemoryTranslator(
                    source="english",
                    target="filipino" if target_lang == "tl" else target_lang,
                ),
            )
            last_error = None
            for translator in translators:
                try:
                    translated_chunks = [
                        translator.translate(chunk)
                        for chunk in chunk_translation_text(protected_text)
                    ]
                    translated = " ".join(chunk for chunk in translated_chunks if chunk)
                    restored = restore_translation_segments(translated, replacements).strip()
                    if restored != "":
                        return restored
                except Exception as exc:
                    last_error = exc
            if last_error is not None:
                raise last_error
            raise TranslationServiceError("Translation returned empty text.")
    except requests_exceptions.ProxyError as exc:
        raise TranslationServiceError(
            "Translation failed because the configured proxy is unreachable."
        ) from exc
    except requests_exceptions.Timeout as exc:
        raise TranslationServiceError("Translation timed out.") from exc
    except requests_exceptions.RequestException as exc:
        raise TranslationServiceError("Translation service is temporarily unavailable.") from exc
    except Exception as exc:
        raise TranslationServiceError("Translation failed.") from exc


def handle_summarize(request: dict) -> dict:
    """Run the single structured pipeline used by every output style.

    The previous compatibility path ignored the requested length and style,
    leaving the UI selection disconnected from the generated result.
    """
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
        target_word_budget=(
            int(request.get("target_word_budget", 0))
            if isinstance(request.get("target_word_budget", 0), int)
            and not isinstance(request.get("target_word_budget", 0), bool)
            else 0
        ),
    ))
    return asdict(result)


def handle_extract_pdf(request: dict) -> dict:
    file_path = str(request.get("file_path", ""))
    if file_path.strip() == "":
        raise ValueError("A PDF file path is required.")

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
    language = normalize_language_code(str(request.get("language", "en") or "en"))
    result = generate_cached_audio(str(request.get("text", "")), language)
    return {
        "file_name": result.file_name,
        "cached": result.cached,
        "message": "Cached audio ready." if result.cached else "Audio generated successfully.",
        "language": language,
    }


def handle_nutshell(request: dict) -> dict:
    from summarizer_core.nutshell import generate_nutshell
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
        emit_error("A local worker action is required.", exit_code=2)

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

        emit_error(f"Unsupported local worker action: {action}", exit_code=2)
    except (ValueError, PiperTtsValidationError) as exc:
        emit_error(str(exc), exit_code=2)
    except TranslationServiceError as exc:
        emit_error(str(exc), exit_code=1)
    except PiperTtsUnavailable as exc:
        emit_error(str(exc), exit_code=3)
    except Exception as exc:
        import traceback
        error_details = f"The local NLP worker failed unexpectedly: {str(exc)}\n{traceback.format_exc()}"
        emit_error(error_details, exit_code=1)

    return 1


if __name__ == "__main__":
    raise SystemExit(main())
