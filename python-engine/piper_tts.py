"""
Piper text-to-speech helpers for the local Python worker.

This module keeps Piper isolated from the PHP layer while reusing a single
loaded voice instance and the same hash-based disk cache contract used by the
existing frontend.
"""

from __future__ import annotations

from dataclasses import dataclass
import io
from pathlib import Path
import hashlib
import os
import re
import subprocess
import sys
import tempfile
import threading
import wave

PROJECT_ROOT = Path(__file__).resolve().parent.parent
PYTHON_ENGINE_DIR = PROJECT_ROOT / "python-engine"


def resolve_project_path(path_value: str, default: Path) -> Path:
    normalized = (path_value or "").strip()
    if normalized == "":
        return default

    candidate = Path(normalized).expanduser()
    if candidate.is_absolute():
        return candidate

    return PROJECT_ROOT / candidate


def load_project_env() -> None:
    env_path = PROJECT_ROOT / ".env"
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
    from piper import PiperVoice

    PIPER_IMPORT_ERROR: Exception | None = None
except Exception as exc:  # pragma: no cover - depends on local Piper install
    PiperVoice = None
    PIPER_IMPORT_ERROR = exc


def env_flag(name: str, default: str = "False") -> bool:
    return os.getenv(name, default).strip().lower() in {"1", "true", "yes", "on"}


def env_value(*names: str, default: str = "") -> str:
    for name in names:
        value = (os.getenv(name) or "").strip()
        if value:
            return value
    return default


def normalize_language_code(language: str | None) -> str:
    normalized = (language or "en").strip().lower()
    if normalized in {"tl", "fil", "filipino", "tagalog"}:
        return "tl"
    return "en"


MAX_TTS_TEXT_LENGTH = int(
    env_value("TTS_MAX_CHARS", "PIPER_MAX_TEXT_LENGTH", "TTS_MAX_TEXT_LENGTH", "BARK_MAX_TEXT_LENGTH", default="3000")
)
MAX_TTS_CHUNK_CHARS = int(
    env_value("PIPER_MAX_CHUNK_CHARS", "TTS_MAX_CHUNK_CHARS", "BARK_MAX_CHUNK_CHARS", default="350")
)
CHUNK_GAP_MS = int(
    env_value("PIPER_GAP_MS", "TTS_GAP_MS", "BARK_GAP_MS", default="225")
)
VOICE_NAME_BY_LANGUAGE = {
    "en": env_value("PIPER_VOICE_EN", "PIPER_VOICE", default="en_US-lessac-medium"),
    "tl": env_value("PIPER_VOICE_FIL", "PIPER_VOICE_TL", "PIPER_VOICE", default="en_US-lessac-medium"),
}
USE_CUDA = env_flag("PIPER_USE_CUDA", "False")
DOWNLOAD_TIMEOUT_SECONDS = int(os.getenv("PIPER_DOWNLOAD_TIMEOUT_SECONDS", "0"))
CACHE_DIR = Path(
    resolve_project_path(
        env_value("AUDIO_OUTPUT_DIR", "PIPER_CACHE_DIR", "TTS_OUTPUT_DIR", "BARK_CACHE_DIR")
        or os.getenv("PIPER_CACHE_DIR")
        or os.getenv("BARK_CACHE_DIR"),
        PYTHON_ENGINE_DIR / "output" / "tts",
    )
)
FALLBACK_CACHE_DIR = Path(tempfile.gettempdir()) / "ai-summarizer-tts"
VOICE_DATA_DIR = resolve_project_path(
    os.getenv("PIPER_DATA_DIR") or "",
    PYTHON_ENGINE_DIR / "models" / "piper",
)
FILE_NAME_PATTERN = re.compile(r"^[a-f0-9]{64}\.wav$")
VOICE_NAME_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]*$")
PIPER_BIN = env_value("PIPER_BIN")
MODEL_ENV_BY_LANGUAGE = {
    "en": ("PIPER_MODEL_EN",),
    "tl": ("PIPER_MODEL_FIL", "PIPER_MODEL_TL"),
}
CONFIG_ENV_BY_LANGUAGE = {
    "en": ("PIPER_CONFIG_EN",),
    "tl": ("PIPER_CONFIG_FIL", "PIPER_CONFIG_TL"),
}

_load_lock = threading.Lock()
_generation_lock = threading.Lock()
_voices: dict[str, PiperVoice] = {}
_voice_error_messages: dict[str, str] = {}


class PiperTtsUnavailable(RuntimeError):
    """Raised when Piper cannot be imported, downloaded, or loaded."""


class PiperTtsValidationError(ValueError):
    """Raised when a text or filename request is invalid."""


@dataclass(frozen=True)
class PiperTtsResult:
    file_name: str
    cached: bool


def normalize_text(text: str) -> str:
    return re.sub(r"\s+", " ", (text or "")).strip()


def validate_text(text: str) -> str:
    normalized = normalize_text(text)

    if not normalized:
        raise PiperTtsValidationError("Text is required for audio generation.")

    if len(normalized) > MAX_TTS_TEXT_LENGTH:
        raise PiperTtsValidationError(
            f"Text exceeds the {MAX_TTS_TEXT_LENGTH}-character TTS limit."
        )

    return normalized


def ensure_cache_dir() -> None:
    global CACHE_DIR

    try:
        CACHE_DIR.mkdir(parents=True, exist_ok=True)
    except OSError:
        pass
    else:
        if os.access(CACHE_DIR, os.W_OK):
            return

    try:
        FALLBACK_CACHE_DIR.mkdir(parents=True, exist_ok=True)
    except OSError as exc:
        raise PiperTtsUnavailable("TTS cache directory is not writable.") from exc

    if os.access(FALLBACK_CACHE_DIR, os.W_OK):
        CACHE_DIR = FALLBACK_CACHE_DIR
        return

    raise PiperTtsUnavailable("TTS cache directory is not writable.")


def ensure_voice_dir() -> None:
    VOICE_DATA_DIR.mkdir(parents=True, exist_ok=True)


def split_long_segment(text: str) -> list[str]:
    words = text.split()
    if not words:
        return []

    chunks: list[str] = []
    current_words: list[str] = []

    for word in words:
        candidate = " ".join(current_words + [word]).strip()
        if current_words and len(candidate) > MAX_TTS_CHUNK_CHARS:
            chunks.append(" ".join(current_words))
            current_words = [word]
            continue

        if len(word) > MAX_TTS_CHUNK_CHARS:
            if current_words:
                chunks.append(" ".join(current_words))
                current_words = []

            start = 0
            while start < len(word):
                chunks.append(word[start:start + MAX_TTS_CHUNK_CHARS])
                start += MAX_TTS_CHUNK_CHARS
            continue

        current_words.append(word)

    if current_words:
        chunks.append(" ".join(current_words))

    return chunks


def split_text_for_piper(text: str) -> list[str]:
    normalized = validate_text(text)
    sentence_like_parts = [
        chunk.strip()
        for chunk in re.split(r"(?<=[.!?])\s+", normalized)
        if chunk.strip()
    ]

    if not sentence_like_parts:
        return split_long_segment(normalized)

    chunks: list[str] = []
    current = ""

    for part in sentence_like_parts:
        oversized_parts = (
            split_long_segment(part)
            if len(part) > MAX_TTS_CHUNK_CHARS
            else [part]
        )

        for segment in oversized_parts:
            candidate = segment if current == "" else f"{current} {segment}"
            if current and len(candidate) > MAX_TTS_CHUNK_CHARS:
                chunks.append(current)
                current = segment
            else:
                current = candidate

    if current:
        chunks.append(current)

    return chunks


def build_cache_file_name(text: str, language: str = "en") -> str:
    normalized = validate_text(text)
    normalized_language = normalize_language_code(language)
    digest_input = f"{normalized_language}\n{normalized}".encode("utf-8")
    return f"{hashlib.sha256(digest_input).hexdigest()}.wav"


def build_legacy_cache_file_name(text: str) -> str:
    normalized = validate_text(text)
    return f"{hashlib.sha256(normalized.encode('utf-8')).hexdigest()}.wav"


def validate_file_name(file_name: str) -> str:
    normalized = (file_name or "").strip()
    if not FILE_NAME_PATTERN.fullmatch(normalized):
        raise PiperTtsValidationError("Invalid audio file request.")

    return normalized


def validate_piper_bin() -> None:
    if PIPER_BIN == "":
        return

    if not resolve_project_path(PIPER_BIN, PYTHON_ENGINE_DIR / "piper.exe").is_file():
        raise PiperTtsUnavailable("Configured Piper executable was not found.")


def validate_voice_name(language: str = "en") -> str:
    normalized_language = normalize_language_code(language)
    normalized = VOICE_NAME_BY_LANGUAGE.get(normalized_language, VOICE_NAME_BY_LANGUAGE["en"]).strip()
    if not VOICE_NAME_PATTERN.fullmatch(normalized):
        raise PiperTtsUnavailable("Configured Piper voice name is invalid.")

    return normalized


def get_audio_path(file_name: str) -> Path:
    safe_file_name = validate_file_name(file_name)
    audio_path = CACHE_DIR / safe_file_name
    if not audio_path.exists():
        raise FileNotFoundError("Cached audio file was not found.")

    return audio_path


def get_configured_model_path(language: str) -> Path | None:
    normalized_language = normalize_language_code(language)
    for env_name in MODEL_ENV_BY_LANGUAGE.get(normalized_language, ()):
        configured_path = env_value(env_name)
        if not configured_path:
            continue

        model_path = resolve_project_path(configured_path, PYTHON_ENGINE_DIR / "models" / "piper")
        if not model_path.is_file():
            raise PiperTtsUnavailable(f"Configured Piper model for {normalized_language} was not found.")
        return model_path

    return None


def get_configured_config_path(language: str) -> Path | None:
    normalized_language = normalize_language_code(language)
    for env_name in CONFIG_ENV_BY_LANGUAGE.get(normalized_language, ()):
        configured_path = env_value(env_name)
        if not configured_path:
            continue

        config_path = resolve_project_path(configured_path, PYTHON_ENGINE_DIR / "models" / "piper")
        if not config_path.is_file():
            raise PiperTtsUnavailable(f"Configured Piper config for {normalized_language} was not found.")
        return config_path

    return None


def get_voice_config_path(model_path: Path, language: str = "en") -> Path:
    configured_config = get_configured_config_path(language)
    if configured_config is not None:
        return configured_config

    return Path(f"{model_path}.json")


def summarize_process_output(stdout: str | None, stderr: str | None) -> str:
    lines: list[str] = []
    for raw_value in (stdout, stderr):
        if raw_value:
            lines.extend(line.strip() for line in raw_value.splitlines() if line.strip())

    if not lines:
        return ""

    return " | ".join(lines[-8:])


def build_silence_frames(frame_rate: int, sample_width: int, channels: int) -> bytes:
    frame_count = max(1, int(frame_rate * (CHUNK_GAP_MS / 1000)))
    return b"\x00" * frame_count * sample_width * channels


def synthesize_chunk_to_frames(
    voice: PiperVoice,
    text: str,
) -> tuple[tuple[int, int, int, str, str], bytes]:
    buffer = io.BytesIO()
    with wave.open(buffer, "wb") as wav_file:
        voice.synthesize_wav(text, wav_file)

    buffer.seek(0)
    with wave.open(buffer, "rb") as generated_wav:
        params = (
            generated_wav.getnchannels(),
            generated_wav.getsampwidth(),
            generated_wav.getframerate(),
            generated_wav.getcomptype(),
            generated_wav.getcompname(),
        )
        frames = generated_wav.readframes(generated_wav.getnframes())

    return params, frames


def find_voice_model_path(language: str) -> Path | None:
    configured_model = get_configured_model_path(language)
    if configured_model is not None:
        return configured_model

    voice_name = validate_voice_name(language)
    direct_path = VOICE_DATA_DIR / f"{voice_name}.onnx"
    if direct_path.exists():
        return direct_path

    matches = sorted(VOICE_DATA_DIR.rglob(f"{voice_name}.onnx"))
    return matches[0] if matches else None


def ensure_voice_files(language: str) -> Path:
    ensure_voice_dir()

    configured_model = get_configured_model_path(language)
    if configured_model is not None:
        config_path = get_voice_config_path(configured_model, language)
        if not config_path.exists():
            raise PiperTtsUnavailable(f"Configured Piper config for {normalize_language_code(language)} was not found.")
        return configured_model

    model_path = find_voice_model_path(language)
    if model_path is not None and get_voice_config_path(model_path, language).exists():
        return model_path

    command = [
        sys.executable,
        "-m",
        "piper.download_voices",
        "--data-dir",
        str(VOICE_DATA_DIR),
        validate_voice_name(language),
    ]

    run_kwargs = {
        "capture_output": True,
        "text": True,
        "check": True,
    }
    if DOWNLOAD_TIMEOUT_SECONDS > 0:
        run_kwargs["timeout"] = DOWNLOAD_TIMEOUT_SECONDS

    try:
        subprocess.run(command, **run_kwargs)
    except subprocess.TimeoutExpired as exc:
        raise PiperTtsUnavailable(
            "Piper voice download timed out before the model finished downloading."
        ) from exc
    except subprocess.CalledProcessError as exc:
        extra_context = summarize_process_output(exc.stdout, exc.stderr)
        message = "Piper voice download failed."
        if extra_context:
            message = f"{message} {extra_context}"
        raise PiperTtsUnavailable(message) from exc
    except Exception as exc:  # pragma: no cover - runtime specific subprocess failure
        raise PiperTtsUnavailable(f"Piper voice download could not start: {exc}") from exc

    model_path = find_voice_model_path(language)
    if model_path is None or not get_voice_config_path(model_path, language).exists():
        raise PiperTtsUnavailable(
            "Piper voice download completed, but the required model files were not found."
        )

    return model_path


def prepare_piper_voice(language: str = "en") -> PiperVoice:
    normalized_language = normalize_language_code(language)
    ensure_cache_dir()
    validate_piper_bin()
    if normalized_language in _voices:
        return _voices[normalized_language]

    with _load_lock:
        if normalized_language in _voices:
            return _voices[normalized_language]

        if PIPER_IMPORT_ERROR is not None:
            _voice_error_messages[normalized_language] = str(PIPER_IMPORT_ERROR)
            raise PiperTtsUnavailable(
                "Piper is not installed or failed to import. Install the piper-tts package first."
            ) from PIPER_IMPORT_ERROR

        model_path = ensure_voice_files(normalized_language)
        try:
            get_voice_config_path(model_path, normalized_language)
            _voices[normalized_language] = PiperVoice.load(str(model_path), use_cuda=USE_CUDA)
        except Exception as exc:  # pragma: no cover - depends on local model/runtime state
            _voice_error_messages[normalized_language] = str(exc)
            raise PiperTtsUnavailable(f"Piper voice failed to load: {exc}") from exc

        _voice_error_messages[normalized_language] = ""
        return _voices[normalized_language]


def generate_cached_audio(text: str, language: str = "en") -> PiperTtsResult:
    normalized = validate_text(text)
    normalized_language = normalize_language_code(language)
    ensure_cache_dir()

    file_name = build_cache_file_name(normalized, normalized_language)
    audio_path = CACHE_DIR / file_name
    if audio_path.exists():
        return PiperTtsResult(file_name=file_name, cached=True)

    if normalized_language == "en":
        legacy_file_name = build_legacy_cache_file_name(normalized)
        legacy_audio_path = CACHE_DIR / legacy_file_name
        if legacy_audio_path.exists():
            return PiperTtsResult(file_name=legacy_file_name, cached=True)

    voice = prepare_piper_voice(normalized_language)
    chunks = split_text_for_piper(normalized)

    with _generation_lock:
        if audio_path.exists():
            return PiperTtsResult(file_name=file_name, cached=True)

        try:
            output_buffer = io.BytesIO()
            with wave.open(output_buffer, "wb") as wav_file:
                expected_params: tuple[int, int, int, str, str] | None = None
                silence_frames = b""

                for index, chunk in enumerate(chunks):
                    chunk_params, chunk_frames = synthesize_chunk_to_frames(voice, chunk)

                    if expected_params is None:
                        wav_file.setnchannels(chunk_params[0])
                        wav_file.setsampwidth(chunk_params[1])
                        wav_file.setframerate(chunk_params[2])
                        wav_file.setcomptype(chunk_params[3], chunk_params[4])
                        expected_params = chunk_params
                        silence_frames = build_silence_frames(
                            frame_rate=chunk_params[2],
                            sample_width=chunk_params[1],
                            channels=chunk_params[0],
                        )
                    elif chunk_params != expected_params:
                        raise PiperTtsUnavailable("Piper returned inconsistent audio settings between chunks.")

                    wav_file.writeframes(chunk_frames)
                    if index < len(chunks) - 1:
                        wav_file.writeframes(silence_frames)

            audio_path.write_bytes(output_buffer.getvalue())
        except PiperTtsUnavailable:
            raise
        except Exception as exc:  # pragma: no cover - runtime/model specific generation failures
            raise PiperTtsUnavailable(f"Piper failed to generate audio: {exc}") from exc

    return PiperTtsResult(file_name=file_name, cached=False)
