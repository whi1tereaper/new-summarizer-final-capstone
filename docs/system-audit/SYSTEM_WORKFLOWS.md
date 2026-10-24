# System Workflows — LIGHT summarizer

Audit date: 17 October 2026 (Asia/Manila). These sequences trace actual code across interfaces, services and stores. **SOURCE-SUPPORTED** means an implemented path was inspected; **VERIFIED IN ISOLATION** applies only to the named fixture. No sequence below is a blanket claim of successful live operation. Logical actors in a diagram describe the intended workflow, not proof of authorization on every route.

## 1. Request startup and guest lifecycle

| Step | Implementation and data flow |
|---|---|
| Request enters a normal PHP page | app/src/whitereaper.php loads configuration, runtime paths and session support. |
| Remembered session is considered | RememberMeService may authenticate a stored nonadministrator validator and rotate its token. |
| An authenticated identity is rechecked | The bootstrap queries users for current identity/role/active state and clears invalid session identity. |
| Guest context is established | GuestSessionService creates/touches guest_sessions for an anonymous visitor; a random one-in-100 branch invokes expiration cleanup. |
| Terms state is synchronized | TermsAcceptanceService reads/mirrors registered acceptance and applies its route-specific redirect policy. |
| Requested handler runs | Each page/API applies its own additional role, ownership, method, input and CSRF checks where implemented. |

This startup can write data during GET, and expiration cleanup can delete expired guest feedback/summaries. A normal page visit is not a safe read-only audit action. A 24-hour sliding guest expiry is source-supported; conversion/expiry coverage is incomplete across the separate document, job and Nutshell stores. Root index, about.php and health.php are inspected exceptions that do not execute this bootstrap. Their three live responses were verified without following redirects.

Evidence: `app/src/whitereaper.php`, `app/src/Services/GuestSessionService.php`, `RememberMeService.php`, `TermsAcceptanceService.php`; `evidence/live-safe-get-results.json`. See OPS-01 and REL-05.

## 2. Account, recovery and terms flows

Registration flows from register.php to auth.php?action=register and AuthController. The submitted username/email/password/confirmation/terms values pass validation and PasswordPolicy before account persistence. Login flows from login.php through the same dispatcher. Authentication checks credentials, active state and rate limits, then establishes/regenerates session state; administrator login additionally has an email-code step whose pending state expires. Guest-owned main summaries/feedback can be claimed for the signed-in account. That claim does not imply equivalent reassignment of every store.

Password recovery requests an emailed OTP through AuthController, PasswordResetService and EmailService. Reset validates the email/account/token and new password, then updates the password hash and consumes the reset record transactionally. The inspected reset code does not revoke existing remembered tokens or all other PHP sessions. The existing database's reset columns also differ from a fresh base-schema installation. These are active findings, not hypothetical omitted controls.

Terms pages render TermsContent. A registered acceptance updates users and session state; guest agreement on terms.php updates session flags. A visible decline button shows a notice and redirects through JavaScript, rather than submitting the server's separate decline POST branch. Terms enforcement is route-specific and must not be represented as a universal processing gate. Profile deactivation verifies the password and changes active state subject to repository safeguards; it is distinct from permanent deletion. Logout is reached through a confirmation modal and a GET dispatcher target.

Stores: users, user_tokens, password_resets, rate_limits, guest_sessions and affected summaries/feedback; PHP session state. Verification: form layouts, password visibility, terms-checkbox enabling and logout-modal Escape were exercised with synthetic state; no account, acceptance, password, token or email action was performed live. SEC-03, SEC-04, REL-01, REL-05 and UI-ISSUE-11 describe relevant gaps.

## 3. Main source-to-summary job

1. **Collect source/settings.** summarizer.php includes summarizer-form.php and index.js. The user selects file, text or URL, plus format/mode/depth and optional title/focus. Browser validation reports input errors and retains a recoverable tab-local draft for up to one hour. File bytes are not recoverable from that storage.
2. **Validate and resolve intake.** summarize.php dispatches to ArticleController. ArticleService builds a pending request; SourceResolver and FileUploadService validate the chosen source and applicable limits. File acceptance checks origin/type/signature/structure. URL resolution checks scheme and address/redirect boundaries. These protections are source-supported; hostile requests were not sent to real hosts.
3. **Create or reuse a durable job.** SummaryJobService derives owner-scoped request identity and creates/reuses a summary_jobs row with queued state, payload, idempotency and retention information. Ownership is checked before owner-specific status/recovery. Session state retains the pending context needed by processing.php.
4. **Begin processing.** processing.js sends the execute action and subsequently checks status. The job service atomically claims work and records processing state, worker identity and progress. The PHP background worker/controller obtains source content and invokes LocalPythonBridge.
5. **Run the selected Python path.** local_cli.py constructs a request. The configured legacy summarizer_core pipeline extracts/cleans content, identifies structure, scores/selects passages, applies depth/format/focus logic and heuristic checks, then returns structured JSON. Optional v2/LLM paths are conditional; current configuration does not establish them as enabled.
6. **Persist successful output.** ArticleService stores main summary metadata in summaries and source/output payload in summary_artifacts; the job is completed with its result reference. This is the source-supported successful path, not an audit-created record.
7. **Open result or recover.** The browser accepts an appropriate same-origin result redirect. Errors use ProcessingError and recovery instructions; retry eligibility, cancellation and stale timeout handling depend on job state. Status reads can themselves cause a stale processing job to become timed_out.

The job source uses queued, processing, completed, failed, cancelled and timed_out outcomes; individual transition methods enforce additional state conditions. File recovery differs from text/URL recovery. No global claim that every state transition works follows from the diagram. A source-traced analytics defect means uploaded-file original-text counts and processing duration may not be persisted correctly; summary-row failure counts can omit failed jobs.

Verification: isolated source-control and error interactions; existing Python regressions; direct synthetic/checked-in engine fixtures. No browser upload-to-saved-result workflow ran. Legacy prose containing register was rejected because whole-paragraph noise cleaning dropped valid content. A direct NIST fixture returned nonempty output; the optional v2 definition-retention test failed. See REL-02, REL-03, REL-08 and REL-09.

Editable diagram counterparts: [context](../capstone-manuscript/diagrams/dfd-context.mmd), [Level 1](../capstone-manuscript/diagrams/dfd-level1.mmd), and [summary Level 2](../capstone-manuscript/diagrams/dfd-level2-summary.mmd).

## 4. Result authorization, display and export

result.php validates the identifier and requests HistoryHandler → HistoryService → HistoryRepository. The repository joins summaries with summary_artifacts. HistoryService returns a row only for matching account ownership, matching guest ownership, or a matching nonempty share token. The share token is a bearer-access capability; possessing an identifier alone is not the intended access policy. Missing/unauthorized data produces an unavailable result. This main result authorization must not be conflated with the defective separate document API.

Formatter interprets stored text/JSON into the primary summary and available optional sections. result.js manages disclosures, evidence navigation, theme, copy, print and TXT export. Copy uses browser clipboard/fallback behavior; PDF uses window.print rather than a server-created PDF endpoint; TXT uses a client Blob/download. No database write is required by these three local actions. Source/evidence blocks appear when the stored payload contains their data.

Verification: synthetic completed/unavailable templates; exact synthetic text copied and exported; theme switched. Real share-token isolation, populated evidence/coverage, every optional format, and browser print output were not fully tested. Screenshot states are generated from synthetic PDO rows, with bootstrap bypassed. Individual guest-owned results are supported even though history.php requires a registered user.

## 5. Audio, translation, Q&A and Nutshell

| Companion path | Input → processing → output | Store/external boundary | Audit result |
|---|---|---|---|
| Audio | Result control → tts_generate.php validates method/CSRF/main-summary access → BarkTtsService/local bridge → tts_audio.php validates summary/filename/token and streams audio | Local worker and audio cache; provisioned voice required and missing voices may be downloaded by source behavior | No speech synthesis/listening; isolated suffix Range response failed; public caching header is a privacy concern |
| Translation | Result text + target + CSRF → translate_proxy.php → local Python translation helper → translated text/error panel | Configured deep-translator providers can transfer submitted text externally | Provider tests are mocked; four live HTTP acceptance tests skipped; no live translation requested |
| Document Q&A | POST summary id/question/share token → main-history access → original text → local bridge source passage ranking → answer/passages/confidence JSON | Local Python passage selection over stored original text | Direct synthetic retrieval found the 120-books passage; current endpoint lacks a CSRF call and can return exception details; no current UI chat widget |
| Nutshell | Registered source/mode + CSRF/request key → nutshell_generate.php validates identity/terms/input/idempotency → source/worker call → result and nutshell_generations persistence | Database and local worker; separate main-summary linkage where present | Guest redirect verified; direct smoke returned insufficient_content with empty text, not a generated nutshell; web save untested |

Nutshell's standalone mode allowlist has six values and normalizes unsupported legal/scientific modes to general, although the main form offers eight. Translation English/Filipino support in an endpoint does not mean every combination has a visible control or equivalent model quality. Audio class naming does not prove the current engine is Bark; current setup references Piper. All heuristics and confidence labels require appropriate interpretation.

## 6. Feedback and activity reporting

Result feedback uses one-to-five stars, optional reason codes for low ratings, and a bounded comment. feedback_submit.php applies its method/payload/CSRF/session/rate-limit checks and invokes FeedbackHandler/FeedbackService. A transaction saves the current viewer's feedback and the append-only comment event where applicable. FeedbackRepository supports per-viewer retrieval, summary/system aggregates, filtered administrator views and telemetry. Anonymous presentation is not absence of internally stored account/guest linkage.

History and dashboard read saved summaries/artifacts and Nutshell through their repositories/controllers; analytics aggregates summaries and Nutshell and adds an account count in administrator scope. Dashboard preferences are inferred from previous summary settings. Analytics can show personal or administrator system scope, date-grouped activity, word reduction, timing and distribution data. These values depend on complete underlying records; missing duration/source counts and job-only failures affect interpretation. History defaults to 10 main summaries per page and up to 20 completed Nutshell records. The service's maximum accepted page size is 50, not the page's default. No visible history navigation reaches older main-summary pages despite backend page-query support.

The landing index.php template and assets/js/landing-analytics.js instrument page activity; api/track_landing.php receives bounded POST beacons and writes landing sessions/events after validation and throttling. Scroll/dwell/device/referrer/CTA/LCP information feeds the administrator panels. Ordinary browsing can therefore create analytics records even apart from guest bootstrap. No beacon was allowed during the isolated screenshots.

Verification: low-rating selection revealed reasons; no feedback save occurred. Empty synthetic dashboard/history/analytics/admin templates were captured. No real activity metrics or human ratings were read or inferred. The application's usefulness rating is not a substitute for the capstone's system-quality questionnaire or a blinded content review.

## 7. Standalone document/cache API

Logical routes are dispatched by DocumentSummaryController through api/router.php and rewrite/alias entry points. POST /api/documents creates a text document and default summary, optionally streaming events. GET /api/documents/{id} retrieves source content. GET/POST summaries retrieves cached output or requests a profile/length result; summaries/batch accepts combinations. DocumentSummaryService uses documents and document_summaries, with unique profile/length caching and a separate five-length vocabulary.

**Observed defect:** retrieval queries by document id without the main-history ownership check. The isolated actual controller returned a synthetic owner's source to another user and to an empty session. Related mutation paths lack the CSRF/rate protections used by main controllers. This is a security defect in an implemented route family, not an intended public-content-sharing design. Deployed Apache rewrite reachability was not probed; authorization failure was demonstrated in the actual isolated controller/service. No real private document was read or changed. SEC-01/02/06 detail the boundary and corrective work.

## 8. Administrator actions and diagnostics

AdminSecurityService guards the dashboard, logs, database diagnostic and feedback feed. Dashboard state-changing actions require POST, admin role and CSRF before dispatch. Administrative service methods implement account deactivation/deletion, status changes and upload cleanup, with audit logging on several paths. However, toggle_status reaches a direct update that bypasses the self/last-admin safeguards and success audit of the distinct deactivation method. Cleanup named orphaned uses file age, not verified absence of references.

Read views include overview counts, upload size/count, user filters, feedback/reason aggregates, landing panels and audit logs. Some service/dispatcher actions have no discovered current visible button. Database-health JSON checks expected tables/columns and conditionally includes development detail. These views do not themselves establish deployment or privacy compliance.

Verification: isolated administrator templates with empty/synthetic rows, filter-label inspection, anonymous guards. No production admin login, user/status action, deletion, cleanup or log-content query occurred. The guards were evaluated with synthetic state; bootstrap and identity verification were intentionally bypassed for rendering.

## 9. Evaluation, independent review and export

EvaluationService represents versioned datasets, approved references, content units/challenges, generation runs, outputs/metrics, assignments, ratings, error classifications and content-unit judgments. A run stores immutable data/configuration snapshots and fingerprints. The evaluation worker processes queued outputs, verifies code identity, computes available metrics/statistics and records status for missing or failed measurements. Its database advisory lock prevents concurrent workers for the same database by design. Implementation does not establish that a real run or participant study occurred.

An active account opens evaluate.php and obtains only an assignment bound to that account/token. The interface shows source and blinded summary, seven required 1–5 criteria and optional comment. Submission locks the assessment. Administrator correction methods require reasons and record an audit trail. Export produces JSON/CSV/HTML for an existing run; report code distinguishes missing measurements, denominators, agreement, comparisons and limitations. Free-text material still needs disclosure review even where designated private fields are removed.

The current evaluation.php redirects administrators to the main dashboard. Existing dataset/run/assignment/output tabs and report links targeting evaluation.php are therefore not an operational management workflow. Service methods and CLI import/worker tools remain source evidence; the user manual identifies this gap rather than inventing a route. Only empty/denied review templates and export denial were isolated-browser checked. No research run, assignment, survey response, correction or report export was created from live data.

## 10. Maintenance and deployment

Setup tools import base schema and migrations; even migration --status can create/seed tracking state. Worker-monitor scripts can install a current-user logon task, launch a hidden supervisor and perform health/restart checks. Cleanup scripts manage specified retention stores but can bootstrap before their dry-run branch. The implemented file-age cleanup should not be treated as a proven orphan detector. Backup automation, an installed monitor, production scheduling, live model readiness and successful recovery are not established by script existence.

The audit used direct metadata inspection rather than migrations. A source copy without the real .env isolated Python tests, while the UI renderer injected synthetic PDO responses and redirected storage paths. The renderer was stopped after capture. [Appendix G](../capstone-manuscript/APPENDIX_G_MAINTENANCE_MANUAL.md) separates verified implementation from recommended operator procedures; [KNOWN_ISSUES.md](KNOWN_ISSUES.md) gives remediation priorities.

## Workflow evidence map

| Workflow | Principal evidence |
|---|---|
| Startup/authentication/terms | Backend report; backend source coverage; UI feature notes; source snapshot |
| Main source/job/output | Backend and Python reports; source map; regression XML/CSV; smoke/follow-up records; summary DFD |
| Result display/local controls | UI report; browser-readonly-results.json; screenshot provenance; synthetic TXT export |
| Audio/document isolation defects | backend_isolated_checks.php and backend-isolated-checks.json |
| Analytics/history/admin | Backend/UI source inventories; metadata dictionary; empty synthetic captures |
| Evaluation | EvaluationService, evaluation CLI/package source; existing isolated regression cases; no live human-result evidence |
| Operations/deployment | Safe configuration metadata; schema metadata; inspected scripts; no install/restore evidence |

The complete artifact-by-artifact provenance appears in [EVIDENCE_REGISTER.md](EVIDENCE_REGISTER.md). The database diagram records physical constraints; workflow diagrams describe transformations and logical data flow. Neither should be used to erase a confirmed authorization or lifecycle limitation.
