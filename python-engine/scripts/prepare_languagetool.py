"""Provision a local LanguageTool server once, outside request handling.

The download fetches only the LanguageTool program archive (checksum-verified by
language_tool_python). Document text is never sent anywhere: at request time the
engine talks only to a Java process it starts on localhost, and it never
triggers a download (see summarizer_core/grammar_check.py).

LanguageTool 6.6+ requires Java 17 or newer. Pass --java-home when the first
`java` on PATH is older (for example Java 8).
"""

from __future__ import annotations

import argparse
import os
from pathlib import Path

DEFAULT_VERSION = "6.8"
DEFAULT_OUTPUT = Path(__file__).resolve().parents[2] / "storage" / "models" / "languagetool"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version", default=DEFAULT_VERSION)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--java-home", type=Path, default=None)
    args = parser.parse_args()

    if args.java_home is not None:
        java_bin = args.java_home / "bin"
        if not (java_bin / ("java.exe" if os.name == "nt" else "java")).is_file():
            parser.error(f"No java executable under {java_bin}")
        os.environ["PATH"] = str(java_bin) + os.pathsep + os.environ.get("PATH", "")

    args.output.mkdir(parents=True, exist_ok=True)
    os.environ["LTP_PATH"] = str(args.output)
    os.environ.pop("LTP_JAR_DIR_PATH", None)  # would short-circuit the download

    from language_tool_python.download_lt import LocalLanguageTool

    local_lt = LocalLanguageTool.from_version_name(args.version)
    local_lt.download()
    jar_dir = local_lt.get_directory_path()
    print(f"Prepared LanguageTool {args.version} at {jar_dir}")
    print(f"Set SUMMARIZER_LANGUAGETOOL_DIR={jar_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
