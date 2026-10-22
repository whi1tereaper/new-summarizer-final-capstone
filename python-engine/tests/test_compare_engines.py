"""Tests for reproducible corpus composition in the comparison harness."""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

ENGINE_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ENGINE_ROOT))

from evaluation.compare_engines import _load_corpus


class CompareEngineCorpusTests(unittest.TestCase):
    def test_article_pilot_includes_only_the_requested_source_documents(self) -> None:
        corpus_path = ENGINE_ROOT / "evaluation" / "corpus" / "independent_article_pilot_20261013.json"
        documents = _load_corpus(corpus_path)

        self.assertEqual(len(documents), 3)
        self.assertEqual({document["category"] for document in documents}, {"news"})
        self.assertEqual(
            {document["id"] for document in documents},
            {
                "NASA-CRS35-HEALTH-RESEARCH",
                "NASA-EARTH-OBSERVATORY-THAILAND-FLOODS",
                "NASA-RELEASE-25-002",
            },
        )
        self.assertTrue(all(len(document["source_sha256"]) == 64 for document in documents))

    def test_include_rejects_missing_document_ids(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            (root / "included.json").write_text(
                json.dumps({"documents": [{"id": "real", "source_text": "A full source sentence."}]}),
                encoding="utf-8",
            )
            manifest = root / "manifest.json"
            manifest.write_text(
                json.dumps({
                    "include_corpora": [{
                        "path": "included.json",
                        "ids": ["missing"],
                    }]
                }),
                encoding="utf-8",
            )

            with self.assertRaisesRegex(ValueError, "Included documents not found"):
                _load_corpus(manifest)


if __name__ == "__main__":
    unittest.main()
