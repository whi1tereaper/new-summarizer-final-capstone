LIGHT input validation and recovery implementation report — 2026-10-14 (Asia/Manila)

The existing application now uses one safe failure contract across PHP, Python, asynchronous jobs, Nutshell, and browser recovery. Existing unrelated changes in this checkout were preserved.

**Actual supported restrictions**

| Source or option | Observed authoritative restriction |
| --- | --- |
| Source method | Exactly one of PDF/DOCX upload, pasted text, or article URL |
| Pasted text | 200,000 bytes, measured as UTF-8 bytes; blank and punctuation-only input rejected before job creation |
| Document title | 255 characters, matching the existing database column |
| Uploads | 30 MiB application limit; effective limit is the smaller positive PHP upload/post limit. XAMPP PHP 8.1 reports 40M/40M, so the application limit is 30 MiB. PHP 8.3 CLI separately reports 2M/8M; browser limits use Apache configuration. |
| Accepted document types | PDF and OOXML DOCX. PDF signature and detected MIME must agree. DOCX requires ZIP signature, readable ZIP, and Word package entries. Generic ZIP/octet-stream MIME is admitted only after these structure checks because XAMPP's older magic database misidentifies genuine DOCX. |
| URLs | Public HTTP/HTTPS; credential-bearing/local/private/reserved destinations rejected; every redirect validated, maximum three redirects; TLS verification retained; resolved public IPs pinned by PHP cURL |
| URL responses | HTML/XHTML/plain text article extraction; direct PDF URLs require actual PDF signature; HTML download cap 5 MiB, PDF cap 30 MiB; connection timeout 10 seconds, request timeout 20 seconds |
| Legacy content gate | Existing paragraph gate requires 12 tokenizer tokens. This is not imposed globally on other engines. Very short valid source behavior is preserved where supported; a 15-word source succeeds in the legacy pipeline test. |
| Legacy PDF readability | Existing checks require 120 alphabetic characters, 30 tokens, and average 25 words/page before using the existing OCR fallback |
| OCR | Existing pytesseract/pdf2image path retained, up to 12 sampled pages, 55-second total budget and 15-second OCR page limit. Lack of extractable text does not prove a scan. |
| Independent engine | Existing 2,000,000-character, 30 MiB, 1,000-PDF-page limits remain; web pasted-input limit is still 200,000 bytes. No OCR pipeline was added to this engine. |
| Options | Eight analysis modes, five output formats, six summary depths retained; existing focus query and synthesis controls retained |
| Worker | Existing persistent supervisor retained; deployed deadline 180 seconds, PHP bridge hard ceiling 300 seconds |

**Architecture and response behavior**

[The registry](../app/config/error_catalog.json) contains the complete title, explanation, recovery, category, HTTP status, and retry permission for every code. [ProcessingError.php](../app/src/Support/ProcessingError.php) and [failure_contract.py](../python-engine/failure_contract.py) read that same file. Unknown exceptions become an honest `PROCESSING_FAILED`; arbitrary exception strings are not treated as evidence of a precise cause.

Public failures include `failure: {code, title, explanation, recovery, retryable, reference, category, http_status}`. The main PHP API retains `success:false` and a safe `error` string. Python retains `ok:false` and safe legacy message fields. Nutshell retains its legacy status/error keys and adds the same `failure` object. Only registered codes and valid opaque references are accepted from worker responses; received prose is ignored.

The database migration [016_summary_error_contract.sql](../database/migrations/016_summary_error_contract.sql), applied locally, adds `error_details` and `retry_count`. Safe error envelopes survive refresh. Historical free-form messages remain hidden because their original cause cannot be established. Status APIs return only owner-visible job state, failure metadata, retry eligibility, and result redirect; payloads and source text are never included.

| Outcome | Job state | HTTP behavior |
| --- | --- | --- |
| Preflight validation failure | No job created | Registry-specific 400/413/415/422 response; native form POST uses 303 redirect with safe flash |
| Work accepted/in progress | queued / processing | 200, job ID and status |
| Source/extraction/NLP/quality failure | failed | Registry status, usually 422 |
| Processing deadline | timed_out | 504 |
| Worker unavailable | failed | 503 |
| User cancellation | cancelled | Cancel mutation acknowledges success; subsequent status returns 409 |
| Completed summary | completed | 200 and existing result redirect |
| Foreign or missing job | No mutation | Indistinguishable 404 |
| Missing/invalid CSRF | No mutation | 403 |
| GET to mutation endpoint | No mutation | 405 with Allow: POST |
| Expired abandoned request | cancelled | 410 |

**Retry, preservation, and cleanup**

Uncertain submission failures retain the same request token, including identical-file reselection after refresh. Backend uniqueness and duplicate-key recovery prevent repeated entries. Files use a content hash rather than a generated temporary filename in the idempotency signature. Settings that affect the result, including title, focus query, and synthesis, participate in the signature.

A temporary terminal text/URL failure permits at most two explicit retries on the same job row within one hour. Permanent input/source restrictions require correction. Completed requests return the same result. File retries require reselection because temporary uploads are removed and browsers cannot restore a file input. Polling interruptions offer status checking of the existing job rather than submitting new work.

Worker claims, cancellation/failure transitions, and retry transitions are conditional. Summary insertion and job completion share a transaction and row lock; a late or cancelled worker cannot insert a result for an obsolete attempt. Authenticated daemon cancellation releases active worker work where available. CLI fallback cannot always be interrupted immediately, but its result is rejected after cancellation.

Title, text/URL, mode, output format, depth, focus query, and synthesis setting survive errors in a tab-scoped sessionStorage draft and bounded server recovery state. Drafts expire after one hour and successful workflows clear their recovery data. Server form state remains available for repeated no-JavaScript refreshes. Processing session metadata excludes the source text.

Successful, permanent-failure, cancelled, and exhausted-retry jobs clear their source payloads. Retryable text/URL payloads expire after one hour. Expired queued requests and stale processing requests are classified before cleanup; orphan uploaded paths are removed only within the configured upload directory and generated filename pattern. Cleanup runs during new submissions and is also available as `php scripts/cleanup_summary_recovery.php`. An unattended deployment should schedule this CLI command for physical cleanup while the site receives no requests; logical retry eligibility already expires after one hour.

**Verification evidence**

| Suite | Result | Evidence |
| --- | --- | --- |
| PHP validation and database lifecycle | 52 checks passed | [test_error_lifecycle.php](../scripts/test_error_lifecycle.php) |
| PHP source, bridge, cancellation, Nutshell | 125 checks passed | [test_source_error_contract.php](../scripts/test_source_error_contract.php) |
| Real Apache HTTP extraction/lifecycle | 31 checks passed; 12 created test jobs audited and cleaned | [HTTP results](../artifacts/error-feedback/http-results.json) |
| Additional real Apache validation | 6 checks passed | [Additional HTTP results](../artifacts/error-feedback/extra-http-results.json) |
| Python source/failure contract | 21 tests passed | [Python test output](../artifacts/error-feedback/python-results.txt) |
| Python supervisor lifecycle | 6 tests passed | Same Python test output |
| Existing capabilities, independent engine, grounded Q&A | 36 checks passed | `scripts/test_summarizer_intelligence_update.php` |
| Actual Chrome form/recovery flows | 17 checks passed | [Browser form results](../artifacts/error-feedback-browser/results.json) |
| Actual Chrome processing/results flows | 6 checks passed, plus the completed result record | [Browser processing results](../artifacts/error-feedback-browser/processing-results.json) |

Real Apache requests reproduced empty, punctuation-only, oversized text, invalid/unsafe/unresolvable URLs, unsupported uploads, >30 MiB files, PDF signature spoofing, ZIP-as-DOCX, title overflow, corrupted PDF, password-protected PDF, image-only PDF, and empty DOCX. Valid PDF, DOCX, text, and the live IANA example-domains article completed. Legacy insufficient text returned `INPUT_TOO_SHORT`. Repeated submissions reused job IDs; completed repeats reused result IDs; failed jobs had no summaries; source payloads were cleared.

Foreign guest access returned 404 without source details; mutation CSRF checks returned 403; GET execute returned 405. Nutshell guest access remained restricted and returned 401. PHP/Python fixtures additionally exercise redirect safety, mapped IPv6/private addresses, TLS failure, extraction exceptions, worker transport, and internal worker authentication without exposing internal paths. Syntax checks passed for changed PHP and browser JavaScript.

The existing intelligence suite first encountered sandbox Windows ACL restrictions, then an occupied worker during concurrent tests. It passed all 36 checks after an approved ACL-capable run with the worker idle; no ACL protection or busy-worker rule was removed.

**Reproduction scope and remaining uncertainty**

- Restriction statuses (401/403/404/429/503), login-only markup, wrong PDF responses, and unreadable pages were reproduced using controlled HTTP fixtures or transport fixtures. These do not verify a particular live paywall or bot challenge.
- The live valid URL test used production fetching and SSRF policy without a test bypass.
- Worker crash, deadline, busy state, child reuse, and cancellation were reproduced by supervisor/transport tests. Browser worker-unavailable and polling-network cases deliberately inject responses or dropped requests and are labeled accordingly in their reports.
- NLP candidate exhaustion and factuality rejection use controlled injections at the actual validation stages; they verify propagation and that rejected output is not persisted.
- Image-only PDF failure and OCR dispatch were verified. Successful recognition of scanned text was not verified. No unsupported claim that every unreadable PDF is scanned is made.
- Password-protected PDF detection is verified. An encrypted legacy Office/OLE container is reported as unsupported unless a parser can reliably establish encryption; no password guessing or access bypass was added.
- JavaScript-rendered article bodies, opaque 200-status challenge pages, unusual extraction-library failures, and unknown operational exceptions can remain ambiguous. The messages state only what diagnostics establish.
- This is targeted regression coverage, not a complete audit of unrelated authentication, RBAC, history, translation, audio, and admin functionality.

**Real-browser screenshots**

| Scenario | Light | Dark |
| --- | --- | --- |
| Empty text near source field | [Screenshot](../artifacts/error-feedback-browser/empty-text-light.png) | [Screenshot](../artifacts/error-feedback-browser/empty-text-dark.png) |
| Actual corrupt-PDF processing failure | [Screenshot](../artifacts/error-feedback-browser/corrupted-pdf-processing-light.png) | [Screenshot](../artifacts/error-feedback-browser/corrupted-pdf-processing-dark.png) |
| Unavailable result | [Screenshot](../artifacts/error-feedback-browser/result-unavailable-light.png) | [Screenshot](../artifacts/error-feedback-browser/result-unavailable-dark.png) |

[File reselection](../artifacts/error-feedback-browser/file-reselection.png), [submission network recovery](../artifacts/error-feedback-browser/submission-network-recovery.png), [processing connection recovery](../artifacts/error-feedback-browser/processing-network-recovery.png), [worker unavailable injection](../artifacts/error-feedback-browser/worker-unavailable-injected.png), and [mobile unsafe-URL feedback](../artifacts/error-feedback-browser/unsafe-url-mobile.png) document recovery placement. Error states use visible title/explanation/recovery, accessible alerts/focus, wrapping text, existing purple actions, and separate red error colors. Light/Dark and mobile checks are included in browser results.

**Files changed for this request**

| Area | Files |
| --- | --- |
| Shared contract | `app/config/error_catalog.json`, `app/src/Support/ProcessingError.php`, `python-engine/failure_contract.py` |
| PHP flow and storage | `app/src/Controllers/ArticleController.php`, `app/src/Services/ArticleService.php`, `FileUploadService.php`, `SourceResolver.php`, `LocalPythonBridge.php`, `SummaryJobService.php`; `app/public/summarize.php`, `nutshell_generate.php`; migration 016 |
| Browser/UI | `app/public/partials/error-support.php`, `partials/summarizer-form.php`, `processing.php`, `result.php`; `assets/js/light-errors.js`, `index.js`, `processing.js`, `result.js`; `assets/css/light-errors.css`, `processing.css` |
| Python | `local_cli.py`, `light_engine_cli.py`, `worker_daemon.py`; `summarizer_core/cleaning.py`, `extraction.py`, `pipeline.py`; `light_engine/contract.py`, `extraction.py` |
| Verification and cleanup | `scripts/test_error_lifecycle.php`, `test_source_error_contract.php`, `test_error_http.py`, `test_error_http_validation.py`, `test_error_http_audit.php`, browser test scripts, `cleanup_summary_recovery.php`; `python-engine/tests/test_failure_feedback.py`, `test_worker_supervisor.py`; report and artifacts linked above |

**Central message and HTTP mapping**

The table is the registry snapshot, not a claim that every entry was separately reproduced. Verification scope is stated above. `retryable` describes the error; `can_retry` also enforces job age, attempts, and retained-source availability.

| Code | HTTP | Retryable | Title and explanation | Recovery |
| --- | --- | --- | --- | --- |
| `TITLE_TOO_LONG` | 422 | No | Shorten the document title. Document titles can contain up to 255 characters. | Use a shorter title or leave the title blank. |
| `REQUEST_EXPIRED` | 410 | No | This request has expired. The recovery period ended and the retained source was removed. | Return to the form and submit your source again. |
| `TERMS_REQUIRED` | 403 | No | Accept the terms to continue. This action requires acceptance of the Terms and Conditions. | Review and accept the terms, then return to your request. |
| `SUMMARY_INELIGIBLE` | 409 | No | This summary cannot be used yet. This action requires a completed summary with readable source text and a readable result. | Choose a completed summary or submit a new source. |
| `RESULT_UNAVAILABLE` | 404 | No | This result is not available. The result could not be found or is not accessible in your current session. | Return to your summary history or submit a source in this session. |
| `URL_TLS_FAILED` | 502 | No | A secure connection could not be established. LIGHT could not verify a secure connection to the source website. | Use another accessible source or paste text you can access. |
| `URL_TOO_LARGE` | 413 | No | This web source is too large. The response exceeds LIGHT’s supported download size. | Use a smaller source or paste the relevant article text. |
| `INPUT_EMPTY` | 422 | No | Add a source to continue. There is no text or article link to summarize. | Paste readable text, enter an article URL, or select a PDF or DOCX. |
| `INPUT_INVALID` | 400 | No | Check your input. LIGHT could not read the submitted source fields. | Choose one source method and enter your source again. |
| `INPUT_TOO_SHORT` | 422 | No | More source text is needed. The supplied text does not contain enough usable content for this request. | Include the complete passage or another relevant paragraph. |
| `INPUT_TOO_LARGE` | 413 | No | This source is too large. The source exceeds the supported input limit shown on the form. | Shorten the pasted text or use a smaller source. |
| `SOURCE_CONFLICT` | 422 | No | Choose one source. More than one source method was submitted. | Keep either a file, pasted text, or an article URL. |
| `SETTINGS_INVALID` | 422 | No | Check your summary settings. One of the selected options is unavailable or unsupported. | Choose an available analysis mode, output format, and summary depth. |
| `REQUEST_INVALID` | 400 | No | This request could not be read. Some required request information is missing or invalid. | Return to the form and submit your source again. |
| `FILE_REQUIRED` | 422 | No | Select a document. No file was received for the file source. | Select a PDF or DOCX file and submit again. |
| `FILE_UNSUPPORTED` | 415 | No | This file format is not supported. The file type or signature does not match a supported PDF or DOCX document. | Export the document as PDF or DOCX, or paste its text. |
| `FILE_TOO_LARGE` | 413 | No | This file is too large. The file exceeds the current upload or download size limit. | Choose a smaller document within the limit shown on the form. |
| `FILE_PAGE_LIMIT` | 413 | No | This PDF has too many pages. The PDF exceeds the processing engine’s supported page limit. | Upload a smaller selection of relevant pages. |
| `FILE_CORRUPTED` | 422 | No | This document could not be opened. The document structure is damaged, incomplete, or malformed. | Open and export a fresh copy, or paste the document text. |
| `FILE_ENCRYPTED` | 422 | No | This document is protected. The document is encrypted or requires a password to read. | If you have permission, export an unprotected copy or paste accessible text. |
| `FILE_TEXT_UNAVAILABLE` | 422 | No | No readable document text was found. LIGHT could not obtain readable text. The document may contain images or unsupported text encoding. | Try a text-based copy or paste its text. An image-only document may need OCR first. |
| `UPLOAD_INCOMPLETE` | 400 | Yes | The upload did not finish. Only part of the file reached LIGHT. | Check your connection, select the file again, and retry. |
| `UPLOAD_FAILED` | 503 | Yes | The file could not be received. LIGHT could not store the uploaded file for processing. | Wait a moment, then select the file again and retry. |
| `URL_INVALID` | 422 | No | Check the article URL. The link is not a valid HTTP or HTTPS address. | Copy the complete article address, including https://. |
| `URL_UNREACHABLE` | 502 | Yes | The website could not be reached. LIGHT could not connect to the article website. | Check the address and try again later, or paste text you can access. |
| `URL_TIMEOUT` | 504 | Yes | The website took too long to respond. The article could not be retrieved within the connection time limit. | Try again later, or paste the article text you can access. |
| `URL_ACCESS_RESTRICTED` | 422 | No | The website restricted access. The source website refused access or requires authorization. | Open the source yourself and paste text you are allowed to use, or choose an accessible source. |
| `URL_RATE_LIMITED` | 429 | Yes | The website is limiting requests. The source website asked LIGHT to slow down. | Wait before retrying, or paste the article text you can access. |
| `URL_CONTENT_UNAVAILABLE` | 422 | No | The article content is unavailable. The link did not return a readable article or the requested document. | Check the article link, use another accessible source, or paste its text. |
| `URL_UNSAFE` | 422 | No | This address cannot be fetched. LIGHT only retrieves sources from permitted public website addresses. | Use a public HTTP or HTTPS article URL, or paste the text. |
| `DOCUMENT_EXTRACTION_FAILED` | 422 | No | We could not read this document. The document was received, but extraction did not complete. The exact cause could not be determined. | Try another copy or paste the document text. |
| `CONTENT_UNREADABLE` | 422 | No | More readable content is needed. The source does not contain enough usable text for reliable processing. | Paste a complete readable passage, or choose a clearer document. |
| `SOURCE_UNAVAILABLE` | 422 | No | The source is no longer available. The source file could not be found for this request. | Return to the form and select the file again. |
| `NLP_NO_VALID_CANDIDATES` | 422 | No | No usable summary passages were found. LIGHT could not identify suitable passages in the available text. | Include complete sentences and relevant context, or choose a clearer source. |
| `SUMMARY_QUALITY_FAILED` | 422 | No | A reliable summary could not be produced. The available text did not produce a summary that passed LIGHT’s quality checks. | Try clearer source text or include more relevant context. |
| `WORKER_UNAVAILABLE` | 503 | Yes | Processing is temporarily unavailable. LIGHT could not start or reach its processing service. | Wait a moment and retry. Your source and settings can be reused where available. |
| `PROCESSING_TIMEOUT` | 504 | Yes | Processing took too long. LIGHT did not finish within the processing time limit. | Try a shorter source, or retry once processing is available. |
| `PROCESSING_FAILED` | 500 | No | This request could not be completed. LIGHT encountered a problem, but could not determine its exact cause. | Return to your settings. If the problem continues, share the reference below with support. |
| `PROCESSING_CANCELLED` | 409 | No | Summary cancelled. This request was cancelled before a result was completed. | Return to your settings to submit a source again. |
| `RATE_LIMITED` | 429 | Yes | Please wait before trying again. Too many attempts were made in a short time. | Wait a moment before retrying this request. |
| `AUTH_REQUIRED` | 401 | No | Sign in to continue. Your session no longer has access to this request. | Sign in again, then return to your summary. |
| `ACCESS_DENIED` | 403 | No | This request is not permitted. Your session could not authorize this action. | Reload the form to refresh your session, then try again. |
| `JOB_NOT_FOUND` | 404 | No | This request is not available. The request could not be found in your current session. | Return to your own summary history or submit a source in this session. |
| `NETWORK_INTERRUPTED` | 503 | Yes | The connection was interrupted. LIGHT could not confirm the latest request status. Processing may still be running. | Check your connection, then check this request again. |

