strictly: do not use api for UI and main summarizer logic/engine
# NON-NEGOTIABLE GLOBAL UI RULE

This rule applies to **EVERY implementation, feature, backend update, frontend update, API integration, RAG feature, analytics feature, security feature, new component, new page section, new state, and future system modification**.

Whenever an implementation has any visible effect on the application, you MUST inspect the existing UI before writing or modifying frontend code.

You are NOT allowed to design a new visual style independently.

The existing LIGHT interface is the primary design reference and source of truth.

---

# MANDATORY UI CHECK FOR EVERY UPDATE

Before implementing ANY feature, determine:

```text
Does this update affect anything the user can see or interact with?
```

Examples include:

- new output;
- new metadata;
- new buttons;
- new controls;
- new states;
- new sections;
- new settings;
- validation messages;
- loading states;
- error states;
- success states;
- empty states;
- evidence;
- analytics;
- account functionality;
- authentication;
- upload behavior;
- summary modes;
- new backend-generated information.

If YES:

```text
STOP
    ↓
inspect existing UI
    ↓
identify existing design pattern
    ↓
reuse or adapt that pattern
    ↓
implement feature
```

Never implement the visible portion first and attempt to make it match later.

---

# EXISTING UI IS THE DESIGN SOURCE OF TRUTH

Before creating any new UI element, inspect the relevant existing pages and shared styles.

At minimum check:

- the page receiving the update;
- landing page;
- global stylesheet;
- shared UI styles;
- header/navigation;
- footer;
- buttons;
- inputs;
- cards/surfaces;
- typography;
- spacing;
- backgrounds;
- borders;
- radii;
- shadows;
- responsive behavior;
- hover states;
- focus states;
- animation behavior.

The implementation must answer:

```text
What existing LIGHT component or visual pattern is closest to this requirement?
```

Reuse that pattern wherever possible.

---

# STRICT RULE

NEVER create UI based solely on:

- generic web design knowledge;
- personal design preference;
- popular SaaS patterns;
- AI-generated design conventions;
- another application's appearance;
- framework defaults;
- arbitrary component libraries.

Instead:

```text
NEW FEATURE
    ↓
EXISTING LIGHT UI
    ↓
MATCH
    ↓
ADAPT
    ↓
IMPLEMENT
```

---

# UI AUDIT REQUIRED BEFORE VISIBLE CHANGES

For every update with frontend implications, briefly inspect and identify:

```text
REFERENCE PAGE:
<existing page being used as visual reference>

REFERENCE FILES:
<actual CSS/PHP/JS files inspected>

EXISTING PATTERN:
<button/card/input/text/section/etc. being reused>

ADAPTATION:
<how the new feature will fit that pattern>
```

Do not invent filenames or classes.

Use actual repository evidence.

---

# DO NOT INTRODUCE A PARALLEL DESIGN SYSTEM

Never create a second design language for a new feature.

For example:

```text
existing LIGHT:
soft purple surfaces
specific button shape
specific typography
specific spacing
```

A new RAG Evidence component must NOT suddenly introduce:

```text
blue dashboard cards
different radius
different font
different shadows
different button language
```

Instead it must visually behave as though it has always belonged to LIGHT.

---

# VISUAL CONSISTENCY OVERRIDES FEATURE NOVELTY

A technically new feature does NOT justify a new visual language.

For example:

```text
RAG
Evidence
Explain This Summary
Analytics
Security alerts
New profile
New length mode
Account settings
Document history
```

must all adapt to the existing UI.

The user should never feel:

```text
"This looks like a different website."
```

---

# REUSE BEFORE CREATING

Before adding new CSS, search for an existing equivalent.

Order of preference:

```text
1. Reuse existing component/class unchanged
2. Reuse existing component with a modifier
3. Extend existing pattern carefully
4. Create a new component using existing design tokens
5. Introduce a completely new visual pattern ONLY if no existing pattern can represent the requirement
```

Option 5 requires a concrete justification.

---

# DESIGN TOKEN PRESERVATION

Never hard-code arbitrary values when equivalent system values already exist.

Reuse existing:

- colors;
- font families;
- font weights;
- font sizes;
- spacing;
- container widths;
- radii;
- shadows;
- borders;
- transitions;
- breakpoints.

Bad:

```css
border-radius: 18px;
background: #7654ff;
font-family: Inter;
```

when LIGHT already has established values.

Correct:

```css
use existing LIGHT token / existing class / existing shared style
```

---

# TYPOGRAPHY CONSISTENCY

Every new piece of text must follow the existing type hierarchy.

Check:

- display typography;
- heading typography;
- body typography;
- label typography;
- muted text;
- metadata;
- button text.

Do not introduce a new font merely because a new feature was added.

---

# BUTTON CONSISTENCY

Never create a visually unique button without necessity.

New actions must use an existing category:

```text
primary
secondary
tertiary
text
danger
```

if such patterns exist.

Examples:

```text
View Evidence
Explain This Summary
Copy
Download
Retry
```

must reuse established LIGHT button behavior.

---

# FORM CONTROL CONSISTENCY

Any new:

- select;
- toggle;
- checkbox;
- input;
- textarea;
- dropdown;
- search field;

must visually match the existing summarizer controls.

Do not introduce browser-default or framework-default controls.

---

# STATE CONSISTENCY

New system states must also follow existing aesthetics.

This includes:

```text
loading
success
warning
error
empty
disabled
processing
fallback
```

A backend change is NOT exempt merely because the state is generated dynamically.

---

# BACKEND FEATURES MUST INCLUDE UI IMPACT REVIEW

Even when the requested change is primarily backend work, explicitly ask:

```text
Does this backend change create new information or states that the frontend must represent?
```

Examples:

```text
RAG evidence
retrieval fallback
processing stages
new profile
new length behavior
validation failure
analytics metric
security status
```

If yes, inspect the UI and design the presentation using existing LIGHT patterns.

Never dump raw backend output directly into the page.

---

# STRICT RAG EXAMPLE

Backend adds:

```json
{
  "evidence": [],
  "coverage": [],
  "retrieval": {}
}
```

Incorrect frontend response:

```text
Create three new generic cards using modern dashboard styling.
```

Correct process:

```text
1. Inspect current result.php.
2. Inspect Results-page CSS.
3. Inspect landing-page section styling.
4. Identify existing typography and surface patterns.
5. Determine whether evidence fits an existing expandable/list pattern.
6. Adapt that existing pattern.
7. Implement using existing tokens.
```

---

# DO NOT COPY THE LANDING PAGE LITERALLY

"Adapt to the existing UI" does not mean copying landing-page sections directly.

The landing page and Results page have different purposes.

Instead extract the existing:

- visual language;
- typography;
- colors;
- spacing;
- interaction style;
- brand personality.

Then adapt them to the new functional context.

Example:

```text
Landing page:
marketing-oriented large typography

Results page:
same typography family and hierarchy
but optimized for long-form reading
```

---

# GLOBAL COMPONENT CONSISTENCY

If an update affects something shared across multiple pages, do NOT patch individual pages independently.

Examples:

- footer;
- navigation;
- buttons;
- notifications;
- modal styles;
- typography;
- upload controls.

Find the shared implementation and update it globally where appropriate.

Avoid:

```text
page1-footer.css
page2-footer-fix.css
page3-footer-new.css
```

when the system should use one shared footer style.

---

# NO GENERIC AI UI

Reject implementations that introduce common AI-generated design patterns without precedent in LIGHT.

Avoid:

- excessive cards;
- excessive gradients;
- random glassmorphism;
- purple glow everywhere;
- floating blobs;
- excessive pills;
- unnecessary icons;
- excessive shadows;
- oversized hero layouts;
- giant whitespace;
- dashboard grids without purpose;
- arbitrary animations;
- excessive border-radius;
- "AI sparkle" icons;
- generic SaaS components.

Use the existing LIGHT identity instead.

---

# RESPONSIVE CONSISTENCY

Every UI modification must be checked against existing responsive behavior.

Do not solve desktop by breaking mobile.

Verify:

```text
1920×1200
1366×768
tablet
mobile
```

Use existing breakpoints whenever possible.

Do not introduce unrelated breakpoints unless necessary.

---

# INTERACTION CONSISTENCY

New interactions must behave like existing LIGHT interactions.

Match existing:

- hover speed;
- transition duration;
- focus behavior;
- pressed states;
- accordion movement;
- button feedback.

Do not introduce:

- excessive spring animations;
- bouncing;
- unrelated parallax;
- random animation libraries.

---

# ACCESSIBILITY MUST SURVIVE THE VISUAL ADAPTATION

Matching the current UI does not justify copying accessibility defects.

Every new component must still use:

- semantic HTML;
- buttons for actions;
- labels;
- keyboard support;
- focus indicators;
- adequate contrast;
- ARIA where appropriate.

---

# REQUIRED UI CHANGE REPORT

Whenever frontend code is changed, include this section in the implementation report:

```text
UI CONSISTENCY CHECK

REFERENCE:
<existing page/component used>

FILES INSPECTED:
<actual files>

PATTERN REUSED:
<existing visual pattern>

NEW UI:
<what was added>

ADAPTATION:
<how it was adapted to LIGHT>

NEW DESIGN TOKENS:
None
```

If new tokens were genuinely required:

```text
NEW DESIGN TOKENS:
<token>

JUSTIFICATION:
<why existing tokens could not represent the requirement>
```

---

# VISUAL REGRESSION CHECK

After every visible update, verify:

```text
[ ] Existing UI identity remains recognizable
[ ] Colors match
[ ] Typography matches
[ ] Buttons match
[ ] Inputs match
[ ] Borders/radii match
[ ] Spacing matches
[ ] Header remains consistent
[ ] Footer remains consistent
[ ] Desktop works
[ ] Mobile works
[ ] New feature does not look externally attached
```

Do not declare the update complete until this check passes.

---

# IMPLEMENTATION DECISION RULE

For every UI decision, ask:

```text
"How would the existing LIGHT interface express this?"
```

Do NOT ask:

```text
"What modern UI should I build for this feature?"
```

That distinction is mandatory.

---

# FINAL NON-NEGOTIABLE RULE

Every future feature must behave as if it was designed as part of LIGHT from the beginning.

The coding agent must continuously reference the real existing interface before creating visible functionality.

Do not make a feature first and style it later.

Do not create isolated feature-specific visual languages.

Do not assume what LIGHT looks like from memory.

Inspect the current repository.

Reuse the actual UI.

Adapt rather than replace.

Preserve brand identity.

For EVERY system update:

```text
AUDIT EXISTING UI
        ↓
FIND MATCHING PATTERN
        ↓
REUSE / ADAPT
        ↓
IMPLEMENT
        ↓
COMPARE WITH EXISTING UI
        ↓
RESPONSIVE CHECK
        ↓
VISUAL REGRESSION CHECK
```

If the new implementation visually appears to belong to a different application, the implementation is NOT complete.