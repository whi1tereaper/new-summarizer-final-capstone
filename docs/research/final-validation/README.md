# Final-validation artifact index

See [the final release report](../article-engine-final-validation.md).
Decision: NOT READY; global routing stays disabled. No engine/frontend change.

## Evidence chain

- `freeze.json`: 192 production file hashes, all 59 preserved pilot file hashes,
  protocol hash, dirty-workspace commit context. `protocol.md` is immutable.
- `followup-input-lock.json`: source/probe/harness lock before new outputs;
  `focus-probes.json`: 24 new query definitions with agent-draft labels, ambiguous
  support intentionally null. Original locked sources/queries remain under
  `../independent-validation/`, untouched.
- `length-diagnosis.json`: original 9/48 reproduced, two-tokenizer relaxed
  cardinality/word feasibility and all unmatched cases.
- `cross-depth-sensitivity.json`: native-only and all-saved-trial one-to-one
  length-only matching; full original output bodies, budgets, depths, common
  subsets and unmatched records. This is retrospective, not a new holdout.
- `new-runs-{legacy,guide_lsa,article21_light,article21_enhanced}.json`: all 200
  new generations, full outputs, controls, metadata, timings and errors.
- `analysis-summary.json`: control comparisons, native repeats, Focus behavior,
  warning counts, per-category/per-depth length strata and resource summaries.
- `focus-output-audit.json`: 288 native/registered-selected views with draft
  acceptance/rejection flags, not independent correctness labels.
- `focus-sentence-audit.json`: every selected Focus sentence occurrence, source
  alignment/neighbors, draft/unresolved roles, dependency/isolation prompts and
  blank human fields. Complete coverage is not complete independent judgment.
- `context-factual-review.json`: 385 unique complete output reviews; exact or
  explicitly tentative source alignment, incomplete warning screens, known source
  inconsistencies, missing context/late draft bundles. Not an error-rate estimate.
- `process-profile-{light,enhanced}.json`: default-permission enforced 512 MiB
  runs; process-map attribution incomplete, seven unreadable maps at peak sample.
- `process-profile-readable-{light,enhanced}.json`: separately preserved follow-up
  with isolated-container SYS_PTRACE permission to read all process maps. No
  production security or engine change. Raw cgroup/process samples, PHP heap,
  image digests and full request outcomes retained. Process PSS is not an additive
  decomposition of cgroup memory. `apache-resource.conf` is evaluation-only.
- `verification.json` and `artifact-manifest.json`: final integrity checks and
  SHA-256 index of research files, report, scripts and tests.

## Independent review is pending

Distribute `source-only-packet.json`, `reviewer-instructions.md`, blank
`human-source-unit-ratings.csv` and `human-query-support-ratings.csv` **first**.
Enroll real qualified independent reviewers in `reviewer-registry.json`; lock
their independently derived/adjudicated source units before viewing outputs.
Then distribute `masked-output-packet.json`, `masked-focus-sentence-packet.json`,
`human-output-ratings.csv` and `human-focus-sentence-ratings.csv`. Keep
`blinding-key.json`, run files and all agent diagnostics away from raters until
ratings lock. `adjudication-blank.csv` preserves disagreements/decisions.
`agreement-results.json` currently has zero submissions and null agreement.

Do not rerun the review-package builder after enrolling reviewers or supplying
ratings; it refuses to overwrite enrolled/submitted work. Preserve raw forms and
versions. Any new ratings require a new artifact manifest, not retroactive
modification of this release's recorded pending status.

## Reproduction and safe replay

From the repository root, on the existing Windows Python 3.11 environment:

```powershell
py -3.11 python-engine/evaluation/final_validation.py
py -3.11 -m unittest discover -s python-engine/tests -p test_final_validation.py -v
py -3.11 python-engine/evaluation/final_agreement.py
C:\xampp\php\php.exe scripts/independent_validation_config.php
```

The first command checks protected code, original pilot files and protocol.
The current integrity tests assert that this release has no human submissions;
future actual-rating analysis belongs in a versioned successor, not falsified
backward compatibility with the blank-status tests.

`final_length_analysis.py` / `final_analysis.py` recompute derived diagnostics.
`final_control_experiment.py` skips completed worker files and must not be used to
silently replace the frozen runs. Fresh model replays require versioned output
paths and the recorded local optional resources, with differences disclosed.
Linux resource replay requires Docker, the exact recorded image IDs, available
local source/PDF inputs, a versioned output location, and serial model/resource
execution to avoid repeating the documented host paging incident.
`final_resource_profile.py` refuses existing observation files; `--readable-maps`
is the explicit map-permission follow-up. Only owned labeled disposable containers
may be cleaned up. Never run the old finalizer against the immutable pilot output
directory. Do not commit secrets, rewrite original evidence, enable global routing
or infer production compatibility from these commands.
