# Error feedback UI verification

Verified on 2026-10-14 against XAMPP Apache at `http://localhost:8080/ai-summarizer-123%20-%20quality%20upg/app/public/`, using real headless Chrome through Playwright.

## Implementation

- `partials/error-support.php` publishes the shared PHP/Python JSON catalog. `assets/js/light-errors.js` renders registered titles, explanations, recovery and opaque references with text nodes, never raw exception strings.
- `partials/summarizer-form.php` renders authoritative text, title and effective PHP/application upload limits. Empty/unreadable text, byte limits, unsupported extensions and URL structure receive immediate field feedback. No minimum word count was invented. The 255-character title error appears directly under the title.
- `assets/js/index.js` submits with a persisted 32-character request token. Connection uncertainty retains that token, including refresh and reselection of the identical file. Changing source/settings creates a new token. Invalid/permanently restricted sources show correction/alternative controls and no retry control.
- Drafts preserve title, pasted text, URL, analysis mode, output format, depth, focus and existing form settings in tab-local `sessionStorage`, scoped to the current application/session/viewer. Drafts expire after one hour of inactivity, clear on successful processing and logout, and are discarded when the viewer session changes. Files are never stored; recovery explicitly requires reselection. Server form state also survives repeated no-JavaScript refresh with a one-hour validity limit.
- `processing.php` / `processing.js` use POST plus CSRF for execute/cancel/retry. Polling connection failures stop after three attempts and offer a status-only check of the existing job. Server `can_retry` controls terminal retry. Completed status, including a cancellation/completion race, opens the existing result.
- `result.php` displays a safe, non-enumerating `RESULT_UNAVAILABLE` 404 state. A historical result does not erase another recovery draft.
- `assets/css/light-errors.css` uses existing typography/spacing and purple recovery controls with separate readable error colors. Scoped dark form color fixes keep source text and supporting guidance readable without changing layout.

## Results

`node scripts/test_error_ui.cjs`: **17 checks passed**. Machine-readable evidence: `artifacts/error-feedback-browser/results.json`.

`node scripts/test_processing_error_ui.cjs`: **6 checks passed**, plus a recorded actual completed result ID. Machine-readable evidence: `artifacts/error-feedback-browser/processing-results.json`.

| Coverage | How reproduced | Observed result |
| --- | --- | --- |
| Empty text | Actual browser form | `INPUT_EMPTY`, inline alert, `aria-invalid`, no retry |
| Excessive title | Actual browser form, 256 Unicode characters | `TITLE_TOO_LONG` under title; input not truncated |
| Oversized text | Actual browser form, UTF-8 multibyte content >200,000 bytes | `INPUT_TOO_LARGE`; source preserved |
| Punctuation-only text | Actual browser form | `CONTENT_UNREADABLE` |
| Invalid URL | Actual browser form | `URL_INVALID` |
| Unsupported upload | Browser preflight and native POST with JavaScript disabled | `FILE_UNSUPPORTED`; repeated refresh preserves settings and error |
| Unsafe URL | Actual AJAX request through Apache to PHP | `URL_UNSAFE`; no retry/sign-in button; alternate source actions |
| Corrupted PDF | Real upload, job and live Python worker | `FILE_CORRUPTED`, no summary/retry; refresh preserves same job/code |
| Unavailable result | Actual PHP request for absent result | HTTP404, safe `RESULT_UNAVAILABLE`, useful recovery |
| Interrupted submit | Deliberately aborted browser request | `NETWORK_INTERRUPTED`; repeated submit uses same token |
| Interrupted file submit + refresh | Deliberately aborted request and same file reselected | Same token preserved; file itself must be reselected |
| Interrupted status polling | Deliberately aborted browser requests | Three attempts, then status-only recovery; no new job |
| Worker unavailable retry controls | Explicitly injected structured status response | `WORKER_UNAVAILABLE`, POST/CSRF retry on same job; hidden when `can_retry=false` |
| Valid text and completed refresh | Actual live worker and database result | Real result; draft cleared; completed refresh reopens identical result |
| Cancel/completion race | Displayed processing status deliberately made stale; real cancel API sees completed job | Existing result opens without regeneration |
| Session change / TTL | Cookie reset / expired timestamp | Previous session source is not restored; expired draft is removed |
| Responsive/dark styling | Actual desktop and 390px browser viewports | No mobile horizontal overflow; readable light/dark error and source styles |
| Browser integrity | Page error collection | No JavaScript errors |

Network failures and worker-unavailable controls above are deliberately injected frontend scenarios. They do not prove physical network outages or actual worker unavailability; backend/extraction reproduction belongs to the backend test reports.

## Screenshots

All screenshots were taken from the actual Apache-rendered pages, with injected scenarios clearly named:

- `artifacts/error-feedback-browser/empty-text-light.png`
- `artifacts/error-feedback-browser/empty-text-dark.png`
- `artifacts/error-feedback-browser/file-reselection.png`
- `artifacts/error-feedback-browser/submission-network-recovery.png`
- `artifacts/error-feedback-browser/unsafe-url-server.png`
- `artifacts/error-feedback-browser/unsafe-url-mobile.png`
- `artifacts/error-feedback-browser/result-unavailable-light.png`
- `artifacts/error-feedback-browser/result-unavailable-dark.png`
- `artifacts/error-feedback-browser/corrupted-pdf-processing-light.png`
- `artifacts/error-feedback-browser/corrupted-pdf-processing-dark.png`
- `artifacts/error-feedback-browser/processing-network-recovery.png`
- `artifacts/error-feedback-browser/worker-unavailable-injected.png`

These verify UI recovery and preservation. They do not substitute for backend ownership, duplicate-row, extraction-fixture or rate-limit tests.
