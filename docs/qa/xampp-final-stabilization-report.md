# XAMPP final stabilization report

Date: 2026-10-23, Asia/Manila.

**Release decision: NOT READY for real student rollout.** All 18 final isolated command groups passed. Production still lacks the designated privacy contact and stable student-number protection secret, so real student registration correctly remains closed. The live migration ledger also reports 017 and 018 pending; QA did not modify it.

Controlled capstone workflows with synthetic accounts and manual approval are verified. Real student use requires the responsible operator's configuration and campus verification process.

## Evidence and reproduction

- [Command results/output](results.json): 18 groups, all exit 0; approximately 124 seconds.
- [HTTP results](http-results.json): 59 passed, 0 failed.
- [Browser results](browser-results.json): 9 passed, 0 failed.
- [Security results](security-results.json): 10 passed, 0 failed.
- [Integrity comparison](integrity.json): all 36 production tables unchanged.
- [Backup/restore receipt](backup.json): restored fingerprints matched all source tables.
- [Audio browser screenshot](browser-playback.png): synthetic test content only.

Earlier unsuccessful attempts remain in attempt-*.json and are excluded from final pass counts.

Run from the project directory:

    & '.\.venv-nlp\Scripts\python.exe' scripts\qa\run.py

The runner creates restricted credentials, allowlisted disposable databases, a separate loopback XAMPP Apache process, local SMTP capture, and an authenticated NLP supervisor. It removes only its processes, databases, test user, captured mail, and temporary credentials after testing. Standalone mutation suites now reject unallowlisted connections; invoke them through this harness.

XAMPP Apache and C:\xampp\php\php.exe use PHP 8.1.25. The separate shell php command is 8.3.29. Final workflows explicitly used XAMPP PHP 8.1.25, Apache 2.4.58, MariaDB 10.4.32 at 127.0.0.1:3307, and the existing Python 3.11.9 environment. Normal Apache remains on port 80.

## Isolation, rollback, and integrity

Port 53319 had no fixture listener. The old bootstrap also referenced removed PostgreSQL infrastructure. A separate MySQL server is unnecessary: tests now use qa_light_<8 hex> databases and exact allowlisted fresh-install/restore names on XAMPP port 3307.

Before mutations, the guard checks the selected database identity and proves its restricted credentials cannot read ai_summarizer.users. Privileges are scoped to the test databases. Root credentials are used only by the CLI setup/backup/cleanup controller.

| Production data | Before | After |
|---|---:|---:|
| Users | 35 | 35 |
| Summaries | 255 | 255 |
| Feedback | 103 | 103 |
| Student applications | 0 | 0 |

Sorted per-row SHA-256 fingerprints matched every row and field in all 36 tables, including password hashes, relationship values, reset records, feedback, analytics, and audit history. No production passwords were changed or production test summaries created during this stabilization pass.

Final rollback dump, outside Apache's document root:

    C:\Users\Robert Martin\AppData\Local\light-qa-hmr8vpq8\rollback.sql

Size: 30,124,592 bytes. SHA-256:

    458eecd899eabb1fcc38ac88cc49bcf56fe860e15b8bf6271339b2a88aa2c7b5

The dump was restored into a separate database and all 36 tables compared before tests. Its private folder has inheritance removed and grants the current Windows user full control. After the run, a server check found zero QA databases/users and no test listeners.

The earlier ai_summarizer_xampp_20261023_190448.sql dump was moved from storage/backups to:

    C:\Users\Robert Martin\AppData\Local\LightSummarizer\Backups\ai_summarizer_xampp_20261023_190448.sql

Its SHA-256 matched before/after the move. Destination inheritance was restricted. No backup contents were discarded, and unrelated backup folders were left intact. The live database was never restored or reset.

## Before and after tests

| Suite | Earlier report | Final executed result |
|---|---|---|
| Phase 1 security | 19 passed | 19 passed, 0 failed |
| Phase 2 dispatch/summarization | 20 passed, 1 AUTH_REQUIRED failure | 21 passed, 0 failed |
| Phase 3 admin/analytics | 22 passed | 22 passed, 0 failed |
| Phase 4 lifecycle/migrations | 17 passed, 1 catalog failure | 18 passed, 0 failed |
| Feedback hardening | 48 passed | 48 passed, 0 failed |
| History repository | 7 passed with existing user assumption | 7 passed with an isolated owner |
| Student access | Blocked by fixture configuration | 60 passed, 0 failed |

These total 195 service/security assertions. A separate migration compatibility check passed both historical reset schemas and repeat application. Fresh installation applied all 18 MySQL migrations. Migration 017 was replayed through its CLI runner successfully.

## Defects and corrections

1. **Phase 2 fixture:** manually built jobs omitted access_visitor and used a fabricated guest token. The fixture now carries a real random guest token and server-created trial identity. Guest access remains subject to production authorization; no student/admin privilege was fabricated.
2. **Migration catalog:** the old expectation stopped at 016. It now includes the existing access migration 017 and new justified reset migration 018. Production migration records were not invented or rewritten.
3. **Hidden full-name validation:** validateSubmission still rejected missing names before its early return. Removed that check, unreachable image validation, and unused image parameters/state. Student-number verification accepts only the number as identity information. Registration retains password/terms requirements; email remains optional.
4. **Student-ID login:** the repository previously searched username/email only. It now resolves normalized student numbers using their keyed duplicate identifiers and still verifies the existing password hash. Optional-email placeholder identifiers are random rather than unkeyed hashes of student numbers.
5. **History/remember-me fixtures:** hardcoded production user ID 12 caused isolated foreign-key failures. Tests now create and clean up their own users.
6. **Database health:** MariaDB native prepares rejected SHOW TABLES LIKE ?, hidden inside HTTP 200. Replaced it with parameterized information_schema metadata lookup. HTTP tests require the payload's status to be OK.
7. **Password-recovery schema:** base installs defined used, while the service expected used_at. Additive migration 018 adds used_at where absent and preserves consumed-token state. Timestamp-only deployed records remain intact. Both shapes and replay were tested. Reset validation now uses a transaction and locking read; HTTP tests reject consumed OTP reuse.
8. **Audio path mismatch:** Piper's environment loader overwrote explicit PHP/process paths with repository .env values. It now uses setdefault, keeping generation/playback in the same cache. Only configuration precedence changed; NLP/synthesis algorithms did not. Protected audio now uses private/no-store caching.
9. **DDL transaction claim:** the access migration runner no longer promises rollback around MySQL DDL, which implicitly commits. Failure messages accurately describe possible partial application.
10. **Legacy compatibility:** removed unreachable PostgreSQL queries from analytics, feedback, guest sessions, rate limiting, reset/remember-me, quota accounting, metadata endpoints, landing analytics, and Nutshell. Removed unused database compatibility methods. Existing MySQL SQL/bindings were preserved except for the demonstrated metadata-query defect.
11. **Obsolete tools:** backup commands use the XAMPP backup/restore rehearsal; the access verifier is MySQL/read-only. They no longer import deleted classes or invoke containers.
12. **Exposure:** project-root rules now deny scripts and all virtual-environment directories. Backup/credential browser and Git protections were executed.

Application changes include AuthController, UserRepository, the services above, app/config/config.php, admin_db_health.php, register.php, student_verification.php, TermsContent.php, .htaccess, and python-engine/piper_tts.py. Test infrastructure is in scripts/qa, repaired phase/history/student fixtures, and database/migrations/018_password_reset_contract.sql. No framework, UI redesign, or summarization-algorithm change was introduced. Pre-existing unrelated worktree changes were retained.

## HTTP and browser validation

The 59 HTTP checks used a separate XAMPP Apache process on loopback port 58139, real application routes, restricted MySQL credentials, and the existing Python engine.

Verified: number-only registration without name/email/images; duplicate rejection; pending/declined restrictions; admin password plus captured email code; dashboards, DB health, audit log, analytics and feedback feed; protected student review; CSRF denial; evidence-required approval; decline/notification; stale-review rejection; immediate access changes; pasted-text/PDF/DOCX/NASA URL processing; MySQL persistence/history; foreign-result denial; feedback; Filipino translation; local WAV generation/range playback; foreign-session audio denial; three actual guest successes/fourth denial; document-API enforcement; registration after exhaustion without quota reset; pending-account Nutshell denial; recovery; consumed-OTP denial; student-ID/password login; session rotation; logout; and HttpOnly/SameSite cookie attributes.

Nutshell's existing anonymous-account requirement returns 401. Exhausted pending-account denial is independently verified with 403. Tests distinguish these restrictions rather than changing the production access policy.

Browser checks exercised mobile registration/overflow, account login, dashboard/history, student-ID login, the existing generated-WAV fallback with browser decoding, and admin email-code login/dashboard. No page script exceptions occurred.

Test conditions are explicit: remote Google Fonts were suppressed in the browser context, and unavailable Web Speech was simulated to test the existing WAV fallback. OS voice availability, speaker output, and subjective pronunciation were not certified. The frontend was not modified for these tests.

## Student access and privacy

The 60 isolated checks cover successful-only atomic quota debit, failure/rollback release, idempotency, expiry, lease recovery, direct-API CSRF enforcement, cached history at zero allowance, duplicate ID claims, admin-only review, approval evidence, audit/notifications, stale decisions, suspension during processing, expiry, role spoofing, inactive users, ownership, resubmission, and three-day detail deletion.

Eight independent connections raced against one guest identity. Exactly three completed; five received expected denials. No fourth success was admitted.

No trusted NEUST enrollment registry is connected. Manual approval requires administrator-recorded campus evidence and an ownership check; format matching never grants approval. Test evidence was explicitly synthetic. Real enrollment/ownership cannot be established by these tests, and manual review cannot eliminate impersonation.

## Translation, audio, and email

Filipino translation returned HTTP 200 and changed the English input through the real configured translation path. It still depends on its external translation provider/network; local database/authentication does not make translation offline.

Local audio returned RIFF/WAV data with HTTP 200, HTTP 206 for a 44-byte range, and denial to another session. Browser decoding through the existing fallback passed. Installed Piper was used; no packages/models were installed for QA.

Admin codes/recovery OTPs went to loopback SMTP capture on port 58125 and were used through normal forms. No test emails were sent to real users. Production SMTP delivery was deliberately not tested. Unauthenticated/unencrypted SMTP capture is restricted to loopback; authenticated TLS remains the default.

## Security evidence

The ordinary port-80 project URL returned 403 for .env, .git/config, the old backup path, the QA controller, and the NLP virtual-environment configuration. Git ignores active credentials and SQL backups; neither was tracked. The new dump is outside the web root/repository.

The application PHP scan found no Neon authentication, PostgreSQL driver/SQL, or container runtime branches. Simulated MySQL unavailability returned the generic local-MySQL error and did not select another database.

Earlier harness failures included a missing access identity, hardcoded user IDs, an Apache override setting, a Python filename shadowing the HTTP library, assumptions about Web Speech and anonymous Nutshell, and a test supervisor deadline below PHP's budget. These were corrected without relaxing application security. An intermediate NASA fetch timed out; the final live URL case passed on its first attempt. A single recorded retry is permitted for transient URL fetch errors.

## Remaining release gates

| Severity | Status | Requirement |
|---|---|---|
| Major | Blocks real student rollout | Production STUDENT_PRIVACY_CONTACT and STUDENT_NUMBER_SECRET are absent. The read-only verifier reports privacy_contact_configured, student_key_configured, and student_registration_configured false. Supply the actual responsible contact and generate/protect a stable base64-encoded 32-byte secret. No contact was invented or existing key rotated. |
| Major | Deployment pending | Live migration dry-run reports 017/018 pending. Access tables exist, but QA did not modify the ledger or execute live migrations. Review the verified backup and additive deployment plan before applying. |
| Major for unattended verification | Institutional dependency | No trusted registry exists. Real approvals require authorized campus records and ownership checks. Automatic enrollment verification is not claimed. |
| Minor | External availability | Source URLs, translation, and remote typography can depend on network services. Earlier source timeouts were recorded. |
| Limitation | Controlled credentials | Existing controlled accounts authenticated through HTTP/browser. Passwords for the 35 real accounts were not supplied or guessed. Their full records/hashes remained unchanged. |
| Limitation | Recovery policy | Accounts without a real email cannot receive email recovery and require operator assistance. No default passwords or fabricated ownership proof were introduced. |
| Limitation | Operator acceptance | Manual review, anonymous-cookie reset limitations, real SMTP delivery, and campus privacy/retention rules require operator acceptance. Campus authorization is not inferred. |

No final automated test remains blocked by MySQL fixtures. No open critical code defect was reproduced in the final run.

## Deployment handoff

Keep XAMPP Apache/MySQL on ports 80/3307 and the existing .venv-nlp interpreter. Normal site:

    http://localhost/ai-summarizer-123%20-%20quality%20upg/app/public/

Use explicit XAMPP PHP for operator commands:

    & 'C:\xampp\php\php.exe' tools\verify_student_access.php
    & 'C:\xampp\php\php.exe' tools\migrate.php --dry-run

After the operator supplies the contact/key and reviews the verified 017/018 migration plan, rerun the verifier and isolated QA command. The decision remains **NOT READY** for real student release until those configuration/deployment gates are resolved; synthetic capstone workflows are verified.
