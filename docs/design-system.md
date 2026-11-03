# LIGHT design system

All rendered pages load `app/public/partials/design-system-head.php` before page styles. The blocking theme script sets the document theme before styles can paint; component behavior loads with `defer`. Include this partial in the head of new PHP pages, immediately after the charset and viewport metadata.

`app/public/assets/css/design-tokens.css` is the source of visual values. Light values live on `:root`; dark values override only `:root[data-theme="dark"]`. Do not define another palette on a body, page class, or component. Page styles may define layout variables and aliases that reference these tokens.

| Purpose | Tokens |
| --- | --- |
| Surfaces | `--color-bg`, `--color-bg-secondary`, `--color-bg-tertiary`, `--color-surface` |
| Content | `--color-text`, `--color-text-secondary`, `--color-text-muted` |
| Brand | `--color-accent`, `--color-accent-hover`, `--color-on-accent`, `--color-accent-soft` |
| Status | `--color-success`, `--color-warning`, `--color-danger`, `--color-info`, plus their `-soft`, `-border`, and `--color-on-*` pairs |
| Typography | `--font-body`, `--font-heading`, `--font-ui`, `--font-size-*`, `--fs-heading-*`, `--fs-display-*` |
| Spacing and shape | `--space-*`, `--radius-control`, `--radius-card`, `--border-width` |
| Controls | `--control-height`, `--control-padding-*`, `--button-*`, `--input-*` |
| Feedback and elevation | `--color-focus`, `--focus-ring-*`, `--shadow-*`, `--color-overlay` |
| Charts | `--color-chart-1` through `--color-chart-6`, `--color-chart-grid`, `--color-chart-label` |

Use paired foreground and background tokens together. For example, filled primary actions use `--button-primary-bg` and `--button-primary-text`; destructive actions use the corresponding danger pair. Avoid fixed white text in dark mode. Print colors intentionally use `--color-print-bg` and `--color-print-text`.

## Components

`components.css` owns button appearance, dimensions, and states. Page CSS owns placement, width, and layout. Keep existing feature-specific classes and IDs for JavaScript hooks, and compose them with the shared classes:

```html
<button class="btn btn--primary" type="submit">Save changes</button>
<button class="btn btn--secondary" type="button">Cancel</button>
<a class="btn btn--outline" href="history.php">View history</a>
<button class="btn btn--ghost" type="button">More options</button>
<button class="btn btn--destructive" type="submit">Delete</button>
```

Use `btn--sm`, `btn--lg`, `btn--icon`, or `btn--block` for explicit sizes and layout. Icon-only buttons need an accessible name. Compact buttons retain a 44px minimum target on mobile and coarse pointers. Navigation links and rating inputs retain their own semantics while inheriting the same tokens.

Native `disabled` and `aria-disabled="true"` display the shared disabled state. For asynchronous actions, set `aria-busy="true"` and disable the control until completion; the shared spinner preserves its dimensions. `components.js` prevents activation of disabled or busy `.btn` and `.text-action` controls, including anchors activated with a keyboard. Always clear the busy state in the feature's completion/error handler.

The former `global-button-effects.css` asset is a compatibility stub. Do not add another hover or animation system there. Focus rings, reduced motion, status colors, and native form-control themes use the same global tokens.

For compact administration table controls, use `.table-actions` with `.text-action` and `.text-action--danger`. These keep a 44px target with transparent backgrounds, neutral or danger text, outline icons, and shared keyboard focus. Put an `aria-hidden="true"` `.table-actions__divider` between available actions only. Keep row actions on one line within the table's responsive scroll container; put confirmation on each form's submit event so keyboard submission is also covered. Preserve each POST destination and its CSRF and record-ID fields.

## Theme preference

The theme engine in `assets/js/theme-toggle.js` owns theme state. It preserves the existing `light-results-theme` preference key. With no explicit preference, it follows the device setting, including changes while the page is open. Explicit choices persist across pages and synchronize across tabs. Storage failures fall back to an in-memory preference.

```js
LightTheme.get();             // effective "light" or "dark"
LightTheme.getPreference();   // "light", "dark", or "system"
LightTheme.set('dark');
LightTheme.toggle();
LightTheme.reset();           // follow the device again
```

Theme buttons use `data-theme-toggle`. A labeled select with `data-theme-preference` accepts `system`, `light`, and `dark`; Profile includes this preference. The engine keeps controls and their accessible state synchronized. Charts redraw from computed tokens on the existing `themechange` event. Feature scripts must not write theme attributes or store separate theme preferences.

## Validation

The design-system QA scripts under `scripts/qa/` exercise the major pages in both themes at desktop and mobile sizes, using synthetic records in a restricted disposable database. They cover preference persistence, device changes, cross-tab updates, storage failure, live token changes, button states and contrast, navigation, and existing workspace/result interactions. Screenshots and the latest report are under `docs/qa/design-system/`.
