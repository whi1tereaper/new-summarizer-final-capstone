# CHAPTER III — RESULTS AND DISCUSSION

This chapter presents the implemented LIGHT summarizer and the evidence obtained during the technical audit on 17 October 2026. It distinguishes three kinds of information: needs and development intentions described in the researchers’ supplied draft; implementation supported by source code and database metadata; and behavior observed in bounded tests. No completed consultant or end-user questionnaire responses were supplied. Consequently, the human-evaluation sections contain pending tables and cannot establish acceptance, learning benefits or compliance with a quality standard.

## 1. Problems Encountered in the Existing System

The researchers’ draft identifies a practical reading problem: students face extensive academic material and may have difficulty selecting main ideas, preserving important details and allocating reading time. Its intended setting is the NEUST Peñaranda Off-Campus academic community. These statements provide the project’s motivation; the available records do not establish how often the problems occurred, how many people experienced them, or what interviews or observations produced them. The term existing system therefore refers to the described reading and summarization practice, rather than to a verified earlier software product.

The audit also found implementation defects. Those defects concern the developed software and are discussed under testing and limitations. They are not substituted for the original research problem. A missing ownership check, for example, is an engineering finding; it is not evidence that students previously experienced a reading difficulty.

## 2. How the Developed System Addresses the Identified Problems

The application brings several reading-support activities into one interface. A user can supply supported material, choose a summary organization and depth, and inspect a result with source-related detail. Registered users have additional history and account functions. Administrators have account, feedback, storage and activity views. These functions provide mechanisms through which the proposed needs may be addressed, but their presence does not establish that users read faster, understand more, or prefer the application to another method.

Table 6. Relationship between supplied project needs and implemented capabilities.

| Supplied need | Implemented response | Evidence and boundary |
|---|---|---|
| Difficulty identifying main ideas | Source extraction, sentence selection, modes, depths and result organization | Source traced; one legacy fixture returned a summary; over-filtering and omission defects remain |
| Extensive material in different forms | PDF/DOCX, pasted text and article URL intake | Interface/source supported; representative live uploads and external URLs not submitted |
| Need to revisit useful material | Summary history, result access, text export and clipboard copy | Copy and TXT export passed with synthetic content; saved history was not exercised |
| Need for alternative ways to read output | Filipino translation, audio and concise Nutshell paths | Implemented; external translation, speech quality and successful Nutshell web generation unverified |
| Need to manage use and collect feedback | Role-restricted administration, ratings, comments and analytics | Source supported; synthetic interfaces only; telemetry is not a research survey |

Table 6 relates the draft’s needs to the actual implementation without treating an expected benefit as a measured outcome. The evidence column limits each claim to the work performed. Evaluation by the intended participants is still needed to determine whether the functions address their needs in practice.

<!-- INCLUDE tables/FEATURE_DISCUSSION.md -->

### Security Features

Authentication uses password hashing and session handling, with an emailed code in the administrator sign-in path. Remembered user sessions use stored token material, while administrator auto-login is refused. Password recovery has a time-limited code flow. Most principal mutation routes use request validation, and database repositories use prepared statements. The source includes role checks, result ownership or bearer-token checks, escaping in result rendering, rate-limit facilities and upload validation. URL acquisition validates schemes and destination addresses, pins the selected address and checks redirects to reduce server-side request risks.

These controls do not provide uniform protection across all interfaces. Isolated execution of the document API’s actual controller and service returned source content for another user and for an anonymous context. The separate Q&A and document dispatchers lack a CSRF check. Password replacement does not revoke existing remembered tokens or all active sessions, and an alternate administrator status action bypasses protections used by the dedicated deactivation action. These findings must remain visible alongside the control descriptions. No comprehensive penetration test or claim of security certification is made.

### User Experience Features

The interface offers a mobile navigation menu, theme selection, source tabs, explanatory labels, validation messages, progress and recovery views, and output controls. Eighteen local interaction assertions passed in the isolated browser fixtures. Forty-six screenshots cover twenty-three interface states at desktop and mobile widths; the tested fixtures did not show document-level horizontal overflow. These observations establish limited layout and interaction behavior, rather than accessibility conformance across browsers and assistive technologies.

Remaining usability issues include password-visibility controls excluded from the normal tab sequence, audit-log filters without programmatic labels, a missing favicon, history without visible paging beyond its default 10-summary page, and links to a retired evaluation-management route. Some pages represent empty or unavailable states. Such states are documented because users require understandable recovery paths as well as successful-result screens.

## 4. Design and Development of the System

### 4.1 Planning Stage

The supplied researcher draft describes an Agile approach and an April–October 2026 activity schedule. Chapter II transcribes that schedule as supplied planning material. It does not establish completed sprints, meetings, testing sessions or deployment milestones. The confirmed project title is LIGHT summarizer; the institution’s required title wording, program, adviser and approval records remain subject to researcher confirmation.

The implemented requirements can be reconstructed from source: multiple source types, selectable output settings, background jobs, result reuse, accounts, administrative facilities and controlled evaluation services. Constraints include document and request limits, local Python dependencies, database consistency, external translation/mail services, and unavailable human-evaluation records. This reconstruction documents the current design; it is not a retroactive assertion that a historical requirements meeting occurred.

### 4.2 Design Stage

#### A. Use Case Diagram

The only persisted account roles are user and admin. A guest is a temporary access context, and an evaluator is a user with an assignment, not a third account role. The use-case model distinguishes browser access contexts. Maintenance is an operational responsibility described in Appendix G, rather than an additional stored account role. Restrictions stated in the diagram apply to intended authorized paths; the document API’s reproduced authorization defect remains an exception requiring repair.


![Implemented actors and major use cases.](diagrams/use-cases.png)

Figure 5. Implemented actors and major use cases.

Figure 5 illustrates guest, registered-user, administrator and assigned-evaluator activities. Guest access does not grant the authenticated Nutshell workflow. A maintenance operator is a deployment responsibility rather than a database account role. Source inspection supports the relationships; only the named test cases establish runtime observations.


The following enlarged role panels provide the readable detail of the overview. Their associations do not introduce additional database roles.

![Guest visitor use cases.](diagrams/use-cases-guests.png)

Figure 6. Guest visitor use cases.

Figure 6 illustrates the authorized activities for the named access context. The panel is derived from the same role model as the overview; existence of a use case is source-supported and does not override the verification limits in the feature inventory.

![Registered account use cases.](diagrams/use-cases-registered.png)

Figure 7. Registered account use cases.

Figure 7 illustrates the authorized activities for the named access context. The panel is derived from the same role model as the overview; existence of a use case is source-supported and does not override the verification limits in the feature inventory.

![Administrator use cases.](diagrams/use-cases-administrators.png)

Figure 8. Administrator use cases.

Figure 8 illustrates the authorized activities for the named access context. The panel is derived from the same role model as the overview; existence of a use case is source-supported and does not override the verification limits in the feature inventory.

![Assigned evaluator use cases.](diagrams/use-cases-evaluators.png)

Figure 9. Assigned evaluator use cases.

Figure 9 illustrates the authorized activities for the named access context. The panel is derived from the same role model as the overview; existence of a use case is source-supported and does not override the verification limits in the feature inventory.

#### B. Data Flow Diagram

The context model treats LIGHT summarizer as one process. Level 1 expands that process into account handling, source processing, result access, supporting output services, administration and evaluation. Detailed models then expand summary generation and authentication. Logical store identifiers retain their meaning across the levels. File storage and PHP session state are shown distinctly from declared database entities. External providers are included where the inspected code can contact them; their presence does not mean those integrations were exercised.


![Context diagram of LIGHT summarizer.](diagrams/dfd-context.png)

Figure 10. Context diagram of LIGHT summarizer.

Figure 10 illustrates the boundary between people, the application, and external information or delivery services. Submitted sources and requests enter the application, while summaries, access decisions and administrative information return through application processes.


![Level 1 data flows and logical stores.](diagrams/dfd-level1.png)

Figure 11. Level 1 data flows and logical stores.

Figure 11 illustrates the division of work among the major application processes. Accounts, generated records, feedback, telemetry, audit information and evaluation material have distinct storage responsibilities. Logical grouping is for explanation; the data dictionary provides the exact tables.


![Level 2 summary-generation data flow.](diagrams/dfd-level2-summary.png)

Figure 12. Level 2 summary-generation data flow.

Figure 12 illustrates source validation and extraction, job creation and execution, Python processing, persistence and status/result response. The output passes through an application process before returning to a user. A worker response alone is not evidence that the PHP save step succeeded.


![Level 2 account and authentication data flow.](diagrams/dfd-level2-auth.png)

Figure 13. Level 2 account and authentication data flow.

Figure 13 illustrates registration, credentials, administrator email-code handling, password recovery and session/token establishment. Session verification state belongs to the account store group; it is not represented as a nonexistent administrator-code table.


The editable diagram sources and focused print panels accompany the manuscript. The models describe the inspected application contract, including optional services, while the issue register identifies places where implementation does not enforce the intended boundary. They do not add a student database, course-record system, messaging service, or research-participant role that the application does not contain.

The following enlarged panels retain the process and store identifiers used in the overview. They divide a dense drawing for print legibility rather than define additional processes.

![Level 1 account and session detail.](diagrams/dfd-level1-accounts.png)

Figure 14. Level 1 account and session detail.

Figure 14 illustrates the named portion of the inspected data flow. Inputs and outputs pass through an application process; persistent tables, file storage and PHP session state retain their documented distinctions. The complete overview and editable sources retain the cross-panel context.

![Level 1 source and summary detail.](diagrams/dfd-level1-summary.png)

Figure 15. Level 1 source and summary detail.

Figure 15 illustrates the named portion of the inspected data flow. Inputs and outputs pass through an application process; persistent tables, file storage and PHP session state retain their documented distinctions. The complete overview and editable sources retain the cross-panel context.

![Level 1 result and reading-helper detail.](diagrams/dfd-level1-results.png)

Figure 16. Level 1 result and reading-helper detail.

Figure 16 illustrates the named portion of the inspected data flow. Inputs and outputs pass through an application process; persistent tables, file storage and PHP session state retain their documented distinctions. The complete overview and editable sources retain the cross-panel context.

![Level 1 feedback and usage detail.](diagrams/dfd-level1-feedback.png)

Figure 17. Level 1 feedback and usage detail.

Figure 17 illustrates the named portion of the inspected data flow. Inputs and outputs pass through an application process; persistent tables, file storage and PHP session state retain their documented distinctions. The complete overview and editable sources retain the cross-panel context.

![Level 1 administration and evaluation detail.](diagrams/dfd-level1-administration.png)

Figure 18. Level 1 administration and evaluation detail.

Figure 18 illustrates the named portion of the inspected data flow. Inputs and outputs pass through an application process; persistent tables, file storage and PHP session state retain their documented distinctions. The complete overview and editable sources retain the cross-panel context.

![Level 2 summary intake detail.](diagrams/dfd-level2-summary-intake.png)

Figure 19. Level 2 summary intake detail.

Figure 19 illustrates the named portion of the inspected data flow. Inputs and outputs pass through an application process; persistent tables, file storage and PHP session state retain their documented distinctions. The complete overview and editable sources retain the cross-panel context.

![Level 2 summary output detail.](diagrams/dfd-level2-summary-output.png)

Figure 20. Level 2 summary output detail.

Figure 20 illustrates the named portion of the inspected data flow. Inputs and outputs pass through an application process; persistent tables, file storage and PHP session state retain their documented distinctions. The complete overview and editable sources retain the cross-panel context.

![Level 2 account verification detail.](diagrams/dfd-level2-auth-verification.png)

Figure 21. Level 2 account verification detail.

Figure 21 illustrates the named portion of the inspected data flow. Inputs and outputs pass through an application process; persistent tables, file storage and PHP session state retain their documented distinctions. The complete overview and editable sources retain the cross-panel context.

![Level 2 session and identity-change detail.](diagrams/dfd-level2-auth-session.png)

Figure 22. Level 2 session and identity-change detail.

Figure 22 illustrates the named portion of the inspected data flow. Inputs and outputs pass through an application process; persistent tables, file storage and PHP session state retain their documented distinctions. The complete overview and editable sources retain the cross-panel context.

#### C. Database Normalization

The available evidence is a current schema snapshot, not a documented historical sequence from unnormalized forms to third normal form. Accordingly, this section describes present design decisions and constraints. User accounts are separated from generated summaries. Source artifacts, jobs, ratings and comment events have separate stores. Evaluation datasets, documents, references, generated outputs and ratings are represented independently, allowing repeated observations without placing every detail in one wide record.

An illustrative dependency is that a user identifier determines that account’s stored attributes, while a summary identifier determines its generated-summary record. Separate job and artifact identifiers support asynchronous processing and supporting material. This explanation is based on declared keys and current code use; it does not prove that every semantic dependency has been exhaustively normalized. JSON/text payloads, caches, counters and snapshots deliberately retain compound or derived information, so a blanket claim that all tables are in third normal form would be unsupported.

The database declares thirty-five foreign keys. Several identifier-looking fields have no declared relationship: examples include documents.user_id, nutshell_generations.user_id and landing_page_sessions.user_id. Guest-token associations also depend on application logic. The summary_artifacts.summary_id column is not unique, so the physical schema permits more than one artifact per summary. These distinctions are preserved in the ERDs and dictionary rather than replaced with assumed one-to-one relationships.

#### D. Entity Relationship Diagram

The complete ERD was generated from the read-only MariaDB metadata snapshot. To keep the printed relationships legible, twelve focused panels repeat shared entities where necessary. Their combined coverage was mechanically checked against all thirty-one entities and thirty-five declared foreign keys. Each relationship uses the actual key and nullability information. A missing line is not evidence that two concepts are unrelated; it means the live schema does not enforce that relationship as a foreign key.


![Accounts and authentication ERD panel.](diagrams/ERD_PANEL_01_ACCOUNTS.png)

Figure 23. Accounts and authentication ERD panel.

Figure 23 illustrates the declared relationships in the accounts and authentication portion of the current database. Shared entities repeat across panels to preserve context. Primary and foreign keys are shown here; all columns and exact constraints appear in the data dictionary.


![Summary ownership, jobs and artifacts ERD panel.](diagrams/ERD_PANEL_02_SUMMARY_OUTPUTS.png)

Figure 24. Summary ownership, jobs and artifacts ERD panel.

Figure 24 illustrates the declared relationships in the summary ownership, jobs and artifacts portion of the current database. Shared entities repeat across panels to preserve context. Primary and foreign keys are shown here; all columns and exact constraints appear in the data dictionary.


![Ratings and comments ERD panel.](diagrams/ERD_PANEL_03_FEEDBACK.png)

Figure 25. Ratings and comments ERD panel.

Figure 25 illustrates the declared relationships in the ratings and comments portion of the current database. Shared entities repeat across panels to preserve context. Primary and foreign keys are shown here; all columns and exact constraints appear in the data dictionary.


![Document extraction and cached combinations ERD panel.](diagrams/ERD_PANEL_04_DOCUMENT_CACHE.png)

Figure 26. Document extraction and cached combinations ERD panel.

Figure 26 illustrates the declared relationships in the document extraction and cached combinations portion of the current database. Shared entities repeat across panels to preserve context. Primary and foreign keys are shown here; all columns and exact constraints appear in the data dictionary.


![Landing sessions and events ERD panel.](diagrams/ERD_PANEL_05_LANDING_TELEMETRY.png)

Figure 27. Landing sessions and events ERD panel.

Figure 27 illustrates the declared relationships in the landing sessions and events portion of the current database. Shared entities repeat across panels to preserve context. Primary and foreign keys are shown here; all columns and exact constraints appear in the data dictionary.


![Stores without declared foreign keys ERD panel.](diagrams/ERD_PANEL_06_INDEPENDENT_STORES.png)

Figure 28. Stores without declared foreign keys ERD panel.

Figure 28 illustrates the current tables that have no declared foreign-key edges. Their identifiers and application-level associations must not be mistaken for enforced relational constraints. The panel intentionally contains no inferred relationship lines.


![Evaluation datasets, documents, runs and outputs ERD panel.](diagrams/ERD_PANEL_07_EVALUATION_PIPELINE.png)

Figure 29. Evaluation datasets, documents, runs and outputs ERD panel.

Figure 29 illustrates the declared relationships in the evaluation datasets, documents, runs and outputs portion of the current database. Shared entities repeat across panels to preserve context. Primary and foreign keys are shown here; all columns and exact constraints appear in the data dictionary.


![References, content units and challenge cases ERD panel.](diagrams/ERD_PANEL_08_EVALUATION_CONTENT.png)

Figure 30. References, content units and challenge cases ERD panel.

Figure 30 illustrates the declared relationships in the references, content units and challenge cases portion of the current database. Shared entities repeat across panels to preserve context. Primary and foreign keys are shown here; all columns and exact constraints appear in the data dictionary.


![Assignments, ratings, errors and metrics ERD panel.](diagrams/ERD_PANEL_09_EVALUATION_SCORING.png)

Figure 31. Assignments, ratings, errors and metrics ERD panel.

Figure 31 illustrates the declared relationships in the assignments, ratings, errors and metrics portion of the current database. Shared entities repeat across panels to preserve context. Primary and foreign keys are shown here; all columns and exact constraints appear in the data dictionary.


![Content-unit reviews ERD panel.](diagrams/ERD_PANEL_10_UNIT_REVIEWS.png)

Figure 32. Content-unit reviews ERD panel.

Figure 32 illustrates the declared relationships in the content-unit reviews portion of the current database. Shared entities repeat across panels to preserve context. Primary and foreign keys are shown here; all columns and exact constraints appear in the data dictionary.


![Evaluator and reviewer account relationships ERD panel.](diagrams/ERD_PANEL_11_EVALUATOR_ACCOUNTS.png)

Figure 33. Evaluator and reviewer account relationships ERD panel.

Figure 33 illustrates the declared relationships in the evaluator and reviewer account relationships portion of the current database. Shared entities repeat across panels to preserve context. Primary and foreign keys are shown here; all columns and exact constraints appear in the data dictionary.


![Dataset, run and reference authorship ERD panel.](diagrams/ERD_PANEL_12_RESEARCH_AUTHORSHIP.png)

Figure 34. Dataset, run and reference authorship ERD panel.

Figure 34 illustrates the declared relationships in the dataset, run and reference authorship portion of the current database. Shared entities repeat across panels to preserve context. Primary and foreign keys are shown here; all columns and exact constraints appear in the data dictionary.


#### E. Data Dictionary

The following tables document every column in the observed database: thirty-one tables and three hundred twelve fields. Types, lengths, nullability, defaults, keys and declared referential actions are metadata facts. Descriptions explain likely field purposes based on names and inspected service use; they do not imply that legacy fields are actively used. No application rows, passwords, tokens or personal records were extracted for this dictionary.

The live password_resets structure uses used_at, whereas the base installation SQL defines used and created_at. No inspected migration resolves that difference. Consequently, the dictionary represents the observed database, while a new installation requires a separate schema-reconciliation and rehearsal task. A successful connection to the current database would not establish that fresh installation is reliable.

<!-- INCLUDE tables/DATA_DICTIONARY.md -->

### 4.3 Development Stage

The application uses PHP-rendered pages with JavaScript and CSS, a MariaDB database, and Python language-processing services. It is organized around controllers, services and repositories rather than a verified external PHP application framework. The inspected configuration defaults to the legacy summary engine; the independent v2 engine and optional LLM synthesis must not be described as the enabled main algorithm. Translation may use external providers, and email verification requires a working delivery configuration.

Table 38. Technologies and observed development environment.

| Component | Observed implementation | Verification boundary |
|---|---|---|
| Web application | PHP templates, controllers, services, repositories; JavaScript and CSS | Original source inspected; PHP/JS syntax checked |
| PHP runtime | XAMPP PHP 8.1.25 for syntax/metadata; PHP 8.3.29 for isolated templates | Other configured installations may differ |
| Database | MariaDB 10.4.32; 31 tables, 312 columns, 35 foreign keys | Metadata read; application rows not read |
| Processing | Python legacy pipeline and optional v2 path | Existing tests ran using available Python 3.14 packages in isolation |
| Document/NLP facilities | pypdf, python-docx, text statistics, optional models and OCR | Resource availability varies; no model downloads performed |
| Audio/translation | Piper voice path; deep-translator/provider paths | Actual speech and live translation not validated |
| Local environment | Windows workspace under XAMPP htdocs | Public hosting, TLS and deployment readiness not verified |

Table 38 records observed technology separately from installation assumptions. In particular, the test interpreter is not proof that every optional dependency supports the same environment. Older setup claims of Python 3.8 conflict with current language/API usage; a tested installation matrix is required.

The screenshots below are original captures of this application’s templates. They were rendered with empty or synthetic records in a read-only harness that bypassed production startup. Each carries an audit label. They illustrate interface organization, controls and error states; they are not screenshots of genuine users, real study results, completed logins or saved production summaries. Appendix F supplies step-by-step use instructions and additional states.


![Landing information and navigation — isolated template capture.](screenshots/ui-landing-desktop.png)

Figure 35. Landing information and navigation — isolated template capture.

Figure 35 illustrates the entry page and links to the summarizer and account pages. The text establishes the interface’s intended purpose, not a measured benefit.


![Source intake and summary settings — isolated template capture.](screenshots/ui-summarizer-desktop.png)

Figure 36. Source intake and summary settings — isolated template capture.

Figure 36 illustrates the source choices, title/focus inputs, eight analysis modes, six depths and five output organizations. Local selection tests do not verify all generation combinations.


![Sign-in form — isolated template capture.](screenshots/ui-sign-in-desktop.png)

Figure 37. Sign-in form — isolated template capture.

Figure 37 illustrates credential and remembered-session controls. The synthetic render does not authenticate an account.


![Registration form — isolated template capture.](screenshots/ui-register-desktop.png)

Figure 38. Registration form — isolated template capture.

Figure 38 illustrates the information requested for account creation. No account was created during this audit.


![Recovery request form — isolated template capture.](screenshots/ui-password-recovery-desktop.png)

Figure 39. Recovery request form — isolated template capture.

Figure 39 illustrates the entry point for requesting a password-reset code. Email delivery and token consumption were not exercised.


![Password reset form — isolated template capture.](screenshots/ui-password-reset-desktop.png)

Figure 40. Password reset form — isolated template capture.

Figure 40 illustrates code and new-password inputs. The known schema and token-revocation limitations apply to this workflow.


![Administrator code verification state — isolated template capture.](screenshots/ui-admin-code-synthetic-desktop.png)

Figure 41. Administrator code verification state — isolated template capture.

Figure 41 illustrates the additional emailed-code step for administrator access. The displayed state was injected in isolation and proves no email delivery.


![Terms acceptance state — isolated template capture.](screenshots/ui-terms-pending-synthetic-desktop.png)

Figure 42. Terms acceptance state — isolated template capture.

Figure 42 illustrates the checkbox and acceptance control for an account with pending terms. Local enabling behavior passed; persistence was not submitted.


![Personal dashboard empty state — isolated template capture.](screenshots/ui-dashboard-empty-desktop.png)

Figure 43. Personal dashboard empty state — isolated template capture.

Figure 43 illustrates the organization of recent work and account-related activity when the fixture contains no saved records. Its counters are not actual usage statistics.


![History empty state — isolated template capture.](screenshots/ui-history-empty-desktop.png)

Figure 44. History empty state — isolated template capture.

Figure 44 illustrates the saved-work area and navigation. The backend history limit and absent paging controls remain known limitations.


![Analytics empty state — isolated template capture.](screenshots/ui-analytics-empty-desktop.png)

Figure 45. Analytics empty state — isolated template capture.

Figure 45 illustrates the presentation of activity filters and summaries. Synthetic empty values cannot be used as research data.


![Account profile display — isolated template capture.](screenshots/ui-profile-synthetic-desktop.png)

Figure 46. Account profile display — isolated template capture.

Figure 46 illustrates the account-information page and available account actions. The sample identity is synthetic, and profile editing is not an implemented page feature.


![Background processing state — isolated template capture.](screenshots/ui-processing-synthetic-desktop.png)

Figure 47. Background processing state — isolated template capture.

Figure 47 illustrates progress and recovery controls around a summary job. This fixture did not run a production job or establish successful persistence.


![Structured result and output controls — isolated template capture.](screenshots/ui-result-synthetic-desktop.png)

Figure 48. Structured result and output controls — isolated template capture.

Figure 48 illustrates the result layout, evidence-related detail and copy, text export, print, audio, translation and feedback controls. The displayed text is synthetic rather than a generated evaluation output.


![Unavailable-result recovery state — isolated template capture.](screenshots/ui-result-unavailable-desktop.png)

Figure 49. Unavailable-result recovery state — isolated template capture.

Figure 49 illustrates the message shown when an accessible result cannot be supplied. This state is part of the user’s recovery path.


![Administrator overview — isolated template capture.](screenshots/ui-admin-dashboard-empty-desktop.png)

Figure 50. Administrator overview — isolated template capture.

Figure 50 illustrates the arrangement of account, storage, feedback and activity administration. Empty synthetic records prevent disclosure of production data.


![Administrator audit-log filters — isolated template capture.](screenshots/ui-admin-log-filters-empty-desktop.png)

Figure 51. Administrator audit-log filters — isolated template capture.

Figure 51 illustrates the filtering controls and log presentation. Missing programmatic labels were observed and require correction.


![Assigned evaluation empty state — isolated template capture.](screenshots/ui-evaluation-empty-desktop.png)

Figure 52. Assigned evaluation empty state — isolated template capture.

Figure 52 illustrates the reviewer view when no eligible assignment is supplied. No respondent rating or completed evaluation is represented.


### 4.4 Testing Stage

The audit used source inspection, syntax checks, read-only database metadata, isolated Python tests, synthetic controller fixtures and local browser rendering. Normal anonymous requests can create or update guest state and can trigger expiry cleanup. To preserve production data, live authenticated workflows, uploads, mutations, email delivery and background generation were therefore not performed. Three live requests were restricted to static health or redirects that do not load the application bootstrap, without following redirects.

Table 39. Audit verification procedures and outcomes.

| Test group | Expected behavior | Actual result | Scope/status |
|---|---|---|---|
| PHP and JavaScript syntax | All selected files parse | 150 passed | Syntax only |
| Existing Python tests | Asserted contracts hold | 595 passed, 1 failed, 4 skipped; 62 subtests passed | Isolated confirmation run, 600 test cases |
| Optional v2 definition fixture | Retain introductory outlier definition | Expected sentence absent | Failed regression |
| Legacy cleaning probe | Preserve valid library announcement | Paragraph containing register removed | Reproduced defect |
| Legacy NIST fixture | Return nonempty summary | Nonempty response | Direct function pass |
| Source Q&A fixture | Return source passage containing 120 books | Expected passage returned | Direct function pass |
| Nutshell synthetic fixture | Inspect generation response | Empty insufficient_content response | Successful generation not demonstrated |
| Document access boundary | Deny other owner and anonymous caller | Both returned HTTP 200 with source | Two failed authorization assertions |
| Audio suffix range | Return 206 and final 100 of 1,000 bytes | Returned 200 and all 1,000 bytes | Failed delivery assertion |
| Browser local interactions | Named controls behave as expected | 18 assertions matched | Isolated interface only |
| Anonymous guards | Source-specified denial/redirect | 19 responses matched | Bootstrap bypassed; not complete authorization proof |
| Responsive fixtures | Fit tested viewports | 46 captures; no document-level overflow | 23 synthetic/empty states |
| Live no-bootstrap routes | Health/redirect response | 3 expected responses | No redirects followed |

Table 39 reports individual evidence classes rather than combining unrelated observations into a misleading overall pass percentage. The full Python case register contains test identifiers, procedures/assertion names and outcomes; logs retain the initial permission-limited attempt and the confirmation run. The four skipped cases require a live translation result URL, which was not supplied. The test environment did not include every optional model resource.

The principal confirmed defects concern document access, whole-paragraph cleaning and audio range handling. Source inspection additionally identified incomplete upload metrics, reset-token persistence, a conflicting fresh-install reset schema, age-based cleanup described as orphan cleanup, and incomplete guest lifecycle coverage. The screenshot review identified interaction and navigation limitations. All are recorded with paths, evidence and remediation direction in KNOWN_ISSUES.md. The application was preserved rather than repaired during the audit.

An initial blank-first-page PDF probe was rejected by readability validation before it could test page-reference alignment. Therefore no measured page-number defect is claimed from that probe. Likewise, the raw Nutshell harness used PASS to mean that a response returned; its payload was insufficient_content with empty output. The manuscript interprets the payload, not the misleadingly broad harness label.

### 4.5 Deployment Stage

The verified environment is a local Windows/XAMPP workspace with an accessible MariaDB server and PHP runtime. No production hostname, public hosting account, TLS certificate, deployment approval or successful external launch was established. A claim that the application has been deployed to its intended research locale would require separate records.

Deployment requires a compatible PHP runtime and extensions, MariaDB credentials and schema, a functioning Python environment, writable application storage and appropriate process permissions. Optional OCR, language models, voices, translation and mail each require their own configuration and verification. The source supplies worker supervision and migration tools, but their existence is not proof that scheduled tasks were installed or that fresh installation succeeds. Appendix G gives staging-first procedures and identifies commands that can mutate state even when described as status or dry-run operations.

### 4.6 Operational Stage

Operational responsibility includes checking worker availability, processing failures, storage growth, mail and translation failures, database health and access-control behavior. Administrators can view activity and feedback, while maintainers have CLI and filesystem responsibilities. These responsibilities describe the implemented operational surface and recommended practice, not a verified staffing arrangement.

Cleanup helpers exist for selected telemetry, audit events, comments, temporary job payloads and guest records. Scheduled execution was not verified. Upload cleanup uses age rather than a complete database-reference check, so it cannot safely be characterized as proven orphan detection. Automated backup and a successful restore drill were not established. A responsible deployment should rehearse a consistent database-and-storage backup, verify restoration in isolation and retain a documented rollback procedure.

## 5. IT Consultants’ System Evaluation

PENDING EVALUATION. No genuine consultant responses, respondent count, approved sampling procedure or final rating scale were supplied. The following seven domains preserve the requested selected ISO/IEC 25010:2011-style organization. They omit maintainability and therefore do not constitute the full eight-characteristic 2011 product-quality model. The 2011 edition has been withdrawn and replaced; the institution must approve the chosen edition and adaptation before administration. Appendix H contains proposed items. The four-point coding in Chapter II is a draft, not an approved research instrument or the application’s separate five-star feedback scale.


### Functional Suitability

Whether the specified tasks produce appropriate results. Relevant sub-characteristics for the proposed technical instrument are functional completeness, correctness and appropriateness. The available audit can guide test tasks and item review, but it cannot provide a consultant’s rating.


Table 40. Functional Suitability — pending human evaluation.

| Item/domain | Valid responses | Weighted mean | Verbal interpretation |
|---|---|---|---|
| Approved items for functional suitability | Pending | Pending | Pending approved scale and responses |

Table 40 is an unpopulated research template. No score, number of respondents or interpretation can be inferred from it. After approved administration, valid response frequencies and the stated analysis rule must support each value; audit findings remain separate evidence.


The result discussion remains pending because no completed responses are available. Before evaluation, the researcher should resolve or disclose the relevant known limitations, preserve the tested software version and document the conditions under which the task is performed.

### Performance Efficiency

Whether representative tasks meet approved time and resource expectations. Relevant sub-characteristics for the proposed technical instrument are time behaviour, resource utilisation and capacity. The available audit can guide test tasks and item review, but it cannot provide a consultant’s rating.


Table 41. Performance Efficiency — pending human evaluation.

| Item/domain | Valid responses | Weighted mean | Verbal interpretation |
|---|---|---|---|
| Approved items for performance efficiency | Pending | Pending | Pending approved scale and responses |

Table 41 is an unpopulated research template. No score, number of respondents or interpretation can be inferred from it. After approved administration, valid response frequencies and the stated analysis rule must support each value; audit findings remain separate evidence.


The result discussion remains pending because no completed responses are available. Before evaluation, the researcher should resolve or disclose the relevant known limitations, preserve the tested software version and document the conditions under which the task is performed.

### Compatibility

Whether the application works alongside relevant software and exchanges supported data. Relevant sub-characteristics for the proposed technical instrument are co-existence and interoperability. The available audit can guide test tasks and item review, but it cannot provide a consultant’s rating.


Table 42. Compatibility — pending human evaluation.

| Item/domain | Valid responses | Weighted mean | Verbal interpretation |
|---|---|---|---|
| Approved items for compatibility | Pending | Pending | Pending approved scale and responses |

Table 42 is an unpopulated research template. No score, number of respondents or interpretation can be inferred from it. After approved administration, valid response frequencies and the stated analysis rule must support each value; audit findings remain separate evidence.


The result discussion remains pending because no completed responses are available. Before evaluation, the researcher should resolve or disclose the relevant known limitations, preserve the tested software version and document the conditions under which the task is performed.

### Usability

Whether intended users can understand, operate and recover from the interface. Relevant sub-characteristics for the proposed technical instrument are appropriateness recognisability, learnability, operability, user error protection, interface aesthetics and accessibility. The available audit can guide test tasks and item review, but it cannot provide a consultant’s rating.


Table 43. Usability — pending human evaluation.

| Item/domain | Valid responses | Weighted mean | Verbal interpretation |
|---|---|---|---|
| Approved items for usability | Pending | Pending | Pending approved scale and responses |

Table 43 is an unpopulated research template. No score, number of respondents or interpretation can be inferred from it. After approved administration, valid response frequencies and the stated analysis rule must support each value; audit findings remain separate evidence.


The result discussion remains pending because no completed responses are available. Before evaluation, the researcher should resolve or disclose the relevant known limitations, preserve the tested software version and document the conditions under which the task is performed.

### Reliability

Whether the system behaves consistently and recovers under defined conditions. Relevant sub-characteristics for the proposed technical instrument are maturity, availability, fault tolerance and recoverability. The available audit can guide test tasks and item review, but it cannot provide a consultant’s rating.


Table 44. Reliability — pending human evaluation.

| Item/domain | Valid responses | Weighted mean | Verbal interpretation |
|---|---|---|---|
| Approved items for reliability | Pending | Pending | Pending approved scale and responses |

Table 44 is an unpopulated research template. No score, number of respondents or interpretation can be inferred from it. After approved administration, valid response frequencies and the stated analysis rule must support each value; audit findings remain separate evidence.


The result discussion remains pending because no completed responses are available. Before evaluation, the researcher should resolve or disclose the relevant known limitations, preserve the tested software version and document the conditions under which the task is performed.

### Security

Whether authorized use and protection of information are adequately supported. Relevant sub-characteristics for the proposed technical instrument are confidentiality, integrity, non-repudiation, accountability and authenticity. The available audit can guide test tasks and item review, but it cannot provide a consultant’s rating.


Table 45. Security — pending human evaluation.

| Item/domain | Valid responses | Weighted mean | Verbal interpretation |
|---|---|---|---|
| Approved items for security | Pending | Pending | Pending approved scale and responses |

Table 45 is an unpopulated research template. No score, number of respondents or interpretation can be inferred from it. After approved administration, valid response frequencies and the stated analysis rule must support each value; audit findings remain separate evidence.


The result discussion remains pending because no completed responses are available. Before evaluation, the researcher should resolve or disclose the relevant known limitations, preserve the tested software version and document the conditions under which the task is performed.

### Portability

Whether the application can be installed or adapted within a specified environment. Relevant sub-characteristics for the proposed technical instrument are adaptability, installability and replaceability. The available audit can guide test tasks and item review, but it cannot provide a consultant’s rating.


Table 46. Portability — pending human evaluation.

| Item/domain | Valid responses | Weighted mean | Verbal interpretation |
|---|---|---|---|
| Approved items for portability | Pending | Pending | Pending approved scale and responses |

Table 46 is an unpopulated research template. No score, number of respondents or interpretation can be inferred from it. After approved administration, valid response frequencies and the stated analysis rule must support each value; audit findings remain separate evidence.


The result discussion remains pending because no completed responses are available. Before evaluation, the researcher should resolve or disclose the relevant known limitations, preserve the tested software version and document the conditions under which the task is performed.

### Overall IT Consultants’ Evaluation Summary

An overall result requires approved domain aggregation rules and genuine domain observations. Empty domains must not be treated as zero scores or silently excluded to create a favorable mean.

Table 47. Overall IT consultants’ summary — pending human evaluation.

| Item/domain | Valid responses | Weighted mean | Verbal interpretation |
|---|---|---|---|
| Seven approved consultant domains | Pending | Pending | Pending approved scale and responses |

Table 47 is an unpopulated research template. No score, number of respondents or interpretation can be inferred from it. After approved administration, valid response frequencies and the stated analysis rule must support each value; audit findings remain separate evidence.


## 6. End-Users’ System Evaluation

PENDING EVALUATION. The supplied material identifies intended academic users but does not provide completed end-user questionnaires, consent records, sample counts or task results. The proposed instrument uses ordinary language and keeps task suitability, perceived performance and usability separate. It cannot establish classroom learning gains without an appropriate additional research design.


### Functional Suitability

This domain asks whether the available input, summary and result functions meet the participant’s specified reading task. Responses must come from actual participants using the approved version and protocol.


Table 48. Functional Suitability for end users — pending human evaluation.

| Item/domain | Valid responses | Weighted mean | Verbal interpretation |
|---|---|---|---|
| Approved end-user items for functional suitability | Pending | Pending | Pending approved scale and responses |

Table 48 is an unpopulated research template. No score, number of respondents or interpretation can be inferred from it. After approved administration, valid response frequencies and the stated analysis rule must support each value; audit findings remain separate evidence.


The narrative result is pending. The interface captures and engineering test outcomes cannot be relabeled as satisfaction responses, and no comparison with an earlier manual process can be made from the available evidence.

### Performance Efficiency

This domain asks whether waiting and response behavior are acceptable for the agreed task and device. Responses must come from actual participants using the approved version and protocol.


Table 49. Performance Efficiency for end users — pending human evaluation.

| Item/domain | Valid responses | Weighted mean | Verbal interpretation |
|---|---|---|---|
| Approved end-user items for performance efficiency | Pending | Pending | Pending approved scale and responses |

Table 49 is an unpopulated research template. No score, number of respondents or interpretation can be inferred from it. After approved administration, valid response frequencies and the stated analysis rule must support each value; audit findings remain separate evidence.


The narrative result is pending. The interface captures and engineering test outcomes cannot be relabeled as satisfaction responses, and no comparison with an earlier manual process can be made from the available evidence.

### Usability

This domain asks whether the participant can understand the steps, controls, messages and result presentation. Responses must come from actual participants using the approved version and protocol.


Table 50. Usability for end users — pending human evaluation.

| Item/domain | Valid responses | Weighted mean | Verbal interpretation |
|---|---|---|---|
| Approved end-user items for usability | Pending | Pending | Pending approved scale and responses |

Table 50 is an unpopulated research template. No score, number of respondents or interpretation can be inferred from it. After approved administration, valid response frequencies and the stated analysis rule must support each value; audit findings remain separate evidence.


The narrative result is pending. The interface captures and engineering test outcomes cannot be relabeled as satisfaction responses, and no comparison with an earlier manual process can be made from the available evidence.

### Overall End-Users’ Evaluation Summary

The proposed summary combines only the domains and item weights approved before data collection. Participants with missing or not-applicable answers must be handled transparently under that rule.

Table 51. Overall end-users’ summary — pending human evaluation.

| Item/domain | Valid responses | Weighted mean | Verbal interpretation |
|---|---|---|---|
| Three approved end-user domains | Pending | Pending | Pending approved scale and responses |

Table 51 is an unpopulated research template. No score, number of respondents or interpretation can be inferred from it. After approved administration, valid response frequencies and the stated analysis rule must support each value; audit findings remain separate evidence.
