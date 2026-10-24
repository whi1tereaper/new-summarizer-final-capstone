# Student-created account registration — implementation report

Validated 24 October 2026, Asia/Manila, in the existing XAMPP project.

## Current workflow

Students enter their eligible Student ID, **required email**, chosen password and matching confirmation on Create Account. The existing optional username is still optional; no name/document/identity form was added. The fixed `PEN` prefix remains non-editable. JavaScript sends the complete ID; PHP independently normalizes/validates it, including when JavaScript is disabled.

The active, unexpired, unclaimed, conflict-free **MySQL registry** remains the only enrollment eligibility authority. A match creates an active ordinary-user account and a verified `registry_match` claim in one transaction. An import authorizes IDs but never creates new user accounts. Unknown, inactive, revoked, expired or already claimed IDs cannot register.

Successful registration no longer automatically signs the student in. It redirects to the existing Sign In page with exactly:

> Account created successfully! Your Student ID was found in the approved enrollment registry. You can now log in.

The chosen email/password, full Student ID/password, and existing username login remain supported. No manual administrator approval, student OTP, email confirmation or secondary verification page follows registration. Existing retired review routes remain retired; historical evidence/schema are preserved. Administrator second-factor authentication is unchanged.

## Form and security changes

- Required server/client email validation and existing case-normalized email uniqueness; no generated substitute email for new signups. Existing account emails/passwords are never rewritten.
- Accessible field-level messages, `aria-invalid`/descriptions, error-summary links, keyboard-focusable show/hide controls and mobile layout. Valid non-password values are restored after server validation failures using session-local, single-use feedback valid for 15 minutes; neither password is retained. Old values are HTML-escaped.
- A disabled submit button, busy/progress state and duplicate-submit guard; normal operation without JavaScript was tested.
- Existing password policy, `password_hash`/`password_verify`, CSRF, rate limiting, session protection, prepared queries, transactional claim locking and unique database constraints remain enforced. The service also rejects malformed/missing email and plaintext password input from internal callers.
- Successful account creation records a transactional `registration_created` audit event. Validation failures are counted safely alongside existing registry/account rejection events. Logs use protected identifiers/reason categories, not passwords, emails or full enrollment lists. Invalid CSRF/rate-limit failures retain their separate security handling; a database outage can prevent an audit write and is logged without raw exception details.
- Database/bootstrap failures now return a generic **HTTP 503** with retry guidance, never a verification fallback. A forced mid-transaction failure was tested: neither the account nor claim survived; retry succeeded after removing the QA fault.
- Administrator counters now distinguish **approved registry IDs, claimed IDs, unclaimed IDs and actual registered student accounts**. Eligibility/import/rejection counters remain. Historical restricted accounts can make account counts differ from claimed-ID counts; disputed IDs are not shown as available.

## Actual tests

| Executed suite | Passed | Failed |
|---|---:|---:|
| All 38 IDs, registration service, reconciliation, imports, races and safe feedback | 164 | 0 |
| Existing Apache HTTP registration/login/management/security workflows | 79 | 0 |
| Required email, mismatch, duplicate email/ID, audits, outage and rollback HTTP cases | 27 | 0 |
| Real mobile/desktop Edge browser, including JavaScript-disabled registration/login | 25 | 0 |
| Existing PHP security, analytics, database, feedback and history regressions | 135 | 0 |
| Real Python summarization, MySQL persistence, result and history retrieval | 6 | 0 |
| **Functional assertions** | **436** | **0** |

All **11 orchestration stages** passed, including isolated provisioning, denied production access, integrity verification and cleanup. Additional results: **20/20** restored-backup table comparisons, **6/6** live registry/configuration checks, **5/5** live HTTP smoke checks, **10/10** public-file/Git/security checks, **161 PHP files** linted, **4 JavaScript files** syntax-checked. The final real pasted-text summary completed in **14.36 seconds**; this is one functional sample, not a speed guarantee.

Coverage includes missing/invalid email, weak/mismatched passwords, safe value retention and XSS escaping, duplicate email rollback, exact success feedback, no automatic login or student verification mail, immediate email/password login, unchanged role boundaries, fake/unlisted/revoked IDs, administrator-created eligibility, 3,000-ID service/1,000-ID browser imports, preview/confirm, search, claimed status, revocation and eight-way duplicate-claim races. All 38 original IDs were tested in isolation. A browser harness navigation-wait timeout in the first attempt was corrected; the complete rerun passed. Earlier attempt receipts remain for traceability.

Mutations used restricted QA credentials that cannot access production. Normal acceptance HTTP used a separate XAMPP Apache/PHP 8.3 instance on loopback; the outage case used an isolated PHP 8.3 HTTP process pointed at an unavailable database port, without stopping live MySQL. Temporary processes, databases and users were removed.

Receipts: `docs/qa/student-account-registration/` contains `results.json`, individual backend/HTTP/browser/core/NLP results, live checks, integrity evidence and screenshots. Common file/Git checks are in `docs/qa/security-results.json`. Fresh PDF/DOCX/URL, translation, audio and live-mail password recovery were not re-executed in this registration-specific run; no real password was reset.

## Data and database

**No schema migration was needed.** Existing migrations through 020, email uniqueness, registry normalization and unique claim constraints were reused. All **38/38 initial IDs remain active**, with no additional production IDs or accounts created by tests.

The fresh pre-change backup was restored only into isolation and compared to live records: all **38 existing users/password hashes**, **208 summaries**, **199 artifacts**, **107 feedback records**, **113 feedback comments**, and the original document/evaluation records are preserved. Live state retains **3 verified student accounts**, **33 restricted non-administrators**, and **2 exempt administrators**. Analytics/core regression tests passed and no live database reset occurred. The previously chosen 24-hour guest retention policy remains unchanged; no expired historical guest summaries were restored.

## Changed files

Frontend:

- `app/public/register.php`, `app/public/partials/student-application-fields.php`.
- `app/public/assets/js/student-registration.js` (new), `app/public/assets/css/student-access.css`.
- `app/public/partials/registry-statistics.php`, `app/public/admin_student_registry.php`.

Backend/policy:

- `app/public/auth.php`, `app/src/Controllers/AuthController.php`.
- `app/src/Support/StudentRegistrationForm.php` (new).
- `app/src/Services/StudentVerificationService.php`, `StudentEnrollmentRegistryService.php`.
- `app/src/Utils/TermsContent.php` (required-email privacy wording and new-submission policy versions).

QA:

- `scripts/student-access/test_registry_only.php`.
- `scripts/qa/run_allowlist.py`, `allowlist_http.py`, `allowlist_browser.cjs`, `allowlist_probe.php`, `allowlist_restore_integrity.php`, and new `registration_http.py`.

No summarization algorithms, authentication providers, server configuration or unrelated UI were changed for this update.

## Rollback and limitations

Private rollback directory: `C:\Users\Robert Martin\AppData\Local\light-registration-update-e96c4e92bc2d4496be93bb336d086e79`. It contains copies of modified existing files and a restore-verified **28,761,027-byte** `rollback.sql`; SHA-256 **`36f1fa8f68104d42cdd5d665700c0368bf2e92598c8dc9cc98c93d1ab59c1609`**. The directory has operator-only ACLs, is outside Apache/Git, and no SQL backup was committed. Its original integrity check found production unchanged. Review any newer legitimate activity before rollback; never overwrite the whole live database from the dump. A code rollback should restore only this update's backed-up files and remove only its newly introduced runtime files if no longer referenced. No schema reversal is necessary.

The intended limitation remains: knowing someone else's valid, unclaimed Student ID may let another person register first. Neither a registry match nor an unconfirmed email independently proves rightful ownership. Rate limits and private error messages reduce abuse but cannot eliminate distributed guessing or disputed claims. No extra identity-verification layer was introduced. No outstanding blocker was found for the requested registration flow.
