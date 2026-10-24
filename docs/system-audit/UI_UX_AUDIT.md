# UI and UX audit

Audit date: 2026-10-17 (Asia/Manila). Application source remains unchanged.

## Evidence scope and safety

The frontend inventory covers **41 public PHP entry points, 13 PHP partials, 16 JavaScript assets (including the bundled Chart library), and 19 stylesheets**. The root index redirect is an additional entry outside app/public. Machine-readable paths, hashes, line counts, forms, fields and dependencies are in [ui-route-inventory.json](ui-route-inventory.json). The 51 frontend capability/action rows in [ui-feature-notes.json](ui-feature-notes.json) are a contribution to the total-system inventory; overlapping backend rows must be reconciled before reporting a system feature count.

Normal anonymous application GETs are not read-only. `app/src/whitereaper.php` calls `GuestSessionService::bootstrapCurrentVisitor()`; that method inserts or updates a guest session and calls a one-in-100 probabilistic expiration cleanup. Cleanup can delete expired guest summaries and feedback. No live bootstrapped pages were requested. Three inspected no-bootstrap requests passed: root index returned 302; about.php returned 301; health.php returned 200 with static service/status JSON. Redirects were not followed. Their results are in [live-safe-get-results.json](evidence/live-safe-get-results.json). Static health does not prove database or worker availability.

The audit used an isolated loopback renderer at 127.0.0.1:8765 to include original templates without editing them. It bypasses bootstrap and substitutes an in-memory synthetic session and a PDO subclass that rejects mutation SQL and transactions. It does not connect to the application's database. Storage paths are redirected to audit-owned synthetic paths. Browser requests are restricted to GET on the harness; external fonts, telemetry and job calls are blocked. The renderer adds an identifying header and the capture process adds a provenance banner. **The screenshots are real browser images of source templates with synthetic data, not live authenticated system evidence.**

The capture produced **46 screenshots for 23 states across two viewports** and **18 passing local interaction checks**. Nineteen direct guard/method tests ran in isolation with an anonymous synthetic session. All tested guard outcomes matched the inspected code. None establishes the correctness of live session bootstrapping, password/email authentication, account persistence, worker processing or database authorization. [browser-readonly-results.json](browser-readonly-results.json) records outcomes and URLs. [Screenshot register](../capstone-manuscript/screenshots/README.md) identifies each image.

## Observed interface strengths

The source selection, output format, analysis mode and depth controls have explicit labels and explanatory text. Text/URL selection and setting changes were exercised without submitting a request. The empty-URL error appeared locally, identified the problem and told the reader to supply a complete HTTP(S) address. The mobile menu updates aria-expanded and closes on Escape. Terms acceptance becomes enabled after checking agreement, and the logout modal closes on Escape at both viewports. The synthetic result successfully switched to dark mode, copied the exact synthetic text and exported the same text to a .txt file. Selecting a low feedback rating revealed optional reason tags without saving feedback.

All baseline captured visible inputs had a label or an ARIA naming mechanism. The extra audit-log-filter state exposes the exception described below. No uncaught browser JavaScript exception was recorded in the 38 baseline captures. No page-level horizontal overflow was detected at 1440px or 390px in those baseline states; this does not cover long user content, every responsive breakpoint, populated tables or assistive-technology behavior. CSS includes reduced-motion handling and focus styles, and result feedback uses a radiogroup and live status messages.

## Findings and recommendations

| ID | Priority | Finding and consequence | Evidence and verification | Recommended follow-up |
|---|---|---|---|---|
| UI-ISSUE-01 | Medium | Password visibility buttons are excluded from sequential keyboard focus by tabindex=-1. Mouse/touch activation works, but a Tab-only user cannot reach the same control. | login.php, register.php and reset_password.php; DOM checks confirm one/two/two excluded buttons. | Make native buttons keyboard reachable and verify logical focus order. |
| UI-ISSUE-02 | Medium | Audit-log filters lack explicit labels: action select and admin-id number input depend on default option/placeholder; dates use title only. | admin_audit_logs.php filter-form; supplemental synthetic existing-table state. | Provide visible labels or robust programmatic names for all four controls. |
| UI-ISSUE-03 | Medium | History defaults to 10 main summaries per page and up to 20 completed Nutshell records. It reads a page query but renders no Previous/Next controls. Older saved summaries are not discoverable through normal history navigation once the first 10-record page is full. | history.php:22–23; HistoryHandler.php:12/22 default limits; complete history template; HistoryService maximum page size 50 is not the default. | Add accessible pagination based on actual result counts. |
| UI-ISSUE-04 | Medium | evaluation.php immediately redirects administrators to admin_dashboard.php. Legacy evaluation tabs/report output links still target evaluation.php?tab=…, so those management views are not reachable from that route. | Complete evaluation.php (guard then 303); evaluation-ui.php/report/output partial links. | Decide whether to restore a supported administration route or retire/update orphan links and documentation. |
| UI-ISSUE-05 | Low | Most main pages request assets/images/poc-neust-logo.png, which does not exist in the source tree. | Local 404 recorded by browser and absent file; other primary hero images loaded. | Supply an approved LIGHT favicon or remove obsolete links. Do not infer institutional affiliation from the missing filename. |
| UI-ISSUE-06 | Low | Recovery/reset/processing/audit-log pages have no h1; summarizer has a visible and a hidden h1; both admin page shells lack a main landmark. | Browser DOM findings and templates. | Use a coherent top-level heading and main landmark. Duplicate h1 alone is a hierarchy consistency concern, not a blanket conformance determination. |
| UI-ISSUE-07 | Low | Registration copy advertises custom profiles, but no editable saved-profile interface is present. Dashboard preferences are inferred from previous summaries; Profile shows identity and deactivation. | register.php, dashboard.php, profile.php and AnalyticsController::getUserPreferredSettings. | Align copy with actual preference behavior and avoid claiming profile editing. |
| UI-ISSUE-08 | Low | Footer social links go to generic platform homepages instead of identified project accounts. | partials/nex-footer.php. Links not opened externally. | Supply verified project URLs or label/remove generic destinations. |
| UI-ISSUE-09 | Medium | Main summary controls offer eight modes, while standalone Nutshell normalizes unsupported legal/scientific modes to general. The UI does not describe this mode change. | index.js forwards selected mode; nutshell_generate.php supports six labels. | Reconcile supported modes or explain the effective Nutshell mode before submission. |
| UI-ISSUE-10 | Medium | The optional Q&A endpoint has no visible current result-page control. Result supports multi-document metadata, but the main form selects one file and exposes no multi-file workflow. | api/qa.php, result.php/result.js, summarizer-form.php. | Document these as API/conditional capabilities; do not advertise an operational chat or multi-file UI without a supported flow. |
| UI-ISSUE-11 | Medium | Terms decline UI is a type=button with a message and delayed landing redirect; the server has a separate decline_terms POST branch that the displayed button does not submit. | terms.php and accept_terms.php inline scripts versus POST branches. | Align visible decline action with the intended server-side acceptance/session behavior. |

Privacy and reliability observations: source/settings can be retained in sessionStorage for up to one hour for recovery; file bytes cannot be restored. Feedback is displayed as anonymous but is associated with user/guest identifiers internally. Landing telemetry emits beacons during ordinary browsing. These are implementation facts, not privacy-policy/legal compliance conclusions. See the backend/security audit for Q&A error disclosure and missing-CSRF review, bootstrap cleanup risks and operational controls.

## Coverage and limits

The protected dashboard/history/analytics/admin captures use empty synthetic data; the profile and result examples are explicitly synthetic. The populated analytics/chart paths, long history and log pagination, full review assignments, evaluation reports, rich evidence metadata, speech, translation, text generation, file extraction and account-changing actions remain SOURCE-SUPPORTED or UNAVAILABLE LIVE EVIDENCE. No survey respondent, score, summary-quality result or performance benchmark was inferred from these captures.

Browser errors caused by the harness blocking Google Fonts, telemetry, automatic job execution or related network calls are expected isolation effects, not reported as application defects. The missing local favicon is independent of that blocking. The audit is not a full WCAG assessment: contrast, zoom/reflow at all sizes, screen readers, focus across every conditional form, and actual assistive technology remain untested.

## Public entry-point coverage

| Entry point | Role/purpose | Live-request constraint |
|---|---|---|
| `about.php` | Public compatibility redirect | No direct bootstrap; inspect any forwarded target |
| `accept_terms.php` | Public reading; authenticated pending-terms confirmation | Normal GET may mutate guest state |
| `admin_audit_logs.php` | Administrator | Normal GET may mutate guest state |
| `admin_challenge_change.php` | Retired challenge redirect | No direct bootstrap; inspect any forwarded target |
| `admin_challenge_setup.php` | Retired challenge redirect | No direct bootstrap; inspect any forwarded target |
| `admin_dashboard.php` | Administrator | Normal GET may mutate guest state |
| `admin_db_health.php` | Administrator API | Normal GET may mutate guest state |
| `admin_feedback_feed.php` | Administrator API | Normal GET may mutate guest state |
| `admin_verify.php` | Retired challenge redirect | Normal GET may mutate guest state |
| `analytics.php` | Registered user; administrator sees system scope | Normal GET may mutate guest state |
| `api/documents.php` | Document API compatibility router; per-dispatch guard | No direct bootstrap; inspect any forwarded target |
| `api/index.php` | Document API compatibility router; per-dispatch guard | No direct bootstrap; inspect any forwarded target |
| `api/qa.php` | Summary access; POST JSON | Normal GET may mutate guest state |
| `api/router.php` | Document API dispatcher; per-dispatch guard | Normal GET may mutate guest state |
| `api/track_landing.php` | Public POST telemetry with size and rate limits | Normal GET may mutate guest state |
| `auth.php` | Authentication action dispatcher; per-action guards | Normal GET may mutate guest state |
| `check_status.php` | Summary owner guest/user or valid share token | Normal GET may mutate guest state |
| `dashboard.php` | Registered non-admin; admin redirects | Normal GET may mutate guest state |
| `evaluate.php` | Active registered account plus assigned review token | Normal GET may mutate guest state |
| `evaluation.php` | Administrator redirect to dashboard; retired admin evaluation page | Normal GET may mutate guest state |
| `evaluation_export.php` | Administrator | Normal GET may mutate guest state |
| `feedback_submit.php` | Guest/user with summary access | Normal GET may mutate guest state |
| `forgot_password.php` | Public recovery form | Normal GET may mutate guest state |
| `health.php` | Public static health response | No direct bootstrap; inspect any forwarded target |
| `history.php` | Registered account | Normal GET may mutate guest state |
| `index.php` | Public landing; administrator redirects to summarizer | Normal GET may mutate guest state |
| `login.php` | Public sign-in and pending admin code form | Normal GET may mutate guest state |
| `logout.php` | Compatibility redirect to logout action | No direct bootstrap; inspect any forwarded target |
| `nutshell_generate.php` | Active registered account | Normal GET may mutate guest state |
| `processing.php` | Visitor with pending summary session | Normal GET may mutate guest state |
| `profile.php` | Registered account | Normal GET may mutate guest state |
| `register.php` | Public registration form | Normal GET may mutate guest state |
| `reset_password.php` | Public reset form; OTP required by action | Normal GET may mutate guest state |
| `result.php` | Summary owner guest/user or valid share token | Normal GET may mutate guest state |
| `summarize.php` | Guest/user summary lifecycle; per-action guards | Normal GET may mutate guest state |
| `summarizer.php` | Guest/user/admin | Normal GET may mutate guest state |
| `summary_worker.php` | CLI only | Normal GET may mutate guest state |
| `terms.php` | Public reading and guest/session or registered acceptance | Normal GET may mutate guest state |
| `translate_proxy.php` | Session and CSRF; text provided by caller | Normal GET may mutate guest state |
| `tts_audio.php` | Summary access and validated filename/token | Normal GET may mutate guest state |
| `tts_generate.php` | Session and summary access | Normal GET may mutate guest state |

## Reproducibility and evidence

Audit scripts are [ui-render-router.php](evidence/ui-render-router.php), [capture-ui.cjs](evidence/capture-ui.cjs), [capture-ui-supplement.cjs](evidence/capture-ui-supplement.cjs), and [build-ui-inventory.cjs](evidence/build-ui-inventory.cjs). The copied synthetic TXT export is [synthetic-summary-download.txt](evidence/synthetic-summary-download.txt). These files are documentation/test harness artifacts only and must not be deployed. Starting the renderer is explicit and local; no application source was edited.
