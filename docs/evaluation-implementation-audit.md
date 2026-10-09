# Evaluation subsystem implementation audit

Recorded **2026-10-02**, Asia/Manila. This is an implementation and engineering-validation audit, **not a completed research experiment**.

**Subsequent authentication change, 2026-10-02:** the user explicitly requested removal of administrator verification. The separate anime/number challenge was removed. Administrator access now requires an authenticated administrator session, with evaluation additionally checking the active account and database role. Password login and valid remember-me authentication enter directly; legacy pending-only sessions must sign in again. The test counts and execution results recorded below describe the original evaluation implementation and remain historical evidence, not new validation of this later authentication change.

**Validation of verification removal:** 19 security checks, 54 transactional evaluation checks, and 38 local HTTP checks passed. HTTP coverage includes actual password login, remembered login and token rotation, administrator navigation, restricted pages/APIs, retired challenge GET/POST routes, pending-only and ordinary-user denial, terms acceptance, incorrect passwords and invalid CSRF. Changed PHP files passed syntax checks. Temporary accounts, tokens and sessions were removed; evaluation fixtures rolled back. Legacy suites that perform broad database or file mutations were not executed.

## Implemented

- Separate, versioned evaluation datasets with configurable planning counts, source text stored privately in the database, provenance, source hashes, categories, profiles, inclusion timestamps, active states, and development/validation/test splits.
- Draft-only source/reference/content-unit/challenge changes. Queuing seals the dataset and stores an immutable source/annotation snapshot. Changed sources or algorithms require a new version/run.
- Configurable document × mode × system matrices; Brief, Balanced, Detailed and Comprehensive; existing profile names reused. Each result retains generated text, status, actual/target word counts, timestamps, configuration, code/package/resource fingerprints and warnings.
- Offline queue, per-output persistence, independent metric availability/error records, cancellation and resumption of unfinished work. Completed outputs cannot be silently overwritten.
- Three baselines, ten actual component ablations, controlled challenge cases, reviewer error categories and source/summary/reference/metric/rating inspection.
- Blind assignments, independent seven-dimension human ratings, visible Likert guidance, locked submissions, administrator corrections with previous values preserved, output-specific content-unit verification.
- Persisted descriptive/inferential reports, system and mode comparison tables, ablation results, coverage/missingness, error examples, a locally hosted compression chart with table fallback, CSV/JSON exports and printable HTML.
- No external API was added to the UI or summarizer engine. New interfaces use ordinary PHP forms and server rendering. Main summaries remain on their existing paths.

## Initial architecture audit and reuse

The actual primary path is PHP `LocalPythonBridge` → stdin/stdout JSON → `python-engine/local_cli.py` → `summarizer_core.pipeline.SummarizationPipeline`. A second existing combination-summary path invokes `generate-summary`; both received real-source smoke checks. Evaluation wraps the existing pipeline with request-local instrumentation and experimental switches; default production behavior is retained.

The application uses its PDO/MySQL `Database` singleton, native prepared statements, shared `whitereaper.php` session bootstrap, existing CSRF helpers, and `AdminSecurityService`'s administrator role check. At the initial evaluation implementation audit, that service also required an administrator challenge; the subsequent user-requested removal is recorded above. Existing production stores include `summaries`/`summary_artifacts` and `documents`/`document_summaries`. Evaluation does not repurpose or rewrite them. Existing Analytics reporting remains separate.

The checkout already contained extensive staged, unstaged and untracked work. That work was retained. New evaluation files are additive; existing Python scoring/selection/cleanup/tokenization received small default-preserving hooks, and the shared navigation gained an administrator Evaluation link.

## Database

Applied migration **012_evaluation_framework.sql**, after confirming 001–011 were already applied. It creates 13 InnoDB tables with foreign keys, targeted indexes and uniqueness constraints:

`evaluation_datasets`, `evaluation_documents`, `evaluation_references`, `evaluation_content_units`, `evaluation_challenge_cases`, `evaluation_runs`, `evaluation_outputs`, `evaluation_metrics`, `evaluation_assignments`, `evaluation_ratings`, `evaluation_errors`, `evaluation_content_unit_reviews`, `evaluation_audit_log`.

The migration is additive and uses `CREATE TABLE IF NOT EXISTS`. Existing summary/history/analytics records are not migrated or deleted. Safe manual rollback order is documented in [operations](evaluation-operations.md); populated research tables are never automatically dropped.

Large snapshots/reports use a transparent, lossless gzip/base64 JSON envelope with SHA-256 verification. This was necessary because the installed MariaDB server permits 1 MB SQL packets. Prior reports remain in the audit log; the replacement report hash identifies the current revision. Report refresh preserves its pending marker if observations change during calculation.

## Metrics

Metrics are independent, never combined into overall accuracy:

| Metric | Exact interpretation |
| --- | --- |
| ROUGE-1, ROUGE-2 | Clipped token unigram/bigram overlap; precision, recall and F1; arithmetic mean across approved references. |
| ROUGE-L | Whole-text longest common subsequence P/R/F1, not ROUGE-Lsum. Explicit versioned tokenizer; no stemming. |
| BERTScore | Optional CPU `bert-score` P/R/F1; local cached model; no silent context truncation; unavailable dependency/model/input conditions retained. Raw F1 is not a probability. |
| Compression | Summary words/source words; reduction percentage `(1-ratio)*100`; null when source count is zero. Expansion can produce ratio >1 and negative reduction. |
| Redundancy | Versioned lexical duplicate/near-duplicate detection, with counts and thresholds in raw details. |
| Critical-fact preservation | Source-aligned heuristic checks for numeric values, dates, entities, negation, qualifiers, comparisons and causal wording. Preserved/missing/altered/suspected-contradiction outcomes, aligned evidence and denominators retained. |
| Factual consistency | Fraction of summary sentences without the specified lexical source-alignment warnings. This is a heuristic warning screen, not validated entailment or proof of factual truth. |
| Content-unit coverage | Represented/expected units, calculated only after all units receive output-specific verification. Literal matches are review aids only. |
| Challenges | Fraction of explicitly specified cases satisfying every required/prohibited phrase assertion; individual failures and reasons retained. |
| Performance | High-resolution stage durations, metric time, budget-calibration time and total item evaluation time. |

PHP and Python use the same `unicode-words-v1` evaluation counting rule. Raw P/R/F1 and metric implementation versions are retained in storage/exports and shown in the inspection view. Full definitions and citations are in [methodology](evaluation-methodology.md).

## Human evaluation

Management uses the existing authenticated-administrator role. Ordinary active accounts can act as evaluators **only for their own assigned items**; no new application-wide role or authentication migration was introduced.

Assignments store independent randomization seeds, stable presentation orders, pseudonyms and opaque random tokens. The evaluator response and rendered HTML exclude algorithm/baseline/configuration identities, run/output IDs, automatic scores, reference author identities and other evaluators' judgments. The source and generated summary remain visible for assessment.

Required dimensions: relevance, factual consistency, coverage, coherence, readability/fluency, conciseness and non-redundancy, each 1–5. Submission is transactional, unique and locked. Administrator-approved corrections require a reason and preserve previous values. Public exports omit account IDs, tokens/seeds, private reference author codes and free-text rating comments.

**No recruited human assessments or independent human references have been collected.** Test ratings were explicit software fixtures and rolled back. Temporary browser accounts, assignments and draft content were removed after verification; no browser test submitted a human assessment.

## Baselines and ablations

Lead-N, basic TF-IDF centroid relevance, and weighted TextRank share the source/candidate pool and target the full system's actual word budget. Whole-sentence selection may deviate from that target; actual counts and deviations remain visible.

Ten switches remove the actual named mechanisms: title ranking weight, section ranking weight, sentence position, paragraph position, profile base weights, article-type relevance, redundancy selection/cleanup, qualifier protection, negation protection and numeric protection. Their descriptions specify which other mechanisms remain active. No global production configuration is changed.

Ablations retain the production mode's native length policy. Their lengths can differ, so their effects are **not automatically length-controlled causal contributions**. Researchers must inspect that potential confound.

## Statistical analysis

- N attempted, valid, missing and distinct documents; mean, median, sample SD, minimum, maximum and seeded 95% percentile bootstrap intervals where estimable.
- Documents are the sampling units; repeated output/rater observations are averaged within document for the relevant condition. Human intervals condition on the observed evaluator panel.
- Document-paired two-sided Wilcoxon signed-rank comparisons, nonzero-pair counts, rank-biserial effects, difference intervals, Holm-adjusted p-values and explicit correction families. These are exploratory unless prespecified before results are inspected. Small positive p-values are not rounded to a displayed zero.
- Ordinal Krippendorff alpha with frequency-weighted ordinal distance, checked against a published missing-data example. Reports distinguish rated/pairable items, missing assigned ratings and unassigned matrix slots.
- Metric versions and dataset splits remain isolated. Missing generated results inherit a uniquely identifiable configured metric version for denominator reporting without changing their raw stored metric records. A failure test verifies N attempted 2 versus N valid 1.

No automatic superiority, accuracy or practical-benefit conclusion is generated. Assumptions, interpretation cautions and source references are in [methodology](evaluation-methodology.md).

## Security and performance

Server-side authenticated-administrator gates; active-account checks; ownership-bound assignment lookup; CSRF validation on writes; prepared SQL; output escaping; integer/enum/text bounds; rating CHECK/UNIQUE constraints; audit records; private/no-store responses; and spreadsheet-formula protection on exported strings. CSV numeric values remain numeric. Sources are stored as text; no arbitrary web-provided file path is opened and no upload execution path was introduced.

Worker computation is CLI-only. PHP starts Python with an argument array and JSON data; no user command string is executed. A database advisory lock bounds concurrency to one evaluation worker. Thread limits, per-item timeouts and input/output/source limits prevent uncontrolled parallel inference. On Windows, private temporary files avoid unreliable nonblocking process pipes while retaining the persistent Python/model cache.

The worker verifies source fingerprints at run start and between items. Code drift invalidates the run rather than silently changing its configuration. Failed metric insertion rolls back the whole output transaction; other outputs can continue. Missing optional metrics retain null values and explanatory status. Interrupted runs resume only unfinished records; retrying failed/completed outputs requires a new run.

## Tests and real execution

| Verification | Observed result |
| --- | --- |
| Full Python regression suite | **127 tests + 14 subtests passed**, including existing production suites. |
| PHP integration/security/failure suite | **52 passed, 0 failed**; all fixtures rolled back; production table counts preserved. |
| Browser checks | **37 passed** across empty/populated pages, exports, forms, XSS escaping, CSRF denial, blind HTML and cross-evaluator access. |
| Responsive inspection | 1920×1200, 1366×768, 768×1024 and 390×844; screenshots inspected; no document-level horizontal overflow. Wide tables scroll within their containers. |
| Native PHP smoke checks | Both existing `summarize` and `generate-summary` paths produced real-source output. |
| Migration safety | Migration012 reran successfully on populated tables without changing output-record counts. |
| Production parity | 24/24 outputs across four real sources and two synthetic sources × four modes exactly matched the unablated canonical pipeline. |
| Actual database run 10 | Four real public-domain documents × four modes × four systems: **64 generated, 0 failures**. |
| Actual database run 11 | Two separately reported synthetic stress cases × four modes × four systems: **32 generated, 0 failures**. |
| Actual database run 12 | 24 synthetic factual challenges × four modes: **96 generated, 0 failures; all 96 satisfied their specified phrase assertions**. This is not general factual accuracy. |
| Actual database run 13 | Four real sources × Balanced × full system plus ten ablations: **44 generated, 0 failures**. |

These four valid runs contain **236 generated outputs**, with stored reports and matching current Python fingerprints. Their counts, statuses and timings were queried from the database into [evaluation-database-validation.json](evaluation-database-validation.json). All lack recruited human ratings and approved human reference summaries; reference-dependent quality evidence remains unavailable.

Run 4 is explicitly invalidated because source files changed during execution; its outputs remain for audit. Run 7 was cancelled before generation to separate real and synthetic evidence. Neither is counted in the 236 valid outputs.

Five actual source-linked engineering error classifications were recorded: USGS sentence fragments, NASA sentence fragments and joined words, omission of the NIST outlier definition in Brief, and retained deliberately corrupted text in the synthetic OCR fixture. These are agent-assisted engineering observations, **not human evaluator scores**. See [validation observations](evaluation-validation-observations.md) and the in-app output reviewer.

## UI consistency check

**Reference:** existing LIGHT landing, Analytics, administrator and summarizer pages.

**Files inspected:** `app/public/index.php`, `analytics.php`, `admin_dashboard.php`, `partials/summarizer-form.php`, `partials/site-nav.php`, `partials/site-footer.php`, `partials/nex-footer.php`, and `assets/css/design-tokens.css`, `style.css`, `index.css`, `analytics.css`, shared navigation/footer/button styles.

**Patterns reused:** shared LIGHT navigation/footer, Analytics shell/headings/ruled surfaces/empty states, existing `form-group` and `btn-auth` controls, shared font families, color/spacing/radius/focus tokens, and established 800/560px Analytics breakpoints.

**New UI:** administrator-only `evaluation.php`, assignment-only `evaluate.php`, restricted `evaluation_export.php`, scoped rendering partials/styles and a local Chart.js view with accessible table fallback. New sections adapt existing components; ordinary result-page reading and existing summarization/analytics/history layouts are retained.

**New design tokens: none.** Desktop/mobile screenshots retain LIGHT's identity, colors, typography, controls, borders, spacing, header and footer. Keyboard controls, labels, unique input IDs, escaped states and responsive table containment were checked.

## Known limitations and research validity risks

- Four real validation documents are engineering coverage, not a representative 48-document research sample. Synthetic stress/challenge runs must remain separate from real-document claims.
- BERTScore's missing-dependency/model path is tested; no successful transformer metric inference or reference-dependent quality result is claimed for these runs. Installing a model and collecting references are still required.
- Factuality/critical-fact checks are English-oriented lexical heuristics. Named-entity spans are heuristic candidates; paraphrases, ambiguous references, causal distinctions and context can escape or confuse them.
- Existing production extraction/cleanup weaknesses were observed and documented, not hidden or rewritten as part of this feature: abbreviation-related fragments, apostrophe spacing, short-summary omissions and retained source noise.
- Synthetic OCR text does not validate PDF scanning, tables, equations or image OCR. Very long/complex document limits need a separate corpus and resource study.
- Whole-sentence baseline budgets and natural ablation lengths are approximate; compare recorded counts before interpreting recall or component effects.
- The statistical implementation assumes independent document IDs and does not infer syndicated/shared-source clusters. References can be subjective; small N gives unstable intervals; repeated exploratory comparisons cannot substitute for a registered protocol.
- Evaluation support is currently English/text oriented. Management is administrator-only rather than a new researcher role. Resource limits and full-run report views should be reviewed before large-scale production deployment.
- Exports omit structured private identifiers, but researchers must review their own source citations/error notes before public publication.

## Defense readiness and next actions

The software can now collect and trace evidence. A capstone quality conclusion still requires: a preregistered sampling/split protocol; the intended independent corpus; independent approved reference summaries at appropriate budgets; recruited/blinded evaluators; verified information-unit annotations; final held-out baseline/mode/ablation execution; and manuscript updates reporting real N, versions, assumptions, uncertainty and observed limitations.

Start at the signed-in administrator's **Evaluation** navigation link. Worker/import/report commands and optional dependencies are in [evaluation-operations.md](evaluation-operations.md). Scientific definitions, formulas and citations are in [evaluation-methodology.md](evaluation-methodology.md). No result or favorable conclusion has been fabricated.
