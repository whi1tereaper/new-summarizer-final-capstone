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
