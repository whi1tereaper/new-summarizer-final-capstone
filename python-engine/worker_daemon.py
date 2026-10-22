"""Foreground supervisor: one reusable NLP child, bounded admission and hard deadlines.

Run under a service manager or an owning test harness; this program never detaches.
Timeout/cancellation kills and joins the child before the slot is reused. Documents
are not retained by the supervisor. Idle/recycled children release model memory.
"""
from __future__ import annotations
import argparse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import hmac
import json
import logging
import multiprocessing
from multiprocessing.connection import Client, Listener
import os
from pathlib import Path
import socket
import signal
import subprocess
import threading
import time
from typing import Any
from failure_contract import failure_response

ENGINE_ROOT = Path(__file__).resolve().parent
DEFAULT_PORT = 5005
DEFAULT_EXECUTION_DEADLINE = 180.0
MAX_EXECUTION_DEADLINE = 300.0
WORKER_PROTOCOL_VERSION = 3
MAX_BODY_BYTES = 16 * 1024 * 1024
LOGGER = logging.getLogger("nlp_worker_daemon")


def _socket_child_main(child_target: Any, address: Any, authkey: bytes) -> None:
    """Run a supervisor child over an authenticated loopback socket.

    Windows named-pipe creation can be denied by service/sandbox policy. A
    loopback socket keeps the same authenticated parent/child contract without
    weakening the HTTP worker's localhost token boundary.
    """
    connection = Client(address, authkey=authkey)
    try:
        child_target(connection)
    finally:
        connection.close()


def resolve_expected_worker_token() -> str:
    secret = os.environ.get("PYTHON_WORKER_SECRET", "").strip()
    token_file = ENGINE_ROOT.parent / "storage" / "worker.token"
    if not secret and token_file.is_file():
        secret = token_file.read_text(encoding="utf-8").strip()
    if not secret:
        raise RuntimeError("Provision PYTHON_WORKER_SECRET or storage/worker.token before startup.")
    return secret


def _child_main(connection: Any) -> None:
    if os.name != "nt":
        os.setsid()
    import local_cli
    handlers = {name: getattr(local_cli, handler) for name, handler in {
        "summarize": "handle_summarize", "generate-summary": "handle_generate_summary",
        "nutshell": "handle_nutshell", "extract-pdf": "handle_extract_pdf",
        "translate": "handle_translate", "self-test": "handle_self_test",
        "tts-generate": "handle_tts", "qa": "handle_qa",
    }.items()}
    try:
        while True:
            action, payload = connection.recv()
            started = time.perf_counter()
            try:
                result = handlers[action](payload)
                if isinstance(result, dict) and isinstance(result.get("source_metadata"), dict):
                    result["source_metadata"]["daemon_elapsed_seconds"] = time.perf_counter() - started
                connection.send((200, result))
                del result
            except Exception as exc:
                failure = failure_response(exc)
                connection.send((failure["failure"]["http_status"], failure))
            finally:
                del payload
    except (EOFError, BrokenPipeError):
        pass
    finally:
        connection.close()


class WorkerSupervisor:
    def __init__(self, deadline: float = DEFAULT_EXECUTION_DEADLINE, *, child_target: Any = _child_main,
                 idle_seconds: float = 300.0, max_jobs: int = 100) -> None:
        self.deadline = max(0.05, min(MAX_EXECUTION_DEADLINE, deadline))
        self.child_target = child_target
        self.idle_seconds, self.max_jobs = idle_seconds, max_jobs
        self.admission, self.state_lock = threading.Lock(), threading.Lock()
        self.cancel_event = threading.Event()
        self.active_job = ""
        self.process = self.connection = None
        self.stopping = False
        self.jobs = 0
        self.last_used = time.monotonic()

    def _start_child(self) -> None:
        context = multiprocessing.get_context("spawn")
        if os.name == "nt":
            authkey = os.urandom(32)
            listener = Listener(("127.0.0.1", 0), authkey=authkey)
            process = context.Process(
                target=_socket_child_main,
                args=(self.child_target, listener.address, authkey),
                name="light-nlp-child",
            )
            process.start()
            listener._listener._socket.settimeout(10)
            parent = listener.accept()
            listener.close()
            self.process, self.connection, self.jobs = process, parent, 0
            return
        parent, child = context.Pipe()
        process = context.Process(target=self.child_target, args=(child,), name="light-nlp-child")
        process.start()
        child.close()
        self.process, self.connection, self.jobs = process, parent, 0

    def _stop_child(self) -> None:
        process, connection = self.process, self.connection
        if connection is not None:
            connection.close()
        if process is None:
            self.process = self.connection = None
            return
        if process.is_alive():
            if os.name == "nt":
                try:
                    subprocess.run(["taskkill", "/F", "/T", "/PID", str(process.pid)],
                                   capture_output=True, timeout=10, check=False)
                except subprocess.TimeoutExpired:
                    process.kill()
            else:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
            if process.is_alive():
                process.kill()
        process.join(timeout=5)
        if process.is_alive():
            raise RuntimeError("Could not stop the NLP child; refusing to reuse its slot.")
        process.close()
        self.process = self.connection = None

    def cancel(self, job_id: str) -> bool:
        with self.state_lock:
            if job_id and self.active_job == job_id:
                self.cancel_event.set()
                return True
        return False

    def reap_idle(self) -> None:
        if time.monotonic() - self.last_used >= self.idle_seconds and self.admission.acquire(False):
            try:
                self._stop_child()
            finally:
                self.admission.release()

    def execute(self, action: str, payload: dict[str, Any], requested_deadline: float) -> tuple[int, dict[str, Any]]:
        if not self.admission.acquire(False):
            return 503, failure_response("WORKER_UNAVAILABLE", accepted=False)
        try:
            with self.state_lock:
                if self.stopping:
                    return 503, failure_response("WORKER_UNAVAILABLE", accepted=False)
                self.active_job = str(payload.get("job_id", ""))
                self.cancel_event.clear()
            deadline = time.monotonic() + min(self.deadline, max(0.05, requested_deadline))
            if self.jobs >= self.max_jobs:
                self._stop_child()
            cold = self.process is None or not self.process.is_alive()
            if cold:
                self._stop_child()
                self._start_child()
            self.connection.send((action, payload))
            while True:
                if self.cancel_event.is_set():
                    self._stop_child()
                    return 409, failure_response("PROCESSING_CANCELLED", cancelled=True)
                if time.monotonic() >= deadline:
                    self._stop_child()
                    return 504, failure_response("PROCESSING_TIMEOUT", timeout=True)
                if self.connection.poll(min(0.05, max(0.0, deadline - time.monotonic()))):
                    status, result = self.connection.recv()
                    self.jobs += 1
                    if isinstance(result, dict) and isinstance(result.get("source_metadata"), dict):
                        result["source_metadata"]["worker_cold_start"] = cold
                    return status, result
                if not self.process.is_alive():
                    raise RuntimeError("Local NLP child exited without a result.")
        except (EOFError, BrokenPipeError, OSError, RuntimeError) as exc:
            self._stop_child()
            failure = failure_response("WORKER_UNAVAILABLE")
            LOGGER.warning("worker_unavailable reference=%s exception_type=%s", failure["failure"]["reference"], type(exc).__name__)
            return 503, failure
        finally:
            self.last_used = time.monotonic()
            with self.state_lock:
                self.active_job = ""
            self.admission.release()

    def close(self) -> None:
        with self.state_lock:
            self.stopping = True
            self.cancel_event.set()
        with self.admission:
            self._stop_child()


class NlpDaemonHandler(BaseHTTPRequestHandler):
    server_version = "LIGHTSupervisor/2"
    def log_message(self, format: str, *args: Any) -> None:
        pass  # Never log user supplied paths or payloads.

    def _send_json(self, status: int, data: dict[str, Any]) -> None:
        body = json.dumps(data, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        try:
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError):
            pass

    def _verify_auth(self) -> bool:
        if self.client_address[0] != "127.0.0.1" or not hmac.compare_digest(self.headers.get("X-Worker-Token", ""), self.server.worker_token):
            self._send_json(401, failure_response("AUTH_REQUIRED"))
            return False
        return True

    def do_GET(self) -> None:
        if not self._verify_auth():
            return
        if self.path.rstrip("/") != "/health":
            self._send_json(400, failure_response("REQUEST_INVALID"))
            return
        self._send_json(200, {"status": "ok", "protocol": WORKER_PROTOCOL_VERSION, "supervised": True,
                              "max_workers": 1, "execution_deadline_seconds": self.server.supervisor.deadline})

    def do_POST(self) -> None:
        if not self._verify_auth():
            return
        try:
            size = int(self.headers.get("Content-Length", "0"))
            if size > MAX_BODY_BYTES:
                self._send_json(413, failure_response("INPUT_TOO_LARGE"))
                return
            if size < 0:
                raise ValueError("Invalid size")
            self.connection.settimeout(10)
            raw = self.rfile.read(size)
            payload = json.loads(raw)
            if len(raw) != size or not isinstance(payload, dict):
                raise ValueError("Invalid body")
        except (ValueError, OSError):
            self._send_json(400, failure_response("REQUEST_INVALID"))
            return
        action = self.path.lstrip("/")
        if action == "cancel":
            self._send_json(200, {"cancelled": self.server.supervisor.cancel(str(payload.get("job_id", "")))})
            return
        if action == "shutdown":
            self._send_json(200, {"stopping": True})
            threading.Thread(target=self.server.shutdown, daemon=True).start()
            return
        if action not in {"summarize", "generate-summary", "nutshell", "extract-pdf", "translate", "self-test", "tts-generate", "qa"}:
            self._send_json(400, failure_response("REQUEST_INVALID"))
            return
        try:
            budget = float(self.headers.get("X-Worker-Deadline", DEFAULT_EXECUTION_DEADLINE))
        except ValueError:
            budget = DEFAULT_EXECUTION_DEADLINE
        status, result = self.server.supervisor.execute(action, payload, budget)
        self._send_json(status, result)


class SupervisorServer(ThreadingHTTPServer):
    daemon_threads = False
    request_queue_size = 8
    # Windows SO_REUSEADDR permits competing processes to bind the same
    # loopback port. One supervisor must own the port at a time.
    allow_reuse_address = os.name != "nt"

    def server_bind(self) -> None:
        if os.name == "nt" and hasattr(socket, "SO_EXCLUSIVEADDRUSE"):
            self.socket.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
        super().server_bind()

    def __init__(self, *args: Any, max_connections: int = 8, **kwargs: Any) -> None:
        self._connection_slots = threading.BoundedSemaphore(max_connections)
        super().__init__(*args, **kwargs)

    def process_request(self, request: Any, client_address: Any) -> None:
        if not self._connection_slots.acquire(blocking=False):
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, client_address)
        except BaseException:
            self._connection_slots.release()
            raise

    def process_request_thread(self, request: Any, client_address: Any) -> None:
        try:
            super().process_request_thread(request, client_address)
        finally:
            self._connection_slots.release()

    def service_actions(self) -> None:
        self.supervisor.reap_idle()


def acquire_instance_lock(port: int):
    lock_directory = ENGINE_ROOT.parent / "storage" / "tmp"
    lock_directory.mkdir(parents=True, exist_ok=True)
    lock_file = (lock_directory / f"nlp-worker-{port}.lock").open("a+b")
    try:
        if lock_file.seek(0, os.SEEK_END) == 0:
            lock_file.write(b"\0")
            lock_file.flush()
        lock_file.seek(0)
        if os.name == "nt":
            import msvcrt
            msvcrt.locking(lock_file.fileno(), msvcrt.LK_NBLCK, 1)
        else:
            import fcntl
            fcntl.flock(lock_file.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError as exc:
        lock_file.close()
        raise RuntimeError(f"Another LIGHT NLP supervisor already owns port {port}.") from exc
    return lock_file


def run_daemon(port: int = DEFAULT_PORT, deadline: float = DEFAULT_EXECUTION_DEADLINE) -> None:
    instance_lock = acquire_instance_lock(port)
    supervisor = WorkerSupervisor(deadline)
    server = None
    try:
        token = resolve_expected_worker_token()
        server = SupervisorServer(("127.0.0.1", port), NlpDaemonHandler)
        server.worker_token, server.supervisor = token, supervisor
        def stop_server(signum: int, frame: Any) -> None:
            threading.Thread(target=server.shutdown, daemon=True).start()
        signal.signal(signal.SIGTERM, stop_server)
        signal.signal(signal.SIGINT, stop_server)
        server.serve_forever(poll_interval=0.1)
    finally:
        supervisor.close()
        if server is not None:
            server.server_close()
        instance_lock.close()


if __name__ == "__main__":
    multiprocessing.freeze_support()
    parser = argparse.ArgumentParser(description="Foreground LIGHT NLP supervisor")
    parser.add_argument("--port", type=int, default=int(os.environ.get("PYTHON_WORKER_PORT", DEFAULT_PORT)))
    parser.add_argument("--deadline", type=float, default=float(os.environ.get("LOCAL_PYTHON_TIMEOUT", DEFAULT_EXECUTION_DEADLINE)))
    args = parser.parse_args()
    run_daemon(args.port, args.deadline)
