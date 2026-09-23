"""
Hybrid extractive summarization engine for general article types.

The implementation keeps the existing PHP/Python summarize contract intact while
generalizing the NLP pipeline for academic articles, news, blogs, reports,
case studies, reviews, and plain-text articles.
"""

from __future__ import annotations

from collections import Counter, defaultdict
from dataclasses import dataclass, field
from functools import lru_cache
import importlib
import importlib.util
from io import BytesIO
import ipaddress
import logging
import math
from pathlib import Path
import re
import socket
import time
from typing import Any, Callable, Iterable, Optional, cast
from urllib.parse import urljoin, urlparse

import nltk
from nltk import RegexpParser
from nltk.corpus import stopwords
from nltk.stem import WordNetLemmatizer
import numpy as np  # pyright: ignore[reportMissingImports]
from pypdf import PdfReader
from sklearn.decomposition import TruncatedSVD  # pyright: ignore[reportMissingModuleSource]

ARTICLE_TYPE_LABELS = {
    "academic": "Academic",
    "news": "News",
    "opinion": "Opinion",
    "blog": "Blog",
    "tutorial": "Educational",
    "technical_tutorial": "Technical",
    "technical_docs": "Technical",
    "technical_report": "Technical",
    "technical_code_document": "Technical Code Document",
    "educational": "Educational",
    "legal_policy": "Legal / Policy",
    "review_article": "Review",
    "case_study": "Case Study",
    "business_report": "Business Report",
    "official_report": "Official Report",
    "accomplishment_report": "Accomplishment Report",
    "activity_report": "Activity Report",
    "official_letter": "Official Letter",
    "request_letter": "Request Letter",
    "memorandum": "Memorandum",
    "meeting_minutes": "Meeting Minutes",
    "proposal": "Proposal",
    "announcement": "Announcement",
    "transcript_interview": "Transcript / Interview",
    "narrative": "Narrative",
    "short_text": "Short Text",
    "general_article": "General",
}
SECTION_ALIASES = {
    "abstract": ("abstract",),
    "introduction": ("introduction", "intro"),
    "background": ("background",),
    "literature_review": (
        "literature review",
        "review of related literature",
        "related work",
        "review of literature",
    ),
    "methodology": (
        "methodology",
        "methods",
        "materials and methods",
        "research design",
        "experimental setup",
        "data collection",
        "procedure",
    ),
    "results": ("results", "findings", "outcomes"),
    "discussion": ("discussion", "analysis", "results and discussion"),
    "conclusion": ("conclusion", "conclusions", "closing thoughts", "final thoughts"),
    "recommendations": ("recommendation", "recommendations", "policy recommendations"),
    "limitations": ("limitations", "study limitations"),
    "future_work": ("future work", "future directions", "further research"),
    "legal_basis": ("legal basis", "authority", "statutory basis"),
    "policy_scope": ("scope", "coverage", "applicability"),
    "obligations": ("obligations", "requirements", "duties", "compliance requirements"),
    "definitions": ("definitions", "defined terms"),
    "references": ("references", "bibliography", "works cited", "literature cited"),
    "acknowledgment": (
        "acknowledgment",
        "acknowledgement",
        "acknowledgments",
        "acknowledgements",
    ),
    "appendix": ("appendix", "appendices"),
    "executive_summary": ("executive summary", "management summary"),
    "system_description": ("system description", "system overview", "implementation details"),
    "overview": ("overview",),
    "comparison": ("comparison", "comparative analysis"),
    "evaluation": ("evaluation", "assessment"),
    "problem": ("problem", "problem statement", "issue", "challenge"),
    "solution": ("solution", "intervention", "approach"),
    "lessons_learned": ("lessons learned", "lesson learned"),
    "lead": ("lead",),
    "body": ("body",),
    "outcome": ("outcome", "status update"),
    "table_of_contents": ("contents", "table of contents"),
    "activity_name": ("activity name", "title of activity", "program name", "project title"),
    "date_and_venue": ("date", "venue", "date and venue", "location", "time"),
    "purpose": ("purpose", "rationale", "objective", "objectives"),
    "highlights": ("highlights", "activity highlights", "program highlights", "event highlights"),
    "annexes": ("annex", "annexes", "attachment", "attachments", "supporting attachments"),
    "documentation": ("documentation", "supporting documentation", "photos", "photo documentation"),
    "signatures": ("signatures", "signatories", "prepared by", "approved by", "noted by"),
}
REFERENCE_SECTIONS = {"references"}
TRAILING_DROP_SECTIONS = {"references", "acknowledgment", "appendix", "annexes", "documentation", "signatures"}
LOW_VALUE_SECTIONS = {"references", "acknowledgment", "appendix", "table_of_contents", "annexes", "documentation", "signatures"}
PAGE_NUMBER_PATTERN = re.compile(r"^(?:page\s+)?\d+(?:\s+of\s+\d+)?$", flags=re.IGNORECASE)
EMAIL_PATTERN = re.compile(r"^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$", flags=re.IGNORECASE)
URL_ONLY_PATTERN = re.compile(r"^(?:https?://\S+|www\.\S+)$", flags=re.IGNORECASE)
PUNCT_SPLIT_PATTERN = re.compile(r"(?<=[.!?])\s+")
TOKEN_PATTERN = re.compile(r"[A-Za-z][A-Za-z'-]*|\d+(?:\.\d+)?", flags=re.UNICODE)
MONTH_PATTERN = re.compile(
    r"\b(january|february|march|april|may|june|july|august|september|october|november|december)\b",
    flags=re.IGNORECASE,
)
DATE_PATTERN = re.compile(
    r"\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\s+\d{1,2}(?:,\s+\d{4})?\b",
    flags=re.IGNORECASE,
)
LOCATION_LEAD_PATTERN = re.compile(r"^[A-Z][A-Z\s\.'-]{2,}\s*[—-]\s+")
ISSN_PATTERN = re.compile(r"\bISSN\b[:\s-]*\d{4}-?\d{3}[\dX]\b", flags=re.IGNORECASE)
ISBN_PATTERN = re.compile(r"\bISBN(?:-1[03])?\b[:\s-]*[\d-]{10,17}[\dX]\b", flags=re.IGNORECASE)
DOI_PATTERN = re.compile(r"\b(?:doi|DOI)\b[:\s]*10\.\d{4,9}/\S+", flags=re.IGNORECASE)
DOI_URL_PATTERN = re.compile(
    r"\b(?:https?://)?(?:[\w-]+\.)*doi\.org/10\.\d{4,9}/\S+",
    flags=re.IGNORECASE,
)
COPYRIGHT_PATTERN = re.compile(r"(?:©|copyright|all rights reserved)", flags=re.IGNORECASE)
VOLUME_ISSUE_PATTERN = re.compile(
    r"\b(?:vol(?:ume)?\.?\s*\d+|issue\s*\d+|no\.?\s*\d+|pp?\.?\s*\d+(?:\s*[-–]\s*\d+)?)\b",
    flags=re.IGNORECASE,
)
PAGE_RANGE_PATTERN = re.compile(
    r"\b(?:pp?\.?|pages?|s:)\s*\d+\s*[-â€“]\s*\d+\b",
    flags=re.IGNORECASE,
)
AVAILABLE_AT_PATTERN = re.compile(r"^\s*available at\b", flags=re.IGNORECASE)
RECEIVED_ACCEPTED_PATTERN = re.compile(
    r"\b(received|accepted|published|revised)\b.*\b\d{4}\b",
    flags=re.IGNORECASE,
)
COOKIE_NOISE_PATTERN = re.compile(
    r"\b(cookie|newsletter|subscribe|sign up|privacy policy|terms of use|advertisement|share this|accept cookies)\b",
    flags=re.IGNORECASE,
)
NAVIGATION_PATTERN = re.compile(
    r"\b(home|about us|contact us|menu|login|register|next article|previous article|read more)\b",
    flags=re.IGNORECASE,
)
FIGURE_CAPTION_PATTERN = re.compile(r"^(?:figure|fig\.|table|chart)\s+\d+[\.:]?", flags=re.IGNORECASE)
INLINE_FIGURE_REFERENCE_PATTERN = re.compile(
    r"\b(?:figure|fig\.|table|chart)\s*\d+[a-z]?(?:\s*\([a-z]\))?",
    flags=re.IGNORECASE,
)
NUMBERED_CAPTION_PATTERN = re.compile(
    r"^\d+\s+(?:change|changes|trend|trends|figure|table|chart)\b",
    flags=re.IGNORECASE,
)
ABSTRACT_LABEL_PATTERN = re.compile(r"\babstract\s*:\s*", flags=re.IGNORECASE)
AUTHOR_METADATA_HINT_PATTERN = re.compile(
    r"\b(?:university|college|campus|department|faculty|philippines|email|corresponding author)\b",
    flags=re.IGNORECASE,
)
TABLE_BLOCK_PATTERN = re.compile(r"^(?:table|tab\.)\s+[ivxlcdm\d]+\b", flags=re.IGNORECASE)
TRUNCATED_ENUMERATION_PATTERN = re.compile(r":\s*(?:\d+|[ivxlcdm]+)\.\s*$", flags=re.IGNORECASE)
OBJECTIVE_SENTENCE_PATTERN = re.compile(
    r"\b(?:this study|the study|this research|the research|this paper|the paper|this article)\s+"
    r"(?:aims?\s+to|aimed\s+to|seeks?\s+to|sought\s+to|was conducted to)\b",
    flags=re.IGNORECASE,
)
METHOD_SENTENCE_PATTERN = re.compile(
    r"\b(?:this study|the study|the researcher|researchers?)\s+"
    r"(?:employ(?:ed|s)|used|utilized|adopted)\b|\bquantitative descriptive research design\b",
    flags=re.IGNORECASE,
)
RESULT_CUE_PATTERN = re.compile(
    r"\b(?:results?\s+(?:show|showed|reveal|revealed|indicate|indicated)|"
    r"findings?\s+(?:show|showed|reveal|revealed|suggest|suggested|indicate|indicated)|"
    r"respondents?\s+reported|the study concludes|the study found|it is recommended|"
    r"contrary to prevailing|crucially)\b",
    flags=re.IGNORECASE,
)
SHORT_JOIN_WORDS = {
    "an", "as", "at", "be", "by", "do", "go", "he", "if", "in", "is",
    "it", "me", "no", "of", "on", "or", "so", "to", "up", "us", "we",
}
MIN_SENTENCE_WORDS = 6
MAX_SENTENCE_WORDS = 90
MAX_KEYPHRASES = 8
MAX_KEY_POINTS = 6
MAX_IMPORTANT_TERMS = 6
READING_WORDS_PER_MINUTE = 200
SUMMARY_STYLE_SIMPLE = "simple_summary"
SUMMARY_STYLE_ACADEMIC = "academic_summary"
SUMMARY_STYLE_BULLETS = "bullet_points"
SUMMARY_STYLE_HYBRID = "hybrid"
MAX_OVERALL_SUMMARY_RATIO = 0.28
DEFAULT_SECTION_SIMILARITY_THRESHOLD = 0.70

SIMILARITY_THRESHOLDS: dict[str, float] = {
    "academic": 0.60,
    "news": 0.78,
    "blog": 0.80,
    "opinion": 0.76,
    "tutorial": 0.75,
    "educational": 0.72,
    "technical": 0.68,
    "legal": 0.65,
    "official": 0.72,
    "business": 0.70,
    "review": 0.74,
    "general": 0.70,
}

SECTION_COVERAGE_GROUPS: dict[str, list[set[str]]] = {
    "academic": [
        {"abstract", "introduction", "background"},
        {"methodology"},
        {"results", "discussion"},
        {"conclusion", "recommendations", "future_work"},
    ],
    "case_study": [
        {"background"},
        {"problem"},
        {"solution"},
        {"results", "outcome"},
        {"conclusion", "lessons_learned"},
    ],
    "official_report": [
        {"activity_name", "date_and_venue"},
        {"purpose"},
        {"highlights"},
        {"results", "conclusion"},
    ],
    "business_report": [
        {"executive_summary", "introduction"},
        {"results"},
        {"discussion"},
        {"conclusion", "recommendations"},
    ],
}

DISCOURSE_BOOST_PATTERNS: dict[str, tuple[re.Pattern[str], float]] = {
    "contrast": (re.compile(r"^\s*(?:however|in contrast|on the other hand|conversely)\b", re.IGNORECASE), 0.10),
    "conclusion": (re.compile(r"^\s*(?:therefore|thus|overall|in summary|to summarize|in conclusion)\b", re.IGNORECASE), 0.18),
    "emphasis": (re.compile(r"^\s*(?:notably|crucially|importantly|significantly|critically)\b", re.IGNORECASE), 0.14),
    "causation": (re.compile(r"^\s*(?:consequently|as a result|hence|accordingly)\b", re.IGNORECASE), 0.12),
    "addition": (re.compile(r"^\s*(?:furthermore|moreover|additionally|in addition)\b", re.IGNORECASE), 0.06),
}

ANTECEDENT_PATTERN = re.compile(
    r"^\s*(?:it|this|these|they|he|she|that)\s+"
    r"(?:was|were|is|are|has|have|shows?|suggests?|indicates?|demonstrates?|reveals?|implies?)\b",
    re.IGNORECASE,
)

SUMMARY_ENGINE_LOG_PATH = (
    Path(__file__).resolve().parent.parent / ".tmp" / "summarizer_engine.log"
)

EXCLUDED_FROM_SUMMARY_SECTIONS = frozenset({
    "references", "acknowledgment", "appendix", "table_of_contents",
})

NOISE_KEYWORD_TERMS = frozenset({
    "wa", "wm", "wi", "th", "nd", "rd", "wo", "ha", "fo", "ca",
    "thi", "tha", "whi", "whe", "fro", "als", "bei", "hav",
    "et", "al", "fig", "vol", "pp", "doi", "isbn", "issn",
    "http", "https", "www", "com", "org", "edu", "pdf", "html",
    "also", "however", "therefore", "thus", "hence", "moreover",
    "furthermore", "nevertheless", "consequently", "meanwhile",
    "although", "whereas", "wherein", "whereby", "herein",
    "said", "used", "made", "based", "given", "shown", "found",
    "may", "can", "could", "would", "should",
    "program", "student", "event", "activities", "concluded", 
    "successfully", "administrative", "activity", "segments", 
    "recreational", "various", "ant", "pageant", "mr", "ms",
    "aiintegrated", "amid", "you", "your", "they", "their",
    "will", "from", "with", "that", "this", "which", "when", "where", "into", "been",
    "were", "have", "some", "such", "than", "then", "very", "more", "most", "each",
    "both", "only", "many", "much", "just", "like", "even",
    "here", "there", "once", "does", "done", "went", "come", "came",
    "take", "took", "give", "gave", "find", "found", "show", "showed", "well", "good",
    "best", "make", "keep", "kept", "seem", "seemed", "look", "looked",
})

GENERIC_IMPORTANT_TERM_NOUNS = frozenset({
    "activity", "activities", "administrator", "administrators", "article",
    "articles", "author", "authors", "campus", "committee", "committees",
    "component", "components", "coordinator", "coordinators", "coordination",
    "event", "events", "finding", "findings", "information", "issue", "issues",
    "paper", "papers", "participant", "participants", "problem", "problems",
    "program", "programs", "project", "projects", "report", "reports",
    "research", "researcher", "researchers", "respondent", "respondents",
    "result", "results", "section", "sections", "service", "services",
    "student", "students", "study", "studies", "support", "system", "systems",
    "topic", "topics", "user", "users", "segments", "ant", "various", "recreational",
    "concluded", "successfully", "administrative", "activity",
})

GENERIC_IMPORTANT_TERM_MODIFIERS = frozenset({
    "academic", "administrative", "basic", "current", "general", "high",
    "important", "key", "local", "main", "overall", "public", "recent",
    "relevant", "successful", "vital", "various", "recreational", "successfully",
})

LEADING_TERM_FILLER_WORDS = frozenset({
    "a", "an", "the", "this", "that", "these", "those",
    "its", "his", "her", "their", "our", "my", "your",
    "if", "first", "next", "then", "after", "before",
})

TRAILING_TERM_ACTION_WORDS = frozenset({
    "aligned", "carried", "concluded", "provides", "reported",
    "reports", "returns", "reviewed", "said", "says",
    "showed", "shows", "strengthened", "used", "using",
})

SURFACE_TERM_PATTERNS = (
    re.compile(r"[\"“”']([^\"“”']{3,80})[\"“”']"),
    re.compile(r"\b[A-Z]{2,}(?:[-/][A-Z0-9]{2,})*(?:\s+\d{4})?\b"),
    re.compile(r"\b[A-Z][a-z0-9]+[A-Z][A-Za-z0-9/-]*\b"),
    re.compile(
        r"\b(?:[A-Z][A-Za-z0-9/-]*|[A-Z]{2,})"
        r"(?:\s+(?:(?:of(?:\s+the)?|and|for|&)\s+)?(?:[A-Z][A-Za-z0-9/-]*|[A-Z]{2,}|\d{2,4})){1,4}\b"
    ),
)

CITATION_ENTRY_PATTERN = re.compile(
    r"(?:^\[?\d+\]?\s*[A-Z][a-z]+.*?\(\d{4}\))"
    r"|(?:^[A-Z][a-z]+,\s*[A-Z]\..*?\(\d{4}\))"
    r"|(?:^[A-Z][a-z]+,\s*[A-Z]\.\s*(?:&|and)\s*[A-Z])",
)
REFERENCE_METADATA_HINT_PATTERN = re.compile(
    r"\b(?:journal|vol(?:ume)?|issue|pages?|pp\.?|doi|abstract|paperid|issn|isbn)\b",
    flags=re.IGNORECASE,
)
INLINE_CITATION_PATTERN = re.compile(
    r"(?:\[[0-9,\-\s]{1,30}\]|\((?:[A-Z][A-Za-z]+(?:\s+et\s+al\.)?[,;]\s*\d{4}[a-z]?)(?:\s*[;,]\s*[A-Z][A-Za-z]+(?:\s+et\s+al\.)?[,;]\s*\d{4}[a-z]?)*\))"
)
URL_GUARD_PATTERN = re.compile(r"https?://\S+|www\.\S+", flags=re.IGNORECASE)

# Assessment, question, directions, and noise patterns
QUESTION_START_PATTERN = re.compile(
    r"^(?:(?:\d+[\.\-\)]\s*|[a-z][\.\)]\s*)?"
    r"(?:what|why|which|how|where|when|who|whose|whom|can|is|are|do|does|did|will|would|should|could|"
    r"hat\s+does|hich\s+of|hy\s+is|hat\s+is|hich\s+strategy|hich\s+action)\b)",
    flags=re.IGNORECASE,
)
QUESTION_PUNCTUATION_PATTERN = re.compile(r"\?\s*$", flags=re.IGNORECASE)

ANSWER_CHOICE_PATTERN = re.compile(
    r"^(?:[A-Da-d][\.\)]\s+|\([A-Da-d]\)\s+|[A-Da-d]\s*[-–—]\s*|[A-Da-d]\s{2,})",
    flags=re.IGNORECASE,
)

DIRECTIONS_PATTERN = re.compile(
    r"^(?:directions?|instructions?|assessment(?:\s+items?)?|activity(?:\s+\d+)?|"
    r"exercise(?:\s+\d+)?|practice(?:\s+questions?|\s+test)?|comprehension\s+questions?|"
    r"evaluation|multiple\s+choice|true\s+or\s+false|fill\s+in\s+the\s+blanks?|"
    r"choose\s+the\s+(?:best|correct)\s+answer|write\s+\d+\s+paragraphs?|"
    r"answer\s+the\s+(?:following|questions?)|match\s+column\s+[a-z]|"
    r"guide\s+questions?|learning\s+activity\s+sheet)\b",
    flags=re.IGNORECASE,
)

BRANDING_WATERMARK_PATTERN = re.compile(
    r"(?:camscanner|scanned\s+with|nourishing\s+the\s+mind|nurturing\s+the\s+heart|"
    r"accredited\s+by|all\s+rights\s+reserved|republic\s+of\s+the\s+philippines|"
    r"department\s+of\s+education|commission\s+on\s+higher\s+education|\bdeped\b|\bched\b|"
    r"school\s+id\b|learner['’]?s\s+material|quarter\s+\d+\s*[-–]\s*module\s+\d+|"
    r"not\s+for\s+sale|for\s+classroom\s+use\s+only|self[-–\s]learning\s+module)",
    flags=re.IGNORECASE,
)

OCR_CORRUPTION_PATTERN = re.compile(
    r"(?:\\CUyY|@\s*CLI\s*&\s*Level|@\s*On\s*\d+\*?\s*Semester|[{}\[\]\\|^~`]{3,}|"
    r"(?:[^\w\s]{2,}[A-Za-z0-9]){3,}|[A-Za-z0-9]{24,})",
    flags=re.IGNORECASE,
)


PURPOSE_CUE_PATTERNS: list[tuple[str, re.Pattern[str]]] = [
    # --- academic cues ---
    ("Objective", re.compile(
        r"\b(?:this\s+study\s+aims?|the\s+study\s+aims?|this\s+research\s+aims?|"
        r"the\s+objective|the\s+purpose|aims?\s+to|sought\s+to|intended\s+to|"
        r"was\s+conducted\s+to|seeks?\s+to)\b",
        flags=re.IGNORECASE,
    )),
    ("Respondents", re.compile(
        r"\b(?:respondents?|participants?|sample\s+size|n\s*=\s*\d+|"
        r"total\s+enumeration|census|purposive\s+sampling|"
        r"convenience\s+sampling|random\s+sampling|enrolled\s+students?)\b",
        flags=re.IGNORECASE,
    )),
    ("Statistical Treatment", re.compile(
        r"\b(?:weighted\s+mean|composite\s+mean|standard\s+deviation|"
        r"t-test|anova|chi-square|likert\s+scale|statistical\s+treatment|"
        r"frequency\s+distribution|percentage\s+distribution)\b",
        flags=re.IGNORECASE,
    )),
    ("Results", re.compile(
        r"\b(?:results?\s+(?:show|showed|reveal|revealed|indicate|indicated)|"
        r"findings?\s+(?:show|showed|reveal|revealed|suggest|suggested)|"
        r"strongly\s+agree|moderately\s+agree|the\s+data\s+show|"
        r"overall\s+(?:weighted\s+)?mean|verbal\s+interpretation)\b",
        flags=re.IGNORECASE,
    )),
    ("Discussion", re.compile(
        r"\b(?:this\s+(?:finding|result)\s+(?:is|was)\s+(?:consistent|inconsistent|similar|contrary)|"
        r"in\s+contrast|consistent\s+with|in\s+line\s+with|supported\s+by|"
        r"this\s+(?:suggests|implies|aligns))\b",
        flags=re.IGNORECASE,
    )),
    ("Recommendation", re.compile(
        r"\b(?:it\s+is\s+recommended|the\s+researchers?\s+recommends?|"
        r"future\s+(?:research|studies|work)\s+(?:should|may|could)|"
        r"should\s+(?:be\s+)?(?:consider|focus|develop|implement|integrate))\b",
        flags=re.IGNORECASE,
    )),
    ("Conclusion", re.compile(
        r"\b(?:in\s+conclusion|to\s+conclude|the\s+study\s+concludes?|"
        r"it\s+(?:is|was)\s+concluded|overall.{0,20}the\s+study)\b",
        flags=re.IGNORECASE,
    )),
    ("Methodology", re.compile(
        r"\b(?:quantitative|qualitative|mixed.?method|descriptive\s+research|"
        r"experimental\s+design|survey\s+method|research\s+design|"
        r"data\s+gathering|data\s+collection\s+(?:method|procedure|technique))\b",
        flags=re.IGNORECASE,
    )),
    ("Legal Basis", re.compile(
        r"\b(?:pursuant\s+to|under\s+(?:section|article|rule|law)|"
        r"in\s+accordance\s+with|statutory\s+authority|legal\s+basis)\b",
        flags=re.IGNORECASE,
    )),
    ("Obligation", re.compile(
        r"\b(?:shall|must|required\s+to|is\s+required\s+to|"
        r"responsible\s+for|obligated\s+to|duty\s+to)\b",
        flags=re.IGNORECASE,
    )),
    ("Restriction", re.compile(
        r"\b(?:shall\s+not|must\s+not|prohibited|not\s+permitted|"
        r"restriction|ban|forbidden)\b",
        flags=re.IGNORECASE,
    )),
    ("Exception", re.compile(
        r"\b(?:except\s+when|except\s+that|unless|provided\s+that|"
        r"notwithstanding|exemption|exception)\b",
        flags=re.IGNORECASE,
    )),
    ("Problem Statement", re.compile(
        r"\b(?:the\s+problem|problem\s+statement|growing\s+concern|"
        r"challenges?\s+(?:faced|encountered)|gap\s+in|lack\s+of|despite\s+the)\b",
        flags=re.IGNORECASE,
    )),
    # --- news cues ---
    ("Main Event", re.compile(
        r"\b(?:reported|announced|launched|arrested|killed|injured|broke\s+out|"
        r"took\s+place|occurred|happened|was\s+held|was\s+signed)\b",
        flags=re.IGNORECASE,
    )),
    ("Impact", re.compile(
        r"\b(?:impact|affected|displaced|damaged|caused|resulted\s+in|"
        r"consequences|aftermath)\b",
        flags=re.IGNORECASE,
    )),
    ("Quote / Statement", re.compile(
        r"(?:\".{10,}\"\s*(?:said|stated|added|noted|explained)|"
        r"\baccording\s+to\b|\bsaid\s+(?:that|in)\b)",
        flags=re.IGNORECASE,
    )),
    # --- opinion cues ---
    ("Main Claim", re.compile(
        r"\b(?:i\s+(?:think|believe|argue|contend)|in\s+my\s+(?:view|opinion)|"
        r"we\s+should|it\s+is\s+(?:clear|evident|obvious)\s+that)\b",
        flags=re.IGNORECASE,
    )),
    ("Supporting Reason", re.compile(
        r"\b(?:because|the\s+reason|this\s+is\s+(?:because|why)|evidence\s+(?:shows|suggests))\b",
        flags=re.IGNORECASE,
    )),
    # --- tutorial cues ---
    ("Step", re.compile(
        r"\b(?:step\s+\d|first,?\s+(?:install|open|create|configure)|next,?\s+(?:add|set|run)|"
        r"then,?\s+(?:click|type|enter|select))\b",
        flags=re.IGNORECASE,
    )),
    ("Requirement", re.compile(
        r"\b(?:prerequisites?|requirements?|before\s+you\s+begin|you\s+(?:will\s+)?need|"
        r"make\s+sure\s+(?:you\s+)?have)\b",
        flags=re.IGNORECASE,
    )),
    ("Warning", re.compile(
        r"\b(?:warning|caution|important\s+note|do\s+not|be\s+careful|avoid)\b",
        flags=re.IGNORECASE,
    )),
    # --- review cues ---
    ("Strength", re.compile(
        r"\b(?:strength|advantage|pro|benefit|excels?\s+(?:at|in)|standout|impressive)\b",
        flags=re.IGNORECASE,
    )),
    ("Weakness", re.compile(
        r"\b(?:weakness|disadvantage|con|drawback|lacks?|disappointing|falls?\s+short)\b",
        flags=re.IGNORECASE,
    )),
    ("Verdict", re.compile(
        r"\b(?:verdict|final\s+(?:thought|word)|rating|score|overall\s+(?:impression|assessment)|recommend)\b",
        flags=re.IGNORECASE,
    )),
]

# labels allowed per article type (purpose labels from section-based or cue-based detection)
# that map to this type's vocabulary; any label not in this set falls back to the type's default
TYPE_ALLOWED_LABELS: dict[str, set[str]] = {
    "academic": {
        "Abstract", "Introduction", "Problem Statement", "Objective", "Methodology",
        "Respondents", "Research Instrument", "Data Gathering Procedure",
        "Statistical Treatment", "Results", "Discussion", "Conclusion",
        "Recommendation", "Background", "Literature Review", "Limitations", "Future Work",
    },
    "news": {
        "Headline / Lead", "Background", "Main Event", "Key Details",
        "Stakeholders", "Quote / Statement", "Impact", "Response", "What Happens Next",
    },
    "opinion": {
        "Main Claim", "Argument", "Supporting Reason", "Evidence",
        "Counterargument", "Author's Position", "Recommendation", "Final Opinion",
    },
    "blog": {
        "Introduction", "Personal Context", "Main Idea", "Explanation",
        "Advice", "Example", "Takeaway",
    },
    "tutorial": {
        "Goal", "Requirement", "Step", "Explanation", "Example",
        "Warning", "Troubleshooting", "Expected Output", "Final Result",
    },
    "educational": {
        "Introduction", "Main Idea", "Explanation", "Example", "Background",
        "Definition", "Key Details", "Takeaway", "Recommendation",
    },
    "technical_tutorial": {
        "Goal", "Requirement", "Setup", "Configuration", "Step", "Usage",
        "Parameter", "Example", "Warning", "Troubleshooting", "Expected Output",
        "Final Result",
    },
    "technical_docs": {
        "Overview", "Requirement", "Setup", "Configuration", "Usage",
        "Parameter", "Example", "Error Handling", "Security Note",
    },
    "legal_policy": {
        "Overview", "Legal Basis", "Scope", "Definition", "Requirement",
        "Obligation", "Restriction", "Exception", "Compliance", "Impact",
        "Recommendation", "Conclusion",
    },
    "review_article": {
        "Product / Service Overview", "Feature", "Strength", "Weakness",
        "Comparison", "Verdict", "Recommendation",
    },
    "case_study": {
        "Background", "Problem Statement", "Solution", "Implementation",
        "Results", "Lesson Learned", "Recommendation",
    },
    "business_report": {
        "Executive Summary", "Introduction", "Results", "Discussion",
        "Conclusion", "Recommendation", "Key Details",
    },
    "official_report": {
        "Activity Name", "Date and Venue", "Purpose", "Highlights",
        "Results", "Conclusion", "Recommendation", "Key Details",
    },
    "narrative": {
        "Introduction", "Background", "Main Event", "Key Details",
        "Turning Point", "Resolution", "Takeaway",
    },
    "general_article": {
        "Introduction", "Main Idea", "Explanation", "Supporting Detail",
        "Example", "Implication", "Takeaway",
    },
    "transcript_interview": {
        "Introduction", "Question", "Answer", "Discussion",
        "Statement", "Follow-up", "Conclusion",
    },
    "meeting_minutes": {
        "Call to Order", "Roll Call", "Agenda", "Discussion",
        "Decision", "Action Item", "Adjournment",
    },
    "official_letter": {
        "Sender", "Recipient", "Subject", "Purpose", "Request", "Supporting Details", "Action Needed", "Closing Statement",
    },
    "request_letter": {
        "Sender", "Recipient", "Subject", "Purpose", "Request", "Supporting Details", "Action Needed", "Closing Statement",
    },
    "memorandum": {
        "Sender", "Recipient", "Subject", "Instruction", "Reason", "Deadline", "Required Action",
    },
    "accomplishment_report": {
        "Activity Title", "Organizer", "Date and Venue", "Purpose", "Activities Conducted", "Coordination", "Outcome", "Institutional Relevance",
    },
    "activity_report": {
        "Activity Title", "Organizer", "Date and Venue", "Purpose", "Activities Conducted", "Coordination", "Outcome", "Institutional Relevance",
    },
    "proposal": {
        "Proposed Project", "Rationale", "Objectives", "Beneficiaries", "Implementation Plan", "Budget", "Expected Outcome",
    },
    "announcement": {
        "Headline / Lead", "Background", "Main Event", "Key Details", "Impact", "What Happens Next",
    },
    "short_text": {
        "Main Idea", "Key Details", "Takeaway",
    },
    "technical_code_document": {
        "Overview", "Code Snippet", "Command", "Configuration", "Usage", "Example", "Error Handling",
    },
}

# default label per article type when no specific purpose is detected
TYPE_DEFAULT_LABEL: dict[str, str] = {
    "academic": "General Explanation",
    "news": "Key Details",
    "opinion": "Argument",
    "blog": "Explanation",
    "tutorial": "Explanation",
    "educational": "Explanation",
    "technical_tutorial": "Step",
    "technical_docs": "Usage",
    "technical_report": "General Explanation",
    "legal_policy": "Requirement",
    "review_article": "Feature",
    "case_study": "Key Details",
    "business_report": "Key Details",
    "official_report": "Key Details",
    "narrative": "Key Details",
    "general_article": "Explanation",
    "transcript_interview": "Dialogue",
    "meeting_minutes": "Note",
    "official_letter": "Explanation",
    "request_letter": "Explanation",
    "memorandum": "Instruction",
    "accomplishment_report": "Key Details",
    "activity_report": "Key Details",
    "proposal": "Key Details",
    "announcement": "Key Details",
    "short_text": "Explanation",
    "technical_code_document": "Code",
}

ARTICLE_TYPE_FAMILY: dict[str, str] = {
    "academic": "academic",
    "news": "news",
    "blog": "blog",
    "opinion": "opinion",
    "tutorial": "educational",
    "educational": "educational",
    "technical_tutorial": "technical",
    "technical_docs": "technical",
    "technical_report": "technical",
    "business_report": "business",
    "review_article": "review",
    "legal_policy": "legal",
    "case_study": "general",
    "narrative": "general",
    "business_report": "business",
    "official_report": "official",
    "general_article": "general",
    "transcript_interview": "transcript",
    "meeting_minutes": "meeting",
    "official_letter": "official",
    "request_letter": "official",
    "memorandum": "official",
    "accomplishment_report": "official",
    "activity_report": "official",
    "proposal": "business",
    "announcement": "news",
    "short_text": "general",
    "technical_code_document": "technical",
}

ARTICLE_TYPE_RELEVANCE_PATTERNS: dict[str, tuple[re.Pattern[str], ...]] = {
    "academic": (
        re.compile(r"\b(?:objective|purpose|method|methodology|respondents?|sample|results?|findings?|discussion|conclusion|recommended?)\b", re.IGNORECASE),
        re.compile(r"\b(?:study|research|paper|analysis|survey|experiment)\b", re.IGNORECASE),
    ),
    "news": (
        re.compile(r"\b(?:officials?|announced|reported|residents?|police|mayor|agency|storm|flood|evacuation|districts?)\b", re.IGNORECASE),
        re.compile(r"\b(?:today|yesterday|monday|tuesday|wednesday|thursday|friday|saturday|sunday|overnight)\b", re.IGNORECASE),
    ),
    "blog": (
        re.compile(r"\b(?:in this post|from my experience|personally|takeaway|tip|advice|helpful)\b", re.IGNORECASE),
        re.compile(r"\b(?:blog|post|reader|practical)\b", re.IGNORECASE),
    ),
    "technical": (
        re.compile(r"\b(?:install|configure|setup|endpoint|api|function|class|module|command|cli|run|deploy|script|parameter)\b", re.IGNORECASE),
        re.compile(r"\b(?:step|steps|usage|response|request|troubleshoot|error|warning)\b", re.IGNORECASE),
    ),
    "business": (
        re.compile(r"\b(?:revenue|market|growth|quarter|annual|strategy|customers?|sales|profit|cost|forecast|industry)\b", re.IGNORECASE),
        re.compile(r"\b(?:trend|performance|investment|share|operations?)\b", re.IGNORECASE),
    ),
    "official": (
        re.compile(r"\b(?:activity name|venue|date|purpose|documentation|annexes|signatures|highlights|prepared by)\b", re.IGNORECASE),
        re.compile(r"\b(?:official report|post-activity|completion report|narrative report)\b", re.IGNORECASE),
    ),
    "educational": (
        re.compile(r"\b(?:students?|teachers?|classroom|lesson|learning|curriculum|module|concept|explains?|example)\b", re.IGNORECASE),
        re.compile(r"\b(?:understand|remember|instruction|teaching|education)\b", re.IGNORECASE),
    ),
    "opinion": (
        re.compile(r"\b(?:i think|i believe|in my view|we should|argue|contend|opinion|claim)\b", re.IGNORECASE),
        re.compile(r"\b(?:because|therefore|however|ultimately)\b", re.IGNORECASE),
    ),
    "review": (
        re.compile(r"\b(?:pros?|cons?|strengths?|weaknesses?|verdict|rating|compare|comparison|recommend)\b", re.IGNORECASE),
        re.compile(r"\b(?:advantage|drawback|feature|impression)\b", re.IGNORECASE),
    ),
    "legal": (
        re.compile(r"\b(?:shall|must|rights?|responsibilities|obligations?|prohibited|restriction|policy|act|section|compliance)\b", re.IGNORECASE),
        re.compile(r"\b(?:authority|pursuant|law|regulation|exception|penalty)\b", re.IGNORECASE),
    ),
    "transcript": (
        re.compile(r"\b(?:question|answer|said|told|asked|interviewer|guest|speaker)\b", re.IGNORECASE),
        re.compile(r"^\s*(?:[A-Z][a-z]+|[A-Z]{2,}):\s+", re.MULTILINE),
    ),
    "meeting": (
        re.compile(r"\b(?:agenda|attendees?|minutes|adjourned|motion|decided|assigned)\b", re.IGNORECASE),
        re.compile(r"\b(?:call to order|roll call|action items?|next meeting)\b", re.IGNORECASE),
    ),
    "narrative": (
        re.compile(r"\b(?:character|protagonist|story|plot|scene|dialogue|chapter|narrative|setting|conflict|resolution)\b", re.IGNORECASE),
        re.compile(r"\b(?:once upon a time|in a world|suddenly|eventually|felt|remembered|thought)\b", re.IGNORECASE),
    ),
    "general": (
        re.compile(r"\b(?:explains?|describes?|discusses?|outlines?|overview|important)\b", re.IGNORECASE),
    ),
}

SECTION_IMPORTANCE = {
    "academic": {
        "abstract": 1.0,
        "introduction": 0.82,
        "background": 0.76,
        "literature_review": 0.6,
        "methodology": 0.86,
        "results": 1.0,
        "discussion": 0.95,
        "conclusion": 1.0,
        "recommendations": 0.9,
        "limitations": 0.65,
        "future_work": 0.6,
        "references": 0.0,
        "acknowledgment": 0.0,
    },
    "news": {
        "lead": 1.0,
        "body": 0.82,
        "outcome": 0.9,
        "background": 0.76,
        "conclusion": 0.86,
    },
    "blog": {
        "overview": 0.92,
        "introduction": 0.88,
        "body": 0.82,
        "conclusion": 0.95,
        "recommendations": 0.9,
    },
    "educational": {
        "introduction": 0.9,
        "background": 0.82,
        "overview": 0.88,
        "body": 0.84,
        "discussion": 0.86,
        "conclusion": 0.9,
        "recommendations": 0.84,
    },
    "technical_report": {
        "executive_summary": 1.0,
        "background": 0.8,
        "system_description": 0.84,
        "findings": 1.0,
        "results": 0.96,
        "recommendations": 0.96,
        "conclusion": 0.92,
    },
    "case_study": {
        "background": 0.84,
        "problem": 0.96,
        "solution": 0.96,
        "results": 1.0,
        "outcome": 0.96,
        "conclusion": 0.9,
        "lessons_learned": 0.94,
    },
    "review_article": {
        "overview": 0.92,
        "literature_review": 0.88,
        "comparison": 0.96,
        "evaluation": 0.96,
        "discussion": 0.9,
        "conclusion": 0.96,
    },
    "general_article": {
        "introduction": 0.84,
        "overview": 0.84,
        "body": 0.8,
        "results": 0.88,
        "discussion": 0.86,
        "conclusion": 0.92,
    },
    "opinion": {
        "introduction": 0.88,
        "body": 0.9,
        "conclusion": 0.96,
        "recommendations": 0.92,
        "discussion": 0.88,
    },
    "tutorial": {
        "introduction": 0.82,
        "overview": 0.84,
        "body": 0.92,
        "conclusion": 0.88,
        "recommendations": 0.8,
    },
    "technical_tutorial": {
        "overview": 0.9,
        "introduction": 0.84,
        "body": 0.94,
        "system_description": 0.9,
        "conclusion": 0.88,
        "recommendations": 0.82,
    },
    "technical_docs": {
        "overview": 0.92,
        "introduction": 0.84,
        "body": 0.9,
        "system_description": 0.96,
        "conclusion": 0.84,
    },
    "legal_policy": {
        "overview": 0.9,
        "legal_basis": 0.98,
        "policy_scope": 0.94,
        "definitions": 0.72,
        "obligations": 1.0,
        "recommendations": 0.86,
        "conclusion": 0.9,
        "body": 0.88,
    },
    "business_report": {
        "executive_summary": 1.0,
        "introduction": 0.84,
        "results": 0.96,
        "discussion": 0.92,
        "conclusion": 0.96,
        "recommendations": 0.96,
    },
    "official_report": {
        "activity_name": 1.0,
        "date_and_venue": 1.0,
        "purpose": 1.0,
        "highlights": 0.96,
        "results": 0.9,
        "conclusion": 0.96,
        "recommendations": 0.9,
        "annexes": 0.0,
        "documentation": 0.0,
        "signatures": 0.0,
    },
    "narrative": {
        "lead": 1.0,
        "introduction": 0.88,
        "body": 0.9,
        "conclusion": 0.92,
        "outcome": 0.88,
    },
    "transcript": {
        "introduction": 0.85,
        "question": 1.0,
        "answer": 0.95,
        "discussion": 0.9,
        "conclusion": 0.9,
    },
    "meeting_minutes": {
        "agenda": 0.8,
        "discussion": 0.9,
        "decision": 1.0,
        "action item": 1.0,
        "conclusion": 0.8,
    },
}
STRUCTURED_ROLES = {
    "academic": [
        ("Purpose", {"abstract", "introduction", "background"}, ("purpose", "aim", "study", "paper", "research")),
        ("Methodology", {"methodology"}, ("method", "approach", "survey", "experiment", "sample", "participants")),
        ("Key Findings", {"results", "discussion"}, ("result", "finding", "show", "significant", "increase", "decrease")),
        ("Conclusion", {"conclusion", "discussion"}, ("conclude", "suggest", "indicate", "overall")),
        ("Recommendation", {"recommendations", "conclusion"}, ("recommend", "should", "future", "policy")),
    ],
    "news": [
        ("Who / What", {"lead", "body"}, ()),
        ("When / Where", {"lead", "body"}, ("today", "yesterday", "monday", "tuesday", "wednesday", "thursday", "friday")),
        ("Why It Matters", {"body", "outcome", "conclusion"}, ("impact", "affect", "matter", "because", "importance")),
        ("Outcome / Status", {"outcome", "conclusion", "body"}, ("ongoing", "status", "confirmed", "announced", "remain")),
    ],
    "blog": [
        ("Main Argument", {"overview", "introduction", "body"}, ("argue", "believe", "should", "important")),
        ("Supporting Point", {"body", "comparison", "evaluation"}, ("because", "for example", "benefit", "challenge")),
        ("Practical Takeaway", {"conclusion", "recommendations", "body"}, ("takeaway", "practical", "should", "can")),
    ],
    "educational": [
        ("Topic", {"introduction", "overview", "background"}, ("topic", "lesson", "concept", "explains")),
        ("Core Explanation", {"body", "discussion"}, ("explains", "means", "refers", "because", "concept")),
        ("Example", {"body", "discussion"}, ("example", "for instance", "such as")),
        ("Takeaway", {"conclusion", "recommendations", "body"}, ("remember", "key point", "important", "takeaway")),
    ],
    "technical_report": [
        ("Problem", {"background", "problem", "executive_summary"}, ("problem", "issue", "challenge", "risk")),
        ("Approach", {"system_description", "methodology", "solution"}, ("approach", "system", "process", "implementation")),
        ("Findings", {"findings", "results"}, ("finding", "result", "show", "identified")),
        ("Recommendation", {"recommendations", "conclusion"}, ("recommend", "should", "next step", "priority")),
    ],
    "case_study": [
        ("Context", {"background", "overview"}, ("context", "case", "setting", "background")),
        ("Problem", {"problem", "background"}, ("problem", "challenge", "issue")),
        ("Solution / Intervention", {"solution", "methodology"}, ("solution", "intervention", "approach", "implemented")),
        ("Result", {"results", "outcome"}, ("result", "outcome", "improved", "reduced", "increased")),
        ("Lesson Learned", {"lessons_learned", "conclusion"}, ("lesson", "insight", "learned", "takeaway")),
    ],
    "review_article": [
        ("Overview", {"overview", "introduction", "literature_review"}, ("overview", "review", "survey")),
        ("Comparison", {"comparison", "evaluation", "discussion"}, ("compare", "comparison", "contrast")),
        ("Evaluation", {"evaluation", "discussion"}, ("strength", "weakness", "limitation", "advantage")),
        ("Conclusion", {"conclusion", "discussion"}, ("overall", "conclude", "suggest")),
    ],
    "opinion": [
        ("Main Claim", {"introduction", "overview", "body"}, ("argue", "believe", "contend", "claim", "should")),
        ("Supporting Argument", {"body"}, ("because", "reason", "evidence", "example", "shows")),
        ("Counterpoint", {"body", "discussion"}, ("however", "critics", "counter", "opposing", "but")),
        ("Final Stance", {"conclusion", "body"}, ("overall", "ultimately", "therefore", "in conclusion")),
    ],
    "tutorial": [
        ("Goal", {"introduction", "overview"}, ("goal", "learn", "build", "create", "set up")),
        ("Requirements", {"body", "introduction"}, ("prerequisite", "requirement", "need", "install", "version")),
        ("Steps", {"body"}, ("step", "first", "next", "then", "run", "click", "enter", "configure")),
        ("Expected Result", {"conclusion", "body"}, ("result", "output", "should see", "expected", "final")),
    ],
    "technical_tutorial": [
        ("Goal", {"introduction", "overview"}, ("goal", "learn", "build", "create", "set up")),
        ("Requirements", {"body", "system_description"}, ("prerequisite", "requirement", "install", "version")),
        ("Implementation Steps", {"body", "system_description"}, ("step", "configure", "run", "endpoint", "function")),
        ("Expected Result", {"conclusion", "body"}, ("result", "output", "response", "expected", "final")),
    ],
    "technical_docs": [
        ("Overview", {"overview", "introduction"}, ("overview", "purpose", "provides", "used for")),
        ("Configuration", {"body"}, ("config", "parameter", "setting", "option", "flag")),
        ("Usage", {"body"}, ("usage", "example", "call", "invoke", "method", "function", "endpoint")),
        ("Errors", {"body", "conclusion"}, ("error", "exception", "throws", "warning", "troubleshoot")),
    ],
    "legal_policy": [
        ("Legal Basis", {"legal_basis", "introduction", "overview"}, ("law", "rule", "authority", "pursuant")),
        ("Scope", {"policy_scope", "overview", "body"}, ("scope", "applies", "covered", "applicable")),
        ("Requirements", {"obligations", "body"}, ("shall", "must", "required", "responsible", "compliance")),
        ("Exceptions", {"body", "definitions"}, ("except", "unless", "provided", "exemption")),
        ("Impact", {"conclusion", "recommendations", "body"}, ("impact", "effect", "penalty", "risk", "recommend")),
    ],
    "business_report": [
        ("Summary", {"executive_summary", "introduction", "overview"}, ("summary", "overview", "report")),
        ("Key Findings", {"body", "results"}, ("finding", "data", "revenue", "growth", "market", "trend")),
        ("Analysis", {"body", "discussion"}, ("analysis", "indicates", "suggests", "compared")),
        ("Recommendation", {"recommendations", "conclusion"}, ("recommend", "should", "strategy", "action")),
    ],
    "official_report": [
        ("Activity Details", {"activity_name", "date_and_venue"}, ("activity", "date", "venue")),
        ("Purpose", {"purpose", "introduction"}, ("purpose", "objective", "rationale")),
        ("Highlights", {"highlights", "body", "results"}, ("highlight", "event", "conducted", "facilitated")),
        ("Conclusion", {"conclusion", "outcome"}, ("overall", "success", "concluded")),
    ],
    "narrative": [
        ("Setting", {"introduction", "background", "lead"}, ("began", "morning", "scene", "place", "town", "city")),
        ("Characters", {"body", "lead"}, ("said", "told", "remembers", "grew up", "worked")),
        ("Events", {"body"}, ("happened", "then", "after", "during", "when", "while")),
        ("Resolution", {"conclusion", "outcome"}, ("finally", "end", "today", "now", "result", "legacy")),
    ],
    "general_article": [
        ("Main Topic", {"introduction", "overview", "lead"}, ()),
        ("Key Evidence", {"body", "results", "discussion"}, ("because", "evidence", "data", "shows")),
        ("Conclusion", {"conclusion", "outcome", "body"}, ("overall", "therefore", "in conclusion", "finally")),
    ],
    "transcript": [
        ("Topic / Question", {"introduction", "question"}, ("discuss", "today", "ask", "question")),
        ("Key Answer", {"answer", "discussion"}, ("think", "believe", "actually", "point")),
        ("Follow-up", {"discussion", "follow-up"}, ("clarify", "mean", "also", "addition")),
        ("Conclusion", {"conclusion", "statement"}, ("wrap up", "finally", "thank", "conclude")),
    ],
    "meeting_minutes": [
        ("Agenda / Goal", {"agenda", "call to order"}, ("agenda", "goal", "discussing", "objective")),
        ("Key Discussion", {"discussion"}, ("noted", "discussed", "raised", "pointed out")),
        ("Decisions Made", {"decision"}, ("decided", "approved", "agreed", "resolved")),
        ("Action Items", {"action item"}, ("assigned", "will", "deadline", "task", "follow up")),
        ("Conclusion", {"conclusion"}, ("conclusion",)),
    ],
    # --- Profile mode keys (used by selection-aware pipeline) ---
    "study": [
        ("Overview", {"introduction", "overview", "background"}, ("topic", "lesson", "subject", "concept", "about")),
        ("Core Concepts", {"body", "discussion", "background"}, ("defined", "definition", "refers to", "means", "concept", "principle")),
        ("Examples", {"body", "discussion"}, ("example", "for instance", "such as", "illustrate", "consider")),
        ("Key Takeaways", {"conclusion", "recommendations", "body"}, ("important", "remember", "key point", "takeaway", "therefore")),
    ],
    "executive": [
        ("Summary", {"executive_summary", "introduction", "overview"}, ("summary", "overview", "report", "purpose")),
        ("Key Findings", {"results", "discussion", "body"}, ("finding", "data", "result", "outcome", "showed", "revealed")),
        ("Risks & Implications", {"body", "discussion", "conclusion"}, ("risk", "impact", "implication", "consequence", "affect")),
        ("Recommendation", {"recommendations", "conclusion"}, ("recommend", "should", "action", "next step", "strategy", "priority")),
    ],
    "technical": [
        ("Overview", {"overview", "introduction", "system_description"}, ("purpose", "provides", "used for", "enables", "designed")),
        ("Architecture", {"body", "system_description"}, ("architecture", "component", "module", "service", "layer", "interface", "api")),
        ("Implementation Steps", {"body"}, ("step", "configure", "install", "run", "deploy", "endpoint", "function", "parameter")),
        ("Constraints & Errors", {"body", "conclusion"}, ("requires", "constraint", "limitation", "error", "exception", "warning", "fail")),
        ("Expected Result", {"conclusion", "body"}, ("result", "output", "response", "expected", "final", "should see")),
    ],
    "news": [
        ("Who / What", {"lead", "body"}, ()),
        ("When / Where", {"lead", "body"}, ("today", "yesterday", "monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday")),
        ("Key Developments", {"body", "outcome"}, ("announced", "confirmed", "declared", "launched", "released", "signed")),
        ("Why It Matters", {"body", "outcome", "conclusion"}, ("impact", "affect", "consequence", "aftermath", "because", "result")),
        ("Outcome / Status", {"outcome", "conclusion", "body"}, ("ongoing", "status", "confirmed", "remain", "resolved", "update")),
    ],
}


SUMMARY_LENGTH_CONFIG: dict[str, dict[str, Any]] = {
    "brief": {
        "label": "Brief",
        "detail_level": 1,
        "target_ratio": 0.05,
        "min_sentences": 2,
        "max_sentences": 4,
        "min_words": 25,
        "max_words": 90,
        "max_sentence_length_words": 20,
        "section_coverage_weight": 0.0,
        "prompt_instruction": "Focus strictly on the core main idea and most essential takeaway.",
    },
    "short": {
        "label": "Short",
        "detail_level": 2,
        "target_ratio": 0.10,
        "min_sentences": 3,
        "max_sentences": 6,
        "min_words": 50,
        "max_words": 160,
        "max_sentence_length_words": 25,
        "section_coverage_weight": 0.3,
        "prompt_instruction": "Include the central idea and the most important supporting points.",
    },
    "balanced": {
        "label": "Balanced",
        "detail_level": 3,
        "target_ratio": 0.18,
        "min_sentences": 5,
        "max_sentences": 10,
        "min_words": 100,
        "max_words": 300,
        "max_sentence_length_words": 30,
        "section_coverage_weight": 0.6,
        "prompt_instruction": "Provide the main idea, major supporting points, important context, and necessary explanations.",
    },
    "detailed": {
        "label": "Detailed",
        "detail_level": 4,
        "target_ratio": 0.28,
        "min_sentences": 8,
        "max_sentences": 16,
        "min_words": 200,
        "max_words": 500,
        "max_sentence_length_words": 38,
        "section_coverage_weight": 0.85,
        "prompt_instruction": "Preserve substantial supporting details, explanations, relationships, and key evidence.",
    },
    "comprehensive": {
        "label": "Comprehensive",
        "detail_level": 5,
        "target_ratio": 0.40,
        "min_sentences": 12,
        "max_sentences": 25,
        "min_words": 320,
        "max_words": 850,
        "max_sentence_length_words": 48,
        "section_coverage_weight": 1.0,
        "prompt_instruction": "Produce a highly complete summary preserving all significant ideas, arguments, findings, relationships, and key supporting information.",
    },
}



