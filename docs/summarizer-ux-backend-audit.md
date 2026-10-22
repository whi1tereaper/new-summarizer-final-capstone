# Summarizer UI and Backend Audit

## Backend capabilities

- **Sources:** PDF and DOCX uploads; pasted text; public HTTP(S) article URLs. URL resolution extracts readable HTML or downloads direct PDF links. Local/private URL targets are rejected. URL retrieval has bounded response sizes, redirects, and timeouts.
- **Upload validation:** PDF/DOCX extensions and detected MIME types are checked server-side. The upload limit is 30 MiB.
- **Text validation:** text is limited to 200,000 bytes and must contain non-whitespace content.
- **Analysis modes:** `general`, `academic`, `executive`, `technical`, `study`, and `news`.
- **Output formats:** `paragraph`, `bullets`, `hybrid`, and `structured`.
- **Summary depths:** `brief`, `short`, `balanced`, `detailed`, and `comprehensive`. Depth is supplied as an enum; the legacy sentence count remains a compatibility field.
- **Processing:** a valid request is staged in the PHP session, the processing page invokes the local Python worker, the service stores the result, then redirects to the result page. URL extraction and worker errors return user-facing errors. There is no job cancellation or granular worker-stage status endpoint.
- **Result data:** the worker returns selected mode, format, depth, source metadata, readability/word-count statistics, processing timing, validation state/notes, evidence, coverage, key points, and optional structured sections. Result rendering already conditionally exposes metadata, evidence, and coverage.
- **Permissions:** summarization is accessible directly without terms and conditions gating. Nutshell remains restricted to registered users by the existing feature gate. Evaluation controls remain in the admin/research evaluation area.

## Existing UI mismatches found

- File and pasted text/URL inputs were simultaneously visible and the backend relied on implicit precedence.
- The depth slider looked continuous and submitted both enum and sentence-count values.
- Analysis modes had one generic description; output formats had a separate client-side list of descriptions.
- The processing page showed a generic state without the selected document/settings.
- The result page already rendered metadata and source evidence, but did not display validation notes.
- The app had no detailed backend stage API, so the interface could not truthfully show extraction/scoring sub-stages.

## Request contract

| UI control | Submitted field | Server handling |
| --- | --- | --- |
| Upload File | `source_type=file`, `pdf_file` | `ArticleService::buildPendingSummary` accepts the file only, then `FileUploadService` checks PDF/DOCX, MIME, and size. |
| Paste Text | `source_type=text`, `original_text` | `ArticleService` checks exclusivity, non-empty content, and 200,000-byte maximum. |
| URL | `source_type=url`, `source_url` | `ArticleService` checks exclusivity and HTTP(S); `SourceResolver` validates destination and extracts readable article content. |
| Output Format | `output_format` | Checked against `ArticleService::ALLOWED_OUTPUT_FORMATS`, then passed to the Python pipeline. |
| Analysis Mode | `analysis_mode` | Checked against `ArticleService::ALLOWED_ANALYSIS_MODES`, then used as the pipeline selection profile. |
| Summary Depth | `summary_depth` | Checked against `ArticleService::ALLOWED_SUMMARY_DEPTHS`, then used for depth-aware selection/compression. `summary_length` and `sentence_count` remain compatibility fields. |
| Document Title | `document_title` | Optional override; a blank title allows existing backend title inference/fallback behavior. |

## UI changes made

- Replaced the visible simultaneous source inputs with an exclusive Upload File / Paste Text / URL selector.
- Added uploaded file name/readiness feedback, remove/replace behavior, actual 30 MiB limit, and PDF/DOCX-only acceptance.
- Added source-specific frontend validation; backend rejects conflicting or unsupported explicit requests.
- Preserved title, text/URL source, and settings in session recovery state after failures and when returning to the form; browsers require an uploaded file to be selected again after navigation.
- Replaced the range slider with five discrete depth buttons and explicit enum submission.
- Added setting descriptions for supported formats and modes, constrained by backend allow-lists.
- Disabled inactive source controls, blocked repeated form submission, and added inline accessible feedback.
- Updated the processing screen with selected title/settings, a live status, timeout handling, and reduced-motion support.
- Displayed backend validation notes on completed results when present.
- Preserved the existing result page’s conditional metadata, evidence, and coverage sections.

## Limits and verification notes

- Extraction, profile selection, scoring, and storage do not currently publish granular live stages. The processing view reports that analysis is running without implying exact worker sub-stages.
- Nutshell continues using its existing endpoint and permission behavior; a URL is passed as the source text for its existing resolver path.
- Evaluation remains available only through existing admin/research tooling; no public evaluation control was added.
- The original form audit used code-level responsive review. The results-page revision below additionally received live headless-browser checks.

## Results page reading layout — 2026-10-07

The reference UI was the existing results page, LIGHT's summarizer and landing page, shared design tokens, buttons, inputs, and footer. The revision reuses their typography, neutral surfaces, purple accents, and native source disclosures.

- The document title and a short format/depth/read-time line precede one main reading surface. Copy, Save PDF, Listen, and Translate are next to the summary, with action feedback in the same area.
- Key points and context, source and supporting passages, and detailed metadata are grouped into three optional disclosures. Repeated section numbering, the long navigation strip, and separate tool panels are removed.
- Selected paragraph, bullet, hybrid, and structured output take precedence over a shorter overview. Supporting material remains available, including existing heading links that open containing disclosures.
- Feedback starts with a rating; the comment form appears after selection and gives way to confirmation after submission. The reading view stays visible without JavaScript.
- Print keeps the summary and visible translation/review notes, excluding the toolbar, auxiliary disclosures, rating, and footer.

Validation: `scripts/test_part2_and_result_page.php` passes 34 checks with transactional fixtures. Chrome checks covered 1366px, 768px, 390px, and 320px layouts, the four output formats, sparse/error/processing records, validation notes, long references, disclosures/deep links, copy/print, and rating confirmation. Translation/audio UI states used intercepted responses; this UI verification does not test provider availability. Fixture records were rolled back after rendering.

## Processing screen — 2026-10-07

UI CONSISTENCY CHECK

- REFERENCE: LIGHT summarizer, results page, and landing page.
- FILES INSPECTED: `processing.php`, `summarizer.php`, `result.php`, `index.php`, `assets/css/design-tokens.css`, `assets/css/index.css`, `assets/css/nex-landing.css`, `assets/css/result.css`, `assets/css/global-button-effects.css`, and the shared navigation/footer partials.
- PATTERN REUSED: paper background, white bordered surface, Satoshi headings, purple accent, shared spacing/radii, black pill action and visible focus state.
- NEW UI: document scan illustration, readable source/settings, elapsed timer, ongoing-request message, explicit recovery state, and no-JavaScript instructions.
- ADAPTATION: one focused processing card; compact label/value rows on mobile; long filenames limited visually to two lines while preserving the complete accessible text and title; reduced-motion support.
- NEW DESIGN TOKENS: None.

The indicator is indeterminate because the existing execute request publishes only its final response. It never invents percentages or engine stages. A 30-second notice explains that the request is still running; the former 90-second client abort no longer discards longer server work. Successful responses replace the processing history entry with the result page. Errors stay visible with a return-to-settings action instead of disappearing during an automatic redirect.

Uploaded original filenames are retained as display metadata without changing storage names or worker inputs. URL staging now retains `source_url` so the recovery form actually restores the original link. Uploaded files must still be selected again after a failure.

Validation: 74 headless Chrome assertions passed across 1920px, 1366px, 768px, 390px, and 320px widths, covering long/escaped/missing titles, source types, elapsed time, delayed responses, successful navigation, server/network/invalid-response errors, reduced motion, and JavaScript disabled. Execute responses were intercepted; these checks did not run summarization or contact providers. PHP and JavaScript syntax checks passed. Source/settings recovery was separately exercised through the actual pending-payload and form-state methods.

## Processing screen cleanup — 2026-10-14
- Removed redundant "In progress" eyebrow above headline to streamline vertical visual flow.
- Removed duplicated "Working on your document" activity label and accent dot from card footer.
- Aligned elapsed timer and cancel control cleanly to the right side of the activity footer.
- Cleaned up JavaScript DOM element references to prevent null-property errors.

## Result page feature cleanup — 2026-10-14
- Removed the "Ask About This Document" (Document Q&A) section from `result.php`.
- Removed corresponding event listeners, fetch logic, and state handling in `assets/js/result.js`.
- Pruned unused `.result-section--qa` and `.result-qa-*` styling rules from `assets/css/result.css`.
- Removed collapsible `<details>` / `<summary>` dropdown toggles for `result-context`, `result-sources`, and `result-details`, rendering them directly as open semantic sections (`.result-open-group`).
- Displayed supporting evidence items and source text viewer openly without click-to-expand toggles.
- Made the feedback comment block (`#feedback-comment-block`) visible directly without requiring a prior star rating click.
- Wired up the "Update feedback" toggle (`#feedback-edit-btn`) to restore the form and comment block when editing past feedback.
- Removed redundant prompt label ("Anything you'd like to add? (optional)") and explanatory hint paragraph ("Your comment is displayed anonymously to administrators.") above the textarea.
- Preserved the Anonymous badge aligned to the top-right and added an explicit `aria-label` to the textarea for full accessibility.
- Updated `scripts/test_part2_and_result_page.php` to verify open section accessibility, comment block visibility, and omission of redundant feedback copy.

## Analytics & Graphs Dashboard Relocation — 2026-10-14
- Relocated the analytics KPI cards and interactive charts from the summarizer page (`summarizer.php`) to the user dashboard (`dashboard.php`) and administrator dashboard (`admin_dashboard.php`).
- Extracted the analytics component into a shared partial (`app/public/partials/analytics-graphs-card.php`) supporting role-based contexts (`Your Activity` for regular users vs `System Overview` for administrators).
- Modularized styling into `app/public/assets/css/dashboard-charts.css` featuring a responsive 2x2 chart grid with unified typography, spacing, and design token integration.
- Decoupled Chart.js rendering and dynamic date-range filtering (`30d`, `7d`, `90d`, `all`) into dedicated `app/public/assets/js/dashboard-charts.js`, removing ~160 lines of sidebar chart logic from `app/public/assets/js/index.js`.
- Restored `summarizer.php` to a clean, focused single-column workspace dedicated solely to document drop and analysis configuration, removing unused database queries and vendor Chart.js bundles.
- Verified system-wide admin analytics aggregation and regular user-scoped dashboard rendering across both dashboard views.

## Native Audio Player Cleanup — 2026-10-14
- Hidden the browser native `<audio controls>` player widget on `result.php`.
- Removed `controls` attribute and added `hidden` attribute to `#summary-audio-player` in `result.php`.
- Added `display: none !important;` for `.result-audio-player` in `assets/css/result.css`.
- Streamlined playback control through the reading toolbar `#play-audio-btn`, which dynamically toggles state labels ("Listen", "Pause", "Resume") across both Web Speech API boundaries and server-generated audio streams.

## Dark Mode Theme Isolation — 2026-10-14
- Restricted dark mode theme execution exclusively to the results page (`result.php`).
- Removed `localStorage.getItem('light-results-theme')` execution scripts from `summarizer.php` and `processing.php` to prevent theme bleed across navigation.
- Removed dark theme overrides for `.nex-workspace-page` (summarizer workspace) in `assets/css/light-errors.css`.
- Explicitly enforced `<html lang="en" data-theme="light">` across `dashboard.php`, `admin_dashboard.php`, `summarizer.php`, and `processing.php`.
- Retained full dark theme toggle capabilities (`#result-theme-toggle`), storage persistence, and scoped CSS variables on `.result-page-body` for `result.php`.







