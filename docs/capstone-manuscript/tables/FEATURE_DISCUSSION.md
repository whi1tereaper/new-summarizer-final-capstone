## 3. Essential Features and Functionalities

The following inventory describes each distinct capability in language intended for readers who are not software developers. Each identifier corresponds to the full evidence, file, function, input and database record in FEATURE_INVENTORY.csv. “Implemented but not verified” means implementation was inspected without completing its operational workflow. “Partially working” identifies an implemented facility with a supported limitation. “Verified working” applies only to the narrow action expressly tested. Benefits describe intended use rather than measured learning or productivity gains.

### Entry

**F001. Landing information and navigation.** The feature allows visitor to learn the purpose and open the summarizer or account forms. The intended output is Landing sections; target page. It connects with navigation and result presentation.

The interaction proceeds as follows: open the landing page; then read about light and use start/summarize; then use footer terms or navigation account links. Inputs are Navigation links; scroll. Behind the interface, the implementation performs Render project information and navigation links.

The audit classification is implemented but not verified. Admin landing requests redirect; footer social links lead to generic social-platform homepages, not verified project accounts. Evidence is recorded under F001 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Navigation

**F002. Responsive mobile menu.** The feature allows visitor to open and close site navigation on small screens. The intended output is Expanded navigation with aria-expanded state. It connects with navigation and result presentation.

The interaction proceeds as follows: press the menu button; then choose a link or press escape. Inputs are Menu button; Escape. Behind the interface, the implementation performs Toggle menu visibility and aria-expanded; close on Escape.

The audit classification is verified working. Opening and Escape verified at 390px; complete keyboard/screen-reader audit not performed. Evidence is recorded under F002 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Appearance

**F003. Light/dark theme preference.** The feature allows visitor to switch the reading theme. The intended output is Light or dark document data-theme. It connects with navigation and result presentation.

The interaction proceeds as follows: use the theme control; then  revisit a supported page to reuse preference. Inputs are Theme button; saved localStorage preference. Behind the interface, the implementation performs Toggle supported page theme and retain browser preference.

The audit classification is verified working. Source inspection; theme persists locally, not as a saved account preference. Evidence is recorded under F003 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Accounts

**F004. Create account.** The feature allows visitor to register with an email and password. The intended output is Validation feedback or account/session redirect. It connects with account registration.

The interaction proceeds as follows: open create account; then enter the required fields; then review and accept terms; then submit create account. Inputs are Username; email; password; confirmation; terms acceptance; CSRF. Behind the interface, the implementation performs Validate account inputs and password policy; check uniqueness; create the account.

The audit classification is implemented but not verified. No account was created. Registration form copy mentions custom profiles; dedicated editable saved-profile UI was not found. Evidence is recorded under F004 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F005. Sign in and remember session.** The feature allows registered user to sign in to account features. The intended output is Session or validation error. It connects with login and logout; remembered login.

The interaction proceeds as follows: enter your username/email and password; then choose remember-me only on a trusted device if desired; then submit sign-in. Inputs are Username or email; password; optional remember-me; CSRF. Behind the interface, the implementation performs Check credentials and status; regenerate session; optionally issue or rotate a remembered user token.

The audit classification is implemented but not verified. Credential and remember-cookie flows not exercised. Evidence is recorded under F005 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F006. Administrator email code verification.** The feature allows administrator to complete the additional administrator login step. The intended output is Verified login or code error. It connects with administrator email otp.

The interaction proceeds as follows: sign in with administrator credentials; then enter the emailed one-time code; then submit verification or cancel to sign in again. Inputs are Pending admin session; emailed code; CSRF. Behind the interface, the implementation performs Create pending administrator-code session state; send and verify time-limited code; rate-limit attempts; record security activity.

The audit classification is implemented but not verified. Source-supported conditional UI only; no email sent; retired challenge routes are redirects, not an active challenge feature. Evidence is recorded under F006 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F007. Password recovery.** The feature allows account holder to request a password-reset code. The intended output is Generic recovery outcome and reset flow. It connects with password reset.

The interaction proceeds as follows: open recover password; then enter email; then choose send otp and follow the response. Inputs are Email; CSRF. Behind the interface, the implementation performs Locate eligible account; issue a time-limited reset code; request email delivery.

The audit classification is partially working. Recovery form rendered; sending, delivery and account existence outcomes not tested. Reset-token storage differs between the live database and clean-install SQL; email delivery was not exercised. Evidence is recorded under F007 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F008. Reset password using OTP.** The feature allows account holder to replace a forgotten password after verification. The intended output is Reset success or validation/code error. It connects with password reset.

The interaction proceeds as follows: open reset form after requesting a code; then enter email and code; then enter matching new passwords; then submit reset. Inputs are Email; OTP; new password; confirmation; CSRF. Behind the interface, the implementation performs Validate reset code and new password; replace password hash and consume reset token.

The audit classification is partially working. No password changed. A password change does not revoke existing remembered tokens or active sessions; the fresh-install reset schema also differs. Evidence is recorded under F008 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F009. Password visibility and strength hints.** The feature allows visitor to review password entry before submitting. The intended output is Plain/masked password; client strength/match hints. It connects with registration and password reset.

The interaction proceeds as follows: enter a password; then use eye button to toggle visibility; then read available strength/mismatch hint. Inputs are Password input and visibility button. Behind the interface, the implementation performs Toggle the password input masking and display local password-strength hints.

The audit classification is partially working. Click toggle verified on registration; eye buttons tabindex=-1 excludes keyboard Tab navigation. The visibility control responds to clicks but uses tabindex=-1, excluding normal keyboard tab access. Evidence is recorded under F009 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Terms

**F010. Read and accept terms.** The feature allows visitor or registered user to review usage conditions and continue after agreement. The intended output is Session/account acceptance or exit/login redirect. It connects with terms acceptance.

The interaction proceeds as follows: open terms and conditions; then read the sections; then check agreement and confirm; choose decline if not agreeing. Inputs are Acceptance checkbox; accept/decline action; CSRF. Behind the interface, the implementation performs Display the current terms and record an authenticated acceptance.

The audit classification is partially working. Reading rendered; acceptance/decline not executed; two terms pages have different guest actions. The decline link does not submit the server-side decline action; terms enforcement also depends on the route allowlist. Evidence is recorded under F010 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Summary input

**F011. Upload PDF or DOCX.** The feature allows guest or registered user to supply a supported local document. The intended output is Selected filename or input error. It connects with input and preference normalization; upload acceptance.

The interaction proceeds as follows: choose upload file; then browse or drag a file; then use replace/remove to correct the selection. Inputs are One PDF/DOCX; optional title. Behind the interface, the implementation performs Select PDF/DOCX; on submission validate multipart origin, extension, MIME, signature and package structure; store a randomized upload file.

The audit classification is implemented but not verified. No file uploaded. Effective limit depends on PHP/config; renderer displayed 2.0 MiB for its PHP environment. Evidence is recorded under F011 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F012. Paste text source.** The feature allows guest or registered user to summarize readable pasted text. The intended output is Text selected as source. It connects with input and preference normalization.

The interaction proceeds as follows: choose paste text; then paste permitted document text; then review source and settings. Inputs are Text up to server-provided byte cap. Behind the interface, the implementation performs Accept pasted text; on submission normalize and validate source length.

The audit classification is implemented but not verified. Source-selector interaction verified; extraction/model processing not run. Evidence is recorded under F012 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F013. Article URL source.** The feature allows guest or registered user to request summarization of an accessible article URL. The intended output is URL source or validation error. It connects with input and preference normalization; url acquisition.

The interaction proceeds as follows: choose url; then paste the complete public address; then submit when source/settings are ready. Inputs are HTTP(S) URL without embedded credentials. Behind the interface, the implementation performs Accept an HTTP(S) article address; validate destination and redirects; acquire bounded source content.

The audit classification is implemented but not verified. Selection and invalid-empty-URL rejection verified locally; fetching and safety policy not exercised. Evidence is recorded under F013 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Summary controls

**F014. Optional document title and focus.** The feature allows guest or registered user to name a result and prioritize a topic/question. The intended output is Selected title/focus in request. It connects with input and preference normalization.

The interaction proceeds as follows: optionally enter document title; then optionally enter focus / topic / question. Inputs are Optional title; optional focus text (UI maxlength 300). Behind the interface, the implementation performs Accept optional title and focus and validate their length for the processing request.

The audit classification is implemented but not verified. Focus changes requested prioritization; cannot promise answer accuracy. Evidence is recorded under F014 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F015. Five output organizations.** The feature allows guest or registered user to choose paragraph, bullets, hybrid, structured sections or key takeaways. The intended output is Updated description and request setting. It connects with input and preference normalization.

The interaction proceeds as follows: open output format; then choose one format and read its description. Inputs are output_format select. Behind the interface, the implementation performs Select one of five output organizations and pass its validated value to generation.

The audit classification is implemented but not verified. Structured selection verified; output quality not evaluated. Evidence is recorded under F015 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F016. Eight analysis modes.** The feature allows guest or registered user to choose general, academic, executive, technical, study, news, legal or scientific emphasis. The intended output is Updated mode description and request setting. It connects with input and preference normalization.

The interaction proceeds as follows: open analysis mode; then choose the intended emphasis. Inputs are analysis_mode select. Behind the interface, the implementation performs Select one of eight analysis modes and pass its validated value to generation.

The audit classification is implemented but not verified. Academic selection verified; modes do not establish domain correctness. Evidence is recorded under F016 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F017. Six summary depths.** The feature allows guest or registered user to choose very brief, brief, short, balanced, detailed or comprehensive depth. The intended output is aria-pressed and hidden depth fields update. It connects with input and preference normalization.

The interaction proceeds as follows: select a summary depth button; then read the depth explanation. Inputs are Depth button. Behind the interface, the implementation performs Select one of six main-interface depths and pass its validated value to generation.

The audit classification is implemented but not verified. Comprehensive selection verified; exact word length is not guaranteed. Evidence is recorded under F017 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F018. Optional evidence-controlled synthesis.** The feature allows guest or registered user when configured to opt in to configured language-model synthesis. The intended output is Requested synthesis path or extractive fallback behavior. It connects with input and preference normalization; python integration.

The interaction proceeds as follows: when generation method is displayed, read the data-transfer notice; then opt in only when authorized to process the source there. Inputs are Conditional use_llm_synthesis checkbox. Behind the interface, the implementation performs Request optional synthesis only when both request flag and server configuration permit the configured provider.

The audit classification is implemented but not verified. Conditional source only; flag disabled in synthetic renderer; external synthesis not invoked. Evidence is recorded under F018 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Summarization

**F019. Submit summary request.** The feature allows guest or registered user to start processing a selected source. The intended output is Processing redirect or recoverable error. It connects with input and preference normalization; recoverable summary jobs; python integration; summary persistence.

The interaction proceeds as follows: review source and settings; then choose summarize document; then follow processing/status feedback. Inputs are Source; title; focus; format; mode; depth; CSRF; request token. Behind the interface, the implementation performs Validate request; resolve source; create or reuse a job; dispatch processing and persist successful output.

The audit classification is partially working. Submission not executed; all harness POSTs blocked. The legacy cleaner can discard legitimate prose containing register. Upload word-count and processing-time persistence are incomplete. Evidence is recorded under F019 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Recovery

**F020. Source validation and one-hour draft recovery.** The feature allows guest or registered user to correct invalid input and recover tab-local source/settings. The intended output is Friendly explanation, recovery action and restored settings. It connects with input and preference normalization.

The interaction proceeds as follows: correct the named field; then use retry only where offered; then for file recovery, select the file again. Inputs are Input errors; saved sessionStorage fields. Behind the interface, the implementation performs Validate browser inputs; display catalogued errors; retain and recover an eligible one-hour browser draft.

The audit classification is implemented but not verified. Empty URL validation verified; file bytes cannot be restored; source text is retained in browser tab storage for up to one hour. Evidence is recorded under F020 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Processing

**F021. Job progress, retry and cancel.** The feature allows visitor with pending job to follow an asynchronous summary and recover from failure. The intended output is Status, completion redirect or recovery message. It connects with recoverable summary jobs.

The interaction proceeds as follows: remain on the processing page; then if offered, check connection/status or retry; then use cancel when available to return to settings. Inputs are Existing job/session; retry or cancel controls. Behind the interface, the implementation performs Resolve owned job; inspect or update state; execute, retry or cancel through guarded actions.

The audit classification is implemented but not verified. Only synthetic pending page rendered; automatic execute/poll requests deliberately blocked. Evidence is recorded under F021 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Nutshell

**F022. Generate and copy concise nutshell.** The feature allows registered active account to produce a short standalone rendition from an eligible source. The intended output is Nutshell text, not-needed notice or recovery error. It connects with python integration; usage analytics.

The interaction proceeds as follows: prepare a source; then choose generate nutshell; then read the panel and use copy after success. Inputs are Selected source/mode; CSRF; idempotency key. Behind the interface, the implementation performs Require a registered account; request concise generation; record successful Nutshell activity and expose copy controls.

The audit classification is implemented but not verified. Guest redirect verified. Generation not run. Backend supports six mode labels; legal/scientific selections are normalized to general by this endpoint. The synthetic direct call returned insufficient_content with an empty nutshell; it did not demonstrate a successful concise summary. Web saving was not run. Evidence is recorded under F022 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Results

**F023. Read structured summary and details.** The feature allows authorized owner or shared-link viewer to read the summary and available metadata. The intended output is Summary, key points, keywords, section analysis and details when stored. It connects with history and sharing; structured display.

The interaction proceeds as follows: open an accessible result; then read the primary summary; then expand available source/details sections. Inputs are Authorized summary id; optional share token. Behind the interface, the implementation performs Resolve owner, guest or bearer access; load summary/artifact and render supported result sections.

The audit classification is implemented but not verified. Synthetic prose rendered; optional rich sections depend on saved output and were not all populated. Evidence is recorded under F023 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F024. Inspect source and evidence passages.** The feature allows authorized result viewer to compare output with retained source passages. The intended output is Source text, evidence/coverage blocks where stored. It connects with history and sharing; structured display.

The interaction proceeds as follows: choose check source; then expand original text; then follow numbered evidence references if present. Inputs are Source disclosure and evidence anchor clicks. Behind the interface, the implementation performs Display available source text and evidence metadata from an accessible result.

The audit classification is implemented but not verified. Source-backed evidence links do not prove factual accuracy; populated metadata not end-to-end tested. Evidence is recorded under F024 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F025. Copy summary.** The feature allows authorized result viewer to copy the displayed summary. The intended output is Clipboard text and status. It connects with navigation and result presentation.

The interaction proceeds as follows: open a completed result; then choose copy. Inputs are Copy button. Behind the interface, the implementation performs Copy the rendered summary text to the browser clipboard.

The audit classification is verified working. Clipboard success verified on loopback with explicit browser permission and synthetic content; other browser permission contexts untested. Evidence is recorded under F025 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F026. Print or save PDF.** The feature allows authorized result viewer to open browser print output for a completed summary. The intended output is Printed output or browser Save as PDF. It connects with navigation and result presentation.

The interaction proceeds as follows: choose pdf; then review the browser print preview; then select a printer or save as pdf. Inputs are PDF button; browser print dialog. Behind the interface, the implementation performs Invoke browser printing with the result print stylesheet; PDF saving depends on browser options.

The audit classification is implemented but not verified. Uses window.print(), not a server-generated PDF endpoint; print dialog not exercised. Evidence is recorded under F026 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F027. Download text.** The feature allows authorized result viewer to save summary text locally. The intended output is Client-generated .txt file. It connects with navigation and result presentation.

The interaction proceeds as follows: choose text export; then save the generated download. Inputs are Text export button. Behind the interface, the implementation performs Create a local text download containing the rendered summary.

The audit classification is verified working. Synthetic result exported to evidence/synthetic-summary-download.txt and exact text checked; no real summary exported. Evidence is recorded under F027 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F028. Listen to summary audio.** The feature allows authorized result viewer with session to request and listen to generated speech. The intended output is Audio player/status or service error. It connects with audio generation and delivery.

The interaction proceeds as follows: choose listen on a completed summary; then use audio controls after successful generation. Inputs are Summary id; share token if applicable; CSRF. Behind the interface, the implementation performs Validate permitted audio request; normalize text; generate or reuse Piper output; serve token-controlled file with byte-range handling.

The audit classification is partially working. Local worker availability required; no audio generated or fetched. The isolated suffix-range request returned the whole file; audio delivery declares public cacheability. Real Piper synthesis and listening were not performed. Evidence is recorded under F028 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F029. Filipino translation.** The feature allows session-bearing result viewer to request a Filipino rendition of displayed summary text. The intended output is Translation panel or error. It connects with python integration.

The interaction proceeds as follows: choose translate on a completed result; then read returned filipino translation with original as reference. Inputs are Text; target language tl; CSRF. Behind the interface, the implementation performs Validate translation request; call configured translation provider through Python; return translated text.

The audit classification is implemented but not verified. Worker not invoked. Endpoint accepts en/tl; UI requests Filipino. Evidence is recorded under F029 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Feedback

**F030. Rate, comment and update feedback.** The feature allows guest/user with summary access to submit a usefulness rating with optional reasons/comment. The intended output is Saved/updated feedback confirmation or error. It connects with rating and comments.

The interaction proceeds as follows: choose a star rating; then optionally add reasons and a short comment; then submit feedback; use update feedback for an existing response. Inputs are 1–5 stars; optional reasons; comment up to 500 UI characters; CSRF. Behind the interface, the implementation performs Validate one-to-five rating and permitted summary context; update latest rating and append comment events.

The audit classification is implemented but not verified. No rating submitted; anonymous presentation does not mean no user/guest identifier is stored. Evidence is recorded under F030 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Results

**F031. Unavailable result recovery.** The feature allows visitor to receive a safe unavailable-result message. The intended output is 404 recovery view or missing-id redirect. It connects with history and sharing.

The interaction proceeds as follows: review the unavailable message; then use back to summary settings or obtain an authorized link. Inputs are Missing, unauthorized or inaccessible result id. Behind the interface, the implementation performs Resolve requested result; render unavailable or recovery view when it cannot be returned.

The audit classification is implemented but not verified. 404 template verified with synthetic absent id; live authorization not tested. Evidence is recorded under F031 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Dashboard

**F032. Personal dashboard and recent summaries.** The feature allows registered non-admin to return to recent work and inspect personal activity. The intended output is Recent five summaries and aggregate/empty dashboard. It connects with personal summary/nutshell history and activity; administrator scope where stated.

The interaction proceeds as follows: open dashboard after signing in; then choose an available recent result or start a summary. Inputs are Account session. Behind the interface, the implementation performs Read recent summaries and Nutshell activity; calculate account dashboard counters and display recent work.

The audit classification is implemented but not verified. Empty state rendered; no live account statistics read. Evidence is recorded under F032 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F033. Reuse inferred preferred settings.** The feature allows registered non-admin to reuse settings inferred from previous completed summaries. The intended output is Validated mode/format/depth preselection. It connects with personal summary/nutshell history and activity; administrator scope where stated.

The interaction proceeds as follows: if preferred settings are shown, choose the reuse link; then review the resulting controls before submitting. Inputs are Preferred-settings link. Behind the interface, the implementation performs Infer frequently used settings from the account’s saved summary/artifact history and expose reuse controls.

The audit classification is implemented but not verified. No separate editable custom-profile storage interface found; history-derived preference is conditional. Evidence is recorded under F033 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### History

**F034. Browse summary and nutshell history.** The feature allows registered user or admin to reopen retained results and read prior nutshells. The intended output is History previews and full-report links. It connects with personal summary/nutshell history and activity; administrator scope where stated.

The interaction proceeds as follows: open history; then choose a title or access full report; then read nutshell history when available. Inputs are Account; optional page query. Behind the interface, the implementation performs Read the requested history page and list summary and Nutshell records for the current context.

The audit classification is partially working. The history page displays 10 summaries per page by default (service cap 50) and 20 Nutshell records; no visible history pagination controls are supplied. Saved history was not exercised against production records. Evidence is recorded under F034 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Analytics

**F035. Personal/system activity and date filters.** The feature allows registered user or admin to inspect activity over a chosen period. The intended output is Counts, reduction, charts, performance and recent activity. It connects with personal summary/nutshell history and activity; administrator scope where stated.

The interaction proceeds as follows: open analytics through an available dashboard link; then choose a preset or from/to dates; then apply filter and review available charts. Inputs are 7d/30d/3m/year/custom dates; role. Behind the interface, the implementation performs Apply date bounds and role context to summary/Nutshell activity counts, distributions and trends.

The audit classification is partially working. Empty state rendered; populated charts not validated; admin scope differs from personal scope. Stored upload source counts and elapsed times can be incomplete, affecting derived activity measures. Evidence is recorded under F035 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Profile

**F036. View account details.** The feature allows registered account to view username, email and join date. The intended output is Read-only identity card. It connects with account display, self-deactivation and logout.

The interaction proceeds as follows: open profile; then review account details; then use sidebar to return to dashboard or history. Inputs are Authenticated account. Behind the interface, the implementation performs Find the current account profile and render stored account details.

The audit classification is implemented but not verified. Synthetic identity rendered; no edit username/email/photo form exists. Evidence is recorded under F036 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F037. Deactivate own account.** The feature allows registered account subject to backend protections to deactivate account after confirming password. The intended output is Inactive account and sign-out; summaries retained. It connects with login and logout; account administration.

The interaction proceeds as follows: read the deactivation description; then enter current password; then submit only when deactivation is intended. Inputs are Current password; CSRF. Behind the interface, the implementation performs Validate authenticated account action; deactivate the account and end applicable access.

The audit classification is implemented but not verified. Not executed; backend has administrator protections; source retention is not hard deletion. Evidence is recorded under F037 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Accounts

**F038. Logout confirmation.** The feature allows signed-in account to confirm leaving the active session. The intended output is Cancelled modal or logout redirect. It connects with login and logout; remembered login.

The interaction proceeds as follows: choose logout; then choose cancel to remain or log out to leave. Inputs are Logout link; Cancel/Log out. Behind the interface, the implementation performs Confirm logout in the browser; clear session and applicable remembered-login state.

The audit classification is implemented but not verified. Escape closed modal on desktop/mobile; actual logout not executed; GET logout target remains source-supported only. Evidence is recorded under F038 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Administration

**F039. System overview and storage counters.** The feature allows administrator to inspect aggregate system and upload counters. The intended output is User/summary/guest/rating counts; storage size/count. It connects with usage analytics; storage administration.

The interaction proceeds as follows: open admin; then choose a statistics period; then review overview and storage cards. Inputs are Stats-period select. Behind the interface, the implementation performs Read aggregate system counters and inspect upload storage totals for the administrator.

The audit classification is implemented but not verified. All captured zeros are synthetic; cleanup/delete-file dispatcher actions exist but visible maintenance buttons were not found. Evidence is recorded under F039 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F040. Search and filter users.** The feature allows administrator to filter the displayed account table. The intended output is Visible rows and count. It connects with account administration.

The interaction proceeds as follows: enter username/email/id text; then choose role and status; then use clear filters to reset. Inputs are Search text; role; active-status filters. Behind the interface, the implementation performs Retrieve and filter the administrator’s account list by supplied search/status criteria.

The audit classification is implemented but not verified. Client-side filter over loaded rows; fixture had no users, so nonempty match behavior not browser-tested. Evidence is recorded under F040 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F041. Deactivate managed user.** The feature allows administrator to disable an eligible user account. The intended output is Account deactivation or error. It connects with account administration.

The interaction proceeds as follows: find the intended account; then read the confirmation on deactivate; then confirm only when intended. Inputs are User id; CSRF; confirmation. Behind the interface, the implementation performs Validate administrator status-changing request and update target account status; dedicated and alternate paths have different safeguards.

The audit classification is partially working. Destructive/stateful action source-inspected only; not executed. The alternate toggle-status action bypasses protections present in the dedicated deactivation method. Evidence is recorded under F041 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F042. Permanently delete managed user.** The feature allows administrator to remove an eligible account and associated data. The intended output is Deletion or protected-account/error result. It connects with account administration.

The interaction proceeds as follows: find and verify the intended account; then read the explicit permanent-deletion warning; then confirm only under approved administration procedures. Inputs are User id; CSRF; confirmation. Behind the interface, the implementation performs Validate authorized hard-delete action; remove target account and process related cleanup according to source and constraints.

The audit classification is implemented but not verified. Not executed; deletion is distinct from deactivation and may remove linked data. Evidence is recorded under F042 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F043. Filter feedback and quality telemetry.** The feature allows administrator to review anonymous-presented feedback and summary setting patterns. The intended output is Feedback/comment tables; mode/depth/reason aggregates. It connects with rating and comments.

The interaction proceeds as follows: review quality telemetry; then filter rating/comment state and sort order; then open a result link where authorized. Inputs are Rating filter; has-comment filter; sort. Behind the interface, the implementation performs Filter and aggregate feedback and retrieve related summary information for administrator display.

The audit classification is implemented but not verified. Empty fixture only; underlying records are linkable internally despite anonymous display. Evidence is recorded under F043 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F044. Landing telemetry dashboard.** The feature allows administrator to inspect landing engagement metrics. The intended output is Session, CTA, dwell, LCP, device, scroll, trend and referrer panels. It connects with usage analytics.

The interaction proceeds as follows: scroll to landing page analytics; then read labels and applicable date periods; then interpret missing values as unavailable. Inputs are Stored landing sessions/events. Behind the interface, the implementation performs Collect permitted landing session/event telemetry and aggregate visit/conversion/device/referrer indicators.

The audit classification is implemented but not verified. No telemetry emitted; the landing view itself normally sends beacons; counters are not human survey evaluation. Evidence is recorded under F044 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F045. Audit log viewer.** The feature allows administrator to review privileged action logs. The intended output is Filtered log rows and pagination. It connects with administrative action accountability.

The interaction proceeds as follows: open audit logs; then choose filters and submit; then use pagination when present. Inputs are Action/admin/date filters; page. Behind the interface, the implementation performs Require administrator access; filter audit records and join actor identity for display.

The audit classification is implemented but not verified. Synthetic missing-table empty state captured; real audit log contents not read. Evidence is recorded under F045 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F046. Database health JSON.** The feature allows administrator to check required-table/column presence. The intended output is Status JSON and development-dependent detail. It connects with navigation and result presentation.

The interaction proceeds as follows: open the diagnostic endpoint under administrator access and interpret status fields. Inputs are Authenticated administrator session. Behind the interface, the implementation performs Require administrator context; query database metadata and return health information.

The audit classification is implemented but not verified. Anonymous isolated 403 verified; authenticated database health not checked against live schema. Evidence is recorded under F046 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Evaluation

**F047. Assigned independent summary review.** The feature allows active account with assignment to read an assigned source/summary and score it independently. The intended output is Submitted-and-locked assessment or error. It connects with blind evaluation assignments.

The interaction proceeds as follows: open the assigned review link while signed in; then read source and blinded summary; then score each criterion separately; then submit once; contact administrator for corrections. Inputs are Assignment token; seven 1–5 criteria; optional comment; CSRF. Behind the interface, the implementation performs Check current assignment access; display blinded output and accept the required review criteria when submitted.

The audit classification is implemented but not verified. Empty/denied UI captured; no assignment or rating created; this 1–5 content rubric is not the capstone ISO usability instrument. Evidence is recorded under F047 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F048. Evaluation export and report rendering.** The feature allows administrator to export an existing evaluation run. The intended output is Download or HTML report. It connects with evaluation reports and export.

The interaction proceeds as follows: use an existing approved run identifier; then  request the selected format; then  protect exported records. Inputs are Positive run id; json/csv/html format. Behind the interface, the implementation performs Require administrator access; build available evaluation report/export with supported identifier removal and spreadsheet safeguards.

The audit classification is partially working. No real run exported; only anonymous 403 tested. evaluation.php now redirects, so legacy management tabs are not operational entry points. Export code exists, but links to the retired evaluation-management page redirect; no real evaluation report was generated. Evidence is recorded under F048 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### API-only

**F049. Document Q&A endpoint.** The feature allows authorized summary viewer to request source-grounded answers through the API. The intended output is Answer/passage/confidence JSON or error. It connects with python integration; history and sharing.

The interaction proceeds as follows: use an authorized api client; then  provide an accessible source summary and question; then  inspect source evidence. Inputs are POST JSON summary_id, question, optional share_token; no CSRF check found in this endpoint. Behind the interface, the implementation performs Accept a source-related question; obtain permitted source context; rank passages and return a grounded answer or unavailable result.

The audit classification is implemented but not verified. No Q&A interaction widget found in current result.js/result.php. Do not advertise a visible chat feature. The API-only endpoint has no main-interface chat widget; a source-grounded Python answer passed in isolation. The route lacks a CSRF check and may disclose raw exception detail. Evidence is recorded under F049 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F050. Document and multi-document REST operations.** The feature allows Intended document caller; dispatcher does not enforce authentication or ownership to access document operations through routed API. The intended output is Document/status/summary response. It connects with document combination api.

The interaction proceeds as follows: consult controller dispatch contract before using an integration. Inputs are Method/path and controller-specific payload. Behind the interface, the implementation performs Dispatch document creation, retrieval, cached-summary combinations, listing, batch or streaming operations; ownership enforcement is missing.

The audit classification is partially working. No multi-file selector in main form; result can render multi_document metadata when present. Actual controller/service fixtures returned another owner’s document and an anonymous document with HTTP 200. The dispatcher also lacks a CSRF check. Evidence is recorded under F050 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Health

**F051. Static service health.** The feature allows visitor or monitor to confirm the PHP health endpoint responds. The intended output is status ok / service ai-summarizer JSON. It connects with navigation and result presentation.

The interaction proceeds as follows: request health.php; then check http status and json service label. Inputs are GET only in this audit. Behind the interface, the implementation performs Return a static JSON health response without loading the application bootstrap.

The audit classification is verified working. This static check does not establish database, NLP worker or full application readiness. Evidence is recorded under F051 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Backend services

**F052. Guest lifecycle.** Guest access keeps a temporary session so eligible results can be retrieved before an account is used. The intended output is Guest access token; eligible records transferred or expired. It connects with main processing, administration and reproducible operation.

The interaction proceeds as follows: use the named authorized entry point in an isolated deployment; then provide the specified inputs; then inspect the response and related records. Inputs are Temporary token and existing guest-owned records. Behind the interface, the implementation performs create token; touch expiry; claim summaries/feedback; purge expired guest data.

The audit classification is partially working. Source-confirmed; GET bootstrap mutates state; lifecycle incomplete for other stores. Evidence is recorded under F052 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F053. Python integration.** The PHP application passes processing work to Python and interprets its response. The intended output is Structured Python response or bounded error. It connects with main processing, administration and reproducible operation.

The interaction proceeds as follows: use the named authorized entry point in an isolated deployment; then provide the specified inputs; then inspect the response and related records. Inputs are Action, normalized payload and worker configuration. Behind the interface, the implementation performs daemon dispatch; CLI dispatch; structured response; timeout; terminate child tree.

The audit classification is implemented but not verified. Source-confirmed; authenticated loopback protocol3; Python behavior separately audited. Evidence is recorded under F053 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F054. Summary persistence.** Successful processing is intended to save a summary and supporting source for later display. The intended output is Saved summary identifier and artifact. It connects with main processing, administration and reproducible operation.

The interaction proceeds as follows: use the named authorized entry point in an isolated deployment; then provide the specified inputs; then inspect the response and related records. Inputs are Normalized output, source and metadata. Behind the interface, the implementation performs normalize worker contract; store summary; store source/artifact; attach metadata.

The audit classification is partially working. Source-confirmed; uploaded source word counts and processing time incomplete. Evidence is recorded under F054 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F055. Storage administration.** Administrators can inspect upload storage and invoke cleanup controls. The intended output is File counts or deletion outcome. It connects with main processing, administration and reproducible operation.

The interaction proceeds as follows: use the named authorized entry point in an isolated deployment; then provide the specified inputs; then inspect the response and related records. Inputs are Authorized cleanup action and upload directory. Behind the interface, the implementation performs count uploads; delete aged uploads; delete all uploads.

The audit classification is partially working. Source-confirmed destructive capability; age-only cleanup is not reference-aware orphan detection; not executed. Evidence is recorded under F055 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Evaluation

**F056. Evaluation dataset preparation.** A maintainer can prepare material for controlled algorithm evaluation through implemented services and import tools. The intended output is Dataset, reference and content-unit records. It connects with main processing, administration and reproducible operation.

The interaction proceeds as follows: use the named authorized entry point in an isolated deployment; then provide the specified inputs; then inspect the response and related records. Inputs are Dataset metadata, source documents and reference annotations. Behind the interface, the implementation performs create dataset; add document/provenance/split; add references; add content units; add challenge cases; activate/deactivate.

The audit classification is implemented but not verified. Source-confirmed service/CLI; not evidence of reachable browser controls or completed research corpus. Evidence is recorded under F056 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F057. Evaluation run control.** Evaluation run control records the settings used for a comparison so results can be traced to their inputs. The intended output is Queued immutable run snapshot and output work items. It connects with main processing, administration and reproducible operation.

The interaction proceeds as follows: use the named authorized entry point in an isolated deployment; then provide the specified inputs; then inspect the response and related records. Inputs are Dataset, profile/length/system combinations and configuration. Behind the interface, the implementation performs queue immutable snapshot; cancel; resume; code fingerprint; bounded combinations.

The audit classification is implemented but not verified. Source-confirmed; five lengths/eight profiles/five systems/ten ablations; optionalLLM may unavailable. Evidence is recorded under F057 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F058. Offline evaluation worker.** An offline worker processes queued comparison items and records available measurements. The intended output is Generated outputs, metric records and error states. It connects with main processing, administration and reproducible operation.

The interaction proceeds as follows: use the named authorized entry point in an isolated deployment; then provide the specified inputs; then inspect the response and related records. Inputs are Queued run and installed evaluation dependencies. Behind the interface, the implementation performs single-database lock; generate outputs; persist metrics; record failures; refresh report.

The audit classification is implemented but not verified. Source-confirmed; production worker not run; missing metric statuses retained. Evidence is recorded under F058 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F059. Evaluation review and correction.** Authorized review services record corrections and detailed analysis without treating automatic measurements as human ratings. The intended output is Recorded correction/review with audit history. It connects with main processing, administration and reproducible operation.

The interaction proceeds as follows: use the named authorized entry point in an isolated deployment; then provide the specified inputs; then inspect the response and related records. Inputs are Output, rating correction reason, error label or content-unit review. Behind the interface, the implementation performs correct rating with reason; classify error; verify content unit representation.

The audit classification is implemented but not verified. Source-confirmed; source method existence does not establish current UI reachability. Evidence is recorded under F059 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Operations

**F060. Migration and installation.** Installation tools establish or update database structures; they must be rehearsed against a disposable database. The intended output is Schema changes and migration ledger. It connects with main processing, administration and reproducible operation.

The interaction proceeds as follows: use the named authorized entry point in an isolated deployment; then provide the specified inputs; then inspect the response and related records. Inputs are Database configuration and checked-in SQL/migrations. Behind the interface, the implementation performs import base schemas; apply migrations; migration ledger/status.

The audit classification is partially working. Source-confirmed; status maywrite; passwordresetfreshinstalldrift; not executed. Evidence is recorded under F060 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F061. Retention cleanup.** Retention helpers limit selected old telemetry, comments and temporary recovery material. The intended output is Pruned eligible records/payloads. It connects with main processing, administration and reproducible operation.

The interaction proceeds as follows: use the named authorized entry point in an isolated deployment; then provide the specified inputs; then inspect the response and related records. Inputs are Retention interval or expiry timestamps. Behind the interface, the implementation performs landing90day default; audit90day default; feedbackcomments90day default; expired job payloads; guest expiry.

The audit classification is implemented but not verified. Source-confirmed; notproofscheduled; dryrunbootstrapcanwrite; no automaticbackupfound. Evidence is recorded under F061 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F062. Windows worker supervision.** Maintainers can start, monitor and stop the local Python worker using supplied Windows scripts. The intended output is Worker lifecycle, health checks and scheduled-task configuration. It connects with main processing, administration and reproducible operation.

The interaction proceeds as follows: use the named authorized entry point in an isolated deployment; then provide the specified inputs; then inspect the response and related records. Inputs are Python executable, worker paths and task settings. Behind the interface, the implementation performs install logon task; healthcheck30seconds; hidden launch; selftest; verify process before stop; remove task.

The audit classification is implemented but not verified. Source-confirmed; installed/runningtaskstate not inspected. Evidence is recorded under F062 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Results

**F063. Bearer-token result access.** A person holding an authorized share link can retrieve its associated result without becoming its owner. The intended output is Result display for token holder. It connects with source intake, processing and result retrieval.

The interaction proceeds as follows: open the relevant result or call the documented internal action through its intended boundary; then provide the required source or access token; then inspect the resulting output and any warnings. Inputs are Summary identifier and bearer share token. Behind the interface, the implementation performs Look up matching token and summary; load result artifact.

The audit classification is implemented but not verified. Source-supported; live share-link lifecycle and revocation were not exercised. Evidence is recorded under F063 in the feature inventory; a template capture or function test does not verify the full saved workflow.

### Processing

**F064. PDF and DOCX source extraction.** The engine converts supported document content into text and structural hints before summarization. The intended output is Extracted source and document context. It connects with source intake, processing and result retrieval.

The interaction proceeds as follows: open the relevant result or call the documented internal action through its intended boundary; then provide the required source or access token; then inspect the resulting output and any warnings. Inputs are Supported source document. Behind the interface, the implementation performs Read pages/paragraphs/tables; reconstruct text; apply readability checks.

The audit classification is implemented but not verified. Parsing fixtures run in isolation; representative real uploads were not submitted. Blank-first-page alignment probe was inconclusive. Evidence is recorded under F064 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F065. Bounded OCR fallback.** Image-only PDF content can be passed to an OCR fallback when suitable local tools are installed. The intended output is Recovered text or a controlled unreadable-content error. It connects with source intake, processing and result retrieval.

The interaction proceeds as follows: open the relevant result or call the documented internal action through its intended boundary; then provide the required source or access token; then inspect the resulting output and any warnings. Inputs are Unreadable selected PDF pages. Behind the interface, the implementation performs Select at most 12 pages; render at 150 DPI; use a 15-second OCR-call timeout and check a 55-second elapsed budget between pages.

The audit classification is implemented but not verified. Source-supported only. The 55-second value is a between-page elapsed-budget check, not a hard total deadline: rasterization has no local timeout. Real OCR tools and scanned-document accuracy were not demonstrated. Evidence is recorded under F065 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F066. Source cleaning and reconstruction.** The engine attempts to remove navigation and extraction noise while preserving useful paragraphs. The intended output is Cleaned paragraphs or an error when content is unavailable. It connects with source intake, processing and result retrieval.

The interaction proceeds as follows: open the relevant result or call the documented internal action through its intended boundary; then provide the required source or access token; then inspect the resulting output and any warnings. Inputs are Extracted or pasted text. Behind the interface, the implementation performs Normalize and classify blocks; remove detected noise; retain readable content.

The audit classification is partially working. A synthetic valid paragraph containing register was discarded. Rewording or paragraph separation retained content; this is a confirmed over-filtering defect. Evidence is recorded under F066 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F067. Default legacy summary selection.** The configured engine ranks and organizes source passages using text statistics, document hints and length constraints. The intended output is Structured summary payload and evidence metadata. It connects with source intake, processing and result retrieval.

The interaction proceeds as follows: open the relevant result or call the documented internal action through its intended boundary; then provide the required source or access token; then inspect the resulting output and any warnings. Inputs are Source, mode, format, depth, title and focus. Behind the interface, the implementation performs Extract; clean; rank sentence candidates; select; simplify; check heuristic facts; format.

The audit classification is partially working. The checked-in NIST fixture returned a nonempty summary. General quality, omission rates and saved web workflows remain unverified; the preceding cleaning stage has a reproduced defect. Evidence is recorded under F067 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F068. Optional v2 summary engine.** An alternative engine implementation provides a configurable experimental processing path. The intended output is Alternative summary result. It connects with source intake, processing and result retrieval.

The interaction proceeds as follows: open the relevant result or call the documented internal action through its intended boundary; then provide the required source or access token; then inspect the resulting output and any warnings. Inputs are Document and supported engine configuration. Behind the interface, the implementation performs Build evidence representation and compose output under the alternative engine contract.

The audit classification is partially working. Not enabled by the audited default configuration. One regression failed because an inline-heading fixture omitted the introductory outlier definition. Evidence is recorded under F068 in the feature inventory; a template capture or function test does not verify the full saved workflow.

**F069. Automated comparison metrics and statistics.** Evaluation helpers compare generated text with supplied references and summarize available measurements. The intended output is Metric records and statistical report payload. It connects with source intake, processing and result retrieval.

The interaction proceeds as follows: open the relevant result or call the documented internal action through its intended boundary; then provide the required source or access token; then inspect the resulting output and any warnings. Inputs are Sources, outputs, references and paired document-level observations. Behind the interface, the implementation performs Compute supported overlap/fact proxies; report unavailable metrics; group comparisons; apply statistical helpers.

The audit classification is implemented but not verified. Tests establish selected contracts in isolation. No human survey responses or approved research dataset were collected; optional metrics require their resources. Evidence is recorded under F069 in the feature inventory; a template capture or function test does not verify the full saved workflow.
