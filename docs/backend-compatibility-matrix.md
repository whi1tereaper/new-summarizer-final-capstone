# LIGHT backend reconstruction and compatibility matrix

Updated 2026-10-13. This is an implementation-status record, not a claim that
the legacy backend has been replaced. The production route and persisted data
remain authoritative until each replacement is integrated and verified.

| Capability | Current implementation | Reconstruction status | Compatibility evidence | Activation / rollback |
| --- | --- | --- | --- | --- |
| Summary generation | `LocalPythonBridge` to the legacy Python pipeline by default; the unified `light_engine` is an alternate worker path | **Parallel replacement; not approved for cutover** | Python regression suite; `scripts/test_summarizer_intelligence_update.php`; an exploratory three-NASA-article comparison now records exact per-output results, but it remains a single-publisher proxy study without human review ([report](independent-article-pilot-20261013.md)) | `SUMMARIZER_ENGINE_VERSION=legacy` remains the default and rollback path |
| Authentication and registration | `AuthController` preserves the existing form/action routes, session lifecycle, CSRF, rate limiting, terms and remember-me flows | **Persistence partially decoupled**: user reads/writes now use `Repositories/UserRepository.php`; orchestration remains in the existing controller | `scripts/test_auth_full_verification.php`: 15 passed, including duplicate-registration behavior, login/reset/logout paths, and repository persistence | No route or schema change; retain the existing auth controller |
| Password recovery | Existing `PasswordResetService`, email service and OTP flow; user lookup/update delegated to `UserRepository` | **Legacy workflow with repository-backed account writes** | Auth integration script; mail delivery and production SMTP remain deployment-dependent | Existing `auth.php` actions remain in place |
| RBAC and authorization | Existing role/session checks, `AdminSecurityService`, `EvaluationAccess` and endpoint-specific guards | **Not rebuilt** | Phase 1/2/3 and evaluation authorization suites exist; do not infer complete route coverage from helper tests alone | Existing role model and guards remain active |
| Database access | `Database` PDO singleton; most existing services still issue their own SQL | **Not rebuilt**. The new repository currently covers authentication persistence only | Migration lifecycle suite and service-specific database tests | No schema/table replacement; existing records preserved |
| Schema lifecycle | Ordered, additive SQL migrations; runtime DDL scan is part of phase-4 checks | **Existing lifecycle retained**, not a new database engine | `scripts/test_phase4_database_lifecycle.php`; verify against target database before deploying pending migrations | Migrations are additive; use the documented restore/rollback procedure |
| Summary jobs and Python bridge | Existing processing/session contract and legacy worker; v2 bridge is additive | **Partial parallel reconstruction**; request/job orchestration is not replaced | `scripts/test_phase2_decoupling.php` passes 21/21 controller/request-boundary checks; worker daemon and summarizer integration tests also exist; no full website cutover test for v2 | Keep legacy selector; no legacy worker removal |
| Upload and source extraction | Existing PHP upload validation/source resolver and extraction paths; v2 adds constrained local PDF/DOCX/text handling | **Partial parallel implementation** | v2 extraction fixtures and PHP HTML extraction fixture; live publisher and complex PDF checks remain outstanding | Existing supported input paths remain; select v2 only for controlled testing |
| History and document records | Existing `HistoryService`, `ArticleService`, summaries/artifacts and document-summary tables | **Not rebuilt** | Existing history/document regression scripts; migration preserves historical rows | Existing tables and routes remain authoritative |
| Analytics | Existing analytics controllers/services and landing metrics | **Not rebuilt** | Existing analytics/phase-3 checks; no replacement parity run claimed | Existing analytics endpoints and records remain active |
| Feedback | Existing feedback controller/handler/service and schema | **Not rebuilt** | Feedback-specific regression scripts exist; not rerun as part of this continuation | Existing feedback writes remain active |
| Admin dashboard and operations | Existing controllers/services, role gates, audit trail and maintenance actions | **Not rebuilt** | Phase-1 and phase-3 suites; administrative workflows still need complete live-site regression before any replacement | Existing admin paths remain active |
| Audit trails | Existing `AdminSecurityService` writes to the current audit table | **Not rebuilt** | Current security/admin tests; repository-wide transaction and retention review outstanding | Existing audit records are retained |
| Human quality evaluation | Additive evaluation datasets, outputs, baselines, ratings, facts and blinded assignments | **Implemented as a separate research subsystem**, not a production summarizer or backend replacement | Evaluation implementation audit records earlier engineering checks and database runs; no recruited human ratings or approved human references exist | Evaluation is isolated from ordinary summaries and analytics |
| Frontend and user-facing contracts | Existing LIGHT pages, forms, processing/results, history and admin interfaces | **Preserved; not reconstructed** | Prior UI audit and browser regression records; this task makes no frontend edits | No redesign or new user-facing contract in this change |

## Current scope and next gates

This continuation creates a typed account-persistence boundary without
changing route names, forms, account schema, role values, session variables or
redirect destinations. `AuthController` still owns validation, session and
CSRF orchestration. A non-unique database failure during registration is now
allowed to surface as an application error instead of being presented as a
duplicate-account message.

The matrix intentionally marks other PHP subsystems as legacy until a
replacement is implemented and exercised against the actual site and existing
database. A passing isolated test, a parallel v2 route, or this matrix alone
does not authorize deletion or activation. Each module needs feature-level
parity, authorization and failure-path tests, record-preservation checks,
end-to-end website verification, and a tested rollback plan.

For v2 summary quality, exact source-span matching is a provenance invariant,
not a factuality score. Literal fact screens are review aids; complete
source-grounded checks require verified source-specific information units and
human review of outputs, including errors and omissions. No human review is
reported until blind reviewers submit ratings.
