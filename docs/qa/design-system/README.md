# Design system verification

Verified on 27 October 2026 using Microsoft Edge/Chromium, PHP 8.3 and a restricted, disposable MySQL database.

- **110 browser checks passed; 0 failed.**
- **96 page/theme/layout samples:** 24 routes or access contexts, light and dark themes, 1440px desktop and 390px mobile with touch/coarse-pointer emulation.
- **520 rendered buttons** matched canonical components for font, size, line height, padding, border and radius. No page styling conflicts remained. Password-field icon positioning was excluded from that geometric comparison.
- **27 stylesheets** passed the source audit with no undefined custom properties, separate page color palettes, literal hex/RGB/HSL colors outside the token source, or unbalanced CSS blocks/comments/strings.
- No page script errors, failed local asset responses, viewport overflow or clipped form controls remained in the final matrix.
- Production core rows were preserved, no live activity was observed during the run, and the owned server, disposable databases and restricted database user were removed.

The route matrix covers landing/about, terms/acceptance, login/admin login, registration, password recovery/reset, guest/user/admin workspaces, user/admin dashboards, history, profile/settings, analytics, Student ID Management, audit logs, storage, results, processing and evaluation report rendering.

The behavior checks verify initial system detection before body insertion, saved preference before body insertion, live operating-system changes, explicit overrides, cross-page persistence/reload, cross-tab synchronization, reset to system, blocked browser storage, accessible theme controls, live semantic-token propagation, chart recoloring, button hover/active/focus/loading/disabled states, guarded busy actions, source choices, mobile sidebar behavior, password visibility, Appearance selection, result copy/export controls and existing CSRF rejection.

All records were synthetic fixtures. The processing view received a controlled pending response; no NLP generation or external email was executed. Google font stylesheets were replaced with empty CSS for deterministic local testing, so bundled and system font fallbacks were exercised. The initial-theme check records the root theme at first body insertion; it is not a pixel-level filmstrip of browser startup.

## Evidence

- [Browser results](browser-results.json)
- [Page measurements and button comparisons](page-measurements.json)
- [Button conflicts — empty](action-conflicts.json)
- [Static token audit](static-results.json)
- [Production integrity](integrity.json)
- [Desktop workspace, dark](workspace-user-dark-1440.png)
- [Mobile workspace, light](workspace-user-light-390.png)
- [Desktop result, dark](result-dark-1440.png)
- [Mobile admin dashboard, light](dashboard-admin-light-390.png)
- [Profile Appearance settings, dark](profile-settings-dark-1440.png)
- [Authentication, mobile dark](login-dark-390.png)

Representative screenshots for eight major views in both themes and sizes are stored beside this report.

## Reproduce

From the repository root with local XAMPP MySQL available and the existing `.env` configured:

```powershell
.\.venv311\Scripts\python.exe scripts/qa/design_system_static.py
.\.venv311\Scripts\python.exe scripts/qa/design_system_qa.py all
```

The `all` command sets up restricted fixtures, starts its own hidden Apache instance, runs the browser suite, and always attempts cleanup. Separate `setup`, `browser`, and `stop` actions are available for diagnosis. Configuration, credentials and sessions remain in a private `LOCALAPPDATA` folder; report artifacts contain no credentials.
