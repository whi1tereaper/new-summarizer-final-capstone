# Admin Actions verification

Verified on 27 October 2026 in an isolated PHP 8.3/MySQL fixture environment using Microsoft Edge/Chromium.

**28 checks passed; 0 failed.** The six visual/layout combinations cover light and dark themes at 1440px desktop, 390px mobile and 320px mobile, with touch/coarse-pointer emulation on mobile.

Verified behavior:

- Active ordinary accounts display Deactivate and Delete beside a decorative divider. Inactive accounts display Delete only. The current administrator and other administrator rows retain their existing unavailable-action states.
- Actions stay on one line, use transparent backgrounds without filled borders/shadows, retain neutral/destructive token colors and decorative outline icons, and provide targets at least 44px wide and high.
- Hover remains transparent; keyboard focus is visible. Shared disabled/busy guards prevent activation.
- Native confirmation cancellation through Enter sends no POST. Accepted confirmations retain the correct action URL, CSRF field and target account identifier.
- `form.requestSubmit()` also confirms, including a fixture username containing quotes and HTML-like text.
- Real isolated backend requests reject invalid CSRF, GET mutations and ordinary/anonymous access. Fixture account records and active/inactive status remain intact.
- The page fits all tested widths; the user table scrolls internally to expose Actions on mobile.

Confirmed browser submissions were intercepted with HTTP 204 before reaching the backend. No deletion or deactivation was executed. All accounts were synthetic. Production integrity checks found preserved original core rows and no observed live activity; the owned Apache process, disposable schemas and restricted database user were removed afterward. External Google font stylesheets were stubbed so the local/system font stack was exercised.

## Evidence

- [Check results and geometry](browser-results.json)
- [Production integrity](integrity.json)
- [Desktop light](actions-light-1440.png)
- [Desktop dark](actions-dark-1440.png)
- [Mobile light — Actions column](actions-light-390.png)
- [Mobile dark — Actions column](actions-dark-390.png)
- [Keyboard focus, light](actions-focus-light-1440.png)
- [Keyboard focus, dark](actions-focus-dark-1440.png)

## Reproduce

```powershell
.\.venv311\Scripts\python.exe scripts/qa/admin_actions_qa.py all
```

The runner reuses the restricted design-system fixture harness and always attempts cleanup. Individual `setup`, `browser` and `stop` actions support diagnosis. Credentials and sessions stay in a private `LOCALAPPDATA` folder.
