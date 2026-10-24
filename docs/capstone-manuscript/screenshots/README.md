# Screenshot provenance register

Captured 2026-10-17 (Asia/Manila). These 46 original screenshots show 23 isolated UI states rendered from the application's real PHP templates at desktop 1440×1000 and mobile 390×844. They are **ISOLATED TEMPLATE RENDERS WITH SYNTHETIC DATA**, not screenshots of authenticated live use. The exact URLs below point to the audit harness that was used, not a deployed service.

The renderer bypasses the normal bootstrap, injects an anonymous/user/admin fixture and a read-only PDO subclass, blocks mutations and uses synthetic/empty rows. Original application files are included without editing. External fonts, tracking and backend calls are blocked. A black audit provenance banner is inserted into screenshots. Its fixed position appears at the first viewport boundary in full-page images. The captures are suitable for explaining layout with these limitations, and must be replaced or complemented by approved live end-to-end captures before claiming deployed workflow evidence.

A synthetic account uses a reserved .invalid address. Summary text explicitly says that it is synthetic and not model output. Empty charts and zero counters are fixture state, not research or operational measurements. The processing screenshot cannot demonstrate job execution because execution requests are blocked. The original no-bootstrap health route and redirects were tested separately; see the audit live-safe-get record.

| State | Viewport | HTTP | Image | Capture URL |
|---|---|---:|---|---|
| landing | desktop | 200 | [PNG](ui-landing-desktop.png) | `http://127.0.0.1:8765/index.php` |
| summarizer | desktop | 200 | [PNG](ui-summarizer-desktop.png) | `http://127.0.0.1:8765/summarizer.php` |
| sign-in | desktop | 200 | [PNG](ui-sign-in-desktop.png) | `http://127.0.0.1:8765/login.php` |
| register | desktop | 200 | [PNG](ui-register-desktop.png) | `http://127.0.0.1:8765/register.php` |
| password-recovery | desktop | 200 | [PNG](ui-password-recovery-desktop.png) | `http://127.0.0.1:8765/forgot_password.php` |
| password-reset | desktop | 200 | [PNG](ui-password-reset-desktop.png) | `http://127.0.0.1:8765/reset_password.php` |
| terms | desktop | 200 | [PNG](ui-terms-desktop.png) | `http://127.0.0.1:8765/terms.php` |
| accept-terms | desktop | 200 | [PNG](ui-accept-terms-desktop.png) | `http://127.0.0.1:8765/accept_terms.php` |
| dashboard-empty | desktop | 200 | [PNG](ui-dashboard-empty-desktop.png) | `http://127.0.0.1:8765/dashboard.php?fixture_role=user` |
| profile-synthetic | desktop | 200 | [PNG](ui-profile-synthetic-desktop.png) | `http://127.0.0.1:8765/profile.php?fixture_role=user` |
| history-empty | desktop | 200 | [PNG](ui-history-empty-desktop.png) | `http://127.0.0.1:8765/history.php?fixture_role=user` |
| analytics-empty | desktop | 200 | [PNG](ui-analytics-empty-desktop.png) | `http://127.0.0.1:8765/analytics.php?fixture_role=user` |
| processing-synthetic | desktop | 200 | [PNG](ui-processing-synthetic-desktop.png) | `http://127.0.0.1:8765/processing.php?fixture_pending=1` |
| result-synthetic | desktop | 200 | [PNG](ui-result-synthetic-desktop.png) | `http://127.0.0.1:8765/result.php?id=900001` |
| result-unavailable | desktop | 404 | [PNG](ui-result-unavailable-desktop.png) | `http://127.0.0.1:8765/result.php?id=900002` |
| admin-dashboard-empty | desktop | 200 | [PNG](ui-admin-dashboard-empty-desktop.png) | `http://127.0.0.1:8765/admin_dashboard.php?fixture_role=admin` |
| admin-audit-logs-empty | desktop | 200 | [PNG](ui-admin-audit-logs-empty-desktop.png) | `http://127.0.0.1:8765/admin_audit_logs.php?fixture_role=admin` |
| evaluation-denied | desktop | 403 | [PNG](ui-evaluation-denied-desktop.png) | `http://127.0.0.1:8765/evaluate.php` |
| evaluation-empty | desktop | 200 | [PNG](ui-evaluation-empty-desktop.png) | `http://127.0.0.1:8765/evaluate.php?fixture_role=user` |
| landing | mobile | 200 | [PNG](ui-landing-mobile.png) | `http://127.0.0.1:8765/index.php` |
| summarizer | mobile | 200 | [PNG](ui-summarizer-mobile.png) | `http://127.0.0.1:8765/summarizer.php` |
| sign-in | mobile | 200 | [PNG](ui-sign-in-mobile.png) | `http://127.0.0.1:8765/login.php` |
| register | mobile | 200 | [PNG](ui-register-mobile.png) | `http://127.0.0.1:8765/register.php` |
| password-recovery | mobile | 200 | [PNG](ui-password-recovery-mobile.png) | `http://127.0.0.1:8765/forgot_password.php` |
| password-reset | mobile | 200 | [PNG](ui-password-reset-mobile.png) | `http://127.0.0.1:8765/reset_password.php` |
| terms | mobile | 200 | [PNG](ui-terms-mobile.png) | `http://127.0.0.1:8765/terms.php` |
| accept-terms | mobile | 200 | [PNG](ui-accept-terms-mobile.png) | `http://127.0.0.1:8765/accept_terms.php` |
| dashboard-empty | mobile | 200 | [PNG](ui-dashboard-empty-mobile.png) | `http://127.0.0.1:8765/dashboard.php?fixture_role=user` |
| profile-synthetic | mobile | 200 | [PNG](ui-profile-synthetic-mobile.png) | `http://127.0.0.1:8765/profile.php?fixture_role=user` |
| history-empty | mobile | 200 | [PNG](ui-history-empty-mobile.png) | `http://127.0.0.1:8765/history.php?fixture_role=user` |
| analytics-empty | mobile | 200 | [PNG](ui-analytics-empty-mobile.png) | `http://127.0.0.1:8765/analytics.php?fixture_role=user` |
| processing-synthetic | mobile | 200 | [PNG](ui-processing-synthetic-mobile.png) | `http://127.0.0.1:8765/processing.php?fixture_pending=1` |
| result-synthetic | mobile | 200 | [PNG](ui-result-synthetic-mobile.png) | `http://127.0.0.1:8765/result.php?id=900001` |
| result-unavailable | mobile | 404 | [PNG](ui-result-unavailable-mobile.png) | `http://127.0.0.1:8765/result.php?id=900002` |
| admin-dashboard-empty | mobile | 200 | [PNG](ui-admin-dashboard-empty-mobile.png) | `http://127.0.0.1:8765/admin_dashboard.php?fixture_role=admin` |
| admin-audit-logs-empty | mobile | 200 | [PNG](ui-admin-audit-logs-empty-mobile.png) | `http://127.0.0.1:8765/admin_audit_logs.php?fixture_role=admin` |
| evaluation-denied | mobile | 403 | [PNG](ui-evaluation-denied-mobile.png) | `http://127.0.0.1:8765/evaluate.php` |
| evaluation-empty | mobile | 200 | [PNG](ui-evaluation-empty-mobile.png) | `http://127.0.0.1:8765/evaluate.php?fixture_role=user` |
| admin-code-synthetic | desktop | 200 | [PNG](ui-admin-code-synthetic-desktop.png) | `http://127.0.0.1:8765/login.php?fixture_admin_code=1` |
| terms-pending-synthetic | desktop | 200 | [PNG](ui-terms-pending-synthetic-desktop.png) | `http://127.0.0.1:8765/accept_terms.php?fixture_role=user&fixture_terms_pending=1` |
| admin-log-filters-empty | desktop | 200 | [PNG](ui-admin-log-filters-empty-desktop.png) | `http://127.0.0.1:8765/admin_audit_logs.php?fixture_role=admin&fixture_logs=1` |
| logout-modal-synthetic | desktop | 200 | [PNG](ui-logout-modal-synthetic-desktop.png) | `http://127.0.0.1:8765/profile.php?fixture_role=user` |
| admin-code-synthetic | mobile | 200 | [PNG](ui-admin-code-synthetic-mobile.png) | `http://127.0.0.1:8765/login.php?fixture_admin_code=1` |
| terms-pending-synthetic | mobile | 200 | [PNG](ui-terms-pending-synthetic-mobile.png) | `http://127.0.0.1:8765/accept_terms.php?fixture_role=user&fixture_terms_pending=1` |
| admin-log-filters-empty | mobile | 200 | [PNG](ui-admin-log-filters-empty-mobile.png) | `http://127.0.0.1:8765/admin_audit_logs.php?fixture_role=admin&fixture_logs=1` |
| logout-modal-synthetic | mobile | 200 | [PNG](ui-logout-modal-synthetic-mobile.png) | `http://127.0.0.1:8765/profile.php?fixture_role=user` |

Reproduction: start the audit-only PHP renderer and execute the two capture scripts under `docs/system-audit/evidence/`. Do not deploy the renderer or use it as an authentication test. See `browser-readonly-results.json` for machine-readable capture and interaction evidence.
