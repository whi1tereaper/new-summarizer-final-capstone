# PostgreSQL student-access schema repair

23 October 2026. **Isolated validation: PASS. Configured deployment readiness:
FAIL / NOT READY.** The configured database was inspected read-only; no migration,
account change, retention deletion or application write was executed against it.
Use the [execution and recovery runbook](postgres-migration-runbook.md) only after
separate operator authorization. This is not a production compatibility certificate.

## Root cause and observed database

The configured provider is Neon PostgreSQL 18.6 (`server_version_num=180006`).
The runtime URL is pooled and a corresponding direct URL is configured. The
[catalog capture](postgres-schema-before.json) contains column/default/type,
constraint and index metadata for 18 tables, plus aggregate counts, not user rows,
passwords, tokens, source articles or connection credentials. It was collected in
a read-only transaction with a 15-second statement timeout.

The live structure matches `database/schema.postgres.sql`, an older PostgreSQL
bootstrap. The later PHP repositories expect the contracts established by the
MySQL migration stream: normalized inline summary artifacts, selector/validator
remember-me tokens, timestamped OTP usage and expanded administrative audit data.
`tools/migrate.php` is MySQL-oriented; importing the PostgreSQL bootstrap does not
translate or replay those changes. Its existing `schema_migrations` table has no
recorded migrations. This is application/bootstrap drift, **not evidence of a Neon
database defect**. Student access 017 was deliberately never applied to this DB.

At inspection there were zero user, summary, authentication, student application
and audit rows (the student tables do not yet exist); guest sessions and rate
limits had 14 and 2 rows respectively. These counts do not justify destructive
replacement: the repair also tests populated legacy and already-verified records.

## Exact current core mismatches

The [read-only preflight](postgres-repair-preflight.json) records 32 issues:
18 missing columns, five unapplied access tables, seven blocking legacy NOT NULL
requirements, one insufficient hash-column width and one missing unique key.
The following column types are the additions, not forced conversions of existing
IDs or timestamps.

| Table | Missing columns and target PostgreSQL types |
|---|---|
| `summaries` | `article_category VARCHAR(100)`, `original_word_count INTEGER`, `summary_word_count INTEGER`, `processing_time NUMERIC(10,3)`, `nutshell_word_count INTEGER`, `nutshell_generated_at TIMESTAMPTZ` |
| `summary_artifacts` | `original_text TEXT`, `generated_summary TEXT` |
| `password_resets` | `used_at TIMESTAMPTZ` |
| `user_tokens` | `selector VARCHAR(255)`, `hashed_validator VARCHAR(255)`, `expires TIMESTAMPTZ` |
| `admin_audit_logs` | `admin_id INTEGER`, `entity_type VARCHAR(100)`, `entity_id BIGINT`, `user_agent VARCHAR(255)` |
| `nutshell_generations` | `input_type VARCHAR(20)`, `word_count INTEGER` |

The five missing access tables are `student_applications`,
`student_review_events`, `summary_access_subjects`, `student_trial_links` and
`summary_access_reservations`. They are an unapplied feature migration, not
evidence of deleted student data.

The seven legacy NOT NULL requirements that prevent current inserts are:

- `summaries.original_text`, `summaries.generated_summary`: current writers save
  content in the artifact row within the same result/quota transaction.
- `summary_artifacts.artifact_type`, `summary_artifacts.storage_path`: current
  inline text rows are not filesystem references.
- `user_tokens.token_hash`, `user_tokens.token_type`, `user_tokens.expires_at`:
  current remember-me writers use selector/validator/expiry instead.

`password_resets.token_hash` is VARCHAR(64), versus the application's expandable
VARCHAR(255) hash contract. Current bcrypt hashes are 60 characters: truncation of
an actual current hash was **not** observed. The missing `used_at` column is an
immediate query failure; widening also avoids a future hash-algorithm trap.
`user_tokens.selector` needs a non-partial unique key, supplied by `selector_idx`.
Legacy `used=1` OTPs must remain unusable after adding `used_at`.

Additional constraints/indexes cannot all be reported as independent preflight
errors while their entire tables or columns are missing. The migration supplies:

- `summary_artifacts_text_pair` and `summary_artifacts_supported_contract` CHECKs;
  `uq_summary_artifacts_inline`, unique on `summary_id` only for complete inline
  pairs. Multiple historical file-reference rows remain supported.
- `user_tokens_supported_contract`, allowing a complete legacy token contract or
  a complete current one; nullable legacy fields are not unrestricted fake tokens.
- `admin_audit_logs.admin_id -> users.id ON DELETE SET NULL`. Existing `user_id`
  references are preserved; their actor IDs are copied only into empty `admin_id`.
- Migration 017's unique application reference/user ID/normalized number/HMAC,
  valid status checks, three-success range check, and foreign keys to users,
  applications and quota subjects. Deletion rules remain those in 017; this repair
  never deletes an account or exercises cascading account deletion.
- Indexes `idx_summaries_status_created`, `idx_summaries_analytics_user_created`,
  `idx_summaries_nutshell_generated`, `idx_ng_created`, `idx_ng_user_created`,
  `idx_ng_summary`, `idx_password_resets_active`, `idx_admin_audit_logs_admin_id`,
  `idx_admin_audit_logs_action`, `idx_admin_audit_entity`; 017 also supplies its
  queue, retention, reviewer, trial-subject and reservation indexes.

The post-migration gate checks 21 active-contract tables, required types/widths,
blocking nullability, relevant valid uniqueness/foreign keys/status/quota checks,
and aggregate summary-content integrity. It is a conservative gate for the
inspected architecture, not a proof of every constraint on arbitrary schemas or
an index-performance benchmark. Unknown incompatible layouts fail rather than
being coerced or rebuilt.

## Minimal expand/backfill repair

| Execution order | File | Purpose |
|---|---|---|
| 1 | `database/migrations/postgres/018_summary_contract_compat.sql` | Expand summary/artifact/Nutshell contracts; preserve original text and file references; backfill one inline pair from existing legacy text where none exists. |
| 2 | `database/migrations/postgres/019_auth_audit_contract_compat.sql` | Expand OTP, remember-me and audit contracts without resetting accounts or inventing credentials. |
| 3 | `database/migrations/postgres/017_student_number_access.sql` | Existing unchanged five-table student/quota feature migration. |

SHA-256 values, also recorded with the successful test:

```text
018_summary_contract_compat.sql
f5f79c2130d9a8a1cbf51d0a07a67d504f2761e8685e59ad7a1b97bccd149903
019_auth_audit_contract_compat.sql
1e58c0702fb7e1d2df4d7667c87c8c96581ca18d8b3eef8e768b4015728f5411
017_student_number_access.sql
73d54fd8e05933ee191d47f25edd1e399309631e4f148060d67a30e269695a7b
```

Historical summary word counts/timings and unknown Nutshell input types/counts
stay NULL, not fabricated measurements. No file content is guessed from paths.
Ambiguous inline duplicates, conflicting old/current content and completed
summaries without readable content abort the transaction for human reconciliation.
Legacy remember-me hashes cannot be converted into current credentials; their
rows remain intact, without granting invented logins. Original used OTP flags
remain authoritative and historical usage times are not fabricated.

`PostgresAccessMigrator` applies all three files in one transaction under an
advisory transaction lock, with a five-second lock timeout and 120-second
per-statement timeout. It adds a checksum column to the existing migration ledger,
records SHA-256 versions and skips matching applied versions. Missing/changed
checksums are rejected, not silently adopted. A failing postcondition rolls back
DDL and row changes. Sequence allocations can still leave harmless gaps; see the
runbook. Ordinary index creation/backfill may block and needs a maintenance window.

The CLI uses existing PDO configuration/direct-connection machinery. It rejects a
pooler migration endpoint, a mismatched database/host/role/port, or a missing PG
driver instead of silently migrating MySQL. No provider, role, extension, actual
environment secret or PostgreSQL base bootstrap was replaced.

## PHP dialect and compatibility repairs

DDL alone did not resolve the affected active workflows. Small query changes are
limited to the surrounding persistence, authentication and administrative routes:

- `HistoryRepository`, Nutshell endpoint and saved-setting analytics select only
  complete inline artifact pairs, avoiding duplicated history from file references.
- `AnalyticsController` replaces MySQL boolean SUM/DATE_SUB/CURDATE/CAST SIGNED/
  FIELD/SUBSTRING_INDEX constructs with portable CASE, explicit numeric division
  and driver-specific date/string expressions. PG calendar buckets use the PHP
  timezone. All six depth choices remain unchanged. Internal `generated_count` /
  `stored_count` aliases avoid reserved MySQL words without changing API keys.
- `FeedbackRepository` / `FeedbackService` use SQL-standard string quoting,
  fractional division and the correct driver-specific retention interval.
- `PasswordResetService` honors the old PG `used` flag as well as `used_at`;
  new PG OTP expiry includes an explicit offset. `RememberMeService` and
  `GuestSessionService` likewise supply offset-aware PG expiries. MySQL formatting
  and its current `used_at` contract are retained.
- `AdminController` removes MySQL-only LIMIT from primary-key UPDATE/DELETE.
  `admin_audit_logs.php` and `admin_db_health.php` use PG catalog checks when on PG,
  while keeping existing RBAC, response shapes and MySQL behavior.

No NLP implementation, depth configuration, frontend design, student review
policy or existing authentication provider was changed by this repair.

## Executed evidence: 242 checks, zero failures

All database writes below used owned disposable loopback containers and synthetic
identities. The PG fixture starts from the unchanged older bootstrap and verifies
its columns/types/nullability against the actual read-only Neon catalog capture.
It uses PG 18.6, not the earlier PG 16 normalized fixture. Full default/constraint/
index metadata is retained for inspection; no full live-data clone was taken.

| Evidence | Passed | Scope |
|---|---:|---|
| [Legacy schema repair](tests-postgres-repair.json) | 58 | Actual migrations, twelve populated tables' original fields preserved, already-verified application preserved, backfill/history, idempotence, checksum rejection, data/DDL rollback, unsafe/ambiguous input rejection, real pg_dump/restore rehearsal. |
| [PG access integration](tests-pgsql-repair.json) | 59 | Migrated legacy schema; registration/number validation, pending/decline/correction/approval, owner/admin/CSRF rules, reservations/debit/failure, retention, eight concurrent PHP workers. |
| [MySQL access regression](tests-mysql-repair.json) | 59 | Existing MySQL 8 fixture/017 contracts, equivalent transactional/race and access checks. |
| [PG query regression](tests-sql-compat-pgsql.json) | 20 | Real PDO analytics/history, fractional metrics, six-depth ordering, feedback, audit, OTP replay prevention and token storage. |
| [MySQL query regression](tests-sql-compat-mysql.json) | 20 | Same query contracts on MySQL 8, including actual Nutshell counts rather than silent fallback. |
| [PG HTTP integration](tests-postgres-repair-http.json) | 26 | Real PHP registration, decline, owner correction, evidenced approval, login/logout/history/admin/audit/analytics/health routes; privacy and CSRF denials. No NLP execution in this follow-up. |

The quota tests admit exactly three successes among eight concurrent workers; five
requests receive expected busy/limit denials. Failed/rolled-back results cost zero.
The transaction suites use controlled generation doubles, not NLP quality tests.
The HTTP suite bypasses admin SMTP login only inside its guarded local test router;
review decisions and registration use actual controllers and database transactions.

The backup rehearsal runs actual custom-format `pg_dump`, then `pg_restore
--single-transaction --exit-on-error` into a separate empty fixture database and
checks representative original rows. It does not establish recoverability of a
real production backup, external filesystem or provider-level point-in-time restore.

Early harness failures (fixture primary-key/date-column assumptions) were corrected
before the recorded runs. Cross-backend SQL tests exposed the reserved aliases;
those queries were repaired and rerun. Passing counts describe the final runs, not
a claim that development had no failures.

## Three-day retention and personal information

Tests force due timestamps, execute `StudentRetentionService`, and verify erasure
of application name, raw/normalized number, eligibility/ownership references,
current reason, and old review notes/student notifications. Pending requests move
to correction; unapproved duplicate claims are released. Minimal status/campus,
reviewer/time, established period/expiry, consent metadata, protected HMAC and
actor/action/status/time events are retained as required by the existing policy.
Approval remains usable until its actual expiry/suspension, not merely three days.
Separate private account email/password and owned summaries survive unchanged.

Ordinary users cannot read admin application details or database health. Public
access-status JSON contains neither student email nor number. Feedback admin
projections do not disclose account email; audit compatibility tests use no personal
numbers/email in audit details. The catalog/preflight exports contain only schema
and counts. This is targeted evidence, not a complete penetration test or legal
compliance finding. An authorized admin still has intended access to review data.

The configured privacy contact and stable HMAC secret remain unset. Account email
is private student contact information, **not** an operator privacy contact.
Physical erasure still requires a monitored scheduler, and old backups/logs/copies
need their own retention policy. HMAC identifiers are pseudonymous personal data.

## Neon compatibility and remaining limits

Read-only connectivity with the existing pooled application URL and direct
preflight works. PG syntax is executed against matching PG 18.6 locally. Migration
DDL is intentionally direct/unpooled, consistent with the
[Neon pooling guidance](https://neon.com/docs/connect/connection-pooling).
This verifies configuration shape/read connectivity and local SQL behavior, **not
an authorized write migration or complete live Neon deployment**.

The captured `pg_stat_ssl` backend-hop flag is false behind the pooler. It does not
prove that the client connection lacks TLS; the existing PHP factory requests
`sslmode=require`. Certificate identity verification, credential rotation, provider
backup/recovery permissions and network security were not independently audited.

`admin_security_challenges` has an older layout but its old table-backed flow is
not executed by current session/email-OTP admin login. It is preserved untouched.
The 13 MySQL `evaluation_*` tables and MySQL-specific `EvaluationService` are also
absent/unported in PG. Public `evaluation.php` currently redirects to the dashboard;
activating the dormant research subsystem requires a separate migration/review.
This repair does not certify that subsystem or modify its research artifacts.

Other unproven deployment conditions include real registrar/ownership review,
SMTP delivery/admin OTP/password-recovery email, PDF/DOCX/live URL extraction,
detached workers, complete date-boundary semantics, large datasets/locking,
concurrency under actual web hosting, cold model memory/timeouts and full regression
coverage. The prior implementation's NLP/browser checks remain historical evidence,
not newly executed tests of this database repair.

[Integrity replay](integrity.json) confirms 38 NLP source/config hashes and 46
historical research artifact hashes unchanged; automatic article routing stays
false. Existing unrelated dirty-worktree changes are preserved.

## Release decision

- **PASS:** repair migrations and active-flow query compatibility on the isolated
  representative PG architecture, preservation/rollback/restore checks, selected
  MySQL regressions and targeted privacy/access/retention checks.
- **FAIL / NOT READY:** configured deployment. The actual DB remains unmigrated,
  operator privacy/HMAC settings and maintenance scheduling are unresolved, and
  real staging/deployment checks have not passed. Do not expose real student
  application collection or claim production compatibility from these tests.

[Final verification](postgres-repair-verification.json) records the six test
reports, 20 passing PHP syntax checks, Python test compilation, passing Git
whitespace check and scoped SHA-256 inventory. The owned local PHP server, two
labeled fixture containers and their anonymous synthetic-data volumes were removed
afterward. Their temporary dump/restore data was intentionally discarded; reports
remain. No real database, unrelated service or cached container image was removed.

The PostgreSQL skill guided direct, guarded additive migrations, transaction/data
preservation checks and isolated backup/restore validation. It did not introduce
another provider, authentication framework or database provisioning workflow.
