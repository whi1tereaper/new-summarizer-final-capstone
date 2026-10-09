"""Conservative source-fact inventory and extractive output diagnostics.

These checks are lexical diagnostics, not semantic entailment or proof of truth.
They deliberately return issue categories and stable sentence IDs, not source text.
"""

from __future__ import annotations

from dataclasses import dataclass
import re
from typing import Iterable

from .models import SentenceCandidate

_NUMBER = re.compile(
    r"(?<![\w.])(?:[$€£]\s*)?[-+]?\d[\d,]*(?:\.\d+)?(?:\s*(?:%|percent(?:age points?)?|"
    r"degrees?|°\s*[CF]|mg|mcg|μg|g|kg|ml|mL|L|km|miles?|hours?|minutes?|days?|weeks?|months?|years?))?(?!\w)",
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
    r"not statistically significant|statistically significant)\b",
    re.IGNORECASE,
)
_NEGATION = re.compile(r"\b(?:not|no|never|without|neither|nor|did not|does not|do not|cannot|can't|n't)\b", re.IGNORECASE)
_COMPARISON = re.compile(
    r"\b(?:compared with|compared to|versus|vs\.?|more than|less than|higher than|"
    r"lower than|greater than|fewer than|at least|at most|between|from .{1,40} to)\b",
    re.IGNORECASE,
)
_WORD = re.compile(r"\w+", re.UNICODE)


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
    issues: list[dict[str, object]] = []
    if not summary_sentences:
        return {"status": "not_applicable", "sentences_checked": 0, "issues": []}
    if not ledger:
        return {"status": "unavailable", "sentences_checked": 0, "issues": []}

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

            # 3. Proposal Safety Check:
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

            # 5. Dangling Antecedent Repair (for summary opener)
            if idx == 0 and DANGLING_ANTECEDENT_PATTERN.search(clean_s):
                if re.match(r"^(?:This|These)\s+findings\b", clean_s, re.I):
                    clean_s = re.sub(r"^(?:This|These)\s+findings\b", "Research findings", clean_s, flags=re.I)
                    issues.append({"sentence_index": idx, "issue": "dangling_opener_repaired"})
                elif re.match(r"^(?:This|These)\s+variation\b", clean_s, re.I):
                    clean_s = re.sub(r"^(?:This|These)\s+variation\b", "The observed variation", clean_s, flags=re.I)
                    issues.append({"sentence_index": idx, "issue": "dangling_opener_repaired"})
                elif re.match(r"^(?:Therefore|Thus|However|At the same time),?\s*", clean_s, re.I):
                    clean_s = re.sub(r"^(?:Therefore|Thus|However|At the same time),?\s*", "", clean_s, flags=re.I)
                    clean_s = clean_s[0].upper() + clean_s[1:]
                    issues.append({"sentence_index": idx, "issue": "dangling_opener_repaired"})

            if clean_s and clean_s[-1] not in ".!?":
                clean_s += "."
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
    )


__all__ = [
    "FactLedgerEntry",
    "FactualityValidator",
    "build_fact_ledger",
    "calculate_quality_metrics",
    "validate_summary_facts",
]
