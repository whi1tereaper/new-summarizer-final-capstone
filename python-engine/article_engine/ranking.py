"""Public article-ranking facade; v1 is frozen under evaluation/ only."""
from .coverage import analyze, QUALIFICATION as QUALIFIER, REFERENCE, LAYOUT

MODES = {
    "academic": r"study|method|participants|results|findings|limitations|conclusion",
    "scientific": r"experiment|measured|observed|results|evidence|uncertainty",
    "technical": r"protocol|architecture|mechanism|system|algorithm|risk",
    "news": r"said|announced|officials|reported|closed|affected",
    "executive": r"cost|revenue|risk|decision|forecast|profit",
    "study": r"definition|concept|principle|example|means",
    "legal": r"shall|must|prohibited|obligation|liability",
    "general": r"findings|results|conclusion|limitations|explains",
}


def select(records, analysis, count, budget, mode, focus):
    from .selection import select as coverage_select
    chosen, status, _ = coverage_select(records, analysis, count, budget, mode, focus)
    return chosen, status
