# Nutshell timeout and interrupted-response recovery

Validated 24 October 2026 (Asia/Manila).

## Findings and corrections

The live Apache log contained Nutshell HTTP 504 requests at 10:02:14 and 10:03:08. PHP diagnostics recorded `PROCESSING_TIMEOUT`; the corresponding failed PDF generation recorded about 46.4 seconds. Nutshell imposed a hard-coded 45-second Python deadline, shorter than the existing extraction engine's 55-second total OCR budget. The original uploaded document was already cleaned up, so its contents and whether it needed OCR could not be established.

The frontend also classified non-JSON server responses as `NETWORK_INTERRUPTED`. Its saved request key replayed a persisted timeout on subsequent retries, rather than making a fresh attempt after a confirmed failure.

Corrections:

- `app/public/nutshell_generate.php`: configurable Python budget, default 90 seconds, bounded to 10–90 seconds and reduced when necessary to preserve 20 seconds of PHP execution headroom. Initialization exceptions now return safe JSON instead of an unhandled HTML/fatal response. JSON responses are non-cacheable and use `nosniff`.
- The same endpoint now supports a CSRF-protected, owner-scoped POST with `action=status` and the original `request_key` (and `summary_id` for summary-bound requests). It returns an existing result/failure, an active reservation, or `not_found` without uploading the source again, starting Python, or consuming quota. Reservation references are derived from the existing owner-scoped idempotency key.
- `app/public/assets/js/index.js`: distinguish HTTP timeouts/unavailability from actual transport failures. After an unreadable/lost generation response, perform one read-only lookup, never an automatic second generation. Keep the same key for an uncertain/not-found request; use a fresh key only after a confirmed failure. Restore uncertain requests after reload. Display “Check status” while the outcome remains uncertain.
- `app/public/assets/js/student-access.js` and `assets/css/student-access.css`: keep read-only recovery and Copy accessible after the third use while generation stays blocked. A browser test exposed inherited `aria-disabled` disabling recovery after reload; a separate quota styling marker now avoids disabling the entire result/recovery form.
- `app/config/config.php` and `.env.example`: document optional `NUTSHELL_TIMEOUT_SECONDS=90`. The real `.env`, PHP configuration and live services were not changed.

No Python/extraction/summarization algorithm, schema, existing user/password, registration policy, UI design or dependency was changed. Failed audit rows remain intact. The same three-success trial, atomic completion accounting and 24-hour guest retention policy remain enforced.

## Executed validation

Final isolated run: **61 passed, 0 failed** orchestration/HTTP checks. This includes wrapper checks for **6/6 baseline mobile browser assertions**, **11/11 recovery browser assertions**, **2/2 unavailable-database HTTP assertions**, and **135/135 existing core regression assertions**; these nested counts are not additional independent end-to-end workflows.

Coverage includes:

- CSRF, method/action/key validation, unsupported uploads, owner isolation and existing account-only History access.
- Read-only unknown/completed/failed/in-flight status, recovery at zero quota, no extra NLP call or charge, and no source required for lookup.
- Actual PHP-to-controlled-worker timeout: structured HTTP 504, recorded failure, released reservation and a successful fresh-key retry. Failed and `not_needed` results cost no attempts.
- A controlled **48.14-second** worker request completed under the 90-second budget, demonstrating the former 45-second cutoff is no longer applied. This is a deterministic timing fixture, **not** an NLP speed result.
- Browser-injected HTML 504/502/200 replies and connection resets; recovery of already-stored results with one generation/charge; fresh-key retry after confirmed failure; processing-state checks; last-use recovery after reload; no browser JavaScript exceptions. These transport faults were deliberately injected, not attributed to an actual Internet outage.
- Shared regular/Nutshell exhaustion, eight concurrent HTTP requests yielding exactly three successes, persistence rollback, rate limiting, login history transfer, verified-student continued access and guest retention.
- Database-down initialization returned HTTP 503 JSON without SQL, credentials or paths. Existing security, analytics, lifecycle, feedback and history regressions passed. PHP and JavaScript syntax checks passed.

Separately, real generated fixtures traversed loopback XAMPP Apache/PHP → the existing Python environment → isolated MySQL, with successful result readback and exactly one trial charge:

| Source | Observed sample workflow time | Result |
| --- | ---: | --- |
| Pasted text, first request to newly started worker | 20.98 s | Completed |
| Text PDF, warm worker | 0.66 s | Completed |
| DOCX, warm worker | 0.73 s | Completed |
| Image-only scanned PDF, real OCR | 3.64 s | Completed |

These small synthetic library-text samples include QA validation/readback overhead; they are not throughput or worst-case guarantees. The original failing upload was not available. URL extraction and multi-page/poor-quality OCR performance were not re-benchmarked in this task.

The first run had 57 passing checks and one failed browser wrapper: the third-use reload recovery control inherited the disabled state. After the correction, the entire expanded suite passed. The [first browser failure](qa/nutshell-recovery/browser-recovery-attempt-1.json) is retained rather than hidden.

Tests used dedicated loopback Apache/Python processes, private fixture storage and restricted isolated database credentials denied access to production. Final integrity verification confirmed original account/password, summary and feedback rows were preserved. Test schemas/credentials and test processes were removed; private connection configuration was deleted. No live database migration was applied, and no live visitor trial was consumed by these tests.

Evidence: [final summary](qa/nutshell-recovery/summary.json), [HTTP checks](qa/nutshell-recovery/checks.json), [browser recovery](qa/nutshell-recovery/browser-recovery.json), [core regressions](qa/nutshell-recovery/core-results.json), [real text](qa/nutshell-recovery/real-nlp.json), [real documents/OCR](qa/nutshell-recovery/real-documents.json), [initialization](qa/nutshell-recovery/initialization.json), [live baseline preservation](qa/nutshell-recovery/integrity.json).

## Operational limits and rollback

- A fresh page load is needed to receive the cache-busted frontend changes. Re-select a file if a confirmed failure needs a new generation after reload; browsers cannot restore file bytes. A stored completed result needs no reupload.
- This remains a bounded synchronous endpoint, not a durable background job service. Status requests may wait for the original request's PHP session lock. No claim of live progress or recovery after browser identity loss is made.
- Very large documents, cold model initialization or an unavailable local Python environment can still time out. The limit remains at most 90 seconds, possibly lower under a shorter PHP budget. Genuine outages are reported, not suppressed. Trials remain protected on failures.
- Deployment-specific external proxy limits were not changed or certified. Recovery depends on reaching this application with the same authenticated/session identity and request key.

Pre-change source and test copies (nine files, including prior user changes) were verified in the operator-only directory outside Apache/Git:

`C:\Users\Robert Martin\AppData\Local\light-nutshell-recovery-2be136e0057b4542aa1974869b25d40c`

Restore those specific files during maintenance if needed. No SQL rollback, account/password reset or deletion of existing result records is required. New QA scripts and this report are test/documentation artifacts, not application dependencies.

Reproduce using the existing environment:

```powershell
$env:QA_NUTSHELL_REPORT = Join-Path (Get-Location) 'docs/qa/nutshell-recovery'
& ./.venv-nlp/Scripts/python.exe scripts/qa/test_nutshell_trial.py
```

This creates only scoped QA schemas/users, starts loopback test services, executes the checks and cleans them up. Ports 58139, 58140 and 58149 must be available. Existing Edge/Playwright, Pillow, pypdf, DOCX, OCR and NLP dependencies are used; nothing is installed.
