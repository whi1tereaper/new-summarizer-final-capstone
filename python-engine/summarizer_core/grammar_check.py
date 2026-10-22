"""Local grammar diagnostics using a local LanguageTool process and spaCy fallback.

Strict offline execution:
1. Document text is never sent to any remote API or third-party server.
2. Only a local Java LanguageTool process running on localhost is utilized.
3. If LanguageTool is unavailable, unconfigured, or fails to start, the checker
   gracefully falls back to spaCy dependency parse diagnostics.
"""

from __future__ import annotations

import logging
import os
import shutil
from pathlib import Path
from typing import Any
from .runtime import parse_text, request_cache, timed

LOGGER = logging.getLogger(__name__)

_DEFAULT_JAVA_HOME_CANDIDATES = (
    r"C:\Program Files\Java\jdk-25",
    r"C:\Program Files\Java\jdk-21",
    r"C:\Program Files\Java\jdk-17",
    r"C:\Program Files\Eclipse Adoptium\jdk-21",
    r"C:\Program Files\Eclipse Adoptium\jdk-17",
)


def _ensure_java_compatibility() -> None:
    """Prepend a modern Java (>=17) directory to PATH if available."""
    current_java = shutil.which("java")
    # If Java is already on PATH, check if it's modern enough or if we should prefer JDK 17+
    for candidate in _DEFAULT_JAVA_HOME_CANDIDATES:
        bin_dir = Path(candidate) / "bin"
        java_exe = bin_dir / ("java.exe" if os.name == "nt" else "java")
        if java_exe.is_file():
            # Prepend to PATH so language_tool_python picks it up
            current_path = os.environ.get("PATH", "")
            if str(bin_dir) not in current_path:
                os.environ["PATH"] = str(bin_dir) + os.pathsep + current_path
            break


class LocalGrammarChecker:
    """Offline grammar and syntactic diagnostics manager."""

    def __init__(self, tool: Any = None, unavailable_reason: str = "") -> None:
        self._tool = tool
        self._unavailable_reason = unavailable_reason

    @property
    def available(self) -> bool:
        return self._tool is not None

    @property
    def unavailable_reason(self) -> str:
        return self._unavailable_reason

    @classmethod
    def from_environment(cls) -> LocalGrammarChecker:
        """Instantiate a local checker using environment paths or local caches."""
        _ensure_java_compatibility()

        # Check explicit environment variable first
        lt_dir = os.environ.get("SUMMARIZER_LANGUAGETOOL_DIR", "").strip()
        candidates: list[Path] = []
        if lt_dir:
            candidates.append(Path(lt_dir))

        # Check common local provisioning targets
        local_app_data = os.environ.get("LOCALAPPDATA", "")
        if local_app_data:
            candidates.append(Path(local_app_data) / "ai-summarizer" / "languagetool")

        repo_storage = Path(__file__).resolve().parents[2] / "storage" / "models" / "languagetool"
        candidates.append(repo_storage)

        # Look for existing LanguageTool directory containing languagetool-server.jar
        resolved_jar_dir: Path | None = None
        for base in candidates:
            if not base.exists():
                continue
            # Check if base itself is the LanguageTool directory or contains LanguageTool-*
            if (base / "languagetool-server.jar").is_file() or (base / "languagetool.jar").is_file():
                resolved_jar_dir = base
                break
            for sub in base.glob("LanguageTool*"):
                if sub.is_dir() and (
                    (sub / "languagetool-server.jar").is_file() or (sub / "languagetool.jar").is_file()
                ):
                    resolved_jar_dir = sub
                    break
            if resolved_jar_dir:
                break

        if resolved_jar_dir is None:
            reason = "local_languagetool_not_prepared"
            LOGGER.info("Local LanguageTool is not provisioned; using spaCy fallback")
            return cls(tool=None, unavailable_reason=reason)

        try:
            import language_tool_python

            os.environ["LTP_PATH"] = str(resolved_jar_dir.parent)
            os.environ["LTP_JAR_DIR_PATH"] = str(resolved_jar_dir)

            # Strictly local: never specify remote_server
            tool = language_tool_python.LanguageTool("en-US")
            return cls(tool=tool)
        except Exception as exc:
            LOGGER.info("Local LanguageTool process not active: %s; using spaCy fallback", exc)
            return cls(tool=None, unavailable_reason=f"{type(exc).__name__}:{exc}")

    @timed("grammar_validation")
    def check(self, text: str) -> list[dict[str, Any]]:
        """Run diagnostic checks on sentence; never sends text over network."""
        clean = text.strip()
        if not clean:
            return []
        cache = request_cache("grammar_diagnostics", entries=512)
        key = (id(self), clean)
        cached = cache.get(key) if cache is not None else None
        if cached is not None:
            return [dict(item) for item in cached]

        if self._tool is not None:
            try:
                matches = self._tool.check(clean)
                issues: list[dict[str, Any]] = []
                for match in matches:
                    issues.append({
                        "rule_id": getattr(match, "ruleId", "RULE"),
                        "message": getattr(match, "message", ""),
                        "category": getattr(match, "category", "GRAMMAR"),
                        "offset": getattr(match, "offset", 0),
                        "error_length": getattr(match, "errorLength", 0),
                    })
                if cache is not None:
                    cache.put(key, [dict(item) for item in issues], len(clean) + len(issues) * 512)
                return issues
            except Exception as exc:
                LOGGER.warning("LanguageTool check failed: %s; falling back to spaCy", exc)

        # Fallback: spaCy dependency check
        from .simplification import get_nlp

        nlp = get_nlp()
        if nlp is not None:
            try:
                doc = parse_text(nlp, clean)
                issues = []
                has_verb = any(t.dep_ == "ROOT" and t.pos_ in {"VERB", "AUX"} for t in doc)
                has_subj = any(
                    t.dep_ in {"nsubj", "nsubjpass", "csubj", "csubjpass", "expl"} for t in doc
                )
                if not has_verb:
                    issues.append({"rule_id": "NO_FINITE_VERB", "message": "Sentence lacks a finite predicate verb", "category": "SYNTAX"})
                if not has_subj and not any(t.dep_ == "ROOT" and t.tag_ == "VB" for t in doc):
                    issues.append({"rule_id": "NO_SUBJECT", "message": "Sentence lacks a grammatical subject", "category": "SYNTAX"})
                if cache is not None:
                    cache.put(key, [dict(item) for item in issues], len(clean) + len(issues) * 512)
                return issues
            except Exception:
                pass
        return []

    def close(self) -> None:
        """Safely shut down the local server process if running."""
        if self._tool is not None:
            try:
                self._tool.close()
            except Exception:
                pass
            self._tool = None


_GLOBAL_CHECKER: LocalGrammarChecker | None = None


@timed("grammar_initialization")
def get_grammar_checker() -> LocalGrammarChecker:
    """Return the cached singleton grammar checker for the current process."""
    global _GLOBAL_CHECKER
    if _GLOBAL_CHECKER is None:
        _GLOBAL_CHECKER = LocalGrammarChecker.from_environment()
        import atexit
        atexit.register(close_grammar_checker)
    return _GLOBAL_CHECKER


def close_grammar_checker() -> None:
    """Shut down the cached singleton grammar checker process."""
    global _GLOBAL_CHECKER
    if _GLOBAL_CHECKER is not None:
        _GLOBAL_CHECKER.close()
        _GLOBAL_CHECKER = None


__all__ = ["LocalGrammarChecker", "get_grammar_checker", "close_grammar_checker"]
