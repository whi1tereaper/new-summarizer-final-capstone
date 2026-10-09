"""Request-local experimental hooks around the existing production pipeline.

No module globals, saved settings or normal requests are modified. Ablations
remove only the mechanism named in ABLATIONS; other safeguards still apply.
"""
from dataclasses import replace
import inspect
from time import perf_counter

from summarizer_core.pipeline import SummarizationPipeline
from summarizer_core.profiles import resolve_profile
from summarizer_core.scoring import calculate_candidate_scores_with_profile
from summarizer_core.selection import select_candidate_indices_with_profile
from summarizer_core.tokenization import OFFLINE_RESOURCES

ABLATIONS = {
    "title_similarity": "Remove the title-similarity ranking weight; retrieval title queries remain unchanged.",
    "section_weighting": "Remove the section-importance ranking weight; section coverage and depth rules remain unchanged.",
    "sentence_position": "Remove the document sentence-position ranking weight.",
    "paragraph_position": "Remove the paragraph-position ranking weight.",
    "profile_weighting": "Replace the six profile-specific base ranking weights with general-profile weights; other profile behavior remains unchanged.",
    "article_type_relevance": "Remove the article-type relevance ranking weight; type detection remains unchanged.",
    "redundancy_filtering": "Disable selection duplicate/near-duplicate rejection, pairwise novelty penalty, and output sentence deduplication; preprocessing and topic balancing remain unchanged.",
    "qualifier_protection": "Disable qualifier ranking bonus and compression protected spans; general cleanup and validation remain unchanged.",
    "negation_protection": "Disable negation ranking bonus and compression protected spans; general cleanup and validation remain unchanged.",
    "numerical_fact_protection": "Disable numeric-fact ranking bonus and compression protected spans; depth evidence bonuses and validation remain unchanged.",
}
WEIGHT_KEYS = {"title_similarity": "title", "section_weighting": "section", "sentence_position": "position", "paragraph_position": "paragraph_pos", "article_type_relevance": "type_relevance"}


class EvaluationPipeline(SummarizationPipeline):
    def __init__(self, ablation=None):
        if ablation and ablation not in ABLATIONS:
            raise ValueError("Unsupported ablation.")
        self.disabled = frozenset([ablation]) if ablation else frozenset()
        self.candidates = []
        self.timings = {}
        self._post_started = None

    def summarize(self, request):
        self._started = perf_counter()
        self._pre_finished = self._started
        self._post_started = None
        token = OFFLINE_RESOURCES.set(True)
        try:
            result = super().summarize(request)
        finally:
            OFFLINE_RESOURCES.reset(token)
        finished = perf_counter()
        post = self._post_started or finished
        self.timings = {
            "preprocessing_seconds": self._pre_finished - self._started,
            "summarization_seconds": post - self._pre_finished,
            "postprocessing_seconds": finished - post,
        }
        return result

    def _build_sentence_candidates(self, *args, **kwargs):
        result = super()._build_sentence_candidates(*args, **kwargs)
        self.candidates = result[0]
        if len(self.candidates) > 2500:
            raise ValueError("Evaluation source exceeds 2500 candidate sentences; split the source explicitly.")
        self._pre_finished = perf_counter()
        return result

    def _compress_selected_sentences(self, *args, **kwargs):
        if self._post_started is None:
            self._post_started = perf_counter()
        return super()._compress_selected_sentences(*args, **kwargs)

    def _score_candidates_with_profile(self, *args, **kwargs):
        bound = inspect.signature(SummarizationPipeline._score_candidates_with_profile).bind(self, *args, **kwargs)
        bound.apply_defaults()
        parameters = dict(bound.arguments)
        parameters.pop("self")
        profile = parameters["sel_profile"]
        weights = dict(profile.scoring_weights)
        if "profile_weighting" in self.disabled:
            weights = dict(resolve_profile("general").scoring_weights)
        for feature, key in WEIGHT_KEYS.items():
            if feature in self.disabled:
                weights[key] = 0.0
        # Do not rescale surviving weights: additive bonuses otherwise acquire
        # different relative strength, changing more than the removed feature.
        parameters["sel_profile"] = replace(profile, scoring_weights=weights)
        parameters["disabled_features"] = self.disabled
        return calculate_candidate_scores_with_profile(**parameters)

    def _select_candidate_indices_with_profile(self, *args, **kwargs):
        return select_candidate_indices_with_profile(*args, **kwargs, redundancy_filtering="redundancy_filtering" not in self.disabled)

    def _sentences_are_redundant(self, left, right, threshold=.62):
        if "redundancy_filtering" in self.disabled:
            return False
        return super()._sentences_are_redundant(left, right, threshold)

    def _compression_protection_enabled(self, feature):
        return feature not in self.disabled
