# LIGHT NLP performance report — 2026-10-14

## Result

LIGHT now reuses one initialized NLP child behind a foreground supervisor. An earlier PHP bridge run measured 16.004 seconds cold and 0.403 seconds warm. A later forced cold run on the current legacy pipeline measured 113.245 seconds cold and 5.615 seconds warm, so 16 seconds is not a safe worst-case expectation. The configured worker deadline is now 180 seconds. A separate end-to-end Apache guest request created a durable job, ran it through the worker, persisted and rendered the result, then removed the temporary QA rows.

The engine does the same summarization work. Across five identical text, DOCX, and PDF inputs, the saved outputs and the project's automated quality diagnostics were byte-for-byte identical after excluding timing metadata. This is automated regression evidence, not a substitute for human linguistic evaluation.

## Test machine and method

- Windows 10 build 26200; Intel Family 6 Model 186; 8 physical/12 logical CPUs; 7,914 MiB RAM.
- Python 3.11.9, local `all-MiniLM-L6-v2`, local spaCy model, and local LanguageTool.
- Serial execution. OS file cache was not flushed.
- `time.perf_counter()` supplied monotonic wall times. CPU and RSS came from `psutil` at request boundaries.
- Cold means a fresh Python process. Warm-unseen means models were initialized by a different document before the measured input; it cannot benefit from retained document text or vectors.
- Stage rows below are wall-clock timings for the 328-word academic source. Inclusive rows overlap their instrumented children and must not be summed. The separate cProfile run is diagnostic only: its cumulative times are not treated as sequential durations.

## Representative latency

| Source | Size/candidates | Before warm-unseen | After warm-unseen | Improvement | Output |
|---|---:|---:|---:|---:|---|
| Short pasted article | 86 words / 4 | 0.391 s | 0.288 s | 26.3% | Identical |
| Academic pasted article | 328 words / 18 | 1.572 s | 1.286 s | 18.2% | Identical |
| Long public report text | 1,481 words / 57 | 3.979 s | 2.858 s | 28.2% | Identical |
| DOCX | 1,450 raw words / 56 | 3.902 s | 2.739 s | 29.8% | Identical |
| Research PDF | 12 pages, 14,439 raw words / 242 | 11.543 s | 9.521 s | 17.5% | Identical |

Fresh-process pipeline latency remained essentially unchanged because a fresh process must still load the same local models: short 10.009→10.995 seconds, medium 16.539→16.968 seconds, and long 18.751→18.311 seconds. The production improvement comes from safely reusing the initialized child. Lazy CLI imports fell from 2.40–2.44 seconds to 0.006–0.011 seconds, depending on the case.

## Stage timings

| Processing stage | Before | After | Improvement |
|---|---:|---:|---:|
| Python CLI imports | 2.433 s | 0.008 s | 99.7% |
| Model loading | Prewarmed | Prewarmed | Reused by supervised child |
| Source extraction (pasted text) | <0.001 s | <0.001 s | No material change |
| Sentence segmentation, inclusive | 0.211 s | 0.105 s | 50.4% |
| Linguistic feature generation, inclusive | 0.024 s | 0.018 s | 24.0% |
| TF-IDF vectorization | 0.0024 s | 0.0022 s | 8.3% |
| Embedding generation | 0.174 s | 0.175 s | Within run noise |
| Candidate generation, exclusive | 0.0048 s | 0.0053 s | Within run noise |
| Candidate selection, inclusive | 0.213 s | 0.100 s | 53.0% |
| Sentence ranking | 0.0051 s | 0.0036 s | 29.4% |
| Redundancy detection, exclusive | 0.072 s / 1,947 calls | 0.015 s / 492 calls | 79.8% time, 74.7% calls |
| Grammar diagnostics | 0.545 s | 0.689 s | Run-to-run variance; no speed claim |
| Coherence validation, inclusive | 0.052 s | 0.042 s | 19.7% |
| Paragraph rendering, inclusive | 0.207 s | 0.028 s | 86.6% |
| Final format rendering | <0.001 s | <0.001 s | No material change |
| Database persistence | Included in browser run | Included in browser run | Not isolated |
| Results-page delivery | Included in browser run | Included in 0.699 s run | Not isolated |
| Total warm academic pipeline | 1.572 s | 1.286 s | 18.2% |

The unprofiled timings are the acceptance measurements. A separate cold cProfile run took 45.029 seconds because profiling inflated import and file-scanning work. Its cumulative view identified 24.025 seconds under two embedding calls, including 23.083 seconds for the first Sentence Transformers import, 7.109 seconds for the first spaCy import, and 5.063 seconds for the first WordNet load. Those cumulative values overlap and were not added together.

## Confirmed causes and implemented changes

1. Every CLI request paid heavyweight imports and model startup. `local_cli.py` now imports summarization, translation, and TTS dependencies only for the action that uses them. The supervised daemon owns one warm child and admits one job at a time.
2. The old daemon used a four-thread executor with an unbounded queue; a timed-out thread continued running. The supervisor now has bounded admission, a hard monotonic deadline, cancellation by job ID, child process-tree termination, restart after failure, a 100-job recycle limit, and a five-minute idle release. Protocol 3 health checks report the configured execution deadline, and each request carries its own bounded deadline.
3. Two modules loaded independent copies of `en_core_web_sm`. Segmentation, simplification, completeness, and grammar fallback now share one process-owned model.
4. Global caches retained sentence text and vectors across users, and the old 5,000-entry embedding cache could raise a `KeyError` after reaching capacity. Text, parses, linguistic features, diagnostics, redundancy pairs, and vectors are now bounded and request-owned, then cleared in `finally`. Only immutable model objects survive requests.
5. Selection repeated identical balanced/coherence work and repeatedly recomputed lexical comparisons. It now shares bounded request-local pair features and reuses the identical strategy result.
6. Topic clustering materialized an N×N dense similarity matrix. It now computes one sparse seed row at a time while preserving the exact cluster order and thresholds.
7. Repeated exact spaCy parses in paragraph rendering and validation now reuse request-local annotations. No validation category was removed.
8. Durable job dispatch treated `PDOStatement::execute()` as a row count and could dispatch a processing job again. It now checks the statement's actual row count, resolves the PHP CLI under Apache/XAMPP, and restricts status/cancel operations to the job owner.

## Quality and reliability verification

- 518 Python tests passed in 229.824 seconds in an independent full-suite run. Four additional spawned-process supervisor lifecycle tests passed after that run.
- 240 contracts cover 8 analysis modes × 5 output formats × 6 depths.
- 48 frozen mode/depth selection comparisons match the pre-change strategy output and quality metadata exactly.
- All five benchmark cases match full saved output and every existing automated metric, excluding timing metadata.
- Dedicated tests cover numbers, negation, uncertainty qualifiers, factual repair, sentence fragments, grammar, technical terms, cache bounds, request isolation, model switching, and cleanup after exceptions.
- A real Apache/XAMPP guest job completed, returned a saved result, and rendered the result page with HTTP 200; the temporary QA summary and job rows were deleted afterward.
- Direct PHP bridge verification on the configured port measured 113.245 seconds cold and 5.615 seconds warm. The cold duration is from this machine and short QA input; large PDFs may take longer.

## Memory and CPU

For the traced warm academic request, the before process peaked at 884.5 MiB RSS and the after process at 878.3 MiB. Tree RSS was approximately 1.37 GiB in both measurements because local LanguageTool runs as a child process. Boundary CPU increased by about 5.36 seconds before and 4.56 seconds after for this traced request. These are process-tree observations on an 8 GiB development machine, not universal capacity estimates.

## Remaining limitations

- Cold model-backed work can be much slower than warm work: the current local QA input took 113.245 seconds cold and 5.615 seconds warm. The provided limited-privilege logon task keeps one supervisor available and monitors recovery once installed for the desktop account; the application itself does not detach an unsupervised daemon.
- The worker uses the configured deadline (180 seconds in this deployment), capped at 300 seconds. Inputs that exceed it fail and restart cleanly rather than leaving orphaned NLP work.
- LanguageTool was the largest warm stage in the traced academic case and showed material run-to-run variance. Its checks remain enabled because disabling them would weaken quality.
- Very large documents still perform full structural analysis and factual validation. The measured 12-page/14,439-word PDF finished in 9.521 seconds warm, but no universal PDF latency is claimed.
- Database persistence and result-page delivery were verified within the full browser measurement but were not separately instrumented in this run; they are marked accordingly rather than estimated.
- Existing automated diagnostics were preserved, including pre-existing grammar findings on some fixtures. No new human linguistic evaluation was performed.

## Reproducible artifacts

- `python-engine/evaluation/benchmark_performance.py`
- `python-engine/evaluation/compare_performance_quality.py`
- `scratch/performance-before-warm-unseen.json`
- `scratch/performance-after.json`
- `scratch/performance-before-docx.json` and `scratch/performance-after-docx.json`
- `scratch/performance-before-pdf.json` and `scratch/performance-after-pdf.json`
- `scratch/performance-quality-comparison.json`
- `scratch/performance-unittest.txt`

