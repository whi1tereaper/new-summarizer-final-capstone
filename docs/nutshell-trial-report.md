# Nutshell in the three-use trial

Validated 24 October 2026 (Asia/Manila).

Guests can now generate Nutshells without registering. Ordinary summaries and Nutshells share **one quota of three successfully completed generations**. They do not receive separate pools. Failed validation/uploads, worker/quality/storage failures and `not_needed` results do not consume attempts. Verified students and administrators retain existing continued access and fair-use limits; other accounts retain the trial boundary.

## Implementation

- `app/public/nutshell_generate.php`: remove the account-only gate; reuse `CampusAccessService` reservations/completion/release; save guest-owned records using the existing nullable `user_id` and private `guest_token`. Guest-specific idempotency prevents cross-visitor result reuse. Saved-summary generation checks the actual owner, including guests. Existing registered-user idempotency keys remain compatible. Rate limits use a durable visitor identifier rather than treating all guests as user zero or a shared campus IP.
- `app/public/partials/summarizer-scripts.php` and `assets/js/index.js`: remove the registration redirect; refresh access state after every generation response, including failures.
- `partials/student-trial.php`, `assets/js/student-access.js` and `assets/css/student-access.css`: explain the shared allowance, update the counter immediately, and block additional generation at zero. Keep the final Nutshell visible/copyable while hiding generation controls. Cache-bust the changed assets. A later authorized continued-access state can restore controls.
- `app/src/Services/GuestSessionService.php`: transfer guest Nutshell ownership on sign-in, alongside existing summary/feedback migration. Apply the existing 24-hour guest retention policy to guest Nutshells without deleting account-owned records.

No summarization/Python algorithm, authentication policy, frontend framework or dependency was changed. No live schema migration was needed or applied: the live MySQL table was inspected and already supports guest ownership. The unrelated pending student-account migration was not applied.

The History **page still requires an account**, as before. Guest results are stored privately and transferred to the account on sign-in; this change does not unlock all registered-only features. Reaching the quota does not delete stored results; normal guest retention still applies. Clearing browser identifiers can bypass an anonymous quota; this does not establish a person's identity.

## Executed tests

The isolated suite reports **40 passed, 0 failed** orchestration/HTTP checks. It includes six real-browser assertions and the existing core regression suite as wrapper checks; those additionally contain **6/6 browser assertions** and **135/135 core assertions**, not 141 extra independent workflows.

Coverage includes guest CSRF, empty/short input, invalid request key, rejected upload, injected worker failure, rejected output, `not_needed`, one-charge success, duplicate retry with no second NLP call/charge, cross-guest idempotency isolation, guest-owned stored results, unowned-summary rejection, owned-summary generation, the third completion/fourth-request block, unchanged account-only History-page access, successful-result retrieval after exhaustion, mixed shared quota, persistence rollback, guest-specific rate limiting, eight concurrent actual HTTP requests completing exactly three results, login/history transfer, no trial reset for an unverified account, 24-hour retention, and continued verified-student access.

Most fault/race cases use an **authenticated loopback controlled worker**, not real NLP, for deterministic assertions. Separately, the existing real Python environment generated a guest Nutshell through the actual PHP HTTP endpoint in **12.28 seconds**, saved it in MySQL and consumed exactly one attempt. This is one functional sample, not a speed guarantee. PDF/DOCX/URL extraction was not re-benchmarked; upload rejection and quota release were exercised, and the existing extraction algorithms were untouched.

The mobile browser generated three Nutshells without registration, observed remaining counts 2/1/0, and verified that the limit notice, result and Copy control remain available without horizontal overflow. Initial test assumptions incorrectly expected the account-only History page to be public and treated the source selector as a select element. Those test assumptions were corrected; the fresh complete rerun passed.

Tests used isolated MySQL schemas and credentials denied access to production, a separate loopback XAMPP Apache/PHP 8.3 process, and a dedicated local Python worker. Real accounts/passwords, summaries and feedback were preserved; test processes, databases and credentials were cleaned up. No real user quota was consumed by these tests. The legacy workflow's single obsolete anonymous-Nutshell assertion was updated to expect the shared exhausted quota; its unrelated historical/manual-verification workflow was **not** rerun or certified.

Evidence: [test summary](qa/nutshell-trial/summary.json), [HTTP checks](qa/nutshell-trial/checks.json), [browser checks](qa/nutshell-trial/browser.json), [core regressions](qa/nutshell-trial/core-results.json), [real NLP result](qa/nutshell-trial/real-nlp.json), [live baseline preservation](qa/nutshell-trial/integrity.json).

## Rollback

Pre-change source copies, including the already-existing work in these files, are in the operator-only directory outside Apache/Git:

`C:\Users\Robert Martin\AppData\Local\light-nutshell-trial-c21a7f6779c048e08415dcd4ecd09ebf`

Restore those specific files during a controlled maintenance window if needed. No SQL rollback is required. Do not delete guest result rows or reset user accounts as part of a source rollback.
