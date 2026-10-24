# MySQL website access repair — completed

23 October 2026 (Asia/Manila). **The reported access-setup error is fixed on the
actual localhost Apache website.** `access_status.php` returns HTTP 200 with
three free summaries, and `summarizer.php` displays the form instead of the setup
failure. This is an access repair, not a full NLP/deployment quality certification.

## Cause and applied fix

Apache was using the configured MariaDB 10.4.32/MySQL-protocol database while PHP
CLI preferred the separate Neon URL. The website's database contained 35 accounts
and 254 summaries, but none of the five student-access tables. The user explicitly
authorized backing up and repairing this current MySQL site, without switching it
to Neon. See the earlier [backend-split findings](configured-migration-2026-10-23.md).

Applied unchanged `database/migrations/017_student_number_access.sql`, SHA-256:

```text
4ddbbd3e6454467c625c9c9277bd50260d2ec660fff43dbf00456ff9a48b8935
```

Only five new InnoDB tables were created: `student_applications`,
`student_review_events`, `summary_access_subjects`, `student_trial_links`,
`summary_access_reservations`. All five live CREATE TABLE definitions match the
restored/staged target. There was no reset, truncation, account promotion, password
reset, history deletion or cross-database data transfer.

Added explicit `DB_DRIVER=auto|mysql|pgsql` selection to the existing database
factory, preserving auto behavior for other environments. The local ignored `.env`
now pins **`DB_DRIVER=mysql`**. Apache, ordinary CLI and scheduled maintenance use
the populated database even if Apache's PostgreSQL extension later starts loading.
Detached workers still honor the database that accepted their job. An explicitly
selected PostgreSQL backend fails closed rather than silently falling back.
The existing Neon URLs/secrets were not replaced or removed. No Apache restart
or server-wide configuration change was necessary.

`tools/migrate_student_access.php --backend=mysql` explicitly targets this MySQL
configuration; `--backend=pgsql` still runs guarded direct PostgreSQL preflight/
migrations. A read-only PG postflight passed after the local pin, with all three
earlier PG versions matching their recorded checksums. Do not remove the local
pin without an intentional account/history migration plan.

## Backup and data preservation

- Took a full 30,076,834-byte logical database backup, including routines, events
  and triggers, with the existing matching MariaDB 10.4.32 client. The private
  native option file kept credentials out of process arguments and output.
- Held READ locks only on this database's 31 existing InnoDB tables for **1.775
  seconds** during fingerprinting/dump. No queued/processing jobs existed. No
  server-wide flush/read lock or shared Apache shutdown was used.
- Restored into an owned disposable MariaDB **10.4.32** container, not the earlier
  MySQL 8 fixture. All 31 original table fingerprints matched the backup snapshot,
  including authentication, accounts, summaries, feedback, analytics and research
  evaluation records. No personal rows were exported into this report.
- Applied 017 twice on the restored copy and tested the quota/role rules there.
  All original table fingerprints still matched. Only then applied to live MySQL.
- Verified 27 original protected tables unchanged afterward. Four ordinary mutable
  visitor/rate-limit/landing telemetry tables were excluded from the live comparison;
  all 31 were checked in the restore/stage. Browser probes may create/touch normal
  visitor/trial records, but no actual summaries or accounts were generated.

Protected backup directory, outside the web root and repository:

```text
C:\Users\Robert Martin\AppData\Local\LightSummarizer\Backups\student-access-mysql-20261023T032202Z
```

`database.sql.dpapi` and `baseline-private.json.dpapi` use Windows DPAPI CurrentUser
encryption. Decryption round-trips were verified before plaintext deletion;
directory ACL inheritance is disabled and access limited to the current Windows
user and SYSTEM. Temporary credential files were deleted. Keep the Windows
account/profile recovery capability: this is a local migration backup, not an
off-site disaster recovery guarantee. The decrypted SQL archive's SHA-256 is:

```text
5bb57449db8edf0f61450d935e85de5d5c3d497fbdf098ec873a0fda41a3af05
```

The owned restore container and its anonymous database volume were removed after
verification. Encrypted backup/evidence, actual databases and cached images remain.
Deleting temporary files is not a claim of forensic secure erasure.

## Executed checks

- Seven staged and seven live transactional access checks passed: new guest has
  three, failed requests cost zero, three successes exhaust quota, fourth request
  is denied, existing admin access is preserved, existing students are not
  automatically verified, and all probe records roll back. No NLP was called.
- Five live access-table definitions exactly match the successful stage.
- Real Apache HTTP checks: status 200/three attempts, summarizer 200/form present,
  setup message absent, refreshing costs zero and trial cookie is HttpOnly.
- [Eleven real-browser checks](tests-live-access-repair.json) passed on the actual
  localhost site, including dark-theme initialization, visible form/counter, six
  unchanged depths, mobile/no-horizontal-overflow and no-JavaScript form visibility.
  [Restored-page screenshot](access-restored-dark.png) contains no logged-in data.
- CLI default resolves to MySQL; retention preview succeeds there with zero due
  applications and **without** applying deletion. Physical scheduling was not added.
- PHP syntax, browser-script syntax and Git whitespace checks passed. Protected
  38 NLP source/config and 46 historical research hashes remain unchanged; automatic
  article routing remains disabled. Historical 242-check reports were not rewritten
  or presented as newly rerun tests.

The database skill informed backup/isolation and preservation checks. MariaDB's
[backup documentation](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump)
was used for the native dump options; no PostgreSQL DDL was applied to MySQL.

## Recovery and remaining limits

Prefer a reviewed roll-forward fix; leave the additive tables and user records in
place. Do not DROP access tables to roll back: they may already hold quota/review
records. MariaDB DDL auto-commits; successful staging does not turn it into an
atomic multi-statement PostgreSQL migration. The tested SQL uses IF NOT EXISTS,
and replay was exercised in the isolated copy, not a claim of universal schema
shape validation on unrelated databases.

If recovery is needed, decrypt under the same Windows user into a new protected
location, verify the SQL hash above, and restore only into a new empty authorized
database. Reconcile post-backup writes before cutover. Never import the dump over
the populated live database or point the website at empty Neon to bypass the issue.

**Access-setup issue: PASS / FIXED.** Wider release readiness remains conditional:
student registration still needs a responsible operator privacy contact and stable
HMAC key; these were not invented or changed. Physical three-day erasure still
requires monitored scheduling and backup retention controls. SMTP, real enrollment
review, full live generation/PDF/DOCX/URL processing, hosting load and complete
regression/penetration testing were not performed by this access-only follow-up.

See [machine-readable evidence](mysql-web-access-repair.json). The dated earlier
backend findings are retained as history; this record supersedes their pending
MySQL authorization/unavailable-site status. No frozen research evidence was edited.
