# Experimental results and release decision

## External v2.1 validation pilot: routing still disabled

The [external validation report](article-engine-independent-validation.md) adds eight authentic, newly acquired articles, all six depths, 288 system/case records and 1,220 saved generation attempts. Only nine of 48 general cases per article configuration meet the registered realized-length tolerance. Source-first agent drafts and exact-evidence counts are **not independently reviewed semantic accuracy**; independent human review is pending. New near-topic Focus, context and fragmentation concerns remain. Enforced Apache/PHP/Python container tests are reported separately from historical RSS observations, with production-path limitations and host interruptions disclosed. **Superiority and full deployment readiness are not established; keep automatic routing disabled.** Historical results below are preserved, not substituted for new held-out evidence.

## Current refinement: recipe-article-2.1

The runtime repair and refinement are documented in [article-engine-release-readiness.md](article-engine-release-readiness.md). **Automatic routing remains disabled.** The matching 162-page PDF completed through actual XAMPP in 252.13 seconds after correcting the worker database handoff and the local timeout; a subsequent run completed in 237.98 seconds. Its Python process high-water mark was 693.89 MiB, so this is not a 512 MB capacity claim.

The [new comparison](refinement-results.json) uses actual UI sentence limits and native depth budgets, then regenerates whole summaries near legacy's realized lengths without prefix truncation or padding. All 13 annotated sources were previously inspected; these are diagnostic results, not a fresh holdout. There are 810 system/case records and 3,670 generation attempts. No native or selected regenerated case failed; 36 tight-budget search trials were infeasible and are retained as typed failures rather than hidden.

| Regenerated general summaries (78 per system) | Legacy | Guide LSA | Article v1 | Article v2 light | Refined light | Refined enhanced |
|---|---:|---:|---:|---:|---:|---:|
| Main-idea proxy /78 | 46 | 39 | 51 | 20 | 61 | 57 |
| Detail-unit checks /474 | 221 | 166 | 216 | 237 | 235 | 239 |
| Total words | 8,144 | 7,588 | 6,695 | 7,292 | 7,431 | 7,308 |
| Cases within registered length tolerance /78 | 78 | 56 | 42 | 60 | 64 | 65 |

Not every whole-sentence output can match length. Within the 64 genuinely matched lightweight pairs, refined/legacy main-idea hits are 48/37, detail hits 192/180 out of 395 checks, and words 5,454/5,520. The enhanced subset has 65 pairs: main 45/36, details 194/180 out of 400, words 5,486/5,570. These paired subsets have different denominators and are not general accuracy estimates. Main ideas still fail on the business example; essay details and some enhanced outcomes regress.

Native Focus recall is 7/13 lightweight and 8/13 enhanced versus 5/13 v2 under these actual depth budgets. All six supported topics are now accepted, and all six deliberately absent topics rejected. Relevance signals remain only 8/18 and 9/18 selected sentences; the opinion case regresses in lightweight mode. Focus quality is not independently established. Development selected residual MMR .95 with six additional unit checks, but all 90 diagnostic outputs are identical with MMR disabled; no general diversity benefit is claimed.

Current verification: full Python discovery 678 run, 674 passed, four skipped; 11/11 real XAMPP HTTP completion cases; authenticated browser login, terms, persistence, access and explicit-sharing checks; PHP integration and lifecycle probes. Current resource tests are in [refinement-performance.json](refinement-performance.json), not inferred from the older v2 benchmark. Independent external sources, labels and review remain [pending under the prepared protocol](external-evaluation-protocol.md).

## Previous coverage-aware candidate v2

The research-based upgrade is implemented as `recipe-article-2.0`. Automatic article routing **remains disabled**. See the complete [quality report](article-engine-quality-report.md), [methodology](algorithm-improvement-methodology.md), [ablation study](ablation-study.md), and reproducible [results](coverage-results.json).

The frozen comparison executed 1,404 cases across 13 configurations, with no execution failures. There are nine development articles, six newly reserved synthetic held-out articles, and ten historical sources. The historical sources are not held out for this upgrade; three have no detail-unit annotations and cannot support detail-recall conclusions. All six actual application depths were retained and evaluated.

| Held-out general summaries (36 per system) | Legacy | Guide LSA | Article v1 | Lightweight hybrid | Enhanced hybrid |
|---|---:|---:|---:|---:|---:|
| Detail-unit checks /252 | 110 | 141 | 125 | 173 | 173 |
| Main-idea proxy /36 | 20 | 23 | 21 | 13 | 19 |
| Fact-string checks /174 | 89 | 94 | 118 | 141 | 142 |
| Exact source sentences | 155/160 | 225/259 | 165/165 | 269/269 | 268/268 |
| Context dependency errors | 0 | 12 | 0 | 0 | 0 |
| Total words | 2,571 | 4,075 | 2,536 | 3,919 | 3,965 |

The candidate improves annotated recall under identical word ceilings, but uses more words. When both systems are prefix-capped to the smaller realized output length, lightweight hybrid has 113 hits vs legacy's 110, while enhanced hybrid has 107 vs legacy's 110. This adapter is imperfect and is not applied in the application. The evidence does not establish equivalent-length superiority. Main-idea loss is particularly severe at short depths. Enhanced NLP is the existing default when its local model is available; lightweight numbers must not be presented as all production behavior.

Supported Focus probes retain 6/13 annotated focus units for either hybrid vs 4/13 legacy and 3/13 article v1. Two valid custom topics are incorrectly rejected, and focus-relevance signals occur in only 7/15 selected candidate sentences. Six deliberately absent topics are all rejected. These limited proxies do not establish satisfactory Focus quality.

Development and held-out MMR comparisons show no coverage gain. The selected candidate uses lambda=1, avoiding redundant similarity work. Exact spans and conservative context bundles prevent the tested numerical/negation edits, but cannot certify semantic truth or completeness. No independent human review or reference summaries exist; no ROUGE or human accuracy figures are reported.

## Historical v1 experiment (preserved)

Recorded 22 October 2026, Windows, Python 3.11.9. The machine-readable [experiment report](article-engine-results.json) retains all ten sources and thirty outputs, including exact settings, timings, memory observations, dependency versions, failures, and empty human-review fields.

**Implementation is available for opt-in testing. Global automatic routing remains disabled because the quality gate has not passed.**

## Detection

The final development-calibrated threshold is 0.70. The fixed 17-example regression subset produced 8 true-positive article decisions and 9 true-negative non-article/uncertain decisions, with no observed errors. Development format augmentation fixed a case where the existing PDF extractor flattened paragraph boundaries. These are engineering fixtures, and the sample was inspected during development; the observed counts do not justify a general accuracy percentage.

## Three-system comparison

| Measurement | Original LIGHT | Guide Sumy LSA | Recipe article |
|---|---:|---:|---:|
| Executed inputs | 10 | 10 | 10 |
| Execution failures | 0 | 0 | 0 |
| Annotated key units retained | 31/37 | 32/37 | 28/37 |
| Literal fact strings retained | 42/44 | 40/44 | 30/44 |
| Mean summary words | 228.3 | 235.3 | 181.8 |
| Mean measured request seconds | 4.0960 | 0.1542 | 0.8301 |
| First request seconds (includes lazy model loads) | 28.5967 | 1.2428 | 7.3675 |
| Mean subsequent request seconds | 1.3737 | 0.0333 | 0.1037 |
| Process peak working set, MB | 737.78 | 150.14 | 394.16 |
| Exact source-matching sentences | 120/121 | 99/121 | 97/97 |
| Mean duplicate/near-duplicate rate | 0 | 0 | 0 |

The article candidate is shorter on average. It executes successfully and preserves exact selected evidence, but it retains fewer annotated details than both comparators. This is **not a demonstrated quality improvement**. The timing difference also includes different lazy loading and simultaneous local regression work. Full per-case memory and cold/warm observations are in the report rather than generalized into production claims.

Examples: the candidate retained all five annotated bridge-news units, but retained four of six units in both the queue-architecture example and the CRS-35 article. These are lexical annotation checks, not reviewer factuality judgments. Good source membership cannot establish that omitted context was harmless. The guide baseline can also edit meaningful strings through its appendix's broad cleaning substitutions.

## Verification evidence

The targeted routing suite covers calibrated decisions, non-articles, feature-flag rollback, uncertain inputs, missing modules, malformed result fallback, one-extraction fallback, source spans, all modes/depths/formats, Focus, optional-resource fallbacks, repeatability, generated PDF, DOCX, and mocked URL content. PHP probes exercise flag loading, preserved legacy/v2 choice when disabled, metadata normalization, and a real subprocess JSON round trip.

Executed verification:

- Pre-change pipeline architecture baseline: 6 passed.
- Full discovery snapshot: 635 run, 631 passed, 4 skipped, 0 failures (263.363 seconds). This snapshot predates the extra malformed-contract and real-supervisor tests added during final review.
- Updated targeted routing suite: 25 passed in 79.932 seconds, including a real spawned warm supervisor that switched from article routing to the original engine on its next request. The subsequently added non-article equivalence test and expanded PDF routing test also passed (2 tests, 37.993 seconds). There are 26 distinct routing tests in the final file; these overlapping runs exercised all of them.
- PHP integration probes: 5/5 passed, including a real subprocess bridge.
- Expanded research/resume DOCX and partial-topic Focus checks passed in follow-up runs.
- PHP syntax: 4 modified/new PHP files passed. New Python modules parsed successfully. Git whitespace check passed.

The full-suite fault-injection logs include deliberate retrieval/evidence errors; those tests passed. The previously observed intermittent Windows translation cooldown failure did not recur in this run. This does not establish that the race was fixed.

No browser-authenticated login/history/admin scenario or database write was executed. Their controller/transaction path is reused, but source inspection is not a successful end-to-end test. URL transport is mocked in the format test; a diverse live-site extraction audit remains outstanding. No independent human factuality, coherence, or readability review was conducted.

## Remaining work before global enablement

Use a new external corpus with reviewer-defined must-preserve units; match realized lengths; examine attribution, uncertainty, and context omissions; verify live XAMPP/deployed flows; and re-evaluate false positives on long operational documents. Tune only with a development partition, then freeze before independent testing. The candidate, original engine, and appendix comparator remain independently callable for that study.
