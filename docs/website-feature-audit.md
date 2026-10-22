# LIGHT Website Feature Audit and Documentation

## Reference source and documentation framing

This audit uses the AccredX manuscript, "AccredX: Record Management and Archiving System for NEUST Peñaranda Off-Campus," as a supplemental reference for documenting a web-based institutional records workflow. The manuscript frames the system as a digital platform for organizing, storing, retrieving, and archiving records in support of accreditation and administrative continuity. In documentation terms, that translates to the following feature expectations that guide this audit:

- central record repository and long-term archival storage
- searchable retrieval of institutional or academic documents
- role-based access for administrators and users
- secure handling of records with structured metadata
- reporting and monitoring functions for operational oversight

These reference concepts are not a separate project scope for LIGHT itself; they are used to anchor the audit language around evidence-driven feature documentation and to reinforce the distinction between implemented capabilities and aspirational or partially validated ones.

## Reference compliance matrix for manuscript-style documentation

To align this audit with the academic reporting style of the AccredX manuscript, the project documentation follows a chapter-based structure grounded in evidence rather than copied system claims.

- Title page and preliminaries: the project is documented as LIGHT, with academic front matter placeholders where actual student, adviser, institution, and approval details are not present in the codebase.
- Chapter I — The Problem and Its Background: maps to the product purpose, user pain points, and the broader need for AI-driven document summarization, supported by the landing page, summarizer flow, and backend routes.
- Chapter II — Review of Related Literature and Methodology: maps to the repository architecture, feature inventory, user roles, workflow analysis, and evidence-based system review captured in this audit and the supporting technical docs.
- Chapter III — Results and Discussion: maps to the feature verification table in this file and the observed working/partial/verified classification across authentication, analytics, admin controls, and result workflows.
- Chapter IV — Conclusion and Recommendations: maps to the final assessment that the core summarization workflow is operational while broader quality validation remains partial rather than absolute.

This approach intentionally mirrors the reference manuscript's academic structure without copying its specific project claims, author details, or technical conclusions.

## Executive summary

LIGHT is a PHP-based AI document summarization application that presents a polished landing page, a configurable summarizer workspace, result analysis pages, a personal dashboard, and administrative tools. The implementation is organized around PHP entry pages in `app/public`, backend controllers and services in `app/src`, configuration in `app/config`, and the relational schema in `database/schema.sql`.

The site supports three primary user paths:

- Anonymous or guest users can reach the landing page and use the summarization workflow.
- Registered users can save history, access preferences, see personal analytics, and manage their generated summaries.
- Administrators can access admin dashboards, audit logs, challenge setup, and users deletion/deactivation.


## System architecture

### Frontend and routes

The public-facing interface is centered on PHP pages under `app/public`:

- `index.php` — marketing/landing page for LIGHT.
- `summarizer.php` — the main summarization workspace.
- `processing.php` — in-flight status page while requests are being processed.
- `result.php` — final summary display, metadata, evidence, and feedback.
- `dashboard.php` and `analytics.php` — user-level activity overview.
- `login.php`, `register.php`, `forgot_password.php`, `reset_password.php` — authentication and account recovery.
- `admin_dashboard.php`, `admin_audit_logs.php`, `admin_verify.php` — admin-only administration.

### Backend logic

The primary business logic is under `app/src` and is split across controllers, services, and repositories:

- `Controllers/ArticleController.php` handles submission, job status, retry, cancellation, and background execution.
- `Services/ArticleService.php` builds pending summaries, validates inputs, resolves sources, and creates the summary result.
- `Services/SummaryJobService.php` manages asynchronous job state and persistence.
- `Services/EvaluationService.php` and related evaluation code provide admin/research evaluation workflows.
- `Repositories/HistoryRepository.php` and `FeedbackRepository.php` support saved history and feedback retrieval.

### Data layer

The canonical database schema is defined in `database/schema.sql` and includes tables for:

- `users` and `password_resets`
- `guest_sessions` for anonymous access
- `rate_limits` for throttling
- `summaries` for generated summary records
- `feedback` for ratings and comments
- `documents` and `document_summaries` for document-level content and structured summaries

### Python summarization engine

The summarization pipeline integrates with the Python-based engine under `python-engine`, including summarization, evaluation, and benchmark tooling. The configuration is defined in `app/config/config.php` and environment values are loaded from `APP_ENV` and related settings.

## Feature inventory and verification status

### 1. Landing page and brand experience
Status: VERIFIED WORKING

Purpose: Introduce the LIGHT product and direct visitors into the summarization flow.

Evidence: `app/public/index.php`, `app/public/partials/site-nav.php`, `app/public/partials/nex-footer.php`, and the CSS under `app/public/assets/css/`.

The page is a marketing-style landing page with a hero section, brand messaging, a clear beginning-to-end CTA, and a navigation header. It intentionally routes admin users directly to the summarizer workspace.

### 2. Authentication and account lifecycle
Status: PARTIALLY WORKING

Purpose: Allow registration, login, logout, password reset, and account deactivation.

Evidence: `app/public/auth.php`, `app/src/Controllers/AuthController.php`, `app/public/login.php`, `app/public/register.php`, `app/public/forgot_password.php`, `app/public/reset_password.php`, and `database/schema.sql`.

The site provides a full authentication flow using a database-backed user model. Registered-user restrictions are enforced on analytics and dashboard access. Password reset and recovery flows exist, along with a guest-session mechanism for anonymous usage.

The current audit found evidence of working account flows in code and routing, but the repository does not provide a full end-to-end authenticated verification record, so the status remains partially working rather than fully validated.

### 3. Guest and registered summarization entry
Status: VERIFIED WORKING

Purpose: Accept a summary request from a visitor with either guest or registered access.

Evidence: `app/public/summarizer.php`, `app/public/partials/summarizer-form.php`, `app/src/Controllers/ArticleController.php`, and `app/src/Services/ArticleService.php`.

The UI supports uploading a PDF or DOCX file, pasting text, or entering a URL. The backend validates inputs, tokenizes user context, and generates a pending summary job before redirecting to the processing state.

### 4. Job processing and background summary execution
Status: VERIFIED WORKING

Purpose: Turn a submitted document into a generated summary asynchronously and keep the user informed while work is in progress.

Evidence: `app/public/processing.php`, `app/src/Controllers/ArticleController.php`, `app/src/Services/SummaryJobService.php`, and the Python summarizer engine under `python-engine`.

After form submission, the system stores a job payload, marks a job as queued/processing, starts the worker, and then redirects the user to the processing screen. The processing page shows the document title, selected settings, and recovery actions when an error occurs.

The supporting documentation (`docs/summarizer-ux-backend-audit.md`) confirms the request contract and the processing flow.

### 5. Result rendering and audience-friendly summary display
Status: VERIFIED WORKING

Purpose: Present the generated summary in a readable format, along with additional metadata and supporting context.

Evidence: `app/public/result.php`, `app/src/Utils/Formatter.php`, `app/src/Controllers/FeedbackHandler.php`, and `app/public/partials/rating-widget.php`.

The result page can display overall summary text, key points, keywords, metadata, source context, validation notes, and optional structured sections. The page includes rating and feedback functionality so the viewer can evaluate the generated result.

This is one of the strongest feature areas in the app, and the repository includes UI validation notes and browser-level checks for multiple layouts and output modes.

### 6. Personal history and dashboard retention
Status: VERIFIED WORKING

Purpose: Let registered users access their prior summaries and quickly reuse preferred settings.

Evidence: `app/public/dashboard.php`, `app/public/history.php`, `app/src/Repositories/HistoryRepository.php`, and `database/schema.sql`.

The system stores summary records against `user_id` or `guest_token` and enables retrieval from the history and dashboard screens. The dashboard shows recent summaries and preferred settings, while the history screen exposes a user’s saved result list.

### 7. Analytics and reporting
Status: PARTIALLY WORKING

Purpose: Show user and system activity trends, number of summaries, word counts, and reduction metrics.

Evidence: `app/public/analytics.php`, `app/src/Controllers/AnalyticsController.php`, `app/public/partials/analytics-graphs-card.php`, and the JS files in `app/public/assets/js/analytics.js` and `app/public/assets/js/dashboard-charts.js`.

The analytics page supports date filtering, KPI cards, and chart rendering. It handles personal analytics for standard users and broader system analytics for admins. This is implemented and routed consistently, but the quality acceptance documentation keeps some broader validation as partially tested rather than fully proven across all scenarios.

### 8. Admin administration and system oversight
Status: VERIFIED WORKING

Purpose: Allow administrators to inspect system behavior, review audit logs, and manage challenge or evaluation-related settings.

Evidence: `app/public/admin_dashboard.php`, `app/public/admin_audit_logs.php`, `app/public/admin_challenge_setup.php`, `app/src/Controllers/AdminController.php`, and `app/src/Services/AdminSecurityService.php`.

Admin tools include dashboard views, challenge setup, audit logs, verification screens, and evaluation-related controls. The app explicitly redirects admin users to the summarizer workspace from the landing page, then exposes straightforward admin navigation through the site header.

### 9. Feedback, ratings, and result quality evaluation
Status: VERIFIED WORKING

Purpose: Capture a result rating and freeform feedback for future quality review.

Evidence: `app/src/Controllers/FeedbackHandler.php`, `app/src/Services/FeedbackService.php`, `database/schema.sql`, `app/public/result.php`, and `app/public/partials/rating-widget.php`.

Submitted ratings are persisted and associated with a summary, user or guest token, and optional comment. This supports quality tracking and iterative improvement.

### 10. Translation, audio, and additional output helpers
Status: IMPLEMENTED BUT UNVERIFIED

Purpose: Support translated and spoken versions of summaries.

Evidence: `app/public/translate_proxy.php`, `app/public/tts_generate.php`, `app/public/tts_audio.php`, and the config and TTS settings in `app/config/config.php`.

These features are implemented and configured in the app, but they are not fully covered by the repo’s public docs as a complete end-to-end verified feature set. They appear to be supported by backend services and configuration, but they remain outside the core audited acceptance coverage.

## User experience assessment

The LIGHT site is generally coherent in tone and visual identity. It uses a shared navigation system, landing-page editorial styling, and reusable design tokens to maintain a consistent interface. The landing page is clear, the summarization flow is understandable, and the result page presents summary content in a readable format.

The main strengths are:

- Clear purpose and strong landing-page narrative.
- Direct path from landing page to summarizer.
- Background processing flow that prevents users from getting stuck on a blank page.
- Structured result display with metadata and feedback opportunities.
- Consistent LIGHT branding across pages.

The main risks are:

- Some capabilities are stronger in implementation evidence than in full end-to-end validation.
- Admin and evaluation features are present, but they are not the target experience for ordinary users.

## Security and reliability observations

The system includes several protective elements:

- input and upload validation for document types and sizes
- rate limiting via `rate_limits`
- guest/session handling with expiry controls
- stored summary and feedback records tied to user or guest identity
- admin-only navigation and role checks

The codebase also includes explicit configuration controls for security-sensitive settings, including trusted proxies and app environment values defined in `app/config/config.php`.

At the same time, the active repository documentation emphasizes engineering validation, not broad production assurance. The quality acceptance documentation is more precise about what was proven and what remained partially validated, which is an appropriate framing for this project.

## Overall status

LIGHT is a functional AI document Article summarizer with a complete public workflow, strong backend structure, clear route separation, and meaningful analytics and admin tooling. The codebase and documentation show that core summary generation and result handling are operational, while broader quality validation remains partial rather than absolute.

Most useful overall classification:

- Core summary application: VERIFIED WORKING
- Authentication and session flow: PARTIALLY WORKING
- Analytics and dashboards: PARTIALLY WORKING
- Quality assurance and linguistic evaluation: PARTIALLY VERIFIED
- Admin and evaluation tooling: VERIFIED WORKING

## Supporting documentation already in the project

This audit is consistent with the technical review materials already present in the repository:

- `docs/quality-acceptance-report.md`
- `docs/summarizer-ux-backend-audit.md`
- `docs/evaluation-methodology.md`
- `docs/evaluation-operations.md`
- external AccredX manuscript used as a feature-design reference for record-management and archival workflows

These documents provide a more technical view of the summarization engine, QA results, and feature contracts than this brief audit file. The AccredX manuscript adds a complementary record-management perspective that is useful when comparing institutional archival requirements to the implemented LIGHT feature set.

## Evidence references

- `app/public/index.php`
- `app/public/summarizer.php`
- `app/public/processing.php`
- `app/public/result.php`
- `app/public/dashboard.php`
- `app/public/analytics.php`
- `app/public/auth.php`
- `app/src/Controllers/ArticleController.php`
- `app/src/Controllers/AuthController.php`
- `app/src/Services/ArticleService.php`
- `app/src/Services/SummaryJobService.php`
- `database/schema.sql`
- `app/config/config.php`
- `docs/quality-acceptance-report.md`
- `docs/summarizer-ux-backend-audit.md`
