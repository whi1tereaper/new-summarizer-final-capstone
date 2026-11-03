# Student registration and safe account claiming

Validation: 24 October 2026, Asia/Manila. Existing PHP 8.3 / XAMPP Apache / MariaDB installation; no frontend redesign or new registration workflow.

**Status:** backend implementation is ready and all 522 isolated functional assertions passed. **Live migration 021 has not been applied.** Deployment is held for operator direction concerning an unrecognized live password-recovery event described below. Ordinary existing registration remains available; the new explicit placeholder metadata requires migration 021 before live use. This is not a claim of completed production rollout.

## Root cause and live findings

The registry already allowed a new account for an eligible ID without an owner. However, the registration service rejected **every linked application** as an established account. There was no reliable way to distinguish an unused, credential-free placeholder from a legitimate account.

The pre-change live inventory contained **40 users, all with nonempty password credentials**, including five student applications. No live record qualified as a credential-free placeholder. Those established accounts must continue to use Sign In or existing password recovery; their passwords/emails must not be replaced by another Create Account submission. A previously reused email also remains unavailable under the existing uniqueness rule. This update does not release or recreate any existing student's account.

## Backend behavior

- Active, unexpired, conflict-free approved ID without an account: create one ordinary student account using the supplied, validated email and securely hashed password; activate it immediately through `registry_match`.
- Approved ID linked to a reliably unclaimed placeholder: update the **same** user and application, store the chosen email/password, accept the existing terms, link the registry, mark claimed and activate atomically. Preserve username, original creation date, summary artifacts/history, feedback and trial relationships.
- Established account, ambiguous owners, unknown/inactive/revoked/expired registry entry: reject without changing credentials or creating an approved ID. Public messages do not disclose the other account's details.
- Successful creation/claim returns to the existing Sign In success screen. Immediate login works by full Student ID, chosen email or the preserved username. No administrator approval, photos, student OTP or email confirmation was added.

The existing global registry mutation gate, row locks, unique student/registry ownership constraints and one transaction cover credentials, account state, verification, terms, trial link and audit. A failure rolls the entire claim back. `placeholder_claimed` is a separate, privacy-safe audit action; client role/status/claim-state fields are not trusted.

### What qualifies as a placeholder

Migration **021_student_placeholder_claims.sql** adds `student_applications.account_claim_state`, constrained to `claimed`/`unclaimed`, default **claimed**. It does not alter any existing password, email, active flag, relationship or Student ID. Existing accounts are deliberately not inferred to be available from their age, pending status, disabled state or an example email.

Only trusted provisioning/verified legacy provenance may explicitly label a genuinely unused record `unclaimed`; the public form cannot do so. Claiming additionally requires an inactive ordinary user, an **exactly empty password hash**, no accepted terms/timestamp, a pending/resubmission historical application and no remember-token, recovery-token or completed registration/claim history. Identifier/campus/registry consistency is checked. Nonempty legacy or malformed credentials are protected just like modern hashes. Do not clear credentials or mark a real account unclaimed to bypass registration errors.

No production record was converted to an unclaimed placeholder. Ordinary registry imports still authorize numbers without creating shell users. This metadata is not a new approval step for normal registration.

Maintenance leaves explicitly unclaimed records unapproved instead of suspending safe inactive shells before they can be claimed. Detail retention still erases visible old identity details, but retains the minimal protected owner fingerprint for an unclaimed shell, preventing duplicate/orphaned accounts. Central summarizer authorization also rejects an unclaimed marker even if stale active/verified flags exist. The existing 24-hour guest-summary retention policy is unchanged.

## Frontend and configuration preservation

All **107 files under `app/public`** were compared by SHA-256 against the private pre-change snapshot and were byte-identical. Create Account, Sign In, field names/request structure, optional username field, CSS, JavaScript, footer and responsive layouts were not edited. NLP algorithms and mail configuration were not changed. The mandatory operator privacy contact, protected Student ID key and authorization to process enrollment records remain enforced.

Evidence: [source and rollback checks](qa/student-account-claim/source-rollback-integrity.json).

## Executed tests

| Suite | Passed | Failed |
|---|---:|---:|
| All 38 original IDs, registration, registry management and races | 164 | 0 |
| Placeholder claims, credential guards, rollback, retention and eight-way race | 54 | 0 |
| Actual Apache HTTP registration/login/administrator security | 79 | 0 |
| Required-email registration, validation, database outage and rollback HTTP | 27 | 0 |
| Existing-form placeholder claiming, login, history, CSRF and concurrent HTTP | 32 | 0 |
| Real mobile/desktop browser, including JavaScript-disabled signup/login | 25 | 0 |
| Existing PHP security, analytics, migrations, feedback and history | 135 | 0 |
| Real Python pasted-text summary, MySQL result storage and history retrieval | 6 | 0 |
| **Functional assertions** | **522** | **0** |

The final clean run passed all **13 orchestration stages**, including production-denied credentials and cleanup. Both eight-way service races (new account and placeholder) allowed exactly one owner; the actual two-request HTTP placeholder race allowed one success and one rejection. Claims preserved the same user/application IDs and byte-identical summary/artifact/feedback/trial records. Failure injected after credential update restored the original shell state; retry succeeded. Already claimed, credential-bearing, ambiguous, inactive, revoked and unknown IDs could not be taken over. Duplicate/invalid email, password mismatch, weak passwords, SQL injection, CSRF, forged client roles/claim states, immediate login, session rotation and logout were exercised.

All mutations occurred in isolated MySQL schemas with credentials denied production access. A separate loopback Apache/PHP 8.3 process exercised the unchanged forms and a loopback SMTP capture prevented real mail delivery. The outage test did not stop live MySQL. Temporary test processes, database users/schemas, private roster/configuration and captured mail were cleaned up. Initial harness issues (a verified fixture missing its required expiry and a Python function shadowing the concurrency module) were corrected before the full clean rerun; earlier attempt receipts remain for traceability.

Additional checks: **4/4** read-only live port-80 page/RBAC checks and **3/3** source/backup checks passed. All 13 changed/added PHP files linted successfully; all three changed/added Python scripts compiled. No production registration POST, real password reset or outbound mail was performed by this task. The live read-only registry verifier confirms **38/38** initial IDs active and conflict-free, the mandatory deployment configuration, five existing approved students and two exempt administrators. It reports **5 passed, 1 failed** because migration 021 is intentionally still pending—not because a new ID was missing.

Receipts: [functional summary](qa/student-account-claim/summary.json), [full run](qa/student-account-claim/results.json), [live registry](qa/student-account-claim/live-registry.json), [live HTTP](qa/student-account-claim/live-http.json). Individual backend, HTTP, browser and NLP reports/screenshots are in the same directory.

## Live integrity exception and deployment hold

The first full run detected one live user-row difference from its starting snapshot. Restoring the **original pre-change SQL backup only into isolation** and comparing 27 table sets confirms:

- All 40 original users still exist. For account **141**, only `password_hash` differs; the other original user fields are unchanged. No hash value is published or restored.
- All original **210 summaries**, **201 artifacts**, **107 feedback records**, **113 feedback comments**, original documents/evaluation data and student-registry relationships survive unchanged. Additional live summaries were observed; they were not removed.
- The original **38 registry rows** and five student applications remain intact. **26/27** original-backup table comparisons pass; the `users` comparison remains a real exception, not waived.

Read-only live evidence shows a Forgot Password request at **09:10:30**, a `resetPassword` POST at **09:11:21**, and account 141's recovery-token `used_at` at **09:11:22**, all on 24 October 2026, Asia/Manila. This supports a live recovery event corresponding to the hash change; it does **not** prove the caller's identity or authorization. The operator answered **“No known password change.”** General query logging and binary logging are off, so these checks cannot establish the SQL actor from those logs. The application's login verifier does not automatically rehash passwords. The new claim path cannot update this live account: existing records default to claimed, and its nonempty credentials independently block claiming.

The subsequent complete isolated rerun preserves its later live baseline and passes cleanup; that does not make the older backup comparison pass. Current credentials were left untouched. No automatic rollback, password reset, account suspension or new logging configuration was performed. Deployment is held pending operator direction: investigate the unrecognized recovery with the account owner, or explicitly authorize proceeding with the **schema-only** migration while preserving the changed credentials. Do not infer an account compromise or an authorized change solely from this evidence.

Evidence: [original-backup comparison](qa/student-account-claim/restored-baseline-integrity.json), [sanitized recovery audit](qa/student-account-claim/live-password-change-audit.json), [later-run baseline preservation](qa/student-account-claim/integrity.json).

### Migration after operator direction

The executed read-only migration plan shows exactly one pending migration:

```powershell
C:\php83\php.exe tools/migrate.php --dry-run
# PENDING: 021_student_placeholder_claims.sql
```

After resolving/accepting the deployment hold, run `C:\php83\php.exe tools/migrate.php` from the project root, then `--status`, the private-roster read-only verifier and the public/source integrity check. Do not reset or reseed the database. Migration 021 was applied successfully to fresh isolated databases and replayed safely; its check constraint rejects invalid claim states. Do not mark any current live account unclaimed as part of deploying it.

## Changed application files

- `app/src/Services/StudentVerificationService.php`: existing registration transaction selects safe creation versus in-place claiming.
- `app/src/Repositories/UserRepository.php`: locked, guarded credential-free placeholder update; established credentials are never overwritten.
- `app/src/Services/StudentEnrollmentRegistryService.php`: canonical owner/conflict lookup includes historical raw identifiers; claim audits; safe reconciliation.
- `app/src/Services/CampusAccessService.php`: unclaimed markers cannot grant continued access.
- `app/src/Services/StudentRetentionService.php`: retain the protected unclaimed owner identifier during detail erasure.
- `database/migrations/021_student_placeholder_claims.sql`: one conservative additive claim-state column.

QA additions: `scripts/student-access/{claim_fixture,test_placeholder_claims}.php`, `scripts/qa/{claim_probe,claim_static_checks,claim_live_audit}.php`, `scripts/qa/{placeholder_http,claim_live_http}.py`. The existing isolated runner, restored-baseline checker, migration-catalog test and read-only live verifier were extended. No dependency was installed.

## Rollback

The pre-change full SQL dump and source copies are outside Apache/Git in the operator-only directory:

`C:\Users\Robert Martin\AppData\Local\light-account-claim-801b3614cf994ef0b8dbdbf2c83fc528`

The **28,813,836-byte** dump has SHA-256 `916ea127b936b528f9299fee598269a58b8d1035d6d926ebd97bf9d754866c4f`. Its initial full restore was verified table-for-table in isolation. Public files are also snapshotted there. No private dump, real roster, password hash or test SMTP message is included in this report.

For an application rollback, first stop new registration requests in a maintenance window and restore the five service/repository source copies from this directory. `CampusAccessService.php` and `StudentRetentionService.php` are at its top level; the other three source copies retain their `app/src/...` paths. Leave the harmless additive column in place; old code conservatively refuses linked accounts. Removing the column/ledger entry requires a separate reviewed schema rollback after confirming no claim metadata is needed. **Do not restore the full SQL dump over an active database:** that could erase legitimate activity since backup. The dump is for verified recovery or comparison under a controlled restore plan.

## Limitations and separate findings

Knowing an active, unclaimed Student ID is not proof of rightful ownership. This implements the requested registry-only enrollment policy without introducing an identity or email challenge. Email format/uniqueness validation does not establish mailbox ownership or actual delivery. Protect the trusted roster and provisioning privileges; ordinary CSRF/rate limiting cannot eliminate first-claim impersonation.

Only synthetic accounts/passwords were mutated during acceptance tests, using isolated schemas and production-denied credentials. No real student's password was reset and no real email was sent. Fresh PDF/DOCX/URL, translation, audio and mailbox-delivery tests are outside this account-claiming change; do not treat them as revalidated by the pasted-text NLP regression. The separate [password recovery diagnostic](password-recovery-availability-report.md) still records its two mail-feedback findings; this request did not authorize changing that workflow or its frontend. The isolated Apache startup also emitted a PHP cURL-extension load warning; no runtime/dependency installation was performed as part of this backend task.
