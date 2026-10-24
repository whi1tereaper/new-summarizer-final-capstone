# Independent external-article evaluation protocol

Status: prepared, **independent annotations and human evaluation pending**. This is a release gate, not a completed experiment. The previously inspected synthetic and historical sources in `refinement-results.json` are diagnostic examples only. Their repeated depths do not become a fresh holdout when the engine version changes.

## Corpus, roles and freeze

Recruit two independent annotators and an adjudicator who did not implement the selector. Obtain 30 legally usable, full-length external articles: five each of news, research reporting, technical explanation, opinion/argument, educational explanation and business/economic analysis. Stratify by genre, source length and source publisher; group related articles about the same event into one partition. Include sparse numbers, negative findings, late conclusions, disputed claims and non-news leads. Do not select sources based on whether the candidate performs well.

Assign 12 development articles, six calibration articles and 12 held-out articles (two per genre in the holdout). Development alone may change algorithm coefficients or query heuristics. Calibration checks evaluator length-realization procedures, review instructions and annotation agreement; it must not become another weight-tuning split. Lock the held-out source snapshots, evidence units, reference dependencies and query-support judgments before examining system outputs. Keep a dated manifest with source hashes, extraction hashes, publisher, author, publication/retrieval dates, licensing and partition identity.

The six verified links in [external-source-candidates.json](external-source-candidates.json) are **nominations for the development pool**, not a completed corpus, representative sample, or unseen holdout. They cover agency science, technical/news reporting and energy/business analysis; independent opinion and educational sources remain to be selected. No article text or independent labels are invented in that manifest. [external-review-workbook.csv](external-review-workbook.csv) contains blank annotation slots, not zero-valued scores.

For the present refinement, twelve engineering-authored development examples selected the core policy. The versioned parameter artifact is `python-engine/article_engine/refinement_parameters.json`. All previously inspected sources remain excluded from claims of independent held-out validity. Further external development must receive another version/freeze rather than silently retrofitting this experiment.

## Source-first annotation

Reviewers first read the source without any engine output. Identify the subject and central proposition, stance, essential conclusions, independent important-detail units, quantity/entity relationships, attribution, negation and uncertainty. Do not define the main idea as “whatever the first sentence says.” Record one or more source sentence IDs/spans for every unit and any indispensable contextual predecessor, qualification or correction. Units can be supported across multiple sentences; do not repeat the old literal matcher's single-sentence attainability mistake.

For each source define at least one supported custom Focus query, one applicable predefined category, a partially supported query and an absent topic. Label support before seeing summaries. Avoid only testing an easily rejected unrelated phrase. Distinguish an unsupported topic from a narrower scope whose evidence is genuinely limited. Disagreements are adjudicated with retained original annotations, not resolved by the implementer in favor of a system.

## Five-system and depth comparison

Compare original LIGHT, the guide-based Sumy LSA baseline, frozen article v1, frozen article v2 and the refined engine. Record lightweight/enhanced configuration and model-resource availability separately. Retain every native result at all six actual UI depths and the PHP sentence-count mapping. Keep the default backend independent of the experimental route.

Generate additional **valid whole summaries** with each engine's own supported sentence/word budget controls. The current diagnostic procedure targets legacy's realized native word count, searches a fixed bounded set of budgets without seeing annotations and chooses the nearest generated length. Sumy uses its sentence-count control. Never prefix-truncate, cut clauses, rewrite, or pad outputs to force a match. Report every trial, the selected budget, actual words, failures and the absolute length difference. Pre-register a tolerance of `max(5 words, 10% of target)` as an engineering comparison rule, not a scientifically privileged threshold; report 5% and 20% sensitivity separately.

Report all native cases and all regenerated cases, plus the genuinely within-tolerance paired subset and its denominator. Do not hide unmatched cases or unsuccessful generations. Reviewers must see only masked system labels, source evidence and outputs. Use deterministic randomized labels/order and keep the decoding key separate. Capture review duration and disagreements. Do not show the implementer's proxy scores to reviewers first.

## Metrics and analysis

Review main-idea preservation, unit recall and precision, factual relationships, Focus evidence coverage/relevance, context-dependent misleadingness, coherence and semantic redundancy. Record exact-source membership as provenance only. Document critical errors separately from averaged scores; exact quotations may still be misleading. If independent reference summaries are written, ROUGE may supplement review but is not a factuality score.

Aggregate and bootstrap by **article**, keeping six depths for the same article together. Present per-genre and per-depth outcomes, uncertainty and every critical failure. Do not treat individual words, units or repeated depths as independent subjects. A small proxy improvement alone cannot establish non-inferiority to legacy. Runtime/memory tests must include cold startup, warm requests, Focus, long inputs and shared PHP/server overhead. Use an enforced deployment memory cap with headroom, not historical standalone RSS as proof of production capacity.

## Activation gate

Remain disabled until representative real request-path checks pass; no unresolved critical factual/context failures remain; independent, realized-length-controlled main-idea/detail results support at least comparable quality; Focus false acceptances/rejections are acceptable under the registered rubric; and the actual deployment passes resource limits with headroom. Document failures and pending work instead of filling blank human scores with automated estimates. The local 162-page PDF repair is runtime evidence, not article-summary accuracy or 512 MB capacity evidence.

## Research provenance for the refinement

The Bulaclac guide remains the TF-IDF/LSA and local NLP foundation described in the existing methodology. Centrality and positional features are informed by [Radev, Jing and Budzikowska's centroid-based summarization research](https://aclanthology.org/W00-0403v2.pdf). The implemented two-stage seed is a LIGHT heuristic, not an implementation of MEAD or a guarantee from that paper. WordNet's documented [relational adjectives and semantic relations](https://wordnet.princeton.edu/documentation/wngloss7wn) inform the bounded local expansion. Limiting senses/hierarchy depth and requiring a direct semantic anchor are disclosed engineering safeguards, not semantic-entailment probabilities.
