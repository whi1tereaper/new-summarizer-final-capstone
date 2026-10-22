"""Before/after engine comparison on a frozen corpus.

Two stages, so both engines are judged by exactly the same scorer:

  run    Summarize every corpus document with ONE engine in this process and
         write raw outputs + timings + peak memory to JSON.
         Usage: python compare_engines.py run --engine baseline --out base.json
                python compare_engines.py run --engine current  --out curr.json

  score  Score two run files with identical metrics and write a report.
         Usage: python compare_engines.py score base.json curr.json --out report.json

Run each engine in its own process: both packages are named summarizer_core,
and peak memory must be attributed to one engine only.

Metrics are deterministic proxies. They do NOT replace blind human review:
key-unit coverage depends on annotation wording, and lexical fact checks can
miss paraphrased distortions.
"""

from __future__ import annotations

import sys
from pathlib import Path

# Remove script directory from sys.path so 'import statistics' imports the standard library
# rather than colliding with evaluation/statistics.py.
_script_dir = str(Path(__file__).resolve().parent)
if sys.path and sys.path[0] == _script_dir:
    sys.path.pop(0)

import argparse
import hashlib
import json
import os
import re
import statistics
import time
from typing import Any

ENGINE_ROOT = Path(__file__).resolve().parents[1]
ENGINE_PATHS = {
    "baseline": ENGINE_ROOT / "evaluation" / "baseline_snapshot",
    "current": ENGINE_ROOT,
    "light_v2": ENGINE_ROOT,
}
DEFAULT_CORPUS = ENGINE_ROOT / "evaluation" / "corpus" / "heldout_documents.json"
DEPTHS = ("brief", "balanced", "detailed")


def _peak_memory_mb() -> float | None:
    try:
        import psutil

        info = psutil.Process().memory_info()
        peak = getattr(info, "peak_wset", None) or getattr(info, "rss", 0)
        return round(peak / (1024 * 1024), 1)
    except Exception:
        return None


def _load_corpus(path: Path, visited: set[Path] | None = None) -> list[dict[str, Any]]:
    resolved_path = path.resolve()
    visited = set() if visited is None else visited
    if resolved_path in visited:
        raise ValueError(f"Corpus inclusion cycle detected at {resolved_path}")
    visited.add(resolved_path)
    payload = json.loads(path.read_text(encoding="utf-8"))
    documents = payload.get("documents")
    if not isinstance(documents, list) or not documents:
        documents = []
    for include in payload.get("include_corpora", []):
        if not isinstance(include, dict) or not isinstance(include.get("path"), str):
            raise ValueError(f"Invalid included corpus entry in {path}")
        included_path = (path.parent / include["path"]).resolve()
        included = _load_corpus(included_path, visited)
        selected_ids = include.get("ids")
        if selected_ids is not None:
            if not isinstance(selected_ids, list) or any(not isinstance(item, str) for item in selected_ids):
                raise ValueError(f"Invalid included document IDs in {path}")
            included_by_id = {str(document.get("id", "")): document for document in included}
            missing_ids = set(selected_ids) - included_by_id.keys()
            if missing_ids:
                raise ValueError(f"Included documents not found in {included_path}: {sorted(missing_ids)}")
            included = [included_by_id[document_id] for document_id in selected_ids]
        documents.extend(included)
    visited.remove(resolved_path)
    if not documents:
        raise ValueError(f"No documents in corpus {path}")
    document_ids = [str(document.get("id", "")) for document in documents]
    if any(not document_id for document_id in document_ids) or len(set(document_ids)) != len(document_ids):
        raise ValueError(f"Corpus document IDs must be non-empty and unique: {path}")
    for document in documents:
        source_text = document.get("source_text")
        if not isinstance(source_text, str) or not source_text.strip():
            raise ValueError(f"Document has no source text: {document.get('id', '')}")
        document["source_sha256"] = hashlib.sha256(source_text.encode("utf-8")).hexdigest()
    return documents


def run_engine(
    engine: str,
    corpus: Path,
    out: Path,
    reference_run_path: Path | None = None,
) -> None:
    sys.path.insert(0, str(ENGINE_PATHS[engine]))
    reference_budgets: dict[tuple[str, str], int] = {}
    if reference_run_path is not None:
        reference_run = json.loads(reference_run_path.read_text(encoding="utf-8"))
        if engine != "light_v2" or reference_run.get("engine") != "baseline":
            raise ValueError("--reference-run is supported only for light_v2 against baseline output.")
        for row in reference_run.get("rows", []):
            key = (str(row.get("id", "")), str(row.get("depth", "")))
            reference_budgets[key] = len(" ".join(row.get("sentences", [])).split())
    if engine == "light_v2":
        from light_engine import summarize

        loaded_from = Path(sys.modules["light_engine"].__file__).resolve()
        pipeline = None
    else:
        from summarizer_core.models import SummarizationRequest
        from summarizer_core.pipeline import SummarizationPipeline

        loaded_from = Path(sys.modules["summarizer_core"].__file__).resolve()
        pipeline = SummarizationPipeline()
    if ENGINE_PATHS[engine].resolve() not in loaded_from.parents:
        raise RuntimeError(f"Wrong engine imported: {loaded_from}")

    rows = []
    for document in _load_corpus(corpus):
        for depth in DEPTHS:
            started = time.perf_counter()
            error = ""
            sentences: list[str] = []
            meta: dict[str, Any] = {}
            target_word_budget = reference_budgets.get((document["id"], depth), 0)
            if reference_run_path is not None and (document["id"], depth) not in reference_budgets:
                raise ValueError(f"Reference run has no row for {document['id']} {depth}.")
            try:
                if engine == "light_v2":
                    request = {
                        "schema_version": 1,
                        "text": document["source_text"],
                        "analysis_mode": document.get("profile", "general"),
                        "summary_depth": depth,
                        "output_format": "paragraph",
                        "sentence_count": 15,
                    }
                    if target_word_budget > 0:
                        request["target_word_budget"] = target_word_budget
                    result = summarize(request)
                    sentences = list(result["sentences"])
                    meta = {
                        "quality_selection": result["source_metadata"].get("quality_selection"),
                        "semantic_model_status": result["source_metadata"].get("semantic_model_status"),
                        "grammar_diagnostics": result["source_metadata"].get("grammar_diagnostics"),
                    }
                else:
                    request = SummarizationRequest(
                        text=document["source_text"],
                        analysis_mode=document.get("profile", "general"),
                        summary_length=depth,
                        output_format="paragraph",
                    )
                    result = pipeline.summarize(request)
                    sentences = list(result.sentences)
                    meta = {
                        "quality_selection": result.source_metadata.get("quality_selection"),
                        "grammar_diagnostics": result.source_metadata.get("grammar_diagnostics"),
                        "semantic_model_status": result.source_metadata.get("semantic_model_status"),
                    }
            except Exception as exc:  # recorded as a failure, never hidden
                error = f"{type(exc).__name__}: {exc}"
            rows.append({
                "id": document["id"],
                "depth": depth,
                "seconds": round(time.perf_counter() - started, 3),
                "sentences": sentences,
                "error": error,
                "target_word_budget": target_word_budget,
                "meta": meta,
            })
            print(f"[{engine}] {document['id']} {depth}: {rows[-1]['seconds']}s {len(sentences)} sentences {error}", flush=True)

    out.write_text(json.dumps({
        "engine": engine,
        "engine_path": str(loaded_from.parent),
        "corpus": str(corpus),
        "peak_memory_mb": _peak_memory_mb(),
        "rows": rows,
    }, indent=1, ensure_ascii=False), encoding="utf-8")


# --------------------------------------------------------------------------
# Scoring
# --------------------------------------------------------------------------

def _unit_covered(sentences: list[str], all_of: list[str]) -> bool:
    for sentence in sentences:
        low = sentence.lower()
        if all(re.search(r"(?<!\w)" + re.escape(token.lower()), low) for token in all_of):
            return True
    return False


_OPENER = re.compile(
    r"^\s*(?:(?:this|these|those|such)\s+(?:finding|result|difference|effect|approach|change|data)s?\b|"
    r"(?:it|they|this|these)\s+(?:was|were|is|are|has|have|showed|suggests?)\b|"
    r"(?:however|therefore|thus|moreover|furthermore|consequently)\b)",
    re.I,
)


class _Scorer:
    def __init__(self, use_languagetool: bool) -> None:
        sys.path.insert(0, str(ENGINE_ROOT))
        from evaluation.facts import check_facts, factual_consistency
        from evaluation.metrics import redundancy

        self.check_facts = check_facts
        self.factual_consistency = factual_consistency
        self.redundancy = redundancy
        self.encoder = None
        model_dir = os.environ.get("SUMMARIZER_LOCAL_EMBEDDING_MODEL") or str(
            ENGINE_ROOT.parent / "storage" / "models" / "all-MiniLM-L6-v2"
        )
        try:
            from sentence_transformers import SentenceTransformer

            self.encoder = SentenceTransformer(model_dir, device="cpu", local_files_only=True)
        except Exception as exc:
            print(f"semantic coverage unavailable: {exc}")
        self.tool = None
        if use_languagetool:
            try:
                from summarizer_core.grammar_check import LocalGrammarChecker

                checker = LocalGrammarChecker.from_environment()
                self.tool = checker if checker.available else None
                if self.tool is None:
                    print(f"LanguageTool unavailable: {checker.unavailable_reason}")
            except Exception as exc:
                print(f"LanguageTool unavailable: {exc}")

    def semantic_coverage(self, source_sentences: list[str], summary: list[str]) -> float | None:
        """Mean over source sentences of max cosine similarity to any summary sentence."""
        if self.encoder is None or not summary or not source_sentences:
            return None
        import numpy as np

        src = self.encoder.encode(source_sentences, normalize_embeddings=True, show_progress_bar=False)
        out = self.encoder.encode(summary, normalize_embeddings=True, show_progress_bar=False)
        return float(np.mean(np.max(src @ out.T, axis=1)))

    def semantic_redundancy(self, summary: list[str]) -> float | None:
        """Highest cosine similarity between any two summary sentences."""
        if self.encoder is None or len(summary) < 2:
            return None
        import numpy as np

        vectors = self.encoder.encode(summary, normalize_embeddings=True, show_progress_bar=False)
        similarity = vectors @ vectors.T
        np.fill_diagonal(similarity, -1.0)
        return float(similarity.max())

    def score_row(self, document: dict[str, Any], row: dict[str, Any]) -> dict[str, Any]:
        from evaluation.text import sentences as split_sentences

        summary = row["sentences"]
        text = " ".join(summary)
        if row["error"] or not summary:
            return {"failed": True}
        source_facts = self.check_facts(document["source_text"], text)
        has_curated_annotations = bool(document.get("critical_facts"))
        annotated_facts = (
            self.check_facts(
                document["source_text"],
                text,
                document["critical_facts"],
            )
            if has_curated_annotations
            else None
        )
        facts = annotated_facts if annotated_facts is not None else source_facts
        consistency = self.factual_consistency(document["source_text"], text, source_facts)
        units = document.get("key_units", [])
        covered = [unit["id"] for unit in units if _unit_covered(summary, unit["all_of"])]
        prohibited = [p for p in document.get("prohibited", []) if p.lower() in text.lower()]
        grammar_issues = None
        if self.tool is not None:
            grammar_issues = sum(len(self.tool.check(sentence)) for sentence in summary)
        source_sentences = [s for s in split_sentences(document["source_text"]) if len(s.split()) >= 4]
        return {
            "failed": False,
            "words": len(text.split()),
            "sentences": len(summary),
            "key_unit_coverage": round(len(covered) / len(units), 3) if units else None,
            "key_units_missed": [u["id"] for u in units if u["id"] not in covered],
            "fact_preservation_rate": facts["preservation_rate"],
            "facts_altered_or_contradicted": facts["altered_facts"] + facts["suspected_contradictions"],
            "source_extracted_fact_preservation_rate": source_facts["preservation_rate"],
            "source_extracted_fact_count": source_facts["total_critical_facts"],
            "source_extracted_fact_failures": (
                source_facts["missing_facts"]
                + source_facts["altered_facts"]
                + source_facts["suspected_contradictions"]
            ),
            "critical_fact_audit": {
                "source_extracted": source_facts,
                "curated_annotations": annotated_facts,
                "curated_annotations_present": has_curated_annotations,
            },
            "consistency_warning_sentences": len(consistency["warnings"]),
            "consistency_warnings": consistency["warnings"],
            "prohibited_hits": prohibited,
            "redundancy_pairs": self.redundancy(text)["duplicate_count"] + self.redundancy(text)["near_duplicate_count"],
            "semantic_redundancy_max": self.semantic_redundancy(summary),
            "dangling_opener": bool(_OPENER.search(summary[0])),
            "languagetool_issues": grammar_issues,
            "semantic_coverage": self.semantic_coverage(source_sentences, summary),
            "seconds": row["seconds"],
        }


def _mean(values: list[Any]) -> float | None:
    numbers = [v for v in values if isinstance(v, (int, float)) and not isinstance(v, bool)]
    return round(statistics.fmean(numbers), 4) if numbers else None


def score(base_path: Path, curr_path: Path, out: Path, use_languagetool: bool) -> None:
    base = json.loads(base_path.read_text(encoding="utf-8"))
    curr = json.loads(curr_path.read_text(encoding="utf-8"))
    corpus = {d["id"]: d for d in _load_corpus(Path(curr["corpus"]))}
    scorer = _Scorer(use_languagetool)
    try:
        per_row = []
        for b_row, c_row in zip(base["rows"], curr["rows"], strict=True):
            if (b_row["id"], b_row["depth"]) != (c_row["id"], c_row["depth"]):
                raise ValueError("Run files are not aligned; regenerate both.")
            document = corpus[b_row["id"]]
            per_row.append({
                "id": b_row["id"],
                "category": document.get("category", "unspecified"),
                "title": document.get("title", document["id"]),
                "source_url": document.get("source_url", ""),
                "source_provenance": document.get("source_provenance", ""),
                "source_sha256": document.get("source_sha256", ""),
                "depth": b_row["depth"],
                "legacy_word_count_target": len(" ".join(b_row["sentences"]).split()),
                "v2_requested_word_budget": c_row.get("target_word_budget", 0),
                "baseline": scorer.score_row(document, b_row),
                "current": scorer.score_row(document, c_row),
                "baseline_summary": b_row["sentences"],
                "current_summary": c_row["sentences"],
                "baseline_error": b_row["error"],
                "current_error": c_row["error"],
                "baseline_semantic_model_status": (b_row.get("meta") or {}).get("semantic_model_status"),
                "current_semantic_model_status": (c_row.get("meta") or {}).get("semantic_model_status"),
                "current_quality_selection": (c_row.get("meta") or {}).get("quality_selection"),
            })
    finally:
        if scorer.tool is not None:
            scorer.tool.close()

    metric_keys = (
        "key_unit_coverage", "fact_preservation_rate", "facts_altered_or_contradicted",
        "consistency_warning_sentences", "redundancy_pairs", "semantic_redundancy_max", "languagetool_issues",
        "semantic_coverage", "source_extracted_fact_preservation_rate",
        "source_extracted_fact_count", "source_extracted_fact_failures",
        "words", "sentences", "seconds",
    )
    aggregate = {}
    for engine in ("baseline", "current"):
        rows = [r[engine] for r in per_row]
        ok = [r for r in rows if not r.get("failed")]
        aggregate[engine] = {key: _mean([r.get(key) for r in ok]) for key in metric_keys}
        aggregate[engine]["failures"] = len(rows) - len(ok)
        aggregate[engine]["prohibited_hits"] = sum(len(r.get("prohibited_hits", [])) for r in ok)
        aggregate[engine]["dangling_openers"] = sum(1 for r in ok if r.get("dangling_opener"))
        aggregate[engine]["peak_memory_mb"] = (base if engine == "baseline" else curr)["peak_memory_mb"]
    comparable_rows = [
        row for row in per_row
        if not row["baseline"].get("failed") and not row["current"].get("failed")
    ]
    length_differences = [
        abs(row["baseline"]["words"] - row["current"]["words"])
        for row in comparable_rows
    ]
    report_length_comparability = {
        "matched_successful_outputs": len(comparable_rows),
        "mean_absolute_word_count_difference": _mean(length_differences),
        "outputs_within_10_percent_of_legacy_length": sum(
            abs(row["baseline"]["words"] - row["current"]["words"])
            <= 0.1 * row["legacy_word_count_target"]
            for row in comparable_rows
            if row["legacy_word_count_target"] > 0
        ),
        "legacy_target_budget_source": "legacy output whitespace-token count",
        "v2_budgeting_note": "V2 target_word_budget is a cap; whole extractive sentences can underfill it.",
    }
    fact_audit_totals = {}
    for engine in ("baseline", "current"):
        source_statuses: dict[str, int] = {}
        annotated_statuses: dict[str, int] = {}
        for row in per_row:
            if row[engine].get("failed"):
                continue
            audit = row[engine]["critical_fact_audit"]
            for fact in audit["source_extracted"]["facts"]:
                status = str(fact["status"])
                source_statuses[status] = source_statuses.get(status, 0) + 1
            curated = audit.get("curated_annotations")
            if curated is not None:
                for fact in curated["facts"]:
                    status = str(fact["status"])
                    annotated_statuses[status] = annotated_statuses.get(status, 0) + 1
        fact_audit_totals[engine] = {
            "source_extracted_facts": {
                "total": sum(source_statuses.values()),
                "counts_by_status": source_statuses,
                "pooled_preservation_percent": round(
                    100 * source_statuses.get("preserved", 0) / max(1, sum(source_statuses.values())),
                    4,
                ),
            },
            "engineering_curated_critical_facts": {
                "total": sum(annotated_statuses.values()),
                "counts_by_status": annotated_statuses,
                "pooled_preservation_percent": round(
                    100 * annotated_statuses.get("preserved", 0) / max(1, sum(annotated_statuses.values())),
                    4,
                ),
            },
        }
    wins = {"coverage": [0, 0, 0], "semantic": [0, 0, 0]}
    article_categories = {"news", "research", "essay"}
    for r in per_row:
        for name, key in (("coverage", "key_unit_coverage"), ("semantic", "semantic_coverage")):
            b, c = r["baseline"].get(key), r["current"].get(key)
            if b is None or c is None:
                continue
            wins[name][0 if c > b + 1e-9 else 1 if c < b - 1e-9 else 2] += 1
    article_wins = {"coverage": [0, 0, 0], "semantic": [0, 0, 0]}
    for row in per_row:
        if row["category"] not in article_categories:
            continue
        for name, key in (("coverage", "key_unit_coverage"), ("semantic", "semantic_coverage")):
            baseline_value = row["baseline"].get(key)
            current_value = row["current"].get(key)
            if baseline_value is None or current_value is None:
                continue
            article_wins[name][
                0 if current_value > baseline_value + 1e-9
                else 1 if current_value < baseline_value - 1e-9
                else 2
            ] += 1
    article_first_aggregate = {}
    for engine in ("baseline", "current"):
        article_rows = [
            row[engine] for row in per_row
            if row["category"] in article_categories and not row[engine].get("failed")
        ]
        other_rows = [
            row[engine] for row in per_row
            if row["category"] not in article_categories and not row[engine].get("failed")
        ]
        article_first_aggregate[engine] = {}
        for key in metric_keys:
            article_mean = _mean([row.get(key) for row in article_rows])
            other_mean = _mean([row.get(key) for row in other_rows])
            article_first_aggregate[engine][key] = (
                round(0.7 * article_mean + 0.3 * other_mean, 4)
                if article_mean is not None and other_mean is not None
                else None
            )
        article_first_aggregate[engine]["article_weight"] = 0.7
        article_first_aggregate[engine]["non_article_weight"] = 0.3
    report = {
        "systems": {
            "baseline": base.get("engine", "baseline"),
            "current": curr.get("engine", "current"),
        },
        "engine_paths": {
            "baseline": base.get("engine_path", ""),
            "current": curr.get("engine_path", ""),
        },
        "dataset": {
            "version": json.loads(Path(curr["corpus"]).read_text(encoding="utf-8")).get("dataset_version"),
            "split": json.loads(Path(curr["corpus"]).read_text(encoding="utf-8")).get("dataset_split"),
            "documents": len(corpus),
            "source_manifest_sha256": hashlib.sha256(
                json.dumps(
                    sorted((document_id, document.get("source_sha256", "")) for document_id, document in corpus.items()),
                    separators=(",", ":"),
                ).encode("utf-8")
            ).hexdigest(),
            "categories": {
                category: sum(1 for document in corpus.values() if document.get("category", "unspecified") == category)
                for category in sorted({document.get("category", "unspecified") for document in corpus.values()})
            },
        },
        "human_review": {
            "status": "not_performed",
            "required": True,
            "note": "Automatic fact and consistency screens are not a substitute for blinded human ratings or output-specific source verification.",
        },
        "source_grounding": {
            "method": "all heuristic source-extracted critical facts plus explicitly annotated critical facts, aligned against each summary",
            "fact_checker_version": "source-aligned-lexical-v1",
            "human_verified": False,
            "warning": "Fact alignment is incomplete and can miss paraphrases or misalign similar claims; every status requires reviewer verification.",
        },
        "aggregate": aggregate,
        "length_comparability": report_length_comparability,
        "fact_audit_totals": fact_audit_totals,
        "per_row_wins_current_vs_baseline": {k: {"current_better": v[0], "baseline_better": v[1], "tie": v[2]} for k, v in wins.items()},
        "article_first": {
            "article_categories": sorted(article_categories),
            "weighted_aggregate": article_first_aggregate,
            "per_row_wins_current_vs_baseline": {
                key: {"current_better": value[0], "baseline_better": value[1], "tie": value[2]}
                for key, value in article_wins.items()
            },
            "limitation": "Weighted aggregation is exploratory and does not compensate for a small, single-publisher convenience sample.",
        },
        "rows": per_row,
        "failures": [
            {
                "id": row["id"],
                "depth": row["depth"],
                "baseline_error": row["baseline_error"],
                "current_error": row["current_error"],
            }
            for row in per_row
            if row["baseline_error"] or row["current_error"]
        ],
        "limitations": [
            str(json.loads(Path(curr["corpus"]).read_text(encoding="utf-8")).get("purpose", "Corpus purpose not supplied.")),
            "No blind human review was performed.",
            "Key-unit coverage is a lexical proxy tied to annotation wording.",
            "Fact checks are lexical alignment screens, not entailment.",
        ],
    }
    out.write_text(json.dumps(report, indent=1, ensure_ascii=False), encoding="utf-8")
    print(json.dumps({"aggregate": aggregate, "wins": report["per_row_wins_current_vs_baseline"]}, indent=1))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="command", required=True)
    run_parser = sub.add_parser("run")
    run_parser.add_argument("--engine", choices=sorted(ENGINE_PATHS), required=True)
    run_parser.add_argument("--corpus", type=Path, default=DEFAULT_CORPUS)
    run_parser.add_argument("--out", type=Path, required=True)
    run_parser.add_argument("--reference-run", type=Path)
    score_parser = sub.add_parser("score")
    score_parser.add_argument("baseline", type=Path)
    score_parser.add_argument("current", type=Path)
    score_parser.add_argument("--out", type=Path, required=True)
    score_parser.add_argument("--no-languagetool", action="store_true")
    args = parser.parse_args()
    if args.command == "run":
        run_engine(args.engine, args.corpus, args.out, args.reference_run)
    else:
        score(args.baseline, args.current, args.out, not args.no_languagetool)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
