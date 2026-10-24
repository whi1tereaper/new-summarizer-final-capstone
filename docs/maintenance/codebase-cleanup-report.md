# Codebase Cleanup and Legacy Audit

Audit date: 2026-10-22 (Asia/Manila)

## A. Initial state

The repository is a PHP application under `app/` with public Apache entry
points in `app/public/`, PHP services/controllers in `app/src/`, database
material in `database/`, and a local Python NLP worker under
`python-engine/`. Deployment is represented by `Dockerfile`, `render.yaml`,
and the Northflank/Render deployment documents. The public document root was
not moved.

The repository also contains tracked research/evaluation material and existing
untracked work from earlier tasks. The cleanup checkpoint is the Git branch
`cleanup-baseline-20261022`, pointing to commit
`b02edfc58a8dfa439ae2314aa4c34ca0ed7645f4`. Existing uncommitted and
untracked files were preserved.

Baseline state:

- Branch: `main`
- Tracked source counts: 207 Python-engine files, 155 app files, 33 scripts,
  19 database files, 17 docs files, and 84 Composer vendor files.
- Full Python unittest discovery: 612 tests, 607 passed, 4 skipped, 1 failed.
- The failure is an unrelated Windows translation cooldown race involving
  `PermissionError: [WinError 5]` during concurrent `os.replace`.
- PHP lint after cleanup: 108 files checked, 0 failures.
- Python bytecode compilation after cleanup: 85 active Python files checked,
  0 failures. Archival `summarizer_orig.py` and the frozen evaluation snapshot
  were intentionally excluded from the active compilation count.

## B. Removed files

Exactly two tracked generated files were removed:

| Original path | Reason | Evidence | Risk |
|---|---|---|---|
| `.tmp/local_cli_probe.json` | One-off local CLI probe payload | No runtime or documentation consumer; `.tmp/` is ignored | Low |
| `.tmp/summarizer_engine.log` | Generated historical debug/runtime log | No reader in active application code; `.tmp/` is a runtime log destination and `.tmp/` is ignored | Low |

No user data, uploads, database material, research evidence, evaluation
datasets, model files, or deployment files were removed.

## C. Moved or consolidated files

No files were moved and no production modules were consolidated. The current
layout already matches the deployment paths used by Apache, Docker, Render,
and the PHP-to-Python bridge. Moving `app/public/` or `python-engine/` would
create unnecessary route and deployment risk without a verified migration
need.

Cleanup counts:

- Files removed: **2**
- Files moved: **0**
- Duplicate production implementations consolidated: **0**

## D. Preserved legacy and research files

| File/group | Classification | Reason retained |
|---|---|---|
| `python-engine/summarizer_core/` | ACTIVE | Default production summarization path through `local_cli.py`. |
| `python-engine/light_engine/` | LEGACY/EXPERIMENTAL | Opt-in v2 path with a documented rollback role; not approved for cutover. |
| `python-engine/summarizer.py` | SHARED/COMPATIBILITY | Imported by legacy compatibility and evaluation paths. |
| `python-engine/pure_nlp.py` | SHARED/COMPATIBILITY | Used by tests and legacy contract helpers. |
| `python-engine/nlp_pipeline.py` | SHARED/LEGACY | Imported by cleaning compatibility code and evaluation tests. |
| `python-engine/summarizer_orig.py` | ARCHIVAL | Historical UTF-16 baseline; preserved for research traceability. |
| `python-engine/evaluation/` | RESEARCH/DOCUMENTATION | Reproducible benchmark and comparison tooling. |
| `python-engine/evaluation/baseline_snapshot/` | RESEARCH/GENERATED BASELINE | Frozen baseline used by existing engine comparisons. |
| `python-engine/tests/` | ACTIVE | Regression, contract, architecture, performance, and evaluation tests. |
| `docs/system-audit/` | DOCUMENTATION/EVIDENCE | Source maps, test evidence, and prior audit results. |
| `vendor/`, `composer.lock`, `package-lock.json` | ACTIVE/DEPENDENCY | Deployment/runtime dependency material; not removed based only on direct imports. |

The apparent multiple summarization implementations are not proven duplicate
production engines: the legacy path is active, v2 is explicitly opt-in, and
the original file is referenced as archival research evidence. Retiring any of
them would break rollback or reproducibility requirements.

## E. Uncertain and user-owned files

The following were not deleted or reorganized because their ownership or
runtime purpose could not be safely established from this cleanup pass:

- `.agents/`, `BA_client/`, `peso_resort_system/`, and
  `request_document_poc_system_gab/`: untracked directories that appear to be
  adjacent projects or user work.
- `docs/capstone-manuscript/`: untracked research/manuscript artifacts.
- `docs/system-audit/` and generated evaluation JSON files: untracked but
  provenance-bearing research evidence.
- `.venv*`, `node_modules/`, `scratch/`, `artifacts/`, and runtime logs:
  ignored local environments/artifacts. They were not deleted because the
  cleanup policy forbids removing ignored user data without explicit scope.
- `CLAUDE.md`, deployment documents, `securityprotocol.md`, and setup guides:
  potentially operational or project-specific documentation; no deletion was
  justified by reference search alone.

Recommended future action: review adjacent untracked project directories in a
separate, explicitly scoped cleanup request and move research artifacts to a
dedicated archive only after confirming manuscript/reproducibility needs.

## F. Current folder structure and entry points

```text
app/
  config/                 Runtime configuration and error catalog
  public/                 Apache/PHP public entry points and assets
  src/                    Controllers, services, repositories, support
database/                 Schema, migrations, and database tooling
python-engine/
  summarizer_core/        Default legacy production NLP pipeline
  light_engine/           Opt-in extractive v2 pipeline
  evaluation/             Research comparison and benchmark tools
  tests/                  Python regression and contract tests
  local_cli.py            Main JSON worker entry point
  light_engine_cli.py     Opt-in v2 worker entry point
  worker_daemon.py        Warm worker supervisor
scripts/                  Maintenance, deployment, and integration scripts
docs/                     Architecture, audit, research, and deployment docs
storage/                  Runtime uploads, audio, models, logs, and temp data
docker/                   Container support files
vendor/                   Composer dependencies
```

Primary request path:

`app/public/summarize.php` → `ArticleController`/`ArticleService` →
`LocalPythonBridge` → `python-engine/local_cli.py` →
`summarizer_core.pipeline`.

The v2 path is selected by `SUMMARIZER_ENGINE_VERSION=v2` and invokes
`light_engine_cli.py`; current defaults retain `legacy`.

## G. Dependencies and configuration

No dependencies were removed. The checked-in Composer and npm lockfiles,
Python requirement files, Docker configuration, and deployment YAML remain
unchanged. `.gitignore` already excludes environment secrets, virtual
environments, caches, runtime uploads/logs, temporary files, and generated
artifacts. The two tracked `.tmp` outputs removed by this audit are now
covered by the existing ignore rule.

The existing `.env` was not modified or copied into documentation. No
credentials were added to the report.

## H. Testing and compatibility results

Executed successfully:

- PHP lint: 108/108 files passed.
- Python `py_compile`: 85 active files passed.
- Targeted navigation-cleaning regressions: 2/2 passed.
- Full Python unittest discovery: 612 executed; 607 passed, 4 skipped, 1
  failed.
- Git diff whitespace check: passed.

The one full-suite failure is outside the cleanup and NLP paths: Windows
concurrent translation cooldown state replacement received `WinError 5`.
It remains documented for a separate fix.

Not executed in this cleanup pass:

- Live Apache/XAMPP browser workflows.
- MariaDB-backed authentication/history/admin workflows.
- External URL, PDF, and DOCX end-to-end submissions.
- Production deployment smoke tests.

These require running services and were not treated as passed based on source
inspection.

## I. Cleanup summary and remaining technical debt

This cleanup deliberately favors a smaller, reviewable diff over cosmetic
restructuring. The production paths remain stable, research evidence remains
reproducible, and the only removed files are confirmed generated debug
artifacts.

Remaining debt:

1. Resolve the Windows translation cooldown race.
2. Keep the legacy summarizer as the production default until the article-first
   quality gate and human evaluation requirements pass.
3. Review untracked adjacent project directories separately.
4. Eventually consolidate compatibility wrappers only after import graphs,
   evaluation baselines, and deployment rollback requirements are formally
   retired.
5. Add a maintained inventory for research artifacts if the capstone workflow
   requires long-term archival ownership.
