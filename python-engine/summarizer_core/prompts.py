"""
Per-combination prompt templates parameterized by profile and length.

Replaces the legacy 'generate everything' monolithic approach with focused,
single-combination prompt templates where each invocation produces exactly
one summary tailored to the requested (profile, length) pair.
"""

from __future__ import annotations

from typing import Any

PROFILE_INSTRUCTIONS: dict[str, str] = {
    "general": (
        "Focus on core ideas, key arguments, and major conclusions. "
        "Maintain an objective, accessible tone suitable for a general audience."
    ),
    "academic": (
        "Emphasize research questions, theoretical framing, methodology, empirical findings, "
        "and scholarly implications. Maintain a formal academic tone."
    ),
    "executive": (
        "Prioritize strategic impact, high-level decisions, operational outcomes, "
        "and actionable takeaways for executive leadership."
    ),
    "technical": (
        "Highlight system architecture, engineering specifications, implementation details, "
        "mechanisms, constraints, and quantitative trade-offs."
    ),
    "study": (
        "Structure around foundational concepts, clear principle explanations, key definitions, "
        "and study takeaways for learning and retention."
    ),
    "news": (
        "Use an inverted pyramid style: lead with the most newsworthy facts (who, what, where, "
        "when, why), followed by crucial context and immediate implications."
    ),
}

LENGTH_INSTRUCTIONS: dict[str, dict[str, Any]] = {
    "brief": {
        "label": "Brief",
        "sentence_range": "1-2 sentences",
        "approx_words": "30-50 words",
        "instruction": "Distill strictly the single most vital conclusion or premise. Maximum conciseness.",
    },
    "balanced": {
        "label": "Balanced",
        "sentence_range": "3-5 sentences",
        "approx_words": "100-180 words",
        "instruction": "Deliver a well-rounded summary preserving the primary premise, key supporting points, and major conclusion.",
    },
    "detailed": {
        "label": "Detailed",
        "sentence_range": "6-10 sentences",
        "approx_words": "200-350 words",
        "instruction": "Provide thorough coverage of major arguments, supporting evidence, key examples, and qualifications.",
    },
    "comprehensive": {
        "label": "Comprehensive",
        "sentence_range": "12+ sentences",
        "approx_words": "400+ words",
        "instruction": "Produce an exhaustive, high-coverage synthesis preserving structural sections, nuanced details, and full findings.",
    },
}

ALLOWED_PROFILES = tuple(PROFILE_INSTRUCTIONS.keys())
ALLOWED_LENGTHS = tuple(LENGTH_INSTRUCTIONS.keys())


def render_combination_prompt(profile: str, length: str, source_text: str) -> str:
    """Render a single-combination prompt template parameterized by profile and length."""
    norm_profile = (profile or "").strip().lower()
    norm_length = (length or "").strip().lower()

    if norm_profile not in PROFILE_INSTRUCTIONS:
        raise ValueError(
            f"Invalid profile '{profile}'. Allowed profiles: {', '.join(ALLOWED_PROFILES)}."
        )
    if norm_length not in LENGTH_INSTRUCTIONS:
        raise ValueError(
            f"Invalid length '{length}'. Allowed lengths: {', '.join(ALLOWED_LENGTHS)}."
        )

    prof_instruction = PROFILE_INSTRUCTIONS[norm_profile]
    length_cfg = LENGTH_INSTRUCTIONS[norm_length]

    return f"""You are an expert summarization engine. Produce exactly ONE summary for the provided source text.

Target Configuration:
- Profile: {norm_profile.capitalize()} — {prof_instruction}
- Length: {length_cfg['label']} ({length_cfg['sentence_range']}, approx {length_cfg['approx_words']}) — {length_cfg['instruction']}

Directives:
1. Generate strictly one coherent summary matching the specified profile and length.
2. Rely exclusively on the source text; never invent facts, extrapolate unsupported claims, or pad content.
3. Do not output multi-section labels, colons, or alternative profiles.

Source Text:
\"\"\"
{source_text.strip()}
\"\"\"

Summary:"""


__all__ = [
    "ALLOWED_LENGTHS",
    "ALLOWED_PROFILES",
    "LENGTH_INSTRUCTIONS",
    "PROFILE_INSTRUCTIONS",
    "render_combination_prompt",
]
