"""Reproducible local CLI cold/warm benchmarks, without modifying engine code.

Run from the repository root with its installed Python interpreter::

    .venv/Scripts/python.exe python-engine/evaluation/benchmark_performance.py \
        --output scratch/performance-after.json

Each case gets a fresh process. Its first request is cold (models not loaded;
OS filesystem caches are not flushed), subsequent identical requests are warm.
--prewarm-models first processes a different document; the target's first run
then measures reusable models without a cache hit on the target document.
Optional --trace records nested monotonic timings, with separately reported
exclusive and inclusive durations. Inclusive durations must never be summed.
--profile adds a separate warm cProfile run, excluded from latency comparisons.
Outputs contain source-derived summaries: keep benchmark artifacts private.
"""
from __future__ import annotations

import time

SCRIPT_STARTED = time.perf_counter()

import argparse
import builtins
from collections import defaultdict
import cProfile
import functools
import hashlib
import importlib
import inspect
import json
import os
from pathlib import Path
import platform
import pstats
import subprocess
import sys

REPOSITORY = Path(__file__).resolve().parents[2]
ENGINE = REPOSITORY / "python-engine"
# evaluation/text.py must not shadow stdlib text when this is run as a script.
if sys.path and Path(sys.path[0]).resolve() == Path(__file__).resolve().parent:
    sys.path.pop(0)


def digest(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True).encode()).hexdigest()


def load_environment():
    path = REPOSITORY / ".env"
    if path.is_file():
        for line in path.read_text(encoding="utf-8").splitlines():
            if line.strip() and not line.lstrip().startswith("#") and "=" in line:
                key, value = line.split("=", 1)
                os.environ[key.strip()] = value.strip().strip("\"'")


def cases():
    heldout = json.loads((ENGINE / "evaluation/corpus/heldout_documents.json").read_text(encoding="utf-8"))["documents"]
    published = json.loads((ENGINE / "tests/fixtures/evaluation_validation_documents.json").read_text(encoding="utf-8"))["documents"]
    by_id = {doc["id"]: doc for doc in heldout + published}
    selections = [("short", "HO-SHORT-LIBRARY"), ("medium", "HO-RESEARCH-COMMUTE"), ("long", "CENSUS-CB24-149")]
    return {size: {**by_id[doc_id], "size_class": size} for size, doc_id in selections}


class Stages:
    """Nested wrappers account for child wall time exactly once exclusively."""

    def __init__(self):
        self.records = defaultdict(lambda: {"calls": 0, "inclusive_seconds": 0.0, "exclusive_seconds": 0.0})
        self.stack = []
        self.enabled = False
        self.patched = set()

    def wrap(self, function, stage):
        @functools.wraps(function)
        def timed(*args, **kwargs):
            if not self.enabled:
                return function(*args, **kwargs)
            started = time.perf_counter()
            frame = [0.0]
            self.stack.append(frame)
            try:
                return function(*args, **kwargs)
            finally:
                elapsed = time.perf_counter() - started
                self.stack.pop()
                if self.stack:
                    self.stack[-1][0] += elapsed
                record = self.records[stage]
                record["calls"] += 1
                record["inclusive_seconds"] += elapsed
                record["exclusive_seconds"] += max(0.0, elapsed - frame[0])
        return timed

    def member(self, owner, name, stage):
        key = (id(owner), name)
        if key in self.patched or not hasattr(owner, name):
            return
        self.patched.add(key)
        descriptor = inspect.getattr_static(owner, name)
        if isinstance(descriptor, (staticmethod, classmethod)):
            setattr(owner, name, type(descriptor)(self.wrap(descriptor.__func__, stage)))
        else:
            setattr(owner, name, self.wrap(getattr(owner, name), stage))

    def aliases(self, module, name, stage):
        original = getattr(module, name, None)
        if original is None:
            return
        wrapped = self.wrap(original, stage)
        for module_name, candidate in tuple(sys.modules.items()):
            if module_name.startswith("summarizer_core"):
                for attribute, value in tuple(vars(candidate).items()):
                    if value is original:
                        setattr(candidate, attribute, wrapped)

    def install(self):
        specifications = {
            "extraction": {"load_source_document": "source_resolution", "fetch_url_source": "source_retrieval", "extract_pdf_source": "pdf_extraction"},
            "cleaning": {"prepare_document_paragraphs": "normalization"},
            "tokenization": {"safe_sent_tokenize": "sentence_segmentation", "preprocess_sentence_for_ranking": "linguistic_features", "load_optional_spacy_pipeline": "spacy_initialization_or_lookup", "safe_pos_tag": "pos_tagging", "normalized_sentence_tokens": "normalization_tokens"},
            "embeddings": {"embed_texts": "embedding_total"},
            "retrieval": {"retrieve_candidates": "mode_evidence_retrieval"},
            "scoring": {"calculate_candidate_scores_with_profile": "sentence_ranking", "sentences_are_redundant": "redundancy_detection"},
            "selection": {"generate_and_rank_summary_candidates": "candidate_selection", "detect_topic_clusters": "topic_clustering"},
            "document_analysis": {"analyze_document_structure": "document_analysis", "build_summary_plan": "summary_plan", "build_dynamic_summary_sections": "format_rendering"},
            "simplification": {"get_nlp": "simplification_spacy_initialization_or_lookup", "simplify_summary_sentences": "simplification"},
            "fact_ledger": {"build_fact_ledger": "fact_ledger", "validate_summary_facts": "factual_validation"},
            "grammar_check": {"get_grammar_checker": "grammar_initialization_or_lookup"},
        }
        for name, functions in specifications.items():
            module = importlib.import_module("summarizer_core." + name)
            for function, stage in functions.items():
                self.aliases(module, function, stage)
        from summarizer_core.pipeline import SummarizationPipeline
        for function, stage in {
            "_build_sentence_candidates": "candidate_generation", "_calculate_dynamic_target": "depth_budget",
            "_looks_sentence_fragment": "sentence_completeness", "_validate_summary_output": "coherence_validation",
            "_verify_source_faithfulness": "source_faithfulness", "_build_plain_summary": "format_rendering",
            "_build_paragraph_summaries": "paragraph_rendering", "_extract_keywords": "keywords",
        }.items():
            self.member(SummarizationPipeline, function, stage)
        from summarizer_core.grammar_check import LocalGrammarChecker
        self.member(LocalGrammarChecker, "check", "grammar_validation")
        from sklearn.feature_extraction.text import TfidfVectorizer
        self.member(TfidfVectorizer, "fit_transform", "tfidf_vectorization")
        from summarizer_core.fact_ledger import FactualityValidator
        self.member(FactualityValidator, "validate_and_repair", "factual_repair")
        from summarizer_core import tokenization
        optional_module = tokenization.optional_module

        def traced_optional_module(name):
            started = time.perf_counter()
            result = optional_module(name)
            if name == "spacy" and result is not None:
                self.member(result, "load", "spacy_model_loading")
                self.member(result.language.Language, "__call__", "spacy_annotation")
                if self.enabled:
                    self.imports[name] = self.imports.get(name, 0.0) + time.perf_counter() - started
            return result
        tokenization.optional_module = traced_optional_module
        self.original_import = builtins.__import__

        def traced_import(name, *args, **kwargs):
            # The original import remains lazy. Only patch optional modules once
            # they exist, so tracing does not preload their expensive models.
            started = time.perf_counter()
            already_loaded = name in sys.modules
            result = self.original_import(name, *args, **kwargs)
            if name == "spacy" and hasattr(result, "load"):
                self.member(result, "load", "spacy_model_loading")
                if hasattr(result, "language") and hasattr(result.language, "Language"):
                    self.member(result.language.Language, "__call__", "spacy_annotation")
            if name == "sentence_transformers" and inspect.isclass(getattr(result, "SentenceTransformer", None)):
                self.member(result.SentenceTransformer, "__init__", "embedding_model_loading")
                self.member(result.SentenceTransformer, "encode", "embedding_generation")
            if self.enabled and not already_loaded and name in {"spacy", "sentence_transformers"}:
                # Import detail is diagnostic and may overlap its enclosing
                # stage; keep it out of the exclusive stage ledger.
                imports = getattr(self, "imports", {})
                imports[name] = imports.get(name, 0.0) + time.perf_counter() - started
                self.imports = imports
            return result
        builtins.__import__ = traced_import


def resources():
    import psutil
    process = psutil.Process()
    processes = [process] + process.children(recursive=True)
    cpu = rss = 0.0
    for item in processes:
        try:
            times = item.cpu_times()
            cpu += times.user + times.system
            rss += item.memory_info().rss / (1024 * 1024)
        except psutil.Error:
            pass
    memory = process.memory_info()
    return {"cpu_seconds": cpu, "rss_mb": memory.rss / (1024 * 1024), "tree_rss_mb": rss,
            "process_peak_rss_mb": getattr(memory, "peak_wset", memory.rss) / (1024 * 1024),
            "child_count": len(processes) - 1}


def worker(args):
    load_environment()
    sys.path.insert(0, str(ENGINE))
    sys.path.insert(0, str(Path(args.engine_root).resolve()))
    started = time.perf_counter()
    import local_cli
    imports = time.perf_counter() - started
    trace = Stages()
    if args.trace:
        trace.install()
    document = ({"id": Path(args.input_file).name, "source_text": "", "file_path": str(Path(args.input_file).resolve()), "profile": "academic"}
                if args.input_file else cases()[args.case])
    request = {"text": document["source_text"], "analysis_mode": args.mode or document.get("profile", "general"),
               "summary_depth": args.depth, "summary_length": args.depth, "output_format": args.format,
               "use_llm_synthesis": False}
    if args.input_file:
        request["file_path"] = document["file_path"]
    result = {"case": args.case, "document_id": document["id"], "source_sha256": digest(document["source_text"]),
              "source_words": len(document["source_text"].split()), "source_characters": len(document["source_text"]),
              "configuration": {key: value for key, value in request.items() if key != "text"},
              "process_to_script_start_seconds": SCRIPT_STARTED - args.parent_started,
              "cli_import_seconds": imports, "traced": args.trace, "runs": []}
    try:
        if args.prewarm_models:
            # Four-candidate short sources skip semantic retrieval, so cannot
            # prime the embedding model used by ordinary/long documents.
            primer = cases()["long" if args.case == "medium" else "medium"]
            started = time.perf_counter()
            local_cli.handle_summarize({**request, "text": primer["source_text"], "file_path": ""})
            result["model_prewarm"] = {"document_id": primer["id"], "seconds": time.perf_counter() - started}
        for index in range(args.warm_runs + 1):
            trace.records.clear()
            trace.imports = {}
            before = resources()
            trace.enabled = args.trace
            started = time.perf_counter()
            output = local_cli.handle_summarize(request)
            elapsed = time.perf_counter() - started
            trace.enabled = False
            after = resources()
            started = time.perf_counter()
            encoded = json.dumps(output, ensure_ascii=False)
            serialization = time.perf_counter() - started
            metadata = output.get("source_metadata", {})
            selected = {key: output.get(key) for key in ("sentences", "plain_summary", "overview", "key_points", "structured_summary", "paragraph_summaries", "evidence", "summary_depth", "analysis_mode", "output_format")}
            run = {"state": ("warm_model_unseen_document" if args.prewarm_models else "cold") if index == 0 else "warm_identical_document", "iteration": index,
                   "summarize_seconds": elapsed, "json_serialization_seconds": serialization,
                   "cpu_seconds": after["cpu_seconds"] - before["cpu_seconds"],
                   "cpu_percent_one_core": (after["cpu_seconds"] - before["cpu_seconds"]) / elapsed * 100,
                   "resources_before": before, "resources_after": after,
                   "candidate_count": metadata.get("candidate_sentence_count"), "selected_sentence_count": output.get("sentence_count"),
                   "summary_word_count": output.get("readability", {}).get("summary_word_count"),
                   "selected_output_sha256": digest(selected), "summary_sha256": digest(output.get("plain_summary", "")),
                   "json_bytes": len(encoded.encode()), "stages": dict(trace.records), "optional_import_seconds": trace.imports,
                   "quality": {"validation_passed": output.get("validation_passed"), "validation_notes": output.get("validation_notes"),
                               "grammar": metadata.get("grammar_diagnostics"), "quality_selection": metadata.get("quality_selection"),
                               "readability": output.get("readability"), "fact_validation": metadata.get("fact_validation")}}
            if index == 0:
                result["output"] = output
                if args.input_file:
                    document["source_text"] = output["raw_text"]
                    result["source_sha256"] = digest(document["source_text"])
                    result["source_words"] = len(document["source_text"].split())
                    result["source_characters"] = len(document["source_text"])
                    result["source_file_sha256"] = hashlib.sha256(Path(args.input_file).read_bytes()).hexdigest()
            result["runs"].append(run)
        if args.profile:
            profiler = cProfile.Profile()
            started = time.perf_counter()
            profiler.runcall(local_cli.handle_summarize, request)
            profile_elapsed = time.perf_counter() - started
            stats = pstats.Stats(profiler)
            result["separate_warm_profile"] = {"wall_seconds": profile_elapsed, "note": "cProfile cumulative times overlap; excluded from latency comparisons", "top_cumulative": [
                {"file": key[0], "line": key[1], "function": key[2], "calls": value[1], "self_seconds": value[2], "cumulative_seconds": value[3]}
                for key, value in sorted(stats.stats.items(), key=lambda item: item[1][3], reverse=True)[:60]]}
        from evaluation.metrics import compute_metrics
        result["automated_quality_metrics"] = compute_metrics(document["source_text"], result["output"]["plain_summary"], annotations=document.get("critical_facts", []), prohibited=document.get("prohibited", []), options={})
    finally:
        from summarizer_core.grammar_check import close_grammar_checker
        close_grammar_checker()
    print(json.dumps(result, ensure_ascii=False), flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", default="scratch/performance.json")
    parser.add_argument("--engine-root", default=str(ENGINE))
    parser.add_argument("--input-file", default="", help="Benchmark PDF/DOCX through the actual CLI file extraction path")
    parser.add_argument("--cases", default="short,medium,long")
    parser.add_argument("--case", default="medium")
    parser.add_argument("--mode", default="")
    parser.add_argument("--depth", default="balanced")
    parser.add_argument("--format", default="paragraph")
    parser.add_argument("--warm-runs", type=int, default=1)
    parser.add_argument("--timeout", type=float, default=300)
    parser.add_argument("--trace", action="store_true")
    parser.add_argument("--profile", action="store_true")
    parser.add_argument("--prewarm-models", action="store_true")
    parser.add_argument("--worker", action="store_true")
    parser.add_argument("--parent-started", type=float, default=0)
    args = parser.parse_args()
    if args.worker:
        worker(args)
        return
    import psutil
    report = {"schema": "light-local-performance-v1", "hardware": {"platform": platform.platform(), "processor": platform.processor(),
              "logical_cpus": psutil.cpu_count(), "physical_cpus": psutil.cpu_count(logical=False), "ram_mb": psutil.virtual_memory().total / (1024 * 1024), "python": sys.version},
              "engine_root": str(Path(args.engine_root).resolve()), "source_code_sha256": digest({str(path.relative_to(args.engine_root)): hashlib.sha256(path.read_bytes()).hexdigest() for path in Path(args.engine_root).rglob("*.py") if "__pycache__" not in str(path) and ("summarizer_core" in str(path) or path.name == "local_cli.py")}),
              "method": "One fresh process per source; serial runs; cold models with unflushed OS caches; identical warm request; CPU includes local grammar subprocess; RSS sampled at request boundaries and process high water mark; no network or synthesis requested.", "cases": []}
    output_path = Path(args.output)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    for case in (["file"] if args.input_file else args.cases.split(",")):
        command = [sys.executable, str(Path(__file__).resolve()), "--worker", "--engine-root", args.engine_root,
                   "--case", case, "--depth", args.depth, "--format", args.format, "--mode", args.mode,
                   "--warm-runs", str(args.warm_runs), "--parent-started", str(time.perf_counter())]
        if args.input_file:
            command += ["--input-file", str(Path(args.input_file).resolve())]
        if args.trace:
            command.append("--trace")
        if args.profile:
            command.append("--profile")
        if args.prewarm_models:
            command.append("--prewarm-models")
        started = time.perf_counter()
        process = subprocess.Popen(command, cwd=REPOSITORY, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, encoding="utf-8", errors="replace")
        try:
            stdout, stderr = process.communicate(timeout=args.timeout)
        except (subprocess.TimeoutExpired, KeyboardInterrupt):
            # Bound the full benchmark-owned process tree, including local
            # LanguageTool Java. Never leave it behind if a run exceeds budget.
            descendants = psutil.Process(process.pid).children(recursive=True)
            for child in descendants:
                try:
                    child.kill()
                except psutil.NoSuchProcess:
                    pass
            process.kill()
            process.communicate()
            psutil.wait_procs(descendants, timeout=5)
            raise
        if process.returncode:
            raise RuntimeError(f"Benchmark {case} failed: {stderr[-5000:]}\n{stdout[-1000:]}")
        record = json.loads(stdout.strip().splitlines()[-1])
        record["subprocess_total_seconds_including_all_runs_and_evaluation"] = time.perf_counter() - started
        report["cases"].append(record)
        output_path.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
        print(json.dumps({"case": case, "import_seconds": record["cli_import_seconds"], "runs": [{key: run[key] for key in ("state", "summarize_seconds", "candidate_count", "summary_word_count")} for run in record["runs"]]}), flush=True)
    print(str(output_path.resolve()), flush=True)


if __name__ == "__main__":
    main()
