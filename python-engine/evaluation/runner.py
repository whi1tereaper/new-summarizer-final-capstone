"""One-item generation and evaluation protocol used by the offline PHP queue."""
from dataclasses import asdict, replace
from datetime import datetime, timezone
from functools import lru_cache
from hashlib import sha256
from importlib import metadata
import json
import os
from pathlib import Path
import platform
import subprocess
from time import perf_counter

from summarizer_core.models import PreprocessingOptions, SummarizationRequest
from summarizer_core.profiles import resolve_profile
from summarizer_core.pipeline import SummarizationPipeline
from summarizer_core.synthesis import synthesis_configuration

from . import EVALUATION_VERSION, SUMMARIZER_VERSION
from .baselines import BASELINE_VERSION, SYSTEMS, generate_baseline
from .engine import ABLATIONS, EvaluationPipeline
from .metrics import compute_metrics
from .text import TOKENIZER_VERSION, word_count

MODES = ("brief", "short", "balanced", "detailed", "comprehensive")
PROFILES = ("general", "general_bullets", "general_hybrid", "academic", "executive", "study", "technical", "news")
ROOT = Path(__file__).resolve().parents[2]


def canonical_hash(value):
    return sha256(json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":"), allow_nan=False).encode("utf-8")).hexdigest()


@lru_cache(maxsize=4)
def model_fingerprint(directory):
    """Hash local model assets once per worker; exclude arbitrary sibling files."""
    path = Path(directory)
    if not path.is_dir():
        return {"status": "unavailable"}
    files = {}
    for asset in sorted(path.rglob("*")):
        if asset.is_file() and asset.suffix.lower() in {".json", ".txt", ".model", ".safetensors", ".bin"}:
            digest = sha256()
            with asset.open("rb") as handle:
                for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                    digest.update(chunk)
            files[str(asset.relative_to(path)).replace("\\", "/")] = digest.hexdigest()
    return {"status": "ok", "sha256": canonical_hash(files), "files": files}


@lru_cache(maxsize=1)
def nlp_resource_provenance():
    import nltk
    resources = {}
    for name in ("tokenizers/punkt", "tokenizers/punkt_tab", "corpora/wordnet.zip", "corpora/stopwords", "taggers/averaged_perceptron_tagger", "taggers/averaged_perceptron_tagger_eng"):
        try:
            located = nltk.data.find(name)
            # ZipFilePathPointer exposes the containing archive separately.
            location = Path(str(located.zipfile.filename)) if hasattr(located, "zipfile") else Path(str(located))
            paths = sorted(path for path in location.rglob("*") if path.is_file()) if location.is_dir() else [location]
            hashes = {}
            for path in paths:
                digest = sha256()
                with path.open("rb") as handle:
                    for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                        digest.update(chunk)
                hashes[str(path.relative_to(location)) if location.is_dir() else location.name] = digest.hexdigest()
            resources[name] = {"available": True, "sha256": canonical_hash(hashes), "file_count": len(hashes)}
        except (LookupError, OSError):
            resources[name] = {"available": False}
    try:
        resources["spacy/en_core_web_sm"] = {"available": True, "package_version": metadata.version("en-core-web-sm")}
    except metadata.PackageNotFoundError:
        resources["spacy/en_core_web_sm"] = {"available": False}
    return resources


def _safe_environment():
    # Match the normal worker's explicit local retrieval choice without loading
    # translation credentials or enabling network model resolution.
    env_path = ROOT / ".env"
    if "SUMMARIZER_LOCAL_EMBEDDING_MODEL" not in os.environ and env_path.is_file():
        for line in env_path.read_text(encoding="utf-8").splitlines():
            key, separator, value = line.partition("=")
            if separator and key.strip() == "SUMMARIZER_LOCAL_EMBEDDING_MODEL":
                os.environ[key.strip()] = value.strip().strip("\"'")
    os.environ.setdefault("TOKENIZERS_PARALLELISM", "false")
    os.environ.setdefault("OMP_NUM_THREADS", "1")
    os.environ.setdefault("MKL_NUM_THREADS", "1")


@lru_cache(maxsize=1)
def provenance():
    engine = ROOT / "python-engine"
    paths = list((engine / "summarizer_core").glob("*.py")) + list((engine / "evaluation").glob("*.py"))
    paths += list(engine.glob("*.py"))
    files = {str(path.relative_to(ROOT)).replace("\\", "/"): sha256(path.read_bytes()).hexdigest() for path in sorted(paths) if path.is_file()}
    packages = {}
    for package in ("numpy", "scipy", "scikit-learn", "nltk", "spacy", "bert-score", "torch", "transformers", "sentence-transformers"):
        try:
            packages[package] = metadata.version(package)
        except metadata.PackageNotFoundError:
            packages[package] = None
    try:
        commit = subprocess.run(["git", "rev-parse", "HEAD"], cwd=ROOT, capture_output=True, text=True, timeout=5, check=True).stdout.strip()
    except (OSError, subprocess.SubprocessError):
        commit = None
    return {"code_hash": canonical_hash(files), "source_files": files, "git_commit": commit, "packages": packages, "python": platform.python_version(), "platform": platform.platform(), "tokenizer": TOKENIZER_VERSION, "nlp_resources": nlp_resource_provenance(), "nlp_resource_policy": "offline; existing deterministic fallback if a resource is missing"}


def capabilities():
    return {"evaluation_version": EVALUATION_VERSION, "summarizer_version": SUMMARIZER_VERSION, "modes": list(MODES), "standard_modes": ["brief", "balanced", "detailed", "comprehensive"], "profiles": list(PROFILES), "systems": list(SYSTEMS), "ablations": ABLATIONS, "metrics": ["rouge_1", "rouge_2", "rouge_l", "bertscore", "compression_ratio", "compression_percentage", "redundancy", "critical_fact_preservation", "factual_consistency", "content_unit_coverage", "challenge"], "limits": {"source_characters": 250000, "candidate_sentences": 2500, "references": 12, "reference_characters": 50000}, "warnings": ["Factual consistency and critical-fact screening are documented lexical heuristics, not factual accuracy.", "BERTScore requires an explicitly prepared local cache; inputs exceeding model context are unavailable.", "Content-unit coverage requires output-specific human verification."]}


def _validate(payload):
    if not isinstance(payload, dict):
        raise ValueError("Evaluation item must be an object.")
    source = payload.get("text", "")
    if not isinstance(source, str) or not source.strip() or len(source) > 250000:
        raise ValueError("Source text must contain 1–250000 characters.")
    mode, profile, system = payload.get("mode", "balanced"), payload.get("profile", "general"), payload.get("system", "proposed")
    if mode not in MODES or profile not in PROFILES or system not in SYSTEMS:
        raise ValueError("Unsupported mode, profile or system.")
    config = payload.get("config", {})
    if not isinstance(config, dict):
        raise ValueError("Configuration must be an object.")
    ablation = payload.get("ablation", config.get("ablation")) or None
    if ablation not in (None, *ABLATIONS) or (ablation and system != "proposed"):
        raise ValueError("Ablations apply only to the proposed system, one supported feature at a time.")
    references = payload.get("references", [])
    if not isinstance(references, list) or len(references) > 12:
        raise ValueError("References must be an array of at most 12 items.")
    for reference in references:
        if not isinstance(reference, dict) or not isinstance(reference.get("text", ""), str) or len(reference.get("text", "")) > 50000:
            raise ValueError("Invalid reference summary.")
    options = payload.get("options", {})
    if not isinstance(options, dict):
        raise ValueError("Metric options must be an object.")
    if "bertscore" in options and not isinstance(options["bertscore"], bool):
        raise ValueError("bertscore option must be boolean.")
    allowed_options = {key: options[key] for key in ("bertscore", "bertscore_model", "bertscore_num_layers", "seed") if key in options}
    allowed_options.setdefault("bertscore", False)
    return source, mode, profile, system, ablation, references, allowed_options


def evaluate_item(payload):
    started = perf_counter()
    response = {"status": "failed", "summary": "", "metrics": [], "warnings": [], "errors": [], "error": None, "evaluation_version": EVALUATION_VERSION}
    try:
        source, mode, profile, system, ablation, references, options = _validate(payload)
        _safe_environment()
        provenance_data = provenance()
        algorithm_version = SUMMARIZER_VERSION + "+" + provenance_data["code_hash"][:12]
        model_directory = os.environ.get("SUMMARIZER_LOCAL_EMBEDDING_MODEL", "").strip()
        synthesis_config = synthesis_configuration()
        configuration = {
            "system": system, "mode": mode, "profile": profile, "ablation": ablation,
            "ablation_description": ABLATIONS.get(ablation), "profile_weights": resolve_profile(profile).scoring_weights,
            "preprocessing": asdict(PreprocessingOptions()), "output_format": "paragraph", "metrics": options,
            "baseline_version": BASELINE_VERSION, "evaluation_version": EVALUATION_VERSION,
            "summarizer_code_hash": provenance_data["code_hash"], "packages": provenance_data["packages"],
            "nlp_resources": provenance_data["nlp_resources"],
            "local_embedding_model": Path(model_directory).name if model_directory else None,
            "local_embedding_fingerprint": model_fingerprint(model_directory) if model_directory else None,
            "title": str(payload.get("title", ""))[:1000], "budget_policy": "match proposed extractive output word count using a closest ranked whole-sentence prefix for baselines and a capped synthesis budget for hybrid_llm",
            "synthesis": {"requested": system == "hybrid_llm", **synthesis_config},
        }
        response.update(configuration=configuration, summarizer_version=algorithm_version, provenance=provenance_data, generated_at=datetime.now(timezone.utc).isoformat(), source_sha256=sha256(source.encode("utf-8")).hexdigest())
        request = SummarizationRequest(text=source, document_title=configuration["title"], analysis_mode=profile, selection_mode=profile, summary_depth=mode, summary_length=mode, output_format="paragraph")
        target = payload.get("target_words", payload.get("target_word_budget"))
        if target is not None and (isinstance(target, bool) or not isinstance(target, int) or not 1 <= target <= 50000):
            raise ValueError("Word target must be a positive integer no greater than 50000.")
        request = replace(request, target_word_budget=target or 0)
        calibration_seconds = 0.0
        baseline_details = None
        if system == "proposed":
            if ablation and target is None:
                calibration_started = perf_counter()
                full_result = EvaluationPipeline().summarize(request)
                target = word_count(full_result.plain_summary)
                calibration_seconds = perf_counter() - calibration_started
            pipeline = EvaluationPipeline(ablation)
            result = pipeline.summarize(request)
            summary = result.plain_summary
            timings = dict(pipeline.timings)
            # Ablations retain the original mode's dynamic word ceiling. Actual
            # lengths are recorded so researchers can stratify any imbalance.
            target = word_count(summary) if target is None else target
            response["warnings"].extend(result.validation_notes)
            response["engine_metadata"] = {"readability": result.readability, "retrieval": result.retrieval_metadata, "source": result.source_metadata}
        elif system == "hybrid_llm":
            if target is None:
                calibration_started = perf_counter()
                proposed = EvaluationPipeline().summarize(request)
                target = word_count(proposed.plain_summary)
                calibration_seconds = perf_counter() - calibration_started
            synthesis_started = perf_counter()
            pipeline = SummarizationPipeline()
            hybrid_request = replace(request, use_llm_synthesis=True, target_word_budget=target)
            result = pipeline.summarize(hybrid_request)
            synthesis = result.source_metadata.get("synthesis", {})
            if synthesis.get("status") != "accepted_heuristic_checks":
                raise RuntimeError("Hybrid language-model output was not accepted; no extractive output was substituted in evaluation.")
            summary = result.plain_summary
            timings = {
                "summarization_seconds": perf_counter() - synthesis_started,
                "budget_calibration_seconds": calibration_seconds,
            }
            response["warnings"].extend(result.validation_notes)
            response["engine_metadata"] = {
                "readability": result.readability,
                "retrieval": result.retrieval_metadata,
                "source": result.source_metadata,
                "synthesis": synthesis,
            }
        else:
            calibration_started = perf_counter()
            pipeline = EvaluationPipeline()
            proposed = pipeline.summarize(request)
            target = target if target is not None else word_count(proposed.plain_summary)
            calibration_seconds = perf_counter() - calibration_started
            baseline_started = perf_counter()
            summary, baseline_details = generate_baseline(pipeline.candidates, system, target)
            timings = {"preprocessing_seconds": 0.0, "summarization_seconds": perf_counter() - baseline_started, "postprocessing_seconds": 0.0}
            response["engine_metadata"] = {"source": proposed.source_metadata, "baseline": baseline_details, "timing_note": "Candidate preparation and proposed-output budget calibration are measured separately in budget_calibration_seconds."}
        if not summary.strip():
            raise ValueError("Summary generation returned no text.")
        configuration["target_words"] = target
        reported_origin = payload.get("target_budget_origin")
        if reported_origin not in {"proposed_output_actual_word_count", "explicit_input_word_count"}:
            reported_origin = (
                "explicit_input_word_count"
                if payload.get("target_words", payload.get("target_word_budget")) is not None
                else "proposed_extractive_actual_word_count"
                if system != "proposed"
                else "generated_proposed_output_word_count"
            )
        configuration["target_budget_origin"] = reported_origin
        response["configuration_hash"] = canonical_hash(configuration)
        metric_started = perf_counter()
        cases = payload.get("challenge_cases", [])
        if not isinstance(cases, list) or len(cases) > 100:
            raise ValueError("Challenge cases must be an array of at most100 items.")
        annotations = payload.get("critical_facts", [])
        if not annotations and cases:
            annotations = []
            for case in cases:
                if not isinstance(case, dict) or not isinstance(case.get("expected_facts", []), list):
                    raise ValueError("Invalid challenge case.")
                for index, fact in enumerate(case.get("expected_facts", [])):
                    annotation = dict(fact) if isinstance(fact, dict) else {"text": str(fact), "kind": "annotated"}
                    annotation.setdefault("id", f"case-{case.get('id')}-{index}")
                    annotations.append(annotation)
        records = compute_metrics(source, summary, references=references, annotations=annotations, content_units=payload.get("content_units", []), prohibited=payload.get("prohibited_distortions", []), options=options, cases=cases)
        timings.update(metrics_seconds=perf_counter() - metric_started, budget_calibration_seconds=calibration_seconds, total_seconds=perf_counter() - started)
        errors = [f"{record['name']}: {record['error']}" for record in records if record["status"] in ("error", "unavailable")]
        delta = word_count(summary) - target
        if target and abs(delta) / target > .15:
            response["warnings"].append("Actual word count differs by over 15% from the comparison budget; inspect length fairness before comparison.")
        response.update(status="completed_with_errors" if errors else "completed", summary=summary, word_count=word_count(summary), source_word_count=word_count(source), target_words=target, target_word_budget=target, budget_delta_words=delta, timings=timings, metrics=records, errors=errors, error=None)
    except Exception as exc:
        response.update(status="failed", error=f"{type(exc).__name__}: {exc}", errors=[f"{type(exc).__name__}: {exc}"], timings={"total_seconds": perf_counter() - started})
    return response
