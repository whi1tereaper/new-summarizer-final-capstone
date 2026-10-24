"""Conservative context dependencies and immutable source-evidence validation."""
from __future__ import annotations
import re
from failure_contract import ProcessingFailure
from .coverage import REFERENCE, FOLLOWING_CAVEAT, QUANTITY, QUALIFICATION, ROLES


def evidence_bundle(records, index, *, guard=True):
    bundle = {index}
    if not guard:
        return bundle
    cursor = index
    # Connectives can refer across paragraph boundaries. Limit closure rather
    # than recursively dragging an entire article into one evidence bundle.
    for _ in range(3):
        if not REFERENCE.search(records[cursor].text):
            break
        if cursor == 0:
            return None
        cursor -= 1
        bundle.add(cursor)
    if REFERENCE.search(records[cursor].text):
        return None
    following = index + 1
    if following < len(records):
        result_like = bool(re.search(ROLES["finding"], records[index].text, re.I))
        if (result_like and FOLLOWING_CAVEAT.search(records[following].text)
                and (records[following].paragraph == records[index].paragraph
                     or records[following].section == records[index].section)):
            bundle.add(following)
    return bundle


def validate_selected(selected, records, text, *, guard=True):
    indices = {s.index for s in selected}
    if len(indices) != len(selected):
        raise ProcessingFailure("SUMMARY_QUALITY_FAILED", "Duplicate source evidence.")
    for sentence in selected:
        if (sentence.index >= len(records) or records[sentence.index] != sentence
                or sentence.start < 0 or sentence.end > len(text)
                or text[sentence.start:sentence.end] != sentence.text):
            raise ProcessingFailure("SUMMARY_QUALITY_FAILED", "Source evidence was changed.")
        required = evidence_bundle(records, sentence.index, guard=guard)
        if required is None or not required.issubset(indices):
            raise ProcessingFailure("SUMMARY_QUALITY_FAILED", "Required factual context is missing.")
    return {"status": "exact_source_and_context_validated", "method": "source_spans_and_context_dependencies",
            "sentences_checked": len(selected), "semantic_entailment_measured": False,
            "quantity_spans_preserved": sum(len(QUANTITY.findall(s.text)) for s in selected),
            "qualification_spans_preserved": sum(len(QUALIFICATION.findall(s.text)) for s in selected),
            "context_checks_enabled": guard, "issues": []}
