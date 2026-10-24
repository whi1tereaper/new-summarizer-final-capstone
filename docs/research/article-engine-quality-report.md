# Article engine algorithm-upgrade quality report

This document preserves the **v2** audit. The current runtime repair, v2.1 refinement and release decision are in [article-engine-release-readiness.md](article-engine-release-readiness.md). The earlier tables below are not current-engine deployment measurements.

22 October 2026. Candidate: `recipe-article-2.0`. Decision: **experimental, not approved for automatic routing**. The candidate improves detail recall under equal ceilings, but equivalent-length superiority, short-summary main ideas, Focus completeness and deployment headroom do not pass the acceptance gate. No weights were changed after examining held-out results.

## A. Root cause analysis

The prior report's 28/37 article-unit hits versus 31/37 legacy hits was a quality failure, not contradicted by execution tests. The original report and outputs remain in [article-engine-results.json](article-engine-results.json). The v1 Python implementation was frozen before changing the selector.

[article-v1-root-causes.json](article-v1-root-causes.json) contains ten complete traces and 23 missed unit/fact checks. Its labels describe observed ranking, final budget feasibility, context/layout exclusion and sentence caps; they are not a proof that one particular weight alone caused each omission.

| Historical source | Missed checks | Evidence and mechanism |
|---|---:|---|
| Commuting research | 3 | Sample sentence with 1,186 responses ranked last; 14-firm method sentence ranked next-to-last. Both exist after extraction; budget and ratio sentence cap excluded them. |
| Bridge news | 2 | September 29 and three-week details remained outside the available budget. All annotated main units were already retained. |
| Queue architecture | 5 | 38-percent load, 214 duplicates and at-least-once delivery were present but lost to scalar ranking, budget and sentence cap. |
| Homework essay | 2 | Age-specific recommendation was reference-led across a paragraph; thirty-minute detail also lost under caps. |
| Ambiguous trial | 2 | Final “remains unclear” qualification was present but not selected within the budget. |
| NASA CRS-35 | 4 | Four-investigation overview rejected for context/caps; biomarker detail lost at the sentence cap. One annotated unit requires multiple source sentences. |
| NASA Thailand floods | 5 | 270,000-hectare detail lost under budget; image-specific date details also had layout exclusions. Location annotation spans multiple sentences. |
| Educational, business, feature fixtures | 0 | No annotated omission checks; these three do not supply detail-recall evidence. |

Extraction and ranking normalization did not remove the missing numbers. Selected v1 sentences remained source-exact. LSA projection norm supplied sentence salience but not collective topic coverage. Single scalar factual bonuses did not represent distinct information units. Cosine filtering could suppress related evidence, but the traced failures chiefly show low rank, limits and contextual exclusions; no unsupported claim is made that every omission was a duplicate-filter error. Source-order restoration and deterministic formatting were not the primary loss point. The guide comparator's cleaning can change source strings, which is measured separately rather than attributed to article v1.

## B. Algorithm improvements

The candidate keeps the guide-based TF-IDF/LSA foundation and adds weighted, diminishing-return concept coverage, strict word-aware context bundles, source-exact validation and Focus-conditioned concepts. It retains short complete qualifications, recognizes generic headings separately from the frozen detector, and preserves multiline offsets. Context costs cannot silently exceed the user's budget. The redundant source-ratio sentence cap was removed; explicit comparison budgets override the implicit ratio up to the existing depth maximum. Ordinary application requests still use the existing ratio/depth limits.

Residual MMR is implemented and tested, but development ties select `lambda=1`, so its extra similarity calculation is disabled. The “hybrid” name identifies the implemented combination; the selected `rank_mix=0` and `lambda=1` make the final selector equivalent to coverage-only selection. No claim is made that extra components improved results just because they were implemented.

The shared optional local spaCy model supplies lemmas, noun phrases and entities; lightweight mode avoids that optional model. No paid API, generative summarization, new model download, dependency, frontend or database migration was introduced. See [research provenance](algorithm-improvement-methodology.md) for the distinction between Bulaclac's guide and subsequent independently verified coverage/MMR research.

## C. Actual file changes and traceability

Paths below are relative to the repository. This manifest describes this algorithm-upgrade task only, not pre-existing uncommitted UI, password, cleanup, routing or manuscript work.

Modified existing files:

- `python-engine/article_engine/__init__.py` — candidate version.
- `python-engine/article_engine/ranking.py` — compatible facade and preserved analysis-mode names.
- `python-engine/article_engine/summarizer.py` — coverage orchestration, strict budgets, source guard, optional profiling and existing response contract.
- `docs/research/experimental-results.md` — new comparison, historical v1 results preserved.

New production files:

- `python-engine/article_engine/coverage.py` — exact segmentation, TF-IDF/LSA and normalized concept matrix.
- `python-engine/article_engine/selection.py` — Focus, marginal-gain selection and optional MMR.
- `python-engine/article_engine/validation.py` — context dependencies and source-span enforcement.
- `python-engine/article_engine/coverage_parameters.json` — all 54 development trials and frozen parameters.

New evaluation and regression files:

- `python-engine/evaluation/article_v1_snapshot/__init__.py`
- `python-engine/evaluation/article_v1_snapshot/baseline.py`
- `python-engine/evaluation/article_v1_snapshot/preprocessing.py`
- `python-engine/evaluation/article_v1_snapshot/ranking.py`
- `python-engine/evaluation/article_v1_snapshot/summarizer.py`
- `python-engine/evaluation/coverage_diagnosis.py`
- `python-engine/evaluation/coverage_corpus.py`
- `python-engine/evaluation/coverage_experiment.py`
- `python-engine/evaluation/coverage_analysis.py`
- `python-engine/evaluation/coverage_performance.py`
- `python-engine/tests/test_article_coverage.py`

New research documents and generated evidence:

- `docs/research/algorithm-improvement-methodology.md`
- `docs/research/coverage-aware-extractive-summarization.md`
- `docs/research/mathematical-formulation.md`
- `docs/research/ablation-study.md`
- `docs/research/article-engine-quality-report.md`
- `docs/research/article-v1-root-causes.json`
- `docs/research/coverage-results.json`
- `docs/research/coverage-analysis.json`
- `docs/research/coverage-performance.json`

Before/after SHA-256 checks confirmed twelve protected detector/router/legacy/PHP/configuration files remained byte-identical during this task. That includes `article_detector.py`, `summary_engine_router.py`, `local_cli.py`, legacy `pipeline.py`, `models.py`, `cleaning.py`, both PHP services, application config, `.env.example`, `Dockerfile` and `render.yaml`. Earlier uncommitted changes to these files are not claimed as part of this upgrade. Existing article preprocessing, guide baseline and detector calibration were not edited.

## D. Mathematical methodology

The exact implemented formulation, not merely a proposal, is in [mathematical-formulation.md](mathematical-formulation.md). `coverage.py:coverage_value` computes `F(S) = sum_k w_k max_{i in S} a_ik`, with `0 <= a_ik <= 1`, nonnegative normalized weights, and zero empty-set coverage. `selection.py:select` maximizes incremental coverage per newly added word cost, charging complete context bundles. For fixed weights this F is monotone and submodular; no approximation guarantee is claimed for the full constrained heuristic.

The optional rule is `[lambda * gain/gain_max - (1-lambda) * residual_cosine] / cost^p`; new protected facts suppress residual similarity. Focus weights are `w'_k proportional to w_k max_i(a_ik q_i)`. The selected parameters are lexical .25, latent .15, section .05, role .10, quantity .30, entity .05, qualification .10, `p=1`, `lambda=1`, `rank_mix=0`. Nonempty groups are renormalized. The 50/50 lexical/latent Focus mixture and claim-role patterns are disclosed engineering choices, not independently calibrated probabilities.

## E. Comparative evaluation

The comparison executed 13 configurations times 108 cases = **1,404 outputs with zero execution failures**. Nine development articles precede six fresh synthetic held-out articles; ten older sources are separately labeled historical. Each system receives six depths on each evaluation article, plus supported/unsupported Focus probes for the fresh six. Parameters were selected using 27 development outputs only. The nine-source development result is 103/132 detail-unit checks and 16/27 main-idea checks, not a held-out performance estimate.

The tables use identical requested word ceilings, not identical realized lengths. Every system's native sentences and metrics remain in [coverage-results.json](coverage-results.json), alongside the common whole-sentence prefix-capped metrics. Held-out legacy, v1 and hybrid native totals equal the table totals; guide-native detail recall is 146/252 versus the capped 141/252. Comparators were not modified to improve their length allocation.

| Held-out general (36 outputs) | Units /252 | Main /36 | Fact strings /174 | Exact source sentences | Context errors | Mean words |
|---|---:|---:|---:|---:|---:|---:|
| Legacy LIGHT | 110 | 20 | 89 | 155/160 | 0 | 71.4 |
| Guide Sumy LSA | 141 | 23 | 94 | 225/259 | 12 | 113.2 |
| Frozen article v1 | 125 | 21 | 118 | 165/165 | 0 | 70.4 |
| Improved TF-IDF + LSA ranking | 157 | 15 | 113 | 272/272 | 0 | 106.2 |
| Coverage only | 173 | 13 | 141 | 269/269 | 0 | 108.9 |
| Coverage + MMR .95 | 173 | 13 | 141 | 269/269 | 0 | 108.9 |
| Final hybrid, lightweight | 173 | 13 | 141 | 269/269 | 0 | 108.9 |
| Final hybrid, enhanced default | 173 | 19 | 142 | 268/268 | 0 | 110.1 |

Detail recall rises from 49.6% v1 and 43.7% legacy to 68.7% hybrid, on this small synthetic ceiling-controlled test only. It is not a general factual-accuracy figure. The candidate uses roughly half again as many words. In the smaller-realized-length prefix sensitivity, lightweight hybrid vs legacy is 113 vs 110 unit hits; enhanced hybrid vs legacy is 107 vs 110. Both hybrids fall below v1 in the paired sensitivity. Length-neutral superiority is therefore unverified. The adapter can itself break context and must not be used in production.

### Per-article before/after

Unit hits are summed over six depths, with the same denominator for each system. Main-idea hits appear separately because detail improvements can hide main-message loss. The three unannotated historical sources have no recall denominator and are omitted from this table, not scored as perfect.

| Article | Units denominator | Legacy | Article v1 | Lightweight hybrid | Enhanced hybrid | Main hits: legacy / v1 / light / enhanced |
|---|---:|---:|---:|---:|---:|---|
| CE-HO-NEWS | 42 | 19 | 22 | 29 | 32 | 6 / 6 / 2 / 4 |
| CE-HO-RESEARCH | 48 | 23 | 25 | 33 | 31 | 1 / 2 / 2 / 3 |
| CE-HO-TECH | 42 | 23 | 22 | 31 | 31 | 5 / 1 / 2 / 2 |
| CE-HO-OPINION | 36 | 8 | 16 | 21 | 20 | 0 / 6 / 3 / 3 |
| CE-HO-SCIENCE | 42 | 17 | 18 | 28 | 26 | 4 / 4 / 2 / 3 |
| CE-HO-BUSINESS | 42 | 20 | 22 | 31 | 33 | 4 / 2 / 2 / 4 |
| Historical commute | 30 | 17 | 17 | 22 | 20 | 6 / 6 / 5 / 5 |
| Historical bridge | 30 | 13 | 15 | 19 | 19 | 6 / 6 / 3 / 3 |
| Historical queue | 36 | 12 | 17 | 24 | 23 | 0 / 0 / 0 / 0 |
| Historical homework | 24 | 18 | 12 | 11 | 9 | 5 / 3 / 1 / 1 |
| Historical ambiguous trial | 30 | 16 | 14 | 20 | 19 | 3 / 6 / 2 / 2 |
| Historical NASA CRS-35 | 36 | 9 | 14 | 11 | 12 | 0 / 5 / 3 / 3 |
| Historical NASA floods | 36 | 12 | 13 | 16 | 15 | 3 / 5 / 2 / 2 |

Historical aggregate detail counts are legacy 97/222, v1 102/222, lightweight hybrid 123/222 and enhanced hybrid 117/222. This does not hide regressions in the essay and CRS-35 examples. Some historical units cannot match any single source sentence; the denominator is preserved and attainability recorded. Historical main-idea counts over all 60 outputs include three unannotated articles and must not be interpreted as 60 independently scored main ideas.

### Depth, precision, factuality and coherence

| Depth | Legacy units /42 | v1 units /42 | Light units /42 | Enhanced units /42 | Light main /6 | Enhanced main /6 |
|---|---:|---:|---:|---:|---:|---:|
| very_brief | 4 | 12 | 12 | 11 | 0 | 0 |
| brief | 9 | 15 | 20 | 19 | 0 | 0 |
| short | 14 | 17 | 26 | 26 | 0 | 2 |
| balanced | 20 | 21 | 32 | 34 | 1 | 5 |
| detailed | 23 | 27 | 41 | 41 | 6 | 6 |
| comprehensive | 40 | 33 | 42 | 42 | 6 | 6 |

The cost-normalized selector favors short factual evidence over the longer central statement. This is a release-blocking error, particularly at the two shortest depths. Comprehensive recall on these short synthetic articles often reflects nearly complete extraction, not exceptional summarization. Nested depth selection is not guaranteed.

Macro annotated-sentence precision is .7125 legacy, .7775 v1, .6859 lightweight and .6725 enhanced. This counts a sentence as a hit when it matches at least one incomplete literal annotation; unannotated useful content can be scored as a miss. It is not human important-information precision. Mean compression (fraction omitted) is .6153 legacy, .6200 v1, .4121 lightweight and .4049 enhanced.

All candidate general sentences are exact source excerpts, with no detected required-context errors. This protects negation, quantities and clauses in selected sentences, not omitted information, truth, entailment, or correct antecedents in every case. Source order and heuristic dependencies are coherence protections, not a human coherence score. Exact and TF-IDF near-duplicate counts at a declared .85 threshold are zero for every system on this set; semantic redundancy remains unmeasured. No independent reference summaries exist, so ROUGE and human factuality/coherence/readability ratings were not fabricated.

### Focus

The six supported probes contain 13 annotated focus units: legacy retains 4, v1 retains 3, either hybrid retains 6, and the guide comparator retains 9 while ignoring the query. The guide's score is consequently not evidence of query awareness. Hybrid relevance signals appear in 7/15 selected sentences; dependency context can legitimately lower this proxy, but unrelated retrieval is not ruled out. All six absent “volcanic seismology on Jupiter” queries are rejected by both article versions. Legacy's unsupported behavior is not measured by article-specific metadata.

The hybrid incorrectly rejects “environmental threats” and “financial results” despite relevant source passages. Stemming, LSA and generic role retrieval improve some queries but do not supply open-domain synonym understanding. The study-design query retains all three annotated units but selects nine sentences, illustrating overbroad role retrieval. These failures are retained, not used for new keyword exceptions or held-out tuning.

## F. Ablation findings

See [ablation-study.md](ablation-study.md) for every controlled count and sensitivity result. Coverage adds 16/252 unit hits over ranking; TF-IDF and LSA contribute small positive differences on this set. Removing context bundles produces eight detected held-out errors. Section/role concepts have no held-out aggregate recall benefit and are worse on historical unit recall. MMR produces no measured coverage improvement and is disabled in the selected path. Enhanced NLP improves main-idea counts but not aggregate detail recall and costs model-loading time. None of these findings is treated as a general causal quality estimate from six synthetic articles.

## G. Performance

Isolated-process scaling results and stage timings are recorded in [coverage-performance.json](coverage-performance.json). Each size/configuration uses a fresh subprocess and two calls, retaining existing model caches within that process. These are single observations on repetitive synthetic monitoring sentences, not confidence intervals, throughput estimates, or realistic-article quality results. Full-suite work had finished before this benchmark; a PHP subprocess probe overlapped part of it, and no exclusive CPU reservation was enforced.

| Source sentences | Configuration | Import startup (s) | First call (s) | Second call (s) | Sampled peak RSS (MiB) |
|---|---|---:|---:|---:|---:|
| 20 | Lightweight | 6.693 | .262 | .044 | 139.03 |
| 20 | Enhanced | 6.250 | 13.388 | .204 | 391.01 |
| 200 | Lightweight | 4.559 | .404 | .295 | 140.14 |
| 200 | Enhanced | 6.071 | 15.884 | 3.334 | 379.90 |
| 800 | Lightweight | 5.640 | 1.926 | 1.736 | 143.03 |
| 800 | Enhanced | 5.614 | 24.533 | 12.222 | 400.32 |

Import startup is measured separately; a cold process takes startup plus its first-call latency. The call includes detection and article processing, but excludes process creation, PHP/Apache, fetching/extraction and persistence. All cases request 400 words and select twenty source sentences. The 800-sentence source contains 101,297 characters; the production candidate limit is 1,000 sentences, not an unlimited-input guarantee.

Warm 800-sentence stage times (lightweight / enhanced, seconds) are annotation .0002 / 10.6411, vectorization .0572 / .0491, SVD .0226 / .0203, concept construction .2874 / .2764, and selection 1.1372 / .9985. Residual redundancy computation is zero in both because lambda=1. Article-core totals are 1.5926 / 12.0522; the enclosing measured calls are longer because detection and instrumentation are outside that total. Optional NLP dominates the enhanced cost; imports/model loads dominate cold latency. Sparse concept columns and at-most-four SVD dimensions bound representation overhead; the selector still repeatedly scores feasible bundles and is not linear-time for arbitrary budgets.

RSS is sampled every 10 ms, can miss transient peaks, and varies with OS paging. Values labeled `*_mb` in the JSON use 1,048,576 bytes, hence MiB here. No OS/container memory cap is enforced. Approximately 400 MiB for enhanced Python alone leaves unverified headroom for PHP, Apache, extraction libraries, other workers and container overhead. **Compatibility with a 512 MB deployment has not been established.**

## H. Regression testing

The complete Python discovery run executed **662 tests in 1,007.877 seconds: 658 passed, 4 skipped, 0 failures**. It includes all 26 routing tests, detector regression fixtures, existing Focus tests, all analysis modes/depths/formats, generated PDF/DOCX, mocked URL extraction, legacy independence, one-extraction fallback and a real warm-supervisor rollback. The four opt-in HTTP translation acceptance tests are skipped when their environment gate is not enabled; no successful live translation acceptance is claimed.

The 24 new coverage tests also passed independently in 1.733 seconds. They cover the exact coverage formula, bounded concepts, diminishing returns, different quantities in similar sentences, late findings, changed numerical/negation evidence rejection, uncertainty preservation, context cost, multiline offsets, six depths/five formats, Focus support and deterministic non-profiled metadata. An initial multiline-heading failure and a timing-induced repeatability failure were corrected before the final full run. There are no remaining failures in that run. The older 26-test routing baseline passed before this upgrade; final routing coverage is supplied by the full discovery run, not inferred from that baseline.

Five PHP CLI integration probes passed: disabled, enabled, original v2 selection, metadata normalization and a real Python subprocess bridge. Both PHP service syntax checks passed. Git whitespace checking passed, with only existing CRLF-conversion notices. Deliberate retrieval/evidence/worker fault-injection logs occurred in passing tests. No claim is made that a pre-existing intermittent translation timing race was fixed.

No browser-authenticated login/history/admin session, production database write, deployed container, live-site extraction diversity audit or independent human assessment was executed. URL transport is mocked. Database behavior is preserved by avoiding database changes, not certified by an end-to-end transaction test.

## I. Remaining limitations

The dataset is small, predominantly synthetic, authored and annotated by the implementing agent, and not independently reviewed. Repeated depths are not independent samples. Historical examples were already inspected. Literal units, fact strings, first-unit main-idea proxies and checker-defined context errors cannot establish semantic accuracy. Two cross-sentence historical annotations are unattainable under the single-sentence matcher.

Short summaries can omit the actual thesis, valid Focus synonyms can be rejected, role-based Focus can overretrieve, and longer outputs explain much of the recall gain. Numerical relevance and contextual qualification detection are heuristic; dates and numbers are not guaranteed correct semantic attachments. Pronouns, distant caveats, quotations and complicated causality remain unresolved. English tokenization, stopwords and cue patterns are not validated for other languages. Generic heading recognition can still misclassify unusual layouts; punctuation-free fragments are not accepted as complete candidates. More than 1,000 candidate sentences triggers the existing typed failure/fallback path.

The selected zero modular rank mixture means analysis-mode cues do not currently change the default coverage-only selection; mode names/contracts are retained, but differentiated selection quality is not established. This is another limitation before general release, not a reason to alter the legacy implementation. No heavyweight embedding model was added to mask these gaps.

## J. Activation recommendation

**Keep `SUMMARIZER_AUTO_ARTICLE_ROUTING=false`.** The preserved legacy engine remains independently available and the detector, router, rollback and UI are unchanged. Do not enable production routing based on execution success or the 68.7% synthetic detail-recall proxy.

A next research iteration should use development-only central-message and Focus improvements, re-evaluate whether structural features justify their complexity, freeze before testing a new externally sourced corpus, obtain independent must-preserve annotations and human factuality/coherence review, compare realized lengths without unsafe prefix adaptation, and measure full deployment memory/time under enforced limits with headroom. The present improvement is an implemented experimental candidate, not a verified production-quality upgrade.

## Reproduction

From the repository root, using the already configured local virtual environment:

```powershell
$env:PYTHONPATH = 'python-engine'
.\.venv-nlp\Scripts\python.exe -m unittest discover -s python-engine/tests -p 'test_*.py'
php scripts/test_article_routing.php all .\.venv-nlp\Scripts\python.exe
.\.venv-nlp\Scripts\python.exe python-engine/evaluation/coverage_diagnosis.py
.\.venv-nlp\Scripts\python.exe python-engine/evaluation/coverage_experiment.py --systems legacy,guide_lsa,article_v1,improved_ranking,coverage,coverage_mmr,hybrid,hybrid_enhanced,no_tfidf,no_lsa,no_structure,no_coverage,no_factual_context --out docs/research/coverage-results.json
.\.venv-nlp\Scripts\python.exe python-engine/evaluation/coverage_analysis.py
.\.venv-nlp\Scripts\python.exe python-engine/evaluation/coverage_performance.py
```

The retained parameter artifact is authoritative. `coverage_experiment.py --tune` reproduces the development-only search but overwrites that artifact; do not retune after looking at held-out outputs. Results retain all inputs, source hashes, dependency versions and settings. Timing/RSS varies by machine and cache state. None of these commands is a deployment or routing enablement instruction.
