# Registry-required registration: no verification waiting step

Later diagnostic retest: [registration-conflict-diagnosis.md](registration-conflict-diagnosis.md) adds five duplicate-credential HTTP cases; the latest suite has 174 passing checks. The original 169-check implementation run below remains its historical receipt.

Date: 2026-10-23. The operator selected: remove the waiting step and require an active administrator-imported student ID.

## Current behavior

- An active, unexpired ID in the administrator-owned enrollment registry activates a new account immediately with `status=verified` and `approval_method=registry_match`.
- Unlisted, inactive, revoked and expired IDs fail registration. The transaction rolls back the provisional user, application, notification and related changes; no new pending account is retained.
- Duplicate claims are still rejected and audited. Client-supplied roles, statuses, approval methods and registry IDs cannot authorize an account.
- Existing unverified accounts, credentials, summaries, feedback and review history are preserved. Their status page now says **Not verified**, not **Under Review**, and offers immediate activation using an eligible imported ID. Failed activation preserves their original application and status. Suspended accounts still require the existing authorized correction/reinstatement process.
- Existing administrator import reconciliation can still approve eligible historical unverified accounts. Existing authorized administrative management and historical manual-review records are retained; this change does not mass-approve old accounts.
- Guest allowance, privacy contact, secure student-number key, enrollment-processing configuration gate, administrator second factor, CSRF, rate limits and centralized access checks remain in place.

`pending` remains an internal transaction staging value and a historical database state. Removing its schema value would damage existing records and audit references. New registration/resubmission cannot commit that staging state: registry approval must succeed in the same transaction. The shared registry mutation lock and unique constraints continue to serialize concurrent claims and eligibility changes.

Registration still returns the existing verified-account confirmation page on success; there is no additional approval step. Import the legitimate enrollment list before students register. A registry number establishes list eligibility, not proof of rightful ownership of that number; the prior first-claim impersonation limitation remains.

## Changed application files

- `app/src/Services/StudentVerificationService.php`: fail registration/activation when the atomic registry check fails; permit historical unverified accounts to retry eligible activation; consistent historical status label; terms-record version advanced to v3.
- `app/public/register.php`, `app/public/partials/student-application-fields.php`: explain required pre-import and immediate activation instead of pending review.
- `app/public/student_verification.php`: immediate activation form and existing-account status wording.
- `app/public/partials/admin-student-verifications.php`, `admin-student-registry.php`, `registry-statistics.php`, and `app/public/admin_student_review.php`: describe immediate activation while retaining historical management without a pending badge.
- `app/src/Utils/TermsContent.php`: document registry-required signup and rejection rather than queued registration.

No schema migration, live record update, password reset, new dependency, UI redesign or summarization-algorithm change was required.

## Executed validation

Command: `.venv-nlp\Scripts\python.exe scripts/qa/run.py --registry-required`.

Final run: **169 individual checks passed, 0 failed; all 11 execution groups passed**.

| Check group | Passed |
|---|---:|
| Privacy contact, secure key and authorization configuration gates | 8 |
| Student-number matching diagnostic with three synthetic IDs | 17 |
| Guest quota, eight concurrent clients, retention and historical-account regression | 60 |
| Registry imports, 3,000-ID batch, rollback, eligibility and registration races | 51 |
| PHP 8.3 import, approval, hashing, duplicate and revocation compatibility | 6 |
| Actual isolated XAMPP Apache HTTP registration/activation/security workflows | 27 |

The HTTP tests completed normal administrator second-factor login using local test mail capture, imported synthetic IDs, registered and signed in an immediately verified student, rejected forged approval fields and ineligible IDs without retained signup rows, rejected duplicate claims, activated an existing unverified account with an imported corrected ID, and checked registration/activation/import CSRF and terms acceptance. Eight concurrent PHP claims activated exactly one account for one imported ID. An import racing a signup either activates after the import or rejects the signup for retry, never queues it.

The first run exposed one stale test expectation: it expected an exhausted trial counter after successful activation, but verified access correctly returns `remaining=null`. The corrected assertion checks continued access and the unchanged underlying completed-trial count of three. The final rerun passed; its prior receipt is retained in the evidence directory. The intentionally injected document-generation failure in the quota test logs a RuntimeException and is an expected test case, not an unhandled production failure.

All fixture mutations used restricted disposable MariaDB credentials proven unable to access production. The final read-only integrity comparison confirmed original live users, summaries and feedback rows were preserved; no concurrent live activity was observed during that run. QA databases, database user and test Apache processes were cleaned up. No real enrollment list was used as a fixture.

PHP syntax checks passed for the changed application files and test helpers; five updated Python QA scripts also parsed successfully. Historical test fixtures now explicitly seed old unverified records rather than relying on registration to create them. The broader workflow/browser tests were adjusted to use those historical fixtures, but were not rerun in full during this focused change. The real NLP engine, PDF/DOCX/URL generation, translation and audio were not retested; quota regression uses the existing fixture bridge and makes no NLP quality/performance claim.

Evidence: `docs/qa/registry-required/results.json`, `http-results.json`, `integrity.json`, plus individual outputs embedded in the results file.

## Rollback

Pre-change copies of all nine edited application files are outside the web root, restricted to the current Windows user:

`C:\Users\Robert Martin\AppData\Local\light-registry-only-231347ad`

Compare and restore only those scoped files during maintenance if reverting this workflow; do not overwrite subsequent unrelated edits. No database rollback is needed. Existing backups and the stable student-number protection key were not changed. This source rollback would intentionally restore the old ability to retain unmatched pending applications.
