"""Independent metrics with explicit denominators and failure statuses."""
from collections import Counter
from functools import lru_cache
from hashlib import sha256
from importlib import metadata
import os
from pathlib import Path
from time import perf_counter

from .facts import FACT_VERSION, check_facts, contains_phrase, factual_consistency
from .text import TOKENIZER_VERSION, normalized, sentences, tokens, word_count


def metric(name, value=None, details=None, status="ok", error=None, version="1.0.0", **components):
    return {"name": name, "version": version, "status": status, "value": value, "details": details or {}, "error": error, **components}


def compression(source, summary):
    count, size = word_count(source), word_count(summary)
    ratio = size / count if count > 0 else None
    return {"source_word_count": count, "summary_word_count": size, "ratio": ratio, "percentage": (1 - ratio) * 100 if ratio is not None else None, "tokenizer": TOKENIZER_VERSION}


def redundancy(summary, threshold=.8):
    parts = sentences(summary)
    duplicates, near_duplicates = [], []
    for index, sentence in enumerate(parts):
        for previous in range(index):
            a, b = normalized(sentence), normalized(parts[previous])
            left, right = set(tokens(sentence)), set(tokens(parts[previous]))
            overlap = len(left & right) / len(left | right) if left | right else 0
            if a == b:
                duplicates.append({"sentence": index, "matches": previous})
                break
            if overlap >= threshold:
                near_duplicates.append({"sentence": index, "matches": previous, "jaccard": overlap})
                break
    total = len(duplicates) + len(near_duplicates)
    return {"score": total / len(parts) if parts else None, "duplicate_count": len(duplicates), "near_duplicate_count": len(near_duplicates), "sentence_count": len(parts), "threshold": threshold, "duplicates": duplicates, "near_duplicates": near_duplicates}


def _prf(overlap, candidate_size, reference_size):
    precision = overlap / candidate_size if candidate_size else 0.0
    recall = overlap / reference_size if reference_size else 0.0
    return {"precision": precision, "recall": recall, "f1": 2 * precision * recall / (precision + recall) if precision + recall else 0.0}


def rouge_pair(summary, reference):
    # Reference ROUGE definitions: clipped unigram/bigram counts and whole-text
    # LCS. No stemming, stopword removal, sentence union or ROUGE-Lsum.
    candidate, expected = tokens(summary), tokens(reference)
    if len(candidate) > 5000 or len(expected) > 5000:
        raise ValueError("ROUGE-L input exceeds 5000 tokens; reduce reference/summary length explicitly.")
    result = {}
    for size in (1, 2):
        a = Counter(tuple(candidate[i:i + size]) for i in range(max(0, len(candidate) - size + 1)))
        b = Counter(tuple(expected[i:i + size]) for i in range(max(0, len(expected) - size + 1)))
        result[f"rouge_{size}"] = _prf(sum((a & b).values()), sum(a.values()), sum(b.values()))
    previous = [0] * (len(expected) + 1)
    for token in candidate:
        current = [0]
        for index, wanted in enumerate(expected, 1):
            current.append(previous[index - 1] + 1 if token == wanted else max(previous[index], current[-1]))
        previous = current
    result["rouge_l"] = _prf(previous[-1], len(candidate), len(expected))
    return result


def rouge(summary, references):
    valid = [ref for ref in references if isinstance(ref, dict) and isinstance(ref.get("text"), str) and ref["text"].strip()]
    if not valid:
        return [metric(name, status="not_applicable", details={"reason": "No valid human reference summary", "references_total": len(references)}, version="rouge-token-lcs-v1") for name in ("rouge_1", "rouge_2", "rouge_l")]
    pairs = [(ref, rouge_pair(summary, ref["text"])) for ref in valid]
    result = []
    for name in ("rouge_1", "rouge_2", "rouge_l"):
        means = {component: sum(pair[name][component] for _, pair in pairs) / len(pairs) for component in ("precision", "recall", "f1")}
        result.append(metric(name, means["f1"], version="rouge-token-lcs-v1", **means, details={"reference_policy": "arithmetic mean of per-reference precision, recall and F1", "valid_references": len(valid), "references_total": len(references), "tokenizer": TOKENIZER_VERSION, "stemming": False, "reference_results": [{"id": ref.get("id"), "version": ref.get("version"), **pair[name]} for ref, pair in pairs]}))
    return result


@lru_cache(maxsize=2)
def _load_bert_scorer(model, layers):
    # Models must have been downloaded explicitly before running evaluations.
    os.environ["HF_HUB_OFFLINE"] = "1"
    os.environ["TRANSFORMERS_OFFLINE"] = "1"
    import torch
    from bert_score import BERTScorer
    torch.set_num_threads(1)
    return BERTScorer(model_type=model, num_layers=layers, lang="en", device="cpu", batch_size=1, nthreads=1, idf=False, rescale_with_baseline=False)


def bertscore(summary, references, options):
    details = {"device": "cpu", "batch_size": 1, "offline_cache_only": True, "idf": False, "rescale_with_baseline": False, "reference_policy": "arithmetic mean of per-reference scores"}
    if not options.get("bertscore", False):
        return metric("bertscore", status="not_applicable", details={**details, "reason": "Expensive metric disabled"}, version="bert-score-optional-v1")
    valid = [ref for ref in references if isinstance(ref, dict) and str(ref.get("text", "")).strip()]
    if not valid:
        return metric("bertscore", status="not_applicable", details={**details, "reason": "No valid human reference"}, version="bert-score-optional-v1")
    model = str(options.get("bertscore_model", "roberta-large"))
    layers = options.get("bertscore_num_layers")
    if layers is not None and (not isinstance(layers, int) or not 1 <= layers <= 96):
        raise ValueError("Invalid BERTScore layer count.")
    try:
        scorer = _load_bert_scorer(model, layers)
        # Refuse silent transformer truncation: every compared text must fit.
        tokenizer = scorer._tokenizer
        limit = min(int(getattr(tokenizer, "model_max_length", 512)), int(getattr(scorer._model.config, "max_position_embeddings", 512)))
        if any(len(tokenizer.encode(text, add_special_tokens=True)) > limit for text in [summary, *(ref["text"] for ref in valid)]):
            return metric("bertscore", status="unavailable", error="Input exceeds the model context; no silent truncation allowed.", details={**details, "model": model, "max_tokens": limit}, version="bert-score-optional-v1")
        precision, recall, f1 = scorer.score([summary] * len(valid), [ref["text"] for ref in valid])
        components = {"precision": float(precision.mean()), "recall": float(recall.mean()), "f1": float(f1.mean())}
        config = scorer._model.config
        details.update(model=model, model_revision=getattr(config, "_commit_hash", None), bertscore_hash=scorer.hash, model_config_hash=sha256(config.to_json_string().encode()).hexdigest(), valid_references=len(valid), package_version=metadata.version("bert-score"))
        if Path(model).is_dir():
            from .runner import model_fingerprint
            details["local_model_fingerprint"] = model_fingerprint(model)
        return metric("bertscore", components["f1"], details=details, version="bert-score-optional-v1", **components)
    except (ImportError, OSError, RuntimeError, KeyError, ValueError) as exc:
        return metric("bertscore", status="unavailable", error=f"Dependency/model unavailable: {type(exc).__name__}: {exc}", details={**details, "model": model}, version="bert-score-optional-v1")


def content_coverage(summary, units):
    # This is candidate evidence for verification, never an automatic semantic
    # coverage score. Only explicitly human-verified labels enter coverage.
    rows = []
    for index, unit in enumerate(units):
        if not isinstance(unit, dict) or not str(unit.get("text", "")).strip():
            raise ValueError("Content units need non-empty text.")
        rows.append({"id": unit.get("id", index), "text": unit["text"], "literal_match_candidate": contains_phrase(summary, unit["text"]), "human_verified": unit.get("human_verified") if isinstance(unit.get("human_verified"), bool) else None})
    checked = [row for row in rows if row["human_verified"] is not None]
    complete = bool(rows) and len(checked) == len(rows)
    return metric("content_unit_coverage", sum(row["human_verified"] for row in rows) / len(rows) if complete else None, status="ok" if complete else "not_applicable", details={"units": rows, "total_units": len(rows), "verified_units": len(checked), "reason": "All units require output-specific human verification before coverage is calculated", "matching": "literal candidates are for review only; not semantic coverage"})


def challenge(summary, expected, prohibited):
    required = [str(fact.get("text", "")) if isinstance(fact, dict) else str(fact) for fact in expected]
    missing = [fact for fact in required if not contains_phrase(summary, fact)]
    found = [phrase for phrase in prohibited if contains_phrase(summary, str(phrase))]
    applicable = bool(required or prohibited)
    return metric("challenge", float(not missing and not found) if applicable else None, status="ok" if applicable else "not_applicable", details={"passed": not missing and not found if applicable else None, "missing_expected_phrases": missing, "prohibited_distortions_found": found, "reason": "Phrase assertions test specified constraints only; passing does not prove full factual correctness."})


def challenge_cases(summary, cases):
    results = []
    for case in cases:
        if not isinstance(case, dict):
            raise ValueError("Challenge case must be an object.")
        result = challenge(summary, case.get("expected_facts", []), case.get("prohibited_distortions", []))
        results.append({"id": case.get("id"), "category": case.get("category"), "status": result["status"], **result["details"]})
    valid = [row for row in results if row["status"] == "ok"]
    passed = sum(row["passed"] for row in valid)
    return metric("challenge", passed / len(valid) if valid else None, status="ok" if valid else "not_applicable", details={"cases": results, "cases_total": len(results), "cases_valid": len(valid), "cases_passed": passed, "cases_failed": len(valid) - passed, "passed": passed == len(valid) if valid else None, "reason": "Fraction of cases satisfying every expected/prohibited phrase assertion; not overall factual accuracy."})


def compute_metrics(source, summary, references=None, annotations=None, content_units=None, prohibited=None, options=None, cases=None):
    references, options = references or [], options or {}
    records = []
    def safe(name, callback):
        started = perf_counter()
        try:
            result = callback()
            rows = result if isinstance(result, list) else [result]
        except Exception as exc:
            rows = [metric(name, status="error", error=f"{type(exc).__name__}: {exc}", version="bert-score-optional-v1" if name == "bertscore" else "1.0.0")]
        for row in rows:
            row.setdefault("details", {})["duration_seconds"] = perf_counter() - started
            records.append(row)
    values = compression(source, summary)
    for name, key in (("compression_ratio", "ratio"), ("compression_percentage", "percentage")):
        records.append(metric(name, values[key], values, status="ok" if values[key] is not None else "not_applicable"))
    rouge_started = perf_counter()
    try:
        rouge_rows = rouge(summary, references)
    except Exception as exc:
        rouge_rows = [metric(name, status="error", error=f"{type(exc).__name__}: {exc}", version="rouge-token-lcs-v1") for name in ("rouge_1", "rouge_2", "rouge_l")]
    for row in rouge_rows:
        row["details"]["duration_seconds"] = perf_counter() - rouge_started
        records.append(row)
    safe("redundancy", lambda: (lambda data: metric("redundancy", data["score"], data))(redundancy(summary)))
    try:
        facts = check_facts(source, summary, annotations)
        records.append(metric("critical_fact_preservation", facts["preservation_rate"], facts, status="ok" if facts["total_critical_facts"] else "not_applicable", version=FACT_VERSION))
        safe("factual_consistency", lambda: (lambda data: metric("factual_consistency", data["score"], data, version=FACT_VERSION))(factual_consistency(source, summary, facts)))
    except Exception as exc:
        records.append(metric("critical_fact_preservation", status="error", error=str(exc), version=FACT_VERSION))
        records.append(metric("factual_consistency", status="error", error="Critical-fact screening failed", version=FACT_VERSION))
    safe("content_unit_coverage", lambda: content_coverage(summary, content_units or []))
    safe("challenge", lambda: challenge_cases(summary, cases) if cases else challenge(summary, annotations or [], prohibited or []))
    safe("bertscore", lambda: bertscore(summary, references, options))
    return records
