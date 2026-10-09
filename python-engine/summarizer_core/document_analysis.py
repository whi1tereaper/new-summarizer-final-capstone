"""Request-local document classification, claim labeling, and summary planning.

The signals here are intentionally transparent lexical and structural cues. They
organize source sentences and prevent common status mix-ups; they do not claim
to provide semantic entailment or calibrated probabilities.
"""

from __future__ import annotations

from collections import Counter, defaultdict
from dataclasses import replace
import re
from typing import Any, Iterable

from .models import DocumentStageDetection, SentenceCandidate
from .structure import extract_structural_heading, match_section_heading



_WORD = re.compile(r"[\w'-]+", re.UNICODE)
_CITATION = re.compile(r"\([^()]{0,80}\b(?:19|20)\d{2}[a-z]?\b[^()]{0,30}\)|\b(?:doi|et al\.)\b", re.I)
_NUMBER = re.compile(r"(?<!\w)[+-]?\d[\d,]*(?:\.\d+)?\s*(?:%|percent|p\s*[<>=]|α\s*=\s*0?\.\d+)?", re.I)
_ACADEMIC_SECTIONS = {"abstract", "introduction", "literature_review", "methodology", "results", "discussion", "conclusion", "limitations", "future_work"}
_ROLE_BY_SECTION = {
    "abstract": "context", "introduction": "context", "background": "context",
    "literature_review": "literature", "problem": "problem", "purpose": "objective",
    "methodology": "method", "results": "result", "discussion": "discussion",
    "conclusion": "conclusion", "recommendations": "recommendation",
    "limitations": "limitation", "future_work": "recommendation",
    "legal_basis": "context", "policy_scope": "scope", "obligations": "process",
    "definitions": "definition", "executive_summary": "context",
    "system_description": "process", "overview": "context", "comparison": "discussion",
    "evaluation": "result", "solution": "process", "date_and_venue": "context",
    "highlights": "result", "outcome": "result", "agenda": "context",
    "decision": "result", "action item": "recommendation", "body": "other",
}

_CUE_GROUPS: dict[str, dict[str, tuple[str, ...]]] = {
    "academic_research": {
        "research_structure": ("abstract", "literature_review", "methodology", "results", "discussion", "hypothesis", "research_question"),
        "research_terms": ("research design", "respondents", "sampling", "questionnaire", "statistical analysis", "research objective", "independent variable", "dependent variable"),
        "citations": (),
        "quantitative_evidence": ("p-value", "pearson", "likert", "confidence interval", "correlation", "regression", "participants"),
    },
    "technical_document": {
        "technical_terms": ("api", "endpoint", "architecture", "configuration", "protocol", "dependency", "deployment", "requirements", "system design", "database schema"),
        "technical_structure": ("system_description", "implementation details", "requirements", "configuration", "architecture"),
    },
    "business_report": {
        "business_terms": ("revenue", "profit", "market share", "quarterly", "kpi", "forecast", "sales performance", "operating cost", "stakeholder"),
        "business_structure": ("executive_summary", "financial results", "business objectives", "market analysis"),
    },
    "news_article": {
        "news_terms": ("officials said", "according to police", "press release", "breaking news", "reporters", "announced", "the agency said"),
        "news_structure": ("lead", "byline", "dateline", "published"),
    },
    "legal_policy_document": {
        "legal_terms": ("pursuant to", "hereby", "statute", "regulation", "shall be", "whereas", "legal basis", "compliance", "section §"),
        "legal_structure": ("legal_basis", "policy_scope", "obligations", "definitions"),
    },
    "educational_material": {
        "education_terms": ("learning objective", "lesson", "course module", "students will learn", "exercise", "assessment", "chapter objectives"),
        "education_structure": ("lesson objectives", "learning outcomes", "exercise", "chapter"),
    },
    "essay": {
        "argument_terms": ("this essay argues", "i argue", "the central argument", "on the other hand", "in contrast", "therefore, we should"),
        "argument_structure": ("thesis statement", "counterargument"),
    },
}

_CUE_PATTERNS: dict[str, re.Pattern[str]] = {
    "objective": re.compile(r"\b(?:aims?|seeks?|intends?|objectives?|purpose is|research question|asks? whether|investigates?)\b", re.I),
    "hypothesis": re.compile(r"\b(?:hypothes(?:ize|izes|ized|izing)\s+that|the hypothesis is|hypothesis predicts?|we expect that)\b", re.I),
    "future": re.compile(r"\b(?:will|shall|plans? to|planned to|intends? to|aims? to|seeks? to|proposes? to|is expected to|are expected to)\b", re.I),
    "actual": re.compile(r"\b(?:found|observed|measured|recorded|increased|decreased|remained|was associated|were associated|was correlated|were correlated|demonstrated|showed|revealed|indicated|reported|states?|argues?|achieved)\b", re.I),
    "decision": re.compile(
        r"\b(?:(?:hospital|company|corporate|executive|advisory)?\s*board\s+approved|"
        r"board\s+approved|executives?\s+approved|budget\s+allocation|strategic\s+investment|"
        r"management\s+decided|committee\s+approved|approved\s+a\s+[\$€£¥₱]\d|"
        r"officially\s+adopted|voted\s+to\s+approve)\b",
        re.I,
    ),
    "risk": re.compile(r"\b(?:risks?|threats?|vulnerabilit(?:y|ies)|concerns?|hazards?|downside|liabilit(?:y|ies)|regulatory\s+risks?)\b", re.I),
    "recommendation": re.compile(r"\b(?:recommend(?:s|ed|ation)?|should consider|future work should|we propose that|must be addressed)\b", re.I),
    "limitation": re.compile(r"\b(?:limitation|limited by|did not include|was not designed to|caveat|constraint)\b", re.I),
    "conclusion": re.compile(r"\b(?:in conclusion|to conclude|we conclude|the study concludes|it can be concluded|overall,? the (?:study|authors?) conclude)\b", re.I),
    "definition": re.compile(r"\b(?:is defined as|are defined as|refers to|is the process of|means that)\b", re.I),
    "prior_literature": re.compile(
        r"(?i:\b(?:previous|prior|earlier|reviewed|past|other)\s+(?:stud(?:y|ies)|research|literature|investigations?|authors?|findings?)\b)|"
        r"\baccording to\b|"
        r"\b[A-Z][a-zA-Z]+(?:\s+(?:and|&)\s+[A-Z][a-zA-Z]+|\s+et\s+al\.)\s*(?:\([0-9]{4}[a-z]?\)|\b(?:found|showed|reported|observed|demonstrated|argued|noted|concluded)\b)|"
        r"\b[A-Z][a-zA-Z]+\s*\([0-9]{4}[a-z]?\)\s*(?:found|showed|reported|observed|demonstrated|argued|noted|concluded)\b|"
        r"\([^()]{0,80}\b(?:19|20)\d{2}[a-z]?\b[^()]{0,30}\)",
    ),
}

_QUALIFIER = re.compile(
    r"\b(?:approximately|about|around|estimated|estimate|may|might|could|possibly|"
    r"potentially|likely|unlikely|suggests?|appears? to|seems? to|preliminary|"
    r"not statistically significant|statistically significant)\b",
    re.I,
)
_NEGATION = re.compile(
    r"\b(?:not|no|never|without|neither|nor|did not|does not|do not|cannot|can't|n't)\b",
    re.I,
)

_STATUS_LABELS = {
    "ACTUAL_RESULT": "actual_result",
    "CURRENT_RESULT": "current_result",
    "PROPOSED_METHOD": "proposed_method",
    "RESEARCH_OBJECTIVE": "research_objective",
    "RESEARCH_QUESTION": "research_question",
    "HYPOTHESIS": "hypothesis",
    "LITERATURE_FINDING": "literature_finding",
    "PRIOR_STUDY_RESULT": "prior_study_result",
    "AUTHOR_CLAIM": "author_claim",
    "BACKGROUND_INFORMATION": "background_information",
    "THEORETICAL_CLAIM": "theoretical_claim",
    "DEFINITION": "definition",
    "LIMITATION": "limitation",
    "RECOMMENDATION": "recommendation",
    "DECISION": "decision",
    "RISK": "risk",
    "UNKNOWN": "unknown",
}



def _terms_present(text: str, terms: Iterable[str]) -> int:
    lowered = text.casefold()
    return sum(1 for term in terms if term.casefold() in lowered)


def _section_role(section: str) -> str:
    return _ROLE_BY_SECTION.get(section, "other")


def classify_claim_with_provenance(
    text: str,
    section: str = "body",
    subsection: str = "",
    document_stage: str = "unknown",
) -> tuple[str, str, str, str, tuple[str, ...], tuple[str, ...], bool, float]:
    """Extract fine-grained claim classification and sentence provenance."""
    lowered = text.casefold()

    numbers = tuple(dict.fromkeys(match.group(0).strip() for match in _NUMBER.finditer(text)))
    qualifiers = tuple(dict.fromkeys(match.group(0).strip() for match in _QUALIFIER.finditer(text)))
    negation = bool(_NEGATION.search(text))

    # Attribution extraction
    attribution = ""
    author_m = re.search(
        r"\b([A-Z][a-zA-Z]+(?:\s+(?:and|&)\s+[A-Z][a-zA-Z]+|\s+et\s+al\.)?)\s*(?:\([0-9]{4}[a-z]?\)|\b(?:found|showed|reported|observed|indicated|argued|noted)\b)",
        text,
    )
    non_author_words = frozenset({
        "the", "this", "these", "those", "our", "their", "results", "findings",
        "data", "table", "figure", "analysis", "survey", "study", "research",
        "evidence", "model", "investigation", "interview", "respondents",
        "participants", "section", "chapter", "paper", "scores", "outcomes",
        "authors", "researchers", "students", "teachers", "values",
    })
    if author_m:
        lead_word = author_m.group(1).split()[0].lower()
        if lead_word not in non_author_words:
            attribution = author_m.group(0).strip()
    if not attribution:
        cite_m = re.search(r"\(([A-Z][A-Za-z]+(?:\s+et\s+al\.)?[,;]?\s*\d{4}[a-z]?)\)", text)
        if cite_m:
            attribution = cite_m.group(1).strip()

    # Rule 1: Prior literature / RRL
    is_prior_lit = False
    if section in {"literature_review", "related_work"} or subsection.lower() in {"literature review", "synthesis", "research gap", "related literature"}:
        is_prior_lit = True
    elif _CUE_PATTERNS["prior_literature"].search(text):
        is_prior_lit = True
    elif attribution != "" and section not in {"results", "discussion", "methodology", "conclusion"}:
        is_prior_lit = True
    elif attribution != "" and re.search(r"\b(?:19|20)\d{2}\b", attribution):
        is_prior_lit = True

    if is_prior_lit and (
        _CUE_PATTERNS["actual"].search(text)
        or _CUE_PATTERNS["objective"].search(text)
        or section in {"literature_review", "related_work"}
        or subsection.lower() in {"literature review", "synthesis", "research gap", "related literature"}
    ):
        if not attribution and section in {"literature_review", "related_work"}:
            attribution = "prior literature"
        return (
            "literature_finding",
            "PRIOR_STUDY_RESULT",
            "prior_study",
            attribution,
            numbers,
            qualifiers,
            negation,
            0.88,
        )

    # Rule 2: Limitations & Delimitations
    if _CUE_PATTERNS["limitation"].search(text) or section in {"limitations", "scope"} or "limitation" in subsection.lower() or "delimitations" in subsection.lower():
        return (
            "limitation",
            "LIMITATION",
            "current_study",
            "",
            numbers,
            qualifiers,
            negation,
            0.85,
        )

    # Rule 3: Recommendations
    if _CUE_PATTERNS["recommendation"].search(text) or section == "recommendations":
        return (
            "recommendation",
            "RECOMMENDATION",
            "current_study",
            "",
            numbers,
            qualifiers,
            negation,
            0.85,
        )

    # Rule 4: Objectives & Purpose
    if _CUE_PATTERNS["objective"].search(text) or section in {"purpose", "problem"} or "statement of the problem" in subsection.lower() or "objective" in subsection.lower():
        if _CUE_PATTERNS["future"].search(text) or section in {"purpose", "problem"}:
            return (
                "research_objective",
                "RESEARCH_OBJECTIVE",
                "current_study",
                "",
                numbers,
                qualifiers,
                negation,
                0.90,
            )

    # Rule 5: Hypotheses & Research Questions
    if _CUE_PATTERNS["hypothesis"].search(text) or section == "hypotheses":
        return (
            "hypothesis",
            "HYPOTHESIS",
            "current_study",
            "",
            numbers,
            qualifiers,
            negation,
            0.88,
        )
    if "?" in text or re.search(r"\b(?:research question|asks? whether|whether)\b", lowered):
        return (
            "research_question",
            "RESEARCH_QUESTION",
            "current_study",
            "",
            numbers,
            qualifiers,
            negation,
            0.88,
        )

    # Rule 6: Framework / Theory
    if section in {"framework", "theory"} or "theoretical framework" in subsection.lower() or "conceptual framework" in subsection.lower() or re.search(r"\b(?:anchored on|theoretical framework|conceptual framework|independent variable|dependent variable)\b", lowered):
        return (
            "background",
            "THEORETICAL_CLAIM",
            "theory",
            "",
            numbers,
            qualifiers,
            negation,
            0.82,
        )

    # Rule 7: Decisions & Risks
    if _CUE_PATTERNS["decision"].search(text) or section in {"decision", "decisions"}:
        return ("decision", "DECISION", "current_study", "", numbers, qualifiers, negation, 0.85)
    if _CUE_PATTERNS["risk"].search(text) or section in {"risks", "threats", "challenges"}:
        return ("risk", "RISK", "current_study", "", numbers, qualifiers, negation, 0.85)

    # Rule 8: Future / Proposed Methods
    if _CUE_PATTERNS["future"].search(text):
        if (
            section in {"methodology", "methods", "research_design"}
            or re.search(
                r"\b(?:use|uses|used|using|utiliz(?:e|es|ed|ing)|conduct(?:ed|ing)?|"
                r"collect(?:ed|ing)?|administer(?:ed|ing)?|analy[sz](?:e|ed|ing)?|"
                r"test(?:ed|ing)?|measure(?:d|ing)?|recruit(?:ed|ing)?|survey(?:ed|ing)?|"
                r"sampl(?:e|es|ed|ing)|compare(?:d|ing)?|select(?:ed|ing)?|employ(?:ed|ing)?)\b",
                lowered,
            )
        ):
            return (
                "method",
                "PROPOSED_METHOD",
                "current_study",
                "",
                numbers,
                qualifiers,
                negation,
                0.90,
            )
        if re.search(r"\b(?:investigate|examine|determine|identify|evaluate|explore)\b", lowered):
            return (
                "research_objective",
                "RESEARCH_OBJECTIVE",
                "current_study",
                "",
                numbers,
                qualifiers,
                negation,
                0.90,
            )
        return ("author_claim", "UNKNOWN", "current_study", "", numbers, qualifiers, negation, 0.50)

    # Rule 9: Methodology
    if section == "methodology" or subsection.lower() in {"research design", "research locale", "respondents", "sampling technique", "research instrument", "data gathering procedure", "statistical treatment"}:
        status = "PROPOSED_METHOD" if document_stage == "proposal" else "AUTHOR_CLAIM"
        return ("method", status, "current_study", "", numbers, qualifiers, negation, 0.85)

    # Rule 10: Conclusion
    if _CUE_PATTERNS["conclusion"].search(text) or section == "conclusion":
        return ("conclusion", "AUTHOR_CLAIM", "current_study", "", numbers, qualifiers, negation, 0.85)

    # Rule 11: Actual Results
    if (_CUE_PATTERNS["actual"].search(text) or re.search(r"\b(?:achieved|measured|outperformed|accuracy|latency|throughput)\b", lowered)) and (
        section in {"results", "discussion", "evaluation", "outcome", "highlights"}
        or re.search(r"\b(?:result|finding|data|accuracy|diagnostic|performance|metric|p-value)\b", lowered)
    ):
        status = "CURRENT_RESULT" if document_stage != "proposal" else "PRIOR_STUDY_RESULT"
        role = "current_study" if document_stage != "proposal" else "prior_study"
        return ("result", status, role, attribution, numbers, qualifiers, negation, 0.88)

    # Rule 12: Definitions
    if _CUE_PATTERNS["definition"].search(text) or section == "definitions":
        return ("definition", "DEFINITION", "definition", "", numbers, qualifiers, negation, 0.85)

    # Rule 13: Academic background
    if re.search(r"\b(?:may|might|could|suggests?|appears? to|seems? to|approximately|about)\b", lowered):
        return ("author_claim", "AUTHOR_CLAIM", "current_study", "", numbers, qualifiers, negation, 0.60)
    if section in _ACADEMIC_SECTIONS:
        return ("background", "BACKGROUND_INFORMATION", "author_background", "", numbers, qualifiers, negation, 0.75)

    return ("claim", "UNKNOWN", "unknown", attribution, numbers, qualifiers, negation, 0.50)


def classify_claim(text: str, section: str = "body") -> tuple[str, str]:
    """Return a conservative claim type and epistemic status for source text."""
    claim_type, epistemic_status, _role, _attr, _num, _qual, _neg, _conf = classify_claim_with_provenance(text, section)
    legacy_status = {
        "CURRENT_RESULT": "ACTUAL_RESULT",
        "PRIOR_STUDY_RESULT": "LITERATURE_FINDING",
    }.get(epistemic_status, epistemic_status)
    return claim_type, legacy_status



def _page_for_text(text: str, page_texts: tuple[str, ...]) -> int | None:
    target = " ".join(_WORD.findall(text.casefold()))
    if not target:
        return None
    target_terms = set(target.split())
    best_page: int | None = None
    best_overlap = 0.0
    for page_number, page in enumerate(page_texts, start=1):
        normalized_page = " ".join(_WORD.findall(page.casefold()))
        if target in normalized_page:
            return page_number
        page_terms = set(normalized_page.split())
        overlap = len(target_terms & page_terms) / max(1, len(target_terms))
        if overlap > best_overlap:
            best_page, best_overlap = page_number, overlap
    return best_page if best_overlap >= 0.85 else None


def _classify_document(text: str, sections: list[str], paragraphs: list[str]) -> tuple[str, float, list[str], dict[str, float]]:
    lowered = text.casefold()
    scores: defaultdict[str, float] = defaultdict(float)
    reasons: dict[str, list[str]] = defaultdict(list)

    def add(kind: str, group: str, weight: float, reason: str) -> None:
        if reason not in reasons[kind]:
            scores[kind] += weight
            reasons[kind].append(reason)

    present_sections = set(sections)
    for line in text.splitlines():
        line_clean = line.strip()
        if line_clean and len(line_clean.split()) <= 6:
            matched = match_section_heading(line_clean)
            if matched:
                present_sections.add(matched)

    academic_count = len(present_sections & _ACADEMIC_SECTIONS)
    if academic_count >= 2:
        add("academic_research", "structure", min(2.5, 1.0 + 0.4 * academic_count), "multiple academic section headings")
    if _CITATION.search(text):
        add("academic_research", "citations", 1.0, "author-year or scholarly citation pattern")
    if _terms_present(lowered, _CUE_GROUPS["academic_research"]["research_terms"]) >= 2:
        add("academic_research", "research_terms", 1.3, "research-method terminology")
    if _terms_present(lowered, _CUE_GROUPS["academic_research"]["quantitative_evidence"]) >= 2:
        add("academic_research", "quantitative_evidence", 0.9, "statistical or study-population terminology")
    if re.search(r"\b(?:thesis|capstone|dissertation)\b", lowered):
        add("thesis_capstone", "document_terms", 2.5, "thesis, capstone, or dissertation label")
    if re.search(r"\bchapter\s+[1-5]\b", lowered) and _terms_present(lowered, ("research design", "respondents", "questionnaire", "scope and delimitations")):
        add("thesis_capstone", "structure", 1.3, "chapter-based research structure")

    if _terms_present(lowered, _CUE_GROUPS["technical_document"]["technical_terms"]) >= 2:
        add("technical_document", "technical_terms", 1.8, "technical system or implementation terminology")
    if present_sections & {"system_description", "requirements", "configuration", "architecture"}:
        add("technical_document", "technical_structure", 1.3, "technical section headings")
    if _terms_present(lowered, _CUE_GROUPS["business_report"]["business_terms"]) >= 2:
        add("business_report", "business_terms", 1.5, "business or financial terminology")
    if present_sections & {"executive_summary", "financial_results", "market_analysis"}:
        add("business_report", "business_structure", 1.2, "business report headings")
    if _terms_present(lowered, _CUE_GROUPS["news_article"]["news_terms"]) >= 1:
        add("news_article", "news_attribution", 1.7, "news-style attribution")
    if re.search(r"\b(?:dateline|byline)\b|\b[A-Z]{3,}(?:,\s*[A-Za-z\s]+)?\s*(?:—|--)\s*|\b(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday),?\s+(?:october|november|december|january|february|march|april|may|june|july|august|september)\s+\d{1,2}", text):
        add("news_article", "publication_structure", 1.2, "byline or dated news structure")
    if _terms_present(lowered, _CUE_GROUPS["legal_policy_document"]["legal_terms"]) >= 2:
        add("legal_policy_document", "legal_terms", 1.8, "legal or policy terminology")
    if present_sections & {"legal_basis", "policy_scope", "obligations", "definitions"}:
        add("legal_policy_document", "legal_structure", 1.6, "legal or policy section headings")
    if _terms_present(lowered, _CUE_GROUPS["educational_material"]["education_terms"]) >= 2:
        add("educational_material", "education_terms", 1.5, "learning and instructional terminology")
    if _terms_present(lowered, _CUE_GROUPS["essay"]["argument_terms"]) >= 1 or present_sections & {"thesis_statement", "counterargument"}:
        add("essay", "argument_structure", 1.5, "explicit argument or essay structure")

    if not scores:
        word_count = len(_WORD.findall(text))
        if word_count >= 120 and len(paragraphs) >= 3:
            add("general_article", "prose_structure", 1.8, "multi-paragraph prose without a stronger genre signal")

    ranked = sorted(scores.items(), key=lambda item: (-item[1], item[0]))
    if not ranked:
        return "unknown", 0.0, [], {}
    if ranked[0][1] < 1.2:
        return "unknown", 0.0, [], dict(scores)
    if len(ranked) > 1 and ranked[1][1] >= 2.0 and ranked[1][1] >= ranked[0][1] * 0.88:
        selected = "mixed"
        signal_share = (ranked[0][1] + ranked[1][1]) / max(sum(scores.values()), 0.001)
        support = min(1.0, (ranked[0][1] + ranked[1][1]) / 6.0)
    else:
        selected = ranked[0][0]
        signal_share = ranked[0][1] / max(sum(scores.values()), 0.001)
        support = min(1.0, ranked[0][1] / 5.0)
    confidence = round(support * signal_share, 3)
    if selected == "academic_research" and (
        _terms_present(lowered, ("experiment", "clinical trial", "randomized", "laboratory", "specimen", "experimental group")) >= 2
        and bool(present_sections & {"methodology", "results", "discussion"})
    ):
        selected = "scientific_paper"
    rationale = [reason for kind, _score in ranked[:2] for reason in reasons[kind]]
    return selected, confidence, rationale, dict(scores)


def detect_document_stage(
    text: str,
    sections: list[str],
    candidates: list[SentenceCandidate],
    claims: list[dict[str, Any]],
    document_type: str = "unknown",
) -> DocumentStageDetection:
    """Infer the document stage across supported categories:
    proposal, completed_research, literature_review, technical_report,
    executive_document, news, general, unknown.
    """
    lowered = text.casefold()
    supporting_evidence: list[str] = []
    present_sections = set(sections)
    for line in text.splitlines():
        line_clean = line.strip()
        if line_clean and len(line_clean.split()) <= 6:
            matched = match_section_heading(line_clean)
            if matched:
                present_sections.add(matched)

    # 1. Tense and verb counts
    proposal_verbs = re.findall(
        r"\b(?:will\s+(?:use|employ|conduct|collect|examine|determine|administer|analy[sz]e|"
        r"investigate|evaluate|sample|utilize|measure|secure|serve|be\s+(?:conducted|used|employed|"
        r"selected|administered|computed|delimited))|aims\s+to|intends\s+to|proposes?\s+to|"
        r"seeks\s+to|planned\s+to|is\s+designed\s+to|is\s+proposed)\b",
        lowered,
    )
    proposal_verb_count = len(proposal_verbs)

    past_research_verbs = re.findall(
        r"\b(?:conducted|administered|investigated|measured|recorded|outperformed|"
        r"we found|the results showed|results show that|findings demonstrated|"
        r"was observed|revealed that|was associated with|were associated with|"
        r"was correlated with|were correlated with)\b",
        lowered,
    )
    past_research_verb_count = len(past_research_verbs)

    # 2. Statistical markers
    statistical_markers = re.findall(
        r"\b(?:p\s*[<>=]\s*0?\.\d+|r\s*=\s*0?\.\d+|t\s*=\s*\d+|f\s*=\s*\d+|"
        r"mean\s*(?:score|rating|of)?\s*=\s*\d+|standard deviation|weighted mean|"
        r"verbal interpretation|anova|chi-square)\b",
        lowered,
    )
    statistical_marker_count = len(statistical_markers)

    # 3. Claims analysis
    current_study_results = [
        c for c in claims
        if c.get("status") in {"ACTUAL_RESULT", "CURRENT_RESULT"}
        and c.get("source_role") != "prior_study"
        and c.get("section") in {"results", "discussion", "evaluation", "outcome", "highlights"}
    ]
    literature_claims = [
        c for c in claims
        if c.get("status") in {"LITERATURE_FINDING", "PRIOR_STUDY_RESULT"}
        or c.get("source_role") == "prior_study"
        or c.get("section") in {"literature_review", "related_work"}
    ]

    has_explicit_results_section = bool(present_sections & {"results", "findings", "outcomes"})
    has_methodology_section = bool(present_sections & {"methodology", "methods", "research_design"})

    if document_type == "news_article" or bool(present_sections & {"lead", "byline", "dateline"}):
        supporting_evidence.append("Journalistic reporting structure and news attribution detected")
        return DocumentStageDetection("news", 0.85, supporting_evidence)

    if document_type == "technical_document" or bool(present_sections & {"system_description", "requirements", "configuration", "architecture"}):
        supporting_evidence.append("Technical system architecture and implementation documentation detected")
        return DocumentStageDetection("technical_report", 0.85, supporting_evidence)

    if document_type == "business_report" or bool(present_sections & {"executive_summary", "financial_results", "market_analysis"}):
        supporting_evidence.append("Corporate executive summary and business metrics detected")
        return DocumentStageDetection("executive_document", 0.85, supporting_evidence)

    is_academic = (
        document_type in {"academic_research", "scientific_paper", "thesis_capstone", "mixed"}
        or bool(present_sections & _ACADEMIC_SECTIONS)
        or bool(re.search(r"\b(?:hypothesis|research design|respondents|sampling|questionnaire|correlational)\b", lowered))
    )

    if is_academic:
        proposal_score = 0.0
        if proposal_verb_count >= 1:
            proposal_score += min(2.5, 0.8 * proposal_verb_count)
            supporting_evidence.append(f"{proposal_verb_count} prospective/proposal verbs found ('will use', 'aims to', etc.)")
        if not has_explicit_results_section and not current_study_results:
            proposal_score += 1.5
            supporting_evidence.append("Absence of primary current-study empirical results section")
        if has_methodology_section:
            proposal_score += 1.0
            supporting_evidence.append("Methodology framework defined")
        if re.search(r"\b(?:proposal|proposed study|the study will|researchers will)\b", lowered):
            proposal_score += 1.0
            supporting_evidence.append("Prospective study design phrasing found")

        if (proposal_verb_count >= 1 or (not current_study_results and not has_explicit_results_section)) and proposal_score >= 2.0:
            confidence = min(0.95, round(0.50 + proposal_score * 0.10, 3))
            return DocumentStageDetection("proposal", confidence, supporting_evidence)

        completed_score = 0.0
        if current_study_results or has_explicit_results_section:
            completed_score += 2.0
            supporting_evidence.append("Empirical findings or results section present")
        if past_research_verb_count >= 2:
            completed_score += min(2.0, 0.5 * past_research_verb_count)
            supporting_evidence.append(f"{past_research_verb_count} completed past-tense research actions found")
        if statistical_marker_count >= 1:
            completed_score += min(2.0, 0.8 * statistical_marker_count)
            supporting_evidence.append(f"{statistical_marker_count} empirical statistical indicators present")

        if completed_score >= 2.5 and completed_score > proposal_score:
            confidence = min(0.95, round(0.50 + completed_score * 0.10, 3))
            return DocumentStageDetection("completed_research", confidence, supporting_evidence)

        if len(literature_claims) >= 3 and len(literature_claims) >= max(1, len(claims) * 0.50) and not has_methodology_section:
            supporting_evidence.append("Literature synthesis across multiple prior studies without primary empirical methodology")
            return DocumentStageDetection("literature_review", 0.82, supporting_evidence)

    if document_type == "general_article":
        supporting_evidence.append("General article prose without specialized academic stage structure")
        return DocumentStageDetection("general", 0.70, supporting_evidence)

    supporting_evidence.append("Insufficient or mixed structural signals to confirm stage")
    return DocumentStageDetection("unknown", 0.0, supporting_evidence)


def analyze_document_structure(
    paragraphs: list[str],
    paragraph_sections: list[str],
    candidates: list[SentenceCandidate],
    *,
    page_texts: tuple[str, ...] = (),
) -> tuple[dict[str, Any], list[SentenceCandidate]]:
    """Build document, section, claim, stage, and source-location representations."""
    section_ids: dict[int, str] = {}
    section_rows: list[dict[str, Any]] = []
    section_rows_by_id: dict[str, dict[str, Any]] = {}
    run_number = 0
    previous_section: str | None = None
    for paragraph_index, section in enumerate(paragraph_sections):
        if section != previous_section:
            run_number += 1
            label = section.replace("_", " ").title() if section != "body" else "Body"
            for paragraph in paragraphs[paragraph_index:paragraph_index + 1]:
                matched = match_section_heading(paragraph)
                if matched == section:
                    label = paragraph.strip().rstrip(":")
                    break
            section_id = f"section-{run_number:03d}-{re.sub(r'[^a-z0-9]+', '-', section.casefold()).strip('-') or 'body'}"
            section_rows.append({
                "section_id": section_id,
                "heading": label,
                "role": _section_role(section),
                "section_key": section,
                "paragraph_indices": [],
                "source_sentence_ids": [],
                "page": None,
            })
            section_rows_by_id[section_id] = section_rows[-1]
            previous_section = section
        section_id = section_rows[-1]["section_id"]
        section_ids[paragraph_index] = section_id
        section_rows[-1]["paragraph_indices"].append(paragraph_index)

    enriched: list[SentenceCandidate] = []
    claims: list[dict[str, Any]] = []
    for candidate in candidates:
        section_id = section_ids.get(candidate.paragraph_index, "section-001-body")
        heading_meta, cleaned_text = extract_structural_heading(candidate.text)
        cand_subsection = candidate.subsection or (heading_meta or "")
        cand_text = cleaned_text if (heading_meta and cleaned_text) else candidate.text

        claim_type, epistemic_status, source_role, attribution, numbers, qualifiers, negation, confidence = (
            classify_claim_with_provenance(cand_text, candidate.section, cand_subsection)
        )
        legacy_status = {
            "CURRENT_RESULT": "ACTUAL_RESULT",
            "PRIOR_STUDY_RESULT": "LITERATURE_FINDING",
        }.get(epistemic_status, epistemic_status)
        page_number = _page_for_text(cand_text, page_texts) if page_texts else None

        annotated = replace(
            candidate,
            text=cand_text,
            normalized_text=cand_text,
            ranking_text=cand_text.casefold(),
            source_sentence_id=f"sentence-{candidate.index + 1:06d}",
            sentence_id=f"sentence-{candidate.index + 1:06d}",
            section_id=section_id,
            subsection=cand_subsection,
            sentence_index=candidate.index,
            page_number=page_number,
            claim_type=claim_type,
            claim_status=legacy_status,
            epistemic_status=epistemic_status,
            source_role=source_role,
            attribution=attribution,
            numbers=numbers,
            qualifiers=qualifiers,
            negation=negation,
            confidence=confidence,
        )
        enriched.append(annotated)
        claims.append({
            "claim_id": f"claim-{candidate.index + 1:06d}",
            "text": cand_text,
            "normalized_meaning": None,
            "type": claim_type,
            "status": legacy_status,
            "epistemic_status": epistemic_status,
            "source_role": source_role,
            "attribution": attribution,
            "subsection": cand_subsection,
            "section_id": section_id,
            "section": candidate.section,
            "source_sentence_ids": [annotated.source_sentence_id],
            "source_paragraph_ids": [f"paragraph-{candidate.paragraph_index + 1:06d}"],
            "page": page_number,
            "numbers": list(numbers),
            "qualifiers": list(qualifiers),
            "negation": negation,
            "importance": None,
            "confidence": None,
            "confidence_method": "not_calibrated",
        })
        section_row = section_rows_by_id[section_id]
        section_row["source_sentence_ids"].append(annotated.source_sentence_id)
        if section_row["page"] is None and page_number is not None:
            section_row["page"] = page_number

    all_text = "\n\n".join(paragraphs)
    document_type, type_confidence, rationale, type_scores = _classify_document(
        all_text, paragraph_sections, paragraphs
    )

    stage_info = detect_document_stage(all_text, paragraph_sections, enriched, claims, document_type)
    document_stage = stage_info.document_stage
    stage_confidence = stage_info.confidence
    stage_evidence = stage_info.supporting_evidence

    # Attach document_stage to enriched candidates and claims
    enriched = [replace(cand, document_stage=document_stage) for cand in enriched]
    for claim in claims:
        claim["document_stage"] = document_stage

    academic_context = document_type in {"academic_research", "scientific_paper", "thesis_capstone", "mixed"} or bool(set(paragraph_sections) & _ACADEMIC_SECTIONS)
    actual_results = [claim for claim in claims if claim["status"] == "ACTUAL_RESULT" and claim.get("source_role") != "prior_study"]
    research_methods = [claim for claim in claims if claim["status"] == "PROPOSED_METHOD"]
    objectives = [claim for claim in claims if claim["status"] in {"RESEARCH_OBJECTIVE", "RESEARCH_QUESTION", "HYPOTHESIS"}]
    explicit_results_section = "results" in set(paragraph_sections) or "discussion" in set(paragraph_sections)
    explicit_conclusion_section = "conclusion" in set(paragraph_sections)
    strong_conclusion = any(_CUE_PATTERNS["conclusion"].search(str(claim["text"])) for claim in claims)

    if document_stage == "proposal":
        research_stage = "proposal"
    elif document_stage == "completed_research":
        research_stage = "completed"
    elif academic_context and actual_results:
        research_stage = "completed"
    elif academic_context and not actual_results and (research_methods or objectives or "methodology" in set(paragraph_sections)):
        research_stage = "proposal"
    else:
        research_stage = "unknown"

    results_available = bool(actual_results) and document_stage != "proposal"
    conclusion_available = explicit_conclusion_section or strong_conclusion
    missing_information: list[str] = []
    if research_stage == "proposal" and not results_available:
        missing_information.append("empirical_results")
    if academic_context and not conclusion_available:
        missing_information.append("conclusion")

    for row in section_rows:
        row["paragraph_ids"] = [f"paragraph-{index + 1:06d}" for index in row["paragraph_indices"]]
        row["paragraph_indices"] = [index for index in row["paragraph_indices"]]
        row["confidence"] = "heading_match" if row["section_key"] != "body" else "fallback_body"

    analysis = {
        "document_type": document_type,
        "document_type_confidence": type_confidence,
        "document_stage": document_stage,
        "document_stage_confidence": stage_confidence,
        "confidence": stage_confidence,
        "supporting_evidence": stage_evidence,
        "stage_evidence": stage_evidence,
        "confidence_method": "measured_structural_and_lexical_signal_share_not_calibrated_probability",
        "classification_signals": rationale,
        "classification_scores": type_scores,
        "detected_structure": section_rows,
        "research_stage": research_stage,
        "results_section_available": explicit_results_section,
        "results_available": results_available,
        "conclusion_available": conclusion_available,
        "missing_information": missing_information,
        "claim_count": len(claims),
        "claims": claims,
    }
    return analysis, enriched


def build_summary_plan(analysis: dict[str, Any], candidates: list[SentenceCandidate], mode: str, depth: str) -> dict[str, Any]:
    """Select only summary topics backed by source claims and detected sections."""
    types_present = {candidate.claim_type for candidate in candidates}
    sections_present = {candidate.section for candidate in candidates}
    proposed = analysis.get("research_stage") == "proposal" or analysis.get("document_stage") == "proposal"

    if mode == "executive":
        requested = [
            ("Executive Overview", {"background", "definition"}),
            ("Strategic Decisions", {"decision"}),
            ("Key Findings", {"result"}),
            ("Risks & Challenges", {"risk", "limitation"}),
            ("Recommendations", {"recommendation"}),
        ]
    elif mode == "technical":
        requested = [
            ("System Overview", {"background", "definition"}),
            ("Architecture & Implementation", {"method"}),
            ("Technical Findings", {"result"}),
            ("Constraints & Risks", {"limitation", "risk"}),
            ("Recommendations", {"recommendation"}),
        ]
    elif mode == "news":
        requested = [
            ("Key Developments", {"result", "decision"}),
            ("Context & Background", {"background", "definition"}),
            ("Reactions & Implications", {"recommendation", "risk"}),
        ]
    elif mode == "study":
        requested = [
            ("Core Concepts", {"definition", "background"}),
            ("Key Mechanisms", {"method"}),
            ("Main Results", {"result"}),
            ("Important Takeaways", {"conclusion", "recommendation"}),
        ]
    elif mode == "general":
        requested = [
            ("Overview", {"background", "definition"}),
            ("Key Findings", {"result", "decision"}),
            ("Process & Methodology", {"method"}),
            ("Outcomes & Outlook", {"conclusion", "recommendation", "risk", "limitation"}),
        ]
    else:  # academic / default
        requested = [
            ("Research Topic", {"background"}),
            ("Purpose", {"research_objective", "research_question"}),
            ("Hypotheses", {"hypothesis"}),
            ("Literature Synthesis", {"literature_finding"}),
            ("Proposed Methodology" if proposed else "Methodology", {"method"}),
            *( [("Actual Findings", {"result"})] if not proposed else [] ),
            *( [("Conclusion", {"conclusion"})] if not proposed else [] ),
            ("Limitations", {"limitation"}),
            ("Recommendations", {"recommendation"}),
            ("Key Concepts", {"definition"}),
        ]

    supported = [
        {"label": label, "claim_types": sorted(allowed & types_present)}
        for label, allowed in requested
        if allowed & types_present
    ]
    if not supported and sections_present - {"body", "references", "acknowledgment", "appendix"}:
        supported = [{"label": section.replace("_", " ").title(), "claim_types": []} for section in sorted(sections_present - {"body", "references", "acknowledgment", "appendix"})]

    limit = {"brief": 3, "short": 4, "balanced": 6, "detailed": 8, "comprehensive": 9}.get(depth, 6)
    return {
        "mode": mode,
        "depth": depth,
        "planned_sections": supported[:limit],
        "supported_claim_types": sorted(types_present),
        "results_available": bool(analysis.get("results_available")),
        "conclusion_available": bool(analysis.get("conclusion_available")),
        "missing_information": list(analysis.get("missing_information", [])),
    }


def build_dynamic_summary_sections(candidates: list[SentenceCandidate], plan: dict[str, Any], max_per_section: int = 3) -> list[dict[str, str]]:
    """Group selected source claims under only the labels supported by the plan."""
    allowed_by_label = {item["label"]: set(item["claim_types"]) for item in plan.get("planned_sections", [])}
    label_by_type: dict[str, str] = {}
    for label, claim_types in allowed_by_label.items():
        for claim_type in claim_types:
            label_by_type.setdefault(claim_type, label)
    grouped: dict[str, list[str]] = defaultdict(list)
    for candidate in candidates:
        label = label_by_type.get(candidate.claim_type)
        if label is None and candidate.section in {"methodology", "results", "discussion", "conclusion", "limitations", "recommendations", "literature_review", "purpose", "background", "problem", "system_description", "decision", "risks"}:
            if candidate.section == "results" and candidate.claim_status != "ACTUAL_RESULT":
                continue
            label = {
                "methodology": "Architecture & Implementation" if "Architecture & Implementation" in allowed_by_label else ("Process & Methodology" if "Process & Methodology" in allowed_by_label else ("Proposed Methodology" if candidate.claim_status == "PROPOSED_METHOD" else "Methodology")),
                "results": "Technical Findings" if "Technical Findings" in allowed_by_label else ("Key Findings" if "Key Findings" in allowed_by_label else "Actual Findings"),
                "discussion": "Discussion",
                "conclusion": "Conclusion" if "Conclusion" in allowed_by_label else ("Outcomes & Outlook" if "Outcomes & Outlook" in allowed_by_label else "Important Takeaways" if "Important Takeaways" in allowed_by_label else "Conclusion"),
                "limitations": "Constraints & Risks" if "Constraints & Risks" in allowed_by_label else ("Risks & Challenges" if "Risks & Challenges" in allowed_by_label else "Limitations"),
                "recommendations": "Recommendations" if "Recommendations" in allowed_by_label else "Important Takeaways",
                "literature_review": "Literature Synthesis",
                "purpose": "Purpose",
                "background": "Research Topic" if "Research Topic" in allowed_by_label else ("System Overview" if "System Overview" in allowed_by_label else ("Executive Overview" if "Executive Overview" in allowed_by_label else ("Overview" if "Overview" in allowed_by_label else "Background"))),
                "problem": "Purpose",
                "system_description": "System Overview" if "System Overview" in allowed_by_label else "Architecture & Implementation",
                "decision": "Strategic Decisions" if "Strategic Decisions" in allowed_by_label else "Key Developments",
                "risks": "Constraints & Risks" if "Constraints & Risks" in allowed_by_label else "Risks & Challenges",
            }.get(candidate.section)
        if label is None or (label not in allowed_by_label and not any(item["label"] == label for item in plan.get("planned_sections", []))):
            continue
        if label in {"Actual Findings", "Key Findings", "Technical Findings", "Key Developments"} and candidate.claim_status in {"PROPOSED_METHOD", "RESEARCH_OBJECTIVE"}:
            continue
        if label in {"Conclusion", "Outcomes & Outlook"} and candidate.section != "conclusion" and not _CUE_PATTERNS["conclusion"].search(candidate.text):
            continue
        grouped[label].append(candidate.text)

    output: list[dict[str, str]] = []
    ordered_labels = [item["label"] for item in plan.get("planned_sections", [])]
    for label in ordered_labels:
        sentences = list(dict.fromkeys(grouped.get(label, [])))[:max_per_section]
        if sentences:
            output.append({"label": label, "text": " ".join(sentences)})
    return output


def build_source_evidence(sentences: list[str], candidates: list[SentenceCandidate]) -> list[dict[str, Any]]:
    """Map local extractive/compressed output sentences to their source sentence."""
    evidence: list[dict[str, Any]] = []
    for summary_index, sentence in enumerate(sentences):
        output_tokens = set(_WORD.findall(sentence.casefold()))
        if not output_tokens:
            continue
        ranked: list[tuple[float, SentenceCandidate]] = []
        for candidate in candidates:
            source_tokens = set(_WORD.findall(candidate.text.casefold()))
            if not source_tokens:
                continue
            coverage = len(output_tokens & source_tokens) / len(output_tokens)
            ranked.append((coverage, candidate))
        if not ranked:
            continue
        coverage, source = max(ranked, key=lambda item: (item[0], -item[1].index))
        if coverage < 0.55:
            continue
        item: dict[str, Any] = {
            "chunk_id": f"p{source.paragraph_index + 1:06d}-s{source.index + 1:06d}",
            "summary_unit": "sentence",
            "section": source.section.replace("_", " ").title() if source.section != "body" else "Source",
            "subsection": getattr(source, "subsection", ""),
            "excerpt": source.text,
            "supports": sentence,
            "source_sentence": source.text,
            "summary_sentence": sentence,
            "summary_sentence_index": summary_index,
            "paragraph_index": source.paragraph_index,
            "source_sentence_indices": [source.index],
            "source_sentence_id": source.source_sentence_id or f"sentence-{source.index + 1:06d}",
            "source_paragraph_id": f"paragraph-{source.paragraph_index + 1:06d}",
            "section_id": source.section_id or source.section,
            "claim_type": source.claim_type,
            "claim_status": source.claim_status,
            "epistemic_status": getattr(source, "epistemic_status", source.claim_status),
            "source_role": getattr(source, "source_role", "unknown"),
            "attribution": getattr(source, "attribution", ""),
            "document_stage": getattr(source, "document_stage", "unknown"),
            "retrieved": False,
            "relevance_score": round(coverage, 4),
        }
        if source.page_number is not None:
            item["page"] = source.page_number
        evidence.append(item)
    return evidence


__all__ = [
    "analyze_document_structure",
    "build_dynamic_summary_sections",
    "build_source_evidence",
    "build_summary_plan",
    "classify_claim",
    "classify_claim_with_provenance",
    "detect_document_stage",
]

