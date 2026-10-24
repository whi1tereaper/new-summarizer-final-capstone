# Appendix J. Data-Gathering and Supporting Documents

## Status of Research-Event Evidence

Authentic photographs, attendance records, signed permissions, participant information/consent records and completed questionnaires for a LIGHT evaluation were not supplied. This appendix preserves their required places without manufacturing a research event. Screenshots from the software audit show interface or test evidence; they are not photographs of participants or proof that a campus demonstration occurred.

Table 72. Supporting-document register and current evidence status.

| Document or evidence | Current status | Required completion |
|---|---|---|
| Researcher draft and embedded diagrams | Supplied; extracted text and image review retained | Confirm version/date and intended use. |
| April–October 2026 Gantt chart | Supplied planning artifact; completion unverified | Identify whether planned, revised or as-completed; supply activity records. |
| Adviser appointment/decision | Not supplied | Insert authentic institutional record. |
| Permission for data gathering | Not supplied | Insert signed permission/reference and conditions. |
| Required ethics/institutional determination | Not supplied | Record actual determination applicable to the final protocol. |
| Participant information and consent | Not supplied | Use approved documents and protected records; publish only suitable blank/redacted forms. |
| Instrument validation/pilot records | Not supplied | Record actual reviewers, revisions and findings without invented coefficients. |
| Recruitment/attendance/task logs | Not supplied | Retain coded records and actual collection dates. |
| Completed responses and codebook | Not supplied | Preserve protected raw data and reproducible analysis copy. |
| Event photographs | Not supplied | Use authentic permitted images with actual date, context and privacy review. |
| Technical audit and synthetic test artifacts | Available in the audit package | Retain their isolation/configuration limits and distinguish them from participant evidence. |

Table 72 distinguishes supplied planning and technical material from unavailable human-research records. Its status entries should change only when the corresponding authentic document or dataset is obtained.

## Photograph and Activity-Record Template

[Insert authentic image only if supplied and approved for publication.]

Actual activity: [record]. Actual date and location: [record]. Participants shown: [describe only as permitted]. Photographer/source and permission reference: [record]. Associated task/consent/attendance reference: [record].

This placeholder receives no figure number because it contains no actual figure. Do not use a generated image, stock photograph or reconstructed scene as documentary evidence of research participation.

# Appendix K. Researcher Curriculum Vitae

Only the four researcher names below are confirmed by the user's explicit instruction. Academic records, personal details, roles, qualifications and achievements have not been inferred. Each researcher should complete and verify their own entry using the institution's approved format. Include only information necessary for submission; sensitive personal identifiers and contact details should not be added automatically.

## Robert Martin Baltisoto

Full name: Robert Martin Baltisoto.

Degree program and current academic status: [researcher to confirm].

Institution/campus and academic year: [researcher to confirm].

Educational background: [verified institutions, qualifications and dates].

Relevant technical skills: [researcher-supplied accurate account].

Actual contribution to LIGHT summarizer: [specific responsibilities and supporting work].

Relevant projects, training or certifications: [verified entries only].

Professional/contact information permitted for submission: [optional, approved entry].

Verification and date: [researcher confirmation].

<!-- PAGEBREAK -->

## Shielo Mariell Leodones

Full name: Shielo Mariell Leodones.

Degree program and current academic status: [researcher to confirm].

Institution/campus and academic year: [researcher to confirm].

Educational background: [verified institutions, qualifications and dates].

Relevant technical skills: [researcher-supplied accurate account].

Actual contribution to LIGHT summarizer: [specific responsibilities and supporting work].

Relevant projects, training or certifications: [verified entries only].

Professional/contact information permitted for submission: [optional, approved entry].

Verification and date: [researcher confirmation].

<!-- PAGEBREAK -->

## Gieselle Sabbaluca

Full name: Gieselle Sabbaluca.

Degree program and current academic status: [researcher to confirm].

Institution/campus and academic year: [researcher to confirm].

Educational background: [verified institutions, qualifications and dates].

Relevant technical skills: [researcher-supplied accurate account].

Actual contribution to LIGHT summarizer: [specific responsibilities and supporting work].

Relevant projects, training or certifications: [verified entries only].

Professional/contact information permitted for submission: [optional, approved entry].

Verification and date: [researcher confirmation].

<!-- PAGEBREAK -->

## Sofiah Heaven Galman

Full name: Sofiah Heaven Galman.

Degree program and current academic status: [researcher to confirm].

Institution/campus and academic year: [researcher to confirm].

Educational background: [verified institutions, qualifications and dates].

Relevant technical skills: [researcher-supplied accurate account].

Actual contribution to LIGHT summarizer: [specific responsibilities and supporting work].

Relevant projects, training or certifications: [verified entries only].

Professional/contact information permitted for submission: [optional, approved entry].

Verification and date: [researcher confirmation].

# Appendix L. Technical Evidence and Reproduction Guide

## Purpose and Boundaries

The accompanying technical package preserves the basis of implementation claims and known limitations. It is not a substitute for institutional research records. Evidence files should be reviewed with their stated environment, synthetic-data and source-only qualifications. Reproduction of mutating application commands belongs in an isolated authorized environment, not against the working database by default.

Table 73. Technical evidence supplied with the manuscript.

| Evidence area | Artifact location relative to docs | What it supports |
|---|---|---|
| Overall examination | `system-audit/COMPLETE_SYSTEM_AUDIT.md` | Audit scope, implementation inventory and limits. |
| Backend and database | `system-audit/BACKEND_AND_DATABASE_AUDIT.md` | Source-supported services and live-schema consistency findings. |
| Security and reliability | `system-audit/SECURITY_AND_RELIABILITY_AUDIT.md` | Prioritized defects, present controls and limited reproductions. |
| Processing engine | `system-audit/PYTHON_ENGINE_AUDIT.md` | Python architecture, configuration and processing findings. |
| Interface examination | `system-audit/UI_UX_AUDIT.md` | Page/interaction observations and rendering limitations. |
| Functional evidence | `system-audit/FUNCTIONAL_TEST_RESULTS.md` | Expected/actual/status evidence with test boundaries. |
| Feature/workflow mapping | `system-audit/FEATURE_INVENTORY.md`, `system-audit/SYSTEM_WORKFLOWS.md` | Actual entry points, actors, data movement and limitations. |
| Schema metadata | `system-audit/evidence/live-schema.json` | Observed tables, columns, constraints and database version, without application row data. |
| Isolated PHP fixtures | `system-audit/evidence/backend-isolated-checks.json` | Two ownership failures and one suffix-range failure using synthetic data. |
| Source inventory | `system-audit/evidence/backend-feature-notes.json` | Curated capabilities, file hashes and method locations. |
| Dictionary and ERD | `capstone-manuscript/tables/DATA_DICTIONARY.md`, `capstone-manuscript/diagrams/ERD_ACTUAL_SCHEMA.mmd` | 312-column dictionary and actual 35-FK relationship source. |
| Researcher-source concordance | `capstone-manuscript/reference-analysis/RESEARCHER_DRAFT_IMAGE_REVIEW.md` | Supplied image review, schedule transcription and corrections. |

Table 73 identifies the supporting files and the claims each can substantiate. The source maps and metadata should be tied to the reviewed version; later application edits can invalidate line references and findings. The manuscript's build reports and generated contents/lists document presentation checks separately. An editable document's successful export does not establish substantive research completeness.

## Controlled Reproduction Principles

Preserve the original application and create a designated isolated copy with synthetic fixtures. Prevent the clone from using production database settings, email delivery, external callbacks or scheduled cleanup. Use the explicitly recorded interpreter and dependencies. Run only relevant tests, retain their raw output, and compare observed outcomes with the stated expected behavior. Treat expected-denial failures as findings, not as a reason to discard the case.

Read-only schema verification should query metadata directly without the application bootstrap. The web bootstrap can touch guest state, and certain maintenance status/dry-run commands have side effects. Do not infer a read-only guarantee from their command names. For future benchmark or human-evaluation reproduction, preserve corpus permissions, source/reference versions, run configuration, model availability and analysis rules before generating results.
