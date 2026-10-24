# Backend and database audit

Audit date: 17 October 2026 (Asia/Manila). Manuscript project name: **LIGHT summarizer**. The runtime configuration still labels the application `AI Article Summarizer`; the audit does not rename application files, configuration, or interfaces.

## Evidence and limits

All PHP classes under `app/src` were read, together with `app/config/config.php`, base schemas, all checked-in migrations, setup tools, the worker-management scripts, and relevant public dispatchers. This is source inspection, not a claim that every feature works. The audit did not execute application bootstrap, visit the live application, run migrations, change production accounts, send email, process real documents, or query application row contents.

Direct PDO metadata queries, using only the environment-file loader, verified the configured database's **31 tables, 312 columns, and 35 foreign keys** on **MariaDB 10.4.32**. `evidence/live-schema.json` contains table/column/index/check/relationship metadata without credentials or private records. Its PHP timestamp includes the CLI timezone offset; the audit date above uses Manila. `evidence/runtime-config-safe.json` records a whitelist of nonsecret settings and PHP extensions. The inspected PHP binary is **8.1.25**. `evidence/backend-isolated-checks.json` contains three controlled defect reproductions against in-memory SQLite and a synthetic audio file, without application bootstrap or a production connection.

Status language used here: **SOURCE-CONFIRMED** means the implementation exists and was read; **METADATA-VERIFIED** means the database definition was observed; **FIXTURE-VERIFIED** means the specific isolated behavior was executed; **UNVERIFIED** means end-to-end operation or research results were not established.

## Architecture and configuration

The application is a server-rendered PHP web application with controllers, service classes and selected repositories, backed by MariaDB and local Python processes. Public entry points reside in `app/public`; `app/src/whitereaper.php` initializes configuration, session, remembered login, guest identity and terms state. `Database.php` supplies one PDO connection per process. Prepared statements are widely used; query construction for sortable/filterable lists is generally constrained to allowlisted fragments. The application is not a strict framework implementation of MVC: several public PHP scripts orchestrate services directly, and SQL also exists in controllers/services.

`LocalPythonBridge` sends JSON through a supervised localhost daemon or subprocess transport. It applies operation timeouts, validates structured responses, uses shell-bypassing process arguments and cleans temporary artifacts. The daemon protocol is version 3, with a token/secret. The fallback path and concurrency controls are implementation features, not measured availability guarantees. `EvaluationPythonBridge` uses a separate persistent CLI evaluation protocol.

Current whitelisted settings show development mode and debug enabled, translation provider `deep-translator`, target `tl`, a 180-second local Python timeout, and a 600-second TTS request timeout. `SUMMARIZER_ENGINE_VERSION` is absent and therefore falls back to the PHP configuration default `legacy`. LLM enable/provider/model values are absent. The existence of optional v2 and LLM code must not be described as evidence that these modes are active in the configured application. Model-file availability and quality are covered in the Python audit.

Actual runtime directories resolve to the project-level `storage/uploads`, `storage/audio`, `storage/logs` and `storage/tmp` by default, with configuration overrides. The setup guide's `app/storage` tree is stale. Environment loading reads the project's `.env`; it is not equivalent to a general process-environment configuration system. Secret values were excluded from reports.

## Roles and authorization model

The stored `users.role` definition contains `user` and `admin`. Guest is a session/token context, not a third database role. Evaluator is an assignment to an existing eligible account, not a separate role enum. No account roster or actual role counts were read.

| Actor/context | Source-supported capabilities | Important boundary |
|---|---|---|
| Guest | Submit text, URL or supported file; obtain summaries; view token-owned history; rate accessible output; use result actions | Guest token expires after a sliding 24-hour period; bootstrap may purge expired data. |
| Registered user | Guest capabilities, persistent account/history, profile and terms acceptance, remembered login | Main history/job access checks user ownership or a valid sharing token. |
| Administrator | Email-OTP login, account/status management, feedback and usage analytics, audit records, cleanup, evaluation administration | `toggle_status` lacks the safeguards applied to the separate deactivate action. |
| Assigned evaluator | View blind assignments and submit structured ratings | Assignment token is bound to the authenticated user; completed ratings are locked. |
| CLI maintainer | Import schema/corpus, migrate, run evaluation, clean retained data, manage worker process | These operations mutate state and were not executed in production. |

The standalone document-summary API does not implement the ownership boundary present in main history. This is a verified defect, not an intended anonymous-public-document feature.

## Authentication, terms and identity lifecycle

`AuthController`, `UserRepository`, `PasswordPolicy`, `RateLimiter`, `SessionManager`, `PasswordResetService`, `RememberMeService` and `AdminSecurityService` implement registration, login, logout, administrator OTP, reset, remembered sessions and throttling. Password rules require 8–72 bytes, a digit, and uppercase or special-character content; PHP password hashing/verification is used. Session IDs regenerate during authentication. Administrator OTP is six digits, email-bound, session-hashed and expires after ten minutes. Reset OTP is six digits, hashed, expires after fifteen minutes, and has rate-limit paths. Outbound SMTP operation was not tested.

Remembered login uses a random selector and hashed validator, a 30-day cookie and validator rotation; administrator cookies are not automatically authenticated. Password reset changes the password and consumes reset records but does not revoke existing persistent login tokens or other PHP sessions. The `admin_security_challenges` table is a legacy structure and is not evidence that the current email-OTP flow uses a security-question challenge.

Terms acceptance is recorded on `users` and mirrored in session state. Registration requires the checkbox, while persistent acceptance is completed through the dedicated flow. The bootstrap has a route allowlist; terms enforcement is not a universal gate on every processing endpoint. `TermsContent` is the implemented text, not a validated institutional privacy policy.

`GuestSessionService` creates/touches guest records and has a one-percent opportunistic purge. Guest claiming updates `summaries` and `feedback` within a transaction and clears the guest identity. It does not similarly reassign standalone document cache, nutshell rows, or pending job rows. Thus conversion and retention are incomplete across feature families. Anonymous GET requests are not read-only operations.

## Input, jobs and stored results

`ArticleController::process` checks method/CSRF, preserves form state and creates a recoverable job. `ArticleService::buildPendingSummary` validates profile, depth and formatting, then resolves text, URL or uploaded PDF/DOCX. `SourceResolver` blocks non-HTTP schemes, credentials, local/private/reserved destinations and unsafe redirects; validates DNS targets and pins resolution; verifies TLS; limits redirects, response sizes and time. HTML extraction uses `LIBXML_NONET` and strips navigation/script noise. These are code controls; no live SSRF or arbitrary-host request was attempted.

`FileUploadService` checks actual PHP upload origin, extension, MIME/signature and DOCX ZIP structure; uses unpredictable filenames; applies a nominal 30 MiB ceiling constrained by PHP limits. Upload extraction and parser behavior are analyzed separately in the Python audit. The older `UploadService` has no current caller found and references helper functions not defined in the inspected validation utility; it should not be presented as an active supported flow.

`SummaryJobService` records owner, request identity, state, attempt count, payload expiry, worker PID and progress. It supports queued/running/completed/failed/cancelled transitions, an atomic claim, stale-job handling, bounded retries and payload cleanup. Reading a job can trigger stale-state updates. Source text may be retained in recovery payloads for a limited interval. File retry behavior differs from recoverable text/URL inputs. The main controller calls `storeSummary` only after worker success and completes the job; failure information goes through the typed `ProcessingError` contract.

Successful results are stored in `summaries`, with source/generated artifact content in `summary_artifacts`. The generated JSON may include blocks, paragraph summaries, overview, keywords, key points, important terms, conclusion, profile/depth/format, retrieval/evidence/coverage and source metadata. `Formatter` escapes HTML rendering and exposes normalized plain-text/display fields; estimated reading time uses a 200-word-per-minute convention, not measured reader performance. The JSON is a structured payload, not a relationally normalized set of all those concepts.

The background path sends an empty original-text argument for uploaded files and passes no processing duration to `storeSummary`. Stored database word-count/compression and time analytics can therefore be incomplete even when worker JSON has source text or timing information. `recordFailedSummary` exists but no caller was found; failed jobs should not be assumed to appear in summary-table failure totals.

## Secondary output and feedback services

`HistoryService`/`HistoryRepository` retrieve recent summaries with owner or bearer-share-token checks and artifact joins. The history page defaults to 10 main summaries per page and 20 Nutshell records; the service maximum accepted page size is 50. The separate `documents`/`document_summaries` API creates text documents and caches one profile/length combination at a time, supports default/individual/batch retrieval and event-stream creation, and uses eight profiles with five allowed lengths. Its vocabulary and tables differ from the main pipeline's six depth choices. Existing caching and uniqueness do not repair the missing document authorization.

`BarkTtsService` is a legacy class name around the local TTS bridge; the current engine setup references Piper. It normalizes text, constructs a deterministic audio filename/cache key, signs access tokens and streams WAV data. The public route checks main-summary access and expected filename/token. A suffix Range request is handled incorrectly in an isolated fixture, and the returned `public` one-year caching header is inappropriate to describe as private authenticated caching.

`FeedbackService` supports 1–5 ratings, nine reason-code choices for low ratings, an optional bounded comment, latest per-user/per-guest feedback and an append-only comment-event ledger within a transaction. Older impression-based feedback maps four categories to 5/4/2/1 and has a separate payload shape. Feedback on a shared result can follow the share-token authorization path. An anonymous label in the administrator presentation is not proof that the stored feedback is unlinkable to a user or summary. Feedback statistics are application usage data, not completed research survey results.

`AnalyticsController` and repositories compute summary/nutshell counts, date-grouped trends, input/mode/depth/format preferences, landing visits/conversion, dwell/scroll/device/referrer aggregates and LCP summaries. Calculations depend on telemetry quality and the incomplete summary metrics noted above. No live aggregate values, participant numbers or research conclusions were collected.

## Evaluation subsystem

`EvaluationService` implements versioned datasets, source/provenance/split records, reference summaries, content units, challenge facts, output generation runs, metrics, assignments, ratings, error classification, coverage review, statistics and exports. Eight profiles and five lengths are supported through `SummarizerPromptTemplate::ALLOWED_LENGTHS`; the main summarizer separately supports six depths including `short`. Compared systems include the proposed pipeline, optional hybrid LLM, lead-N, TF-IDF and TextRank; the ablation list is explicit in source. A maximum 500 documents, 20 MB run snapshot, 10,000 planned outputs and three queued/running runs constrain workload. A run seals dataset content and stores a canonical configuration, code fingerprint and immutable source/reference snapshot.

Administrator-oriented service methods create or activate datasets/documents, add references/content units/challenges, queue/cancel/resume runs, assign evaluators, correct ratings with a reason, classify errors and verify represented content units. These methods are not evidence that every action has a reachable current browser control: `evaluation.php` currently redirects administrators to the dashboard. `evaluate.php` and `evaluation_export.php` implement reviewer/export entry points, while import/worker CLI entry points exist. The offline worker enforces one worker per database using an advisory lock, processes outputs independently, verifies code fingerprints before and during work, and saves descriptive reports with input hashes. Failed or missing metrics retain explicit status instead of silently becoming zero.

Assigned reviewers receive blind labels and randomized order; seven required criteria use 1–5 ratings, with an optional overall value. Submission is user/assignment-bound and locks the assignment. Administrator corrections record previous/replacement values and a reason. Exports remove specified private identifiers/comments but free-text notes still require disclosure review. Statistical/report code is not proof that a real evaluation has occurred. No evaluation corpus import, run, assignment, survey or participant action was performed by this audit.

## Live schema and migration consistency

The complete dictionary is `../capstone-manuscript/tables/DATA_DICTIONARY.md` (also CSV). `../capstone-manuscript/diagrams/ERD_ACTUAL_SCHEMA.mmd` includes all 35 observed foreign keys; core and evaluation groupings improve readability. Only real FK constraints are diagrammed. All observed tables use InnoDB and utf8mb4 collation. SQL enum/check constraints, primary/unique keys and delete/update rules are recorded in the metadata rather than inferred from names.

| Data family | Actual tables |
|---|---|
| Accounts/security | users, user_tokens, password_resets, rate_limits, admin_security_challenges, admin_audit_logs |
| Main summary lifecycle | guest_sessions, summaries, summary_artifacts, summary_jobs, feedback, feedback_comments |
| Additional output/cache | documents, document_summaries, nutshell_generations |
| Landing telemetry | landing_page_sessions, landing_page_events |
| Evaluation | evaluation_datasets, evaluation_documents, evaluation_references, evaluation_content_units, evaluation_challenge_cases, evaluation_runs, evaluation_outputs, evaluation_metrics, evaluation_assignments, evaluation_ratings, evaluation_errors, evaluation_content_unit_reviews, evaluation_audit_log |
| Migration tracking | schema_migrations |

`documents.user_id`, `nutshell_generations.user_id`, `landing_page_sessions.user_id` and the guest-token links are not enforced foreign keys. `summary_artifacts.summary_id` is nonunique: the actual relation permits multiple artifacts even if the normal writer inserts one. These limitations should remain visible in the ERD and normalization discussion. A historical progression through first/second/third normal form cannot be reconstructed from current source alone.

**Fresh-install defect:** the live `password_resets` table contains `used_at` and no `used`/`created_at`. Base `database/schema.sql` defines `used` and `created_at`, and none of the checked-in migrations adds `used_at`. `PasswordResetService` queries `used_at`. The configured database matches that service, but a fresh installation from the checked-in scripts is not schema-equivalent. This conclusion compares source and live metadata; no install was executed.

`tools/import_schema.php` creates/imports and runs migrations. `tools/migrate.php --status` can create the migration ledger and insert inferred baseline records before reporting status. It is not a read-only inventory command. `tools/check_native_environment.php` creates runtime directories and invokes a Python self-test. Cleanup scripts import bootstrap before checking `--dry-run`; those flags do not guarantee absence of unrelated bootstrap writes.

## Maintenance and deployment observations

Worker scripts install a current-user limited-privilege logon task named `LIGHT NLP Worker Monitor`, check supervisor health every 30 seconds, and start a hidden process. Stop logic verifies project script identity, process owner and listener PID before terminating a process tree. Source existence does not establish that the task is installed or running. Cleanup scripts cover landing sessions, audit logs, feedback comments and expired job payloads; defaults for the first three are 90 days. The audit found no verified automatic database backup/restore implementation or evidence of a successful recovery drill.

Apache configuration supplies security headers and rewrite rules conditionally on enabled modules. CSP permits inline script/style; HSTS is HTTPS-conditional. A PHP development server does not apply `.htaccess`. Root deployment should point to `app/public` and preserve protection of source, database, storage and secret files; exact production host configuration was not inspected. The setup guide's claims of universal portability, no external dependencies, Python 3.8 sufficiency and a secondary administrator security-question setup should not be copied as verified facts. The current source uses Python features requiring at least 3.10, dependency/model versions may require newer runtimes, and translation/email/model provisioning have their own dependencies.

## Findings to carry into the manuscript

The software implements a substantial local summarization workflow and a separate evaluation workflow. This audit establishes structure, metadata and selected isolated defects. It does not establish production readiness, privacy compliance, correctness of every source-to-summary claim, successful email/TTS/translation in the deployed environment, performance under load, or research effectiveness. Prioritize the authorization, reset revocation, administrator-status bypass and fresh-install schema defects before a supervised deployment or participant evaluation. Detailed issue evidence and recommended remediation are in `SECURITY_AND_RELIABILITY_AUDIT.md`; no fixes were applied under the documentation-only scope.
