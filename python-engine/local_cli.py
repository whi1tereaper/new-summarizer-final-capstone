"""
Local JSON-over-stdin CLI for summarize, translate, and TTS.

This replaces the need for the PHP app to call the Python runtime over HTTP.
"""

from __future__ import annotations

from contextlib import contextmanager
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
    from deep_translator import GoogleTranslator
    from requests import exceptions as requests_exceptions
    from piper_tts import (
        PiperTtsUnavailable,
        PiperTtsValidationError,
        generate_cached_audio,
        normalize_language_code,
    )
    from nlp_pipeline import PreprocessingOptions
    from summarizer import extract_pdf_text, summarize_document
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
TRANSLATION_CHUNK_SIZE = 4500
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
TRANSLATION_PLACEHOLDER_PREFIX = "ZXQKEEP"
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


def translate_text(text: str, target_lang: str) -> str:
    normalized = (text or "").strip()
    if normalized == "":
        raise ValueError("Text is required for translation.")

    if target_lang not in SUPPORTED_TARGET_LANGUAGES:
        raise ValueError(f"Unsupported target language: {target_lang}")

    protected_text, replacements = protect_translation_segments(normalized)

    try:
        # use the same provider so Filipino translation stays consistent
        with temporary_proxy_bypass():
            translator = GoogleTranslator(source="auto", target=target_lang)
            translated_chunks = [
                translator.translate(protected_text[index:index + TRANSLATION_CHUNK_SIZE])
                for index in range(0, len(protected_text), TRANSLATION_CHUNK_SIZE)
            ]
        translated = " ".join(chunk for chunk in translated_chunks if chunk)
        restored = restore_translation_segments(translated, replacements).strip()
        if restored == "":
            raise TranslationServiceError("Translation returned empty text.")
        return restored
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
    preprocessing_options = PreprocessingOptions.from_mapping(request.get("preprocessing"))
    summary_length = str(request.get("summary_length", request.get("length", "")) or "").strip().lower()
    summary_result = summarize_document(
        text=str(request.get("text", "")),
        file_path=str(request.get("file_path", "")),
        sentence_count=int(request.get("sentence_count", 5) or 5),
        preprocessing_options=preprocessing_options,
        summary_style=str(request.get("summary_style", "standard_paragraph") or "standard_paragraph"),
        summary_length=summary_length if summary_length else "balanced",
    )
    return {
        "title": summary_result.title or "Generated Summary",
        "sentences": summary_result.sentences,
        "overview": summary_result.overview,
        "plain_summary": summary_result.plain_summary,
        "overall_summary_bullets": summary_result.overall_summary_bullets,
        "raw_text": summary_result.raw_text,
        "cleaned_text": summary_result.cleaned_text,
        "sentence_count": summary_result.sentence_count,
        "summary_length": getattr(summary_result, "summary_length", summary_length or "balanced"),
        "preprocessing": summary_result.preprocessing,
        "readability": summary_result.readability,
        "summary_method": summary_result.summary_method,
        "keywords": summary_result.keywords,
        "important_terms": summary_result.important_terms,
        "article_type": summary_result.article_type,
        "key_points": summary_result.key_points,
        "conclusion": summary_result.conclusion,
        "structured_summary": summary_result.structured_summary,
        "source_metadata": summary_result.source_metadata,
        "paragraph_summaries": [
            {
                "paragraph_number": pa.paragraph_number,
                "section": pa.section,
                "purpose": pa.purpose,
                "main_idea": pa.main_idea,
                "supporting_details": pa.supporting_details,
                "keywords": pa.keywords,
                "summary": pa.summary,
            }
            for pa in summary_result.paragraph_summaries
        ],
        "excluded_sections": summary_result.excluded_sections,
    }


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
