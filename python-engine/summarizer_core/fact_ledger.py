"""Conservative source-fact inventory and extractive output diagnostics.

These checks are lexical diagnostics, not semantic entailment or proof of truth.
They deliberately return issue categories and stable sentence IDs, not source text.
"""

from __future__ import annotations

from dataclasses import dataclass
import re
from typing import Any, Iterable

from .models import SentenceCandidate
from .normalization import ensure_terminal_punctuation

_NUMBER = re.compile(
    r"(?<![\w.])(?:[$€£]\s*)?[-+]?\d[\d,]*(?:\.\d+)?(?:\s*(?:%|percent(?:age points?)?|"
    r"degrees?|°\s*[CF]|mg|mcg|μg|g|kg|ml|mL|L|km|miles?|hours?|minutes?|days?|weeks?|months?|years?|mm|cm|m\b))?(?!\w)",
    re.IGNORECASE,
)
_DATE = re.compile(
    r"\b(?:\d{4}-\d{1,2}-\d{1,2}|"
    r"(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|"
    r"Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)"
    r"\s+\d{1,2}(?:,?\s+\d{4})?|\d{1,2}\s+"
    r"(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|"
    r"Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)"
    r"\s+\d{4})\b",
    re.IGNORECASE,
)
_QUALIFIER = re.compile(
    r"\b(?:approximately|about|around|estimated|estimate|may|might|could|possibly|"
    r"potentially|likely|unlikely|suggests?|appears? to|seems? to|preliminary|"
    r"not statistically significant|statistically non-significant|statistically significant)\b",
    re.IGNORECASE,
)
_NEGATION = re.compile(r"\b(?:not|no|never|without|neither|nor|did not|does not|do not|cannot|can't|n't)\b", re.IGNORECASE)
_COMPARISON = re.compile(
    r"\b(?:compared with|compared to|versus|vs\.?|more than|less than|higher than|"
    r"lower than|greater than|fewer than|at least|at most|between|from .{1,40} to)\b",
    re.IGNORECASE,
)
_WORD = re.compile(r"\w+", re.UNICODE)

_TREND_UPWARD = re.compile(
    r"\b(?:increase[sd]?|increasing(?:ly)?|increments?|rise[sn]?|rising|rose|upward[s]?|"
    r"growth|grow[sn]?|growing|grew|higher|elevat(?:ed|ing|ion)|warming|gain(?:ed|s|ing)?|"
    r"accelerat(?:ed|ing|es?)|positive\s+trends?|climb(?:ed|ing|s)?|surge[sd]?|surging|"
    r"expand(?:ed|ing|s)?|expansion|upward\s+shift)\b",
    re.IGNORECASE,
)

_TREND_DOWNWARD = re.compile(
    r"\b(?:decrease[sd]?|decreasing(?:ly)?|drop(?:ped|ping|s)?|downward[s]?|decline[sd]?|"
    r"declining|lower(?:ed|ing|s)?|reduc(?:ed|ing|tion|tions|es?)|cooling|dimming|"
    r"loss(?:es)?|negative\s+trends?|fall(?:en|ing|s)?|fell|diminish(?:ed|ing|es?)|"
    r"shrink(?:ing|s)?|shrunk|shrinkage|downward\s+shift)\b",
    re.IGNORECASE,
)

_TREND_STABLE = re.compile(
    r"\b(?:stable|stability|stabiliz(?:ed|ing|es?)|constant|remained\s+constant|unchanged|"
    r"no\s+(?:significant\s+)?trend|plateau(?:ed)?|level(?:ed)?)\b",
    re.IGNORECASE,
)

_SIGNIFICANCE_NON_SIG = re.compile(
    r"\b(?:statistically\s+non-?significant|not\s+statistically\s+significant|non-?significant|"
    r"insignificant|no\s+significant\s+(?:difference|trend|effect|correlation|change|impact|relationship)|"
    r"not\s+significant(?:ly)?|did\s+not\s+reach\s+statistical\s+significance|"
    r"failed\s+to\s+reach\s+statistical\s+significance|p\s*>\s*0?\.05)\b",
    re.IGNORECASE,
)

_SIGNIFICANCE_SIG = re.compile(
    r"\b(?:statistically\s+significant(?:ly)?|significant\s+(?:trend|increase|decrease|decline|"
    r"warming|cooling|difference|effect|change|impact|correlation|relationship)|"
    r"significant\s+at\s+the|p\s*<\s*0?\.0[0-9]+)\b",
    re.IGNORECASE,
)

_CLAUSE_SPLIT = re.compile(r";|\b(?:whereas|while|although|however|in contrast|conversely|but)\b", re.IGNORECASE)

_DETAILED_NUMBER = re.compile(
    r"(?P<sign>[-+])?\s*(?P<val>\d+(?:,\d{3})*(?:\.\d+)?)\s*(?P<unit>%|percent|degrees?|°\s*[CF]|mg|mcg|μg|g|kg|ml|mL|L|km|miles?|hours?|minutes?|days?|weeks?|months?|years?|mm|cm|m\b)?",
    re.IGNORECASE,
)


def extract_trend_signals(text: str) -> list[dict[str, Any]]:
    """Extract metric, trend direction, and significance for each clause in text."""
    if not text or not isinstance(text, str):
        return []

    clauses = [c.strip() for c in _CLAUSE_SPLIT.split(text) if c.strip()]
    if not clauses:
        clauses = [text.strip()]

    signals: list[dict[str, Any]] = []

    for clause in clauses:
        direction = None
        has_up = bool(_TREND_UPWARD.search(clause))
        has_down = bool(_TREND_DOWNWARD.search(clause))
        has_stable = bool(_TREND_STABLE.search(clause))

        has_plus_num = bool(re.search(r"\+\s*\d+(?:\.\d+)?", clause))
        has_minus_num = bool(re.search(r"-\s*\d+(?:\.\d+)?", clause))

        trend_match = _TREND_UPWARD.search(clause) or _TREND_DOWNWARD.search(clause) or _TREND_STABLE.search(clause)
        is_negated = False
        if trend_match:
            prefix = clause[:trend_match.start()].strip()
            tokens_before = prefix.split()[-6:] if prefix else []
            prefix_window = " ".join(tokens_before).lower()
            if re.search(r"\b(?:not|no|never|neither|nor|without|did not|does not|do not|failed to|unable to|hardly)\b", prefix_window):
                is_negated = True

        words = set(re.findall(r"\b[A-Za-z]{3,}\b", clause.lower()))
        non_metric = {
            "trend", "trends", "showed", "shows", "shown", "observed", "exhibited",
            "displayed", "indicated", "increased", "increasing", "increase",
            "decreased", "decreasing", "decrease", "decline", "declined",
            "statistically", "significant", "significantly", "rate", "rates",
            "contrast", "decade", "period", "study", "value", "approximately",
            "about", "around", "slight", "slightly", "clear", "differences",
            "patterns", "annual", "total", "were", "was", "been", "have",
            "with", "from", "over", "into", "that", "this",
        }
        metric_tokens = words - non_metric

        if has_up and not has_down:
            direction = "NEGATED_UPWARD" if is_negated else "UPWARD"
        elif has_down and not has_up:
            direction = "NEGATED_DOWNWARD" if is_negated else "DOWNWARD"
        elif has_stable and not (has_up or has_down):
            direction = "STABLE"
        elif has_plus_num and not has_minus_num and not (has_up or has_down):
            direction = "UPWARD"
        elif has_minus_num and not has_plus_num and not (has_up or has_down):
            direction = "DOWNWARD"
        elif has_up and has_down:
            up_match = _TREND_UPWARD.search(clause)
            down_match = _TREND_DOWNWARD.search(clause)
            sig_level = "NON_SIGNIFICANT" if _SIGNIFICANCE_NON_SIG.search(clause) else ("SIGNIFICANT" if _SIGNIFICANCE_SIG.search(clause) else "UNSPECIFIED")
            if up_match:
                up_window = clause[max(0, up_match.start() - 30): min(len(clause), up_match.end() + 30)]
                up_tokens = set(re.findall(r"\b[A-Za-z]{3,}\b", up_window.lower())) - non_metric
                signals.append({
                    "clause": clause,
                    "direction": "UPWARD",
                    "significance": sig_level,
                    "metric_tokens": up_tokens,
                })
            if down_match:
                down_window = clause[max(0, down_match.start() - 30): min(len(clause), down_match.end() + 30)]
                down_tokens = set(re.findall(r"\b[A-Za-z]{3,}\b", down_window.lower())) - non_metric
                signals.append({
                    "clause": clause,
                    "direction": "DOWNWARD",
                    "significance": sig_level,
                    "metric_tokens": down_tokens,
                })
            continue

        if not direction:
            continue

        significance = "UNSPECIFIED"
        if _SIGNIFICANCE_NON_SIG.search(clause):
            significance = "NON_SIGNIFICANT"
        elif _SIGNIFICANCE_SIG.search(clause):
            significance = "SIGNIFICANT"

        signals.append({
            "clause": clause,
            "direction": direction,
            "significance": significance,
            "metric_tokens": metric_tokens,
        })

    return signals


def detect_trend_contradiction(source_text: str, output_text: str) -> list[dict[str, Any]]:
    """Detect if output reverses trend direction or statistical significance relative to source."""
    if not source_text or not output_text:
        return []

    src_signals = extract_trend_signals(source_text)
    out_signals = extract_trend_signals(output_text)
    if not src_signals or not out_signals:
        return []

    contradictions: list[dict[str, Any]] = []

    for out_sig in out_signals:
        best_src = None
        if len(src_signals) == 1 and len(out_signals) == 1:
            best_src = src_signals[0]
        else:
            best_overlap = 0
            for src_sig in src_signals:
                overlap = len(out_sig["metric_tokens"] & src_sig["metric_tokens"])
                if overlap > best_overlap:
                    best_overlap = overlap
                    best_src = src_sig

        if best_src is None:
            continue

        src_dir = best_src["direction"]
        out_dir = out_sig["direction"]

        if out_dir == "UPWARD" and src_dir in {"DOWNWARD", "NEGATED_UPWARD"}:
            contradictions.append({
                "issue": "reversed_trend_direction",
                "detail": f"Output claims upward trend but source specifies {src_dir.lower()}.",
            })
        elif out_dir == "DOWNWARD" and src_dir in {"UPWARD", "NEGATED_DOWNWARD"}:
            contradictions.append({
                "issue": "reversed_trend_direction",
                "detail": f"Output claims downward trend but source specifies {src_dir.lower()}.",
            })

        src_sig_lvl = best_src["significance"]
        out_sig_lvl = out_sig["significance"]

        if src_sig_lvl == "NON_SIGNIFICANT" and out_sig_lvl == "SIGNIFICANT":
            contradictions.append({
                "issue": "statistical_significance_reversal",
                "detail": "Source specifies non-significant trend but output claims statistically significant.",
            })
        elif src_sig_lvl == "SIGNIFICANT" and out_sig_lvl == "NON_SIGNIFICANT":
            contradictions.append({
                "issue": "statistical_significance_reversal",
                "detail": "Source specifies statistically significant trend but output claims non-significant.",
            })

    return contradictions


def extract_detailed_numbers(text: str) -> list[dict[str, Any]]:
    """Extract numeric items with normalized values, explicit signs, and units."""
    if not text:
        return []
    items: list[dict[str, Any]] = []
    for match in _DETAILED_NUMBER.finditer(text):
        raw = match.group(0).strip()
        val_str = match.group("val").replace(",", "")
        sign = match.group("sign") or ""
        unit = (match.group("unit") or "").lower().strip()
        if unit.startswith("°") or unit.startswith("degree"):
            unit = "deg"
        elif unit in {"percent", "%"}:
            unit = "%"
        elif unit in {"hours", "hour", "h"}:
            unit = "h"
        elif unit in {"years", "year", "yr"}:
            unit = "y"

        try:
            val_float = float(val_str)
        except ValueError:
            continue

        items.append({
            "raw": raw,
            "value_str": val_str,
            "value_float": val_float,
            "sign": sign,
            "unit": unit,
        })
    return items


def detect_unsupported_numbers(source_text: str, output_text: str, allowed_context: str = "") -> list[dict[str, Any]]:
    """Verify that all output numbers exist in source, with matching sign and unit."""
    if not output_text:
        return []

    src_text = f"{source_text} {allowed_context}" if allowed_context else source_text
    src_nums = extract_detailed_numbers(src_text)
    out_nums = extract_detailed_numbers(output_text)

    if not out_nums:
        return []

    issues: list[dict[str, Any]] = []

    for out_num in out_nums:
        matching_src = [s for s in src_nums if s["value_str"] == out_num["value_str"]]
        if not matching_src:
            issues.append({
                "issue": "unsupported_numerical_value",
                "detail": f"Output numerical value '{out_num['raw']}' does not appear in source text.",
            })
            continue

        if out_num["sign"]:
            exact_sign_match = any(s["sign"] == out_num["sign"] for s in matching_src)
            opp_sign_match = any(
                (out_num["sign"] == "+" and s["sign"] == "-") or
                (out_num["sign"] == "-" and s["sign"] == "+")
                for s in matching_src
            )
            if not exact_sign_match and opp_sign_match:
                issues.append({
                    "issue": "reversed_numerical_sign",
                    "detail": f"Output number '{out_num['raw']}' has inverted sign relative to source.",
                })

        if out_num["unit"]:
            unit_match = any(s["unit"] == out_num["unit"] or not s["unit"] for s in matching_src)
            if not unit_match:
                issues.append({
                    "issue": "mutated_numerical_unit",
                    "detail": f"Output unit '{out_num['unit']}' does not match source unit for value {out_num['value_str']}.",
                })

    return issues


@dataclass(frozen=True)
class FactLedgerEntry:
    source_sentence_id: int
    section_id: str
    numbers: tuple[str, ...]
    dates: tuple[str, ...]
    qualifiers: tuple[str, ...]
    negations: tuple[str, ...]
    comparisons: tuple[str, ...]
    claim_text: str
    claim_status: str = "UNKNOWN"
    section: str = "body"
    trends: tuple[str, ...] = ()
    significance_markers: tuple[str, ...] = ()


def build_fact_ledger(candidates: Iterable[SentenceCandidate]) -> tuple[FactLedgerEntry, ...]:
    """Inventory literal fact-bearing spans for source sentence candidates."""
    return tuple(
        FactLedgerEntry(
            source_sentence_id=candidate.index,
            section_id=candidate.section_id or candidate.section,
            numbers=_spans(_NUMBER, candidate.text),
            dates=_spans(_DATE, candidate.text),
            qualifiers=_spans(_QUALIFIER, candidate.text),
            negations=_spans(_NEGATION, candidate.text),
            comparisons=_spans(_COMPARISON, candidate.text),
            claim_text=candidate.text,
            claim_status=candidate.claim_status,
            section=candidate.section,
            trends=tuple(s["direction"] for s in extract_trend_signals(candidate.text)),
            significance_markers=tuple(s["significance"] for s in extract_trend_signals(candidate.text)),
        )
        for candidate in candidates
    )


def validate_summary_facts(
    summary_sentences: list[str],
    ledger: tuple[FactLedgerEntry, ...],
) -> dict[str, object]:
    """Return conservative lexical warnings for each output sentence.

    Sentences are aligned to the source entry with the greatest token overlap.
    Missing source facts are not automatically treated as errors: summaries may
    omit details. The function only flags unsupported output spans and possible
    loss of meaning-changing markers during compression.
    """
    if not summary_sentences:
        return {
            "status": "not_applicable",
            "sentences_checked": 0,
            "issues": [],
            "method": "literal_span_and_marker_checks_v1",
        }
    if not ledger:
        return {
            "status": "unavailable",
            "sentences_checked": 0,
            "issues": [],
            "method": "literal_span_and_marker_checks_v1",
        }

    issues: list[dict[str, object]] = []

    for summary_index, sentence in enumerate(summary_sentences):
        entry = _best_source(sentence, ledger)
        if entry is None:
            issues.append({"summary_sentence_id": summary_index, "source_sentence_id": None, "issue": "no_source_match"})
            continue

        checks = (
            ("unsupported_number_or_unit", _spans(_NUMBER, sentence), entry.numbers),
            ("unsupported_date", _spans(_DATE, sentence), entry.dates),
            ("unsupported_qualifier", _spans(_QUALIFIER, sentence), entry.qualifiers),
            ("unsupported_negation", _spans(_NEGATION, sentence), entry.negations),
            ("unsupported_comparison", _spans(_COMPARISON, sentence), entry.comparisons),
        )
        for issue_name, output_spans, source_spans in checks:
            for span in output_spans:
                if not _contains_span(source_spans, span):
                    issues.append({"summary_sentence_id": summary_index, "source_sentence_id": entry.source_sentence_id, "issue": issue_name})

        # Semantic Trend Invariance Checks
        trend_contradictions = detect_trend_contradiction(entry.claim_text, sentence)
        for t_issue in trend_contradictions:
            issues.append({
                "summary_sentence_id": summary_index,
                "source_sentence_id": entry.source_sentence_id,
                "issue": t_issue["issue"],
                "detail": t_issue.get("detail", ""),
            })

        # Grounded Numerical Values & Signs Checks
        num_contradictions = detect_unsupported_numbers(entry.claim_text, sentence)
        for n_issue in num_contradictions:
            issues.append({
                "summary_sentence_id": summary_index,
                "source_sentence_id": entry.source_sentence_id,
                "issue": n_issue["issue"],
                "detail": n_issue.get("detail", ""),
            })

        for category, source_spans, output_spans in (
            ("possible_negation_omission", entry.negations, _spans(_NEGATION, sentence)),
            ("possible_qualifier_omission", entry.qualifiers, _spans(_QUALIFIER, sentence)),
            ("possible_comparison_omission", entry.comparisons, _spans(_COMPARISON, sentence)),
        ):
            if source_spans and not any(_contains_span(output_spans, cue) for cue in source_spans):
                issues.append({"summary_sentence_id": summary_index, "source_sentence_id": entry.source_sentence_id, "issue": category})

        # A proposal's future-tense method, objective, question, or hypothesis
        # must not be silently recast as a completed result during compression.
        if entry.claim_status in {
            "ACTUAL_RESULT", "PROPOSED_METHOD", "RESEARCH_OBJECTIVE", "RESEARCH_QUESTION",
            "HYPOTHESIS", "LITERATURE_FINDING",
        }:
            from .document_analysis import classify_claim

            _output_type, output_status = classify_claim(sentence, entry.section)
            if output_status != entry.claim_status:
                issues.append({
                    "summary_sentence_id": summary_index,
                    "source_sentence_id": entry.source_sentence_id,
                    "issue": "possible_claim_status_shift",
                })

    return {
        "status": "checked_with_warnings" if issues else "checked_no_lexical_warnings",
        "sentences_checked": len(summary_sentences),
        "ledger_entries": len(ledger),
        "issues": issues,
        "method": "literal_span_and_marker_checks_v1",
        "interpretation": "Heuristic diagnostics only; not semantic entailment or proof of factual consistency.",
    }


def _spans(pattern: re.Pattern[str], text: str) -> tuple[str, ...]:
    return tuple(dict.fromkeys(match.group(0).strip() for match in pattern.finditer(text)))


def _contains_span(spans: tuple[str, ...], target: str) -> bool:
    target_normalized = _normalize_span(target)
    return any(_normalize_span(span) == target_normalized for span in spans)


def _normalize_span(value: str) -> str:
    return re.sub(r"\s+", " ", value.strip().casefold())


def _best_source(sentence: str, ledger: tuple[FactLedgerEntry, ...]) -> FactLedgerEntry | None:
    output = set(_WORD.findall(sentence.casefold()))
    if not output:
        return None
    return max(
        ledger,
        key=lambda entry: (
            len(output & set(_WORD.findall(entry.claim_text.casefold()))) / len(output),
            -entry.source_sentence_id,
        ),
        default=None,
    )


class FactualityValidator:
    """Post-construction factuality, provenance, and attribution validation engine."""

    @classmethod
    def validate_and_repair(
        cls,
        summary_sentences: list[str],
        candidates: list[SentenceCandidate],
        document_stage: str = "unknown",
    ) -> tuple[list[str], dict[str, Any]]:
        """Validate output statements against source provenance and repair safely."""
        from .constants import DANGLING_ANTECEDENT_PATTERN
        from .normalization import normalize_whitespace
        from .scoring import extract_content_tokens
        from .structure import extract_structural_heading

        repaired: list[str] = []
        issues: list[dict[str, Any]] = []
        candidates_by_text = {c.text.strip(): c for c in candidates}

        for idx, sentence in enumerate(summary_sentences):
            clean_s = normalize_whitespace(sentence).strip()
            if not clean_s:
                continue

            # 1. Heading leak check
            heading, prose = extract_structural_heading(clean_s)
            if heading and prose:
                issues.append({
                    "sentence_index": idx,
                    "issue": "heading_leak_detected",
                    "heading": heading,
                })
                clean_s = prose

            # 2. Find best candidate match
            matched_candidate = candidates_by_text.get(clean_s)
            if matched_candidate is None:
                s_tokens = extract_content_tokens(clean_s)
                best_cand = None
                best_overlap = 0.0
                for cand in candidates:
                    c_tokens = extract_content_tokens(cand.text)
                    if not s_tokens or not c_tokens:
                        continue
                    overlap = len(s_tokens & c_tokens) / len(s_tokens | c_tokens)
                    if overlap > best_overlap:
                        best_overlap = overlap
                        best_cand = cand
                if best_overlap >= 0.30:
                    matched_candidate = best_cand

            # 3. Source-Grounded Trend & Statistical Invariance Guard:
            if matched_candidate is not None:
                trend_conflicts = detect_trend_contradiction(matched_candidate.text, clean_s)
                if trend_conflicts:
                    clean_s = matched_candidate.text
                    for item in trend_conflicts:
                        issues.append({
                            "sentence_index": idx,
                            "issue": f"{item['issue']}_reverted_to_source",
                            "detail": item.get("detail", ""),
                        })

                # 4. Source-Grounded Numerical Integrity Guard:
                num_conflicts = detect_unsupported_numbers(matched_candidate.text, clean_s)
                if num_conflicts:
                    clean_s = matched_candidate.text
                    for item in num_conflicts:
                        issues.append({
                            "sentence_index": idx,
                            "issue": f"{item['issue']}_reverted_to_source",
                            "detail": item.get("detail", ""),
                        })

            # 5. Proposal Safety Check:
            if document_stage == "proposal":
                has_completed_claim = bool(re.search(
                    r"\b(?:results? showed|findings? indicated|revealed that|found that|demonstrated that|significantly affected|significant relationship was found|survey responses showed)\b",
                    clean_s,
                    re.IGNORECASE,
                ))
                is_prior_lit = matched_candidate and (
                    matched_candidate.source_role == "prior_study"
                    or matched_candidate.epistemic_status in {"PRIOR_STUDY_RESULT", "LITERATURE_FINDING"}
                )
                if has_completed_claim and not is_prior_lit:
                    if matched_candidate and re.search(r"\b(?:will|aims? to|intends? to|proposed)\b", matched_candidate.text, re.I):
                        clean_s = matched_candidate.text
                        issues.append({
                            "sentence_index": idx,
                            "issue": "proposal_claim_restored_to_prospective",
                        })

            # 4. Attribution Preservation:
            if matched_candidate and matched_candidate.source_role == "prior_study":
                attribution = matched_candidate.attribution
                has_attribution_in_sentence = bool(
                    (attribution and attribution.lower() in clean_s.lower())
                    or re.search(r"\b(?:previous|prior|earlier|past|other)\s+(?:studies|research|authors|findings)\b", clean_s, re.I)
                    or re.search(r"\([A-Z][a-zA-Z]+(?:\s+et\s+al\.)?[,;]?\s*\d{4}[a-z]?\)", clean_s)
                )
                if not has_attribution_in_sentence:
                    prefix = f"According to {attribution}, " if attribution and attribution != "prior literature" else "Prior studies reported that "
                    first_char = clean_s[0].lower() if len(clean_s) > 1 and clean_s[1].islower() else clean_s[0]
                    clean_s = prefix + first_char + clean_s[1:]
                    issues.append({
                        "sentence_index": idx,
                        "issue": "prior_study_attribution_restored",
                        "attribution": attribution,
                    })

            # 5. Dangling Antecedent Repair (for summary opener or disconnected sentences)
            if DANGLING_ANTECEDENT_PATTERN.search(clean_s):
                is_dangling = False
                if idx == 0:
                    is_dangling = True
                else:
                    from .scoring import extract_content_tokens
                    prev_tokens = extract_content_tokens(summary_sentences[idx - 1])
                    curr_tokens = extract_content_tokens(clean_s)
                    if not (prev_tokens & curr_tokens):
                        is_dangling = True

                if is_dangling:
                    if re.match(r"^(?:This|These)\s+findings\b", clean_s, re.I):
                        clean_s = re.sub(r"^(?:This|These)\s+findings\b", "Research findings", clean_s, flags=re.I)
                        issues.append({"sentence_index": idx, "issue": "dangling_reference_repaired"})
                    elif re.match(r"^(?:This|These)\s+variation\b", clean_s, re.I):
                        clean_s = re.sub(r"^(?:This|These)\s+variation\b", "The observed variation", clean_s, flags=re.I)
                        issues.append({"sentence_index": idx, "issue": "dangling_reference_repaired"})
                    elif re.match(r"^(?:Consequently|Therefore|Thus|However|At the same time),?\s*", clean_s, re.I):
                        clean_s = re.sub(r"^(?:Consequently|Therefore|Thus|However|At the same time),?\s*", "", clean_s, flags=re.I)
                        clean_s = clean_s[0].upper() + clean_s[1:]
                        issues.append({"sentence_index": idx, "issue": "dangling_reference_repaired"})

            clean_s = ensure_terminal_punctuation(clean_s)
            repaired.append(clean_s)

        return repaired, {
            "status": "validated_and_repaired",
            "sentences_repaired_count": len(issues),
            "issues": issues,
        }


def calculate_quality_metrics(
    summary_sentences: list[str],
    candidates: list[SentenceCandidate],
    summary_plan: dict[str, Any] | None = None,
    document_stage: str = "unknown",
) -> Any:
    """Calculate deterministic evaluation metrics for summary quality and safety."""
    from .constants import DANGLING_ANTECEDENT_PATTERN
    from .models import QualityMetrics
    from .scoring import extract_content_tokens
    from .structure import extract_structural_heading

    if not summary_sentences:
        return QualityMetrics(
            coverage_score=0.0,
            redundancy_score=1.0,
            source_support_rate=0.0,
            attribution_preservation_rate=1.0,
            numeric_preservation_rate=1.0,
            qualifier_preservation_rate=1.0,
            section_coverage=0.0,
            dangling_reference_count=0,
            heading_leak_count=0,
        )

    # 1. Coverage Score
    plan = summary_plan or {}
    planned_sections = plan.get("planned_sections", [])
    if planned_sections:
        planned_labels = {item.get("label", "").lower() for item in planned_sections if isinstance(item, dict)}
        covered_count = 0
        summary_blob = " ".join(summary_sentences).lower()
        for label in planned_labels:
            if any(word in summary_blob for word in label.split() if len(word) >= 4):
                covered_count += 1
        coverage_score = round(min(1.0, covered_count / max(1, len(planned_labels))), 4)
    else:
        coverage_score = 1.0

    # 2. Redundancy Score
    max_overlap = 0.0
    for i in range(len(summary_sentences)):
        for j in range(i + 1, len(summary_sentences)):
            t_i = extract_content_tokens(summary_sentences[i])
            t_j = extract_content_tokens(summary_sentences[j])
            if t_i and t_j:
                ov = len(t_i & t_j) / len(t_i | t_j)
                if ov > max_overlap:
                    max_overlap = ov
    redundancy_score = round(max(0.0, 1.0 - max_overlap), 4)

    # 3. Source Support Rate
    supported = 0
    aligned_candidates: list[SentenceCandidate | None] = []
    for s in summary_sentences:
        s_tokens = extract_content_tokens(s)
        best_cand = None
        best_overlap = 0.0
        for cand in candidates:
            c_tokens = extract_content_tokens(cand.text)
            if not s_tokens or not c_tokens:
                continue
            ov = len(s_tokens & c_tokens) / len(s_tokens)
            if ov > best_overlap:
                best_overlap = ov
                best_cand = cand
        if best_overlap >= 0.40:
            supported += 1
            aligned_candidates.append(best_cand)
        else:
            aligned_candidates.append(None)
    source_support_rate = round(supported / len(summary_sentences), 4)

    # 4. Attribution Preservation Rate
    prior_study_count = 0
    attribution_preserved = 0
    for s, cand in zip(summary_sentences, aligned_candidates):
        if cand and cand.source_role == "prior_study":
            prior_study_count += 1
            if (cand.attribution and cand.attribution.lower() in s.lower()) or re.search(r"\b(?:previous|prior|earlier|past|other)\s+(?:studies|research|findings)\b", s, re.I):
                attribution_preserved += 1
    attribution_preservation_rate = (
        round(attribution_preserved / prior_study_count, 4) if prior_study_count > 0 else 1.0
    )

    # 5. Numeric Preservation Rate
    total_source_numbers = 0
    preserved_numbers = 0
    for s, cand in zip(summary_sentences, aligned_candidates):
        if cand and cand.numbers:
            total_source_numbers += len(cand.numbers)
            for num in cand.numbers:
                if num in s:
                    preserved_numbers += 1
    numeric_preservation_rate = (
        round(preserved_numbers / total_source_numbers, 4) if total_source_numbers > 0 else 1.0
    )

    # 6. Qualifier Preservation Rate
    total_qualifiers = 0
    preserved_qualifiers = 0
    for s, cand in zip(summary_sentences, aligned_candidates):
        if cand and cand.qualifiers:
            total_qualifiers += len(cand.qualifiers)
            for q in cand.qualifiers:
                if q.lower() in s.lower():
                    preserved_qualifiers += 1
    qualifier_preservation_rate = (
        round(preserved_qualifiers / total_qualifiers, 4) if total_qualifiers > 0 else 1.0
    )

    # 7. Section Coverage
    all_sections = {c.section for c in candidates if c.section and c.section != "body"}
    summary_sections = {cand.section for cand in aligned_candidates if cand and cand.section and cand.section != "body"}
    section_coverage = (
        round(len(summary_sections) / max(1, len(all_sections)), 4) if all_sections else 1.0
    )

    # 8. Dangling Reference Count
    dangling_count = 0
    for i, s in enumerate(summary_sentences):
        if DANGLING_ANTECEDENT_PATTERN.search(s):
            if i == 0:
                dangling_count += 1
            else:
                prev_tokens = extract_content_tokens(summary_sentences[i - 1])
                curr_tokens = extract_content_tokens(s)
                if not (prev_tokens & curr_tokens):
                    dangling_count += 1

    # 9. Heading Leak Count
    heading_leak_count = sum(
        1 for s in summary_sentences if extract_structural_heading(s)[0] is not None
    )

    # 10. Trend Consistency & Unsupported Numerics
    trend_sentences_count = 0
    consistent_trend_count = 0
    total_unsupported_numerics = 0

    for s, cand in zip(summary_sentences, aligned_candidates):
        if cand:
            contradictions = detect_trend_contradiction(cand.text, s)
            src_trends = extract_trend_signals(cand.text)
            if src_trends:
                trend_sentences_count += 1
                if not contradictions:
                    consistent_trend_count += 1
            num_issues = detect_unsupported_numbers(cand.text, s)
            total_unsupported_numerics += len(num_issues)

    trend_consistency_rate = (
        round(consistent_trend_count / trend_sentences_count, 4) if trend_sentences_count > 0 else 1.0
    )

    return QualityMetrics(
        coverage_score=coverage_score,
        redundancy_score=redundancy_score,
        source_support_rate=source_support_rate,
        attribution_preservation_rate=attribution_preservation_rate,
        numeric_preservation_rate=numeric_preservation_rate,
        qualifier_preservation_rate=qualifier_preservation_rate,
        section_coverage=section_coverage,
        dangling_reference_count=dangling_count,
        heading_leak_count=heading_leak_count,
        trend_consistency_rate=trend_consistency_rate,
        unsupported_numeric_count=total_unsupported_numerics,
    )


__all__ = [
    "FactLedgerEntry",
    "FactualityValidator",
    "build_fact_ledger",
    "calculate_quality_metrics",
    "detect_trend_contradiction",
    "detect_unsupported_numbers",
    "extract_detailed_numbers",
    "extract_trend_signals",
    "validate_summary_facts",
]
