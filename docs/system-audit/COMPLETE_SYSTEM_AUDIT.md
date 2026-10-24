# Complete System Audit — LIGHT summarizer

Audit date: **17 October 2026, Asia/Manila**. This report consolidates the actual application audit and its evidence. It is not a security certification, a completed human evaluation, or a claim that every implemented workflow operates successfully.

## Overall assessment

LIGHT implements a substantial document summarization application: PHP pages and services manage accounts, sources, jobs, stored summaries, feedback, analytics and administration; a local Python engine performs extraction and text processing; MariaDB stores application and evaluation records. The inspected configuration selects the **legacy** summarization path by default. The separate v2 engine and optional language-model synthesis code must not be described as the active production pipeline simply because they exist in the repository.

The audit establishes the implementation structure, actual database definitions, original interface layouts, and selected isolated behavior. It also confirms important defects: the standalone document API lacks ownership enforcement, ordinary prose can be discarded by legacy navigation cleaning, a v2 regression loses an expected definition, and suffix audio-range handling is incorrect. Additional source-confirmed security and deployment defects include missing document-API mutation protections, incomplete session/token revocation after password reset, an alternate administrator status action that bypasses safeguards, and a clean-install password-reset schema mismatch. **The evidence does not establish readiness for unrestricted deployment or participant evaluation.** No application fixes were made under the documentation-only scope.

The recommended next step is a separately authorized remediation and disposable-environment verification phase, beginning with access control, account-security invariants, schema reproducibility and the confirmed input-cleaning defect. The manuscript can describe the software and this audit now, provided it preserves the limits below and leaves actual participant evaluation pending.

## Scope, sources and preservation

The audit began with the supplied 184-page AccredX manuscript as a structural reference. Its screenshots, participants, results, institutional statements and personal material are not evidence about LIGHT. Application analysis then inspected source, public routes, configuration structure, schema and migration definitions, worker/maintenance scripts, frontend assets and Python modules. Existing repository research/engineering artifacts were identified as pre-existing material rather than new evaluation results.

| Area | Coverage established | Evidence limit |
|---|---|---|
| Public PHP and interface assets | 41 entry points, 13 partials, 16 JavaScript assets including the Chart vendor asset, and 19 stylesheets indexed with hashes/dependencies; root index redirect inspected separately | Indexing and source reading do not execute every conditional branch |
| PHP backend, setup and maintenance | Classes under app/src, configuration, schema/migrations, setup tools and relevant dispatchers read; backend contribution records 30 capability groups | Method presence does not prove a reachable UI or successful integration |
| PHP/JavaScript syntax | 135 PHP files and 15 JavaScript files passed the recorded syntax checks, 150 total | Parse success is not functional/security correctness; the complete checked-file list is authoritative |
| Python source | 108 files indexed; 107 parse as UTF-8 source; UTF-16 archival summarizer_orig.py requires decoding before its text parses | The archival file is not the current runtime entry point; AST indexing is not model execution |
| Database | 31 tables, 312 columns and 35 actual foreign keys observed through metadata queries | No application row contents, account roster, usage counts or participant records were collected |
| Interface runtime | 46 screenshots of 23 states at 1440×1000 and 390×844; 18 local interaction checks | Original templates with synthetic sessions/data and bypassed bootstrap; not authenticated live execution |
| Restricted live HTTP | Root index 302, about redirect 301, static health 200; redirects not followed | These three handlers do not bootstrap the application; static health does not test dependencies |
| Persistence and external services | Source-traced; limited isolated controller/function tests described below | Live account changes, mail, normal summarization submission/save, worker service readiness, translation and speech were not tested end-to-end |

Pre-existing working-tree changes were preserved; the initial status and source hashes are retained. The final source comparison found **all 336 files in the initial application/configuration/schema/scripts snapshot unchanged**; documentation outputs are intentionally excluded. A separate frontend checkpoint found all 89 indexed public source/asset files unchanged. The audit's new files are documentation and explicitly isolated evidence. The configured database was queried only for definitions, not altered. No migrations, production cleanup, registration, login, recovery email, document submission, feedback save, account mutation or evaluation queue action was performed against live application data.

Normal anonymous application GETs were deliberately excluded: whitereaper.php creates/touches guest state, and GuestSessionService has a one-percent opportunistic purge that can remove expired guest summaries and feedback. Even some commands labeled status or dry-run have startup side effects. Direct metadata inspection and source-preserving fixtures were used instead.

## Architecture and actual capability boundaries

The public PHP entry points render HTML or dispatch JSON requests. Controllers and service classes coordinate validation, source resolution, session/role policy, persistence, jobs and local processing. Selected repositories encapsulate reads, but some SQL remains in services/controllers; this is not a strict framework-enforced MVC application. Runtime paths default to project-level storage directories. LocalPythonBridge uses JSON with a supervised loopback daemon or subprocess transport; EvaluationPythonBridge provides the separate evaluation protocol. Mail and configured translation providers are external boundaries. Optional synthesis can introduce another external transfer when enabled.

The main summary form accepts one PDF/DOCX, pasted text, or an HTTP(S) URL. It exposes eight analysis modes, five formats, six depths, an optional title and a focus query. Main intake uses a 200,000-byte text cap, a 255-character title limit and a 300-character focus limit; file acceptance has a nominal 30 MiB ceiling constrained by PHP and configuration. A screenshot's displayed limit belongs to its renderer environment and is not a universal deployment limit. The document/evaluation subsystem has a separate five-length contract; it must not be conflated with the six main-form depths.

Guest is a temporary identity context, not a database role. Stored account roles are user and admin. An evaluator is an eligible account with assignments, not a third role enum. The HistoryService can authorize an individual result by user, guest ownership or bearer share token, while the **history page** itself redirects guests to registration. Registered users have dashboard, history, personal analytics, profile and Nutshell access. Profile is read-only identity information plus deactivation; there is no discovered editable username/email/photo profile interface. Administrators have system views and protected actions. Evaluation service methods exist, but evaluation.php redirects to the administrator dashboard; its legacy management tabs are not a working current navigation flow.

Completed result templates can display source text, summary, evidence/coverage and other metadata, plus copy, browser-print/PDF, TXT export, audio, translation and feedback controls. Conditional metadata does not establish that every run creates every section. The Q&A endpoint is API-only in the current UI. The main form exposes one file, although the separate document API and result metadata include multi-document concepts. These boundaries are recorded in [FEATURE_INVENTORY.md](FEATURE_INVENTORY.md), its CSV, and the detailed frontend/backend inventories; contribution counts must not be added without reconciliation.

The reconciled inventory contains **69 distinct capabilities: 5 Verified working, 18 Partially working, and 46 Implemented but not verified**. The other inventory classes have zero entries. Verified working is limited to the mobile menu, tested result theme toggle, clipboard copy, TXT export, and static health response. Partial identifies a demonstrated or source-confirmed limitation within a grouped capability; it does not establish failure of every action. Zero capabilities classified Not working therefore does not contradict the confirmed defects below. The 51 frontend and 30 backend contribution groups overlap and are not additional capabilities.

## Data, identity and operational findings

The observed MariaDB 10.4.32 database uses 31 InnoDB/utf8mb4 tables. They cover accounts/security; guests and summary jobs; main summaries/artifacts; feedback/comments; standalone documents/cache; Nutshell; landing telemetry; evaluation; and migration tracking. The dictionary lists all 312 actual columns and constraints. ERDs draw only the 35 observed foreign keys. Several owner/token columns have no FK, and summary_artifacts.summary_id is not unique, so a one-to-one artifact relationship cannot be asserted from the schema alone. Current tables do not establish the historical process by which normalization occurred.

The current database's password_resets table contains used_at, while the base schema defines used/created_at and the checked-in migrations do not reconcile the difference. PasswordResetService requires used_at. This is a source-versus-metadata clean-install defect, even though the existing database contains the expected field. Fresh installation and upgrade need separate disposable-database verification.

The safe configuration snapshot reports development/debug enabled, deep-translator with target tl, default legacy engine selection, and no enabled optional LLM configuration. The metadata/configuration inspection used PHP 8.1.25; the isolated UI renderer used PHP 8.3.29, and the Python confirmation used Python 3.14 with locally available packages. These observations describe different audit tools, not an assertion that every runtime is equivalent. Installation guidance claiming Python 3.8 or universally local/no-external-dependency operation is not supported by the current source.

Source controls include password hashing, OTP hashing/expiry, rate-limiting services, session regeneration, remembered-token rotation, CSRF checks on main/account/admin mutations, source URL/address controls, upload validation, typed processing errors, bounded job recovery, worker supervision, and evaluation snapshots/fingerprints. Their existence does not cancel the separate findings in [KNOWN_ISSUES.md](KNOWN_ISSUES.md). Scheduled-task installation, real worker uptime, successful backups/restoration, production Apache headers, external-provider availability, and representative load capacity remain unverified.

## Functional evidence and its interpretation

| Evidence group | Final observed result | What it establishes |
|---|---|---|
| PHP/JS syntax | 150 files passed | The listed files parse in the tested tools |
| Python confirmation | **600 testcases: 595 passed, 1 failed, 4 skipped; 62 subtests passed** | Scoped regression behavior in an isolated source copy; subtests are not 62 additional independent testcases |
| Python v2 failure | Inline-section-heading fixture omitted the expected outlier definition | A reproducible regression in the optional v2 test path; not a measured failure rate on real documents |
| Legacy cleanup probe | A 64-word ordinary paragraph containing register became unreadable; replacing that word retained the paragraph | Confirmed overbroad noise removal; changing paragraph structure also altered retained content |
| Legacy NIST fixture | Direct summary call returned nonempty structured output | Successful Python-function response for that checked-in fixture, without PHP persistence integration |
| Direct Q&A | Synthetic question returned a passage containing 120 books | Source passage retrieval for one synthetic example, not calibrated answer accuracy |
| Direct Nutshell | Returned a nonempty response object with status insufficient_content and an empty nutshell | Structured failure/insufficient-content response only; **not successful Nutshell generation**. The smoke harness's PASS checks object truthiness, not output quality |
| Blank-first-page PDF probe | Readability rejection occurred before page-reference verification | Inconclusive for page alignment; do not report a proved citation-number defect |
| Backend security/audio fixtures | Three expected-protection assertions failed: other-owner read, anonymous read, suffix-range handling | Confirmed defects using actual handlers/services with synthetic SQLite/audio only |
| Browser | 46 source-template images, 18 matching local actions, 19 matching isolated guard outcomes | Visual/local-control behavior and direct guard branches; bootstrap and real account authentication bypassed |
| Live metadata/static requests | Metadata inspection and three safe no-bootstrap responses succeeded | Actual definitions and limited local PHP connectivity |

The initial Python run encountered temporary-file permission errors in the restricted environment. The confirmation run is the authoritative application test count; both runs remain available for provenance. Four live HTTP translation acceptance tests were skipped because no live result URL was supplied. The 124.26-second regression-suite runtime is not an application performance benchmark. Counts from different evidence groups are intentionally not combined into a misleading system pass percentage.

## Priority and readiness

The consolidated register contains 30 findings/limitations: six High, twenty Medium and four Low engineering priorities. These are scoped observations, not an independently scored risk rating. The six High findings concern document ownership, missing document mutation protections, password-reset revocation, alternate administrator status updates, fresh-install reset schema, and the legacy prose-cleaning defect. The matrix gives the precise source/evidence and expected next verification for each. The optional v2 failure is separately retained and must not be hidden by the overall regression pass count.

Before live user evaluation, remediate those access/identity/schema/input failures, verify a fresh install and upgrade in a disposable environment, and complete account and source-to-result workflows with synthetic accounts/documents. Validate audio/translation/error handling under actual configured providers and privacy rules, reconcile analytics denominators, test guest lifecycle and concurrency, and demonstrate a restore. Then conduct approved human evaluation with genuine participants and raw responses. Source audit, anonymous-presented product feedback, synthetic screenshots and automated test cases cannot substitute for those responses.

## Related audit outputs

- [BACKEND_AND_DATABASE_AUDIT.md](BACKEND_AND_DATABASE_AUDIT.md): PHP architecture, roles, schema, persistence and operations.
- [SECURITY_AND_RELIABILITY_AUDIT.md](SECURITY_AND_RELIABILITY_AUDIT.md): detailed backend findings and fixture reproduction.
- [PYTHON_ENGINE_AUDIT.md](PYTHON_ENGINE_AUDIT.md): active/optional pipelines, extraction, worker and model limits.
- [UI_UX_AUDIT.md](UI_UX_AUDIT.md): pages, local interactions, accessibility/navigation and capture scope.
- [FUNCTIONAL_TEST_RESULTS.md](FUNCTIONAL_TEST_RESULTS.md): test groups, procedures, outcomes and restrictions.
- [SYSTEM_WORKFLOWS.md](SYSTEM_WORKFLOWS.md): cross-layer sequence and trust/persistence boundaries.
- [KNOWN_ISSUES.md](KNOWN_ISSUES.md): consolidated priorities and remediation acceptance targets.
- [EVIDENCE_REGISTER.md](EVIDENCE_REGISTER.md): raw records, fixtures, schemas, screenshots, diagrams and preservation snapshots.

The final manuscript must use those evidence classes consistently. Missing institutional facts, approvals, development history, human response data and research conclusions remain researcher responsibilities rather than inferred application facts.
