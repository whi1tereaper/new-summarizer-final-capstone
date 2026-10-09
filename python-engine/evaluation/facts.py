"""Source-aligned lexical critical-fact screening; NOT a proof of factuality.

Sentence alignment and phrase extraction are English-oriented heuristics. An
omitted source fact is distinguished from an altered aligned claim. Entities
are capitalized-span candidates, not a trained entity recognizer. Annotation
supplied by researchers takes precedence over automatically extracted facts.
"""
import re
from functools import lru_cache
from nltk.stem import PorterStemmer

from .text import normalized, sentences, tokens

FACT_VERSION = "source-aligned-lexical-v1"
PATTERNS = {
    "dates": re.compile(r"\b(?:\d{4}-\d{2}-\d{2}|(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2}(?:,?\s+\d{4})?)\b", re.I),
    "numbers": re.compile(r"(?<!\w)[+-]?\d+(?:,\d{3})*(?:\.\d+)?(?:\s*(?:%|percent\b|kg\b|mg\b|km\b|cm\b|mm\b|ml\b|liters?\b|hours?\b|days?\b|years?\b|participants?\b|patients?\b|ms\b|GB\b|MB\b|USD\b))?", re.I),
    "entities": re.compile(r"\b(?:[A-Z][a-z]+(?:\s+[A-Z][a-z]+)+|[A-Z]{2,})\b"),
    "negations": re.compile(r"\b(?:not|no|never|neither|without|cannot|failed to|didn't|doesn't|wasn't|isn't)\b", re.I),
    "qualifiers": re.compile(r"\b(?:may|might|could|approximately|roughly|possibly|potentially|likely|preliminary|suggests?|appears? to|not statistically significant|not significantly)\b", re.I),
    "comparisons": re.compile(r"\b(?:more than|less than|greater than|lower than|higher than|at least|at most|increased|decreased|reduced|improved)\b", re.I),
    "causal_language": re.compile(r"\b(?:associated with|correlated with|caused by|causes?|led to|resulted in)\b", re.I),
}
STOP = set("a an the to in of for by with and or is are was were did does do has have had this that it from as be been".split())
STEMMER = PorterStemmer()


def extract_critical_facts(source):
    found = []
    for index, sentence in enumerate(sentences(source)):
        seen = set()
        for kind, pattern in PATTERNS.items():
            for match in pattern.finditer(sentence):
                key = (kind, normalized(match.group()))
                if key not in seen:
                    found.append({"id": f"s{index}-{kind}-{len(seen)}", "kind": kind, "text": match.group(), "source_sentence": sentence, "sentence_index": index})
                    seen.add(key)
    return found


@lru_cache(maxsize=8192)
def _alignment_tokens(text):
    for kind in ("numbers", "qualifiers", "negations"):
        text = PATTERNS[kind].sub(" ", text)
    return frozenset(STEMMER.stem(token) for token in set(tokens(text)) - STOP)


def similarity(left, right):
    # Strip critical-value expressions so changed numbers still align to the
    # same claim; the values themselves are checked after alignment.
    a, b = _alignment_tokens(left), _alignment_tokens(right)
    return len(a & b) / len(a | b) if a and b else 0.0


def contains_phrase(text, phrase):
    value, haystack = normalized(phrase), normalized(text)
    if not value:
        return False
    if re.fullmatch(r"[+-]?\d+(?:[,.]\d+)*", value):
        # Numeric extraction also finds values in ranges/ordinals (1951-1980,
        # 20th-century). Respect numeric boundaries, not whitespace boundaries,
        # while keeping 12 distinct from 120 and 12.7.
        return re.search(r"(?<![\d.,])" + re.escape(value) + r"(?![\d.,])", haystack) is not None
    return re.search(r"(?<!\w)" + re.escape(value) + r"(?!\w)", haystack) is not None


def check_facts(source, summary, annotations=None):
    source_sentences, output_sentences = sentences(source), sentences(summary)
    facts = annotations if annotations else extract_critical_facts(source)
    outcomes = []
    for index, raw in enumerate(facts):
        fact = dict(raw) if isinstance(raw, dict) else {"text": str(raw), "kind": "annotated"}
        value = str(fact.get("text", "")).strip()
        if not value:
            raise ValueError("Critical fact annotation requires non-empty text.")
        context = fact.get("source_sentence") or next((sentence for sentence in source_sentences if contains_phrase(sentence, value)), "")
        if not context:
            outcomes.append({"id": fact.get("id", index), "kind": fact.get("kind", "annotated"), "text": value, "status": "invalid_annotation", "reason": "Expected phrase absent from source; review annotation."})
            continue
        match = max(output_sentences, key=lambda sentence: similarity(context, sentence), default="")
        alignment = similarity(context, match) if match else 0.0
        kind = fact.get("kind", "annotated")
        if alignment >= .3 and contains_phrase(match, value):
            status = "preserved"
        elif alignment >= .45 and kind in {"negations", "qualifiers", "comparisons", "causal_language"}:
            status = "suspected_contradiction"
        elif alignment >= .45 and kind in PATTERNS and PATTERNS[kind].search(match):
            status = "altered"
        else:
            status = "missing"
        outcomes.append({"id": fact.get("id", index), "kind": kind, "text": value, "status": status, "source_sentence": context, "matched_summary_sentence": match if alignment >= .3 else None, "alignment_similarity": alignment})
    valid = [fact for fact in outcomes if fact["status"] != "invalid_annotation"]
    counts = {status: sum(fact["status"] == status for fact in valid) for status in ("preserved", "missing", "altered", "suspected_contradiction")}
    return {
        "total_critical_facts": len(valid), "preserved_facts": counts["preserved"], "missing_facts": counts["missing"],
        "altered_facts": counts["altered"], "suspected_contradictions": counts["suspected_contradiction"],
        "preservation_rate": 100 * counts["preserved"] / len(valid) if valid else None,
        "invalid_annotations": len(outcomes) - len(valid), "facts": outcomes,
        "annotation_source": "researcher" if annotations else "automatic heuristic",
        "limitations": "Lexical sentence alignment can miss paraphrases and misalign similar claims. Omissions are not automatically factual errors. Named entities are capitalized-span candidates. Human verification required.",
    }


def factual_consistency(source, summary, fact_results):
    source_sentences, output_sentences = sentences(source), sentences(summary)
    warnings = []
    for sentence in output_sentences:
        match = max(source_sentences, key=lambda source: similarity(source, sentence), default="")
        alignment = similarity(match, sentence) if match else 0.0
        reasons = []
        if alignment < .3:
            reasons.append("low_source_alignment")
        for kind in ("numbers", "dates", "entities"):
            for fact in PATTERNS[kind].finditer(sentence):
                if not contains_phrase(match, fact.group()):
                    reasons.append(f"unsupported_{kind}:{fact.group()}")
        if alignment >= .45:
            if bool(PATTERNS["negations"].search(match)) != bool(PATTERNS["negations"].search(sentence)):
                reasons.append("negation_mismatch")
            if PATTERNS["qualifiers"].search(match) and not PATTERNS["qualifiers"].search(sentence):
                reasons.append("qualifier_omission")
            source_causal = {normalized(m.group()) for m in PATTERNS["causal_language"].finditer(match)}
            output_causal = {normalized(m.group()) for m in PATTERNS["causal_language"].finditer(sentence)}
            if output_causal - source_causal:
                reasons.append("causal_wording_mismatch")
        if reasons:
            warnings.append({"summary_sentence": sentence, "source_sentence": match, "reasons": reasons, "alignment_similarity": alignment})
    return {
        "score": 1 - len(warnings) / len(output_sentences) if output_sentences else None,
        "name": "Source-aligned lexical warning-free sentence fraction", "warnings": warnings,
        "contradiction_indicators": fact_results["suspected_contradictions"], "sentences_checked": len(output_sentences),
        "limitations": "Heuristic warning screen, not a validated entailment model or factual accuracy measure. A warning-free sentence can still be false.",
    }
