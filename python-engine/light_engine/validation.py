"""Source-provenance validation and optional local grammar diagnostics."""

from __future__ import annotations

from typing import TYPE_CHECKING, Any

from .contract import ContractError
from .extraction import SourceSentence

if TYPE_CHECKING:
    from summarizer_core.grammar_check import LocalGrammarChecker


def _local_grammar_checker() -> LocalGrammarChecker:
    from summarizer_core.grammar_check import LocalGrammarChecker

    return LocalGrammarChecker.from_environment()


def validate_candidates(
    sentences: list[SourceSentence],
    source_text: str,
) -> tuple[dict[str, Any], dict[str, Any]]:
    """Validate exact source spans and report local grammar checks without rewriting."""
    if any(
        sentence.start < 0
        or sentence.end < sentence.start
        or sentence.end > len(source_text)
        or source_text[sentence.start:sentence.end] != sentence.text
        or not sentence.text.strip()
        for sentence in sentences
    ):
        raise ContractError(
            "provenance_failure",
            "A selected summary sentence is not an exact source excerpt.",
        )
    if len({sentence.index for sentence in sentences}) != len(sentences):
        raise ContractError("provenance_failure", "Summary evidence contains duplicate source sentences.")

    source_guard = {
        "status": "exact_source_validated",
        "method": "source_offset_and_substring_validation",
        "sentences_checked": len(sentences),
        "sentences_restored_to_source": 0,
        "sentences_omitted_without_source_match": 0,
        "semantic_entailment_measured": False,
        "issues": {},
    }
    checker = _local_grammar_checker()
    if not checker.available:
        reason = checker.unavailable_reason
        safe_reason = (
            "local_languagetool_not_prepared"
            if reason == "local_languagetool_not_prepared"
            else "local_languagetool_start_failed"
        )
        return source_guard, {
            "status": "unavailable",
            "method": "local_grammar_checker",
            "reason": safe_reason,
            "issue_count": 0,
            "issues_by_sentence": [],
        }

    try:
        issues_by_sentence = []
        for summary_index, sentence in enumerate(sentences):
            issues = checker.check(sentence.text)
            if issues:
                issues_by_sentence.append({
                    "summary_sentence_index": summary_index,
                    "issue_count": len(issues),
                    "rule_ids": sorted({
                        str(issue.get("rule_id", "RULE"))
                        for issue in issues
                        if isinstance(issue, dict)
                    }),
                })
        return source_guard, {
            "status": "checked",
            "method": "local_grammar_checker",
            "issue_count": sum(item["issue_count"] for item in issues_by_sentence),
            "issues_by_sentence": issues_by_sentence,
        }
    finally:
        checker.close()
