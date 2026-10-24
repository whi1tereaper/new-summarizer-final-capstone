# Student Enrollment Registry — implementation and validation

Date: 2026-10-23. This change extends the existing LIGHT application; it does not replace authentication, the admin dashboard, the guest-quota service, or the NLP engine.

Current registration policy: [registry-required-registration.md](registry-required-registration.md) supersedes the original unmatched/pending signup behavior below. New signups now require an active imported ID and activate immediately, or roll back without retaining a pending account.

Later configuration update: at the operator's request, the enrollment-processing flag is now enabled and the launch hold released (`STUDENT_ENROLLMENT_AUTHORIZED=true`). See [registry-activation-report.md](registry-activation-report.md) for the current state and focused HTTP/security retest. The original deployment/hold notes below describe the earlier snapshot.

## Implemented behavior

- The existing Admin Dashboard links to **Student Enrollment Registry**, using the existing navigation, CSS tokens and student-access components. Statistics are database-derived: registry total, eligible active IDs, student applications/accounts, current registry approvals, pending applications and IDs imported in the last seven days.
- One manual ID, pasted newline/comma-separated IDs, and actual multipart CSV/TXT imports use the same bounded parser. Optional UTF-8 BOM and first-line `student_id` header are supported. CSV is IDs only, not a multi-column spreadsheet. Blank lines are ignored; empty comma fields and malformed IDs are reported as invalid. Broken quoting/binary/non-UTF-8 data rejects the upload. Limits: 1 MiB, 10,000 entries, 4,096 bytes per line. File extensions, upload origin, size, MIME and contents are checked. No upload is moved to public storage.
- A server-owned, administrator-bound preview lasts 15 minutes. Its masked sample and up to 20 line-numbered issues precede explicit confirmation. No registry record is trusted at preview time. Confirmation recalculates counts, inserts valid unique IDs, reports existing/in-batch duplicates and invalid rows, reconciles eligible pending applications, and erases the preview payload. Reconfirming the same batch is idempotent.
- Existing IDs are skipped, not overwritten. Inactive/revoked/expired records are never reactivated or renewed by importing the same number again. Eligibility changes and explicit campus-confirmed renewal are separate, version-checked administrative actions.
- Eligible registration/resubmission matches become `verified`, with `approval_method = registry_match`, registry provenance, the confirmed enrollment period and an expiry. No additional ownership document, mandatory email, or manual approval is inserted into this successful-match path. Optional email and existing password authentication remain unchanged.
- Unmatched/ineligible applicants remain pending and restricted, with a generic campus-assistance message. Pending accounts retain the original unused guest allowance; registration never resets the three-success limit. Existing evidenced manual review remains available for unmatched applications; known inactive/revoked/expired registry eligibility cannot be overridden by manual approval.
- Import confirmation approves only current, active, non-administrator, unambiguous pending applications with retained matching details. It does not silently reverse declines, suspensions, corrections or expired/erased applications. Conflicts are recorded for authorized review. Already verified accounts are not rewritten by repeat imports.
- Inactivation/revocation suspends every matching verified application, including a manual approval, without deleting accounts, hashes, summaries, feedback or relationships. Centralized access checks recheck eligibility before generation and successful persistence. Reactivation does not silently restore suspended access. Authorized review can request correction, followed by a matching resubmission on the same account. Renewed expired applications can resubmit; existing access is not silently extended.

## Identity and security boundaries

The existing shared normalizer trims surrounding whitespace, uppercases ASCII letters and preserves leading zeros and separators. Its configurable default is a generic safety pattern, **not a confirmed institutional format or evidence of enrollment**. Campus operators must confirm any broader institutional formats before changing `STUDENT_NUMBER_PATTERN`. IDs such as `00001234` and `1234`, or `ABCD-1234` and `ABCD1234`, remain distinct.

Database uniqueness protects both canonical registry IDs and keyed application duplicate identifiers. A short InnoDB mutation gate serializes registration/resubmission, registry changes/imports and review transitions before account/application locks. Guest accounting remains separate; no database transaction or registry gate is held during NLP processing. A batch commits as one unit, including pending approvals and audit events. Failure rolls it all back and leaves the preview retryable.

Registry endpoints require a fully authenticated administrator session and a current active administrator database role. State changes require CSRF, configured per-administrator rate limits and explicit confirmation. Queries are parameterized. Search is exact, POST-based and session-limited to 15 minutes, so full IDs do not enter query-string access logs. Displays mask IDs, including short IDs. Enrollment and review pages prohibit caching and referrer disclosure. No public lookup or full-list export is provided; CSV formula export injection therefore has no new export surface. Invalid formula-shaped input is rejected by the configured default normalizer.

Conflicts store HMAC identifiers and minimal administrator/account references, not raw IDs in public diagnostics. Review notifications remain in-app and do not include the enrollment list. Student numbers and registry data are never sent to external AI/verification services. No new dependency, Neon/PostgreSQL/Docker service, photo upload, frontend framework, NLP algorithm or output mode was added.

**Residual impersonation risk:** an eligible student number proves inclusion in the administrator-approved list, not the identity of its first claimant. Transactional one-account claims, rate limits, password authentication, optional recovery email, private lists and audited dispute/recovery handling reduce abuse but cannot stop someone who already knows an unclaimed eligible number. Stronger ownership assurance would require an additional campus credential or verification factor; that was deliberately not made mandatory in the requested ID-only workflow. The public generic duplicate response does not disclose an owner's details, but successful versus unsuccessful registration can still imply claim availability. Do not represent this as strong identity proof.

## Schema and migration

`database/migrations/019_student_enrollment_registry.sql` adds:

- `student_registry_gate`: one transaction-lock row, not an enrollment record.
- `student_registry_batches`: private preview, source/provenance, expiry, confirmed enrollment period and import result counts.
- `student_enrollment_registry`: unique canonical ID and keyed identifier, status, period/end date, source batch/admin, status-change metadata and optimistic version.
- `student_registry_events`: import/approval/eligibility/conflict audit, with foreign keys and indexed time/batch.
- Nullable `student_applications.approval_method` and `registry_id`, with a registry foreign key. Existing accounts are not backfilled as approved or imported as enrollment records.

The migration is additive, replay-tested on the installed XAMPP MariaDB and contains no database reset, account deletion or password update. Existing manual approvals retain their prior semantics unless an authorized registry action changes eligibility. MySQL/MariaDB DDL implicitly commits; deploy during a maintenance window using a verified private backup. Oracle MySQL was not separately tested; this installation uses XAMPP MariaDB.

Before deployment, inspect `C:\xampp\php\php.exe tools/migrate.php --status` and `--dry-run`. The pre-change ledger showed 017, 018 and 019 pending even though the 017 tables already existed. Use the migration runner to apply the reviewed pending additive migrations; do not fabricate ledger rows or reset the database.

## Privacy configuration and deliberate launch hold

The published contact is **AI Summarizer System Administrator / Privacy Contact**, using the administrator-controlled project email from existing environment configuration, with `STUDENT_PRIVACY_CONTACT` as an editable override. This contact is **not represented as NEUST's official Data Protection Officer**. No email credential is published.

Registration must remain closed unless all of these are configured:

1. A valid operator privacy email.
2. A securely generated, stable base64-encoded 32-byte `STUDENT_NUMBER_SECRET`.
3. Explicitly confirmed enrollment-data processing authority (`STUDENT_ENROLLMENT_AUTHORIZED=true`). This is an operator attestation to actual authorization, not authorization granted by this software or an import checkbox.

No actual processing authority was supplied in this conversation. Keep that gate false until the responsible operator has confirmed the campus source/authority and lawful processing arrangements. Do not disable the key requirement or assume an SMTP account makes enrollment processing authorized. Protect and back up the key with restricted access; changing it requires an explicit keyed-identifier migration, not a casual environment edit.

Application details retain the existing three-day erasure policy. Committed preview payloads are erased immediately; expired previews are physically cleared by the existing retention job or the next preview operation. The separate trusted registry is not erased by the three-day application cleanup. The operator must define and execute lawful registry/audit/backup retention and erasure, and schedule `tools/student_retention.php --apply`; an ad hoc job does not create a campus retention policy. No indefinite-retention compliance claim is made.

## Executed validation and evidence

Validation is executed by `scripts/qa/run.py` using isolated `qa_light_*` databases and credentials that are demonstrably denied access to the application database. It starts a separate copy of installed XAMPP Apache on loopback 58139, captures test email locally, uses private test storage and the existing Python environment, and removes only the strictly named QA databases/accounts/processes. The normal port-80 application and user NLP daemon are not used for mutating fixtures.

Final result counts and deployment state are recorded below after the last run. Machine-readable evidence: `docs/qa/results.json`, `http-results.json`, `registry-http-results.json`, `browser-results.json`, `registry-php83-results.json`, `backup.json`, `integrity.json`; browser screenshots include `browser-registry.png` and `browser-playback.png`. Earlier run receipts are retained as `attempt-*.json`.

The browser actually imported 1,000 IDs, checked their count in MySQL, proved a pre-existing pending account was approved, registered a new matching student without email/images/additional approval, logged in using that ID and password, generated a real summary and verified its persisted MySQL record. Integration tests additionally imported 3,000 IDs, raced eight independent PHP claimants and overlapping imports/registration, injected a trigger failure mid-import, retried the same batch, tested expiry/renewal/revocation, and verified original password hashes/history remained intact.

Earlier discovered failures/corrections:

- A new partial initially reused the existing dashboard's `$stats` variable, overwriting unrelated counters. Registry variables are now namespaced; final logs and dashboard regressions are checked.
- The first browser test asserted visibility before navigation completed. It now waits for the destination heading before asserting; the complete workflow subsequently passed.
- Apache's generic referrer policy overrode the private page policy. A scoped server-header rule corrects it and is checked through actual HTTP.
- One repeat run hit the existing NLP cold-start time budget in the legacy phase-2 harness. It is recorded as a timeout, not a pass; the final run and remaining runtime risks must be reported honestly. No summarization algorithm was changed to address it.

## Files in this feature

New: registry service; migration 019; `admin_student_registry.php`; dashboard/registry-statistics partials; `student-registry.js`; registry service/race tests; HTTP/CLI QA probes; this report.

Updated: `StudentVerificationService`, `CampusAccessService`, `StudentRetentionService`, `RateLimiter`, environment configuration/example, `TermsContent`, admin dashboard/review/verification partial, registration guidance, student status page, shared `nex-footer.php`, public `.htaccess`, phase-4 migration expectations, QA runner and browser test. New deployment tooling: `tools/deploy_student_registry.php`; read-only preflight: `tools/verify_student_access.php`. Privacy-gate and PHP-8.3 probes live under `scripts/qa/`. Existing unrelated dirty-worktree changes were preserved. No NLP source file was edited for this feature.

## Final results / handoff

Final run: **22/22 test groups passed, zero failed**. The individual checks below total **372 passed / 0 failed**, excluding seven additional successful harness/backup/isolation/cleanup operations.

| Checks | Passed | Failed |
|---|---:|---:|
| Existing PHP security, authentication, analytics, database, feedback, history and guest access | 195 | 0 |
| Registry integration, 3,000-ID import, races, rollback, retention and migration replay | 51 | 0 |
| Privacy/contact/key/processing-authorization gates | 8 | 0 |
| PHP 8.3.29 registry compatibility | 6 | 0 |
| Real Apache HTTP regressions, including text/PDF/DOCX/URL, translation, audio and recovery | 59 | 0 |
| Registry multipart HTTP, contact publication, RBAC, CSRF, injection, revocation and rate limits | 27 | 0 |
| Real browser checks, including the complete 1,000-ID workflow | 15 | 0 |
| Public exposure, Git protection and removed-runtime dependency checks | 10 | 0 |
| Historical reset-contract migration scenarios | 1 | 0 |

The last run produced no PHP warnings/fatal errors in its private Apache/PHP log. PHP 8.3 CLI import/approval/password/duplicate/revocation tests passed; installed XAMPP Apache still runs PHP **8.1.25**. Switching the Apache module to PHP 8.3 was not performed and remains an operator runtime-upgrade consideration, not a claim that Apache is already on 8.3.

After the successful run, `tools/deploy_student_registry.php --apply` applied the reviewed pending **017, 018 and 019** migrations to the configured local database. The wrapper verified the private backup hash and restore receipt, checked that the database had not changed since that snapshot, rejected unreviewed pending migrations, and compared every original table's original columns after deployment. **All original data was preserved at deployment verification**, including **35 users, 255 summaries and 103 feedback rows**, their hashes and relationships. These are that deployment snapshot's counts, not a guarantee that later live activity leaves counts unchanged. Existing migration ledger entries were retained; three new migration records were added. No real enrollment IDs or synthetic fixture accounts were inserted into the live database. See `docs/qa/registry-deployment.json` for hashes and deployment evidence. A later speed benchmark detected concurrent live changes and did not pass its separate source-data stability comparison; see `docs/summarization-speed-report.md`.

Rollback snapshot: `C:\Users\Robert Martin\AppData\Local\light-qa-c_eiaohk\rollback.sql`, 30,124,592 bytes, SHA-256 `b88cbbeb032406a903bf06e2debf19da2320a907d8a4d520683787cf0cbc5f98`. It was restored into an isolated database and all original tables matched. The same restricted folder contains `environment-with-student-key.env`, byte-hash verified against the active environment; neither file is under Apache or Git. A restore would replace database state, so stop writes and assess newer data before an operator-controlled rollback; no automatic destructive restore was performed.

The final read-only access preflight confirms that all nine access/registry tables exist, the project privacy contact and secure matching key are configured, **`enrollment_processing_authorized=false`**, and **`student_registration_configured=false`**. Registration and roster imports intentionally remain closed until actual enrollment-data processing authority is confirmed. The shared footer and terms use the environment-backed project contact and the requested administrator/privacy-contact label, explicitly without an official NEUST DPO claim.

Remaining risks/limits: first-claim impersonation for known unclaimed student numbers; dependence on administrator list accuracy and independently confirmed enrollment periods; institution-specific ID syntax still needing confirmation; operator-defined registry/audit/backup retention and scheduled cleanup; no Oracle MySQL validation; existing Apache/PHP version mismatch; and NLP cold-start/load variability. An earlier repeat attempt timed out in the legacy phase-2 harness. Its budget now accommodates the existing 300-second worker deadline without changing the engine; the final phase-2 run completed in 30.22 seconds. No high-concurrency NLP throughput or fixed response-time guarantee is asserted.
