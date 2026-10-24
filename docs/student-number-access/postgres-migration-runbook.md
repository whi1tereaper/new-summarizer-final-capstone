# PostgreSQL migration execution and recovery

Status: prepared/tested locally, **not authorized or executed on the configured
database**. Read the [repair report](postgres-schema-repair.md) and current JSON
preflight first. Do not run the older MySQL migration tool or reimport/reset the
PostgreSQL base schema to fix an existing database.

## 1. Authorization, backup and isolation

Obtain explicit authority for the exact database/Neon branch before any applying
command. Confirm responsible operator, rollback decision-maker and maintenance
window. Pause web writes, summary workers and retention jobs for the migration
window; existing generations must drain. Ordinary index creation and the inline
backfill take locks and may copy substantial text. This is not zero-downtime DDL.

Review `DATABASE_URL` and `DATABASE_URL_UNPOOLED` privately. The latter must be the
same endpoint/database/role/port without the Neon `-pooler` suffix. Never paste
connection URLs, passwords, student records or full environment files into logs,
commits or shell arguments. Keep the existing PDO provider and authentication.

Back up using a PostgreSQL 18-compatible client, a protected libpq service entry
and protected password file. Protect the backup from web access/source control;
encrypt it at rest. The following are operator templates, **not commands already
run against the configured database**. `light_direct` and `light_restore_test`
must be separately configured services, with the latter targeting a NEW EMPTY
authorized test database, never the existing database.

```powershell
pg_dump --dbname="service=light_direct" --format=custom --file="<protected backup path>"
pg_restore --list "<protected backup path>"
pg_restore --dbname="service=light_restore_test" --single-transaction --exit-on-error "<protected backup path>"
```

Inspect the dump as appropriate for its trusted source. Record backup checksum,
timestamp and restored row/constraint checks privately. A successful `--list` is
not a successful restore. The local rehearsal preserved synthetic records; it
does not replace this deployment-specific recovery test. Do not use `--clean`,
in-place restoration, a database reset or destructive down migration. See the
[PostgreSQL pg_restore documentation](https://www.postgresql.org/docs/18/app-pgrestore.html).

Use synthetic records where possible. If authorized real records are copied,
restrict access and carry over the three-day detail retention/read barriers and
backup policy. Do not publish a production dump as test evidence.

## 2. Read-only checks

From the repository root with the intended private runtime configuration:

```powershell
C:\xampp\php\php.exe tools/migrate_student_access.php
```

Default behavior is read-only, with a 15-second statement timeout. `--report`
additionally replaces the local diagnostic `postgres-repair-preflight.json`, not
database data; preserve the original evidence separately when auditing a new
deployment. `tools/inspect_postgres_contract.php` prints schema/count metadata;
its `--report` refuses to overwrite the initial catalog capture. These commands
still require authorized access to schema metadata, not public exposure.

Review the JSON `contract.issues` and version statuses, not just the exit code.
Before initial deployment the known pending additions are expected. Stop on a
different legacy layout, incompatible existing types/keys, missing base tables,
conflicting inline content, unexpected schema/search path, or unavailable direct
credentials. Reconcile unexpected records without deleting or inventing content.
For file-only completed summaries, authorized recovery of the actual source/result
is required; a path alone does not justify fabricating an inline text pair.

Require the report's three exact SQL hashes. A recorded migration without a
matching checksum is an explicit review stop. Do not edit a ledger checksum to
make a failing migration appear successful. Prior manually applied unversioned
migrations require a separately audited adoption plan; there is no force option.

## 3. Apply only after explicit approval

The applying command below changes the configured database. It was **not run**
as part of this task:

```powershell
C:\xampp\php\php.exe tools/migrate_student_access.php --apply
```

The runner uses the existing direct PDO connection. It checks the direct target,
requires `pdo_pgsql`, takes a transaction advisory lock and executes **018, 019,
then unchanged 017** in one transaction. Do not sort or run these files through
the MySQL migration runner. Lock acquisition is limited to five seconds and each
SQL statement to 120 seconds; there is no whole-request SLA implied by these
limits. The CLI needs table ownership/DDL permissions; ordinary web users should
not receive extra privileges to make a migration work.

The existing `schema_migrations` ledger gains `checksum CHAR(64)`. Exact matching
versions are skipped; checksums and postconditions are still checked on replay.
Failure rolls back new DDL and row modifications, including the ledger changes.
PostgreSQL sequence allocations are not transactional: harmless ID gaps can remain
after rollback. Never reset sequences to close those gaps or assume the full
physical database state is bit-identical. See
[PostgreSQL sequence behavior](https://www.postgresql.org/docs/18/functions-sequence.html).

On timeout/constraint/postcondition failure, keep writes paused, confirm rollback,
capture only sanitized diagnostics, and investigate using a separate authorized
test target. Do not remove constraints, overwrite old records or raise timeouts
blindly. A lost connection around COMMIT has an uncertain outcome: reconnect and
inspect ledger/checksums and contract before retrying; do not assume it failed.

## 4. Postflight and staged release

Rerun the default read-only command. Require `contract.compatible=true`, zero
content-conflict counts and all three `applied_checksum_matches` statuses. Compare
protected pre/post account counts, original account/hash/role fields, summary
content and audit/verification records in authorized tooling. Never export personal
rows into the public report. A second authorized apply should skip all versions,
as already tested locally, but is not necessary merely to obtain a postflight.

Before real application collection, configure a real responsible-operator
`STUDENT_PRIVACY_CONTACT` and a stable secret-manager-managed base64 32-byte
`STUDENT_NUMBER_SECRET`. A student's active email is not that public contact.
Never regenerate the identifier secret on each deployment; erased numbers cannot
be reconstructed to rebuild old duplicate HMACs. Preserve existing secrets securely.

In authorized staging, exercise registration/number rejection/duplicates, pending
limits, three shared successful guest attempts, fourth-request rejection,
failures/concurrent requests, admin accept/decline/correction, RBAC/CSRF, audit,
existing login/remember-me/recovery, history, analytics and feedback. Complete actual
PDF/DOCX/live URL, detached worker, SMTP, privacy and hosting resource checks.
Local 242-check success is not a substitute for these tests.

Preview due erasure, then schedule the applying job only after its own approval:

```powershell
C:\xampp\php\php.exe tools/student_retention.php
# Approved private scheduler only; this erases due details:
C:\xampp\php\php.exe tools/student_retention.php --apply
```

Run at least every 15 minutes, monitor failures/backlogs, and drain batches (default
500 applications). Verify that account email/password/history remain private and
intact, review notes and application details expire, and minimal approval/HMAC/
expiry/audit remain. The scheduler and backup retention were not installed here.
Resume service only after postflight and the required operator release checks.
Automatic article routing remains disabled; this work never enables it.

## 5. Recovery after commit

There is intentionally no DROP-column/table rollback: new summaries, credentials,
review decisions or audit rows may already depend on the expanded contract.
Prefer a reviewed roll-forward fix. Retain compatible query/OTP guards when
rolling application code back; blindly restoring the old PHP can duplicate
artifact rows or mishandle used OTPs even while the additive schema stays intact.

If recovery from backup is necessary, stop writes and obtain separate approval.
Restore to a new isolated database or authorized recovery branch, validate it,
reconcile every post-backup account/summary/decision and coordinate a controlled
cutover. Do not overwrite the original database or discard post-backup user data.
Reapply required privacy erasure/read barriers before serving restored records;
a backup must not silently resurrect expired application details. Provider-level
restore permissions, retention and cutover were not exercised by this task.

## Isolated reproduction used for this repair

Use owned disposable containers only, labeled `light.test=postgres-schema-repair`:
PostgreSQL 18.6 (`postgres:18-alpine`, recorded image digest in the verification
artifact) on **127.0.0.1:55439** and MySQL 8 on **127.0.0.1:53319**. Both have
synthetic `student_test` databases; test credentials are fixed in the guarded
`scripts/student-access/test_bootstrap.php`, not read from the application's URL.
Provision these fixtures separately; the commands below write only to them.
`test_postgres_repair.php` also creates new `repair_*_<8hex>` databases and runs
backup/restore inside the owned container `light-pg-repair-test`.

```powershell
C:\xampp\php\php.exe scripts/student-access/test_postgres_repair.php
$env:STUDENT_REPAIR_TEST_DB=(Get-Content docs/student-number-access/tests-postgres-repair.json -Raw | ConvertFrom-Json).fixture_database
C:\xampp\php\php.exe scripts/student-access/test_access.php pgsql --existing-schema --repair-report --report
C:\xampp\php\php.exe scripts/student-access/test_access.php mysql --repair-report --report
C:\xampp\php\php.exe scripts/student-access/test_sql_compat.php pgsql
C:\xampp\php\php.exe scripts/student-access/test_sql_compat.php mysql
$env:LIGHT_STUDENT_FIXTURE='isolated-local-only'
C:\xampp\php\php.exe -S 127.0.0.1:58139 -t app/public scripts/student-access/http_router.php
# Separate terminal while this owned test server is running:
py -3.11 scripts/student-access/test_repair_http.py
C:\xampp\php\php.exe scripts/student-access/verify_integrity.php --report
```

The `STUDENT_REPAIR_TEST_DB` name is restricted to `student_test` or a generated
`repair_main_<8hex>` database, with fixed loopback host/port. Do not expose the
fixture router. The HTTP follow-up does not generate NLP output; original
implementation NLP/browser results are preserved separately. Replays replace
integration diagnostics, never immutable research evidence. Remove only verified
owned fixture servers/containers and anonymous test volumes after use; keep all
unrelated services and data.
