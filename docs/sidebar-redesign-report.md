# LIGHT sidebar navigation redesign

Implemented October 26, 2026. Release verdict: **READY**.

## What changed

The application navigation is now a shared, fixed left sidebar on application pages. It retains LIGHT branding, places the authenticated account directly below the brand, and organizes destinations into Workspace, Management, and Account groups.

- `app/public/partials/app-navigation.php` is the central, permission-aware navigation map.
- `app/public/partials/app-sidebar.php` is the reusable PHP sidebar/account-disclosure component.
- `app/public/assets/css/app-sidebar.css` provides the desktop, collapsed, dark-theme, and mobile-drawer layout.
- `app/public/assets/js/app-sidebar.js` provides account disclosure keyboard behavior, persisted desktop collapse state, drawer focus/inert handling, logout handoff, and hash-aware active links.
- `app/public/partials/site-nav.php` now selects the sidebar for authenticated application routes while leaving public/authentication pages on their existing header.

The existing Summarize workspace remains unchanged functionally and now responds to its available content width beside the sidebar. Profile no longer renders a competing local navigation column. Dashboard and Admin Dashboard content use their shared layout beside the new sidebar; the Admin Dashboard content wrapper is now semantic `main`.

## Navigation and account behavior

- The account control shows the real session username and server-derived role, with initials rather than stored profile data in browser storage.
- Its disclosure contains the existing My Profile, theme, and secure logout actions. No nonexistent settings route was invented.
- Admin-only links (Analytics, Admin Dashboard, Student IDs, Audit Logs, Storage) are rendered only after the existing server-side role check. Direct admin access by an ordinary user was also tested and denied by the server.
- The active link is server-rendered for routes. The Storage link receives hash-aware active state for `admin_dashboard.php#storage-maintenance`, while Admin Dashboard remains current on the unfragmented route.
- Desktop collapse persists only the non-sensitive width preference in `localStorage`; icon links retain readable labels, tooltips, and accessible names.
- At 900px and below, the sidebar becomes an off-canvas drawer with an explicit close button, backdrop dismissal, Escape support, focus trapping/restoration, scroll locking, and inert background content.

## Validation

| Verification | Result |
| --- | --- |
| Isolated Edge/Playwright browser suite | **57 passed, 0 failed** |
| PHP syntax: shared navigation, sidebar, site nav, Admin Dashboard, Dashboard, Profile | Passed |
| JavaScript syntax: sidebar and Summarize workspace scripts | Passed |
| Diff whitespace check for sidebar-related files | Passed |
| Isolated database integrity | Original core rows preserved; no live activity observed |
| QA teardown | Owned Apache/NLP processes stopped; isolated schemas/user removed; private config removed |

The browser suite covers actual account information and role visibility, profile-menu keyboard controls, theme persistence, collapse persistence, light/dark layouts at 360/390/768/1024/1280/1440px, mobile focus management, public-route exclusion, every sidebar destination, Storage deep links, server-side admin denial, existing text/Nutshell generation, secure logout, and browser-script errors.

QA used local Apache/PHP, Microsoft Edge/Playwright, the existing real Python NLP worker, and a restricted synthetic MySQL database. The QA database account was denied application-database access; successful generation responses were not mocked. [Integrity evidence](qa/sidebar-redesign/integrity.json) records the final isolation result.

## Screenshots

| View | Before | After |
| --- | --- | --- |
| Administrator desktop | [Before](qa/sidebar-redesign/before-admin-desktop.png) | [Expanded sidebar](qa/sidebar-redesign/after-expanded-desktop.png) |
| Collapsed desktop | — | [Collapsed sidebar](qa/sidebar-redesign/after-collapsed-desktop.png) |
| Mobile | [Before](qa/sidebar-redesign/before-mobile.png) | [Workspace](qa/sidebar-redesign/after-mobile-workspace.png), [drawer](qa/sidebar-redesign/after-mobile-drawer.png) |
| Dark theme | [Before](qa/sidebar-redesign/before-dark.png) | [After](qa/sidebar-redesign/after-dark.png) |

Additional proof captures: [collapsed account menu](qa/sidebar-redesign/after-collapsed-account.png), [mobile account menu](qa/sidebar-redesign/after-mobile-account.png), [real text result](qa/sidebar-redesign/real-text-result.png), and [real Nutshell result](qa/sidebar-redesign/real-nutshell.png).

## Remaining verification limits

No unresolved functional or layout issue was found in the tested paths. Browser automation ran in Edge/Chromium; Safari, Firefox, and manual screen-reader sessions were not run. These checks support WCAG-oriented implementation but are not a formal accessibility certification. Deployment was not requested; this is a verified local implementation.

Reproduction details and individual assertions are in [the sidebar QA README](qa/sidebar-redesign/README.md) and [browser results](qa/sidebar-redesign/browser.json).
