# Evaluation installation and operations

Framework version: `1.0.0`. This feature extends the existing PHP/MySQL/Python installation. It does not replace production summarization, history, authentication or analytics. Run commands from the repository root with the existing `.env` configuration. The examples use PowerShell and the project's Python 3.11 environment; use the interpreter configured by `LOCAL_PYTHON_BIN` if different.

## Database migration

Back up the existing database using the deployment's normal backup procedure, then inspect pending migrations:

```powershell
php tools/migrate.php --status
php tools/migrate.php
```

The migration runner applies **all pending migrations**, so inspect the status first. This implementation added only `012_evaluation_framework.sql`; migrations 001–011 were already applied in the development workspace. Migration 012 uses `CREATE TABLE IF NOT EXISTS` and does not alter or delete existing production records. Web requests never execute schema DDL.

Migration 012 creates these 13 tables:

- `evaluation_datasets`, `evaluation_documents`, `evaluation_references`
- `evaluation_content_units`, `evaluation_challenge_cases`
- `evaluation_runs`, `evaluation_outputs`, `evaluation_metrics`
- `evaluation_assignments`, `evaluation_ratings`
- `evaluation_errors`, `evaluation_content_unit_reviews`, `evaluation_audit_log`

`users` remains the existing `user`/`admin` role model. Evaluation management requires a signed-in, active administrator account. At the user's request on 2026-10-02, the separate anime/number administrator challenge was removed; password login and valid remember-me authentication now establish the administrator session directly. A session containing only the retired pending-admin state must sign in again. An active ordinary account can read and submit only its explicitly assigned assessments. No new authentication role is needed.

## Create and freeze a corpus

Open `app/public/evaluation.php` using an authenticated administrator session. Create a dataset version, add the source text, category, profile, provenance and split, then add approved independent reference summaries, essential information units and challenge assertions as appropriate. Documents and reference authors remain private research records.

Queueing the first run seals that dataset version. Source text, document activity, references, units and challenge assertions cannot subsequently change through the application. The run contains a full immutable selected-document snapshot and SHA-256 fingerprint, in addition to its configuration fingerprint. Large snapshots and reports use a lossless gzip/base64 JSON envelope with an integrity hash; `EvaluationService::decode()` transparently reads both this encoding and ordinary JSON. Public exports contain normal decoded JSON. Writes exceeding the configured MySQL packet limit fail before sending an oversized packet; increase `max_allowed_packet` for unusually large incompressible corpora. Dataset deactivation remains available to prevent further use. To revise sources or annotations, create and populate a **new version**; do not overwrite an old run.

The default planning target is 48 documents, but the selected source count is measured from actual records. Resource bounds are 500 documents per dataset, 200,000 UTF-8 bytes per source, a 20 MB selected snapshot, 10,000 output conditions per run, and three queued/running runs. These bounds are operational limits, not the research sample-size protocol. Modes, systems, ablations, split and seed are selected per run. A run uses one split only.

The CLI importer supports a JSON object with `dataset_version`, `dataset_split` and `documents`. Each document accepts `title`, `source_text`, `category`, `profile`, `provenance` (or `source_provenance`), `source_reference` (or `source_url`), `notes`, and optional `references`, `content_units`, `critical_facts` and `prohibited_distortions`. References use `text`, `version`, `review_status` and a private `author_identifier`.

Replace `ADMIN_ID` below with an existing active administrator's numeric ID. The CLI actor is checked against the database; it is not an evaluator impersonation parameter in a web request.

```powershell
php scripts/evaluation_import.php --file path/to/corpus.json --actor ADMIN_ID --version EVAL-2026-v1
```

`--queue` also queues all four modes and all four systems. It generates no scores until a worker executes. Use the web interface for another selection or an ablation matrix.

The validation fixture deliberately contains both real excerpts and synthetic stress cases. Import them separately to avoid pooling unlike evidence:

```powershell
php scripts/evaluation_import.php --file python-engine/tests/fixtures/evaluation_validation_documents.json --actor ADMIN_ID --version EVAL-REAL-VALIDATION-v1 --real-only --queue
php scripts/evaluation_import.php --file python-engine/tests/fixtures/evaluation_validation_documents.json --actor ADMIN_ID --version EVAL-SYNTHETIC-STRESS-v1 --synthetic-only --queue
```

`--real-only` includes records explicitly marked `synthetic: false`; `--synthetic-only` includes records explicitly marked `synthetic: true`. Change the version when repeating an import; version identifiers are unique. The included challenge fixture uses `cases` instead of `documents`; the importer also accepts this explicitly synthetic schema:

```powershell
php scripts/evaluation_import.php --file python-engine/tests/fixtures/evaluation_challenges.json --actor ADMIN_ID --version SYNTHETIC-FACT-CHALLENGES-v1
```

The source fixtures supply no human ratings, reference summaries or impressive expected scores. Imported challenge assertions are engineering test expectations, not independent human assessments.

## Execute the offline queue

```powershell
php scripts/evaluation_worker.php --once
php scripts/evaluation_worker.php --once --run 123 --timeout 300
php scripts/evaluation_worker.php
```

`--once` handles at most one queued run or pending report. `--run` selects a particular run. Without those options the command drains the current queue and exits; it does not install a persistent service or launch from a web request. Invoke it manually or through the deployment's ordinary scheduler. The configurable per-item/report timeout is 1–3,600 seconds, default 300. Exit code 2 means another evaluation worker owns the database lock.

Only **one evaluation worker per database** can run at once. This conservative fixed concurrency limit prevents competing CPU/model loads; increasing it requires an explicit scheduling redesign. Python numerical libraries are limited to one thread. A persistent JSON-lines subprocess handles sequential items and caches optional model objects across requests. On Windows, private temporary regular-file stdout/stderr streams avoid PHP's unreliable nonblocking process pipes. The bridge bounds response sizes, rotates the process if a temporary output file exceeds 100 MB between items, and removes its own temporary files when it closes. Crashed worker temporary files may require the deployment's normal temporary-directory cleanup.

Each output is an atomic database transaction. It stores the generated text, actual Unicode-tokenizer word count, target budget, timings, algorithm settings and provenance, followed by separate metric rows. A failed generation or metric-persistence transaction cannot leave partially committed metrics. A failure is recorded for that item; the worker continues with later items when storage remains available. Missing metrics retain an explicit status and null value, never a fabricated zero.

Baselines receive the recorded full-system word budget for the same source/mode when available. Their actual achieved word counts remain in the output record; sentence boundaries can prevent an exact match. All ten supported ablations are evaluation-only variants, with the full system retained as a comparison condition. Production settings are unchanged.

The worker verifies the queued Python source fingerprint before starting and before each output. Source changes stop further generation with an explicit failure record. Preserve that run and queue a new run after code is stable. Do not silently resume a changed algorithm under an old configuration identifier. Package and local model fingerprints are recorded in Python provenance; preserve the environment as part of experiment archiving.

## Cancel and resume

Cancellation is a server-validated administrator POST. The worker checks cancellation between outputs; a currently executing item can finish and be recorded. Successful outputs remain available. Cancellation does not erase observations.

Resume restores only interrupted **queued** outputs. Completed and failed outputs are never overwritten. A `running` run needs a heartbeat older than one hour before the administrator can mark it resumable, to avoid mistaking an active long item for a dead process. The worker's database lock continues to prevent competing workers. If all outputs are already recorded, create a new run to retry failures. If code changed, use a new run rather than resuming an incompatible snapshot.

A storage outage can prevent the failure itself from being written. Resolve the database problem and inspect the last heartbeat and recorded output states before resuming. Reports expose attempted, valid and unavailable counts, so an interrupted experiment cannot masquerade as a complete one.

## Optional BERTScore

Lightweight metrics require the existing local Python dependencies. BERTScore is optional and isolated from ordinary summarization. To prepare an evaluation environment explicitly:

```powershell
.\.venv311\Scripts\python.exe -m pip install -r python-engine/requirements-evaluation.txt
```

Prepare the chosen model cache separately before executing a run. The web queue's standard BERTScore model is `roberta-large`; the direct Python protocol supports a separately specified model and layer setting. The PHP bridge uses `storage/models/huggingface` as `HF_HOME`. The scorer enforces offline model loading, CPU execution, batch size one and one thread. An absent package/cache produces an `unavailable` metric with a reason and leaves other metrics intact. Input beyond the model context is reported as unavailable rather than silently truncated. Successful BERTScore inference was not demonstrated merely by installing this subsystem; the final experiment must retain evidence from an actual cached-model run.

## Human review and reports

Assign completed outputs to selected active accounts in the administrator interface. Use independent reviewers and a manageable subset; the software does not manufacture evaluator participation. The assignment seed/mapping is stored server-side. The evaluator sees an opaque token, randomized label, source and generated summary, plus the visible rubric. Algorithm identity, mode/profile, run/output IDs, reference authors and automatic scores are excluded from that response.

An assessment requires all seven 1–5 integer criteria. A unique assignment/rating constraint and row lock prevent duplicate submissions. Submission locks the assessment. An administrator correction requires a reason and records old and replacement values in `evaluation_audit_log`. Disabling an account immediately removes its assignment access, even if its session remains present.

Reviewers may classify multiple errors and verify individual content units for a specific output. Verified content coverage is calculated only when every expected unit has an output-specific decision. Human ratings, corrections, assignments and unit verification mark the report for an offline refresh:

```powershell
php scripts/evaluation_worker.php --report 123 --timeout 300
```

The ordinary worker also refreshes pending reports. Old complete report versions remain in `evaluation_audit_log.previous_json` under `offline_report_revision`; replacement metadata includes the new report's SHA-256 hash and version, while the latest full report remains on `evaluation_runs`. This avoids duplicating two large reports in one SQL packet. If observations change while statistics are computing, saving that report keeps the pending marker so the next refresh includes those observations. Report failures keep output records and expose the report error; explicitly rerun `--report` after resolving it.

JSON exports contain generated text, independent raw metrics and components, versioned configuration/provenance, pseudonymous ratings, reviewer error classifications and the statistical report. CSV exports provide output/metric/rating/error records with spreadsheet-formula protection. The HTML report uses the same application layout and print stylesheet. Exports exclude evaluator account IDs, assignment tokens/seeds, private reference author codes, private source paths and rating free-text comments. Review researcher-entered error notes and source content before public dissemination.

## Verification

New offline report revisions also store their computation timestamp, input-observation SHA-256 and analysis-source fingerprint. Source changes during a worker's lifetime prevent it from storing a report computed under an ambiguous code revision; start a fresh worker after editing evaluation code.

```powershell
php scripts/test_evaluation.php
.\.venv\Scripts\python.exe -B -m pytest python-engine/tests -q -p no:cacheprovider
```

The PHP suite currently contains 52 checks. It executes real proposed-system and Lead-N summaries, uses isolated users/datasets inside a rolled-back transaction, checks database failure atomicity, source sealing, blinded assignment ownership, inactive-account revocation, CSRF/admin guards, rating locks/correction history, report races and missingness, lossless large-report storage, and verifies existing production table counts remain unchanged. A two-document run with one failed generation specifically verifies a single compression group reports N attempted 2 and N valid 1. Test ratings are synthetic fixtures that are rolled back; they are never exported as real human research evidence. The Python test names should be checked against the installed revision when selecting a subset; running the full `python-engine/tests` suite verifies existing summarization alongside evaluation.

The real-document validation observations and actual executed test counts belong in [evaluation-validation-observations.md](evaluation-validation-observations.md). Scientific assumptions and limits belong in [evaluation-methodology.md](evaluation-methodology.md).

## Rollback planning

Do not run destructive rollback against retained research evidence. First stop the worker, export/archive every needed run and its database backup, remove evaluation navigation/access from deployment, and verify no code is reading these tables. The dependency-safe table removal order is:

1. `evaluation_audit_log`
2. `evaluation_content_unit_reviews`
3. `evaluation_errors`
4. `evaluation_ratings`
5. `evaluation_assignments`
6. `evaluation_metrics`
7. `evaluation_outputs`
8. `evaluation_runs`
9. `evaluation_challenge_cases`
10. `evaluation_content_units`
11. `evaluation_references`
12. `evaluation_documents`
13. `evaluation_datasets`

Only after an intentional complete removal should the migration ledger's `012_evaluation_framework.sql` entry be removed so a future installation can apply it again. No automatic rollback command is supplied because dropping populated research tables would destroy reproducibility evidence. None of these steps requires dropping or rewriting `users`, production summaries, history or analytics tables.
