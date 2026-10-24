# Coverage-aware article engine: improvement methodology

## Runtime repair and v2.1 refinement addendum

The current iteration first reproduced a real PDF request-path failure: Apache accepted the job in MySQL while its CLI worker selected PostgreSQL and failed in job lookup. The worker now retains the accepting backend, and private correlated stage records identify failures without logging source prose or credentials. The matching large PDF also exceeded the old 180-second local budget; bounded 300-second local configuration and real successful retests are documented in [the release-readiness report](article-engine-release-readiness.md). These runtime changes do not alter legacy ranking algorithms.

After runtime verification, the v2 article package was frozen byte-for-byte under `evaluation/article_v2_snapshot/`; all eight Python hashes match the v2 experiment. Twelve development articles (nine existing plus three adversarial examples with late theses, opposition-first openings and methods-first reporting) selected a central-message seed before cost-normalized coverage. Six core policies crossed with two MMR settings were evaluated at all six native UI depths. Development favors title .6, centroid .2, explicit claim cue .2 and positional weight zero. This is not a first-sentence-only policy or a fixed budget-percentage reservation.

Residual MMR .95 adds six development unit checks compared with the same core policy at lambda=1. It is retained as an experimental development-selected setting; all 90 subsequent known-source diagnostic outputs are identical without MMR, so broader utility is unverified. WordNet supplies bounded local query expansion with semantic-anchor and modifier support checks. Generic shared ancestors are not enough to accept a topic. Procedural predicates replace document-wide words such as “study” as the predefined design-scope retrieval signal. Existing span validation and immediate correction/caveat dependencies remain mandatory.

The additional centrality motivation is [Radev, Jing and Budzikowska](https://aclanthology.org/W00-0403v2.pdf); query expansion uses documented [WordNet relations](https://wordnet.princeton.edu/documentation/wngloss7wn). These are separate from the uploaded guide and do not establish the optimality of LIGHT's coefficients or WordNet sense/depth caps.

The new comparison regenerates outputs near actual legacy lengths, choosing by length/contract validity only, never by annotation score. It retains unmatched cases and infeasible budget trials. All 13 scored sources have already been inspected and are explicitly diagnostic, not newly held out. Independent evaluation is pending under [the external protocol](external-evaluation-protocol.md). Historical v2 methodology follows, preserved for reproducibility.

This upgrade targets the dedicated article engine only. The original summarizer, detector, router, PHP integration, database path, frontend, and disabled routing state are retained. The repository exposes **six** depths, including `very_brief`; all six are evaluated despite the request's reference to five.

## Evidence and diagnosis

The historical comparison and every omission were inspected before editing the candidate. `evaluation/coverage_diagnosis.py` and `article-v1-root-causes.json` retain ten article traces and 23 missed unit/fact checks. The old engine is frozen in `evaluation/article_v1_snapshot/` and remains executable as a research comparator.

The sample-size sentence containing 1,186 responses and the technical article's 38% database-read figure were present after extraction. They lost to higher scalar-ranked sentences without a reward for new concepts. Other omissions were caused by the combination of a source-ratio sentence cap and word budget, or by rejecting context-dependent conclusions across paragraph boundaries. The engine preserved the text of selected sentences, so these were predominantly selection/coverage failures, not generative factual alterations. Several annotations require words from multiple source sentences; those remain visible in the denominator and are identified separately as single-sentence annotation limits.

Normalization was ranking-only. The original LSA row norm measured projection strength, not whether multiple latent themes were collectively covered. A single numeric/qualifier bonus did not distinguish two different facts from the same study. Pairwise cosine could reject related evidence, while sentence selection could stop before using a fair information budget. Custom headings and short complete statements also needed more conservative handling.

## Research foundation

The user-supplied Bulaclac guide was reread, including Chapter 2, Recipes 3-3/3-4/4-1/4-2/4-4, and PDF pages 159–162. Its tokenization, TF-IDF, noun-phrase/entity analysis, cosine similarity, and LSA appendix supply the methodological foundation. The guide's Sumy implementation remains an identifiable comparator. The source identity and SHA-256 are recorded in [recipe-based-article-engine.md](recipe-based-article-engine.md).

The added coverage objective and selection policy draw on separately verified original research:

- Gillick, D., and Favre, B. (2009). [A Scalable Global Model for Summarization](https://aclanthology.org/W09-1802/), pp. 10–18. Models information at concept level and optimizes coverage. LIGHT uses greedy selection, not their exact ILP.
- Lin, H., and Bilmes, J. (2011). [A Class of Submodular Functions for Document Summarization](https://aclanthology.org/P11-1052/), pp. 510–520. Motivates diminishing-return coverage and diversity. Its published evaluation results are not transferred to LIGHT.
- Khuller, S., Moss, A., and Naor, J. (1999). [The Budgeted Maximum Coverage Problem](https://doi.org/10.1016/S0020-0190(99)00031-9), *Information Processing Letters*, 70(1), 39–45. Provides the budgeted-coverage foundation. LIGHT does not claim their approximation guarantee.
- Goldstein, J., and Carbonell, J. (1998). [Summarization: (1) Using MMR for Diversity-Based Reranking and (2) Evaluating Summaries](https://aclanthology.org/X98-1025/). Supports the separate query-relevance/novelty comparison.
- McDonald, R. (2007). [A Study of Global Inference Algorithms in Multi-Document Summarization](https://research.google/pubs/a-study-of-global-inference-algorithms-in-multi-document-summarization/), ECIR. Establishes the importance of evaluating selection jointly under a budget rather than relying only on sentence ranks.

These papers study related problems and different datasets. The present heuristic definitions and measured coefficients belong to this implementation; they are not claimed to be formulas copied from the academic guide or universally validated constants.

## Experimental design

Development consists of nine engineering-authored articles: five diagnosed historical examples and four existing detector articles with explicit development annotations. Six fresh synthetic articles and annotations were written before parameter search and reserved for held-out comparison. The older ten-article benchmark is separately labeled historical because its outputs were already inspected. Two historical inputs are archived NASA articles; no synthetic text is presented as authentic news or independently reviewed research.

Search the documented group weights, word-cost exponents, MMR lambdas, and rank mixtures on development only. Select first for zero context errors, then detail recall, main-idea proxy, and shorter output on ties; prefer simpler scoring on remaining ties. Freeze `article_engine/coverage_parameters.json` before examining held-out results. All source inputs, hashes, parameters, native outputs, capped outputs, and automatic metrics are preserved in `coverage-results.json`.

All systems receive the same source snapshots, depth names, Focus probes, and word ceilings. Comparators can exceed their requested budget, so a common source-order prefix cap is applied in evaluation to every system. Both native and capped results are retained. The capped comparison is an artificial length-control experiment, not a claim that the legacy application normally uses that adapter. Main-idea preservation, detail coverage and annotated precision are literal proxies; no human truth judgments are invented.

ROUGE is not computed without independent reference summaries. Human factuality/coherence ratings, participant studies, live external-site reliability, and memory-limited production deployment remain unverified. The [quality report](article-engine-quality-report.md) gives the actual release recommendation.
