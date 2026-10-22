"""Bounded, request-owned NLP features and optional monotonic diagnostics.

Only models/resources may live across requests. Text, parses and vectors in this
module are released on success *and* failure; ContextVar keeps callers isolated.
"""
from __future__ import annotations

from collections import OrderedDict
from contextlib import contextmanager
from contextvars import ContextVar
from dataclasses import dataclass, field
from functools import wraps
import time
from typing import Any


class BoundedCache:
    def __init__(self, entries: int, units: int) -> None:
        self.entries = entries
        self.units = units
        self.used = 0
        self.values: OrderedDict[Any, tuple[Any, int]] = OrderedDict()

    def get(self, key: Any) -> Any:
        item = self.values.get(key)
        if item is None:
            return None
        self.values.move_to_end(key)
        return item[0]

    def put(self, key: Any, value: Any, cost: int = 1) -> None:
        cost = max(1, cost)
        if cost > self.units:
            return
        old = self.values.pop(key, None)
        if old is not None:
            self.used -= old[1]
        while self.values and (len(self.values) >= self.entries or self.used + cost > self.units):
            _, (_, removed) = self.values.popitem(last=False)
            self.used -= removed
        self.values[key] = (value, cost)
        self.used += cost


@dataclass
class RequestRuntime:
    profile: bool = False
    caches: dict[str, BoundedCache] = field(default_factory=dict)
    stages: dict[str, dict[str, float | int]] = field(default_factory=dict)
    stack: list[list[float]] = field(default_factory=list)
    started: float = field(default_factory=time.perf_counter)
    cpu_started: float = field(default_factory=time.process_time)

    def report(self) -> dict[str, Any]:
        wall = time.perf_counter() - self.started
        cpu = time.process_time() - self.cpu_started
        report = {
            "wall_seconds": round(wall, 6),
            "cpu_seconds": round(cpu, 6),
            "cpu_percent_one_core": round(100 * cpu / max(wall, 1e-9), 2),
            "stages": {key: {k: round(v, 6) if isinstance(v, float) else v for k, v in item.items()}
                       for key, item in self.stages.items()},
            "timing_note": "Exclusive times exclude instrumented children; inclusive times overlap and must not be summed.",
        }
        try:
            import psutil
            memory = psutil.Process().memory_info()
            report["rss_mb"] = round(memory.rss / 1048576, 2)
            report["peak_rss_mb"] = round(getattr(memory, "peak_wset", memory.rss) / 1048576, 2)
        except ImportError:
            pass
        return report


_CURRENT: ContextVar[RequestRuntime | None] = ContextVar("light_request_runtime", default=None)


@contextmanager
def request_scope(*, profile: bool = False):
    existing = _CURRENT.get()
    if existing is not None:
        yield existing
        return
    runtime = RequestRuntime(profile=profile)
    token = _CURRENT.set(runtime)
    try:
        yield runtime
    finally:
        runtime.caches.clear()
        _CURRENT.reset(token)


def request_cache(name: str, *, entries: int = 2048, units: int = 2_000_000) -> BoundedCache | None:
    runtime = _CURRENT.get()
    if runtime is None:
        return None
    if name not in runtime.caches:
        runtime.caches[name] = BoundedCache(entries, units)
    return runtime.caches[name]


def request_scoped(function):
    @wraps(function)
    def wrapped(*args, **kwargs):
        with request_scope():
            return function(*args, **kwargs)
    return wrapped


@contextmanager
def stage(name: str):
    runtime = _CURRENT.get()
    if runtime is None or not runtime.profile:
        yield
        return
    frame = [time.perf_counter(), 0.0]
    runtime.stack.append(frame)
    try:
        yield
    finally:
        elapsed = time.perf_counter() - frame[0]
        runtime.stack.pop()
        if runtime.stack:
            runtime.stack[-1][1] += elapsed
        item = runtime.stages.setdefault(name, {"calls": 0, "inclusive_seconds": 0.0, "exclusive_seconds": 0.0})
        item["calls"] += 1
        item["inclusive_seconds"] += elapsed
        item["exclusive_seconds"] += max(0.0, elapsed - frame[1])


def timed(name: str):
    def decorate(function):
        @wraps(function)
        def wrapped(*args, **kwargs):
            with stage(name):
                return function(*args, **kwargs)
        return wrapped
    return decorate


def parse_text(nlp: Any, text: str) -> Any:
    """Reuse only an identical full-text parse; never substitute paragraph spans."""
    cache = request_cache("spacy_docs", entries=512, units=60_000)
    key = (id(nlp), text)
    cached = cache.get(key) if cache is not None else None
    if cached is not None:
        return cached
    with stage("linguistic_annotations"):
        doc = nlp(text)
    if cache is not None:
        cache.put(key, doc, max(len(doc), len(text) // 4))
    return doc
