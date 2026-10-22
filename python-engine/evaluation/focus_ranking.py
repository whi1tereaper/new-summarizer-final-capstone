"""Curated deterministic relevance set and ranking metrics for Focus evaluation."""

from __future__ import annotations

from dataclasses import dataclass
import math


@dataclass(frozen=True)
class FocusEvaluationCase:
    name: str
    query: str
    candidates: tuple[str, ...]
    relevance_grades: tuple[int, ...]
    split: str = "holdout"
    context_texts: tuple[str, ...] = ()


FOCUS_EVALUATION_CASES = (
    FocusEvaluationCase(
        "exact-phrase",
        "What clinical outcomes were measured?",
        (
            "The report summarized financial results from the same year.",
            "The study measured clinical outcomes in adult patients.",
        ),
        (0, 2),
        split="development",
    ),
    FocusEvaluationCase(
        "negation-and-paraphrase",
        "Why did the policy not reduce costs?",
        (
            "The policy reduced costs for urban households.",
            "The policy failed to reduce costs for rural households.",
            "The report describes the transportation budget.",
        ),
        (0, 2, 0),
        split="development",
    ),
    FocusEvaluationCase(
        "query-word-order",
        "health care outcomes",
        (
            "Outcomes affect access to health care.",
            "Health care outcomes improved after treatment.",
        ),
        (0, 2),
        split="development",
    ),
    FocusEvaluationCase(
        "specific-numeric-detail",
        "What was the change in operating costs?",
        (
            "Operating costs increased by 12 percent during the year.",
            (
                "The report provides background, methods, and financial context; "
                "operating costs increased by 12 percent during the year."
            ),
        ),
        (2, 1),
        split="development",
    ),
    FocusEvaluationCase(
        "comparison-and-direction",
        "Did revenue increase while operating costs fell?",
        (
            "Revenue increased by 8 percent during the year.",
            "Operating costs fell by 12 percent after the tariff change.",
            "Revenue remained stable throughout the same period.",
        ),
        (2, 2, 0),
    ),
    FocusEvaluationCase(
        "multi-part-query",
        "What happened to enrollment and completion rates?",
        (
            "Enrollment increased by 14 percent after the new program began.",
            "Completion rates improved from 61 to 74 percent.",
            "The report was published in June.",
        ),
        (2, 2, 0),
    ),
    FocusEvaluationCase(
        "synonym-paraphrase",
        "How did the new schedule affect employee absences?",
        (
            "Staff missed fewer workdays after the schedule changed.",
            "The schedule was approved by the board in April.",
            "Employee salaries were reviewed annually.",
        ),
        (2, 0, 0),
    ),
    FocusEvaluationCase(
        "numeric-comparison",
        "Which group had the higher response rate?",
        (
            "The treatment group response rate was 68 percent.",
            "The control group response rate was 54 percent.",
            "Both groups were recruited from four clinics.",
        ),
        (2, 2, 0),
    ),
    FocusEvaluationCase(
        "negation-scope",
        "Which treatment did not reduce symptoms?",
        (
            "Treatment A reduced symptoms by 20 percent.",
            "Treatment B did not reduce symptoms during the trial.",
            "Symptoms were assessed weekly by clinicians.",
        ),
        (0, 2, 0),
    ),
    FocusEvaluationCase(
        "section-context",
        "What was the annual increase limit?",
        (
            "The committee reviewed the figures at its regular meeting.",
            "The cap was 5 percent.",
            "The program served 2,400 households.",
        ),
        (0, 2, 0),
        context_texts=("", "Annual Increase Limit", ""),
    ),
    FocusEvaluationCase(
        "nearby-context",
        "How did the revised tariff affect operating costs?",
        (
            "Operating costs were reviewed each year and no major changes were reported.",
            "They fell by 12 percent the following year.",
            "The committee met in the spring.",
        ),
        (0, 2, 0),
        context_texts=("", "The revised tariff reduced operating costs.", ""),
    ),
    FocusEvaluationCase(
        "no-evidence-domain",
        "clinical outcomes",
        (
            "The report describes routine financial results.",
            "The committee scheduled a meeting for Thursday.",
        ),
        (0, 0),
    ),
    FocusEvaluationCase(
        "no-evidence-cause",
        "What caused the bridge closure?",
        (
            "The bridge was closed for repairs on Tuesday.",
            "The closure will remain in effect for two weeks.",
        ),
        (0, 0),
    ),
)


def ranking_metrics(
    ranked_indices: list[int],
    relevance_grades: tuple[int, ...],
    scores: list[float] | tuple[float, ...] | None = None,
    cutoff: int = 5,
) -> dict[str, float]:
    """Return MRR, Recall@1, and nDCG@cutoff for a single labeled query."""
    relevant = {
        index for index, grade in enumerate(relevance_grades) if grade > 0
    }
    reciprocal_rank = next(
        (
            1.0 / rank
            for rank, candidate_index in enumerate(ranked_indices, start=1)
            if candidate_index in relevant
        ),
        0.0,
    )
    recall_at_1 = float(bool(ranked_indices) and ranked_indices[0] in relevant)
    ranked_relevance = [
        relevance_grades[index] for index in ranked_indices[:cutoff]
    ]
    dcg = sum(
        (2**grade - 1) / math.log2(rank + 1)
        for rank, grade in enumerate(ranked_relevance, start=1)
    )
    ideal_relevance = sorted(relevance_grades, reverse=True)[:cutoff]
    ideal_dcg = sum(
        (2**grade - 1) / math.log2(rank + 1)
        for rank, grade in enumerate(ideal_relevance, start=1)
    )
    return {
        "reciprocal_rank": reciprocal_rank,
        "recall_at_1": recall_at_1,
        f"ndcg_at_{cutoff}": dcg / ideal_dcg if ideal_dcg else 0.0,
        "no_evidence_nonzero_score_at_1": float(
            not relevant
            and scores is not None
            and bool(scores)
            and max(scores) > 0.0
        ),
    }


def aggregate_ranking_metrics(
    cases: tuple[FocusEvaluationCase, ...],
    ranked_orders: list[list[int]],
    score_lists: list[list[float]] | None = None,
    cutoff: int = 5,
) -> dict[str, float]:
    """Average ranking metrics over labeled cases."""
    if len(cases) != len(ranked_orders):
        raise ValueError("Each evaluation case must have one ranked order.")
    if score_lists is not None and len(cases) != len(score_lists):
        raise ValueError("Each evaluation case must have one score list.")
    if not cases:
        return {
            "reciprocal_rank": 0.0,
            "recall_at_1": 0.0,
            f"ndcg_at_{cutoff}": 0.0,
            "no_evidence_nonzero_score_at_1": 0.0,
        }
    metrics = []
    no_evidence_metrics = []
    for index, (case, order) in enumerate(zip(cases, ranked_orders, strict=True)):
        scores = score_lists[index] if score_lists is not None else None
        case_metrics = ranking_metrics(
            order,
            case.relevance_grades,
            scores,
            cutoff,
        )
        if any(case.relevance_grades):
            metrics.append(case_metrics)
        else:
            no_evidence_metrics.append(case_metrics)
    if not metrics:
        metrics = [{
            "reciprocal_rank": 0.0,
            "recall_at_1": 0.0,
            f"ndcg_at_{cutoff}": 0.0,
        }]
    return {
        "reciprocal_rank": sum(
            item["reciprocal_rank"] for item in metrics
        ) / len(metrics),
        "recall_at_1": sum(
            item["recall_at_1"] for item in metrics
        ) / len(metrics),
        f"ndcg_at_{cutoff}": sum(
            item[f"ndcg_at_{cutoff}"] for item in metrics
        ) / len(metrics),
        "no_evidence_nonzero_score_at_1": sum(
            item["no_evidence_nonzero_score_at_1"]
            for item in no_evidence_metrics
        ) / len(no_evidence_metrics) if no_evidence_metrics else 0.0,
    }
