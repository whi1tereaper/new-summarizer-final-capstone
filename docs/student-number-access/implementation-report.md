# Student-number access control — implementation and deployment handoff

Latest status, 23 October: **the actual Apache website access error is fixed**.
After explicit authorization, the current MariaDB database was backed up,
restore-tested and migrated with 017. Existing accounts/history remain intact;
local `DB_DRIVER=mysql` now prevents accidental backend changes. Eleven live
browser checks passed. See the [completed website repair](mysql-web-access-repair.md).
Student privacy/HMAC settings and wider deployment checks remain outstanding.

Earlier follow-up, 23 October: the user authorized the PostgreSQL backup
and migration; **018 -> 019 -> 017 are now applied and verified on Neon**. The
actual running Apache site was then found to use a separate MariaDB database with
35 existing accounts and missing access tables. At that point browser access was
still blocked and no MySQL migration had been authorized; the completed repair
above supersedes that intermediate state. No backend switch to Neon was performed.
See the [authorized migration and web-backend findings](configured-migration-2026-10-23.md).
The original preparatory report below is retained as historical evidence; its
"not applied" statements describe the earlier handoff, not current Neon state.

23 October 2026. Implemented in the existing application; **not enabled or
migrated against the configured database**. No real student applications were
collected, accounts deleted, passwords reset, or roles automatically promoted.
The earlier photo-verification proposal remains reverted. No ID-image collection,
OCR, new authentication provider, frontend framework, or NLP algorithm was added.

PostgreSQL repair follow-up (23 October): **isolated PASS; configured deployment
FAIL / NOT READY**. The older PostgreSQL bootstrap mismatch is now diagnosed and
an additive repair is implemented/tested, but has **not** been applied to the
configured Neon database. See the [schema repair report](postgres-schema-repair.md),
[migration/recovery runbook](postgres-migration-runbook.md) and
[read-only preflight](postgres-repair-preflight.json). The follow-up passed 242
targeted checks; those are distinct from the historical implementation runs below.

## Delivered workflow

- A separate 180-day random HttpOnly/Lax trial cookie identifies a database-backed
  subject. Existing 24-hour guest-history identity remains separate. Pending
  accounts bind to their original subject so registration/login never replenish
  it. Browser clearing/device changes can still evade anonymous identification;
  neither IP addresses nor invasive fingerprints are treated as unique people.
- All text/file/URL jobs, synchronous document variants/API batches, and Nutshell
  use `CampusAccessService`. Before generation, row-locked reservations enforce
  the three-success allowance and fair-use limits. A successful result and its
  debit commit in one transaction. Failure, rollback and expired reservations
  do not consume allowance. Completed cache hits remain free and readable at zero.
  PID-scoped job reservations fence stale worker cleanup. Eligibility is checked
  again before persistence, so revoked access cannot commit privileged output.
- Registration stores full name/student number, account email, existing password
  hash, explicit acknowledgements, policy versions/time and a Pending application.
  A safe generic format is configurable, **not an official campus-format claim**.
  Database uniqueness includes a keyed HMAC duplicate-check identifier. Correct
  syntax never grants approval. No roster or authoritative identity source was
  supplied; approval requires actual human-recorded eligibility and ownership
  evidence, period, future expiry, confirmation and review reason.
- The existing admin dashboard includes the verification queue, pending count,
  status/order filters, name/number search, masked rows and protected detail links.
  Search is POST/CSRF protected rather than putting numbers in URLs; its session
  value expires after 15 minutes. Admin details/actions recheck the active DB role,
  use CSRF plus row locks/version checks, and record audit events. Accept, decline,
  correction and suspension are real database transitions, not placeholder matches.
- The owner-only Verification page shows current status, recent notifications,
  reasons and correction/renewal without repeat registration. Notifications are
  **in-app, not decision emails**. Existing accounts are unverified until they
  complete review; administrators retain their established role-based access.
- The summarizer shows remaining allowance and a polished exhausted state with
  registration/status, login and official enrollment links. The form is hidden at
  zero even without JavaScript; backend authorization remains decisive. Current
  design tokens, navigation and all six Summary Depth settings are preserved.
- Policies describe actual collection, processing, rights, limitations, finite
  detail retention and contact configuration. No university endorsement or legal
  compliance certification is claimed.

## Confirmed three-day retention

Detailed application full name, raw/normalized student number, enrollment/ownership
references and current review reason are erased **three days after submission**.
Detailed review-event notes/notifications expire three days after each event.
Afterward only minimal actor/action/status/time audit remains. Expired unreviewed
applications require correction, and unapproved duplicate claims are released.
Approval is **not** revoked merely because its details were erased. Minimal
approval status, campus, reviewer/time, established period/expiry, consent versions
and protected duplicate identifier remain for the account's eligibility controls.
Account email/username/password hash and summary history are separate records.

Read barriers hide overdue details even if maintenance is delayed. Physical
erasure requires the scheduled CLI job below. Backups, host logs, exports and
administrator copies require corresponding operator retention controls; this code
cannot certify erasure from independently retained backups. HMAC records remain
personal/pseudonymous data, not anonymous information. Never rotate or lose the
HMAC secret without a controlled identifier migration: erased raw numbers cannot
be reconstructed to recompute old fingerprints.

The answer “their active email” is used for **private student account
communications**, not publication of a student's address as the operator's privacy
contact. A designated responsible operator email is still required.

## Changed integration surfaces

New services: `CampusAccessService`, `StudentVerificationService`,
`StudentRetentionService`. Existing integration changes: `AuthController`,
`ArticleController`, `ArticleService`, `SummaryJobService`, `GuestSessionService`,
`DocumentSummaryController`, `DocumentSummaryService`, `RateLimiter`, bootstrap,
Nutshell endpoint, config and safe error catalog. The guest-history feedback claim
query and job cutoffs now work with both SQL dialects; no history deletion was
added to quota exhaustion. The document API now requires CSRF for POST and owner
authorization for all reads/generation. Consumers must send the session CSRF token
as `X-CSRF-Token` or JSON `csrf_token` and preserve session cookies.

UI/policies: registration, admin dashboard/module, protected admin review page,
student status page, shared application fields/trial panel, access-status endpoint,
navigation, scoped CSS/JS, TermsContent/terms page, `.env.example`.
Migration/maintenance/audit/replay scripts are under `tools/` and
`scripts/student-access/`; evidence is local to this directory. No historical
research report, frozen run, review submission or NLP implementation was edited.

## Deployment prerequisites — not yet satisfied

1. Review the actual campus verification method, privacy legal basis/authority,
   reviewer permissions, official number formats, retention and rights workflow.
   A human typing an evidence reference does not independently validate that
   evidence or prevent every stolen-number impersonation attempt.
2. Set `STUDENT_PRIVACY_CONTACT` to the responsible operator's real email and
   `STUDENT_NUMBER_SECRET` to a securely generated, stable base64-encoded 32-byte
   key in protected runtime configuration. Do not commit it. Applications fail
   closed until both are configured. Keep TLS, private database credentials,
   session security and backups configured. No actual `.env` secret was changed.
3. Run the read-only preflight from the repository root:

   ```powershell
   C:\xampp\php\php.exe tools/migrate_student_access.php
   ```

   The configured Neon PostgreSQL 18.6 database still has the older bootstrap
   layout. The updated read-only preflight identifies 18 missing active-contract
   columns, five unapplied access tables, seven incompatible NOT NULL requirements,
   hash-column width and selector uniqueness. See the complete
   [root-cause and constraint analysis](postgres-schema-repair.md). The runner now
   checks column types, relevant keys/foreign keys and content integrity, not just
   column presence. The older MySQL-oriented migration tool does not repair this
   PostgreSQL layout.
4. Follow the [reviewed execution/recovery procedure](postgres-migration-runbook.md):
   obtain explicit database-write approval, pause writes/workers, securely back up
   and restore-test the exact deployment schema, then stage the repair. Only after
   authorization, the applying command is:

   ```powershell
   C:\xampp\php\php.exe tools/migrate_student_access.php --apply
   ```

   PostgreSQL order is explicitly **018 summary compatibility, 019 authentication/
   audit compatibility, then unchanged 017 access tables**, not numeric filename
   sorting. All three use the matching direct/unpooled connection in one
   transaction with a SHA-256 migration ledger and postcondition gate. Legacy
   values remain; readable legacy summary text is copied into one inline artifact
   without deleting file references. Ambiguous/incompatible records fail closed.
   The MySQL path retains `database/migrations/017_student_number_access.sql`;
   there are no new MySQL repair migrations. PostgreSQL DDL is transactional while
   MySQL DDL auto-commits. **Neither applying command nor retention deletion was
   executed against the configured database.**
5. Schedule and monitor physical erasure at least every 15 minutes, using the
   existing deployment's private scheduler (no public maintenance endpoint):

   ```powershell
   C:\xampp\php\php.exe tools/student_retention.php
   C:\xampp\php\php.exe tools/student_retention.php --apply
   ```

   Default is a read-only due-count preview; `--apply` erases only due details and
   eligible stale anonymous accounting data. Each batch processes up to 500
   applications; drain backlog and alert on failures. Do not schedule the preview
   in place of the applying job. Account-linked trial ledgers are retained, not
   reset by anonymous cleanup.
6. Run staging end-to-end PDF/DOCX/live-URL processing, detached-worker recovery,
   full dashboard analytics, feedback writes, SMTP password recovery/admin OTP,
   real registrar-review and deployment load/timeouts. These are not established
   by the isolated tests below. Never expose the test router or fixture databases.

The database skills informed additive, dialect-specific migration design and
isolated transaction/race tests; they did not introduce another provider or replace
the existing authentication system.

## Historical initial-implementation verification

The following runs are preserved from the earlier implementation. They were not
all rerun as part of the PostgreSQL schema repair, and their normalized PG fixture
was not representative of the inspected older bootstrap. Current repair evidence
is listed separately below.

- `tests-pgsql.json`: **59 passed**, zero failed; PostgreSQL 16 disposable local
  fixture, actual production migration 017, real PDO transactions and connections.
- `tests-mysql.json`: **59 passed**, zero failed; MySQL 8 disposable local fixture,
  actual production migration 017 and InnoDB transactions. Eight concurrent PHP
  processes per backend admitted exactly three successful generations, with five
  expected busy/limit denials; counters never exceeded three.
- `tests-http.json`: **29 passed**, zero failed. Real PHP HTTP registration,
  session/cookie, quota/CSRF/ownership checks, protected admin queue/detail/approval,
  student notification, immediate eligibility update, real PHP-to-Python document
  summarization/persistence, real Nutshell success/cached replay, cached history, password login/logout and page smoke
  for profile/history/terms/password recovery. Synthetic identities and enrollment
  evidence only; no actual university verification was asserted. Administrative
  fixture login bypasses SMTP **only in the isolated test router**, not production.
- `tests-browser.json`: **8 passed**, zero failed. Real headless browser mobile
  fields/layout, six existing depth buttons, visible exhausted panel/hidden form,
  desktop dark text/surface distinction, persistent HttpOnly cookie and exhaustion
  without JavaScript. Screenshots retained for review. This is not a complete
  accessibility/color-contrast audit or human UX assessment.
- PHP syntax checked across 26 integration files; JS syntax and Git whitespace
  check passed. Existing unrelated worktree changes are preserved.
- `integrity.json`: 38 NLP source/config hashes and 46 historical research artifact
  hashes checked unchanged; automatic article routing remains false. Historical
  production PHP snapshot hashes necessarily differ after this authorized access
  integration; the old research evidence was not rewritten to conceal that.

The transaction suites use a fixture NLP bridge to force success/failure and
rollback deterministically; they are not 118 independent NLP-quality tests. The
HTTP suite additionally regenerated an actual local NLP result. Initial test
harness issues (method signature, response contract, API transport, logout/depth
selector assumptions) were corrected before the passing recorded runs.

A subsequent cold synchronous document-API replay hit the local PHP server's
60-second maximum execution time inside the pre-existing bridge wait loop.
The API now subtracts elapsed request time and a fifteen-second transport/cleanup margin from
worker deadlines, does not start another attempt after a known timeout, and tests
that exhausted request budgets return a controlled failure with zero debit.
Nutshell also bounds its worker call after URL resolution to remaining PHP time.
This leaves the algorithms and PHP limit unchanged; a cold model may still fail
within a tight deployment budget. Production cold-start/load readiness is not
claimed. An earlier HTTP exhausted-panel assertion also failed once during live
iteration and passed on subsequent replay; its transient cause was not established.

## PostgreSQL repair verification follow-up

The matching PostgreSQL 18.6 fixture starts from the unchanged older bootstrap;
its column/type/nullability signature matches the live read-only catalog capture.
Twelve tables were populated with synthetic historical records, including password
hashes, used OTPs, legacy tokens, summary file references, analytics and an existing
verified student. Original row fields survive unchanged. The production migrator
and a real pg_dump/restore-to-new-database rehearsal were exercised locally.

- `tests-postgres-repair.json`: **58 passed**, preservation, repair, checksum/
  idempotence, unsafe target guards and failed-migration rollback checks.
- `tests-pgsql-repair.json`: **59 passed**, access/retention/race integration on the
  migrated older schema, with `--existing-schema` (no normalized-fixture shortcut).
- `tests-mysql-repair.json`: **59 passed**, existing MySQL access regressions.
- `tests-sql-compat-pgsql.json`, `tests-sql-compat-mysql.json`: **20 passed each**,
  actual PDO analytics/history/feedback/audit/OTP/token queries on both dialects.
- `tests-postgres-repair-http.json`: **26 passed**, real PHP registration, decline,
  correction, approval, private status, login/history/admin/analytics/audit/health
  routes. This follow-up did **not** execute NLP or SMTP delivery.

Total: **242 checks, zero failures** in final recorded runs. Three-day application
and event-detail erasure retains minimal approval/HMAC/expiry/audit, and preserves
private account email/password and owned history. Direct admin access and public
status disclosure checks passed. The replayed `integrity.json` still reports 38
NLP source/config and 46 historical research hashes unchanged, routing false.

See the repair report for exact SQL changes, test scope and unresolved deployment
conditions. Production remains **FAIL / NOT READY**: configured schema unchanged,
privacy/HMAC settings and scheduler unresolved, real deployment checks pending.
Neither these tests nor the historical 155 checks certify full Neon production
compatibility or the dormant MySQL-only research evaluation subsystem.

## Replay and limits

Use only owned disposable loopback containers named/labeled for this test, with
PostgreSQL `student_test` at port 55439 and MySQL `student_test` at 53319. Test-only
credentials and DSNs are fixed in `test_bootstrap.php`; it refuses other driver
names/database names and cannot connect to the configured production database.
Never run old database-seeding regression scripts against the configured DB.
`fixtureSchema` is a normalized integration fixture, **not a copy of the older
configured PostgreSQL schema**. For the schema repair, use the separate
[PG 18 legacy-schema reproduction procedure](postgres-migration-runbook.md#isolated-reproduction-used-for-this-repair),
which bypasses that shortcut and sets the guarded generated test-database name.
Do not run the historical normalized-PG commands below on the repaired fixture.

```powershell
C:\xampp\php\php.exe scripts/student-access/test_access.php pgsql --report
C:\xampp\php\php.exe scripts/student-access/test_access.php mysql --report
$env:LIGHT_STUDENT_FIXTURE='isolated-local-only'
$env:STUDENT_TEST_PYTHON='<absolute path to the existing Python 3.11 executable>'
C:\xampp\php\php.exe -S 127.0.0.1:58139 -t app/public scripts/student-access/http_router.php
# In a second terminal while the owned test server is running:
py -3.11 scripts/student-access/test_http.py --report
node scripts/student-access/test_browser.cjs
C:\xampp\php\php.exe scripts/student-access/verify_integrity.php --report
```

The browser script reuses the existing locally installed Playwright/browser;
`PLAYWRIGHT_MODULE` may point to that installation. No application dependencies
were added. Test reports are this integration's replayable diagnostics, not frozen
NLP validation evidence. Replays may replace these integration test reports, never
the historical research artifacts.

Batch document generation is all-or-nothing and capped at 12 combinations. It
holds a transaction across sequential batch generation, which may increase lock
waits; deployment-equivalent stress testing remains necessary. Guest reservation
leases are 900 seconds; failed or interrupted workers may temporarily reserve a
slot until cleanup/lease expiry without consuming allowance. Successful cached
reads do not count as new summaries. A successfully persisted result can consume
allowance even if the client's network disconnects afterward.

Legal/privacy review source: [Philippine Data Privacy Act / NPC](https://privacy.gov.ph/DATA-PRIVACY-ACT/).
Official enrollment link verified for the interface:
[NEUST enrollment procedure and qualifications](https://neust.edu.ph/enrollment-procedure-and-qualifications/).
No authoritative campus-specific number-format or enrollment API documentation
was located/supplied. **Manual review cannot guarantee current enrollment or
prevent all forged/stolen-number impersonation.**

The initial implementation's PHP test server, two labeled fixture containers and
anonymous test-data volumes were removed after its verification. Historical
reports/screenshots remain. The repair follow-up used newly owned isolated
containers, including an actual synthetic-data backup/restore rehearsal; see
`postgres-repair-verification.json` for its final checks and cleanup record. The
configured database, real accounts, existing NLP supervisor and cached Docker
images are not cleanup targets.
