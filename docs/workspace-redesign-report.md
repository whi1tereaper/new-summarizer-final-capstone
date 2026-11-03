# LIGHT Summarize workspace redesign

Implemented October 26, 2026. Release verdict: **READY**, subject to the verification limits below.

## A. Files changed

Modified application files:

- `app/public/summarizer.php`: compact heading, dedicated workspace assets, skip link, and compact support/footer navigation.
- `app/public/partials/summarizer-form.php`: source toolbar, unified document/preferences layout, optional Focus, accessible upload/editor/URL controls, and action bar. Existing request and security fields are retained.
- `app/public/assets/js/index.js`: Focus draft persistence, consistent restored depth fields, mutual exclusion between summary and Nutshell requests, preserved button labels/icons, and real operation state. Pre-existing recovery changes were retained.

Created application files:

- `app/public/assets/css/summarizer-workspace.css`: scoped layout, theme tokens, responsive controls, feedback states, navigation adjustments, and logout-dialog styles.
- `app/public/assets/js/summarizer-workspace.js`: real file dropping and replacement, source readiness, inline validation, bounded editor resizing/counts, preferences focus, and mobile keyboard handling.

Created verification files:

- `scripts/qa/test_workspace_redesign.py`
- `scripts/qa/workspace_redesign_browser.cjs`
- `docs/workspace-redesign-report.md`
- `docs/qa/workspace-redesign/README.md`
- In `docs/qa/workspace-redesign/`: `before.json`, `browser.json`, `supplementary.json`, `screenshots.json`, `contrast.json`, `integrity.json`, `persistence.json`, `summary.json`, `intelligence-test.txt`, and the temporary-run locator `last-run.txt`.
- All 15 PNG artifacts linked individually in section E below. Temporary credentials and synthetic test databases are excluded from the deliverables.

No application files were deleted. No framework, package, database migration, or summarization algorithm was introduced. Shared landing styles remain available to the landing page; the workspace no longer loads the old landing/global styles or animation script. Unrelated existing working-tree changes were preserved.

## B. UI improvements

The page now follows the supplied reference: compact left-aligned heading, source toolbar with a single Preferences action, large document input, a quiet vertical divider, a three-by-two depth selector, and aligned summary/Nutshell buttons. Optional Focus and configured synthesis consent are discoverable under More preferences. Account allowances and privacy guidance remain visible.

Desktop and laptop layouts use two columns. Tablet and mobile layouts stack document, preferences, and actions. Mobile navigation retains authorized destinations and adds keyboard focus handling. Light and dark themes use the existing persisted theme preference. The local LIGHT font and favicon are retained; functional inline SVG icons require no library.

Uploads show filename, extension, size, and Replace/Remove controls. Unsupported or oversized files receive inline errors. Text has a live count and bounded automatic resizing. Source changes retain entered drafts while disabling inactive source fields. Generation actions are unavailable without valid input and while either operation is running. Backend failures retain recoverable source/settings state.

## C. Preserved functionality and integration points

- `summarize.php` remains the form action; successful requests retain the existing `processing.php` / `result.php` flow.
- `nutshell_generate.php` remains a separate operation with inline output, copying, request recovery, and the shared usage allowance.
- Source fields remain `source_type`, `pdf_file`, `original_text`, and `source_url`. Browser tests verify only the selected source is submitted.
- Optional `document_title` and `focus_query` reach the real backend and were verified in isolated persisted results.
- Output values remain `paragraph`, `bullets`, `hybrid`, `structured`, and `key_takeaways`.
- Analysis values remain `general`, `academic`, `executive`, `technical`, `study`, `news`, `legal`, and `scientific`.
- Depth values remain `very_brief`, `brief`, `short`, `balanced`, `detailed`, and `comprehensive`. `summary_depth`, `summary_length`, and sentence counts stay synchronized, including restored drafts and dashboard preference links.
- CSRF tokens, request tokens, synthesis consent, session/role navigation, trial restrictions, server URL protections, processing status, history persistence, and failure recovery use the existing services.
- Upload and text limits are derived from existing backend capabilities.

## D. Testing results

All final executed tests passed:

| Verification | Result |
| --- | --- |
| Existing source/error/bridge contract regression | 145 passed |
| Existing intelligence regression using the real isolated Python engine | 36 passed, 0 failed |
| Main browser suite | 45 passed, 0 failed |
| Supplementary browser checks | 12 passed, 0 failed |
| Modified PHP and JavaScript syntax | Passed |
| Diff whitespace check | Passed |

Total: **238 recorded checks passed, 0 failed**, plus syntax and whitespace checks.

The browser suites include screenshot assertions; the count is not a claim of 57 independent summarization scenarios. Real end-to-end generation succeeded for pasted text, PDF, DOCX, a public NASA article URL, and Nutshell. Standard requests reached actual result pages with one initial submission despite duplicate submit events. Persistence evidence confirms completed results, the optional title, Focus, and the actual `nutshell-extractive-v2` algorithm.

Three successive real guest Nutshells reduced the shared allowance from 3 to 0. At exhaustion, generation controls were hidden and the last result remained copyable, including at 390px. Real invalid-CSRF and local-network URL requests were rejected. Browser errors retained input and settings. Administrative links remained restricted to synthetic administrator sessions, and the existing logout confirmation remained functional.

Both themes were checked at **360, 390, 768, 1024, 1280, and 1440px**, with expected columns and no viewport overflow or clipped controls. Accessibility checks covered one page heading, labels, live regions, selected states, visible focus, Preferences navigation, mobile Escape/focus return, and reduced-motion handling. Muted text and input boundaries were strengthened for contrast.

Tests used local XAMPP Apache, PHP 8.3, Microsoft Edge/Playwright, the real Python worker, and an isolated synthetic MySQL database. The QA database user was denied access to the application database. No successful backend responses were mocked. The initial automation response-body/navigation race was corrected before the successful final run.

[Database integrity verification](qa/workspace-redesign/integrity.json) confirms that the original users, summaries, and feedback rows were preserved. Other live database activity was observed during testing, so this is not a claim that the entire live database was frozen. The owned QA Apache/NLP processes were stopped, isolated schemas and database user removed, and private credentials deleted. The temporary QA preview is no longer running.

[Sampled contrast measurements](qa/workspace-redesign/contrast.json) pass 4.5:1 for the tested small text and 3:1 for the tested input boundaries in both themes; the minimum tested muted-text contrast is 4.83:1 and control-border contrast is 3.14:1.

Detailed evidence and reproduction commands: [QA README](qa/workspace-redesign/README.md), [main browser results](qa/workspace-redesign/browser.json), [supplementary results](qa/workspace-redesign/supplementary.json), [real engine regression](qa/workspace-redesign/intelligence-test.txt), and [persistence checks](qa/workspace-redesign/persistence.json).

## E. Screenshots

| View | Before | After |
| --- | --- | --- |
| Desktop | [Before](qa/workspace-redesign/before-desktop.png) | [Guest](qa/workspace-redesign/after-desktop.png), [administrator](qa/workspace-redesign/after-admin-desktop.png) |
| Tablet | [Before](qa/workspace-redesign/before-tablet.png) | [After](qa/workspace-redesign/after-tablet.png) |
| Mobile | [Before](qa/workspace-redesign/before-mobile.png) | [After](qa/workspace-redesign/after-mobile.png) |
| Dark theme | [Before](qa/workspace-redesign/before-dark.png) | [After](qa/workspace-redesign/after-dark.png) |

Real output captures: [text](qa/workspace-redesign/real-text-result.png), [PDF](qa/workspace-redesign/real-pdf-result.png), [DOCX](qa/workspace-redesign/real-docx-result.png), [article URL](qa/workspace-redesign/real-url-result.png), [Nutshell](qa/workspace-redesign/real-nutshell.png), and [mobile trial exhaustion](qa/workspace-redesign/real-mobile-trial-exhausted.png).

## F. Remaining issues and verification limits

No unresolved functional or layout defect was found in the tested scenarios. Browser automation used Edge/Chromium; separate Safari/Firefox and manual assistive-technology sessions were not run. Accessibility checks are not a full WCAG certification. Optional external LLM synthesis consent was retained; live external-provider generation was not exercised. The complete repository test suite was not run; the relevant source, engine, and workspace suites were run.

## G. Release verdict

**READY.** The requested layout and interactions are implemented, all tested real generation workflows succeed, and responsive/theme/access controls pass. Deployment was not requested; changes are in the existing local application.
