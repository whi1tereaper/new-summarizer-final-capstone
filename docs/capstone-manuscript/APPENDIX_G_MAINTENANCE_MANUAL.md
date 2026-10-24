# Appendix G. LIGHT summarizer Maintenance Manual

## 1. Introduction

This manual guides the designated maintainer of LIGHT summarizer in preserving the application, its database, local processing services and stored content. It follows the reference manuscript's ten maintenance sections while describing the inspected LIGHT implementation. The baseline is the 17 October 2026 audit, not a claim of production certification. Procedures and schedules identified as proposed require adoption by the project owner; their inclusion does not mean they have been performed or automated.

The audit made documentation artifacts only. It did not import a schema, migrate the live database, purge records, change accounts, install a scheduled task, send email or restore a backup. Known defects remain in the application and are documented in the security/reliability report.

## 2. System Overview

LIGHT uses PHP pages and services, MariaDB storage and a local Python summarization worker. Users can supply text, an article URL or a supported PDF/DOCX. The main flow creates a recoverable job, sends the source and preferences to Python, stores the resulting summary/artifact and presents result/history actions. The source also includes a standalone document-combination API and a distinct evaluation subsystem; their storage and authorization behavior differ from the main flow.

The configured database metadata contains 31 tables, 312 columns and 35 foreign keys. The full data dictionary and actual-constraint ERDs accompany Chapter III. The runtime configuration calls the application `AI Article Summarizer`; LIGHT summarizer is the manuscript title. The current absent engine-version setting resolves to `legacy`; optional v2/LLM code must not be assumed active.

Table 58. Maintainer's path and component reference.

| Component | Verified repository location | Maintenance significance |
|---|---|---|
| Public PHP pages and APIs | `app/public` | Intended web document root; authenticated and anonymous flows begin here. |
| Controllers, services, repositories, utilities | `app/src` | Application logic; `whitereaper.php` bootstrap has database side effects. |
| Configuration | `app/config/config.php`, `.env` | Preserve secrets privately; audit documents do not contain credentials. |
| Schema and migrations | `database/schema.sql`, `database/analytics_schema.sql`, `database/migrations` | Definitions differ from live password-reset schema; verify before installation/upgrade. |
| Local NLP and evaluation engine | `python-engine` | Requires compatible interpreter, dependencies and separately provisioned optional models. |
| Runtime storage defaults | `storage/uploads`, `storage/audio`, `storage/logs`, `storage/tmp` | Protect from direct web access; preserve data according to an approved retention policy. |
| Worker authentication | `storage/worker.token` or configured secret | Keep access restricted; never include token in screenshots or reports. |
| Dependency definitions | `composer.json`, `composer.lock`, `python-engine/requirements-local.txt`, `requirements-evaluation.txt` | Record installed versions and test upgrades in staging. |
| Operational tools | `tools`, `scripts` | Many commands write state even when their names imply checking. |

Table 58 presents maintainer's path and component reference. The stated status and qualifications govern its interpretation.

## 3. Major Features

The inspected backend supports guest and registered-user contexts, account/password flows, administrator email verification, terms acceptance, eight analysis profiles, six main-summary depths, output formatting, text/URL/PDF/DOCX inputs, recoverable processing jobs, history/sharing, ratings/comments, translation and audio integration, usage analytics and administrator maintenance actions. The standalone combination API uses five lengths, omitting the main flow's `short` option. The evaluation service and CLI support versioned datasets, reference summaries, baseline/ablation comparisons, blind assignments, seven-criterion ratings, review corrections, metrics and exports.

These are implementation capabilities. Availability of SMTP, external translation, optional models and evaluation management browser controls has not been established by source existence. The current document API lacks owner checks, and the administrator status-toggle path bypasses the guarded deactivate operation. Restrict deployment exposure and resolve the documented defects through a separate authorized implementation task before relying on these paths with real participant data.

## 4. System Requirements

The inspected local PHP executable is 8.1.25; the queried database server is MariaDB 10.4.32. The application setup tool checks PHP 8.1 or later. Required PHP facilities include PDO/MySQL, cURL, DOM/libxml, fileinfo, JSON, mbstring, OpenSSL and enabled `proc_open`; DOCX package validation also uses ZIP. The audit's extension whitelist confirms these on the inspected executable, which may differ from the PHP instance used by a web server.

Python source uses union-type syntax and strict ZIP iteration, so the old setup statement “Python 3.8 or higher” is insufficient. Use a dependency-compatible Python 3.10-or-newer environment and validate the exact resolved packages; the confirmed isolated engine tests used Python 3.14. A particular version's compatibility with all optional model packages still requires testing. Composer supplies PHPMailer. Python requirement files define NLP, parsing, translation and TTS dependencies; optional embedding/BERTScore/NLI/LLM models require explicit provisioning and configuration. A package name in a requirement file is not proof that its model files exist.

Use a modern browser and an appropriately configured PHP web server. Apache rewrite/header/override configuration controls whether `.htaccess` protections apply; the PHP development server does not apply them. For deployment, configure the public root, TLS and private runtime paths deliberately. The worker listens on loopback and uses an authentication token. SMTP and the configured translation provider require their own connectivity; operation is not wholly independent of external services.

No measured minimum hardware or supported simultaneous-user count was established. Size CPU, RAM and disk using representative synthetic/approved workloads, chosen models and retention periods. The setup guide's small RAM/disk estimates should be treated as unvalidated legacy guidance. Record actual hardware and versions when reporting timings.

## 5. Preventive Maintenance Procedures

### Database and schema

Keep a versioned schema inventory and compare intended changes against the actual database before each release. Read-only inventory can use direct `information_schema` queries without application bootstrap. The audit's metadata collector is an example; it excludes application rows and credentials. Do not substitute `tools/migrate.php --status` for a guaranteed read-only check: it may create/seed the migration ledger.

Resolve the password-reset schema discrepancy in staging before a fresh installation. The service expects `password_resets.used_at`; the checked-in base schema defines `used` and `created_at`. Existing live metadata cannot prove a clean install works. Verify both an empty-database installation and an upgrade from a representative protected backup. Check constraints, migration ledger and critical flows after each test. Do not claim an automatic repair or run schema import against an existing production database as a troubleshooting shortcut.

### File storage and retention

Monitor free space, directory permissions and growth in uploads, audio, logs and recovery payloads. Coordinate deletion with active jobs and the approved retention policy. The administrator's “orphan” cleanup actually selects age over 24 hours and does not verify database references; its delete-all action is destructive. Avoid these controls as routine cleanup until their selection has been reviewed. Do not remove arbitrary files while a worker is using them.

Existing CLI cleanup scripts cover landing sessions, feedback comment events, administrator audit records and expired summary recovery data. The first three default to 90 days unless configured otherwise. Their existence does not establish scheduled execution or the appropriateness of that retention period. Their `--dry-run` modes load application bootstrap, which may touch guest state and trigger guest cleanup. Validate proposed cleanup on a clone and use direct count queries when a strictly read-only estimate is needed. Guest-token claiming and expiry do not currently cover every feature store; retention documentation must acknowledge that gap.

### Security and dependency maintenance

Review account privileges and administrator access, audit events and unexpected processing failures. Keep `.env`, worker tokens, backup files and logs outside public access and grant only the required service accounts access. Use TLS for deployment, inspect effective response/cookie headers, and verify proxy assumptions. Store passwords as hashes through the application; never document plaintext account credentials. Password reset currently does not revoke every persistent token/session, so a reset must not be represented as a complete session-revocation action.

Review dependency updates in staging, retain lock/version records and run relevant tests before deployment. An unverified blanket package upgrade can change extraction, model output and scoring. Preserve previous version/configuration evidence and a rollback plan. Rotate any exposed secret through a controlled operation, update dependent services and verify it without printing the value.

### Worker and performance monitoring

Inspect queue age, failure categories, job retries, disk consumption and worker error logs. The Windows scripts can install a limited-privilege current-user logon monitor that checks health every 30 seconds. Installation/running state was not verified. The start script probes protocol 3 and a supervised child self-test; it may terminate a verified stale project worker before restart. Review active work before using start/stop/remove scripts.

The configured 180-second local processing timeout and 600-second TTS timeout are settings, not measured completion times; supervisor limits may further constrain execution. Track processing durations using trustworthy instrumentation. Existing uploaded-source word-count and main-job timing persistence gaps limit current analytics. Evaluate latency and resource usage with synthetic or approved sources under stated hardware and concurrency.

### Proposed backup and recovery procedure

The following is a proposed operational procedure, not existing automated backup functionality or a completed recovery test.

1. Identify an accountable operator, backup destination, retention period, encryption/access controls and a recovery window. Record the application revision, PHP/Python/database versions, model inventory, schema and configuration key names. Keep secret values in a separate protected store.
2. Schedule a coordinated maintenance window or use a documented database/file consistency strategy. Pause new processing and wait for active jobs to settle when consistent application/file snapshots cannot otherwise be guaranteed. Capture the database with a compatible MariaDB/MySQL logical-backup utility using an operator-managed login/option file; do not place passwords in command history. A transactional snapshot protects InnoDB consistency but does not by itself synchronize files or concurrent schema changes.
3. Include the database, required stored user artifacts, deployment source/revision, dependency records and protected configuration recovery material. Record hashes, creation time, size and tool version. Store an encrypted access-controlled copy separately from the running host. Verify that backups can be read, rather than relying only on a command exit code.
4. Restore first into a separately named disposable database and isolated storage tree on a staging host. Set the clone's configuration to those targets before loading any application code. Disable real email, external callbacks and scheduled jobs; use synthetic identities or an approved protected data-handling procedure. Never restore over the live database merely to test a backup.
5. Validate schema/constraints, representative record relationships, file accessibility and critical synthetic flows. Check that job state, audio, history and evaluation artifacts agree. Exercise login/reset only with staging-controlled delivery. Record elapsed restoration time and data-loss window as measured observations; do not invent recovery-time or recovery-point guarantees.
6. If a real recovery is required, obtain the responsible owner's deployment decision with a reviewed backup identifier, target, expected data-loss window and rollback procedure. Preserve the pre-recovery state where feasible, perform the approved cutover and recheck critical flows. Record who acted, what changed and the outcome.

## 6. Troubleshooting Guide

Table 59. Evidence-based troubleshooting guide.

| Symptom | Plausible inspected cause | Maintainer action |
|---|---|---|
| Cannot connect to database | Service stopped, wrong local configuration, missing PDO extension | Check service/configuration privately and compare CLI/web PHP extensions; do not print credentials or run schema import against live data. |
| Reset fails after fresh installation | `used_at` absent from base-schema installation | Compare actual metadata with service SQL; prepare a staging-tested compatibility migration. |
| Administrator cannot complete login | OTP expired, SMTP unavailable, rate limit or inactive account | Review safe server logs and email transport configuration; use the current email-OTP flow rather than obsolete security-question instructions. |
| Upload rejected | PHP/form limit, unsupported format, MIME/package failure, permission issue | Check the displayed effective limit and uploaded file integrity; verify private upload permissions. Do not blindly raise limits. |
| Ordinary text reported unreadable | Cleaning rules can remove an entire paragraph containing navigation-like words such as “register” | Preserve a synthetic reproduction, inspect cleaned-text diagnostics and escalate the documented cleaning defect. Do not claim the original source was inherently unreadable. |
| Job remains queued/running | Worker unavailable, stale state, timeout or process permission failure | Inspect job/log evidence; recovery reads may change stale state. Review retry eligibility and active process before restarting. |
| Python command unavailable | Windows app alias or mismatched environment | Use an explicit configured interpreter path and verify packages in that environment. |
| Translation unavailable | External provider/network/configuration failure | Check provider configuration and safe errors; avoid sending confidential text to a provider without an approved policy. |
| Audio missing or seeking inconsistent | Model/binary unavailable, worker timeout, missing cache file or suffix-range defect | Verify model paths and logs in staging; distinguish generation failure from the reproduced HTTP range bug. |
| Guest output disappears after login | Incomplete guest-to-user claiming across stores | Inspect ownership in a controlled test; do not promise every feature's guest data migrates. |
| Summary analytics inconsistent | Uploaded-source count/timing omission or failures stored only as jobs | Reconcile the metric's data source; do not infer research effectiveness from dashboard totals. |
| Evaluation metric missing | Optional model/reference unavailable or explicit worker failure | Preserve missing/error status, dependency versions and provenance; do not replace missing values with zero. |
| Private source visible by document ID | Document API lacks owner validation | Restrict exposure and prioritize the ownership fix; validate denial using synthetic accounts in staging. |

Table 59 presents evidence-based troubleshooting guide. The stated status and qualifications govern its interpretation.

## 7. Roles and Responsibilities

The designated administrator manages application accounts, reviews logs and coordinates retention and access. The deployment maintainer manages the web/database/Python environment, secrets, backup validation, worker scheduling and release records. Developers investigate defects, implement reviewed changes and preserve regression evidence. Researchers maintain corpus provenance, consent/permissions where required, evaluation assignments and interpretation of results. Assigned evaluators independently score their allocated summaries; evaluator assignment is not a separate stored application role. Ordinary users remain responsible for choosing permitted source material and reviewing generated text against the original.

These responsibilities are proposed operating assignments. The audit did not identify or appoint production staff, verify institutional approval, or certify any completed maintenance activity.

## 8. Data Security and Privacy

Treat uploaded text, generated summaries, original artifacts, recovery payloads, audio, account details, telemetry and evaluation comments as potentially sensitive. Selected masking of original-text fields does not guarantee that generated evidence or summary text is anonymous. An “Anonymous” feedback label does not remove every stored relationship. Share links grant access to their holders and should be distributed accordingly. Audio currently carries a public long-lived cache directive despite access checks; review this before deployment.

Collect only content needed for the approved use, define retention for every store and review third-party transmission paths. Limit backup/export access, redact secrets and direct identifiers from screenshots, and inspect free-text notes before publishing evaluation exports. The applicable institutional and legal requirements require responsible review; implementation controls alone do not establish compliance. No claim of completed ethical approval, participant consent, anonymization or legal certification is made here.

## 9. Maintenance Schedule

Table 60. Proposed maintenance schedule; adoption and execution are not verified.

| Frequency / trigger | Proposed activity | Evidence to retain |
|---|---|---|
| Each operating day | Review failed/stale jobs, worker errors and disk space; create a protected database/artifact backup according to the adopted consistency plan | Dated checks, backup ID/hash and exceptions |
| Weekly | Verify backup readability, review access/audit events, inspect storage growth and tested retention candidates | Review record and approved deletion preview |
| Monthly | Review accounts, dependencies, secrets/access controls and data-retention exceptions | Account/dependency review and change tickets |
| Each release | Test installation and upgrade in staging; validate critical flows, schema and representative output; preserve rollback materials | Versioned test report, schema comparison and release decision |
| Quarterly | Restore into isolated staging and measure recovery; review privacy/export practices and optional model configuration | Restore log, measured recovery figures and unresolved findings |
| Annually or after a major change | Review architecture, threats, capacity assumptions and maintenance ownership | Updated audit/manual and responsible-owner sign-off |
| Security incident or failed backup | Preserve evidence, restrict affected service as appropriate, follow the approved incident/recovery decision | Incident timeline, actions, recovery validation and follow-up |

Table 60 presents proposed maintenance schedule; adoption and execution are not verified. The stated status and qualifications govern its interpretation.

An existing worker monitor checks every 30 seconds when installed, but that is a process-health mechanism rather than the proposed database backup or maintenance schedule. Cleanup scripts are available; their scheduling and successful operation were not established.

## 10. Conclusion

Maintenance of LIGHT requires a current schema inventory, controlled source/dependency changes, restricted storage and secret access, observable processing, and verified recovery procedures. The immediate priorities are the documented authorization, account-status, reset-token, schema-installation and text-cleaning defects. Record each maintenance action and its evidence so future maintainers can distinguish implemented behavior, tested behavior and proposed practice.
