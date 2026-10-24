# Editable manuscript build

`build_manuscript.py` assembles the Markdown manuscript into an editable Word document. It uses the measured reference page box (515.88 × 728.52 points), Courier New 12-point prose, justified paragraphs, and exact 20.4-point body line spacing. Margins are the documented adaptations in `REFERENCE_FORMAT_ANALYSIS.md`; they are not a claim about the reference's unavailable Word settings. Tables are real Word tables with repeated header rows. Figures are embedded images with editable captions and alternative text. Captions contain Word SEQ fields, and the contents and lists contain updateable TOC fields.

Run from the repository root in PowerShell:

```powershell
& '.venv311/Scripts/python.exe' 'docs/capstone-manuscript/build/build_manuscript.py' --pdf
```

The command writes `EDITABLE_MANUSCRIPT.docx` and `EDITABLE_MANUSCRIPT.pdf` beneath `docs/capstone-manuscript/`. The PDF is a layout preview of a research draft. LibreOffice updates the contents, figure list, table list, and page fields in both deliverables using a hidden isolated instance. Its profile is confined to `build/libreoffice-profile/`. This does not change system-wide LibreOffice settings. Original manuscript Markdown remains the source of truth; changes made only in Word are not imported back automatically.

Dependencies available in this workspace: `.venv311` Python with `python-docx`; LibreOffice at `C:/Program Files/LibreOffice/program/soffice.exe`, including its bundled Python and PyUNO. SVG-only assets also require the locally installed Playwright module (override `MANUSCRIPT_PLAYWRIGHT_MODULE` if necessary). No download or online conversion service is used.

## Input conventions

- Default order: `PRELIMINARY_PAGES.md`, Chapters 1–4, `REFERENCES.md`, optional `APPENDICES_A_E.md`, Appendix F, Appendix G, `EVALUATION_INSTRUMENTS.md`, optional `ADDITIONAL_APPENDICES.md`. A JSON list supplied through `--manifest` overrides this order. Paths are relative to `docs/capstone-manuscript/`.
- Use UTF-8 Markdown. A `#` heading starts a new page; in preliminary pages, `##` also starts a new page. First-page front-matter numbering is suppressed; following front pages use lower-case Roman numerals. Chapter I resets numbering to Arabic 1. The front matter's first page should therefore be the unnumbered cover. An inner title page, if required, follows as a separate heading.
- Use `# CHAPTER I — THE PROBLEM AND ITS BACKGROUND`; the chapter label and title become separate centered lines. `##`, `###`, and `####` preserve editable heading levels. The contents lists the first three heading levels.
- Put `<!-- TOC -->`, `<!-- LIST_OF_TABLES -->`, and `<!-- LIST_OF_FIGURES -->` immediately after their corresponding headings in preliminary pages. If omitted, the builder inserts them before `Abstract`, or after the remaining front matter if no abstract heading exists.
- Use standard Markdown images with paths relative to the source file: `![Descriptive alternative text](screenshots/home.png)`. Prefer PNG files beside any editable SVG diagram. When only SVG exists, the builder creates a PNG copy beneath `build/rasterized/` using the browser renderer.
- Put `Figure 1. Descriptive caption.` below its image and `Table 1. Descriptive caption.` above its pipe table. Explicit numbers are preserved as SEQ reset fields. Numbering gaps and duplicates appear in `BUILD_REPORT.json`. If no explicit caption exists, a numbered caption is generated from image alternative text or the current table heading and marked as generated in the report. Place `<!-- NO_TABLE_CAPTION -->` before an intentionally unnumbered table.
- All pipe tables become editable Word tables. A data dictionary whose first heading contains `Field` receives widths suited to field, description, type, and constraint columns. Long tables repeat their header row. Table text uses 9 points (8 points for tables exceeding four columns) so it remains legible at the measured narrow page width.
- `<!-- INCLUDE relative/file.md -->` incorporates a Markdown fragment in place. Include paths must remain beneath `docs/`. `<!-- PAGEBREAK -->` inserts an explicit page break. Other HTML comments are excluded from the manuscript.
- Put `<!-- CENTER -->` before cover/title-page text and `<!-- END_CENTER -->` after it to center researchers' names, institution, and other title-page lines. Caption labels require a period or colon after the number; explanatory paragraphs such as "Figure 1 illustrates..." remain body prose.
- Hyperlinks, bold text, inline code, numbered instructions, bullet lists, and fenced code blocks are supported. Nested lists, raw HTML layouts, and advanced Markdown extensions are intentionally outside this small document converter; inspect the PDF before release.

## Build evidence and limitations

`BUILD_REPORT.json` lists exact source files, headings, caption numbers, embedded images, layout settings, and warnings. `EXPORT_REPORT.json` records the PDF page count and renderer. A build fails on missing mandatory sources or missing images, so it cannot silently claim complete material. `--allow-missing` is available only for explicitly partial draft work.

The document is a draft until researcher placeholders and pending evaluations are resolved. Browser screenshots retain their original evidence limits. Exact institutional requirements, signatures, approvals, and research results are not produced by this tool. The generated Word file remains editable, and its fields can be refreshed in Word with Select All / Update Fields. Layout can vary slightly across Word/LibreOffice versions and installed font rendering.


## Preserve the finalized source

Use `build_manuscript.py --pdf` for ordinary regeneration. Scripts under `system-audit/evidence/` and the source-finalization helpers were used to prepare this audit snapshot; they are not required for rebuilding the final document. Re-running scaffolding scripts without reviewing their inputs can replace editorial corrections or duplicate source sections. Keep the finalized Markdown under version control and rerun caption/link validation deliberately after structural edits.
