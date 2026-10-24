# Evidence Register

Audit date: 17 October 2026 (Asia/Manila). This register distinguishes application implementation, observed database definitions, isolated execution, restricted live requests, pre-existing engineering material, manuscript reference analysis and proposed research content. Evidence strength attaches to a specific claim, not to an entire file merely because it exists.

## Evidence classes and counting rules

| Class | Meaning | Does not establish |
|---|---|---|
| SOURCE-SUPPORTED | Current source/configuration/schema scripts were read or indexed | Successful execution, security correctness or actual usage |
| METADATA-VERIFIED | Actual database definitions were observed with read-only metadata queries | Stored application values, users, evaluation participants or scores |
| ISOLATED-VERIFIED | The named function/controller/template behavior executed with synthetic data/source copies | Full production integration, authentication or persistence |
| LIVE-LIMITED | An inspected no-bootstrap handler responded at localhost | General application/database/worker readiness |
| HISTORICAL ARTIFACT | A pre-existing repository report/corpus/baseline | New audit results, an approved study protocol or completed survey |
| REFERENCE-ONLY | Supplied manuscript structure/visual conventions were analyzed | Facts about LIGHT or permission to copy reference personal/institutional content |
| PROPOSED/PENDING | Draft instrument, procedure, academic context or recommendation | Approved research, observed history or collected results |

The authoritative Python confirmation contains 600 testcases: 595 passed, one failed and four skipped; 62 passed subtests are reported separately. The initial run is retained for provenance but does not supply the final pass/fail total. The browser total is 46 screenshots across 23 states and two viewports, 18 local interactions, and 19 isolated guard cases. Three live no-bootstrap checks and three deliberately failing backend defect checks are separate groups. Syntax checks cover 135 PHP plus 15 JavaScript files, 150 total. These denominators must not be pooled into an artificial system completion/pass percentage.

## A. Audit reports and capability inventories

| Evidence ID | Artifact | Use and limit |
|---|---|---|
| E-A01 | [COMPLETE_SYSTEM_AUDIT.md](COMPLETE_SYSTEM_AUDIT.md) | Consolidated findings, scope and readiness; derivative of the raw evidence below |
| E-A02 | [BACKEND_AND_DATABASE_AUDIT.md](BACKEND_AND_DATABASE_AUDIT.md) | PHP/service/store/operation analysis and metadata findings; most complete workflows remain source-supported |
| E-A03 | [SECURITY_AND_RELIABILITY_AUDIT.md](SECURITY_AND_RELIABILITY_AUDIT.md) | Backend priorities, three controlled defect reproductions and untested boundaries |
| E-A04 | [PYTHON_ENGINE_AUDIT.md](PYTHON_ENGINE_AUDIT.md) | Active legacy versus optional paths, extraction, services/models and regression/smoke interpretation |
| E-A05 | [UI_UX_AUDIT.md](UI_UX_AUDIT.md) | Frontend coverage, synthetic render/interaction scope and accessibility/navigation findings |
| E-A06 | [FUNCTIONAL_TEST_RESULTS.md](FUNCTIONAL_TEST_RESULTS.md) | Procedures, expected/actual outcomes and functional limits; final browser totals include baseline and supplement |
| E-A07 | [SYSTEM_WORKFLOWS.md](SYSTEM_WORKFLOWS.md) | Cross-layer trace; no implied full integration pass |
| E-A08 | [KNOWN_ISSUES.md](KNOWN_ISSUES.md) | Consolidated 30 findings/limitations, priority and proposed verification criteria |
| E-A09 | [ui-feature-notes.json](ui-feature-notes.json) | 51 frontend/API-companion rows with actors, actions, steps, inputs/outputs, source paths and limits; 14 isolated-UI verified, 37 source-supported at the frontend checkpoint |
| E-A10 | [backend-feature-notes.json](evidence/backend-feature-notes.json) | 30 backend capability groups plus per-source methods/lines/hashes and review coverage; overlaps frontend rows |
| E-A11 | [ui-route-inventory.json](ui-route-inventory.json) | All 41 app/public PHP entry points, 13 partials, 16 JS assets and 19 CSS files, with source hash, lines, roles, forms/fields and dependencies |
| E-A12 | [FEATURE_INVENTORY.md](FEATURE_INVENTORY.md), [CSV](FEATURE_INVENTORY.csv), [feature-inventory.json](feature-inventory.json) | Reconciled 69 capabilities: 5 Verified working, 18 Partially working, 46 Implemented but not verified; other classes zero. Verified boundaries are the mobile menu, tested theme toggle, copy, TXT export and static health |
| E-A13 | [FEATURE_DISCUSSION.md](../capstone-manuscript/tables/FEATURE_DISCUSSION.md) | Chapter III discussion of the same 69 capabilities, linked to roles, source and evidence; no additional test execution |

The coordinating FEATURE_INVENTORY Markdown/CSV/JSON reconciles overlapping contribution rows and defines the reported total of 69 capabilities. The 51 and 30 contribution counts are not additive. A feature's source existence, fixture behavior, live integration and research evaluation remain separate dimensions. Zero capabilities in the Not working class does not negate defects within partially working grouped capabilities.

## B. Database, runtime and controlled backend checks

| Evidence ID | Artifact | Use and limit |
|---|---|---|
| E-B01 | [live-schema.json](evidence/live-schema.json) | Direct information_schema/SHOW metadata: 31 tables, 312 columns, 35 foreign keys; engine/version/keys/checks/DDL. No credentials or application rows |
| E-B02 | [inspect_schema.php](evidence/inspect_schema.php) | Reproducible metadata-inspection harness that avoids normal application bootstrap; its source defines queried metadata |
| E-B03 | [runtime-config-safe.json](evidence/runtime-config-safe.json) | Whitelisted nonsecret configuration and PHP 8.1.25 extensions; absence of flags is explicit; not the UI renderer's PHP version |
| E-B04 | [inspect_runtime_config.php](evidence/inspect_runtime_config.php) | Safe configuration whitelist procedure; does not publish the .env contents |
| E-B05 | [backend-isolated-checks.json](evidence/backend-isolated-checks.json) | Two synthetic document-ownership denials failed with 200/source disclosure; one synthetic suffix-audio range assertion failed with 200/full payload |
| E-B06 | [backend_isolated_checks.php](evidence/backend_isolated_checks.php) | Real handler/service injection with in-memory SQLite and synthetic audio; no production connection/bootstrap; fixture was removed after audio check |
| E-B07 | [backend-documentation-validation.json](evidence/backend-documentation-validation.json) | Checks dictionary coverage, actual FK edges, maintenance-manual section structure and feature references; documentation consistency, not runtime correctness |
| E-B08 | [DATA_DICTIONARY.md](../capstone-manuscript/tables/DATA_DICTIONARY.md), [CSV](../capstone-manuscript/tables/DATA_DICTIONARY.csv) | Generated actual column/type/constraint documentation, all 31 tables/312 field rows |
| E-B09 | [build_schema_artifacts.py](evidence/build_schema_artifacts.py), [build_backend_inventory.py](evidence/build_backend_inventory.py) | Reproducible derivative-artifact generation; not migrations or application implementation |

Primary checked-in schema inputs are `database/schema.sql`, `database/analytics_schema.sql` and every SQL migration in `database/migrations/001_normalize_summaries.sql` through `016_summary_error_contract.sql`. Their definitions were compared with E-B01; checked-in SQL is not automatically identical to the configured database. The password_resets used_at mismatch is the documented example. `schema_migrations` metadata was observed without executing migrate --status, which can itself mutate tracking state.

## C. Python source, test executions and probes

| Evidence ID | Artifact | Use and limit |
|---|---|---|
| E-C01 | [python-source-map.json](evidence/python-source-map.json) | AST/import/definition index for 108 Python files, including archived baseline material. 107 UTF-8 parses pass; UTF-16 summarizer_orig.py is archival and separately interpretable after decoding |
| E-C02 | [prepare_isolated_audit.py](evidence/prepare_isolated_audit.py) | Source-copy and AST-map procedure; excludes real .env and copies selected source/document/fixture formats |
| E-C03 | [isolated-project/](evidence/isolated-project/) | Audit copy of app, Python, scripts/tools, schema and selected fixtures/config examples; not a production deployment or independent project version. Audit temporary storage is local to the copy |
| E-C04 | [python-tests-confirmation.log](evidence/python-tests-confirmation.log), [XML](evidence/python-tests-confirmation.xml) | Authoritative confirmation: 595 passed, one failed, four skipped, 62 passed subtests in the isolated environment. Includes the v2 definition-retention assertion and stack trace |
| E-C05 | [python-test-case-register.csv](evidence/python-test-case-register.csv), [test-counts.json](evidence/test-counts.json) | Machine-readable per-case register and final counts; no actual participant records |
| E-C06 | [python-tests.log](evidence/python-tests.log), [XML](evidence/python-tests.xml) | Initial restricted-environment attempt with temporary-file permission issues. Retained to explain the rerun; not the final application defect count |
| E-C07 | [isolated_smoke.py](evidence/isolated_smoke.py), [isolated-smoke.log](evidence/isolated-smoke.log), [isolated-smoke-results.json](evidence/isolated-smoke-results.json) | Synthetic PDF/source/Q&A/Nutshell probe. Read raw payloads: T-NUTSHELL PASS checks truthy response but actual status is insufficient_content with empty text; T-PDF-PAGE is inconclusive after readability rejection |
| E-C08 | [smoke_followup.py](evidence/smoke_followup.py), [smoke-followup.log](evidence/smoke-followup.log), [isolated-smoke-followup.json](evidence/isolated-smoke-followup.json) | Cleaning variants substantiate valid-prose loss involving register; NIST checked-in source fixture returns a nonempty legacy summary. These are function examples, not saved website summaries or research-quality scores |
| E-C09 | [synthetic-blank-first-page.pdf](evidence/synthetic-blank-first-page.pdf) | Two-page synthetic extraction probe with first blank page; failure occurred before intended page-reference assertion. Not a user document |

The fixtures `python-engine/tests/fixtures/evaluation_validation_documents.json`, `evaluation_challenges.json`, and `selection_performance_baseline.json` are primary checked-in test inputs. The NIST example is selected from the first of those files. Their material and synthetic probes are not recruited participants or a final evaluation corpus.

Pre-existing `python-engine/evaluation/` files include baseline/current/comparison reports, p0 results/performance/validation artifacts, dated article-pilot legacy/v2/budget-matched reports, and `corpus/heldout_documents.json` / `independent_article_pilot_20261013.json`. The baseline_snapshot source directory is also historical comparison material. These files were identified and indexed; they must retain their own provenance, assumptions and dataset limits. Their presence does not authorize transferring numerical claims into new human-evaluation tables. The audit did not recreate an independent participant study from them.

## D. Browser, static HTTP and screenshot evidence

| Evidence ID | Artifact | Use and limit |
|---|---|---|
| E-D01 | [browser-readonly-results.json](browser-readonly-results.json) | 38 baseline + 8 supplemental captures; 10 baseline + 8 supplemental local actions; 19 isolated guard cases with expectedStatus/passed. Captures include URL, viewport, status, DOM observations and baseline browser events |
| E-D02 | [ui-render-router.php](evidence/ui-render-router.php) | Audit-only loopback renderer: original template includes, bootstrap bypass, synthetic account/PDO rows, mutation/transaction rejection, overridden storage, GET-only routing and restrictive CSP. Not for deployment/authentication testing |
| E-D03 | [capture-ui.cjs](evidence/capture-ui.cjs), [capture-ui-supplement.cjs](evidence/capture-ui-supplement.cjs) | Headless Chrome capture/control procedures; requests restricted to loopback GET, external requests blocked; provenance banner injected into images |
| E-D04 | [screenshots/README.md](../capstone-manuscript/screenshots/README.md) | Complete 46-image register, 23 states, desktop/mobile dimensions, exact capture URLs and synthetic-data conditions |
| E-D05 | [screenshots/](../capstone-manuscript/screenshots/) | PNGs named ui-{state}-{desktop/mobile}.png; anonymous forms, synthetic account/result/admin, empty lists, errors/denials, pending OTP/terms and modal states. Not live authenticated screenshots |
| E-D06 | [synthetic-summary-download.txt](evidence/synthetic-summary-download.txt) | Exact synthetic text exported through the browser's real client TXT control; no real summary exported |
| E-D07 | [live-safe-get-results.json](evidence/live-safe-get-results.json) | Only actual localhost root/about/static-health responses. Redirects not followed; no normal bootstrap request |
| E-D08 | [ui-evidence-validation.json](evidence/ui-evidence-validation.json) | Frontend checkpoint: 89 source hashes unchanged, 46 PNGs, 18 actions, 19 guards, 51 rows, 20 Appendix F captions/no missing images, isolated server stopped. Caption counts refer to this checkpoint before final manuscript renumbering |
| E-D09 | [build-ui-inventory.cjs](evidence/build-ui-inventory.cjs), [write-ui-docs.cjs](evidence/write-ui-docs.cjs) | Frontend index/derivative documentation builders. Generated notes received subsequent documented evidence updates; rerunning a builder is not a substitute for preserving the final records |

Capture origin was `http://127.0.0.1:8765`; the isolated PHP server was stopped afterward. The actual limited live path was `http://localhost/ai-summarizer-123%20-%20quality%20upg/`. The shared application bootstrap was not used in screenshot rendering because of guest writes/purge. Synthetic `.invalid` email, explicitly synthetic summary text and empty counters prevent accidental use of real account/research material. Blocked Google Fonts, telemetry and worker requests are expected isolation effects, not automatically product defects. The missing local favicon is independently source-confirmed.

## E. Source preservation, syntax and tooling

| Evidence ID | Artifact | Use and limit |
|---|---|---|
| E-E01 | [git-status-before.txt](evidence/git-status-before.txt) | Records pre-existing working-tree changes; they must not be attributed to this documentation audit |
| E-E02 | [source-hashes-before.json](evidence/source-hashes-before.json) | Initial content hashes of selected original source/configuration files. It includes file identities/hashes, not secret configuration values. A before snapshot alone does not establish every file's final preservation |
| E-E03 | [syntax-checks.json](evidence/syntax-checks.json) | Per-file parser status/output for 135 PHP and 15 JS files, all passing; no logic/security/coverage claim |
| E-E04 | [ui-route-inventory.json](ui-route-inventory.json), E-A10 and E-D08 | Additional source hashes/review index and the frontend unchanged comparison; use E-E06 for the final 336-file snapshot comparison rather than extrapolate the 89-file checkpoint |
| E-E05 | [tool-dependencies/](evidence/tool-dependencies/) | Audit support packages/tooling only; not evidence that the application requires or provisions these dependencies. Do not count third-party package files as reviewed application features |
| E-E06 | [source-hashes-after.json](evidence/source-hashes-after.json), [source-preservation-check.json](evidence/source-preservation-check.json) | Final comparison: all 336 files in the initial application/configuration/schema/scripts snapshot unchanged; documentation outputs intentionally excluded. This is the defined snapshot scope, not an assertion that every operating-system or database file was hashed |

The metadata inspector's PHP 8.1.25, UI renderer's PHP 8.3.29 and Python confirmation's Python 3.14 are distinct execution contexts. Browser automation used the existing Playwright package and installed headless Chrome. The audit did not download inference models. Tool invocation success and third-party availability do not establish the deployed worker's configured environment.

## F. Diagrams, manuscript structure and reference material

| Evidence ID | Artifact | Use and limit |
|---|---|---|
| E-F01 | [REFERENCE_FORMAT_ANALYSIS.md](../capstone-manuscript/REFERENCE_FORMAT_ANALYSIS.md), [REFERENCE_COMPLIANCE_MATRIX.md](../capstone-manuscript/REFERENCE_COMPLIANCE_MATRIX.md) | AccredX structure/presentation mapping, independent project equivalents and missing research evidence |
| E-F02 | [reference-structure-notes.md](../capstone-manuscript/reference-analysis/reference-structure-notes.md) | Detailed 184-page reference coverage and editorial inconsistencies; format only, not facts about LIGHT |
| E-F03 | [reference-analysis/](../capstone-manuscript/reference-analysis/) | metadata.json, font-layout-metrics.json, reference-extracted-text.txt and page-001.txt–page-184.txt; extracted reference images and rendered contact sheets. Text-sparse pages were visually reviewed; extracted content is not reused as LIGHT evidence |
| E-F04 | [render_reference.py](evidence/render_reference.py) | Reference text/image/contact-sheet extraction tool; does not generate new study data |
| E-F05 | [RESEARCHER_DRAFT_IMAGE_REVIEW.md](../capstone-manuscript/reference-analysis/RESEARCHER_DRAFT_IMAGE_REVIEW.md), researcher-draft-extracted.txt and researcher-draft-media/ | Draft-review artifacts to reconcile supplied prose/images with source. Draft assertions remain distinct from runtime/participant evidence |
| E-F06 | [DIAGRAM_README.md](../capstone-manuscript/diagrams/DIAGRAM_README.md), [DIAGRAM_MODELS.json](../capstone-manuscript/diagrams/DIAGRAM_MODELS.json) | Source/boundary/provenance notes and generation models for original editable diagrams |
| E-F07 | [use-cases.mmd](../capstone-manuscript/diagrams/use-cases.mmd), [dfd-context.mmd](../capstone-manuscript/diagrams/dfd-context.mmd), [dfd-level1.mmd](../capstone-manuscript/diagrams/dfd-level1.mmd), [dfd-level2-auth.mmd](../capstone-manuscript/diagrams/dfd-level2-auth.mmd), [dfd-level2-summary.mmd](../capstone-manuscript/diagrams/dfd-level2-summary.mmd) and corresponding SVGs | Original source-derived actors/flows; not proof that authorization or execution succeeds on every depicted route |
| E-F08 | [ERD_ACTUAL_SCHEMA.mmd](../capstone-manuscript/diagrams/ERD_ACTUAL_SCHEMA.mmd), ERD_CORE / ERD_EVALUATION Mermaid/SVG/PNG, [ERD_NOTES.md](../capstone-manuscript/diagrams/ERD_NOTES.md) | Actual-FK diagrams derived from E-B01; 35 observed edges, no invented relationships |
| E-F09 | [ERD_PRINT_PANELS.md](../capstone-manuscript/diagrams/ERD_PRINT_PANELS.md) and ERD_PANEL_01–12 Mermaid/SVG/PNG files | Readable print groupings of schema relationships; duplicate panel presentation is not an additional FK count |
| E-F10 | [research-paradigm.mmd](../capstone-manuscript/diagrams/research-paradigm.mmd) and SVG | Proposed academic Input–Process–Output framework, clearly distinct from a claim of historical research methodology |
| E-F11 | [APPENDIX_F_USER_MANUAL.md](../capstone-manuscript/APPENDIX_F_USER_MANUAL.md), [APPENDIX_G_MAINTENANCE_MANUAL.md](../capstone-manuscript/APPENDIX_G_MAINTENANCE_MANUAL.md) | Source/capture-derived user and operator documentation, with unsupported flows and recommendations identified |
| E-F12 | [PRELIMINARY_PAGES.md](../capstone-manuscript/PRELIMINARY_PAGES.md), [CHAPTER_1.md](../capstone-manuscript/CHAPTER_1.md), [CHAPTER_2.md](../capstone-manuscript/CHAPTER_2.md), [REFERENCES.md](../capstone-manuscript/REFERENCES.md) | Academic source drafts and independently identified literature; researcher confirmation still governs academic facts/protocol/approvals |
| E-F13 | [CHAPTER_3.md](../capstone-manuscript/CHAPTER_3.md), [CHAPTER_4.md](../capstone-manuscript/CHAPTER_4.md) | Implementation/results synthesis and scoped conclusions; pending human tables contain no invented respondent values |
| E-F14 | [EVALUATION_INSTRUMENTS.md](../capstone-manuscript/EVALUATION_INSTRUMENTS.md), [APPENDICES_A_E.md](../capstone-manuscript/APPENDICES_A_E.md), [ADDITIONAL_APPENDICES.md](../capstone-manuscript/ADDITIONAL_APPENDICES.md), [RESEARCH_INFORMATION_NEEDED.md](../capstone-manuscript/RESEARCH_INFORMATION_NEEDED.md) | Proposed instruments, templates and explicit researcher evidence gaps; not completed approvals, recruitment or evaluation |
| E-F15 | [assemble_documentation.py](evidence/assemble_documentation.py), [write_results_chapters.py](evidence/write_results_chapters.py), [build_diagrams.py](evidence/build_diagrams.py) | Documentation/inventory/chapter/diagram assembly procedures; generated claims remain bounded by their input evidence |
| E-F16 | [build/README.md](../capstone-manuscript/build/README.md), [build_manuscript.py](../capstone-manuscript/build/build_manuscript.py), [update_and_export.py](../capstone-manuscript/build/update_and_export.py), [inspect_pdf.py](../capstone-manuscript/build/inspect_pdf.py) | Editable manuscript and PDF build/review procedures; successful export does not validate research claims |
| E-F17 | [DIAGRAM_RENDER_REPORT.json](../capstone-manuscript/build/DIAGRAM_RENDER_REPORT.json), [MANUSCRIPT_DIAGRAM_RENDER_REPORT.json](../capstone-manuscript/build/MANUSCRIPT_DIAGRAM_RENDER_REPORT.json), [ERD_PANEL_COVERAGE.json](../capstone-manuscript/build/ERD_PANEL_COVERAGE.json), [EXPORT_REPORT.json](../capstone-manuscript/build/EXPORT_REPORT.json), [split_erd.py](../capstone-manuscript/build/split_erd.py) | Diagram/export checks and print-panel generation; consult each report for its exact checkpoint and scope |

The coordinating Chapter III/IV, research instruments, completeness/readiness documents and editable manuscript exports derive from these records. Generated presentation does not upgrade an unsupported claim to verified evidence. Final figure/table numbering and pagination belong to the assembled manuscript; the audit register preserves file-level provenance and checkpoint counts.

## Claim-to-evidence rules for manuscript authors

- Describe a feature as implemented only when its source path is identified; describe it as executed only at the scope of its actual test.
- Use E-B01/E-B08 for table names, types and constraints, rather than assuming a foreign key from a column name or copying a schema image from the reference.
- Use E-C04 for final Python counts and include its failure/skips. Read smoke payloads before interpreting outer PASS labels.
- Caption E-D05 as isolated template rendering with synthetic data. Do not call the synthetic summary an AI output, the fixture account a participant, or empty dashboard zeros actual usage.
- Keep E-F03 reference images/participant materials out of LIGHT screenshots and results. Do not fabricate missing approvals, letters, signatures, respondent counts or scores.
- Treat configured limits, test-suite duration, heuristics and existing pilot reports according to their exact context; none alone establishes user benefit, factual correctness, capacity or production readiness.

No human-survey response set, authenticated live workflow proof, real worker/service readiness measurement, production deployment verification, automated-backup proof or restoration drill was created by this audit. Those remain explicit evidence gaps.
