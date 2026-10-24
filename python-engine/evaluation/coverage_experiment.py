"""Development-only parameter search, fixed-budget comparisons and ablations."""
from __future__ import annotations
import argparse
from dataclasses import replace
from hashlib import sha256
from importlib.metadata import version, PackageNotFoundError
import itertools
import json
import os
from pathlib import Path
import platform
import subprocess
import sys
import threading
from time import perf_counter

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
sys.path = [p for p in sys.path if p != str(Path(__file__).resolve().parent)]
from evaluation.coverage_corpus import development, heldout
from evaluation.article_engine_experiment import quality_documents
from article_engine.coverage import analyze, article_sentences, parameters, DEFAULT_PARAMETERS
from article_engine.selection import select
from article_engine.validation import evidence_bundle, validate_selected
from article_engine.preprocessing import TOKEN

DEPTHS = {"very_brief": (40, 2), "brief": (65, 4), "short": (95, 6),
          "balanced": (140, 10), "detailed": (190, 18), "comprehensive": (240, 32)}
GROUPS = [dict(lexical=.25, topic=.15, section=.05, role=.10, quantity=.30, entity=.05, qualification=.10),
          dict(lexical=.40, topic=.20, section=.10, role=.10, quantity=.10, entity=.05, qualification=.05),
          dict(lexical=.15, topic=.10, section=.10, role=.15, quantity=.30, entity=.05, qualification=.15)]


def matches(value, tokens):
    return all(str(token).casefold() in value.casefold() for token in tokens)


def capped(values, budget):
    out, used = [], 0
    for value in values:
        cost = len(TOKEN.findall(value))
        if used + cost > budget:
            break
        out.append(value)
        used += cost
    return out


def metrics(doc, values, *, query=""):
    text = doc["source_text"]
    units = doc.get("key_units", [])
    records, _ = article_sentences(text)
    covered = [u["id"] for u in units if any(matches(s, u["all_of"]) for s in values)]
    attainable = [u["id"] for u in units if any(matches(s.text, u["all_of"]) for s in records)]
    facts = doc.get("critical_facts", [])
    joined = " ".join(values)
    indices = {s.index for s in records if s.text in values}
    context_errors = 0
    for index in indices:
        required = evidence_bundle(records, index)
        context_errors += required is None or not required.issubset(indices)
    unique = set()
    duplicate = 0
    precision_hits = 0
    for s in values:
        key = " ".join(s.casefold().split())
        duplicate += key in unique
        unique.add(key)
        precision_hits += any(matches(s, u["all_of"]) for u in units)
    focus = doc.get("focus", {}) if query else {}
    wanted = focus.get("units", [])
    signals = focus.get("evidence", [])
    return {"unit_covered": len(covered), "unit_count": len(units), "covered_units": covered,
            "attainable_unit_count": len(attainable), "missing_units": [u["id"] for u in units if u["id"] not in covered],
            "main_idea_preserved": bool(units and units[0]["id"] in covered),
            "fact_preserved": sum(f.casefold() in joined.casefold() for f in facts), "fact_count": len(facts),
            "annotated_sentence_precision": precision_hits / len(values) if values else 0,
            "source_exact_count": sum(s in text for s in values), "sentence_count": len(values),
            "context_dependency_errors": context_errors, "duplicate_count": duplicate,
            "word_count": len(TOKEN.findall(joined)),
            "compression": 1-len(TOKEN.findall(joined))/max(1,len(TOKEN.findall(text))),
            "focus_unit_covered": sum(u in covered for u in wanted), "focus_unit_count": len(wanted),
            "focus_relevant_sentences": sum(any(signal.casefold() in s.casefold() for signal in signals) for s in values),
            "focus_sentence_count": len(values) if query else 0}


def reweight(analysis, config):
    weights = analysis.concept_weights.copy()
    for group in GROUPS[0]:
        mask = [i for i, name in enumerate(analysis.concepts) if name.startswith(group + ":")]
        if mask:
            weights[mask] *= config[group] / max(float(weights[mask].sum()), 1e-12)
    weights /= max(float(weights.sum()), 1e-12)
    return replace(analysis, concept_weights=weights)


def tune():
    docs = development()
    cached = []
    for d in docs:
        records, _ = article_sentences(d["source_text"])
        cached.append((d, records, analyze(records, enhanced=False, config=DEFAULT_PARAMETERS)))
    trials = []
    for group, exponent, lam, mix in itertools.product(GROUPS, [0, .5, 1], [.85, .95, 1], [0, .15]):
        config = {**DEFAULT_PARAMETERS, **group, "lambda": lam, "cost_power": exponent, "rank_mix": mix}
        unit_hits = unit_total = main_hits = cases = context_errors = words = 0
        for d, records, base in cached:
            analysis = reweight(base, config)
            for depth in ["brief", "balanced", "comprehensive"]:
                budget, count = DEPTHS[depth]
                chosen, _, _ = select(records, analysis, count, budget, d.get("profile", "general"), "", config=config)
                values = [records[i].text for i in chosen]
                m = metrics(d, values)
                unit_hits += m["unit_covered"]
                unit_total += m["unit_count"]
                main_hits += m["main_idea_preserved"]
                context_errors += m["context_dependency_errors"]
                words += m["word_count"]
                cases += 1
        trials.append({"parameters": config, "unit_hits": unit_hits, "unit_total": unit_total,
                       "main_hits": main_hits, "cases": cases, "context_errors": context_errors, "words": words})
    def key(row):
        return (-row["context_errors"], row["unit_hits"], row["main_hits"], -row["words"],
                row["parameters"]["lambda"], -row["parameters"]["rank_mix"])
    best = max(trials, key=key)
    frozen = {"selected_parameters": best["parameters"], "development_result": best,
              "mmr_comparison_parameters": max((t for t in trials if t["parameters"]["lambda"] < 1
                                                and t["parameters"]["rank_mix"] == 0), key=key)["parameters"],
              "objective": "min_context_errors_then_max_unit_coverage_then_main_idea_then_min_words; ties prefer less MMR/rank complexity",
              "split": "development_only", "development_ids": [d["id"] for d in docs],
              "development_sha256": sha256(json.dumps(docs, sort_keys=True).encode()).hexdigest(),
              "human_verified": False, "trials": trials}
    return frozen


class MemorySampler:
    def __init__(self):
        import psutil
        self.process = psutil.Process()
        self.stop = threading.Event()
        self.peak = self.process.memory_info().rss
        self.thread = threading.Thread(target=self.sample, daemon=True)

    def sample(self):
        while not self.stop.wait(.01):
            self.peak = max(self.peak, self.process.memory_info().rss)

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, *args):
        self.peak = max(self.peak, self.process.memory_info().rss)
        self.stop.set()
        self.thread.join()


def jobs():
    for split, docs in [("heldout", heldout()), ("historical", quality_documents())]:
        for d in docs:
            for depth, (budget, count) in DEPTHS.items():
                yield split, d, depth, budget, count, ""
            if d.get("focus"):
                yield split, d, "balanced", 140, 10, d["focus"]["query"]
                yield split, d, "balanced", 140, 10, "volcanic seismology on Jupiter"


def worker(system):
    from summarizer_core.models import SourceDocument
    from article_detector import detect_article
    from article_engine.summarizer import summarize
    from evaluation.article_v1_snapshot.summarizer import summarize as v1
    from article_engine.baseline import guide_lsa
    from local_cli import _summarize_request
    records = []
    for split, doc, depth, budget, count, query in jobs():
        payload = {"text": doc["source_text"], "summary_depth": depth, "summary_length": depth,
                   "sentence_count": count, "target_word_budget": budget, "output_format": "paragraph",
                   "analysis_mode": doc.get("profile", "general"), "focus_query": query,
                   "automatic_article_routing": False, "performance_profile": True}
        began = perf_counter()
        try:
            with MemorySampler() as memory:
                if system == "legacy":
                    result = _summarize_request(payload)
                elif system == "guide_lsa":
                    values = guide_lsa(doc["source_text"], count)
                    result = {"sentences": values, "plain_summary": " ".join(values)}
                elif system == "article_v1":
                    result = v1(payload, SourceDocument(doc["source_text"], "text"), detect_article(doc["source_text"]))
                else:
                    variant = {"improved_ranking": "ranking", "coverage": "coverage", "coverage_mmr": "coverage_mmr"}.get(system, "hybrid")
                    ablation = {system.removeprefix("no_"): True} if system.startswith("no_") else None
                    if system == "no_coverage":
                        variant = "ranking"
                    config = parameters()
                    if system == "coverage_mmr":
                        config = json.loads((ROOT / "article_engine/coverage_parameters.json").read_text())["mmr_comparison_parameters"]
                    result = summarize(payload, SourceDocument(doc["source_text"], "text"), detect_article(doc["source_text"]),
                                       variant=variant, enhanced=system == "hybrid_enhanced", ablation=ablation, config=config)
            raw = result["sentences"]
            values = capped(raw, budget)
            records.append({"id": doc["id"], "split": split, "depth": depth, "focus": query,
                            "system": system, "budget": budget, "sentences": values, "native_sentences": raw,
                            "metrics": metrics(doc, values, query=query), "native_metrics": metrics(doc, raw, query=query),
                            "focus_status": result.get("source_metadata", {}).get("article_analysis", {}).get("focus_status"),
                            "stage_timings": result.get("source_metadata", {}).get("article_analysis", {}).get("timings", {}),
                            "seconds": perf_counter()-began, "sampled_peak_rss_mb": memory.peak/1048576,
                            "error": None})
        except Exception as exc:
            records.append({"id": doc["id"], "split": split, "depth": depth, "focus": query,
                            "system": system, "budget": budget, "sentences": [], "metrics": metrics(doc, [], query=query),
                            "seconds": perf_counter()-began, "error": type(exc).__name__})
    return records


def aggregate(rows):
    totals = {}
    for r in rows:
        key = r["system"] + "/" + r["split"] + ("/unsupported_focus" if r["focus"] == "volcanic seismology on Jupiter" else "/focus" if r["focus"] else "/general")
        t = totals.setdefault(key, {"cases": 0, "failures": 0, "unit_hits": 0, "unit_total": 0,
                                     "main_hits": 0, "facts": 0, "fact_total": 0, "source_exact": 0,
                                     "sentences": 0, "context_errors": 0, "duplicates": 0,
                                     "words": 0, "seconds": 0, "peak_mb": 0})
        m = r["metrics"]
        for field, value in [("cases", 1), ("failures", bool(r["error"])), ("unit_hits", m["unit_covered"]),
                             ("unit_total", m["unit_count"]), ("main_hits", m["main_idea_preserved"]),
                             ("facts", m["fact_preserved"]), ("fact_total", m["fact_count"]),
                             ("source_exact", m["source_exact_count"]), ("sentences", m["sentence_count"]),
                             ("context_errors", m["context_dependency_errors"]), ("duplicates", m["duplicate_count"]),
                             ("words", m["word_count"]), ("seconds", r["seconds"])]:
            t[field] += value
        t["peak_mb"] = max(t["peak_mb"], r.get("sampled_peak_rss_mb", 0))
    return totals


def evaluate(systems):
    all_rows, processes = [], {}
    env = dict(os.environ, OPENBLAS_NUM_THREADS="1", OMP_NUM_THREADS="1", MKL_NUM_THREADS="1",
               PYTHONIOENCODING="utf-8", HF_HUB_OFFLINE="1", TOKENIZERS_PARALLELISM="false")
    for system in systems:
        start = perf_counter()
        run = subprocess.run([sys.executable, str(Path(__file__).resolve()), "--worker", system], input="",
                             capture_output=True, text=True, encoding="utf-8", env=env, timeout=600)
        if run.returncode:
            raise RuntimeError(f"{system} worker failed: {run.stderr[-1500:]}")
        rows = json.loads(run.stdout)
        all_rows.extend(rows)
        processes[system] = {"wall_seconds": perf_counter()-start, "cases": len(rows),
                             "stderr_tail": run.stderr[-800:]}
        print(f"finished {system}: {len(rows)} cases", file=sys.stderr, flush=True)
    versions = {}
    for name in ["numpy", "scipy", "scikit-learn", "nltk", "sumy", "spacy", "en_core_web_sm", "psutil"]:
        try:
            versions[name] = version(name)
        except PackageNotFoundError:
            versions[name] = "unavailable"
    inputs = {"development": development(), "heldout": heldout(), "historical": quality_documents()}
    return {"protocol": "equal_token_word_caps_with_raw_outputs_retained", "depths": DEPTHS,
            "inputs": inputs, "input_sha256": sha256(json.dumps(inputs, sort_keys=True).encode()).hexdigest(),
            "parameter_artifact": json.loads((ROOT / "article_engine/coverage_parameters.json").read_text()),
            "source_sha256": {str(p.relative_to(ROOT)): sha256(p.read_bytes()).hexdigest() for p in sorted((ROOT / "article_engine").glob("*.py"))},
            "python": platform.python_version(), "platform": platform.platform(), "versions": versions,
            "rows": all_rows, "aggregate": aggregate(all_rows), "processes": processes,
            "human_review": {"completed": False, "ratings": [], "independent_references": []},
            "limitations": ["Fresh heldout sources and annotations are synthetic engineering work, not human-verified real articles.",
                            "Historical examples were inspected and are not held out for this upgrade.",
                            "Common prefix capping can alter comparator context; native outputs/metrics are retained.",
                            "Literal annotation precision/recall, exact spans and context checks do not establish semantic factuality.",
                            "Memory includes process imports and reused models; 10-ms samples can miss transient peaks."]}


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--tune", action="store_true")
    parser.add_argument("--worker")
    parser.add_argument("--out", type=Path)
    parser.add_argument("--systems", default="legacy,guide_lsa,article_v1,improved_ranking,coverage,coverage_mmr,hybrid")
    args = parser.parse_args()
    if args.worker:
        print(json.dumps(worker(args.worker), ensure_ascii=False))
    else:
        data = tune() if args.tune else evaluate(args.systems.split(","))
        out = args.out or (ROOT / "article_engine/coverage_parameters.json" if args.tune else ROOT.parent / "docs/research/coverage-results.json")
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(data, indent=2, ensure_ascii=False)+"\n", encoding="utf-8")
        print(json.dumps(data["development_result"] if args.tune else data["aggregate"], indent=2))
