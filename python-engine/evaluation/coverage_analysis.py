"""Post-hoc reporting only: never feeds held-out results into model parameters."""
from collections import defaultdict
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
sys.path = [p for p in sys.path if p != str(Path(__file__).resolve().parent)]
from evaluation.coverage_experiment import capped, metrics


def totals(rows, field="metrics"):
    result = {"cases": len(rows)}
    for key in ["unit_covered", "unit_count", "main_idea_preserved", "fact_preserved",
                "fact_count", "source_exact_count", "sentence_count", "context_dependency_errors",
                "duplicate_count", "word_count", "focus_unit_covered", "focus_unit_count",
                "focus_relevant_sentences", "focus_sentence_count"]:
        result[key] = sum(r[field][key] for r in rows)
    result["mean_annotated_sentence_precision"] = sum(r[field]["annotated_sentence_precision"] for r in rows) / max(1, len(rows))
    result["mean_compression"] = sum(r[field]["compression"] for r in rows) / max(1, len(rows))
    result["mean_seconds"] = sum(r["seconds"] for r in rows) / max(1, len(rows))
    result["sampled_peak_rss_mb"] = max((r.get("sampled_peak_rss_mb", 0) for r in rows), default=0)
    return result


def analyze(data):
    rows = data["rows"]
    systems = list(data["processes"])
    groups = defaultdict(list)
    for r in rows:
        if not r["focus"]:
            groups[r["split"], r["system"]].append(r)
    report = {"role": "Reporting only; parameter selection remains frozen development-only.",
              "general": {"/".join(k): totals(v) for k, v in groups.items()},
              "native_general": {"/".join(k): totals(v, "native_metrics") for k, v in groups.items()},
              "focus": {}, "per_article": [], "per_depth": [], "realized_length_sensitivity": [],
              "near_redundancy": {}}
    for system in systems:
        report["focus"][system] = totals([r for r in rows if r["system"] == system and r["focus"] and "Jupiter" not in r["focus"]])
    for split, docs in data["inputs"].items():
        if split == "development":
            continue
        for doc in docs:
            report["per_article"].append({"split": split, "id": doc["id"], "systems": {
                system: totals([r for r in groups[split, system] if r["id"] == doc["id"]]) for system in systems}})
        for depth in data["depths"]:
            report["per_depth"].append({"split": split, "depth": depth, "systems": {
                system: totals([r for r in groups[split, system] if r["depth"] == depth]) for system in systems}})
    # Same realized ceiling for each paired native output, not a re-optimized
    # baseline. Prefix removal can change context, so this is only sensitivity.
    docs = {d["id"]: d for d in data["inputs"]["heldout"]}
    pairs = {(r["id"], r["depth"], r["system"]): r for r in rows if r["split"] == "heldout" and not r["focus"]}
    for candidate in ["hybrid", "hybrid_enhanced"]:
        for reference in ["legacy", "article_v1"]:
            values = {candidate: [], reference: []}
            for article_id in docs:
                for depth in data["depths"]:
                    a, b = pairs[article_id, depth, candidate], pairs[article_id, depth, reference]
                    budget = min(a["native_metrics"]["word_count"], b["native_metrics"]["word_count"], a["budget"])
                    for r in [a, b]:
                        values[r["system"]].append({**r, "metrics": metrics(docs[article_id], capped(r["native_sentences"], budget))})
            report["realized_length_sensitivity"].append({"candidate": candidate, "reference": reference,
                                                        "totals": {k: totals(v) for k, v in values.items()}})
    from sklearn.feature_extraction.text import TfidfVectorizer
    for system in systems:
        relevant = groups["heldout", system]
        repeated = denominator = 0
        for r in relevant:
            sentences = r["sentences"]
            denominator += len(sentences)
            if len(sentences) < 2:
                continue
            matrix = TfidfVectorizer(stop_words="english").fit_transform(sentences)
            cosine = (matrix @ matrix.T).toarray()
            repeated += sum(any(cosine[i, j] >= .85 for j in range(i)) for i in range(len(sentences)))
        report["near_redundancy"][system] = {"sentences_above_threshold": repeated, "selected_sentences": denominator,
                                            "threshold": .85, "representation": "within-summary unigram TF-IDF cosine"}
    return report


if __name__ == "__main__":
    path = ROOT.parent / "docs/research/coverage-results.json"
    report = analyze(json.loads(path.read_text(encoding="utf-8")))
    out = path.with_name("coverage-analysis.json")
    out.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print("Wrote coverage-analysis.json (derived diagnostics; no tuning).")
