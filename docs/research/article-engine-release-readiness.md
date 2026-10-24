# LIGHT runtime repair and article-engine release readiness

Follow-up: [external v2.1 validation pilot and release gate](article-engine-independent-validation.md). New real-article outputs and enforced container measurements do not establish independent semantic superiority or full production readiness. Routing remains disabled; the historical repair/refinement evidence below is preserved.

22 October 2026. Refined candidate: **recipe-article-2.1**. Local request repair is verified; **global automatic routing is not approved and remains disabled**. Independent external quality review and deployment memory headroom are still outstanding. Existing failed file jobs were not rewritten or automatically rerun: use a fresh upload.

## A. Runtime failure investigation

The user reported the generic “This request could not be completed” / undetermined-cause message and identified PDF input, but supplied no exact reference ID. We therefore do not claim to have identified every historical occurrence of that message. We reproduced concrete failures on a new upload whose bytes matched the retained 162-page, 6,828,558-byte academic-guide PDF.

### Observed failures and corrections

1. Apache had not loaded `pdo_pgsql` and was accepting jobs in the configured local MySQL fallback. Its detached PHP CLI had loaded `pdo_pgsql`, selected the configured PostgreSQL endpoint and executed MySQL-specific job lookup SQL there. The fresh normal HTTP upload stayed `processing`; directly inspecting that same isolated worker invocation produced exit **255**, SQLSTATE **42601**, in `SummaryJobService::getJob`, before a worker PID could be recorded. [Original reproduction](runtime-failure-reproduction.json) retains the observations. This is not an article-algorithm error: routing was false and legacy selected.
2. After the handoff correction, the large PDF reached Python but exceeded the existing **180-second** deadline. Private stage records showed approximately 112 seconds through topic analysis and 176 seconds through the first embedding pass, before candidate selection finished. Local `LOCAL_PYTHON_TIMEOUT` was raised to the bridge's existing bounded **300-second** ceiling. `NLTK_DATA` was pointed at the already installed data so the service account can discover the intended resources. This does not change deployment defaults or remove hard deadlines.
3. Expanded opt-in HTTP testing later found a separate contract mismatch: the article engine intentionally returns an unsupported-Focus message with zero excerpts, but PHP rejected the empty excerpt list. Reference `bc790e5eabfcb5a312ad4d56` identified `response_validation`, article selection and Python exit **0**, rather than an engine crash. PHP now accepts only the precisely recognized, validated informational outcome: article engine, unsupported status, zero checked/issue-free evidence, nonempty query, empty excerpts/evidence, true contract validation and the canonical insufficient-information message. Arbitrary empty/malformed responses remain quality failures. No source sentence is fabricated to make storage succeed.

`SummaryJobService` now passes a validated, server-derived `--database-driver=mysql|pgsql` to `summary_worker.php` before bootstrap. `Database` honors it only in CLI; a pinned PostgreSQL worker fails closed if its accepting backend is unavailable. No public form can choose the database. Following the Neon/Postgres skill's preservation guidance, we retained the existing endpoints/drivers and actual accepting datastore rather than provisioning, migrating or switching user data. No schema changes or Apache restart were performed. **The wider application still contains MySQL-specific SQL: this task does not certify a full PostgreSQL deployment or make it safe to switch the entire running application to another datastore.**

Python dotenv loading now preserves service-owned environment values. Local model execution is explicitly offline at the subprocess boundary; missing local resources keep their existing fallback. This is operational configuration, not a replacement of legacy scoring, selection or formatting algorithms.

### Successful normal-path retests

The matching large PDF completed through actual Apache/XAMPP in **252.13 seconds**, persisted result **638**, and its result page returned HTTP 200 with the submitted test title. After explicit resource-path configuration, another fresh upload completed in **237.98 seconds**, persisted result **639**, and was retrievable. Text and a short PDF also completed independently. [HTTP attempt records](runtime-http-attempts.jsonl) retain successes and earlier timeouts; these are not isolated summarizer calls masquerading as website tests.

The trace is: existing form/CSRF/session → `ArticleController::process` → validated source and durable job → detached PHP worker pinned to the accepting backend → existing source resolver → server-owned routing flag → bridge/legacy or article engine → response validation → transactional summary/artifact/job completion → normal result retrieval and history. URL fetching and file validation remain their existing implementations.

### Private diagnostics and public safety

`ProcessingDiagnostics` links opaque public references to correlation ID, failure stage, module/function, internal exception class, duration, selected engine and real subprocess exit status. Five-character SQLSTATE and allow-listed Python exception/stage names are retained without exception prose, credentials, URLs or source text. Python progress files contain constant stage names, PID and elapsed time only. Daemon transport has no subprocess exit code to invent. Result-retrieval failures now also log the reference that is displayed. Unavailable log storage falls back to the PHP error log without replacing the original failure.

Operational files live under the existing HTTP-denied `storage/logs` directory. Production log rotation/retention and central collection remain operational work, not claims established by this local test. Friendly catalog messages are preserved; no unresolved exception is converted into an arbitrary successful summary.

## B. Algorithm quality investigation

V2 divided marginal information gain by word cost from the first selection. Short, distinct numbers could therefore displace the longer sentence carrying the central thesis. The prior 13/36 lightweight main-idea result was under explicit experimental ceilings, not the actual PHP sentence-count mapping. Current comparisons use the real six-depth controls and distinguish native vs regenerated outputs.

Main-idea protection is still not solved universally: the business example's core is missed at every lightweight matched depth, essay details remain substantially below legacy, and enhanced NLP regresses on the ambiguous-trial example. Expanded outputs and automatic unit checks do not by themselves establish reader-valued quality.

V2 Focus rejected valid paraphrases because it required insufficiently flexible lexical support, while generic words such as “study” over-retrieved results and recommendations for a design query. The refinement fixes the two known rejection cases but does not establish open-domain query understanding. One lightweight opinion query now loses annotated focus units. Whole-sentence context can legitimately introduce material without the query wording; it can also still be overbroad.

## C. Improvements implemented, equations and code

The complete changed/new file list and hashes are in [refinement-change-manifest.json](refinement-change-manifest.json). Runtime code is in `Database.php`, the job/bridge/article services, controller, worker bootstrap, result-retrieval logging, `ProcessingError.php` and the new `ProcessingDiagnostics.php`. Python CLI/env handling and `summarizer_core/runtime.py` changed only for runtime precedence/diagnostics; legacy summarization algorithms remain unchanged. Existing UI/password/cleanup/manuscript work was preserved.

V2 was frozen under `python-engine/evaluation/article_v2_snapshot/`; all eight Python hashes exactly match the old experiment. New algorithm code is `article_engine/core.py` and `focus.py`, with targeted orchestration/validation changes in `selection.py`, `summarizer.py`, `validation.py` and the version initializer. `refinement_parameters.json` retains all twelve development trials. No paid API, generative model, new dense encoder, new frontend, detector replacement or routing-policy change was introduced.

For source sentence i, the central-message seed score is

```text
c_i = alpha_p/(i+1) + alpha_t normalized_title_cosine_i
      + alpha_r normalized_TF-IDF_centroid_relevance_i + alpha_h claim_cue_i
```

Twelve development articles at six native depths selected `(alpha_p,alpha_t,alpha_r,alpha_h)=(0,.6,.2,.2)`. Three added adversarial development examples place the thesis after a narrative hook, an opposing view, or method details. A first-sentence-only policy is not selected. The best feasible context-closed core bundle is selected **without dividing by word length**; every seed word still consumes the budget. Missing title evidence contributes zero. No fixed percentage of the budget is reserved.

The supporting stage retains `F(S)=sum_k w_k max_{i in S} a_ik`, bounded normalized concept coverage and the existing cost-aware greedy utility. Focus conditions the weights on supported query evidence and skips the unrelated global core. Optional local WordNet uses bounded senses/relations and requires a source/query semantic anchor, not just a shared generic ancestor. Topical modifiers must be supported. Procedural predicates constrain the predefined methods scope. Exact spans, attribution/negation/number preservation and immediate caveat/reanalysis dependencies remain enforced; distant contradictions and arbitrary coreference are not solved.

```text
extract immutable sentence spans and existing TF-IDF/LSA concepts
if Focus requested: retrieve supported lexical/semantic evidence and condition weights
if Focus absent from source: return the recognized informational outcome, with no excerpts
if general: seed the highest calibrated central-claim bundle that fits
recompute supporting marginal coverage after each complete bundle addition
restore source order, validate spans/context and format through the existing contract
```

Development selected residual MMR **.95**: same 59/72 main checks as lambda=1, but 175 vs 169 detail checks out of 318. That benefit does **not** reproduce on the known diagnostic set: all 90 native and all 90 regenerated outputs match the no-MMR ablation exactly. The penalty is an experimental development-selected setting, not a claimed general diversity improvement; independent evaluation may justify removing it. Existing coverage masses remain unchanged. [Exact formulas](mathematical-formulation.md), [ablation details](ablation-study.md) and [research provenance](algorithm-improvement-methodology.md) distinguish the uploaded guide from subsequent research.

### Six-level budgets

The UI is unchanged. PHP still supplies counts 3/3/5/8/11/15. The article engine caps these at its own depth limits; the resulting current UI limits are **2/3/5/8/11/15**. Article word ceilings are `min(depth_max,max(45,round(source_words*ratio)))`, with existing ratios .10/.18/.26/.38/.55/.75 and maxima 80/150/280/500/850/1600. Explicit evaluation budgets replace the ratio ceiling up to the depth maximum. Word ceilings are not fill targets; sentences are never clipped or padded. The same feasible central seed persists as budgets grow, but supporting selections need not be nested. Comprehensive remains bounded by sentence limits and cannot promise every detail in a long document.

Legacy retains its separate existing `DEPTH_CONFIG` (including .06/.10 target ratios for its two shortest depths), compression, topic/section and validation controls. We did not replace those algorithms to manufacture a favorable comparison.

## D. Comparative evaluation

[refinement-results.json](refinement-results.json) retains 810 system/case records: nine configurations × 90 cases (78 general plus six supported and six absent Focus probes). There are 3,670 native/regeneration attempts. Native and selected regenerated outputs have no execution failures; 36 tight-budget trial attempts are infeasible and remain recorded. All 13 annotated articles were previously inspected; they are **diagnostic, not a fresh holdout**. Literal units, first-unit main proxies and checker-defined context errors are not human judgments.

An [independent replay of the 36 failed budget trials](refinement-trial-verification.json) reproduced every failure as `NLP_NO_VALID_CANDIDATES`; it did not alter any selected output or score. That artifact also records the actual Python/platform/library versions. The generator now retains typed codes in future trial records rather than only the exception class.

Each engine generates whole summaries through its supported controls. Selection targets legacy's native realized word count and chooses by length/contract validity only, never by annotation score. Sumy uses sentence counts. No naive prefix truncation, clause editing or padding is applied by evaluation. Whole sentences cannot always match: the registered tolerance is `max(5 words,10% of target)`. Unmatched cases remain visible.

| Regenerated general results | Main /78 | Details /474 | Words | Matched cases /78 |
|---|---:|---:|---:|---:|
| Original LIGHT | 46 | 221 | 8,144 | 78 |
| Guide Sumy LSA | 39 | 166 | 7,588 | 56 |
| Frozen article v1 | 51 | 216 | 6,695 | 42 |
| Frozen article v2 light | 20 | 237 | 7,292 | 60 |
| Frozen article v2 enhanced | 22 | 228 | 7,266 | 62 |
| Refined light | 61 | 235 | 7,431 | 64 |
| Refined enhanced | 57 | 239 | 7,308 | 65 |

Within **64 genuinely matched light pairs**, refined/legacy main hits are **48/37**, details **192/180 of 395**, words **5,454/5,520**. Within **65 enhanced pairs**, main hits are **45/36**, details **194/180 of 400**, words **5,486/5,570**. These subsets differ; do not compare their denominators as if identical. Mean absolute differences across all 78 cases are 10.88 light and 12.18 enhanced words. The unmatched tail prevents a blanket equivalent-length accuracy claim.

Native UI results are also retained: legacy 46 main /221 details /8,144 words; v2 light 14/230/6,877; refined light 61/234/7,042; enhanced 57/238/6,984. This improvement in the main proxy is not obtained by making summaries longer than legacy.

### Per-depth regenerated checks

Each row has 13 main checks and 79 repeated unit checks. These are related observations, not independent subjects.

| Depth | Legacy main / details | V2 light main / details | Refined light main / details | Enhanced main / details |
|---|---|---|---|---|
| Very Brief | 3 / 13 | 0 / 11 | 8 / 13 | 7 / 14 |
| Brief | 5 / 21 | 1 / 24 | 9 / 23 | 8 / 21 |
| Short | 8 / 30 | 2 / 32 | 11 / 31 | 10 / 32 |
| Balanced | 9 / 40 | 2 / 43 | 11 / 42 | 10 / 43 |
| Detailed | 10 / 46 | 4 / 53 | 11 / 53 | 10 / 55 |
| Comprehensive | 11 / 71 | 11 / 74 | 11 / 73 | 12 / 74 |

### Per-article regenerated detail checks

| Article | Denominator | Legacy | V2 light | Refined light | Enhanced |
|---|---:|---:|---:|---:|---:|
| News rail closure | 42 | 19 | 25 | 24 | 24 |
| Reminder trial | 48 | 23 | 22 | 22 | 21 |
| Inventory cache | 42 | 23 | 27 | 28 | 28 |
| Library-fees opinion | 36 | 8 | 15 | 18 | 20 |
| Wetlands explanation | 42 | 17 | 19 | 19 | 18 |
| Manufacturer results | 42 | 20 | 21 | 21 | 22 |
| Historical commute | 30 | 19 | 21 | 17 | 22 |
| Historical bridge | 30 | 13 | 16 | 15 | 16 |
| Historical queue | 36 | 12 | 17 | 12 | 11 |
| Historical homework | 24 | 18 | 7 | 8 | 8 |
| Historical ambiguous trial | 30 | 16 | 16 | 18 | 16 |
| NASA CRS-35 | 36 | 15 | 15 | 14 | 14 |
| NASA floods | 36 | 18 | 16 | 19 | 19 |

All selected refined general excerpts match source text, with no detected local context errors. That is provenance, not semantic factual completeness. Regenerated light fact-string hits are 227/438 vs legacy 226/438 and v2 265/438: the main-message improvement trades away some numerical detail. Annotated sentence precision is .6365 light, .6239 enhanced and .6541 legacy; incomplete literal annotations make this a weak proxy. No exact duplicates were counted, but no human semantic-redundancy/coherence score exists. No independent reference summaries exist, so no ROUGE result is invented.

## E. Focus evaluation

Under native UI budgets, supported-Focus unit checks are legacy **4/13**, v2 **5/13**, refined light **7/13**, enhanced **8/13**. The earlier v2 6/13 report used different explicit budgets and is not an identical baseline. All six supported topics now yield supported status, including the former environment/finance false negatives. All six deliberately absent topics remain rejected. Extra automated tests also check partially absent queries, misleading shared ancestors and missing-WordNet fallback.

Detected relevance signals occur in **8/18 light** and **9/18 enhanced** selected sentences. These do not establish satisfactory precision; context dependencies can legitimately add non-query sentences, and semantic retrieval can overreach. The lightweight membership-fees query drops from one annotated unit to zero. Finance still retains only one of three focus units, despite no longer being falsely rejected. The two rejection fixes must not be presented as complete Focus accuracy.

Independent reviewers and adversarial near-topic unsupported queries remain necessary. [external-evaluation-protocol.md](external-evaluation-protocol.md), the candidate source manifest and blank workbook explicitly mark this work pending. Neither implementing-agent annotations nor repeated synthetic depths are independent human evidence.

## F. Performance and deployment constraints

[Current article performance](refinement-performance.json) contains **54 successful calls**, 27 per configuration: cold startup, both source sizes at every depth with/without custom Focus, and first/repeated 800-sentence scaling calls. The short input is 1,235 characters; the real NASA article 3,507. The 800-sentence case is synthetic scaling, not a quality benchmark. Processes are separate and imports measured independently. No container memory limit is enforced.

| Current configuration | Imports (s) | First no-Focus call (s) | First Focus call (s) | Subsequent Focus range (s) | Sampled peak RSS (MiB) |
|---|---:|---:|---:|---:|---:|
| Lightweight | 7.920 | .283 | 8.787 | .255–.869 | 310.80 |
| Enhanced | 7.210 | 14.910 | 6.956 | .346–1.425 | 472.47 |

Warm no-Focus ranges are .030–.098 seconds light and .148–.487 enhanced. First/repeated 800-sentence calls take 1.506/1.405 seconds light and 12.212/13.134 enhanced. Stage timings, realized words, model/resource status and every depth/query case are retained. Optional NLP and first WordNet loading dominate their respective cold paths. Sampling every 10 ms may miss short peaks; cached models, corpus state and paging affect RSS. These observations do not establish production throughput or quality.

The [actual large-PDF process measurements](runtime-memory-c002f04023a7c1b3d5a8b1d9cd948e75.json) are **legacy**, not article-engine measurements: sampled Python RSS 570.46 MiB, Windows process high-water 693.89 MiB, parent PHP 13.93 MiB and shared Apache 10.51 MiB. Shared-server RSS cannot be attributed entirely to one request. The corrected standalone diagnostic is separately retained in `runtime-python-profile.json`; its different process context is not substituted for the normal Apache measurements.

**512 MB deployment compatibility is not demonstrated.** Enhanced article RSS alone is too close to that limit to assume adequate PHP, extraction, OS and container headroom; the repaired large legacy PDF already exceeds it. No production resources were resized, deployment restarted or routing enabled. The existing authenticated loopback supervisor was started locally at its configured port with a 300-second deadline; its one-worker admission limit still returns a typed busy failure under overlap.

## G. Regression and real-world testing

- Full Python discovery: **678 run in 983.502 seconds, 674 passed, four skipped, zero failures**. [Exact report](refinement-regression-results.json) also records the enclosing runner duration. The four opt-in HTTP translation acceptance tests were not enabled. Fault-injection retrieval/worker logs occurred in passing tests, not unexplained failures.
- New article/refinement plus existing coverage tests: 36 passed in the final targeted run. New runtime/preference tests and earlier runtime/configuration/failure suites also passed; these overlap full discovery and are not added to the distinct total.
- PHP structured diagnostics/empty-response guard checks: **22 passed**. Existing error/job/database lifecycle checks: **52 passed**, with test-only rows cleaned through scoped ownership conditions. Standard empty/malformed responses still fail.
- PHP article routing/bridge probes: **5/5 passed**, including real subprocess JSON transport. Full Python discovery includes all 26 routing tests, calibrated detector regressions, legacy independence, fallback, source spans, modes, depths, formats, PDF/DOCX and URL mocks.
- Actual Apache/XAMPP guest HTTP matrix: **11/11 passed** for six depths, PDF, DOCX, supported/absent Focus completion and a live NASA URL. These establish completion/persistence/retrieval, not semantic Focus accuracy on the legacy route.
- Actual headless authenticated browser: **six checks passed**—local test-account login, terms acceptance, real form submission/background/result, history persistence, private-ID access isolation and explicit bearer-link sharing. Test accounts and their records were cleaned; no real account was borrowed or reset.
- Isolated local PHP-server opt-in: **12/12 passed**, covering all six article depths, PDF/DOCX, supported and unsupported Focus, a live URL and actual nonarticle-to-legacy routing. Stored metadata confirms `recipe_article` for the eleven article cases and `legacy` for the nonarticle. This is real PHP/HTTP/database execution, explicitly **not** an Apache global-flag change. Engine-exception fallback is covered by automated regression, not claimed as a deliberately crashed live production workflow.
- Real HTTP privacy checks returned 403 for private diagnostics and implementation source, and 404 for both CLI-only runtime/account inspection helpers. No diagnostic file or fixture helper was exposed as a new public page.

Initial checks exposed and corrected: overly broad semantic ancestors, an overbroad methods scope, the PHP unsupported-Focus contract, and missing Windows CRLF handling in private stage parsing. Initial browser attempts also encountered an unavailable bundled Chromium, then expected one-slot busy admission during parallel testing, and a test that incorrectly treated an intentional share-token URL as a private-ID request. The final browser run used installed Edge, ran serially, and tested private ownership separately from intentional sharing. These are disclosed rather than silently relabeled successful attempts; [browser attempt records](runtime-browser-attempts.jsonl) retain the latter runs.

No external paid model/API, independent human study, exhaustive browser matrix, live production deployment, enforced 512 MB test, or full PostgreSQL migration was executed. Existing authentication/history/share semantics and UI controls were preserved. The fresh external review corpus, reviewer-defined units, calibration/held-out locking and human factuality/coherence assessments remain pending—not zero-valued results.

## H. Release decision

**Local request repair verified; article automatic-routing release not ready.** Keep `SUMMARIZER_AUTO_ARTICLE_ROUTING=false`. Main-message proxies improved substantially within more comparable realized lengths, but business/essay/Focus failures, weak annotations, unmatched lengths, unproven MMR utility and memory headroom prevent approval. Do not advertise these diagnostic counts as real-world factual accuracy.

Before activation: independently annotate and lock the external corpus, evaluate genuine held-out matched-length performance and critical contextual errors, review supported/partially supported/absent Focus near-topics, remove complexity not justified by independent evidence, and run the actual deployment under enforced memory/time limits with headroom. A broader PostgreSQL transition requires its own compatibility/data-migration review rather than an opportunistic change to the running site's datastore.

## Reproduction and artifact boundaries

Use the existing `.venv-nlp` interpreter. `scripts/test_runtime_repair.py`, `test_runtime_matrix.py` and `test_runtime_authenticated_browser.cjs` exercise real local XAMPP. `test_article_optin_http.py` starts/stops its own isolated server with server-owned opt-in and leaves Apache's flag false. `inspect_summary_runtime.php` is CLI-only/read-only; its local-MySQL option matches the accepting runtime without editing credentials. `runtime_browser_account.php` creates/deletes only guarded `qa_runtime_*@example.invalid` fixtures, sends no mail and is CLI-only.

`evaluation/refinement_calibration.py` reproduces development selection; do not retune after reading evaluation outputs. `refinement_experiment.py` retains native/regenerated outputs, all length-search attempts, hashes and frozen parameters. `refinement_performance.py` is current-engine resource measurement, separate from legacy's real PDF diagnosis and earlier v2 data. Diagnostic/performance artifacts are evidence, not instructions to enable routing or change deployment limits.
