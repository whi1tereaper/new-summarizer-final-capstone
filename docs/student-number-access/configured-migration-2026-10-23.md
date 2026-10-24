# Authorized PostgreSQL migration and discovered web-backend split

23 October 2026 (Asia/Manila). **Neon migration: PASS. XAMPP web access: still
blocked; backend decision pending.** The user's "fix it" followed an explicit
request to authorize backup/verification and PostgreSQL migrations 018, 019, 017.
Those operations were completed. They did not repair the separate database used
by the currently running Apache process. No database was reset or truncated.

## Completed, verified PostgreSQL work

- Created a fresh private backup directory outside the web root/repository, with
  inherited permissions removed and access limited to the current Windows user
  and SYSTEM. Neither credentials nor database records were exported into Git.
- Used the existing configured direct connection and an exported repeatable-read,
  read-only snapshot. `pg_dump` imported that same snapshot, producing an 87,972-byte
  custom archive, SHA-256
  `c30c553a7cae932e9f60a7f0111ca7430c430bb44b91d50bf0eee64fd5d8774b`.
- Restored the real backup to an owned isolated PostgreSQL 18.6 container with
  `--single-transaction --exit-on-error --no-owner --no-acl`. All 18 original
  tables matched their snapshot fingerprints. Local owner/ACL remapping was
  necessary; original grants remain in the archive, not independently restore-tested.
- Ran the actual migration service on that restored copy and verified original
  field values again. Only then applied the exact previously tested **018 -> 019
  -> 017** files to the configured Neon database. The transaction committed.
- Read-only postflight reports all three `applied_checksum_matches`, a compatible
  21-table active contract and zero ambiguous/missing/conflicting summary counts.
  Original protected-table records matched the backup baseline after apply;
  mutable visitor/rate-limit/landing telemetry was excluded from that live
  comparison. No queued/processing jobs existed in the snapshot; the access gate
  was already failing closed. Shared Apache was not stopped or reconfigured.
- Tested the actual CLI runtime's pooled PostgreSQL connection: three guest
  successes, zero debit for failure, fourth request blocked, and all probe records
  rolled back. Six checks passed. No NLP output or fake account was persisted.
- Rechecked 38 NLP source/config and 46 historical research hashes: unchanged;
  automatic article routing remains false. The historical 242-check isolated
  repair results were not represented as a new full live regression run.

The PostgreSQL skill guided direct connection selection, consistent backup,
isolated restore/rehearsal and postflight preservation checks. No provider/role,
actual environment secret, frontend design or NLP algorithm was changed.

## Why the actual browser still fails

The localhost `access_status.php` endpoint still returned HTTP 503 after the PG
migration. Apache's private error log identifies `pdo_pgsql` as unavailable in
`apache2handler`, causing the existing `Database` factory to fall back to its
configured MySQL connection. PHP CLI loads PostgreSQL successfully. The on-disk
PHP/Apache configuration already enables the extensions/loads libpq, but that is
not proof that the existing Apache process has loaded them. No Apache reload or
PHP configuration edit was performed.

A separate read-only inspection of the configured fallback found:

| Actual running web database | Observed |
|---|---:|
| Server | MariaDB 10.4.32 (MySQL protocol) |
| Existing accounts | 35: 33 user, 2 admin |
| Summaries | 254 |
| Summary artifact rows | 245 |
| Documents | 25 |
| Feedback rows | 103 |
| Required student-access tables | All five missing |

In contrast the Neon backup contained zero accounts/summaries/documents/feedback,
14 guest sessions and two rate-limit rows. These databases must not be treated as
interchangeable. Enabling PostgreSQL in Apache without a deliberate data migration
would make existing web accounts/history disappear from the application's view,
even though the original MySQL records would still physically exist.

Earlier PostgreSQL evidence established the CLI target, not the actual Apache
backend. This live observation corrects that limitation. A landing-page HTTP 200
was not accepted as a working summarizer: the access API remained 503, and no
browser-facing success is claimed. Initial HTTP probes were insufficiently
fail-fast; their later output was not counted as a pass.

## Approval needed for the next step

Requested a specific choice: back up/restore-test and apply the existing MySQL
017 access migration to the current site (recommended for preserving current
accounts/history), or separately plan migration of the existing MySQL data to
Neon first. **No MySQL schema change, account alteration, cross-database transfer,
backend switch or automatic approval has been performed.** Normal HTTP diagnostics
may touch ordinary visitor/rate-limit bookkeeping; they did not generate summaries.

Do not reload Apache into a different backend until that decision is made. The
MySQL 8 fixture regressions are useful evidence but do not certify the actual
MariaDB schema; its own backup and staged migration are required before apply.
The selected web/backend choice should also be made explicit for future reloads
and background workers, rather than relying on accidental extension failure.

## Backup custody and recovery

Protected local directory:

```text
C:\Users\Robert Martin\AppData\Local\LightSummarizer\Backups\student-access-20261023T030238Z
```

`database.dump.dpapi` and `baseline-private.json.dpapi` are encrypted with Windows
DPAPI CurrentUser. Decryption round-trips were hash-verified before removing their
plaintext originals. Temporary service/password files were removed. The backup
receipt and private diagnostics remain in that restricted directory. This is a
local migration safety backup, not independently verified off-site disaster
recovery; preserve the current Windows account/profile's recovery capability.

For recovery, decrypt under the same Windows user into a new protected location,
verify the recovered archive against the SHA-256 above, and follow the
[new-empty-database recovery procedure](postgres-migration-runbook.md#5-recovery-after-commit).
Do not restore over either existing database or discard post-backup writes.
The migration intentionally has no destructive down script. The scoped helper
`tools/protect_student_backup.ps1` only encrypts/validates and removes exact temporary
files; it does not migrate or restore a configured database.

The owned restore container and its temporary database volume were removed after
verification. The encrypted backup, configured databases, cached image and other
services were retained. File deletion is not a forensic secure-wipe guarantee.

## Current readiness

**PostgreSQL active schema: PASS. Browser summarization access and overall
deployment: FAIL / NOT READY.** The actual MySQL access tables remain missing;
operator privacy contact/HMAC settings, erasure scheduling and full deployment
checks also remain unresolved. Student registration remains closed rather than
inventing a privacy contact or collecting unprotected student numbers.

See [machine-readable follow-up evidence](configured-migration-2026-10-23.json).
Earlier research/frozen manifests were not rewritten. The earlier repair inventory
describes the report versions at that time; this dated follow-up and the explicitly
superseding implementation-report note are later authorized documentation changes.
