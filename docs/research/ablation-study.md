# Coverage-engine ablation study

## Current v2.1 refinement ablation

The current [frozen comparison](refinement-results.json) uses 13 previously inspected, annotated articles and all six actual UI depths: 78 general outputs per system. It is diagnostic, not a new holdout. The old v2 study below remains historical.

| Current native configuration | Main checks /78 | Unit checks /474 | Words |
|---|---:|---:|---:|
| Coverage-only v2 | 14 | 230 | 6,877 |
| Refined light, core disabled | 14 | 230 | 6,877 |
| Refined light, calibrated core | 61 | 234 | 7,042 |
| Same core, MMR disabled | 61 | 234 | 7,042 |
| Refined enhanced | 57 | 238 | 6,984 |

The core-first stage substantially improves the literal main-idea proxy without a large length increase over v2. At regenerated comparable-length operating points, light detail hits decrease slightly from v2's 237 to 235 while main hits rise from 20 to 61. These are genuine tradeoffs, not proof of semantic superiority. Some essays and business-main-idea cases still fail.

Development evaluated twelve articles at six depths with six core policies and lambdas 1/.95. The selected title/centroid/claim policy yields 59/72 main checks with either lambda; .95 increases unit checks from 169 to 175 out of 318 and reduces words from 4,929 to 4,923. However, all 90 native and all 90 regenerated diagnostic outputs are identical at .95 and 1. The experimental development-selected penalty is not evidence of a general diversity improvement; independent evaluation may justify removing it. No new dense semantic model was introduced.

New Focus logic accepts the two formerly rejected supported topics and preserves all six absent-topic rejections. Under native UI budgets, coverage is 7/13 light and 8/13 enhanced vs v2's 5/13. The opinion query regresses in lightweight mode, and relevance/coverage remain incomplete. The earlier 6/13 v2 result used different explicit budgets and must not be treated as an identical operating-point baseline.

The comparison does **not** prefix-truncate summaries. It retains all regenerated candidates, 36 infeasible tight-budget trials and 14/13 unmatched light/enhanced general cases. The paired within-tolerance subsets are reported separately in the release report so length differences cannot be disguised as quality improvements.

Recorded 22 October 2026. Inputs, parameters, output sentences, timings and native/capped metrics are in [coverage-results.json](coverage-results.json); derived tables and length sensitivity are in [coverage-analysis.json](coverage-analysis.json). These are automated engineering measurements, not independent human judgments.

## Protocol

The nine-article development split selected parameters from 54 combinations, using three depths per article. Six fresh synthetic articles were reserved before search. The table below covers all six depths on those articles: 36 related outputs, 252 repeated article-unit checks, and 174 literal fact checks. The effective independent article count is six, not 36 or 252. No significance claim is made.

Every system has the same depth-specific word ceiling. Realized lengths differ. Comparator output is prefix-capped only if necessary; native output is retained. This is a ceiling-controlled experiment, not a proof of equivalent-length quality. See the length-sensitivity results below.

## Controlled lightweight ablations

| Configuration | Detail units /252 | Main-idea proxy /36 | Fact strings /174 | Context dependency errors | Total words |
|---|---:|---:|---:|---:|---:|
| Frozen hybrid | 173 | 13 | 141 | 0 | 3,919 |
| Term counts instead of TF-IDF | 171 | 12 | 140 | 0 | 3,927 |
| Without LSA | 167 | 12 | 137 | 0 | 3,941 |
| Without section/role concepts | 173 | 13 | 141 | 0 | 3,934 |
| Ranking instead of coverage | 157 | 15 | 113 | 0 | 3,823 |
| Without context bundles | 169 | 13 | 141 | 8 | 3,907 |
| Coverage + residual MMR, lambda=.95 | 173 | 13 | 141 | 0 | 3,919 |
| Enhanced NLP hybrid | 173 | 19 | 142 | 0 | 3,965 |

TF-IDF and LSA have small positive detail-recall differences here. Coverage adds 16 unit hits and 28 fact-string hits over modular ranking, with 96 more words, but loses two main-idea hits. Neither result establishes that all extra content is important to a reader. The section/role ablation does not demonstrate a held-out aggregate gain; on historical examples it actually improves unit hits from 123 to 126. Structural heuristics remain an experimental part of the development-selected configuration, not a proven requirement. A simpler candidate should be tested on a new holdout rather than selected retrospectively here.

Removing context dependencies introduces eight detected omissions on held-out general summaries and 19 on historical summaries. Exact source-span validation remains enabled in that ablation: these are examples of why source membership alone is insufficient. The ablation does not disable every factual safeguard, and the dependency checker is also the source of this metric; this is a mechanism check, not independent semantic factuality evaluation.

## MMR decision

The best development settings for lambdas .85, .95 and 1 tied at 103/132 detail-unit checks, 16/27 main-idea checks and 3,394 words, with no detected context errors. The tie rule selected lambda=1 and rank_mix=0. The nontrivial .95 comparator also produced the same held-out coverage, selected sentence counts and word count. Coverage alone took 0.883 seconds across the 36 measured requests; the MMR comparator took 1.099 seconds in that particular run. Timing is observational, not a controlled speedup estimate.

The deployed candidate therefore does not compute residual similarity at lambda=1. MMR remains implemented for research and regression tests, but is not added to the default path without demonstrated value. All systems have zero exact duplicates and zero within-summary TF-IDF near-duplicates at the post-hoc .85 threshold on this set. This corpus is consequently weak evidence about real-world redundancy reduction.

The enhanced configuration retains six additional main-idea hits without improving aggregate detail recall. Its annotation/model-load cost is substantial. We did not tune enhanced weights or change the existing enhanced default after seeing held-out data. There is no demonstrated justification for introducing a larger embedding model.

## Realized-length sensitivity

For each article/depth pair, prefix-cap both native outputs at the smaller realized word count. This is a diagnostic, not a re-optimized baseline. Whole sentences leave different unused space, and prefix removal can remove context.

| Paired comparison | Candidate detail hits | Comparator detail hits | Candidate / comparator words |
|---|---:|---:|---:|
| Lightweight hybrid vs legacy | 113 | 110 | 2,357 / 2,571 |
| Enhanced hybrid vs legacy | 107 | 110 | 2,322 / 2,571 |
| Lightweight hybrid vs v1 article | 114 | 121 | 2,286 / 2,458 |
| Enhanced hybrid vs v1 article | 106 | 121 | 2,248 / 2,473 |

The main ceiling-controlled gain is substantially explained by using more of the allowed budget. Comparable realized-length superiority is **not established**, especially for enhanced production-default behavior. Prefix capping itself creates one context dependency error in each candidate-v1 sensitivity comparison; that error is not present in the candidate's native output. Do not use the adapter in production.

## Conclusion

Weighted coverage is a useful experimental improvement over the old article selector, but the full acceptance gate has not passed. Main-idea retention, Focus support, equivalent-length quality and independently reviewed factual completeness remain unresolved. Parameters were not retuned on these results. Automatic routing stays disabled.
