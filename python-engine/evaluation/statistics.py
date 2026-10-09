"""Offline, document-level evaluation statistics; no web or model dependencies.

References and assumptions are recorded in docs/evaluation-methodology.md.
Missing observations remain visible and are never replaced by zero scores.
"""
from __future__ import annotations

from collections import Counter, defaultdict
from itertools import combinations
import math
import random
from statistics import mean, median, stdev
from typing import Any, Iterable, Mapping, Sequence

VERSION = "evaluation-statistics/1.0.0"
DEFAULT_SEED = 20261002


def _number(value: Any) -> float | None:
    if value is None or isinstance(value, bool):
        return None
    try:
        numeric = float(value)
    except (TypeError, ValueError, OverflowError):
        return None
    return numeric if math.isfinite(numeric) else None


def _percentile(ordered: Sequence[float], fraction: float) -> float:
    location = (len(ordered) - 1) * fraction
    lower, upper = math.floor(location), math.ceil(location)
    return ordered[lower] + (ordered[upper] - ordered[lower]) * (location - lower)


def descriptive_statistics(
    values: Iterable[Any], *, attempted_n: int | None = None,
    cluster_ids: Iterable[Any] | None = None,
    seed: int = DEFAULT_SEED, bootstrap_samples: int = 2000,
) -> dict[str, Any]:
    """Equal-weight document means; resample whole documents, never rater rows.

    n_valid counts usable input observations; n_documents counts independent
    document clusters. SD, quantiles and the CI describe the document means.
    Without cluster IDs, each observation is a separate independent item.
    """
    raw = list(values)
    ids = list(cluster_ids) if cluster_ids is not None else list(range(len(raw)))
    if len(ids) != len(raw):
        raise ValueError("cluster_ids and values must have equal lengths")
    if attempted_n is None:
        attempted_n = len(raw)
    if attempted_n < len(raw):
        raise ValueError("attempted_n cannot be smaller than the supplied observations")
    if not 100 <= bootstrap_samples <= 20000:
        raise ValueError("bootstrap_samples must be between 100 and 20000")
    clusters: dict[str, list[float]] = defaultdict(list)
    valid_n = 0
    for key, value in zip(ids, raw):
        numeric = _number(value)
        if numeric is not None:
            if key is None:
                raise ValueError("A valid observation must have a document ID")
            clusters[str(key)].append(numeric)
            valid_n += 1
    document_means = [mean(clusters[key]) for key in sorted(clusters)]
    result: dict[str, Any] = {
        "n_attempted": attempted_n, "n_valid": valid_n,
        "n_missing": attempted_n - valid_n, "n_documents": len(document_means),
        "analysis_unit": "document mean", "mean": None, "median": None,
        "sd": None, "min": None, "max": None, "ci_95": None,
        "ci_method": "document-cluster percentile bootstrap of equal-weight document means",
        "bootstrap_samples": bootstrap_samples, "bootstrap_seed": seed,
        "status": "no_data", "warnings": [],
    }
    if not document_means:
        return result
    result.update(mean=mean(document_means), median=median(document_means),
                  min=min(document_means), max=max(document_means), status="ok")
    if len(document_means) >= 2:
        result["sd"] = stdev(document_means)
        rng = random.Random(seed)
        draws = sorted(mean(rng.choices(document_means, k=len(document_means)))
                       for _ in range(bootstrap_samples))
        result["ci_95"] = [_percentile(draws, 0.025), _percentile(draws, 0.975)]
    else:
        result["warnings"].append("A confidence interval and sample SD need at least two independent documents.")
    if len(document_means) < 10:
        result["warnings"].append("Small document sample; bootstrap interval stability and generalization are limited.")
    return result


def holm_adjust(pvalues: Sequence[float | None]) -> list[float | None]:
    """Holm step-down familywise correction; unavailable tests retain null."""
    valid = []
    for index, value in enumerate(pvalues):
        numeric = _number(value)
        if value is not None and (numeric is None or not 0 <= numeric <= 1):
            raise ValueError("p-values must be finite probabilities or null")
        if numeric is not None:
            valid.append((numeric, index))
    valid.sort()
    adjusted: list[float | None] = [None] * len(pvalues)
    previous = 0.0
    for rank, (pvalue, original_index) in enumerate(valid):
        previous = max(previous, min(1.0, (len(valid) - rank) * pvalue))
        adjusted[original_index] = previous
    return adjusted


def paired_comparison(
    left: Mapping[Any, Any], right: Mapping[Any, Any], *, seed: int = DEFAULT_SEED,
    bootstrap_samples: int = 2000, difference_decimals: int = 12,
) -> dict[str, Any]:
    """Two-sided Wilcoxon on document-paired differences, left minus right.

    Exact untied tests use SciPy; ties use its seeded sign-permutation path.
    Baseline and proposed outputs must already share a length budget and mode.
    """
    left_valid = {str(k): _number(v) for k, v in left.items() if _number(v) is not None}
    right_valid = {str(k): _number(v) for k, v in right.items() if _number(v) is not None}
    shared = sorted(set(left_valid) & set(right_valid))
    differences = [round(left_valid[k] - right_valid[k], difference_decimals) for k in shared]
    result: dict[str, Any] = {
        "status": "insufficient_data", "test": "Wilcoxon signed-rank, two-sided",
        "n_paired": len(shared),
        "n_candidate_documents": len({str(key) for key in left} | {str(key) for key in right}),
        "n_unpaired": len({str(key) for key in left} | {str(key) for key in right}) - len(shared),
        "n_left_valid": len(left_valid), "n_right_valid": len(right_valid),
        "n_nonzero_pairs": sum(value != 0 for value in differences),
        "statistic": None, "p_value": None, "p_adjusted": None,
        "effect_size": None, "effect_size_name": "matched-pairs rank-biserial correlation",
        "mean_difference": mean(differences) if differences else None,
        "median_difference": median(differences) if differences else None,
        "ci_95": None, "difference_direction": "left minus right",
        "difference_decimals": difference_decimals, "zero_method": "wilcox",
        "assumptions": ["Independent sampled documents", "Matched documents and comparable output budgets",
                        "Paired differences have an approximately symmetric distribution"],
        "warnings": [],
    }
    if len(shared) < 2:
        return result
    result["ci_95"] = descriptive_statistics(differences, seed=seed,
                                              bootstrap_samples=bootstrap_samples)["ci_95"]
    nonzero = [value for value in differences if value != 0]
    if not nonzero:
        result.update(status="no_difference", statistic=0.0, p_value=1.0, effect_size=0.0,
                      p_method="all paired differences are zero")
        return result
    try:
        import scipy
        from scipy.stats import PermutationMethod, rankdata, wilcoxon

        ranks = rankdata([abs(value) for value in nonzero], method="average")
        positive = sum(rank for rank, value in zip(ranks, nonzero) if value > 0)
        negative = sum(rank for rank, value in zip(ranks, nonzero) if value < 0)
        result["effect_size"] = float((positive - negative) / (positive + negative))
        tied = len({abs(value) for value in nonzero}) < len(nonzero)
        if not tied and len(nonzero) <= 50:
            test = wilcoxon(nonzero, alternative="two-sided", zero_method="wilcox", method="exact")
            result["p_method"] = "SciPy exact untied signed-rank distribution"
        else:
            # Bounded batches avoid allocating all sign permutations at once.
            method = PermutationMethod(n_resamples=9999, batch=128, random_state=seed)
            test = wilcoxon(nonzero, alternative="two-sided", zero_method="wilcox", method=method)
            result["p_method"] = "SciPy sign permutations: exhaustive when <=9999, otherwise 9999 seeded draws"
            result["permutation_seed"] = seed
        result.update(status="ok", statistic=float(test.statistic), p_value=float(test.pvalue),
                      scipy_version=scipy.__version__)
        if len(nonzero) < 10:
            result["warnings"].append("Few nonzero document pairs; inferential power and p-value resolution are limited.")
    except (ImportError, TypeError, ValueError) as exc:
        result.update(status="unavailable", error=f"Wilcoxon dependency/method unavailable: {exc}")
    return result


def krippendorff_alpha(ratings: Sequence[Sequence[Any]], *, level: str = "ordinal") -> dict[str, Any]:
    """Krippendorff alpha from item x rater scores, with pairable marginals.

    Ordinal distance is the squared cumulative marginal frequency between
    categories, less half their endpoint frequencies (Krippendorff, 2011).
    It is deliberately NOT the squared numeric Likert-score difference.
    """
    if level not in {"ordinal", "interval", "nominal"}:
        raise ValueError("level must be ordinal, interval or nominal")
    width = max((len(row) for row in ratings), default=0)
    rows: list[list[float]] = []
    observed_n = 0
    for row in ratings:
        clean = []
        for value in row:
            numeric = _number(value)
            if numeric is not None:
                clean.append(numeric)
        observed_n += len(clean)
        if len(clean) >= 2:
            rows.append(clean)
    result: dict[str, Any] = {
        "statistic": "Krippendorff alpha", "level": level, "value": None,
        "status": "insufficient_data", "n_raters": width, "n_items": len(ratings),
        "n_pairable_items": len(rows), "n_ratings": observed_n,
        "missing_ratings": len(ratings) * width - observed_n,
        "n_pairable_ratings": sum(map(len, rows)),
        "interpretation": "Chance-corrected agreement, not summary quality. Negative values are possible; constant ratings make alpha undefined.",
    }
    if len(rows) < 2:
        return result
    frequencies = Counter(value for row in rows for value in row)
    categories = sorted(frequencies)
    ranks = {value: index for index, value in enumerate(categories)}

    def distance(a: float, b: float) -> float:
        if a == b:
            return 0.0
        if level == "nominal":
            return 1.0
        if level == "interval":
            return (a - b) ** 2
        low, high = sorted((ranks[a], ranks[b]))
        cumulative = sum(frequencies[category] for category in categories[low:high + 1])
        return (cumulative - (frequencies[a] + frequencies[b]) / 2.0) ** 2

    total = sum(frequencies.values())
    observed = sum(sum(distance(a, b) for i, a in enumerate(row)
                       for j, b in enumerate(row) if i != j) / (len(row) - 1)
                   for row in rows) / total
    expected = sum(frequencies[a] * frequencies[b] * distance(a, b)
                   for a in categories for b in categories if a != b) / (total * (total - 1))
    result.update(observed_disagreement=observed, expected_disagreement=expected)
    if expected == 0:
        result.update(status="undefined", reason="No marginal rating variation; expected disagreement is zero.")
    else:
        result.update(status="ok", value=1.0 - observed / expected)
    return result


def build_statistical_report(payload: Mapping[str, Any]) -> dict[str, Any]:
    """JSON report contract used by the offline PHP evaluation worker.

    Rows must include null slots for attempted/assigned but unavailable items.
    Groups never combine splits, modes, systems or metric implementations.
    """
    seed = int(payload.get("seed", DEFAULT_SEED))
    samples = int(payload.get("bootstrap_samples", 2000))
    if not 100 <= samples <= 20000:
        raise ValueError("bootstrap_samples must be between 100 and 20000")
    metric_rows = list(payload.get("metric_rows", []))
    rating_rows = list(payload.get("rating_rows", []))
    result: dict[str, Any] = {
        "version": VERSION, "automatic": [], "human": [], "agreement": [],
        "comparisons": [], "warnings": [],
        "methodology": {
            "analysis_unit": "Independent document; repeated observations averaged within document",
            "confidence_interval": "95% document-cluster percentile bootstrap; equal document weights",
            "bootstrap_seed": seed, "bootstrap_samples": samples, "significance_level": 0.05,
            "test": "Two-sided paired Wilcoxon signed-rank, paired by document ID",
            "correction": "Holm within each metric/version/split and contrast kind",
            "interpretation": "Descriptive evidence only. Statistical significance does not establish overall accuracy or superiority.",
        },
    }
    grouped: dict[tuple[str, str, str, str, str, str], list[dict[str, Any]]] = defaultdict(list)
    seen: set[tuple[Any, ...]] = set()
    for source_kind, rows in (("automatic", metric_rows), ("human", rating_rows)):
        for row in rows:
            metric = str(row.get("metric") if source_kind == "automatic" else row.get("criterion"))
            version = str(row.get("version", row.get("metric_version", "unspecified"))) if source_kind == "automatic" else "likert-1-5/v1"
            split = str(row.get("dataset_split", "unspecified"))
            if row.get("document_id") is None or not metric or metric == "None":
                raise ValueError("Each statistics row requires document_id and metric/criterion")
            system, mode = str(row.get("system", "unspecified")), str(row.get("mode", "unspecified"))
            key = (source_kind, metric, version, system, mode, split)
            identity = (key, str(row.get("output_id", row["document_id"])),
                        str(row.get("evaluator_id")) if source_kind == "human" else "metric")
            if identity in seen:
                raise ValueError("Duplicate output/metric or output/evaluator/criterion row in statistical input")
            seen.add(identity)
            value = row.get("value") if source_kind == "automatic" else row.get("rating")
            if source_kind == "automatic" and row.get("status", "ok") not in {"ok", "completed", "success"}:
                value = None
            if source_kind == "human" and _number(value) is not None:
                if float(value) not in {1, 2, 3, 4, 5}:
                    raise ValueError("Human ratings must be integer scores from 1 to 5")
            grouped[key].append({**row, "_value": value})

    document_values: dict[tuple[str, str, str, str, str, str], dict[str, float | None]] = {}
    for key in sorted(grouped):
        kind, metric, version, system, mode, split = key
        rows = grouped[key]
        stats = descriptive_statistics([row["_value"] for row in rows],
                                       cluster_ids=[row["document_id"] for row in rows],
                                       seed=seed, bootstrap_samples=samples)
        group = {"metric" if kind == "automatic" else "criterion": metric,
                 "version": version, "system": system, "mode": mode, "dataset_split": split, **stats}
        if kind == "human":
            group["rating_counts"] = {str(score): sum(_number(row["_value"]) == score for row in rows)
                                      for score in range(1, 6)}
        result[kind].append(group)
        by_doc: dict[str, list[float]] = defaultdict(list)
        for row in rows:
            values = by_doc[str(row["document_id"])]
            numeric = _number(row["_value"])
            if numeric is not None:
                values.append(numeric)
        document_values[key] = {doc: mean(values) if values else None for doc, values in by_doc.items()}

    agreement_groups: dict[tuple[str, str], list[dict[str, Any]]] = defaultdict(list)
    for row in rating_rows:
        agreement_groups[(str(row["criterion"]), str(row.get("dataset_split", "unspecified")))].append(row)
    for (criterion, split), rows in sorted(agreement_groups.items()):
        raters = sorted({str(row["evaluator_id"]) for row in rows})
        items = sorted({str(row["output_id"]) for row in rows})
        indexed = {(str(row["output_id"]), str(row["evaluator_id"])): row.get("rating") for row in rows}
        reliability = krippendorff_alpha([[indexed.get((item, rater)) for rater in raters] for item in items])
        assigned = len(rows)
        reliability.update(criterion=criterion, dataset_split=split, assigned_rating_slots=assigned,
                           unsubmitted_assigned_ratings=sum(_number(row.get("rating")) is None for row in rows),
                           unassigned_matrix_slots=len(items) * len(raters) - assigned)
        result["agreement"].append(reliability)

    families: dict[tuple[str, ...], list[dict[str, Any]]] = defaultdict(list)
    keys = sorted(document_values)
    for a, b in combinations(keys, 2):
        if (a[0], a[1], a[2], a[5]) != (b[0], b[1], b[2], b[5]):
            continue
        if a[4] == b[4] and a[3] != b[3]:
            kind, left, right, fixed = "system", a[3], b[3], {"mode": a[4]}
        elif a[3] == b[3] and a[4] != b[4]:
            kind, left, right, fixed = "mode", a[4], b[4], {"system": a[3]}
        else:
            continue
        comparison = {"kind": kind, "metric": a[1] if a[0] == "automatic" else "human:" + a[1],
                      "version": a[2], "dataset_split": a[5], "left": left, "right": right, **fixed,
                      **paired_comparison(document_values[a], document_values[b], seed=seed, bootstrap_samples=samples)}
        if kind == "mode":
            comparison["assumptions"][1] = "Matched source documents; differing mode budgets are part of this contrast"
        families[(a[0], a[1], a[2], a[5], kind)].append(comparison)
    for family, comparisons in sorted(families.items()):
        corrected = holm_adjust([row["p_value"] for row in comparisons])
        for row, pvalue in zip(comparisons, corrected):
            row.update(p_adjusted=pvalue, correction="Holm", correction_family=" / ".join(family),
                       family_tests=sum(item["p_value"] is not None for item in comparisons),
                       significant=pvalue < 0.05 if pvalue is not None else None)
            result["comparisons"].append(row)
    if not rating_rows:
        result["warnings"].append("No human assessment records: quality ratings and inter-rater reliability are unavailable.")
    if any(key[5] == "unspecified" for key in grouped):
        result["warnings"].append("Rows without dataset split are isolated as unspecified and cannot support a final-test claim.")
    return result
