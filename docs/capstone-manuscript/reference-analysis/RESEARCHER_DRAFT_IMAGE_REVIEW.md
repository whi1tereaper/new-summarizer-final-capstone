# Researcher draft image review and concordance

Source: `C:/Downloads/CHECK  BUSSENG  HAYOP NA YAN.docx`, supplied by the researchers during this documentation task. All 11 embedded media objects were extracted without altering the original document into `researcher-draft-media/` and visually inspected. Two are institutional logos and nine are substantive diagrams. These are supplied research draft materials, not independent evidence that research activities occurred or that an older diagram matches the present application.

The current explicit user title is **LIGHT summarizer**. The current explicit researcher names are **Robert Martin Baltisoto**, **Shielo Mariell Leodones**, **Gieselle Sabbaluca**, and **Sofiah Heaven Galman**. Those user-supplied names take precedence over initials, capitalization, and order appearing in the older draft. The supplied document's academic project title and NEUST Peñaranda Off-Campus locale are SOURCE-SUPPORTED researcher statements; institutional approval and completed research activities still require supporting records.

## Inventory and visual findings

| Extracted object | Content | Evidence and disposition |
|---|---|---|
| `image1.png` | NEUST College of Information and Communications Technology emblem | Supplied institutional graphic; not proof of institutional approval. Preserve as source evidence. |
| `image2.png` | Nueva Ecija University of Science and Technology emblem | Supplied institutional graphic; not proof of institutional approval. Preserve as source evidence. |
| `image3.png` | Gantt chart titled AGILE MODEL, April–October 2026 | Establishes a supplied schedule organized by planning, analysis, design, implementation, testing/integration, and maintenance. It does not establish that Agile practices, tests, evaluations, or backups actually occurred. |
| `image4.png` | Use case diagram | Shows user/admin activities including summarization, history, translation, playback, feedback, and administration. Guests and registered users are merged in one actor despite different record access. Several include/extend arrows terminate in dashboard/group boundaries or connect unrelated actions rather than expressing valid use case dependencies. Rebuild from audited roles and activities. |
| `image5.jpeg` | Context data-flow diagram | Central label repeats NLP-Based. An admin-to-system arrow is labelled Feedback and Summary Records, implying records originate from the admin; actual system storage and monitoring must be shown accurately. External processing/mail services are absent. Rebuild and balance with the verified lower-level DFD. |
| `image6.png` | Six-process data-flow diagram | User information/account credentials are routed between user management and admin as if admin were a data store. Analytics Request points from process to admin, while Analytics Results/Summary Records/Feedback Ratings points from admin to process; these directions are reversed for a request/result workflow. No actual database stores or external processing service are represented. Rebuild from verified data exchanges. |
| `image7.png` | User management/authentication decomposition | Users data store connects directly to Admin, bypassing a process. Authentication has only an input and no validation store or response. These are incomplete DFD semantics, not verified implementation facts. |
| `image8.png` | Login and password-reset decomposition | Login Status forms a self-returning loop to the same process rather than a response to the actor. Login credentials use ambiguous bidirectional paths. The backend audit supports six-digit administrator/reset OTP flows, so the presence of OTP is not itself an error. The corrected diagram distinguishes PHP-session verification state, password_resets and remembered-login tokens within logical D1. |
| `image9.jpeg` | Summary viewing/history/saving decomposition | Names a store `summary_records`, whereas the audited ERD uses `summaries` plus `summary_artifacts` and related tables. A notes-based save request is depicted but cannot be adopted without a corresponding audited feature. |
| `image10.jpeg` | Feedback submission/viewing decomposition | Names `feedback_records`; the audited schema instead has `feedback` and `feedback_comments`. A repeated D4 feedback store is disconnected. The final representation must distinguish per-summary ratings/comments from the separate evaluation module. |
| `image11.jpeg` | Analytics decomposition | Uses the same unsupported `summary_records` and `feedback_records` names, and omits the separately audited landing-page analytics/session/event stores. Rebuild from actual queries and intended scope. |

The current ERD source of truth is `../diagrams/ERD_ACTUAL_SCHEMA.mmd`, generated from the inspected live-schema evidence, with its assumptions in `../diagrams/ERD_NOTES.md`. A diagram label that merely sounds plausible is not evidence of a real SQL table or foreign key. These supplied images are not inserted into the new manuscript as if they were corrected architecture diagrams.

## Gantt chart transcription

The chart uses four numbered week slots per month, without calendar-date boundaries. The following intervals are visual transcriptions of colored bars; exact start/end days and completion status are not supplied. Month/week slots must not be converted into invented activity dates. As of the user-provided current date, 17 October 2026, the chart still does not prove whether a scheduled activity has been completed.

| Stage | Activity shown | Supplied 2026 schedule interval |
|---|---|---|
| Planning | Brainstorming | April weeks 1–2 |
| Planning | Data Gathering | April weeks 3–4 |
| Planning | Determining Data Requirements | May weeks 1–2 |
| Planning | Defining System Scope and Objectives | May weeks 3–4 |
| Analysis | Gathering Requirements | May week 1–June week 1 |
| Analysis | Defining Features | May week 4–June week 2 |
| Analysis | Defining the System Objectives | June weeks 2–4 |
| Design | Creating System Models | May week 4–June week 4 |
| Design | Structuring the Front-End | June week 2–July week 4 |
| Design | Planning the Back-End Interface | July week 2–August week 2 |
| Implementation | Creating System Flow | June week 4–July week 4 |
| Implementation | Choosing Database | July week 2–August week 3 |
| Implementation | Structuring Tables and Variables | August week 1–September week 2 |
| Implementation | Coding of the System | August week 2–September week 2 |
| Testing and integration | Finalizing the System Functionalities | August week 4–September week 3 |
| Testing and integration | System Testing by Researchers | September week 3–October week 1 |
| Testing and integration | Evaluation by End-Users | October weeks 1–2 |
| Testing and integration | Evaluation of IT Consultants | October weeks 2–3 |
| Maintenance | Improving the System Performance | October weeks 1–2 |
| Maintenance | Fixing Identified Issues | October weeks 2–3 |
| Maintenance | Continuous System Backup | October weeks 3–4 |

The chart may be described as a **SOURCE-SUPPORTED researcher-supplied schedule** and reproduced with that qualification if desired. It must not be described as a verified completed development timeline, proof of evaluation respondents/results, or evidence of automated backup operations. Researcher confirmation is needed for whether it is planned, revised, or an as-completed record; dated activity logs, evaluator consent/response files, and backup evidence remain separate requirements.
