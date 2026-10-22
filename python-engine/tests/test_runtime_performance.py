"""Regression tests for bounded request caches and process-owned resources."""
from __future__ import annotations

from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import os
import subprocess
import sys
import threading
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from summarizer_core.runtime import BoundedCache, parse_text, request_cache, request_scope, stage
from summarizer_core import embeddings


class RequestRuntimeTests(unittest.TestCase):
    def test_cache_enforces_both_limits_and_lru(self):
        cache = BoundedCache(entries=2, units=5)
        cache.put("a", 1, 2)
        cache.put("b", 2, 2)
        self.assertEqual(cache.get("a"), 1)
        cache.put("c", 3, 2)
        self.assertIsNone(cache.get("b"))
        cache.put("oversized", 4, 6)
        self.assertIsNone(cache.get("oversized"))
        self.assertLessEqual(cache.used, 5)

    def test_sensitive_features_are_released_even_after_failure(self):
        with self.assertRaises(ValueError):
            with request_scope() as runtime:
                request_cache("private").put("sensitive document", [1])
                raise ValueError("request failed")
        self.assertEqual(runtime.caches, {})
        self.assertIsNone(request_cache("private"))
        with request_scope():
            self.assertIsNone(request_cache("private").get("sensitive document"))

    def test_concurrent_request_cache_isolation(self):
        barrier = threading.Barrier(2)
        def request(value):
            with request_scope():
                request_cache("same").put("same sentence", value)
                barrier.wait(timeout=5)
                return request_cache("same").get("same sentence")
        with ThreadPoolExecutor(2) as executor:
            self.assertEqual(list(executor.map(request, [1, 2])), [1, 2])

    def test_exact_parses_are_shared_but_different_texts_and_requests_are_not(self):
        nlp = Mock(side_effect=lambda text: [text])
        with request_scope():
            first = parse_text(nlp, "The trial found no improvement.")
            self.assertIs(parse_text(nlp, "The trial found no improvement."), first)
            parse_text(nlp, "The trial found improvement.")
        with request_scope():
            parse_text(nlp, "The trial found no improvement.")
        self.assertEqual(nlp.call_count, 3)

    def test_nested_timings_exclude_child_duration(self):
        with request_scope(profile=True) as runtime:
            with patch("summarizer_core.runtime.time.perf_counter", side_effect=[1, 2, 5, 8]):
                with stage("outer"):
                    with stage("inner"):
                        pass
        self.assertEqual(runtime.stages["outer"]["inclusive_seconds"], 7)
        self.assertEqual(runtime.stages["outer"]["exclusive_seconds"], 4)
        self.assertEqual(runtime.stages["inner"]["exclusive_seconds"], 3)


class EmbeddingReuseTests(unittest.TestCase):
    def setUp(self):
        self.model_path = str(Path(__file__).parent.resolve())
        self.encoder = Mock()
        self.encoder.encode.side_effect = lambda texts, **kwargs: np.array([[len(text), 1] for text in texts], dtype=np.float32)
        self.patches = [
            patch.dict(os.environ, {"SUMMARIZER_LOCAL_EMBEDDING_MODEL": self.model_path}),
            patch.object(embeddings, "_CACHED_MODEL_PATH", self.model_path),
            patch.object(embeddings, "_CACHED_ENCODER", self.encoder),
        ]
        for item in self.patches:
            item.start()
            self.addCleanup(item.stop)

    def test_batch_deduplicates_and_reuses_only_within_request(self):
        with request_scope():
            first = embeddings.embed_texts(["alpha", "beta", "alpha"])
            second = embeddings.embed_texts(["beta", "new sentence"])
            np.testing.assert_array_equal(first.vectors[1], second.vectors[0])
        with request_scope():
            embeddings.embed_texts(["alpha"])
        self.assertEqual([call.args[0] for call in self.encoder.encode.call_args_list],
                         [["alpha", "beta"], ["new sentence"], ["alpha"]])

    def test_more_than_old_cache_limit_stays_on_dense_backend(self):
        texts = [f"unique source sentence {index}" for index in range(5001)]
        with request_scope() as runtime:
            result = embeddings.embed_texts(texts)
            self.assertEqual(result.backend, "sentence_transformers_local")
            self.assertEqual(result.vectors.shape, (5001, 2))
            self.assertLessEqual(len(runtime.caches["embeddings"].values), 2048)
        np.testing.assert_array_equal(result.vectors[-1], [len(texts[-1]), 1])

    def test_model_switch_cannot_return_old_vectors(self):
        encoder2 = Mock()
        encoder2.encode.return_value = np.array([[99, 1]], dtype=np.float32)
        factory = Mock(return_value=encoder2)
        with request_scope():
            embeddings.embed_texts(["same text"])
            with patch.dict(os.environ, {"SUMMARIZER_LOCAL_EMBEDDING_MODEL": str(Path(self.model_path).parent)}), \
                 patch.dict(sys.modules, {"sentence_transformers": SimpleNamespace(SentenceTransformer=factory)}):
                changed = embeddings.embed_texts(["same text"])
            self.assertEqual(changed.vectors[0, 0], 99)
        factory.assert_called_once()


class ResourceInitializationTests(unittest.TestCase):
    def test_unavailable_grammar_resource_is_not_reinitialized_per_request(self):
        from summarizer_core import grammar_check
        unavailable = grammar_check.LocalGrammarChecker(unavailable_reason="not installed")
        with patch.object(grammar_check, "_GLOBAL_CHECKER", None), \
             patch.object(grammar_check.LocalGrammarChecker, "from_environment", return_value=unavailable) as initialize, \
             patch("atexit.register"):
            self.assertIs(grammar_check.get_grammar_checker(), unavailable)
            self.assertIs(grammar_check.get_grammar_checker(), unavailable)
        initialize.assert_called_once()

    def test_cli_health_does_not_import_unrelated_heavy_libraries(self):
        engine = str(Path(__file__).resolve().parents[1])
        command = "import sys; sys.path.insert(0, sys.argv[1]); import local_cli; local_cli.handle_self_test({}); assert not {'torch', 'spacy', 'sentence_transformers', 'piper', 'deep_translator'} & sys.modules.keys()"
        result = subprocess.run([sys.executable, "-B", "-c", command, engine], capture_output=True, text=True, timeout=15)
        self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == "__main__":
    unittest.main()
