import sys
import tempfile
import threading
import time
import unittest
from datetime import datetime, timedelta, timezone
from email.utils import format_datetime
from pathlib import Path
from unittest.mock import patch

ENGINE_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ENGINE_ROOT))

from failure_contract import failure_response
import local_cli


class _RateLimitError(RuntimeError):
    def __init__(self, *, retry_after=None):
        super().__init__("429 Too Many Requests")
        self.response = type("Response", (), {"headers": {"Retry-After": str(retry_after) if retry_after is not None else ""}})()


class _FakeGoogleTranslator:
    __module__ = "deep_translator.google_translator"
    def __init__(self, *args, **kwargs):
        pass

    def translate(self, text):
        return "Mga halimbawa ng teksto sa Filipino."


class _FakeMemoryTranslator:
    __module__ = "deep_translator.mymemory_translator"
    def __init__(self, *args, **kwargs):
        pass

    def translate(self, text):
        if str(text).lower().startswith("hello"):
            raise _RateLimitError(retry_after=3)
        return "Salamat sa pagtingin."


class TranslationResilienceTests(unittest.TestCase):
    def setUp(self):
        self.original_cooldown_file = local_cli._PROVIDER_COOLDOWN_FILE
        self.cooldown_tempdir = tempfile.TemporaryDirectory(prefix="light-translation-resilience-")
        local_cli._PROVIDER_COOLDOWN_FILE = Path(self.cooldown_tempdir.name) / "cooldown.json"
        local_cli._PROVIDER_COOLDOWN_STATE.clear()
        local_cli._PROVIDER_REQUEST_HISTORY.clear()

    def tearDown(self):
        local_cli._PROVIDER_COOLDOWN_FILE = self.original_cooldown_file
        local_cli._PROVIDER_COOLDOWN_STATE.clear()
        local_cli._PROVIDER_REQUEST_HISTORY.clear()
        self.cooldown_tempdir.cleanup()

    def test_rate_limited_memory_falls_back_to_google(self):
        with patch.object(local_cli, "_resolve_provider_name", side_effect=lambda translator: "mymemory" if isinstance(translator, _FakeMemoryTranslator) else "google"):
            with patch("deep_translator.GoogleTranslator", _FakeGoogleTranslator), patch("deep_translator.MyMemoryTranslator", _FakeMemoryTranslator):
                result = local_cli.translate_text("Hello world.", "tl")
                self.assertIn("Filipino", result)
                self.assertTrue(local_cli._provider_is_cooling_down("mymemory"))

    def test_both_providers_in_cooldown_fail_fast(self):
        local_cli._apply_provider_cooldown("google", 30)
        local_cli._apply_provider_cooldown("mymemory", 30)
        with self.assertRaises(local_cli.TranslationServiceError) as raised:
            local_cli.translate_text("Hello world.", "tl")
        self.assertEqual(raised.exception.code, "TRANSLATION_RATE_LIMITED")

    def test_retry_after_is_normalized_and_safe(self):
        value = local_cli._normalize_retry_after("  5 ")
        self.assertEqual(value, 5.0)
        value = local_cli._normalize_retry_after("not-a-date")
        self.assertIsNone(value)
        self.assertIsNone(local_cli._normalize_retry_after("-5"))
        self.assertIsNone(local_cli._normalize_retry_after("1e999"))
        self.assertIsNone(local_cli._normalize_retry_after(10 ** 1000))
        http_date = format_datetime(datetime.now(timezone.utc) + timedelta(seconds=20), usegmt=True)
        self.assertGreaterEqual(local_cli._normalize_retry_after(http_date), 18)

    def test_rate_limit_code_and_retry_interval_survive_worker_contract(self):
        with self.assertRaises(local_cli.TranslationServiceError) as raised:
            local_cli._classify_translation_exception("mymemory", _RateLimitError(retry_after=3))

        response = failure_response(raised.exception)
        failure = response["failure"]
        self.assertEqual(failure["code"], "TRANSLATION_RATE_LIMITED")
        self.assertGreater(failure["retry_after_seconds"], 0)
        self.assertNotIn("429 Too Many Requests", str(response))

    def test_provider_request_lock_wait_is_bounded_and_falls_back(self):
        lock = local_cli._PROVIDER_REQUEST_LOCKS["mymemory"]
        self.assertTrue(lock.acquire(blocking=False))
        try:
            with patch.object(local_cli, "_PROVIDER_REQUEST_LOCK_TIMEOUT_SECONDS", 0.05), \
                    patch.object(local_cli, "_resolve_provider_name", side_effect=lambda translator: "mymemory" if isinstance(translator, _FakeMemoryTranslator) else "google"), \
                    patch("deep_translator.GoogleTranslator", _FakeGoogleTranslator), \
                    patch("deep_translator.MyMemoryTranslator", _FakeMemoryTranslator):
                started = time.monotonic()
                result = local_cli.translate_text("Hello world.", "tl")
                elapsed = time.monotonic() - started
        finally:
            lock.release()
        self.assertIn("Filipino", result)
        self.assertLess(elapsed, 1)

    def test_invalid_response_classifies_without_crashing(self):
        with self.assertRaises(local_cli.TranslationServiceError) as raised:
            local_cli._classify_translation_exception("google", ValueError("JSON decode error"))
        self.assertEqual(raised.exception.code, "TRANSLATION_INVALID_RESPONSE")

    def test_concurrent_requests_do_not_bypass_cooldown(self):
        local_cli._apply_provider_cooldown("mymemory", 1.5)
        results = []
        barrier = threading.Barrier(2)

        def worker():
            barrier.wait()
            try:
                local_cli._provider_is_cooling_down("mymemory")
                results.append(True)
            except Exception as exc:  # pragma: no cover
                results.append(exc)

        threads = [threading.Thread(target=worker) for _ in range(2)]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join()
        self.assertEqual(len(results), 2)
        self.assertTrue(all(result is True for result in results))


if __name__ == "__main__":
    unittest.main()
