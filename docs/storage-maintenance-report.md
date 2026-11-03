# Storage & Maintenance integration

Validated October 24, 2026. No database migration or new dependency is required.

## Finding and correction

The old dashboard was connected to the uploads directory, but showed only its size and file count. Sources are normally removed after processing; summaries persist in MySQL. An empty upload folder was therefore not evidence of a disconnected system. Its old top-level `*.*` scan also omitted nested and extensionless files.

The dashboard now displays an authorized, read-only system snapshot:

- Completed summaries and completed Nutshells queried from the configured MySQL database.
- Estimated MySQL table/index allocation, and queued/processing job records.
- Recursive measurements for configured uploads, audio/cache, temporary files, logs, and storage-root model/backup folders.
- Read/write availability, missing-folder and partial-scan warnings, last-check time, and a manual Refresh status control.

Refresh uses the real backend, not hardcoded numbers, browser storage or simulated progress. Failed refreshes preserve the last successful readings. Missing database metrics remain unavailable, not zero. The panel supports keyboard use, locally scrollable tables, responsive layouts and existing light/dark tokens.

The service checks the acting account's current database role and active flag. The endpoint also checks the authenticated session, permits GET/HEAD only, and returns JSON with `no-store`, `nosniff` and `no-referrer`. It returns aggregate information only, not server paths, filenames, document text or credentials. It does not read document bodies or invoke deletion. Ordinary application bootstrap/session bookkeeping remains unchanged.

## Files

- `app/src/Services/StorageMaintenanceService.php`: MySQL and bounded filesystem measurements, current-role authorization.
- `app/src/Controllers/AdminController.php`: integration method; old public storage/cleanup methods retained.
- `app/public/admin_storage_status.php`: authenticated read-only JSON endpoint.
- `app/public/admin_dashboard.php`: initial server-side snapshot and asset/partial inclusion.
- `app/public/partials/admin-storage-maintenance.php`: initial rendering, including no-JavaScript measurements.
- `app/public/assets/css/admin-storage.css` and `assets/js/admin-storage.js`: scoped presentation and refresh handling.
- `scripts/qa/storage_probe.php`, `storage_browser.cjs`, `test_storage_maintenance.py`: restricted database, HTTP and browser tests.

## Executed validation

The final run passed 16 orchestration/HTTP checks, 19 service checks and 17 actual Edge/Playwright browser checks. The orchestration checks wrap some nested suites; these counts should not be added together as independent cases. The six existing regression suites passed 135 checks (security 19, request boundaries 21, admin/analytics 22, database lifecycle 18, feedback 48, history 7).

Executed cases included:

- Real MySQL completed versus failed counts, real job states and a positive allocation estimate.
- Exact synthetic file counts/bytes, nested and extensionless files, ignored server metadata, audio directory alias deduplication.
- Missing tables/folders producing explicit unavailable/partial status; broad workspace/ancestor roots rejected; scan-entry/time limits and overlapping paths handled safely.
- Anonymous, ordinary-user and inactive-admin denial; JSON headers; HEAD; POST deletion attempts rejected with 405.
- HTTP refresh preserving account, summary/artifact, Nutshell, job and file-content hashes after normal session bootstrap.
- Browser refresh after adding a MySQL summary and file, then removing the file; keyboard activation; duplicate in-flight requests prevented.
- Simulated network failure, expired session, malformed JSON structure, partial metrics and HTML-like warning text; previous readings retained and no script execution.
- Light/dark layouts at 1506, 768, 390 and 320 pixels, and no JavaScript exceptions.
- PHP syntax checks for the changed PHP files/test probe and JavaScript syntax checks for the frontend/test runner.

Mutating tests ran against random isolated schemas using restricted credentials proven unable to read production. HTTP/browser tests used a private PHP 8.3 loopback server and synthetic accounts/files, not a live Apache administrator login. Test databases/credentials were removed and the test server stopped. Production integrity checks confirmed all original users/password hashes, summaries and feedback rows were preserved. Other live activity was observed, so this is not a claim that every production table remained byte-identical.

Initial attempts exposed test-harness issues: an obsolete fixture tried to insert text into columns now held in `summary_artifacts`, and a button locator waited for its idle label while the button was busy. Both fixtures were corrected and the final suite rerun successfully. Earlier receipts are retained; the first aborted harness summary must not be interpreted as a complete passing run.

Receipts: [QA summary](qa/storage-maintenance/summary.json), [HTTP checks](qa/storage-maintenance/checks.json), [service checks](qa/storage-maintenance/service.json), [browser checks](qa/storage-maintenance/browser.json), [core suites](qa/storage-maintenance/core-results.json), [production integrity](qa/storage-maintenance/integrity.json).

## Live read-only check

A direct service call using the actual configured production MySQL connection and runtime folders succeeded without application bootstrap, cleanup or record writes:

- 214 summary records, of which 205 completed; 25 completed Nutshells.
- 0 queued and 1 processing job stored in MySQL.
- 44,826,624 bytes estimated database allocation.
- 114 runtime files, 238,716,202 bytes; 0 temporary uploads. All six measured folders readable/writable, with no scan warnings.
- Snapshot took approximately 118 ms on this machine. This is one observation, not a performance guarantee.

These are point-in-time measurements, not fixed dashboard values. A processing job row alone does not demonstrate that its worker is alive.

## Scope, limitations and rollback

Filesystem scanning is limited to 20,000 entries and a two-second scan budget checked between filesystem operations. Slow OS/filesystem calls themselves are not forcibly interrupted. Linked directories are skipped; external model caches, Python environments and operator backups outside the configured folders are not included. Totals are not total disk consumption or backup validation. MySQL allocation is an engine estimate, not exact content size. OS-level unreadable-folder ACL behavior and every possible junction configuration were not exhaustively exercised on this Windows machine.

The existing `clean_files` and `delete_files` actions remain behind the existing admin/POST/CSRF guards; no new deletion buttons were introduced and neither action was run against live files. Existing age-based cleanup does not prove that a file is unreferenced by an active job. Treat those legacy destructive actions cautiously; safe reference-aware cleanup would require a separate change. Their existing dispatch regressions passed only on isolated test storage. The established guest retention policy is unchanged.

Before this task, the two existing integration files were copied to the operator-private directory `C:\Users\Robert Martin\AppData\Local\light-storage-status-ff75b6c2839d44409bd05d0e00de0864`, preserving the prior audit/UI work. The copies were verified present. To roll back this task, review/restore only those storage-related dashboard/controller changes from that snapshot, preserving any newer edits, then remove the newly added storage service/endpoint/partial/assets. No data rollback or schema rollback is needed. Do not revert unrelated worktree changes.

Open **Admin → Storage & Maintenance → Refresh status** to check current readings.
