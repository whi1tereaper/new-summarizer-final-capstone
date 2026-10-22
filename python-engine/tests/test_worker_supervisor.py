"""Lifecycle tests use a real spawned process without loading NLP libraries."""
import os
from pathlib import Path
import sys
import threading
import time
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from worker_daemon import SupervisorServer, WorkerSupervisor


def child_fixture(connection):
    if os.name != "nt":
        os.setsid()
    try:
        while True:
            action, payload = connection.recv()
            if action == "crash":
                os._exit(3)
            time.sleep(payload.get("sleep", 0))
            connection.send((200, {"pid": os.getpid(), "source_metadata": {}}))
    except (EOFError, BrokenPipeError):
        pass


class WorkerSupervisorTests(unittest.TestCase):
 def test_supervisor_disallows_port_sharing(self):
    self.assertEqual(SupervisorServer.allow_reuse_address, os.name != "nt")

 def test_configured_deadline_is_honored_with_a_hard_upper_bound(self):
    worker = WorkerSupervisor(120)
    self.assertEqual(worker.deadline, 120)
    worker.close()
    bounded = WorkerSupervisor(999)
    self.assertEqual(bounded.deadline, 300)
    bounded.close()

 def test_reuse_recycle_and_idle_release(self):
    worker = WorkerSupervisor(3, child_target=child_fixture, max_jobs=2, idle_seconds=0)
    try:
        first = worker.execute("run", {}, 3)[1]
        second = worker.execute("run", {}, 3)[1]
        third = worker.execute("run", {}, 3)[1]
        self.assertEqual(first["pid"], second["pid"])
        self.assertNotEqual(third["pid"], first["pid"])
        self.assertTrue(first["source_metadata"]["worker_cold_start"])
        self.assertFalse(second["source_metadata"]["worker_cold_start"])
        worker.reap_idle()
        self.assertIsNone(worker.process)
    finally:
        worker.close()


 def test_deadline_kills_child_and_next_request_restarts(self):
    worker = WorkerSupervisor(3, child_target=child_fixture)
    try:
        old_pid = worker.execute("run", {}, 3)[1]["pid"]
        started = time.monotonic()
        status, result = worker.execute("run", {"sleep": 5}, 0.15)
        self.assertEqual(status, 504)
        self.assertTrue(result["timeout"])
        self.assertEqual(result["failure"]["code"], "PROCESSING_TIMEOUT")
        self.assertLess(time.monotonic() - started, 3)
        self.assertIsNone(worker.process)
        self.assertNotEqual(worker.execute("run", {}, 3)[1]["pid"], old_pid)
    finally:
        worker.close()


 def test_busy_request_never_queues_and_cancellation_stops_child(self):
    worker = WorkerSupervisor(3, child_target=child_fixture)
    worker.execute("run", {}, 3)
    results = []
    thread = threading.Thread(target=lambda: results.append(worker.execute("run", {"job_id": "owner", "sleep": 5}, 3)))
    try:
        thread.start()
        deadline = time.monotonic() + 2
        while worker.active_job != "owner" and time.monotonic() < deadline:
            time.sleep(0.005)
        status, response = worker.execute("run", {}, 3)
        self.assertEqual(status, 503)
        self.assertFalse(response["accepted"])
        self.assertEqual(response["failure"]["code"], "WORKER_UNAVAILABLE")
        self.assertFalse(worker.cancel("someone_else"))
        self.assertTrue(worker.cancel("owner"))
        thread.join(3)
        self.assertFalse(thread.is_alive())
        self.assertEqual(results[0][0], 409)
        self.assertIsNone(worker.process)
    finally:
        worker.close()
        thread.join()


 def test_crash_is_not_retried_and_next_request_recovers(self):
    worker = WorkerSupervisor(10, child_target=child_fixture)
    try:
        # Allow Windows spawn startup separately from the fixture's immediate crash.
        status, result = worker.execute("crash", {}, 10)
        self.assertEqual(status, 503)
        self.assertEqual(result["failure"]["code"], "WORKER_UNAVAILABLE")
        self.assertIsNone(worker.process)
        self.assertEqual(worker.execute("run", {}, 10)[0], 200)
    finally:
        worker.close()


if __name__ == "__main__":
    unittest.main()
