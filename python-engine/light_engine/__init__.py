"""Independent, local-only LIGHT extractive summarization engine."""

from .contract import ContractError, SummaryRequest
from .pipeline import summarize

__all__ = ["ContractError", "SummaryRequest", "summarize"]
