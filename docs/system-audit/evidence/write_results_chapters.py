from pathlib import Path
import json,re
ROOT=Path(__file__).resolve().parents[3]
MAN=ROOT/'docs/capstone-manuscript'; AUD=ROOT/'docs/system-audit'
def write(p,s):p.write_text(s.strip()+'\n',encoding='utf-8')
def read(p):return p.read_text(encoding='utf-8-sig')
inv=json.loads(read(AUD/'feature-inventory.json'))
parts=['''# CHAPTER III — RESULTS AND DISCUSSION

This chapter presents the implemented LIGHT summarizer and the evidence obtained during the technical audit on 17 October 2026. It distinguishes three kinds of information: needs and development intentions described in the researchers’ supplied draft; implementation supported by source code and database metadata; and behavior observed in bounded tests. No completed consultant or end-user questionnaire responses were supplied. Consequently, the human-evaluation sections contain pending tables and cannot establish acceptance, learning benefits or compliance with a quality standard.

## 1. Problems Encountered in the Existing System

The researchers’ draft identifies a practical reading problem: students face extensive academic material and may have difficulty selecting main ideas, preserving important details and allocating reading time. Its intended setting is the NEUST Peñaranda Off-Campus academic community. These statements provide the project’s motivation; the available records do not establish how often the problems occurred, how many people experienced them, or what interviews or observations produced them. The term existing system therefore refers to the described reading and summarization practice, rather than to a verified earlier software product.

The audit also found implementation defects. Those defects concern the developed software and are discussed under testing and limitations. They are not substituted for the original research problem. A missing ownership check, for example, is an engineering finding; it is not evidence that students previously experienced a reading difficulty.

## 2. How the Developed System Addresses the Identified Problems

The application brings several reading-support activities into one interface. A user can supply supported material, choose a summary organization and depth, and inspect a result with source-related detail. Registered users have additional history and account functions. Administrators have account, feedback, storage and activity views. These functions provide mechanisms through which the proposed needs may be addressed, but their presence does not establish that users read faster, understand more, or prefer the application to another method.

Table C3A. Relationship between supplied project needs and implemented capabilities.

| Supplied need | Implemented response | Evidence and boundary |
|---|---|---|
| Difficulty identifying main ideas | Source extraction, sentence selection, modes, depths and result organization | Source traced; one legacy fixture returned a summary; over-filtering and omission defects remain |
| Extensive material in different forms | PDF/DOCX, pasted text and article URL intake | Interface/source supported; representative live uploads and external URLs not submitted |
| Need to revisit useful material | Summary history, result access, text export and clipboard copy | Copy and TXT export passed with synthetic content; saved history was not exercised |
| Need for alternative ways to read output | Filipino translation, audio and concise Nutshell paths | Implemented; external translation, speech quality and successful Nutshell web generation unverified |
| Need to manage use and collect feedback | Role-restricted administration, ratings, comments and analytics | Source supported; synthetic interfaces only; telemetry is not a research survey |

Table C3A relates the draft’s needs to the actual implementation without treating an expected benefit as a measured outcome. The evidence column limits each claim to the work performed. Evaluation by the intended participants is still needed to determine whether the functions address their needs in practice.

<!-- INCLUDE tables/FEATURE_DISCUSSION.md -->

### Security Features

Authentication uses password hashing and session handling, with an emailed code in the administrator sign-in path. Remembered user sessions use stored token material, while administrator auto-login is refused. Password recovery has a time-limited code flow. Most principal mutation routes use request validation, and database repositories use prepared statements. The source includes role checks, result ownership or bearer-token checks, escaping in result rendering, rate-limit facilities and upload validation. URL acquisition validates schemes and destination addresses, pins the selected address and checks redirects to reduce server-side request risks.

These controls do not provide uniform protection across all interfaces. Isolated execution of the document API’s actual controller and service returned source content for another user and for an anonymous context. The separate Q&A and document dispatchers lack a CSRF check. Password replacement does not revoke existing remembered tokens or all active sessions, and an alternate administrator status action bypasses protections used by the dedicated deactivation action. These findings must remain visible alongside the control descriptions. No comprehensive penetration test or claim of security certification is made.

### User Experience Features

The interface offers a mobile navigation menu, theme selection, source tabs, explanatory labels, validation messages, progress and recovery views, and output controls. Eighteen local interaction assertions passed in the isolated browser fixtures. Forty-six screenshots cover twenty-three interface states at desktop and mobile widths; the tested fixtures did not show document-level horizontal overflow. These observations establish limited layout and interaction behavior, rather than accessibility conformance across browsers and assistive technologies.

Remaining usability issues include password-visibility controls excluded from the normal tab sequence, audit-log filters without programmatic labels, a missing favicon, history without visible paging beyond its backend limit, and links to a retired evaluation-management route. Some pages represent empty or unavailable states. Such states are documented because users require understandable recovery paths as well as successful-result screens.

## 4. Design and Development of the System

### 4.1 Planning Stage

The supplied researcher draft describes an Agile approach and an April–October 2026 activity schedule. Chapter II transcribes that schedule as supplied planning material. It does not establish completed sprints, meetings, testing sessions or deployment milestones. The confirmed project title is LIGHT summarizer; the institution’s required title wording, program, adviser and approval records remain subject to researcher confirmation.

The implemented requirements can be reconstructed from source: multiple source types, selectable output settings, background jobs, result reuse, accounts, administrative facilities and controlled evaluation services. Constraints include document and request limits, local Python dependencies, database consistency, external translation/mail services, and unavailable human-evaluation records. This reconstruction documents the current design; it is not a retroactive assertion that a historical requirements meeting occurred.

### 4.2 Design Stage

#### A. Use Case Diagram

The only persisted account roles are user and admin. A guest is a temporary access context, and an evaluator is a user with an assignment, not a third account role. The use-case model separates browser users from maintenance operators and external processing services. Restrictions stated in the diagram apply to intended authorized paths; the document API’s reproduced authorization defect remains an exception requiring repair.
''']

def fig(label,path,caption,discussion):
    return f'![{caption}]({path})\n\nFigure {label}. {caption}\n\nFigure {label} illustrates {discussion}\n'

parts.append(fig('C3A','diagrams/use-cases.png','Implemented actors and major use cases.','guest, registered-user and administrator activities, together with maintenance and assigned-evaluation responsibilities. Guest access does not grant the authenticated Nutshell workflow. A maintenance operator is a deployment responsibility rather than a database account role. Source inspection supports the relationships; only the named test cases establish runtime observations.'))
parts.append('''#### B. Data Flow Diagram

The context model treats LIGHT summarizer as one process. Level 1 expands that process into account handling, source processing, result access, supporting output services, administration and evaluation. Detailed models then expand summary generation and authentication. Logical store identifiers retain their meaning across the levels. File storage and PHP session state are shown distinctly from declared database entities. External providers are included where the inspected code can contact them; their presence does not mean those integrations were exercised.
''')
for key,path,cap,desc in [
('C3B','dfd-context','Context diagram of LIGHT summarizer.','the boundary between people, the application, and external information or delivery services. Submitted sources and requests enter the application, while summaries, access decisions and administrative information return through application processes.'),
('C3C','dfd-level1','Level 1 data flows and logical stores.','the division of work among the major application processes. Accounts, generated records, feedback, telemetry, audit information and evaluation material have distinct storage responsibilities. Logical grouping is for explanation; the data dictionary provides the exact tables.'),
('C3D','dfd-level2-summary','Level 2 summary-generation data flow.','source validation and extraction, job creation and execution, Python processing, persistence and status/result response. The output passes through an application process before returning to a user. A worker response alone is not evidence that the PHP save step succeeded.'),
('C3E','dfd-level2-auth','Level 2 account and authentication data flow.','registration, credentials, administrator email-code handling, password recovery and session/token establishment. Session verification state belongs to the account store group; it is not represented as a nonexistent administrator-code table.')]:
    parts.append(fig(key,'diagrams/'+path+'.png',cap,desc))
parts.append('''The editable diagram sources and focused print panels accompany the manuscript. The models describe the inspected application contract, including optional services, while the issue register identifies places where implementation does not enforce the intended boundary. They do not add a student database, course-record system, messaging service, or research-participant role that the application does not contain.

#### C. Database Normalization

The available evidence is a current schema snapshot, not a documented historical sequence from unnormalized forms to third normal form. Accordingly, this section describes present design decisions and constraints. User accounts are separated from generated summaries. Source artifacts, jobs, ratings and comment events have separate stores. Evaluation datasets, documents, references, generated outputs and ratings are represented independently, allowing repeated observations without placing every detail in one wide record.

An illustrative dependency is that a user identifier determines that account’s stored attributes, while a summary identifier determines its generated-summary record. Separate job and artifact identifiers support asynchronous processing and supporting material. This explanation is based on declared keys and current code use; it does not prove that every semantic dependency has been exhaustively normalized. JSON/text payloads, caches, counters and snapshots deliberately retain compound or derived information, so a blanket claim that all tables are in third normal form would be unsupported.

The database declares thirty-five foreign keys. Several identifier-looking fields have no declared relationship: examples include documents.user_id, nutshell_generations.user_id and landing_page_sessions.user_id. Guest-token associations also depend on application logic. The summary_artifacts.summary_id column is not unique, so the physical schema permits more than one artifact per summary. These distinctions are preserved in the ERDs and dictionary rather than replaced with assumed one-to-one relationships.

#### D. Entity Relationship Diagram

The complete ERD was generated from the read-only MariaDB metadata snapshot. To keep the printed relationships legible, twelve focused panels repeat shared entities where necessary. Their combined coverage was mechanically checked against all thirty-one entities and thirty-five declared foreign keys. Each relationship uses the actual key and nullability information. A missing line is not evidence that two concepts are unrelated; it means the live schema does not enforce that relationship as a foreign key.
''')
panels=[('ACCOUNTS','Accounts and authentication'),('SUMMARY_OUTPUTS','Summary ownership, jobs and artifacts'),('FEEDBACK','Ratings and comments'),('DOCUMENT_CACHE','Document extraction and cached combinations'),('LANDING_TELEMETRY','Landing sessions and events'),('INDEPENDENT_STORES','Stores without declared foreign keys'),('EVALUATION_PIPELINE','Evaluation datasets, documents, runs and outputs'),('EVALUATION_CONTENT','References, content units and challenge cases'),('EVALUATION_SCORING','Assignments, ratings, errors and metrics'),('UNIT_REVIEWS','Content-unit reviews'),('EVALUATOR_ACCOUNTS','Evaluator and reviewer account relationships'),('RESEARCH_AUTHORSHIP','Dataset, run and reference authorship')]
for i,(suffix,title) in enumerate(panels,1):
    desc=f'the declared relationships in the {title.lower()} portion of the current database. Shared entities repeat across panels to preserve context. Primary and foreign keys are shown here; all columns and exact constraints appear in the data dictionary.'
    if i==6:desc='the current tables that have no declared foreign-key edges. Their identifiers and application-level associations must not be mistaken for enforced relational constraints. The panel intentionally contains no inferred relationship lines.'
    parts.append(fig('ERD'+str(i),f'diagrams/ERD_PANEL_{i:02}_{suffix}.png',title+' ERD panel.',desc))
parts.append('''#### E. Data Dictionary

The following tables document every column in the observed database: thirty-one tables and three hundred twelve fields. Types, lengths, nullability, defaults, keys and declared referential actions are metadata facts. Descriptions explain likely field purposes based on names and inspected service use; they do not imply that legacy fields are actively used. No application rows, passwords, tokens or personal records were extracted for this dictionary.

The live password_resets structure uses used_at, whereas the base installation SQL defines used and created_at. No inspected migration resolves that difference. Consequently, the dictionary represents the observed database, while a new installation requires a separate schema-reconciliation and rehearsal task. A successful connection to the current database would not establish that fresh installation is reliable.

<!-- INCLUDE tables/DATA_DICTIONARY.md -->

### 4.3 Development Stage

The application uses PHP-rendered pages with JavaScript and CSS, a MariaDB database, and Python language-processing services. It is organized around controllers, services and repositories rather than a verified external PHP application framework. The inspected configuration defaults to the legacy summary engine; the independent v2 engine and optional LLM synthesis must not be described as the enabled main algorithm. Translation may use external providers, and email verification requires a working delivery configuration.

Table C3B. Technologies and observed development environment.

| Component | Observed implementation | Verification boundary |
|---|---|---|
| Web application | PHP templates, controllers, services, repositories; JavaScript and CSS | Original source inspected; PHP/JS syntax checked |
| PHP runtime | XAMPP PHP 8.1.25 used for audit checks | Other configured installations may differ |
| Database | MariaDB 10.4.32; 31 tables, 312 columns, 35 foreign keys | Metadata read; application rows not read |
| Processing | Python legacy pipeline and optional v2 path | Existing tests ran using available Python 3.14 packages in isolation |
| Document/NLP facilities | pypdf, python-docx, text statistics, optional models and OCR | Resource availability varies; no model downloads performed |
| Audio/translation | Piper voice path; deep-translator/provider paths | Actual speech and live translation not validated |
| Local environment | Windows workspace under XAMPP htdocs | Public hosting, TLS and deployment readiness not verified |

Table C3B records observed technology separately from installation assumptions. In particular, the test interpreter is not proof that every optional dependency supports the same environment. Older setup claims of Python 3.8 conflict with current language/API usage; a tested installation matrix is required.

The screenshots below are original captures of this application’s templates. They were rendered with empty or synthetic records in a read-only harness that bypassed production startup. Each carries an audit label. They illustrate interface organization, controls and error states; they are not screenshots of genuine users, real study results, completed logins or saved production summaries. Appendix F supplies step-by-step use instructions and additional states.
''')
shots=[('landing','Landing information and navigation','the entry page and links to the summarizer and account pages. The text establishes the interface’s intended purpose, not a measured benefit.'),('summarizer','Source intake and summary settings','the source choices, title/focus inputs, eight analysis modes, six depths and five output organizations. Local selection tests do not verify all generation combinations.'),('sign-in','Sign-in form','credential and remembered-session controls. The synthetic render does not authenticate an account.'),('register','Registration form','the information requested for account creation. No account was created during this audit.'),('password-recovery','Recovery request form','the entry point for requesting a password-reset code. Email delivery and token consumption were not exercised.'),('password-reset','Password reset form','code and new-password inputs. The known schema and token-revocation limitations apply to this workflow.'),('admin-code-synthetic','Administrator code verification state','the additional emailed-code step for administrator access. The displayed state was injected in isolation and proves no email delivery.'),('terms-pending-synthetic','Terms acceptance state','the checkbox and acceptance control for an account with pending terms. Local enabling behavior passed; persistence was not submitted.'),('dashboard-empty','Personal dashboard empty state','the organization of recent work and account-related activity when the fixture contains no saved records. Its counters are not actual usage statistics.'),('history-empty','History empty state','the saved-work area and navigation. The backend history limit and absent paging controls remain known limitations.'),('analytics-empty','Analytics empty state','the presentation of activity filters and summaries. Synthetic empty values cannot be used as research data.'),('profile-synthetic','Account profile display','the account-information page and available account actions. The sample identity is synthetic, and profile editing is not an implemented page feature.'),('processing-synthetic','Background processing state','progress and recovery controls around a summary job. This fixture did not run a production job or establish successful persistence.'),('result-synthetic','Structured result and output controls','the result layout, evidence-related detail and copy, text export, print, audio, translation and feedback controls. The displayed text is synthetic rather than a generated evaluation output.'),('result-unavailable','Unavailable-result recovery state','the message shown when an accessible result cannot be supplied. This state is part of the user’s recovery path.'),('admin-dashboard-empty','Administrator overview','the arrangement of account, storage, feedback and activity administration. Empty synthetic records prevent disclosure of production data.'),('admin-log-filters-empty','Administrator audit-log filters','the filtering controls and log presentation. Missing programmatic labels were observed and require correction.'),('evaluation-empty','Assigned evaluation empty state','the reviewer view when no eligible assignment is supplied. No respondent rating or completed evaluation is represented.')]
for i,(name,title,desc) in enumerate(shots,1):parts.append(fig('UI'+str(i),f'screenshots/ui-{name}-desktop.png',title+' — isolated template capture.',desc))
parts.append('''### 4.4 Testing Stage

The audit used source inspection, syntax checks, read-only database metadata, isolated Python tests, synthetic controller fixtures and local browser rendering. Normal anonymous requests can create or update guest state and can trigger expiry cleanup. To preserve production data, live authenticated workflows, uploads, mutations, email delivery and background generation were therefore not performed. Three live requests were restricted to static health or redirects that do not load the application bootstrap, without following redirects.

Table C3C. Audit verification procedures and outcomes.

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

Table C3C reports individual evidence classes rather than combining unrelated observations into a misleading overall pass percentage. The full Python case register contains test identifiers, procedures/assertion names and outcomes; logs retain the initial permission-limited attempt and the confirmation run. The four skipped cases require a live translation result URL, which was not supplied. The test environment did not include every optional model resource.

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
''')
criteria=[('Functional Suitability','functional completeness, correctness and appropriateness','Whether the specified tasks produce appropriate results.'),('Performance Efficiency','time behaviour, resource utilisation and capacity','Whether representative tasks meet approved time and resource expectations.'),('Compatibility','co-existence and interoperability','Whether the application works alongside relevant software and exchanges supported data.'),('Usability','appropriateness recognisability, learnability, operability, user error protection, interface aesthetics and accessibility','Whether intended users can understand, operate and recover from the interface.'),('Reliability','maturity, availability, fault tolerance and recoverability','Whether the system behaves consistently and recovers under defined conditions.'),('Security','confidentiality, integrity, non-repudiation, accountability and authenticity','Whether authorized use and protection of information are adequately supported.'),('Portability','adaptability, installability and replaceability','Whether the application can be installed or adapted within a specified environment.')]
def pending_table(label,title,item):
    return f'''Table {label}. {title} — pending human evaluation.

| Item/domain | Valid responses | Weighted mean | Verbal interpretation |
|---|---|---|---|
| {item} | Pending | Pending | Pending approved scale and responses |

Table {label} is an unpopulated research template. No score, number of respondents or interpretation can be inferred from it. After approved administration, valid response frequencies and the stated analysis rule must support each value; audit findings remain separate evidence.
'''
for i,(name,sub,explain) in enumerate(criteria,1):
    parts.append(f'### {name}\n\n{explain} Relevant sub-characteristics for the proposed technical instrument are {sub}. The available audit can guide test tasks and item review, but it cannot provide a consultant’s rating.\n')
    parts.append(pending_table('IT'+str(i),name,'Approved items for '+name.lower()))
    parts.append('The result discussion remains pending because no completed responses are available. Before evaluation, the researcher should resolve or disclose the relevant known limitations, preserve the tested software version and document the conditions under which the task is performed.')
parts.append('### Overall IT Consultants’ Evaluation Summary\n\nAn overall result requires approved domain aggregation rules and genuine domain observations. Empty domains must not be treated as zero scores or silently excluded to create a favorable mean.')
parts.append(pending_table('IT8','Overall IT consultants’ summary','Seven approved consultant domains'))
parts.append('''## 6. End-Users’ System Evaluation

PENDING EVALUATION. The supplied material identifies intended academic users but does not provide completed end-user questionnaires, consent records, sample counts or task results. The proposed instrument uses ordinary language and keeps task suitability, perceived performance and usability separate. It cannot establish classroom learning gains without an appropriate additional research design.
''')
for i,(name,explain) in enumerate([('Functional Suitability','whether the available input, summary and result functions meet the participant’s specified reading task'),('Performance Efficiency','whether waiting and response behavior are acceptable for the agreed task and device'),('Usability','whether the participant can understand the steps, controls, messages and result presentation')],1):
    parts.append(f'### {name}\n\nThis domain asks {explain}. Responses must come from actual participants using the approved version and protocol.\n')
    parts.append(pending_table('EU'+str(i),name+' for end users','Approved end-user items for '+name.lower()))
    parts.append('The narrative result is pending. The interface captures and engineering test outcomes cannot be relabeled as satisfaction responses, and no comparison with an earlier manual process can be made from the available evidence.')
parts.append('### Overall End-Users’ Evaluation Summary\n\nThe proposed summary combines only the domains and item weights approved before data collection. Participants with missing or not-applicable answers must be handled transparently under that rule.')
parts.append(pending_table('EU4','Overall end-users’ summary','Three approved end-user domains'))
write(MAN/'CHAPTER_3.md','\n\n'.join(parts))

# Make the live-schema dictionary an includable subsection with one global caption per entity.
p=MAN/'tables/DATA_DICTIONARY.md';d=read(p)
d=d.replace('# Data dictionary — verified live schema','##### Data dictionary — verified live schema')
d=d.replace('Tables are presented alphabetically. Chapter III supplies the academic table caption; subsection keys here are stable dictionary identifiers.','Tables are presented alphabetically. Each table has a manuscript caption and a stable dictionary identifier.')
d=re.sub(r'^## DD-(\d+)\. (.+)$',r'##### DD-\1. \2',d,flags=re.M)
chunks=re.split(r'(?=^##### DD-\d+\.)',d,flags=re.M)
for i in range(1,len(chunks)):
    number,name=re.search(r'^##### DD-(\d+)\. (.+)',chunks[i]).groups()
    if not re.search(r'^Table ',chunks[i],re.M):
        chunks[i]=chunks[i].replace('| Field Name |',f'Table DD{number}. Data dictionary for {name}.\n\n| Field Name |',1)
        chunks[i]+=f'\nTable DD{number} records the observed columns and constraints for {name}. The descriptions explain their storage purpose; runtime behavior remains subject to the separately stated verification limits.\n\n'
write(p,''.join(chunks))
write(MAN/'CHAPTER_4.md','''# CHAPTER IV — SUMMARY, CONCLUSIONS AND RECOMMENDATIONS

## 1. Summary

LIGHT summarizer is an implemented PHP and Python web application intended to support article and document summarization. The researchers’ draft places its intended academic use at NEUST Peñaranda Off-Campus and describes an Agile approach with an April–October 2026 schedule. The audit does not establish that the planned activities, locale-based evaluation or institutional approvals were completed. This distinction limits the conclusions that can be drawn from the documentation.

In relation to the proposed questions about users’ difficulties, the supplied draft identifies extensive reading, selection of main ideas and preservation of important details as motivations. No interview dataset, baseline observation or respondent frequency distribution was available. The needs therefore remain researcher-supplied problem statements rather than independently measured research findings.

In relation to system development and required functionality, the audit documented sixty-nine distinct capabilities. These include supported source intake, configurable summarization, job processing, result controls, history, accounts, administration, evaluation services and maintenance facilities. Five narrowly scoped capabilities were verified working; eighteen have supported limitations and are classified partially working; forty-six are implemented but remain unverified end-to-end. These counts use a documented capability grouping and do not imply that every source method or output combination is a separate feature.

The observed database contains thirty-one tables, three hundred twelve columns and thirty-five declared foreign keys. The diagrams and data dictionary reflect those actual constraints. Source inspection also identified differences between the current reset-token table and the clean-install SQL, so the observed database should not be confused with a successfully rehearsed installation process.

In relation to technical verification, one hundred fifty PHP/JavaScript files passed syntax checks. The confirmed isolated Python run produced 595 passed, one failed and four skipped test cases, with sixty-two passed subtests. Eighteen local browser assertions, nineteen isolated anonymous-guard expectations and three live no-bootstrap response checks matched expectations. Forty-six original template captures document twenty-three desktop/mobile interface states. Those captures contain synthetic or empty data, and no completed login or production generation workflow is inferred from them.

Important limitations were also demonstrated. The document API returned source content to an unauthorized owner context and to an anonymous context in actual controller/service fixtures. A legacy cleaning rule removed a meaningful paragraph containing the ordinary word register. An audio suffix-range request returned the entire file, and an optional v2 regression omitted an expected definition. Additional source-supported concerns involve reset-token persistence, administrator-status safeguards, metrics persistence, guest cleanup coverage and deployment configuration.

In relation to consultant and end-user evaluation, no genuine completed questionnaires or approved sample records were supplied. The evaluation instruments and result tables are proposed or pending. The audit therefore provides no weighted means, acceptance ratings, measured time savings, learning improvements or certification claim.

## 2. Conclusions

The available implementation demonstrates a substantial software foundation for source-based summarization and supporting account and administrative activities. Its capability and schema documentation can be traced to actual files, method inventories and read-only metadata. The narrow tests establish specific behavior within their recorded boundaries; they do not establish complete operational reliability or quality for the intended student population.

The current evidence does not support describing the application as uniformly secure, fully verified, production-ready or validated by consultants and end users. Reproduced authorization and processing defects require attention before evaluation or wider deployment. The optional v2 path should also remain distinct from the configured legacy engine when reporting results.

The research conclusions concerning user needs, effectiveness and acceptance remain pending. A supplied methodology narrative and schedule can support planning documentation, but they cannot substitute for actual development records, approved participant procedures or collected responses. The manuscript is consequently an evidence-grounded documentation foundation with explicit research-completion requirements.

## 3. Recommendations

The first implementation priority is to enforce authentication and ownership across every document API action, including retrieval and cached combinations, and to apply suitable CSRF and request controls. Regression checks should demonstrate denial for anonymous and cross-owner contexts while preserving authorized access. Existing reset sessions and remembered tokens should be addressed when passwords change, and all administrator status-changing routes should apply consistent self/last-administrator safeguards and audit recording.

The processing team should replace whole-paragraph navigation-word filtering with a context-sensitive rule that preserves ordinary prose, then retain the reproduced library fixture as a regression case. The optional v2 inline-heading failure should be corrected and reviewed against representative documents. Audio range handling and cache visibility should be corrected, and real audio generation should be tested with the intended voice and language configuration.

Installation and data integrity should be addressed in a disposable staging environment. The reset-token schema should be reconciled with checked-in migrations, the supported runtime/dependency matrix should be established, and a clean install should be rehearsed. Upload source counts and elapsed-time persistence should be verified before relying on analytics. Guest lifecycle coverage and cleanup should be reviewed across all stores, and upload deletion should check references rather than infer orphan status solely from age.

A complete authenticated test deployment should then cover registration, code delivery, password reset, terms, source submission, jobs, retries, cancellation, saved result access, feedback, account administration, exports and evaluator assignments. Tests should include actual supported files, realistic documents and unavailable-service cases without using production personal data. Accessibility review should address keyboard access and labels and should include assistive-technology testing. Representative performance and recovery drills should be designed around explicit acceptance criteria.

The researchers should confirm the institution, program, title wording, adviser, locale, development records, participant groups, sampling procedure and approved evaluation scale. The proposed research questions and instruments should be reviewed before administration. Any genuine data should retain its provenance, consent and anonymization arrangements. Weighted means and conclusions should be calculated only after validated responses are supplied, with missing and not-applicable observations handled under a declared rule.

Before final submission, institutional reviewers should approve the adapted reference formatting and research sections, replace all explicit placeholders, verify current literature and citations, and review the regenerated Word and PDF files. Before deployment, maintainers should resolve the priority defects, verify production configuration and rehearse backup and restoration. These are future actions grounded in observed gaps, not claims that the activities have already occurred.
''')
print('Results chapters and dictionary captions written.')
