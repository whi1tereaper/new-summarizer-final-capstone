# Measured summarization speed

Measured 2026-10-23 (Asia/Manila), using actual HTTP requests through the installed XAMPP Apache/PHP, local MariaDB, background summary jobs and existing Python NLP engine. No algorithm, model setting or application UI was changed for this measurement.

## Results

Six of six final summarizations completed successfully and their persisted results were retrieved from the isolated database. Settings: General analysis, Paragraph output, Balanced depth. Times are submit-to-result HTTP elapsed seconds, not a stopwatch inferred from the dashboard.

| Input | Source words | End-to-end seconds | Backend job window (whole seconds) |
|---|---:|---:|---:|
| Pasted text, first request to a fresh worker | 148 | 294.000 | 291 |
| Same text, warm worker, repeat 1 | 148 | 19.765 | 17 |
| Same text, warm worker, repeat 2 | 148 | 21.172 | 20 |
| Text PDF, warm worker | 148 | 10.641 | 8 |
| DOCX, warm worker | 148 | 5.047 | 5 |
| Public NASA article URL, warm worker | 1,161 | 27.297 | 26 |

The two warm text repeats averaged **20.469 seconds**. The final fresh-worker text request took **4 minutes 54 seconds**. A separate preliminary fresh-worker request returned its result in **85.735 seconds**, demonstrating substantial startup/load variability; that attempt's diagnostic probe failed on a nullable timing field and is not counted among the six final passing cases.

These are a few sequential samples, not throughput, percentile, concurrent-user or long-document guarantees. Warm text repeats used the same source. File cases ran later with the already-warmed worker, so these numbers do **not** prove DOCX is intrinsically faster than pasted text. The URL case uses different, longer content and includes network retrieval. Text PDF results do not represent scanned-PDF/OCR performance.

## Observed bottlenecks and limitations

- Cold starts are the major measured outlier. The first job occupied almost the entire 300-second worker deadline; the second and third requests were much faster. No profiler was installed, so the exact split between model loading, inference, paging and other job stages was not measured.
- Machine observed: Intel Core i5-13420H, eight cores / twelve logical processors; 7.73 GiB usable physical RAM. Only approximately **334 MiB RAM was available** during the slow run. Memory pressure may be contributing. The isolated worker ran alongside the active application and other processes; this was not an idle-machine benchmark.
- The application's `summaries.processing_time` field was **NULL** for these background jobs. The controller passes a null timing value to persistence on this path. It is therefore not a trustworthy source for an NLP-only measurement. The benchmark instead measures HTTP elapsed time and reads `summary_jobs.started_at/completed_at` as a coarse backend-job window, which also includes extraction and persistence work. This diagnostic finding was documented, not fixed, because the request was to check speed.
- Login, test fixture creation, environment setup and browser rendering were excluded. Submission/upload, job execution, 0.2-second status polling and retrieval of the persisted result were included. Polling adds up to roughly one interval plus HTTP scheduling overhead. Test PDF/DOCX files were 1,652 and 37,092 bytes respectively. Source word counts use the persisted source artifact if the application's original-word counter is zero.

## Isolation, checks and non-passing integrity result

The benchmark used a synthetic registry-approved account, a separate XAMPP Apache listener on loopback 58139, private storage, a dedicated existing-environment Python worker, and restricted `qa_light_*` database credentials. A direct check proved those credentials could not read the application database. The benchmark summaries and account existed only in the isolated database; all its databases/accounts/processes were cleaned up. The normal application worker was not stopped or reconfigured.

**Timing cases: 6 passed / 0 failed. Overall harness: 6 groups passed / 1 failed.** The failed group was the original-core-row preservation comparison, not a summary-generation failure: live source records changed during the active session, so a stable production-data snapshot could not be certified. The receipt explicitly reports `original_core_rows_preserved=false` and `live_activity_observed=true`; do not describe that check as passing. The isolated HTTP credentials were denied production access, and the lifecycle code's production operations were read-only snapshots, with writes scoped to strictly named QA databases/accounts. No production restoration, deletion, password change or enrollment activation was performed by this benchmark. Attribution of concurrent live changes was not established.

An initial benchmark setup also stopped because a freshly restored backup did not match changing live activity records (`guest_sessions` and `summary_access_subjects`). Its isolated databases/account were removed. The final benchmark reused the previously verified rollback path instead of asserting a new live point-in-time backup match. The earlier feature deployment's restore-verified backup and its data-preservation receipt remain separate evidence; later live activity does not make those earlier checks a current snapshot guarantee.

Registration and roster imports remain held: the environment-backed administrator/privacy contact and secure matching key are configured, but enrollment processing authorization remains false. No live student registration was activated for benchmarking.

## Reproduce and evidence

Run `C:\xampp\htdocs\ai-summarizer-123 - quality upg\.venv-nlp\Scripts\python.exe scripts/qa/run.py --benchmark-only` from the project directory. It uses the installed runtime/dependencies, not Docker or another database. For a repeatable baseline, arrange a quiescent live application separately and record machine load; this tool does not terminate unrelated processes or suppress integrity failures.

- `docs/qa/performance/speed.json`: individual successful timing samples.
- `docs/qa/performance/results.json`: process exits, isolation and cleanup checks, including the failed live-preservation comparison.
- `docs/qa/performance/integrity.json`: live-change flag.
- `docs/qa/performance/speed-attempt-1.json`: preliminary result and diagnostic-probe error.
- `docs/student-enrollment-registry-report.md`: completed registry implementation, 372 passing checks, additive deployment and deliberate registration hold.

Conclusion: on this run, a warmed worker summarized the 148-word text in about **20–21 seconds**, while startup could take **roughly 1.4–4.9 minutes**. Startup variability and low available memory merit investigation before claiming consistently fast production response times.
