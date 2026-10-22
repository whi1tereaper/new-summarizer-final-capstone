import getpass
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest

ENGINE_ROOT = Path(__file__).resolve().parents[1]
REPOSITORY_ROOT = ENGINE_ROOT.parent
STORAGE_ROOT = REPOSITORY_ROOT / "storage"
sys.path.insert(0, str(ENGINE_ROOT))

import local_cli


class TranslationCooldownProcessTests(unittest.TestCase):
    def setUp(self):
        STORAGE_ROOT.mkdir(parents=True, exist_ok=True)
        self.temp_storage = tempfile.TemporaryDirectory(
            prefix=".translation-cooldown-test-",
            dir=STORAGE_ROOT,
        )
        self.state_path = Path(self.temp_storage.name) / "cooldown.json"

    def tearDown(self):
        self.temp_storage.cleanup()

    def run_python(self, code, *arguments):
        result = subprocess.run(
            [sys.executable, "-c", code, str(self.state_path), *map(str, arguments)],
            cwd=REPOSITORY_ROOT,
            capture_output=True,
            text=True,
            timeout=30,
            env={
                **os.environ,
                "PYTHONPATH": str(ENGINE_ROOT)
                + os.pathsep
                + os.environ.get("PYTHONPATH", ""),
                "PYTHONDONTWRITEBYTECODE": "1",
            },
        )
        self.assertEqual(
            result.returncode,
            0,
            msg=f"Subprocess failed with {result.returncode}.\n"
            f"stdout:\n{result.stdout}\nstderr:\n{result.stderr}",
        )
        return result.stdout.strip()

    def test_cooldown_survives_independent_process_exit_and_restart(self):
        writer = """
import json, sys
from pathlib import Path
import local_cli
local_cli._PROVIDER_COOLDOWN_FILE = Path(sys.argv[1])
until = local_cli._apply_provider_cooldown("mymemory", 60)
print(json.dumps({"writer_until": until}))
"""
        reader = """
import json, sys
from pathlib import Path
import local_cli
local_cli._PROVIDER_COOLDOWN_FILE = Path(sys.argv[1])
print(json.dumps({
    "cooling": local_cli._provider_is_cooling_down("mymemory"),
    "until": local_cli._provider_cooldown_until("mymemory"),
}))
"""

        writer_output = json.loads(self.run_python(writer))
        self.assertGreater(writer_output["writer_until"], time.time())
        self.assertTrue(self.state_path.exists())

        process_b = json.loads(self.run_python(reader))
        self.assertTrue(process_b["cooling"])
        self.assertAlmostEqual(process_b["until"], writer_output["writer_until"], delta=0.05)

        process_c = json.loads(self.run_python(reader))
        self.assertTrue(process_c["cooling"])
        self.assertEqual(getpass.getuser(), self.run_python("import getpass; print(getpass.getuser())"))

    def test_concurrent_independent_updates_keep_valid_complete_json(self):
        start_file = self.state_path.with_name("start")
        writer = """
import sys, time
from pathlib import Path
import local_cli
local_cli._PROVIDER_COOLDOWN_FILE = Path(sys.argv[1])
start = Path(sys.argv[2])
deadline = time.monotonic() + 15
while not start.exists():
    if time.monotonic() >= deadline:
        raise TimeoutError("concurrent writer start signal timed out")
    time.sleep(0.005)
local_cli._apply_provider_cooldown(sys.argv[3], 90)
"""
        processes = [
            subprocess.Popen(
                [sys.executable, "-c", writer, str(self.state_path), str(start_file), f"provider-{index}"],
                cwd=REPOSITORY_ROOT,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                env={
                    **os.environ,
                    "PYTHONPATH": str(ENGINE_ROOT)
                    + os.pathsep
                    + os.environ.get("PYTHONPATH", ""),
                    "PYTHONDONTWRITEBYTECODE": "1",
                },
            )
            for index in range(12)
        ]
        try:
            time.sleep(0.15)
            start_file.write_text("go", encoding="utf-8")
            for process in processes:
                stdout, stderr = process.communicate(timeout=30)
                self.assertEqual(
                    process.returncode,
                    0,
                    msg=f"Concurrent writer failed with {process.returncode}.\n"
                    f"stdout:\n{stdout}\nstderr:\n{stderr}",
                )
        finally:
            for process in processes:
                if process.poll() is None:
                    process.terminate()
                    process.wait(timeout=5)

        state = json.loads(self.state_path.read_text(encoding="utf-8"))
        self.assertEqual(set(state), {f"provider-{index}" for index in range(12)})
        self.assertTrue(all(until > time.time() for until in state.values()))

    def test_concurrent_provider_requests_use_mocked_processes(self):
        marker_directory = self.state_path.with_name("provider-calls")
        marker_directory.mkdir()
        start_file = self.state_path.with_name("provider-start")
        release_file = self.state_path.with_name("provider-release")
        request = """
import os, sys, time, types
from pathlib import Path
class RateLimit(RuntimeError):
    def __init__(self):
        super().__init__("429 Too Many Requests")
        self.response = types.SimpleNamespace(headers={"Retry-After": "30"})
class FakeGoogle:
    __module__ = "deep_translator.google_translator"
    def __init__(self, *args, **kwargs): pass
    def translate(self, text): return "Mock Filipino translation."
class FakeMemory:
    __module__ = "deep_translator.mymemory_translator"
    def __init__(self, *args, **kwargs): pass
    def translate(self, text):
        marker = Path(sys.argv[2]) / str(os.getpid())
        marker.write_text("called", encoding="utf-8")
        release = Path(sys.argv[4])
        deadline = time.monotonic() + 15
        while not release.exists():
            if time.monotonic() >= deadline:
                raise TimeoutError("test release signal timed out")
            time.sleep(0.005)
        raise RateLimit()
sys.modules["deep_translator"] = types.SimpleNamespace(
    GoogleTranslator=FakeGoogle, MyMemoryTranslator=FakeMemory
)
import local_cli
local_cli._PROVIDER_COOLDOWN_FILE = Path(sys.argv[1])
ready = Path(sys.argv[2]) / ("ready-" + str(os.getpid()))
ready.write_text("ready", encoding="utf-8")
start = Path(sys.argv[3])
deadline = time.monotonic() + 15
while not start.exists():
    if time.monotonic() >= deadline:
        raise TimeoutError("test start signal timed out")
    time.sleep(0.005)
result = local_cli.translate_text("Original English summary.", "tl")
assert result == "Mock Filipino translation."
"""
        process_count = 6
        processes = [
            subprocess.Popen(
                [
                    sys.executable,
                    "-c",
                    request,
                    str(self.state_path),
                    str(marker_directory),
                    str(start_file),
                    str(release_file),
                ],
                cwd=REPOSITORY_ROOT,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                env={
                    **os.environ,
                    "PYTHONPATH": str(ENGINE_ROOT)
                    + os.pathsep
                    + os.environ.get("PYTHONPATH", ""),
                    "PYTHONDONTWRITEBYTECODE": "1",
                },
            )
            for _ in range(process_count)
        ]
        try:
            ready_deadline = time.monotonic() + 20
            while len(list(marker_directory.glob("ready-*"))) < process_count:
                if time.monotonic() >= ready_deadline:
                    self.fail("Independent translation processes did not reach the start barrier.")
                time.sleep(0.01)
            start_file.write_text("go", encoding="utf-8")
            request_deadline = time.monotonic() + 10
            while not list(marker_directory.glob("[0-9]*")):
                if time.monotonic() >= request_deadline:
                    self.fail("No mocked provider request reached the synchronization barrier.")
                time.sleep(0.01)
            time.sleep(0.5)
        finally:
            release_file.write_text("release", encoding="utf-8")
            for process in processes:
                stdout, stderr = process.communicate(timeout=30)
                self.assertEqual(
                    process.returncode,
                    0,
                    msg=f"Translation subprocess failed with {process.returncode}.\n"
                    f"stdout:\n{stdout}\nstderr:\n{stderr}",
                )

        call_count = len(list(marker_directory.glob("[0-9]*")))
        self.assertEqual(call_count, 1, f"Expected one concurrent MyMemory call, observed {call_count}.")
        state = json.loads(self.state_path.read_text(encoding="utf-8"))
        self.assertIn("mymemory", state)

    def test_cooldown_expires_across_processes(self):
        self.run_python(
            """
import sys
from pathlib import Path
import local_cli
local_cli._PROVIDER_COOLDOWN_FILE = Path(sys.argv[1])
local_cli._apply_provider_cooldown("google", 0.3)
"""
        )
        time.sleep(0.4)
        output = json.loads(
            self.run_python(
                """
import json, sys
from pathlib import Path
import local_cli
local_cli._PROVIDER_COOLDOWN_FILE = Path(sys.argv[1])
print(json.dumps({
    "cooling": local_cli._provider_is_cooling_down("google"),
    "state": local_cli._read_provider_cooldown_state(),
}))
"""
            )
        )
        self.assertFalse(output["cooling"])
        self.assertEqual(output["state"], {})

    def test_corrupt_or_partially_written_state_is_ignored_and_recoverable(self):
        self.state_path.write_text('{"google":', encoding="utf-8")
        result = json.loads(
            self.run_python(
                """
import json, sys
from pathlib import Path
import local_cli
local_cli._PROVIDER_COOLDOWN_FILE = Path(sys.argv[1])
print(json.dumps({
    "cooling": local_cli._provider_is_cooling_down("google"),
    "state": local_cli._read_provider_cooldown_state(),
}))
"""
            )
        )
        self.assertFalse(result["cooling"])
        self.assertEqual(result["state"], {})

        self.run_python(
            """
import sys
from pathlib import Path
import local_cli
local_cli._PROVIDER_COOLDOWN_FILE = Path(sys.argv[1])
local_cli._apply_provider_cooldown("google", 45)
"""
        )
        state = json.loads(self.state_path.read_text(encoding="utf-8"))
        self.assertGreater(state["google"], time.time())

    def test_storage_permissions_and_production_path(self):
        expected_path = STORAGE_ROOT / "translation_provider_cooldown.json"
        self.assertEqual(local_cli._PROVIDER_COOLDOWN_FILE.resolve(), expected_path.resolve())
        self.assertTrue(os.access(STORAGE_ROOT, os.W_OK))

        output = json.loads(
            self.run_python(
                """
import json, getpass, sys
from pathlib import Path
import local_cli
local_cli._PROVIDER_COOLDOWN_FILE = Path(sys.argv[1])
until = local_cli._apply_provider_cooldown("permission-check", 30)
print(json.dumps({
    "cooling": local_cli._provider_is_cooling_down("permission-check"),
    "until": until,
    "user": getpass.getuser(),
    "lock_exists": local_cli._PROVIDER_COOLDOWN_FILE.with_suffix(".lock").exists(),
}))
"""
            )
        )
        self.assertTrue(output["cooling"])
        self.assertTrue(output["lock_exists"])
        self.assertGreater(output["until"], time.time())
        self.assertEqual(output["user"], getpass.getuser())

    def test_cli_and_daemon_translation_handlers_use_the_shared_cooldown(self):
        cli_output = json.loads(
            self.run_python(
                """
import json, sys
from pathlib import Path
import local_cli
local_cli._PROVIDER_COOLDOWN_FILE = Path(sys.argv[1])
local_cli._apply_provider_cooldown("mymemory", 60)
source = "Original English summary."
def fake_translate(text, target_lang):
    assert text == source
    assert local_cli._provider_is_cooling_down("mymemory")
    return "Mock Filipino translation."
local_cli.translate_text = fake_translate
result = local_cli.handle_translate({"text": source, "target_lang": "tl"})
assert source == "Original English summary."
print(json.dumps({"result": result, "source": source}))
"""
            )
        )
        self.assertEqual(cli_output["source"], "Original English summary.")
        self.assertEqual(cli_output["result"]["translated"], "Mock Filipino translation.")

        daemon_output = json.loads(
            self.run_python(
                """
import json, sys
from pathlib import Path
import local_cli
import worker_daemon
local_cli._PROVIDER_COOLDOWN_FILE = Path(sys.argv[1])
local_cli._apply_provider_cooldown("google", 60)
source = "Original English summary."
def fake_translate(text, target_lang):
    assert text == source
    assert local_cli._provider_is_cooling_down("google")
    return "Mock Filipino translation."
local_cli.translate_text = fake_translate
class Connection:
    def __init__(self):
        self.sent = []
        self.received = False
    def recv(self):
        if self.received:
            raise EOFError
        self.received = True
        return "translate", {"text": source, "target_lang": "tl"}
    def send(self, response):
        self.sent.append(response)
    def close(self):
        pass
connection = Connection()
worker_daemon._child_main(connection)
assert source == "Original English summary."
print(json.dumps({"response": connection.sent[0], "source": source}))
"""
            )
        )
        self.assertEqual(daemon_output["source"], "Original English summary.")
        self.assertEqual(daemon_output["response"][0], 200)
        self.assertEqual(daemon_output["response"][1]["translated"], "Mock Filipino translation.")

    def test_cooldown_state_contains_no_document_text_or_credentials(self):
        self.run_python(
            """
import sys
from pathlib import Path
import local_cli
local_cli._PROVIDER_COOLDOWN_FILE = Path(sys.argv[1])
local_cli._apply_provider_cooldown("mymemory", 60)
"""
        )
        raw_state = self.state_path.read_text(encoding="utf-8")
        for private_value in ("Original English summary", "Bearer ", "api_key", "password"):
            self.assertNotIn(private_value, raw_state)
        self.assertEqual(set(json.loads(raw_state)), {"mymemory"})


if __name__ == "__main__":
    unittest.main()
