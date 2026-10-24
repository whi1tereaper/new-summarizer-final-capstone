# Student ID allowlist replacement — validation report

Historical snapshot: the later [student-created account registration update](student-account-registration-report.md) supersedes this report's optional-email/automatic-sign-in behavior and six-counter overview. The registry-only eligibility policy and recorded historical test results below remain applicable to that earlier revision.

Date: 24 October 2026, Asia/Manila. Project: `C:\xampp\htdocs\ai-summarizer-123 - quality upg`.

## Implemented policy

The active administrator-managed MySQL registry is now the only student eligibility authority. Registration displays a fixed `PEN` prefix, accepts the remaining year/number, and checks the complete normalized string. Leading zeros are preserved. An active, unexpired, conflict-free, unclaimed match creates and approves the account atomically with `approval_method=registry_match`. Unknown, inactive, revoked, expired and claimed IDs cannot register. There is one locked eligibility-and-claim lookup; normal account creation, authentication and transaction queries still run.

There is no student photo/document upload, student OTP/email confirmation, secondary identity form or manual approval. Old verification/review GET routes redirect to Profile/Student ID Management; their state-changing POST routes return 410. Historical application states, evidence columns and audit records remain; an internal uncommitted staging state is not a waiting workflow. Legacy manual approval no longer grants access. Normal passwords, password-recovery features, sessions and administrator second-factor authentication/RBAC remain in place.

## Live database and runtime

- All **38/38** requested IDs are present, exact, unique and active. They already existed: initialization inserted **0**, retained **38**, excluded **0**. The roster itself is not embedded in production source or tracked fixtures.
- Additive migration `020_registry_only_allowlist.sql` is applied. It adds policy/reconciliation metadata, a durable conflict flag, unique registry claims and an audit index. All previous 19 migration records remain. Fresh installation and repeat application were tested in isolation.
- One-time initialization records the initial-list digest. Replays preserve later administrator additions and eligibility changes; they do not reset the registry to the original 38.
- Initial reconciliation checked **1** existing student account: **1 already verified**, **0 newly approved**, **0 conflicting**, **0 newly restricted**. **2 administrators** remain exempt. **33 older accounts without a student ID** remain preserved and restricted; they were not granted student privileges.
- A normal live registration occurred during validation. The current snapshot therefore has **37 users**, **2 approved student accounts**, **33 restricted non-administrators**, and **38 registry IDs**. All original **36 user rows/password hashes** are unchanged.
- XAMPP Apache on **port 80 now serves PHP 8.3.29**. Its installed module passed isolated HTTP/browser/NLP tests and the full Apache configuration passed syntax validation. The operator restarted the Windows service after this session was denied service-control access. MySQL/MariaDB remains on **3307**. No active application PHP branch requires the removed database/container services.
- The existing configurable privacy contact, student-number protection key and operator enrollment-processing authorization remain enforced. They were not bypassed.

## Administrator interface

The existing dashboard opens **Student ID Management**, using the established layout and violet accents. Six database-backed counters show registry totals, active eligible IDs, registered students, unclaimed IDs, rejected registry/account registration attempts and IDs imported this week. Historical inactive/revoked records remain in totals; disputed claims are visibly restricted and excluded from eligible counts.

Working controls include individual insertion, bulk paste, CSV/TXT uploads, server-validated preview followed by explicit confirmation, duplicate/invalid-row feedback, exact private search, filtering, pagination, eligibility changes, reconciliation, import history and conflict history. Limits are 10,000 entries, 1 MiB and 4,096 bytes per line. Preview payloads expire after 15 minutes and are erased on confirmation/cleanup. Re-import alone cannot reactivate a revoked/inactive record.

All changes require an authenticated administrator, CSRF and existing rate limits. Prepared queries, a shared mutation lock and database uniqueness prevent double claims. Approval is never based on client-supplied roles/statuses or syntax alone. Disputed historical claims remain restricted for operator resolution; no automatic ownership transfer is provided.

## Executed validation

All mutating acceptance tests used restricted, production-denied QA credentials, isolated databases and private storage. Test-only synthetic IDs were never inserted into the live registry. The four requested negative examples were never inserted even into the QA approved registry.

| Executed suite | Passed | Failed |
|---|---:|---:|
| Registry backend, all 38 signups, reconciliation, imports and races | 158 | 0 |
| PHP 8.3 Apache HTTP registration/login/admin/security workflows | 78 | 0 |
| Actual mobile/desktop Edge browser workflows | 16 | 0 |
| Existing PHP security, dispatch, analytics, database, feedback and history regressions | 135 | 0 |
| Real Python summarization, result rendering and MySQL/history persistence | 6 | 0 |
| Functional assertions total | **393** | **0** |
| Live port-80 HTTP smoke checks | 4 | 0 |
| Live read-only registry/configuration/admin checks | 6 | 0 |
| Public-file/Git/database-failure security checks | 10 | 0 |

The final runner completed **10/10 orchestration stages** successfully, including production-denied credentials, live-integrity verification against the post-retention starting snapshot, and isolated database/user cleanup. Additional syntax checks: **160 PHP files**, **3 JavaScript files**, and the installed Apache configuration passed.

Coverage includes all 38 initial IDs registering through real services and logging in over HTTP; optional username/email left blank in a real browser; successful immediate access; duplicate, unknown, malformed, inactive and expired IDs; forged client status/role values; retired endpoints; ordinary-user/anonymous administrator denial; administrator second factor; CSRF; rate limiting; CSV/TXT and unsafe-file rejection; 3,000-ID backend and 1,000-ID browser imports; preview/confirmation; mid-import rollback; duplicate imports; dynamic account status; keyboard/mobile layouts; immediate revocation/reauthorization; and existing-account reconciliation. Eight concurrent registrations produced one claim; eight concurrent guest requests produced exactly three completions, with failed processing consuming none.

Real NLP used the unchanged existing Python engine and a pasted-text article; the final sample completed in **11.98 seconds**. Timing is recorded in `docs/qa/allowlist-replacement/nlp-results.json`; it is one functional sample, not a general speed benchmark. This replacement run does not claim fresh PDF/DOCX/URL, translation, audio or live-mail password-recovery acceptance. No real password was reset for testing.

Receipts: `docs/qa/allowlist-replacement/{results,backend-results,http-results,browser-results,core-results,nlp-results,live-http,live-state,integrity}.json`; file/Git checks: `docs/qa/security-results.json`. Earlier attempts remain separately recorded. Initial defects found and corrected were a nullable CLI reconciliation actor, an outdated 19-migration test expectation, and disputed-claim display/statistics. One run was interrupted by the server restart; only its explicitly identified QA schemas/user were removed and rebuilt. QA cleanup now retains its private recovery configuration if cleanup is interrupted.

### Data-retention exception explicitly accepted by the operator

The original private backup was restored into isolation and compared against live rows. **18/20 table comparisons matched all original rows**. Two strict comparisons did not: live summaries changed **221 → 208**, and artifacts **212 → 199**. The missing **13 summaries are anonymous guests**, all older than 24 hours and all matching the pre-existing expired-session cleanup conditions; none belong to registered accounts. **All 155 original account-owned summaries, all 36 original users/passwords, all 107 feedback records, all 113 feedback comments, and the original document/evaluation records are preserved.**

The operator explicitly chose **“Keep the existing 24-hour guest retention policy.”** No restore or cleanup-policy change was made. The backup still contains the expired rows. These two strict historical comparisons are **not** reported as identical or silently converted to passes. See `restored-baseline-integrity.json` and `retention-decision.json` in the receipt directory. The final isolated run checks live integrity against its own post-retention starting snapshot; it does not undo this documented historical difference.

## Changed files

Runtime changes for this replacement, excluding unrelated pre-existing edits:

- Services: `app/src/Services/{CampusAccessService,StudentVerificationService,StudentEnrollmentRegistryService}.php`.
- Authentication/policy: `app/src/Controllers/AuthController.php`, `app/src/Utils/TermsContent.php`.
- Existing routes: `app/public/{register,login,profile,student_verification,admin_student_review,admin_student_registry,admin_dashboard}.php`.
- Existing partials: `app/public/partials/{student-application-fields,student-trial,site-nav,registry-statistics,admin-student-registry,admin-student-verifications}.php`.
- Assets: `app/public/assets/css/student-access.css`, `app/public/assets/js/student-access.js`. The existing `student-registry.js` interactions were reused and tested.
- Migration/operator tools: `database/migrations/020_registry_only_allowlist.sql`, `tools/{initialize_student_allowlist,verify_student_allowlist}.php`.
- QA: `scripts/student-access/{test_registry_only,allowlist_race_worker}.php`; `scripts/qa/run_allowlist.py`, `allowlist_http.py`, `allowlist_browser.cjs`, `allowlist_core.php`, `allowlist_nlp.py`, `allowlist_probe.php`, `allowlist_runtime.php`, `allowlist_restore_integrity.php`; migration-catalog expectation in `scripts/test_phase4_database_lifecycle.php`.
- Local server configuration: `C:\xampp\apache\conf\extra\httpd-xampp.conf`, `C:\php83\php.ini` (absolute extension/session paths, compatible memory/upload/time limits, hidden PHP errors, disabled unused PostgreSQL extensions).

No summarization algorithms, existing password hashes, account roles or unrelated UI were rewritten. The existing user repository and retention service were inspected/reused, not changed by this replacement.

## Deployment and rollback

The live migration and initial-list initialization are already applied. For another deployment: first create and restore-verify a private database backup, run `C:\php83\php.exe tools\migrate.php`, and supply a private UTF-8 ID-only list through STDIN to `tools\initialize_student_allowlist.php` (dry-run first; `--apply` to initialize). Never pipe the whole formatted request into that tool. After initialization, extend/manage the registry through the administrator page. Use `tools\verify_student_allowlist.php` with the same private list for read-only validation. Do not commit the roster, environment or SQL backup.

Verified pre-change SQL backup: `C:\Users\Robert Martin\AppData\Local\light-qa-vrda_z3l\rollback.sql`, **29,130,080 bytes**, SHA-256 `0ca53fbdf43e20503ec078335e5166155aeea6339b9aafa80d84871b6c4f37a6`. All tables were originally restore-verified. Later integrity checks also restored it only into ephemeral QA databases and removed those copies afterward.

Private source/configuration copies: `C:\Users\Robert Martin\AppData\Local\light-allowlist-replacement-5cd5022d`. They include the touched existing application files, environment copy, old Apache configuration and PHP 8.3 INI. Both private locations have operator-only ACLs, are outside Apache/Git, and the normal in-project backup paths are HTTP-blocked/Git-ignored.

Rollback must be reviewed, not automatic: preserve newer legitimate live registrations and activity; restore only the appropriate backed-up files or specifically approved affected rows. Do **not** overwrite the whole live database from the pre-change dump. The additive tables/indexes may remain for a code rollback; dropping constraints/metadata requires a reviewed migration and backup. Restoring the old Apache configuration requires an administrator-controlled service restart.

## Remaining limitations

A registry match proves list eligibility, **not rightful identity**: someone knowing another student's valid unclaimed ID can register first. No prohibited extra verification was added to conceal that limitation. Rejection distinctions and distributed guessing cannot be perfectly hidden, though rate limits, private registry pages and generic claim-conflict messages reduce exposure. Conflicting claims require authorized operator resolution and never auto-transfer ownership. Retention/revalidation and private-backup disposal remain operator policy responsibilities. No release blocker remains for the requested registry workflow; the historical guest-retention difference above is an explicit operator-accepted exception.
