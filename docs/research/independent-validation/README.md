# Article 2.1 external validation artifact index

Primary report: [article-engine-independent-validation.md](../article-engine-independent-validation.md).
Human review remains pending; this directory is not independent gold data.

- `freeze.json`, `protocol.md`, `input-lock.json`: pre-output engine/procedure/input locks.
- `prior-data-audit.json`, `source-overlap-audit.json`: exclusion and historical overlap evidence.
- `corpus-provenance.md`, `corpus.json`, `draft-labels.json`: credited licensed prose and clearly marked agent draft units/queries.
- `comparison-results.json`, `runs-{legacy,guide_lsa,article21_light,article21_enhanced}.json`: authoritative complete comparison, every trial/output and unmatched cases.
- `draft-unit-attainability.json`: exact-bundle constraints and copy-matcher bias.
- `failure-traces.json`, `focus-analysis.json`: exhaustive source-addressed diagnostic flags, not independently adjudicated errors.
- `reviewer-instructions.md`, `human-source-packet.json`, `human-output-packet.json`, `human-review-blank.csv`: unfilled independent-review materials. Keep `blinding-key.json` separate until ratings are locked.
- `resource-summary.json`: authoritative aggregate resource ledger, including incomplete/failed harness branches.
- `resource-warm-*.json`, `resource-warm-results.json`: completed capped real-article persistent-supervisor matrices.
- `resource-{light,enhanced}-512.json`: completed 512 MiB CLI-route matrices, including explicitly identified legacy requests.
- `resource-large-guide-*.json`: separate known 162-page file tests; successful **legacy fallback**, not fresh quality articles or Article 2.1 success.
- `resource-*-1024.json`: incomplete earlier CLI attempts; do not substitute these for the completed warm 1 GiB tests.
- `resource-harness-*.json`, `resource-results.json`, `host-incidents.json`: earlier harness/interrupted orchestration records. In particular, the old generic `resource-results.json` is **not** the final successful matrix; use `resource-summary.json` and individually completed artifacts.
- `acquisition-index.json`, `acquisition-final-index.json`, `acquisition-attempt-*.json`, `acquisition-IV-*.json`: provisional acquisition attempts, including rejected extraction/HTTP results. Only locked `corpus.json` is model input.
- `resource-large.pdf`: generated transport fixture from the credited PLOS prose, with disclosed WinAnsi glyph substitution; not a separate quality source.
- `Dockerfile.enhanced`, `apache-benchmark.conf`: isolated benchmark setup; production Dockerfile/configuration unchanged.

## Reproduction

Use the existing `.venv-nlp` interpreter and the recorded package/model versions.
The scripts are under `python-engine/evaluation/independent_*.py`.

```powershell
.\.venv-nlp\Scripts\python.exe python-engine/evaluation/independent_validation.py verify
.\.venv-nlp\Scripts\python.exe -m unittest python-engine/tests/test_independent_validation.py -v
C:\xampp\php\php.exe scripts/independent_validation_config.php
```

The comparison refuses to overwrite existing runs. `independent_comparison.py
--resume` retains completed cases; it does **not** regenerate already completed
outputs. For a genuine fresh replay, create a new task-specific output directory,
copy only `freeze.json`, `protocol.md`, `input-lock.json`, `corpus.json` and
`draft-labels.json` into it, then set `LIGHT_IV_OUTPUT_DIR` to that absolute path.
Run `independent_comparison.py` **without** `--resume`, followed by
`independent_failure_analysis.py` and `independent_review_materials.py`. Preserve
the original artifacts and compare replay outputs; do not silently overwrite them.

For resource replay, also copy `apache-benchmark.conf` into the new output
directory. Build the evaluation-only Dockerfile from this directory after
confirming the recorded base image ID, then run `independent_resources.py --warm`.
The current base tag may drift; image IDs/package manifests in the original
artifacts identify the executed environments. `--memory 512 --mode light` narrows
a run. CLI transport is used when `--warm` is omitted. `--large-guide` accepts an
explicit local PDF path, mounts it read-only, and creates separate resource-only
artifacts; no PDF is copied into the corpus. Do not run heavy Windows model
comparison and Docker benchmarks concurrently on the recorded 8 GiB host.

Finish with `independent_resource_summary.py` and freeze verification. Resource
tools create/stop/remove only uniquely named owned containers, bind HTTP only to
loopback, use ephemeral tokens and never connect to the real application's
database or host NLP daemon. Production routing must remain disabled.
