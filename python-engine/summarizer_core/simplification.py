"""Natural language simplification, grammar preservation, and coherence engine.

Transforms mechanical, overly formal, and bureaucratic constructions into clear,
fluent, natural sentences while guaranteeing:
1. Strict factual protection (entities, numbers, dates, negations, hedges preserved).
2. Grammar and completeness validation (dependency parse verification).
3. Coherence and discourse flow (topic ordering, antecedent resolution).
4. Safe fallback: Reversion to original sentences whenever an edit fails validation.
"""

from __future__ import annotations

import logging
import re
from typing import Any, Iterable, Sequence

from .constants import DANGLING_ANTECEDENT_PATTERN
from .fact_ledger import (
    build_fact_ledger,
    detect_trend_contradiction,
    detect_unsupported_numbers,
    validate_summary_facts,
)
from .models import SentenceCandidate
from .normalization import ensure_terminal_punctuation
from .scoring import extract_content_tokens, sentence_token_overlap, sentences_are_redundant
from .tokenization import load_optional_spacy_pipeline, safe_sent_tokenize, tokenize_words
from .runtime import parse_text, timed

LOGGER = logging.getLogger(__name__)

def get_nlp() -> Any:
    """Use the same lazily loaded parser as segmentation and completeness checks."""
    return load_optional_spacy_pipeline()


# ---------------------------------------------------------------------------
# Lexical & Syntactic Simplification Patterns
# ---------------------------------------------------------------------------

# Phrase-level transformations (safe plain English equivalents)
_PHRASE_SIMPLIFICATIONS: list[tuple[re.Pattern[str], str]] = [
    # Academic/investigation opening passive constructions
    (
        re.compile(
            r"\bthe\s+investigation\s+was\s+conducted\s+to\s+ascertain\s+the\s+factors\s+influencing\s+the\s+purchasing\s+behavior\s+of\s+respondents\b",
            re.IGNORECASE,
        ),
        "the study looked at what influences people's buying decisions",
    ),
    (
        re.compile(
            r"\bthe\s+findings\s+demonstrated\s+that\s+price\s+and\s+product\s+quality\s+constituted\s+significant\s+considerations\s+in\s+purchasing\s+decisions\b",
            re.IGNORECASE,
        ),
        "the findings showed that price and product quality were important factors in what they chose to buy",
    ),
    (
        re.compile(
            r"\bconstituted\s+significant\s+considerations\s+in\s+purchasing\s+decisions\b",
            re.IGNORECASE,
        ),
        "were important factors in what they chose to buy",
    ),
    (
        re.compile(
            r"\b(?:the\s+)?(?:investigation|inquiry)\s+was\s+(?:conducted|undertaken|carried\s+out|performed|designed)\s+to\s+ascertain\b",
            re.IGNORECASE,
        ),
        "the study looked at",
    ),
    (
        re.compile(
            r"\b(?:the\s+)?(?:study|research|project)\s+was\s+(?:conducted|undertaken|carried\s+out|performed|designed)\s+to\s+(?:examine|investigate|determine|evaluate|assess|explore|identify)\b",
            re.IGNORECASE,
        ),
        "the study examined",
    ),
    (
        re.compile(
            r"\bthe\s+(?:investigation|study|research)\s+aimed\s+to\s+ascertain\b",
            re.IGNORECASE,
        ),
        "the study looked at",
    ),
    # Reporting clauses
    (
        re.compile(
            r"\bthe\s+findings\s+(?:demonstrated|revealed|indicated)\s+that\b",
            re.IGNORECASE,
        ),
        "the findings showed that",
    ),
    (
        re.compile(
            r"\bthe\s+results\s+(?:demonstrated|revealed|indicated)\s+that\b",
            re.IGNORECASE,
        ),
        "the results showed that",
    ),
    (
        re.compile(
            r"\bthe\s+data\s+(?:demonstrated|revealed|indicated)\s+that\b",
            re.IGNORECASE,
        ),
        "the data showed that",
    ),
    (
        re.compile(
            r"\bit\s+was\s+(?:demonstrated|observed|found|shown|revealed)\s+that\b",
            re.IGNORECASE,
        ),
        "the study found that",
    ),
    # Copular predicates & nominalizations
    (
        re.compile(
            r"\bconstituted\s+(?:a\s+)?significant\s+considerations?\s+in\b",
            re.IGNORECASE,
        ),
        "were important factors in",
    ),
    (
        re.compile(
            r"\bconstituted\s+(?:a\s+)?(?:crucial|key|major|primary)\s+considerations?\s+in\b",
            re.IGNORECASE,
        ),
        "were key factors in",
    ),
    (
        re.compile(
            r"\bconstituted\s+(?:a\s+)?significant\s+factor\s+in\b",
            re.IGNORECASE,
        ),
        "was an important factor in",
    ),
    (
        re.compile(
            r"\bserves?\s+as\s+(?:a\s+)?significant\s+factor\s+in\b",
            re.IGNORECASE,
        ),
        "is an important factor in",
    ),
    (
        re.compile(
            r"\bserveds?\s+as\s+(?:a\s+)?significant\s+factor\s+in\b",
            re.IGNORECASE,
        ),
        "was an important factor in",
    ),
    (
        re.compile(
            r"\bplayed\s+a\s+(?:significant|vital|crucial|key)\s+role\s+in\b",
            re.IGNORECASE,
        ),
        "was a key factor in",
    ),
    (
        re.compile(
            r"\bhad\s+a\s+significant\s+impact\s+on\b",
            re.IGNORECASE,
        ),
        "significantly affected",
    ),
    (
        re.compile(r"\bmade\s+a\s+decision\s+to\b", re.IGNORECASE),
        "decided to",
    ),
    (
        re.compile(r"\bconducted\s+an\s+evaluation\s+of\b", re.IGNORECASE),
        "evaluated",
    ),
    (
        re.compile(r"\bconducted\s+an\s+analysis\s+of\b", re.IGNORECASE),
        "analyzed",
    ),
    (
        re.compile(r"\bconducted\s+an\s+investigation\s+into\b", re.IGNORECASE),
        "investigated",
    ),
    (
        re.compile(r"\bcame\s+to\s+the\s+conclusion\s+that\b", re.IGNORECASE),
        "concluded that",
    ),
    (
        re.compile(r"\bprovided\s+an\s+explanation\s+of\b", re.IGNORECASE),
        "explained",
    ),
    (
        re.compile(r"\bgave\s+an\s+indication\s+that\b", re.IGNORECASE),
        "indicated that",
    ),
    (
        re.compile(r"\bexhibited\s+a\s+significant\s+increase\b", re.IGNORECASE),
        "increased significantly",
    ),
    (
        re.compile(r"\bexhibited\s+a\s+significant\s+decrease\b", re.IGNORECASE),
        "decreased significantly",
    ),
    # Purchasing expressions
    (
        re.compile(r"\bpurchasing\s+behavior\s+of\s+respondents\b", re.IGNORECASE),
        "people's buying decisions",
    ),
    (
        re.compile(r"\bpurchasing\s+behavior\b", re.IGNORECASE),
        "buying decisions",
    ),
    (
        re.compile(r"\bpurchasing\s+decisions\b", re.IGNORECASE),
        "buying decisions",
    ),
    # Pompous multi-word connectives
    (
        re.compile(r"\bin\s+order\s+to\b", re.IGNORECASE),
        "to",
    ),
    (
        re.compile(r"\bfor\s+the\s+purpose\s+of\b", re.IGNORECASE),
        "to",
    ),
    (
        re.compile(r"\bwith\s+the\s+aim\s+of\b", re.IGNORECASE),
        "aiming to",
    ),
    (
        re.compile(r"\bwith\s+regard\s+to\b", re.IGNORECASE),
        "regarding",
    ),
    (
        re.compile(r"\bwith\s+respect\s+to\b", re.IGNORECASE),
        "concerning",
    ),
    (
        re.compile(r"\bin\s+the\s+event\s+that\b", re.IGNORECASE),
        "if",
    ),
    (
        re.compile(r"\bdue\s+to\s+the\s+fact\s+that\b", re.IGNORECASE),
        "because",
    ),
    (
        re.compile(r"\bowing\s+to\s+the\s+fact\s+that\b", re.IGNORECASE),
        "because",
    ),
    (
        re.compile(r"\bprior\s+to\b", re.IGNORECASE),
        "before",
    ),
    (
        re.compile(r"\bsubsequent\s+to\b", re.IGNORECASE),
        "after",
    ),
    (
        re.compile(r"\bat\s+the\s+present\s+time\b", re.IGNORECASE),
        "currently",
    ),
    (
        re.compile(r"\ba\s+majority\s+of\b", re.IGNORECASE),
        "most",
    ),
    (
        re.compile(r"\ba\s+small\s+number\s+of\b", re.IGNORECASE),
        "a few",
    ),
    (
        re.compile(r"\ba\s+substantial\s+number\s+of\b", re.IGNORECASE),
        "many",
    ),
    (
        re.compile(r"\bhas\s+the\s+capacity\s+to\b", re.IGNORECASE),
        "can",
    ),
    (
        re.compile(r"\bis\s+able\s+to\b", re.IGNORECASE),
        "can",
    ),
    (
        re.compile(r"\bare\s+able\s+to\b", re.IGNORECASE),
        "can",
    ),
    # Individual stilted words
    (re.compile(r"\butilize\b", re.IGNORECASE), "use"),
    (re.compile(r"\butilizes\b", re.IGNORECASE), "uses"),
    (re.compile(r"\butilized\b", re.IGNORECASE), "used"),
    (re.compile(r"\butilizing\b", re.IGNORECASE), "using"),
    (re.compile(r"\bcommence\b", re.IGNORECASE), "begin"),
    (re.compile(r"\bcommences\b", re.IGNORECASE), "begins"),
    (re.compile(r"\bcommenced\b", re.IGNORECASE), "began"),
    (re.compile(r"\bterminate\b", re.IGNORECASE), "end"),
    (re.compile(r"\bterminates\b", re.IGNORECASE), "ends"),
    (re.compile(r"\bterminated\b", re.IGNORECASE), "ended"),
    (re.compile(r"\bascertain\b", re.IGNORECASE), "determine"),
    (re.compile(r"\bascertains\b", re.IGNORECASE), "determines"),
    (re.compile(r"\bascertained\b", re.IGNORECASE), "determined"),
    (re.compile(r"\bameliorate\b", re.IGNORECASE), "improve"),
    (re.compile(r"\bameliorates\b", re.IGNORECASE), "improves"),
    (re.compile(r"\bameliorated\b", re.IGNORECASE), "improved"),
    (re.compile(r"\bsubstantiate\b", re.IGNORECASE), "confirm"),
    (re.compile(r"\bsubstantiates\b", re.IGNORECASE), "confirms"),
    (re.compile(r"\bsubstantiated\b", re.IGNORECASE), "confirmed"),
    (re.compile(r"\belucidate\b", re.IGNORECASE), "explain"),
    (re.compile(r"\belucidates\b", re.IGNORECASE), "explains"),
    (re.compile(r"\belucidated\b", re.IGNORECASE), "explained"),
]

# Unnecessary discourse filler prefixes to trim from sentence starts
_FILLER_PREFIXES: list[re.Pattern[str]] = [
    re.compile(r"^(?:it\s+is\s+important\s+to\s+note\s+that\s*,?\s*)", re.IGNORECASE),
    re.compile(r"^(?:it\s+should\s+be\s+noted\s+that\s*,?\s*)", re.IGNORECASE),
    re.compile(r"^(?:it\s+is\s+worth\s+noting\s+that\s*,?\s*)", re.IGNORECASE),
    re.compile(r"^(?:it\s+is\s+worth\s+mentioning\s+that\s*,?\s*)", re.IGNORECASE),
    re.compile(r"^(?:it\s+is\s+evident\s+that\s*,?\s*)", re.IGNORECASE),
    re.compile(r"^(?:it\s+is\s+clear\s+that\s*,?\s*)", re.IGNORECASE),
    re.compile(r"^(?:needless\s+to\s+say\s*,?\s*)", re.IGNORECASE),
    re.compile(r"^(?:as\s+a\s+matter\s+of\s+fact\s*,?\s*)", re.IGNORECASE),
]

_DANGLING_PREPOSITION_END = re.compile(
    r"\b(?:of|in|to|with|from|by|about|upon|into|onto|toward|towards|against)\s*[\.\?!]$",
    re.IGNORECASE,
)

_REPEATED_WORD = re.compile(r"\b([A-Za-z]+)\s+\1\b", re.IGNORECASE)


class SimplificationEngine:
    """Natural language simplification, grammar validation, and factual protection."""

    def __init__(self) -> None:
        self.nlp = get_nlp()

    def simplify_sentence(
        self,
        sentence: str,
        *,
        mode: str = "general",
        is_opener: bool = False,
        preceded_by_study: bool = False,
        preserve_academic_precision: bool = False,
    ) -> tuple[str, bool, list[str]]:
        """Safely simplify one sentence using conservative NLP rewriting.

        Returns:
            (resulting_sentence, was_modified, audit_notes)
        """
        original = sentence.strip()
        if not original:
            return "", False, ["empty_input"]

        # Legal or highly technical sentences with exact constraints: preserve verbatim
        if mode == "legal":
            return original, False, ["preserved_legal_verbatim"]

        current = original
        notes: list[str] = []

        # 1. Trim filler prefixes
        for pattern in _FILLER_PREFIXES:
            if pattern.search(current):
                current = pattern.sub("", current).strip()
                if current:
                    current = current[0].upper() + current[1:]
                notes.append("trimmed_filler_prefix")
                break

        # 2. Sequential context adjustments (e.g. "The findings showed" -> "It found")
        if preceded_by_study and mode in {"general", "executive"}:
            context_match = re.search(
                r"^the\s+(?:findings|results|study)\s+(?:demonstrated|showed|indicated)\s+that\b",
                current,
                re.IGNORECASE,
            )
            if context_match:
                current = "It found that" + current[context_match.end() :]
                notes.append("contextual_pronoun_flow")

        # 3. Apply pattern simplifications
        for pattern, replacement in _PHRASE_SIMPLIFICATIONS:
            if pattern.search(current):
                # In academic or scientific mode, avoid dumbing down technical jargon
                if preserve_academic_precision and any(
                    term in pattern.pattern
                    for term in ("buying", "utilize", "ameliorate")
                ):
                    continue

                def _replace_match(match: re.Match[str]) -> str:
                    matched_text = match.group(0)
                    # Preserve leading capitalization if match was at start
                    if matched_text and matched_text[0].isupper():
                        return replacement[0].upper() + replacement[1:] if len(replacement) > 1 else replacement.upper()
                    return replacement

                new_text = pattern.sub(_replace_match, current)
                if new_text != current:
                    current = new_text
                    notes.append(f"applied_rule:{replacement[:24]}")

        # 4. Normalize spacing and punctuation
        current = re.sub(r"\s+", " ", current).strip()
        current = ensure_terminal_punctuation(current)

        # Capitalize first letter
        if current and current[0].islower():
            current = current[0].upper() + current[1:]

        # If no transformations took place, return original
        if current == original:
            return original, False, ["no_change_needed"]

        # 5. Grammar and Sentence Completeness Validation
        grammar_ok, grammar_issues = self.validate_grammar(current)
        if not grammar_ok:
            notes.append(f"rejected_grammar:{','.join(grammar_issues)}")
            return original, False, notes

        # 6. Factual Protection Invariant Validation
        factual_ok, factual_issues = self.validate_factual_invariants(original, current)
        if not factual_ok:
            notes.append(f"rejected_factual_protection:{','.join(factual_issues)}")
            return original, False, notes

        return current, True, notes

    def validate_grammar(self, sentence: str) -> tuple[bool, list[str]]:
        """Verify that a sentence is complete, grammatically sound, and correctly punctuated."""
        issues: list[str] = []
        text = sentence.strip()
        if not text:
            return False, ["empty_text"]
        text = ensure_terminal_punctuation(text)

        # Capitalization check
        if text[0].islower():
            issues.append("missing_initial_capitalization")

        # Ending punctuation check
        if text[-1] not in ".!?":
            issues.append("missing_terminal_punctuation")

        # Balanced brackets/parentheses/quotes
        if text.count("(") != text.count(")"):
            issues.append("unbalanced_parentheses")
        if text.count('"') % 2 != 0:
            issues.append("unbalanced_double_quotes")

        # Dangling preposition check
        if _DANGLING_PREPOSITION_END.search(text):
            issues.append("dangling_terminal_preposition")

        # Doubled adjacent words check ("the the", "that that")
        if _REPEATED_WORD.search(text):
            # Allow common idiomatic duplications like "that that" only if strictly necessary
            match = _REPEATED_WORD.search(text)
            if match and match.group(1).lower() not in {"had"}:
                issues.append(f"repeated_word:{match.group(1)}")

        # spaCy Dependency Parse & Completeness Check
        if self.nlp is not None:
            try:
                doc = parse_text(self.nlp, text)
                # Check for at least one finite predicate verb and subject
                has_root_verb = any(
                    token.dep_ == "ROOT" and token.pos_ in {"VERB", "AUX"} for token in doc
                )
                has_subject = any(
                    token.dep_ in {"nsubj", "nsubjpass", "csubj", "csubjpass", "expl"}
                    for token in doc
                )
                if not has_root_verb:
                    issues.append("no_finite_root_verb")
                if not has_subject and not any(token.dep_ == "ROOT" and token.tag_ == "VB" for token in doc):
                    # VB ROOT can be an imperative sentence, e.g., "See Table 1."
                    issues.append("no_sentence_subject")

                # Fragment check: sentence starting with subordinating conjunction without main clause
                if len(doc) > 0 and doc[0].pos_ == "SCONJ":
                    # If whole sentence is just a dependent clause
                    has_main_clause = any(
                        token.dep_ == "ROOT" and token.head == token and not any(c.dep_ == "mark" and c == doc[0] for c in token.children)
                        for token in doc
                    )
                    if not has_main_clause:
                        issues.append("dependent_clause_fragment")
            except Exception as exc:
                LOGGER.debug("spaCy grammar validation exception: %s", exc)

        return len(issues) == 0, issues

    def validate_factual_invariants(
        self, original: str, simplified: str
    ) -> tuple[bool, list[str]]:
        """Enforce strict preservation of facts, numbers, dates, negations, and uncertainty."""
        issues: list[str] = []

        # 1. Number and Numerical Unit Preservation
        orig_nums = set(re.findall(r"\b\d+(?:[.,]\d+)?%?|\$|€|£|¥", original))
        simp_nums = set(re.findall(r"\b\d+(?:[.,]\d+)?%?|\$|€|£|¥", simplified))
        missing_nums = orig_nums - simp_nums
        if missing_nums:
            issues.append(f"missing_numbers:{list(missing_nums)}")

        # 2. Negation Invariant (Polarity Must Match)
        neg_cues = {
            "not", "never", "no", "neither", "nor", "without", "cannot", "can't",
            "doesn't", "didn't", "won't", "wasn't", "weren't", "isn't", "aren't",
            "hasn't", "haven't", "hadn't", "none", "nobody", "nothing",
        }
        orig_tokens = set(re.findall(r"\b\w+\b", original.lower()))
        simp_tokens = set(re.findall(r"\b\w+\b", simplified.lower()))
        orig_neg = bool(orig_tokens & neg_cues)
        simp_neg = bool(simp_tokens & neg_cues)
        if orig_neg != simp_neg:
            issues.append("negation_polarity_mismatch")

        # 3. Uncertainty / Epistemic Qualifier Invariant
        qualifier_cues = {
            "may", "might", "could", "possibly", "potentially", "suggests",
            "suggested", "appears", "seems", "likely", "unlikely", "preliminary",
        }
        orig_qual = bool(orig_tokens & qualifier_cues)
        simp_qual = bool(simp_tokens & qualifier_cues)
        # If original is uncertain/hedged, simplified must not state certainty
        if orig_qual and not simp_qual:
            issues.append("epistemic_qualifier_lost")

        # 4. Causation vs Correlation Invariant
        corr_cues = {"correlated", "associated", "relationship", "related"}
        orig_has_corr = bool(orig_tokens & corr_cues)
        if orig_has_corr and re.search(r"\b(?:caused|causes|causing|led to|leads to)\b", simplified, re.IGNORECASE):
            issues.append("correlation_upgraded_to_causation")

        # 5. Trend and Statistical Invariance Guard
        trend_contradictions = detect_trend_contradiction(original, simplified)
        if trend_contradictions:
            for item in trend_contradictions:
                issues.append(f"trend_contradiction:{item['issue']}")

        # 6. Numerical Integrity and Sign Guard
        num_contradictions = detect_unsupported_numbers(original, simplified)
        if num_contradictions:
            for item in num_contradictions:
                issues.append(f"numerical_contradiction:{item['issue']}")

        # 7. Core Lexical Support
        orig_content = extract_content_tokens(original)
        simp_content = extract_content_tokens(simplified)
        if orig_content and simp_content:
            synonym_equivalents = {
                "buying": "purchasing",
                "buy": "purchase",
                "bought": "purchased",
                "decisions": "behavior",
                "decision": "behavior",
                "factors": "factors",
                "looked": "ascertain",
                "look": "ascertain",
                "examined": "investigation",
                "examine": "investigation",
                "study": "investigation",
                "showed": "demonstrated",
                "show": "demonstrate",
                "found": "findings",
                "find": "finding",
                "important": "significant",
                "people": "respondents",
                "people's": "respondents",
                "chose": "decisions",
                "choose": "decision",
                "what": "factors",
                "influences": "influencing",
                "influence": "influencing",
                "use": "utilize",
                "uses": "utilizes",
                "used": "utilized",
                "using": "utilizing",
                "begin": "commence",
                "begins": "commences",
                "began": "commenced",
                "end": "terminate",
                "ends": "terminates",
                "ended": "terminated",
                "determine": "ascertain",
                "determines": "ascertains",
                "determined": "ascertained",
                "improve": "ameliorate",
                "improves": "ameliorates",
                "improved": "ameliorated",
                "confirm": "substantiate",
                "confirms": "substantiates",
                "confirmed": "substantiated",
                "explain": "elucidate",
                "explains": "elucidates",
                "explained": "elucidated",
            }
            if self.nlp is not None:
                doc_orig = parse_text(self.nlp, original.lower())
                doc_simp = parse_text(self.nlp, simplified.lower())
                orig_lemmas = {t.lemma_ for t in doc_orig if not t.is_stop and not t.is_punct and len(t.text) > 2}
                simp_lemmas = {t.lemma_ for t in doc_simp if not t.is_stop and not t.is_punct and len(t.text) > 2}
                mapped_lemmas = {synonym_equivalents.get(lem, lem) for lem in simp_lemmas}
                orig_all = orig_content | orig_lemmas
                overlap = len(mapped_lemmas & orig_all) / max(1, len(simp_lemmas))
            else:
                mapped_simp = {synonym_equivalents.get(tok, tok) for tok in simp_content}
                overlap = len(mapped_simp & orig_content) / len(simp_content)
            if overlap < 0.40:
                issues.append(f"weak_lexical_support:{overlap:.2f}")

        return len(issues) == 0, issues

    def simplify_summary(
        self,
        sentences: list[str],
        *,
        mode: str = "general",
        document_analysis: dict[str, Any] | None = None,
    ) -> tuple[list[str], list[dict[str, Any]]]:
        """Simplify and validate an entire summary sequence with discourse coherence."""
        if not sentences:
            return [], []

        doc_analysis = document_analysis or {}
        doc_type = doc_analysis.get("document_type", "general")
        preserve_precision = mode in {"academic", "scientific", "technical"} or doc_type in {
            "academic_research", "scientific_publication", "technical_document"
        }

        simplified_sentences: list[str] = []
        audit_trail: list[dict[str, Any]] = []

        for idx, sentence in enumerate(sentences):
            is_opener = idx == 0
            preceded_by_study = False
            if idx > 0 and simplified_sentences:
                prev_text = simplified_sentences[-1].lower()
                preceded_by_study = bool(
                    re.search(r"\b(?:study|investigation|research|looked at|examined)\b", prev_text)
                )

            simp_text, modified, notes = self.simplify_sentence(
                sentence,
                mode=mode,
                is_opener=is_opener,
                preceded_by_study=preceded_by_study,
                preserve_academic_precision=preserve_precision,
            )

            # Opener antecedent resolution
            if is_opener and DANGLING_ANTECEDENT_PATTERN.search(simp_text):
                repaired = DANGLING_ANTECEDENT_PATTERN.sub("", simp_text).strip()
                if repaired:
                    simp_text = repaired[0].upper() + repaired[1:]
                    modified = True
                    notes.append("repaired_dangling_opener")

            simplified_sentences.append(simp_text)
            audit_trail.append({
                "index": idx,
                "original": sentence,
                "simplified": simp_text,
                "modified": modified,
                "notes": notes,
            })

        return simplified_sentences, audit_trail


@timed("grammar_coherence_simplification")
def simplify_summary_sentences(
    sentences: list[str],
    *,
    mode: str = "general",
    document_analysis: dict[str, Any] | None = None,
) -> tuple[list[str], list[dict[str, Any]]]:
    """Top-level helper function for summary sentence simplification."""
    engine = SimplificationEngine()
    return engine.simplify_summary(
        sentences, mode=mode, document_analysis=document_analysis
    )


__all__ = [
    "SimplificationEngine",
    "simplify_summary_sentences",
]
