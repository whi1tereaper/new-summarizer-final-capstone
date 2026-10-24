# Python Engine Audit

Audit date:17October2026 (Asia/Manila). SOURCE-SUPPORTED unless a scoped test is named. The default configured engine is `legacy`; the independent `light_engine` implementation is an optional v2 path, not the enabled main engine. The application UI is branded LIGHT; the user-confirmed title is LIGHT summarizer.

## Active summary path

`ArticleController::handleBackgroundJob` obtains a resolved source from `ArticleService`, calls `LocalPythonBridge`, and saves the result through `ArticleService::storeSummary`. `local_cli.handle_summarize` constructs a `SummarizationRequest`; `summarizer_core.pipeline.SummarizationPipeline.summarize` extracts content, removes detected noise, identifies document structure, builds sentence candidates, computes TF-IDF/title/profile scores, adjusts retrieval scores, selects complementary candidates, applies a length budget, simplifies selected text, performs heuristic fact repair, and renders the chosen output format. Eight analysis modes, five output formats and six depths are accepted at the main PHP boundary. The evaluation/document API has a separate five-length contract without `short`.

Plain-language interpretation: the tool chooses and organizes passages from the submitted material, with rule-based cleanup and safeguards. Its default path does not require a remote generative model. Source matching and numeric/qualification checks do not establish that every statement is true or that every important finding is retained. A source can itself be wrong.

The CLI also exposes PDF extraction, concise nutshell generation, translation, text-to-speech, source-grounded question answering, a compatibility generation action, and a shallow self-test. A self-test only reports the Python runtime/configuration; it does not establish model readiness or successful document processing.

## Sources and extraction

Main PHP intake permits PDF/DOCX, pasted text, or an HTTP(S)article URL. The PHP text limit is200000bytes, file service limit30MiB subject to stricter PHP settings, title255characters and focus300characters. URL content passes scheme/address checks, per-redirect validation, time and body limits in SourceResolver. The separate Python URL extractor exists for direct engine/evaluation use; its checks do not replace the PHP boundary.

PDF text extraction uses pypdf and cleaning/reconstruction helpers. OCR is a bounded fallback for unreadable/image-only PDFs: up to 12 selected pages at 150 DPI, a 15-second OCR-call timeout and a 55-second elapsed-budget check between pages (rasterization has no local timeout, so this is not a hard total deadline), dependent on installed OCR/rendering tools. Those limits mean an entire long scanned PDF is not guaranteed to be processed. DOCX extraction and table handling are source-supported; fixture tests exercise document parsing. The initially attempted blank-first-page probe failed readability validation before the suspected page-number alignment condition could be verified. Nonempty-page-only accumulation remains a source-supported page-reference concern, not a demonstrated numbered-citation failure.

## Supporting services and external boundaries

Question answering ranks source sentences with TF-IDF plus token overlap and returns up to three relevant passages. Its confidence labels are heuristic thresholds, not calibrated probabilities. The isolated question fixture returned the passage containing120books. The endpoint is API-only in the current result UI; it is not a demonstrated conversational assistant.

Nutshell generation produces a concise representation using its own pipeline; the web route requires an authenticated account. The direct synthetic Python call returned status insufficient_content and an empty nutshell. Its harness PASS label records a returned response, not successful generation; the database/web save path was not tested. Translation uses deep-translator/Google/MyMemory and can send selected text outside the local computer; shared cooldown state and bounded locks support rate-limit recovery. Tests use mocked providers, so no live translation quality or availability claim follows. Piper TTS is local synthesis once a voice is provisioned; its source can download missing voice files. Filipino language selection defaults to an English voice unless a suitable voice is configured. No real speech generation/listening validation occurred in this audit.

Optional evidence-bounded synthesis requires both a request flag and server configuration. Current safe configuration inspection indicates it is disabled. Its lexical/citation guards are not a proof of semantic entailment. Optional local sentence embeddings fall back to TF-IDF when unavailable; LanguageTool/spaCy diagnostics depend on provisioned resources. No inference models were downloaded for this audit.

## Worker and evaluation

`worker_daemon.py` binds loopback, authenticates a secret, admits one job, applies a bounded execution deadline, restarts crashed children, and releases idle/recycled workers. Its lifecycle fixture tests pass in isolation. Installed scheduled-task status, real daemon uptime and concurrent production load were not verified.

The independent evaluation package computes overlap metrics, compression/redundancy measures, optional model metrics, fact proxies and statistics. It contains document-level grouping, paired comparisons, Holm correction and agreement helpers. These are implemented analysis facilities; they are not collected human results. Existing repository reports and JSON artifacts are historical engineering evidence with their own datasets and limitations, not new results of this audit or respondent surveys.

## Verified findings and limits

The confirmation run on an isolated source copy produced595passed,1failed and4skipped tests, with62passed subtests. The failure is the optional v2 test `test_inline_section_heading_does_not_hide_document_definition`: the expected outlier definition is absent from the selected output. Four HTTP translation acceptance tests were skipped because no live result URL was provided. The first sandbox attempt encountered temporary-file permission failures; those were eliminated by the documented confirmation environment, and are not counted as application failures.

An additional synthetic probe established a legacy cleanup defect: a meaningful one-paragraph library announcement containing the ordinary word `register` is discarded, producing CONTENT_UNREADABLE. Replacing that word with `check in` retains a paragraph, and splitting the source into paragraphs retains content. `NAVIGATION_PATTERN` includes `register`; whole-block filtering can therefore remove valid prose. This should be corrected under a separate implementation task, not silently changed during this audit.

A direct legacy summary call on the checked-in NIST fixture returned nonempty output; direct Q&A returned a source passage. The Nutshell call returned an empty insufficient-content response, so successful Nutshell generation was not established. These are engine-function tests, not saved website summaries or independent linguistic evaluation. The selected test runtime was Python3.14 with available local packages; no environment equivalence to every configured deployment is claimed.

`python-source-map.json` catalogs108Python files including archived baseline material.107parse as UTF-8 source; `summarizer_orig.py` is UTF-16 archival material whose decoded text parses but is not standard runnable Python source. Current execution points import `summarizer.py`/`summarizer_core`, not that archive. Setup documentation claiming Python3.8 is inconsistent with syntax/API usage requiring at least3.10; dependencies may impose a higher version. A tested installation matrix should replace the unsupported minimum.

Evidence: `python-tests-confirmation.xml`, `python-test-case-register.csv`, `isolated-smoke-results.json`, `isolated-smoke-followup.json`, `python-source-map.json`, `runtime-config-safe.json`. No source edits or live data mutations were performed.
