import http.cookiejar
import json
import os
from html.parser import HTMLParser
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlparse
from urllib.request import Request, build_opener, HTTPCookieProcessor, urlopen

ENGINE_ROOT = Path(__file__).resolve().parents[1]
REPOSITORY_ROOT = ENGINE_ROOT.parent
PUBLIC_ROOT = REPOSITORY_ROOT / "app" / "public"
PHP_BINARY = os.environ.get("LIGHT_TEST_PHP_BIN") or shutil.which("php")
RESULT_PAGE_URL = os.environ.get("LIGHT_TRANSLATION_TEST_RESULT_URL", "")


class _ResultPageParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.summary_page = None
        self.in_title = False
        self.title = ""

    def handle_starttag(self, tag, attrs):
        attributes = dict(attrs)
        if attributes.get("id") == "summary-page":
            self.summary_page = attributes
        if tag == "title":
            self.in_title = True

    def handle_endtag(self, tag):
        if tag == "title":
            self.in_title = False

    def handle_data(self, data):
        if self.in_title:
            self.title += data.strip()


@unittest.skipUnless(
    PHP_BINARY and RESULT_PAGE_URL,
    "Set LIGHT_TEST_PHP_BIN and LIGHT_TRANSLATION_TEST_RESULT_URL for HTTP acceptance.",
)
class TranslationHttpAcceptanceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tempdir = tempfile.TemporaryDirectory(prefix="light-translation-http-")
        cls.temp_root = Path(cls.tempdir.name)
        cls.mode_file = cls.temp_root / "provider-mode"
        cls.calls_file = cls.temp_root / "provider-calls"
        cls.cooldown_file = cls.temp_root / "cooldown.json"
        cls.cooldown_file.write_text("{}", encoding="utf-8")

        cls.worker_port = cls._free_port()
        cls.php_port = cls._free_port()
        cls._write_mock_provider_bootstrap()
        cls._start_worker()
        cls._start_php_server()

    @classmethod
    def tearDownClass(cls):
        if not hasattr(cls, "tempdir"):
            return
        cls._stop_php_server()
        cls._stop_worker()
        cls.tempdir.cleanup()

    @staticmethod
    def _free_port():
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            return sock.getsockname()[1]

    @classmethod
    def _write_mock_provider_bootstrap(cls):
        support = cls.temp_root / "python-support"
        support.mkdir()
        (support / "sitecustomize.py").write_text(
            """
import os
import sys
import time
import types
from pathlib import Path

sys.path.insert(0, os.environ["LIGHT_TEST_ENGINE_ROOT"])
import local_cli
local_cli._PROVIDER_COOLDOWN_FILE = Path(os.environ["LIGHT_TEST_COOLDOWN_FILE"])

class MockRateLimit(RuntimeError):
    def __init__(self, retry_after="45"):
        super().__init__("429 Too Many Requests")
        headers = {} if retry_after is None else {"Retry-After": retry_after}
        self.response = types.SimpleNamespace(headers=headers)

def mode():
    return Path(os.environ["LIGHT_TEST_PROVIDER_MODE"]).read_text(encoding="utf-8").strip()

def fail_for_mode(current_mode):
    if current_mode == "rate_limit":
        raise MockRateLimit()
    if current_mode == "rate_limit_missing":
        raise MockRateLimit(None)
    if current_mode == "rate_limit_invalid":
        raise MockRateLimit("not-a-valid-retry-time")
    if current_mode == "timeout":
        from requests.exceptions import Timeout
        raise Timeout("private provider timeout detail")
    if current_mode == "network":
        from requests.exceptions import ConnectionError
        raise ConnectionError("private network detail")
    if current_mode == "invalid_response":
        raise ValueError("private malformed JSON response")

def record(provider):
    with open(os.environ["LIGHT_TEST_PROVIDER_CALLS"], "a", encoding="utf-8") as stream:
        stream.write(provider + "\\n")

class MockGoogleTranslator:
    __module__ = "deep_translator.google_translator"
    def __init__(self, *args, **kwargs):
        if mode() == "worker_failure":
            raise RuntimeError("private worker exception")
    def translate(self, text):
        record("google")
        fail_for_mode(mode())
        return "Mock Filipino translation."

class MockMyMemoryTranslator:
    __module__ = "deep_translator.mymemory_translator"
    def __init__(self, *args, **kwargs):
        pass
    def translate(self, text):
        record("mymemory")
        fail_for_mode(mode())
        return "Mock Filipino translation."

sys.modules["deep_translator"] = types.SimpleNamespace(
    GoogleTranslator=MockGoogleTranslator,
    MyMemoryTranslator=MockMyMemoryTranslator,
)
""".lstrip(),
            encoding="utf-8",
        )
        cls.php_prepend = cls.temp_root / "php_prepend.php"
        cls.php_prepend.write_text(
            "<?php\n"
            + "require_once "
            + json.dumps(str(REPOSITORY_ROOT / "app" / "src" / "Support" / "Env.php"))
            + ";\n"
            + "\\App\\Src\\Support\\Env::load();\n"
            + f"putenv('PYTHON_WORKER_PORT={cls.worker_port}');\n",
            encoding="utf-8",
        )

    @classmethod
    def _start_worker(cls):
        env = {
            **os.environ,
            "PYTHONPATH": str(cls.temp_root / "python-support")
            + os.pathsep
            + str(ENGINE_ROOT),
            "PYTHONDONTWRITEBYTECODE": "1",
            "LIGHT_TEST_ENGINE_ROOT": str(ENGINE_ROOT),
            "LIGHT_TEST_COOLDOWN_FILE": str(cls.cooldown_file),
            "LIGHT_TEST_PROVIDER_MODE": str(cls.mode_file),
            "LIGHT_TEST_PROVIDER_CALLS": str(cls.calls_file),
            "PYTHON_WORKER_PORT": str(cls.worker_port),
        }
        cls.worker_log = open(cls.temp_root / "worker.log", "w", encoding="utf-8")
        cls.worker = subprocess.Popen(
            [
                sys.executable,
                str(ENGINE_ROOT / "worker_daemon.py"),
                "--port",
                str(cls.worker_port),
                "--deadline",
                "60",
            ],
            cwd=ENGINE_ROOT,
            env=env,
            stdout=cls.worker_log,
            stderr=subprocess.STDOUT,
        )
        cls._wait_for_worker()

    @classmethod
    def _wait_for_worker(cls):
        deadline = time.monotonic() + 30
        while time.monotonic() < deadline:
            if cls.worker.poll() is not None:
                raise RuntimeError("Mocked Python worker exited during startup.")
            try:
                with socket.create_connection(("127.0.0.1", cls.worker_port), timeout=0.2):
                    return
            except OSError:
                time.sleep(0.1)
        raise RuntimeError("Mocked Python worker did not start.")

    @classmethod
    def _start_php_server(cls):
        cls.php_log = open(cls.temp_root / "php.log", "w", encoding="utf-8")
        cls.php_server = subprocess.Popen(
            [
                PHP_BINARY,
                "-d",
                f'auto_prepend_file="{cls.php_prepend}"',
                "-S",
                f"127.0.0.1:{cls.php_port}",
                "-t",
                str(PUBLIC_ROOT),
            ],
            cwd=REPOSITORY_ROOT,
            env={**os.environ, "PYTHON_WORKER_PORT": str(cls.worker_port)},
            stdout=cls.php_log,
            stderr=subprocess.STDOUT,
        )
        deadline = time.monotonic() + 20
        while time.monotonic() < deadline:
            if cls.php_server.poll() is not None:
                raise RuntimeError("PHP test server exited during startup.")
            try:
                with socket.create_connection(("127.0.0.1", cls.php_port), timeout=0.2):
                    return
            except OSError:
                time.sleep(0.1)
        raise RuntimeError("PHP test server did not start.")

    @classmethod
    def _stop_php_server(cls):
        process = getattr(cls, "php_server", None)
        if process is not None and process.poll() is None:
            process.terminate()
            process.wait(timeout=5)
        log = getattr(cls, "php_log", None)
        if log:
            log.close()

    @classmethod
    def _stop_worker(cls):
        process = getattr(cls, "worker", None)
        if process is not None and process.poll() is None:
            try:
                cls._worker_request("/shutdown", {})
                process.wait(timeout=10)
            except (OSError, URLError, subprocess.TimeoutExpired):
                process.terminate()
                process.wait(timeout=5)
        log = getattr(cls, "worker_log", None)
        if log:
            log.close()

    @classmethod
    def _worker_request(cls, path, payload):
        token = cls._worker_token()
        request = Request(
            f"http://127.0.0.1:{cls.worker_port}{path}",
            data=json.dumps(payload).encode("utf-8"),
            headers={"Content-Type": "application/json", "X-Worker-Token": token},
        )
        with urlopen(request, timeout=3) as response:
            return response.status, response.read()

    @staticmethod
    def _worker_token():
        dotenv = REPOSITORY_ROOT / ".env"
        if dotenv.is_file():
            for line in dotenv.read_text(encoding="utf-8").splitlines():
                if line.strip().startswith("PYTHON_WORKER_SECRET="):
                    value = line.split("=", 1)[1].strip()
                    if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
                        return value[1:-1]
                    if value:
                        return value
        token_file = REPOSITORY_ROOT / "storage" / "worker.token"
        return token_file.read_text(encoding="utf-8").strip()

    def setUp(self):
        self.mode_file.write_text("success", encoding="utf-8")
        self.calls_file.write_text("", encoding="utf-8")
        self.cooldown_file.write_text("{}", encoding="utf-8")
        self.opener = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
        source_url = urlparse(RESULT_PAGE_URL)
        query = parse_qs(source_url.query)
        self.assertIn("id", query)
        self.assertIn("share", query)
        self.result_url = (
            f"http://127.0.0.1:{self.php_port}/result.php"
            f"?id={query['id'][0]}&share={query['share'][0]}"
        )

    def _get_result_page(self):
        with self.opener.open(self.result_url, timeout=20) as response:
            status = response.status
            page_url = response.geturl()
            html = response.read().decode("utf-8")
        parser = _ResultPageParser()
        parser.feed(html)
        self.php_log.flush()
        log_lines = (self.temp_root / "php.log").read_text(
            encoding="utf-8", errors="replace"
        ).splitlines()
        php_diagnostics = "\n".join(log_lines[-6:])
        self.assertIsNotNone(
            parser.summary_page,
            f"Results page did not render its summary root (HTTP {status}, "
            f"title={parser.title!r}, html_length={len(html)}, "
            f"URL path={urlparse(page_url).path!r}, PHP diagnostics={php_diagnostics!r}).",
        )
        attributes = parser.summary_page
        csrf = attributes.get("data-csrf-token", "")
        source = attributes.get("data-summary-text", "")
        self.assertTrue(csrf)
        self.assertTrue(source)
        return csrf, source

    def _post_translation(self, csrf, source):
        request = Request(
            f"http://127.0.0.1:{self.php_port}/translate_proxy.php",
            data=json.dumps(
                {"csrf_token": csrf, "text": source, "target_lang": "tl"}
            ).encode("utf-8"),
            headers={"Content-Type": "application/json"},
        )
        try:
            with self.opener.open(request, timeout=30) as response:
                return response.status, response.headers, json.loads(response.read())
        except HTTPError as error:
            return error.code, error.headers, json.loads(error.read())

    def test_results_page_php_worker_translation_success_preserves_source(self):
        csrf, original_summary = self._get_result_page()
        status, headers, response = self._post_translation(csrf, original_summary)
        self.assertEqual(status, 200)
        self.assertEqual(response["target_lang"], "tl")
        self.assertTrue(response["translated"].startswith("Mock Filipino translation."))
        self.assertFalse(response["used_fallback"])
        provider_calls = self.calls_file.read_text(encoding="utf-8").splitlines()
        self.assertTrue(provider_calls)
        self.assertTrue(all(provider == "mymemory" for provider in provider_calls))
        self.assertEqual(headers.get("Cache-Control"), "no-store")
        _, summary_after = self._get_result_page()
        self.assertEqual(summary_after, original_summary)

    def test_rate_limit_and_immediate_retry_are_safe_and_fail_fast(self):
        self.mode_file.write_text("rate_limit", encoding="utf-8")
        csrf, original_summary = self._get_result_page()
        status, headers, response = self._post_translation(csrf, original_summary)
        self.assertEqual(status, 503)
        self.assertEqual(response["code"], "TRANSLATION_RATE_LIMITED")
        self.assertTrue(response["retryable"])
        self.assertEqual(
            response["error"],
            "Filipino translation is temporarily unavailable because the translation service is busy. Your original summary is safe. Please try again later.",
        )
        self.assertEqual(headers.get("Retry-After"), "45")
        self.assertEqual(headers.get("Cache-Control"), "no-store")
        self.assertEqual(response["retry_after_seconds"], 45)
        initial_calls = self.calls_file.read_text(encoding="utf-8").splitlines()
        self.assertEqual(initial_calls, ["mymemory", "google"])

        retry_status, retry_headers, retry_response = self._post_translation(csrf, original_summary)
        self.assertEqual(retry_status, 503)
        self.assertEqual(retry_response["code"], "TRANSLATION_RATE_LIMITED")
        self.assertGreater(int(retry_headers["Retry-After"]), 0)
        self.assertEqual(retry_response["error"], response["error"])
        self.assertEqual(self.calls_file.read_text(encoding="utf-8").splitlines(), initial_calls)
        _, summary_after = self._get_result_page()
        self.assertEqual(summary_after, original_summary)

    def test_missing_or_invalid_provider_retry_after_uses_known_local_cooldown(self):
        csrf, source = self._get_result_page()
        for mode in ("rate_limit_missing", "rate_limit_invalid"):
            with self.subTest(mode=mode):
                self.mode_file.write_text(mode, encoding="utf-8")
                self.cooldown_file.write_text("{}", encoding="utf-8")
                self.calls_file.write_text("", encoding="utf-8")
                status, headers, response = self._post_translation(csrf, source)
                self.assertEqual(status, 503)
                self.assertEqual(response["code"], "TRANSLATION_RATE_LIMITED")
                self.assertEqual(headers.get("Cache-Control"), "no-store")
                self.assertGreater(int(headers["Retry-After"]), 0)
                self.assertEqual(
                    int(headers["Retry-After"]),
                    response["retry_after_seconds"],
                )
                self.assertNotIn("not-a-valid-retry-time", json.dumps(response))

    def test_timeout_network_invalid_response_and_worker_failures_keep_classification(self):
        csrf, source = self._get_result_page()
        expected = {
            "timeout": (504, "TRANSLATION_TIMEOUT"),
            "network": (503, "TRANSLATION_NETWORK_FAILURE"),
            "invalid_response": (502, "TRANSLATION_INVALID_RESPONSE"),
            "worker_failure": (503, "TRANSLATION_WORKER_FAILURE"),
        }
        for mode, (expected_status, expected_code) in expected.items():
            with self.subTest(mode=mode):
                self.mode_file.write_text(mode, encoding="utf-8")
                self.cooldown_file.write_text("{}", encoding="utf-8")
                self.calls_file.write_text("", encoding="utf-8")
                status, headers, response = self._post_translation(csrf, source)
                self.assertEqual(status, expected_status)
                self.assertEqual(response["code"], expected_code)
                self.assertTrue(headers.get("Cache-Control", "").startswith("no-store"))
                self.assertNotIn("Retry-After", headers)
                self.assertNotIn("private", json.dumps(response).lower())
                self.assertNotIn(source, json.dumps(response))


if __name__ == "__main__":
    unittest.main()
