# Complete feature inventory

69 distinct capabilities. Repeated UI/service observations are merged; related sub-actions remain within a capability. This count does not equate to completed end-to-end verification.

| Classification | Count |

|---|---:|

| Verified working | 5 |

| Partially working | 18 |

| Not working | 0 |

| Implemented but not verified | 46 |

| UI only | 0 |

| Not implemented | 0 |

| Not applicable | 0 |

Verified working is limited to the exact capability boundary: mobile menu, tested result theme toggle, clipboard copy, text export, and static health. Partial means an implemented capability has a demonstrated or source-confirmed limitation; it does not mean every operation fails. Other implemented facilities remain unverified end-to-end. No missing feature is inferred from an unapproved wish list.

## Source method anchors

Functions below are public methods in the associated classes, supplied for navigation. They are not a claim that every listed method runs on every action. Complete method/line inventories are in evidence/backend-feature-notes.json; Python definitions are in evidence/python-source-map.json.

## F001. Landing information and navigation

**Module / role / route:** Entry / visitor / index.php

**Plain-language explanation and purpose:** As visitor, I can learn the purpose and open the summarizer or account forms.

**Need addressed:** Provides the named landing information and navigation action within its stated role and route.

**User or operator steps:** Open the landing page → Read About LIGHT and use Start/Summarize → Use footer terms or navigation account links.

**Inputs:** Navigation links; scroll

**System behavior:** Render project information and navigation links

**Expected output:** Landing sections; target page

**Related workflows:** Navigation and result presentation

**Frontend:** app/public/index.php; app/public/partials/site-nav.php; app/public/assets/js/nex-landing.js

**Backend:** app/public/about.php

**Controller/service anchors:** See script/definition map for the cited entry points.

**Database operations:** No persistent database operation in this capability; browser/session/file state may apply as described.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** VERIFIED_UI_IN_ISOLATION

**Observed result:** browser-readonly-results.json (template/local interaction scope only); source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Admin landing requests redirect; footer social links lead to generic social-platform homepages, not verified project accounts.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F002. Responsive mobile menu

**Module / role / route:** Navigation / visitor / all main templates

**Plain-language explanation and purpose:** As visitor, I can open and close site navigation on small screens.

**Need addressed:** Provides the named responsive mobile menu action within its stated role and route.

**User or operator steps:** Press the menu button → Choose a link or press Escape.

**Inputs:** Menu button; Escape

**System behavior:** Toggle menu visibility and aria-expanded; close on Escape

**Expected output:** Expanded navigation with aria-expanded state

**Related workflows:** Navigation and result presentation

**Frontend:** app/public/partials/site-nav.php; app/public/assets/css/site-nav.css

**Backend:** No backend for the named browser-only action.

**Controller/service anchors:** See script/definition map for the cited entry points.

**Database operations:** No persistent database operation in this capability; browser/session/file state may apply as described.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Verified working

**Testing:** Isolated local interaction / static route only

**Observed result:** browser-readonly-results.json (template/local interaction scope only); the named local interaction passed; no server persistence is claimed.

**Limitations:** Opening and Escape verified at 390px; complete keyboard/screen-reader audit not performed.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F003. Light/dark theme preference

**Module / role / route:** Appearance / visitor / nonlanding templates and result.php

**Plain-language explanation and purpose:** As visitor, I can switch the reading theme.

**Need addressed:** Provides the named light/dark theme preference action within its stated role and route.

**User or operator steps:** Use the theme control →  revisit a supported page to reuse preference.

**Inputs:** Theme button; saved localStorage preference

**System behavior:** Toggle supported page theme and retain browser preference

**Expected output:** Light or dark document data-theme

**Related workflows:** Navigation and result presentation

**Frontend:** app/public/assets/js/theme-toggle.js; app/public/assets/js/result.js; app/public/assets/css/design-tokens.css

**Backend:** No backend for the named browser-only action.

**Controller/service anchors:** See script/definition map for the cited entry points.

**Database operations:** No persistent database operation in this capability; browser/session/file state may apply as described.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Verified working

**Testing:** Isolated local interaction / static route only

**Observed result:** browser-readonly-results.json supplementalInteractions; synthetic data/client-only action; the named local interaction passed; no server persistence is claimed.

**Limitations:** Source inspection; theme persists locally, not as a saved account preference.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F004. Create account

**Module / role / route:** Accounts / visitor / register.php → auth.php?action=register

**Plain-language explanation and purpose:** As visitor, I can register with an email and password.

**Need addressed:** Provides the named create account action within its stated role and route.

**User or operator steps:** Open Create Account → Enter the required fields → Review and accept terms → Submit Create Account.

**Inputs:** Username; email; password; confirmation; terms acceptance; CSRF

**System behavior:** Validate account inputs and password policy; check uniqueness; create the account

**Expected output:** Validation feedback or account/session redirect

**Related workflows:** Account registration

**Frontend:** app/public/register.php

**Backend:** app/public/auth.php; app/src/Controllers/AuthController.php; app/src/Repositories/UserRepository.php; app/src/Utils/PasswordPolicy.php

**Controller/service anchors:** AuthController::register (app/src/Controllers/AuthController.php:240); AuthController::forgotPassword (app/src/Controllers/AuthController.php:409); AuthController::resetPassword (app/src/Controllers/AuthController.php:453); UserRepository::updatePasswordHash (app/src/Repositories/UserRepository.php:168)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: users.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** No account was created. Registration form copy mentions custom profiles; dedicated editable saved-profile UI was not found.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F005. Sign in and remember session

**Module / role / route:** Accounts / registered user / login.php → auth.php?action=login

**Plain-language explanation and purpose:** As registered user, I can sign in to account features.

**Need addressed:** Provides the named sign in and remember session action within its stated role and route.

**User or operator steps:** Enter your username/email and password → Choose remember-me only on a trusted device if desired → Submit sign-in.

**Inputs:** Username or email; password; optional remember-me; CSRF

**System behavior:** Check credentials and status; regenerate session; optionally issue or rotate a remembered user token

**Expected output:** Session or validation error

**Related workflows:** Login and logout; Remembered login

**Frontend:** app/public/login.php

**Backend:** app/public/auth.php; app/src/Controllers/AuthController.php; app/src/Services/RememberMeService.php; app/src/Utils/SessionManager.php

**Controller/service anchors:** AuthController::login (app/src/Controllers/AuthController.php:44); AuthController::verifyAdminLoginCode (app/src/Controllers/AuthController.php:165); RememberMeService::loginFromCookie (app/src/Services/RememberMeService.php:83)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: users, rate_limits, user_tokens.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Credential and remember-cookie flows not exercised.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F006. Administrator email code verification

**Module / role / route:** Accounts / administrator / login.php → auth.php?action=verifyAdminLoginCode

**Plain-language explanation and purpose:** As administrator, I can complete the additional administrator login step.

**Need addressed:** Provides the named administrator email code verification action within its stated role and route.

**User or operator steps:** Sign in with administrator credentials → Enter the emailed one-time code → Submit verification or cancel to sign in again.

**Inputs:** Pending admin session; emailed code; CSRF

**System behavior:** Create pending administrator-code session state; send and verify time-limited code; rate-limit attempts; record security activity

**Expected output:** Verified login or code error

**Related workflows:** Administrator email OTP

**Frontend:** app/public/login.php

**Backend:** app/src/Controllers/AuthController.php; app/src/Services/AdminSecurityService.php; app/src/Services/EmailService.php

**Controller/service anchors:** AuthController::verifyAdminLoginCode (app/src/Controllers/AuthController.php:165); AdminSecurityService::isVerifiedAdmin (app/src/Services/AdminSecurityService.php:11); AdminSecurityService::requireVerifiedAdmin (app/src/Services/AdminSecurityService.php:17); AdminSecurityService::requireVerifiedAdminApi (app/src/Services/AdminSecurityService.php:25); EmailService::sendAdminLoginCodeEmail (app/src/Services/EmailService.php:75)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: users, rate_limits, admin_audit_logs.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed. Configured SMTP/mail service for code delivery.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Source-supported conditional UI only; no email sent; retired challenge routes are redirects, not an active challenge feature.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F007. Password recovery

**Module / role / route:** Accounts / account holder / forgot_password.php → auth.php?action=forgotPassword

**Plain-language explanation and purpose:** As account holder, I can request a password-reset code.

**Need addressed:** Provides the named password recovery action within its stated role and route.

**User or operator steps:** Open Recover Password → Enter email → Choose Send OTP and follow the response.

**Inputs:** Email; CSRF

**System behavior:** Locate eligible account; issue a time-limited reset code; request email delivery

**Expected output:** Generic recovery outcome and reset flow

**Related workflows:** Password reset

**Frontend:** app/public/forgot_password.php

**Backend:** app/src/Controllers/AuthController.php; app/src/Services/PasswordResetService.php; app/src/Services/EmailService.php

**Controller/service anchors:** AuthController::resetPassword (app/src/Controllers/AuthController.php:453); EmailService::sendPasswordResetEmail (app/src/Services/EmailService.php:41)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: users, password_resets, rate_limits.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed. Configured SMTP/mail service for code delivery.

**Implementation / classification:** Yes / Partially working

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication. See the reproduced or source-confirmed limitation below.

**Limitations:** Recovery form rendered; sending, delivery and account existence outcomes not tested. Reset-token storage differs between the live database and clean-install SQL; email delivery was not exercised.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F008. Reset password using OTP

**Module / role / route:** Accounts / account holder / reset_password.php → auth.php?action=resetPassword

**Plain-language explanation and purpose:** As account holder, I can replace a forgotten password after verification.

**Need addressed:** Provides the named reset password using otp action within its stated role and route.

**User or operator steps:** Open reset form after requesting a code → Enter email and code → Enter matching new passwords → Submit reset.

**Inputs:** Email; OTP; new password; confirmation; CSRF

**System behavior:** Validate reset code and new password; replace password hash and consume reset token

**Expected output:** Reset success or validation/code error

**Related workflows:** Password reset

**Frontend:** app/public/reset_password.php

**Backend:** app/src/Controllers/AuthController.php; app/src/Services/PasswordResetService.php

**Controller/service anchors:** AuthController::forgotPassword (app/src/Controllers/AuthController.php:409); AuthController::resetPassword (app/src/Controllers/AuthController.php:453)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: users, password_resets.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed. Configured SMTP/mail service for code delivery.

**Implementation / classification:** Yes / Partially working

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication. See the reproduced or source-confirmed limitation below.

**Limitations:** No password changed. A password change does not revoke existing remembered tokens or active sessions; the fresh-install reset schema also differs.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F009. Password visibility and strength hints

**Module / role / route:** Accounts / visitor / login.php/register.php/reset_password.php

**Plain-language explanation and purpose:** As visitor, I can review password entry before submitting.

**Need addressed:** Provides the named password visibility and strength hints action within its stated role and route.

**User or operator steps:** Enter a password → Use eye button to toggle visibility → Read available strength/mismatch hint.

**Inputs:** Password input and visibility button

**System behavior:** Toggle the password input masking and display local password-strength hints

**Expected output:** Plain/masked password; client strength/match hints

**Related workflows:** Registration and password reset

**Frontend:** app/public/login.php; app/public/register.php; app/public/reset_password.php

**Backend:** app/src/Utils/PasswordPolicy.php

**Controller/service anchors:** PasswordPolicy::validate (app/src/Utils/PasswordPolicy.php:6); PasswordPolicy::getStrength (app/src/Utils/PasswordPolicy.php:31)

**Database operations:** No direct SQL for the input/control action; a later submitted summary workflow persists through F019/F021.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Partially working

**Testing:** VERIFIED_UI_IN_ISOLATION

**Observed result:** browser-readonly-results.json (template/local interaction scope only); source inspection establishes implementation; template rendering does not establish successful persistence or authentication. See the reproduced or source-confirmed limitation below.

**Limitations:** Click toggle verified on registration; eye buttons tabindex=-1 excludes keyboard Tab navigation. The visibility control responds to clicks but uses tabindex=-1, excluding normal keyboard tab access.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F010. Read and accept terms

**Module / role / route:** Terms / visitor or registered user / terms.php / accept_terms.php

**Plain-language explanation and purpose:** As visitor or registered user, I can review usage conditions and continue after agreement.

**Need addressed:** Provides the named read and accept terms action within its stated role and route.

**User or operator steps:** Open Terms and Conditions → Read the sections → Check agreement and confirm; choose decline if not agreeing.

**Inputs:** Acceptance checkbox; accept/decline action; CSRF

**System behavior:** Display the current terms and record an authenticated acceptance

**Expected output:** Session/account acceptance or exit/login redirect

**Related workflows:** Terms acceptance

**Frontend:** app/public/terms.php; app/public/accept_terms.php

**Backend:** app/src/Utils/TermsContent.php; app/src/Services/TermsAcceptanceService.php

**Controller/service anchors:** TermsAcceptanceService::storeTermsStateInSession (app/src/Services/TermsAcceptanceService.php:55); TermsAcceptanceService::acceptCurrentUserTerms (app/src/Services/TermsAcceptanceService.php:63); TermsAcceptanceService::currentAuthenticatedUserHasAccepted (app/src/Services/TermsAcceptanceService.php:89); TermsAcceptanceService::currentUserNeedsAcceptance (app/src/Services/TermsAcceptanceService.php:108); TermsAcceptanceService::redirectAuthenticatedUserIfTermsPending (app/src/Services/TermsAcceptanceService.php:117); TermsAcceptanceService::redirectAfterTermsAcceptance (app/src/Services/TermsAcceptanceService.php:162)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: users.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Partially working

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication. See the reproduced or source-confirmed limitation below.

**Limitations:** Reading rendered; acceptance/decline not executed; two terms pages have different guest actions. The decline link does not submit the server-side decline action; terms enforcement also depends on the route allowlist.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F011. Upload PDF or DOCX

**Module / role / route:** Summary input / guest or registered user / summarizer.php

**Plain-language explanation and purpose:** As guest or registered user, I can supply a supported local document.

**Need addressed:** Provides the named upload pdf or docx action within its stated role and route.

**User or operator steps:** Choose Upload File → Browse or drag a file → Use Replace/Remove to correct the selection.

**Inputs:** One PDF/DOCX; optional title

**System behavior:** Select PDF/DOCX; on submission validate multipart origin, extension, MIME, signature and package structure; store a randomized upload file

**Expected output:** Selected filename or input error

**Related workflows:** Input and preference normalization; Upload acceptance

**Frontend:** app/public/partials/summarizer-form.php; app/public/assets/js/nex-landing.js

**Backend:** app/src/Services/FileUploadService.php; app/src/Services/SourceResolver.php; app/src/Services/ArticleService.php; app/src/Controllers/ArticleController.php

**Controller/service anchors:** FileUploadService::effectiveUploadLimit (app/src/Services/FileUploadService.php:30); FileUploadService::assertUploadedFile (app/src/Services/FileUploadService.php:37); FileUploadService::validateUploadedFile (app/src/Services/FileUploadService.php:90); FileUploadService::saveUploadedFile (app/src/Services/FileUploadService.php:108); FileUploadService::removeUploadedFile (app/src/Services/FileUploadService.php:143); SourceResolver::resolve (app/src/Services/SourceResolver.php:66); ArticleService::resolvePendingSummarySource (app/src/Services/ArticleService.php:183)

**Database operations:** No direct SQL for the input/control action; a later submitted summary workflow persists through F019/F021.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed. Python process and relevant installed packages.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** No file uploaded. Effective limit depends on PHP/config; renderer displayed 2.0 MiB for its PHP environment.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F012. Paste text source

**Module / role / route:** Summary input / guest or registered user / summarizer.php

**Plain-language explanation and purpose:** As guest or registered user, I can summarize readable pasted text.

**Need addressed:** Provides the named paste text source action within its stated role and route.

**User or operator steps:** Choose Paste Text → Paste permitted document text → Review source and settings.

**Inputs:** Text up to server-provided byte cap

**System behavior:** Accept pasted text; on submission normalize and validate source length

**Expected output:** Text selected as source

**Related workflows:** Input and preference normalization

**Frontend:** app/public/partials/summarizer-form.php; app/public/assets/js/index.js

**Backend:** app/src/Services/ArticleService.php; app/src/Services/SourceResolver.php; app/src/Controllers/ArticleController.php

**Controller/service anchors:** ArticleService::resolvePendingSummarySource (app/src/Services/ArticleService.php:183); ArticleService::normalizeSummaryLength (app/src/Services/ArticleService.php:550); ArticleService::normalizeAnalysisMode (app/src/Services/ArticleService.php:586); ArticleService::normalizeOutputFormat (app/src/Services/ArticleService.php:605); SourceResolver::resolve (app/src/Services/SourceResolver.php:66); SourceResolver::validateUrl (app/src/Services/SourceResolver.php:87)

**Database operations:** No direct SQL for the input/control action; a later submitted summary workflow persists through F019/F021.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** VERIFIED_UI_IN_ISOLATION

**Observed result:** browser-readonly-results.json (template/local interaction scope only); source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Source-selector interaction verified; extraction/model processing not run.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F013. Article URL source

**Module / role / route:** Summary input / guest or registered user / summarizer.php

**Plain-language explanation and purpose:** As guest or registered user, I can request summarization of an accessible article URL.

**Need addressed:** Provides the named article url source action within its stated role and route.

**User or operator steps:** Choose URL → Paste the complete public address → Submit when source/settings are ready.

**Inputs:** HTTP(S) URL without embedded credentials

**System behavior:** Accept an HTTP(S) article address; validate destination and redirects; acquire bounded source content

**Expected output:** URL source or validation error

**Related workflows:** Input and preference normalization; URL acquisition

**Frontend:** app/public/partials/summarizer-form.php; app/public/assets/js/index.js

**Backend:** app/src/Services/SourceResolver.php; app/src/Services/ArticleService.php; app/src/Controllers/ArticleController.php

**Controller/service anchors:** SourceResolver::resolve (app/src/Services/SourceResolver.php:66); SourceResolver::validateUrl (app/src/Services/SourceResolver.php:87); ArticleService::resolvePendingSummarySource (app/src/Services/ArticleService.php:183)

**Database operations:** No direct SQL for the input/control action; a later submitted summary workflow persists through F019/F021.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed. Reachable public source server and PHP cURL/DNS.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** VERIFIED_UI_IN_ISOLATION

**Observed result:** browser-readonly-results.json (template/local interaction scope only); source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Selection and invalid-empty-URL rejection verified locally; fetching and safety policy not exercised.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F014. Optional document title and focus

**Module / role / route:** Summary controls / guest or registered user / summarizer.php

**Plain-language explanation and purpose:** As guest or registered user, I can name a result and prioritize a topic/question.

**Need addressed:** Provides the named optional document title and focus action within its stated role and route.

**User or operator steps:** Optionally enter Document Title → Optionally enter Focus / Topic / Question.

**Inputs:** Optional title; optional focus text (UI maxlength 300)

**System behavior:** Accept optional title and focus and validate their length for the processing request

**Expected output:** Selected title/focus in request

**Related workflows:** Input and preference normalization

**Frontend:** app/public/partials/summarizer-form.php

**Backend:** app/src/Services/ArticleService.php; app/src/Services/SummarizerPromptTemplate.php; app/src/Controllers/ArticleController.php

**Controller/service anchors:** ArticleService::buildPendingSummary (app/src/Services/ArticleService.php:57); ArticleService::normalizeSummaryLength (app/src/Services/ArticleService.php:550); ArticleService::normalizeAnalysisMode (app/src/Services/ArticleService.php:586); ArticleService::normalizeOutputFormat (app/src/Services/ArticleService.php:605)

**Database operations:** No direct SQL for the input/control action; a later submitted summary workflow persists through F019/F021.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Focus changes requested prioritization; cannot promise answer accuracy.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F015. Five output organizations

**Module / role / route:** Summary controls / guest or registered user / summarizer.php

**Plain-language explanation and purpose:** As guest or registered user, I can choose paragraph, bullets, hybrid, structured sections or key takeaways.

**Need addressed:** Provides the named five output organizations action within its stated role and route.

**User or operator steps:** Open Output Format → Choose one format and read its description.

**Inputs:** output_format select

**System behavior:** Select one of five output organizations and pass its validated value to generation

**Expected output:** Updated description and request setting

**Related workflows:** Input and preference normalization

**Frontend:** app/public/partials/summarizer-form.php; app/public/assets/js/index.js

**Backend:** app/src/Services/ArticleService.php; app/src/Controllers/ArticleController.php

**Controller/service anchors:** ArticleService::normalizeSummaryLength (app/src/Services/ArticleService.php:550); ArticleService::normalizeAnalysisMode (app/src/Services/ArticleService.php:586); ArticleService::normalizeOutputFormat (app/src/Services/ArticleService.php:605)

**Database operations:** No direct SQL for the input/control action; a later submitted summary workflow persists through F019/F021.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** VERIFIED_UI_IN_ISOLATION

**Observed result:** browser-readonly-results.json (template/local interaction scope only); source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Structured selection verified; output quality not evaluated.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F016. Eight analysis modes

**Module / role / route:** Summary controls / guest or registered user / summarizer.php

**Plain-language explanation and purpose:** As guest or registered user, I can choose general, academic, executive, technical, study, news, legal or scientific emphasis.

**Need addressed:** Provides the named eight analysis modes action within its stated role and route.

**User or operator steps:** Open Analysis Mode → Choose the intended emphasis.

**Inputs:** analysis_mode select

**System behavior:** Select one of eight analysis modes and pass its validated value to generation

**Expected output:** Updated mode description and request setting

**Related workflows:** Input and preference normalization

**Frontend:** app/public/partials/summarizer-form.php; app/public/assets/js/index.js

**Backend:** app/src/Services/ArticleService.php; app/src/Controllers/ArticleController.php

**Controller/service anchors:** ArticleService::normalizeSummaryLength (app/src/Services/ArticleService.php:550); ArticleService::normalizeAnalysisMode (app/src/Services/ArticleService.php:586); ArticleService::normalizeOutputFormat (app/src/Services/ArticleService.php:605)

**Database operations:** No direct SQL for the input/control action; a later submitted summary workflow persists through F019/F021.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** VERIFIED_UI_IN_ISOLATION

**Observed result:** browser-readonly-results.json (template/local interaction scope only); source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Academic selection verified; modes do not establish domain correctness.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F017. Six summary depths

**Module / role / route:** Summary controls / guest or registered user / summarizer.php

**Plain-language explanation and purpose:** As guest or registered user, I can choose very brief, brief, short, balanced, detailed or comprehensive depth.

**Need addressed:** Provides the named six summary depths action within its stated role and route.

**User or operator steps:** Select a Summary Depth button → Read the depth explanation.

**Inputs:** Depth button

**System behavior:** Select one of six main-interface depths and pass its validated value to generation

**Expected output:** aria-pressed and hidden depth fields update

**Related workflows:** Input and preference normalization

**Frontend:** app/public/partials/summarizer-form.php; app/public/assets/js/index.js

**Backend:** app/src/Services/ArticleService.php; app/src/Controllers/ArticleController.php

**Controller/service anchors:** ArticleService::normalizeSummaryLength (app/src/Services/ArticleService.php:550); ArticleService::normalizeAnalysisMode (app/src/Services/ArticleService.php:586); ArticleService::normalizeOutputFormat (app/src/Services/ArticleService.php:605)

**Database operations:** No direct SQL for the input/control action; a later submitted summary workflow persists through F019/F021.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** VERIFIED_UI_IN_ISOLATION

**Observed result:** browser-readonly-results.json (template/local interaction scope only); source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Comprehensive selection verified; exact word length is not guaranteed.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F018. Optional evidence-controlled synthesis

**Module / role / route:** Summary controls / guest or registered user when configured / summarizer.php

**Plain-language explanation and purpose:** As guest or registered user when configured, I can opt in to configured language-model synthesis.

**Need addressed:** Provides the named optional evidence-controlled synthesis action within its stated role and route.

**User or operator steps:** When Generation Method is displayed, read the data-transfer notice → Opt in only when authorized to process the source there.

**Inputs:** Conditional use_llm_synthesis checkbox

**System behavior:** Request optional synthesis only when both request flag and server configuration permit the configured provider

**Expected output:** Requested synthesis path or extractive fallback behavior

**Related workflows:** Input and preference normalization; Python integration

**Frontend:** app/public/partials/summarizer-form.php; app/public/assets/js/index.js

**Backend:** app/src/Services/ArticleService.php; app/src/Services/LocalPythonBridge.php; app/src/Controllers/ArticleController.php

**Controller/service anchors:** ArticleService::buildPendingSummary (app/src/Services/ArticleService.php:57); ArticleService::requestSummary (app/src/Services/ArticleService.php:203); LocalPythonBridge::summarize (app/src/Services/LocalPythonBridge.php:31); LocalPythonBridge::summarizeV2 (app/src/Services/LocalPythonBridge.php:36)

**Database operations:** No direct SQL for the input/control action; a later submitted summary workflow persists through F019/F021.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed. Python process and relevant installed packages. Optional configured LLM endpoint; currently disabled.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Conditional source only; flag disabled in synthetic renderer; external synthesis not invoked.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F019. Submit summary request

**Module / role / route:** Summarization / guest or registered user / summarize.php

**Plain-language explanation and purpose:** As guest or registered user, I can start processing a selected source.

**Need addressed:** Provides the named submit summary request action within its stated role and route.

**User or operator steps:** Review source and settings → Choose Summarize Document → Follow processing/status feedback.

**Inputs:** Source; title; focus; format; mode; depth; CSRF; request token

**System behavior:** Validate request; resolve source; create or reuse a job; dispatch processing and persist successful output

**Expected output:** Processing redirect or recoverable error

**Related workflows:** Input and preference normalization; Recoverable summary jobs; Python integration; Summary persistence

**Frontend:** app/public/assets/js/index.js

**Backend:** app/public/summarize.php; app/src/Controllers/ArticleController.php; app/src/Services/ArticleService.php; app/src/Services/SummaryJobService.php; app/src/Services/LocalPythonBridge.php

**Controller/service anchors:** ArticleController::process (app/src/Controllers/ArticleController.php:25); ArticleController::execute (app/src/Controllers/ArticleController.php:69); ArticleService::resolvePendingSummarySource (app/src/Services/ArticleService.php:183); ArticleService::storeSummary (app/src/Services/ArticleService.php:263); LocalPythonBridge::summarize (app/src/Services/LocalPythonBridge.php:31); LocalPythonBridge::summarizeV2 (app/src/Services/LocalPythonBridge.php:36)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: summary_jobs, summaries, summary_artifacts.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed. Python process and relevant installed packages.

**Implementation / classification:** Yes / Partially working

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication. See the reproduced or source-confirmed limitation below.

**Limitations:** Submission not executed; all harness POSTs blocked. The legacy cleaner can discard legitimate prose containing register. Upload word-count and processing-time persistence are incomplete.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F020. Source validation and one-hour draft recovery

**Module / role / route:** Recovery / guest or registered user / summarizer.php

**Plain-language explanation and purpose:** As guest or registered user, I can correct invalid input and recover tab-local source/settings.

**Need addressed:** Provides the named source validation and one-hour draft recovery action within its stated role and route.

**User or operator steps:** Correct the named field → Use retry only where offered → For file recovery, select the file again.

**Inputs:** Input errors; saved sessionStorage fields

**System behavior:** Validate browser inputs; display catalogued errors; retain and recover an eligible one-hour browser draft

**Expected output:** Friendly explanation, recovery action and restored settings

**Related workflows:** Input and preference normalization

**Frontend:** app/public/assets/js/index.js; app/public/assets/js/light-errors.js; app/public/partials/error-support.php

**Backend:** app/src/Support/ProcessingError.php; app/src/Services/ArticleService.php; app/src/Controllers/ArticleController.php

**Controller/service anchors:** ArticleService::resolvePendingSummarySource (app/src/Services/ArticleService.php:183); ArticleService::normalizeSummaryLength (app/src/Services/ArticleService.php:550); ArticleService::normalizeAnalysisMode (app/src/Services/ArticleService.php:586); ArticleService::normalizeOutputFormat (app/src/Services/ArticleService.php:605)

**Database operations:** No direct SQL for the input/control action; a later submitted summary workflow persists through F019/F021.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** VERIFIED_UI_IN_ISOLATION

**Observed result:** browser-readonly-results.json (template/local interaction scope only); source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Empty URL validation verified; file bytes cannot be restored; source text is retained in browser tab storage for up to one hour.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F021. Job progress, retry and cancel

**Module / role / route:** Processing / visitor with pending job / processing.php

**Plain-language explanation and purpose:** As visitor with pending job, I can follow an asynchronous summary and recover from failure.

**Need addressed:** Provides the named job progress, retry and cancel action within its stated role and route.

**User or operator steps:** Remain on the processing page → If offered, check connection/status or retry → Use cancel when available to return to settings.

**Inputs:** Existing job/session; retry or cancel controls

**System behavior:** Resolve owned job; inspect or update state; execute, retry or cancel through guarded actions

**Expected output:** Status, completion redirect or recovery message

**Related workflows:** Recoverable summary jobs

**Frontend:** app/public/processing.php; app/public/assets/js/processing.js

**Backend:** app/src/Controllers/ArticleController.php; app/src/Services/SummaryJobService.php

**Controller/service anchors:** ArticleController::process (app/src/Controllers/ArticleController.php:25); ArticleController::execute (app/src/Controllers/ArticleController.php:69); ArticleController::status (app/src/Controllers/ArticleController.php:84); ArticleController::retry (app/src/Controllers/ArticleController.php:90); ArticleController::handleBackgroundJob (app/src/Controllers/ArticleController.php:104); ArticleController::cancel (app/src/Controllers/ArticleController.php:137); SummaryJobService::completeJob (app/src/Services/SummaryJobService.php:143); SummaryJobService::failJob (app/src/Services/SummaryJobService.php:149); SummaryJobService::cancelJob (app/src/Services/SummaryJobService.php:163); SummaryJobService::failureForJob (app/src/Services/SummaryJobService.php:279); SummaryJobService::retryJob (app/src/Services/SummaryJobService.php:301)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: summary_jobs, summaries.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed. Python process and relevant installed packages.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Only synthetic pending page rendered; automatic execute/poll requests deliberately blocked.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F022. Generate and copy concise nutshell

**Module / role / route:** Nutshell / registered active account / nutshell_generate.php

**Plain-language explanation and purpose:** As registered active account, I can produce a short standalone rendition from an eligible source.

**Need addressed:** Provides the named generate and copy concise nutshell action within its stated role and route.

**User or operator steps:** Prepare a source → Choose Generate Nutshell → Read the panel and use Copy after success.

**Inputs:** Selected source/mode; CSRF; idempotency key

**System behavior:** Require a registered account; request concise generation; record successful Nutshell activity and expose copy controls

**Expected output:** Nutshell text, not-needed notice or recovery error

**Related workflows:** Python integration; Usage analytics

**Frontend:** app/public/assets/js/index.js; app/public/partials/summarizer-form.php

**Backend:** app/public/nutshell_generate.php; app/src/Services/LocalPythonBridge.php; app/src/Controllers/AnalyticsController.php; app/src/Repositories/UserRepository.php

**Controller/service anchors:** AnalyticsController::getDashboardData (app/src/Controllers/AnalyticsController.php:330)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: nutshell_generations.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed. Python process and relevant installed packages.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Guest redirect verified. Generation not run. Backend supports six mode labels; legal/scientific selections are normalized to general by this endpoint. The synthetic direct call returned insufficient_content with an empty nutshell; it did not demonstrate a successful concise summary. Web saving was not run.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F023. Read structured summary and details

**Module / role / route:** Results / authorized owner or shared-link viewer / result.php?id=…

**Plain-language explanation and purpose:** As authorized owner or shared-link viewer, I can read the summary and available metadata.

**Need addressed:** Provides the named read structured summary and details action within its stated role and route.

**User or operator steps:** Open an accessible result → Read the primary summary → Expand available source/details sections.

**Inputs:** Authorized summary id; optional share token

**System behavior:** Resolve owner, guest or bearer access; load summary/artifact and render supported result sections

**Expected output:** Summary, key points, keywords, section analysis and details when stored

**Related workflows:** History and sharing; Structured display

**Frontend:** app/public/result.php

**Backend:** app/src/Controllers/HistoryHandler.php; app/src/Services/HistoryService.php; app/src/Utils/Formatter.php; app/src/Repositories/HistoryRepository.php

**Controller/service anchors:** HistoryHandler::getSummaryById (app/src/Controllers/HistoryHandler.php:17); HistoryService::getSummaryById (app/src/Services/HistoryService.php:35); HistoryRepository::findSummaryById (app/src/Repositories/HistoryRepository.php:23)

**Database operations:** SELECT for display/aggregation: summaries, summary_artifacts.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Synthetic prose rendered; optional rich sections depend on saved output and were not all populated.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F024. Inspect source and evidence passages

**Module / role / route:** Results / authorized result viewer / result.php

**Plain-language explanation and purpose:** As authorized result viewer, I can compare output with retained source passages.

**Need addressed:** Provides the named inspect source and evidence passages action within its stated role and route.

**User or operator steps:** Choose Check source → Expand original text → Follow numbered evidence references if present.

**Inputs:** Source disclosure and evidence anchor clicks

**System behavior:** Display available source text and evidence metadata from an accessible result

**Expected output:** Source text, evidence/coverage blocks where stored

**Related workflows:** History and sharing; Structured display

**Frontend:** app/public/result.php; app/public/assets/js/result.js

**Backend:** app/src/Utils/Formatter.php; app/src/Services/HistoryService.php; app/src/Repositories/HistoryRepository.php; app/src/Controllers/HistoryHandler.php

**Controller/service anchors:** HistoryService::getSummaryById (app/src/Services/HistoryService.php:35); HistoryRepository::findSummaryById (app/src/Repositories/HistoryRepository.php:23); HistoryHandler::getSummaryById (app/src/Controllers/HistoryHandler.php:17)

**Database operations:** SELECT for display/aggregation: summaries, summary_artifacts.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Source-backed evidence links do not prove factual accuracy; populated metadata not end-to-end tested.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F025. Copy summary

**Module / role / route:** Results / authorized result viewer / result.php

**Plain-language explanation and purpose:** As authorized result viewer, I can copy the displayed summary.

**Need addressed:** Provides the named copy summary action within its stated role and route.

**User or operator steps:** Open a completed result → Choose Copy.

**Inputs:** Copy button

**System behavior:** Copy the rendered summary text to the browser clipboard

**Expected output:** Clipboard text and status

**Related workflows:** Navigation and result presentation

**Frontend:** app/public/assets/js/result.js

**Backend:** No backend for the named browser-only action.

**Controller/service anchors:** See script/definition map for the cited entry points.

**Database operations:** No persistent database operation in this capability; browser/session/file state may apply as described.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Verified working

**Testing:** Isolated local interaction / static route only

**Observed result:** browser-readonly-results.json supplementalInteractions; synthetic data/client-only action; the named local interaction passed; no server persistence is claimed.

**Limitations:** Clipboard success verified on loopback with explicit browser permission and synthetic content; other browser permission contexts untested.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F026. Print or save PDF

**Module / role / route:** Results / authorized result viewer / result.php

**Plain-language explanation and purpose:** As authorized result viewer, I can open browser print output for a completed summary.

**Need addressed:** Provides the named print or save pdf action within its stated role and route.

**User or operator steps:** Choose PDF → Review the browser print preview → Select a printer or Save as PDF.

**Inputs:** PDF button; browser print dialog

**System behavior:** Invoke browser printing with the result print stylesheet; PDF saving depends on browser options

**Expected output:** Printed output or browser Save as PDF

**Related workflows:** Navigation and result presentation

**Frontend:** app/public/assets/js/result.js; app/public/assets/css/result.css

**Backend:** No backend for the named browser-only action.

**Controller/service anchors:** See script/definition map for the cited entry points.

**Database operations:** No persistent database operation in this capability; browser/session/file state may apply as described.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Uses window.print(), not a server-generated PDF endpoint; print dialog not exercised.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F027. Download text

**Module / role / route:** Results / authorized result viewer / result.php

**Plain-language explanation and purpose:** As authorized result viewer, I can save summary text locally.

**Need addressed:** Provides the named download text action within its stated role and route.

**User or operator steps:** Choose Text export → Save the generated download.

**Inputs:** Text export button

**System behavior:** Create a local text download containing the rendered summary

**Expected output:** Client-generated .txt file

**Related workflows:** Navigation and result presentation

**Frontend:** app/public/assets/js/result.js

**Backend:** No backend for the named browser-only action.

**Controller/service anchors:** See script/definition map for the cited entry points.

**Database operations:** No persistent database operation in this capability; browser/session/file state may apply as described.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Verified working

**Testing:** Isolated local interaction / static route only

**Observed result:** browser-readonly-results.json supplementalInteractions; synthetic data/client-only action; the named local interaction passed; no server persistence is claimed.

**Limitations:** Synthetic result exported to evidence/synthetic-summary-download.txt and exact text checked; no real summary exported.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F028. Listen to summary audio

**Module / role / route:** Results / authorized result viewer with session / tts_generate.php / tts_audio.php

**Plain-language explanation and purpose:** As authorized result viewer with session, I can request and listen to generated speech.

**Need addressed:** Provides the named listen to summary audio action within its stated role and route.

**User or operator steps:** Choose Listen on a completed summary → Use audio controls after successful generation.

**Inputs:** Summary id; share token if applicable; CSRF

**System behavior:** Validate permitted audio request; normalize text; generate or reuse Piper output; serve token-controlled file with byte-range handling

**Expected output:** Audio player/status or service error

**Related workflows:** Audio generation and delivery

**Frontend:** app/public/result.php; app/public/assets/js/result.js

**Backend:** app/public/tts_generate.php; app/public/tts_audio.php; app/src/Services/BarkTtsService.php

**Controller/service anchors:** BarkTtsService::buildCacheFileName (app/src/Services/BarkTtsService.php:51); BarkTtsService::buildLegacyCacheFileName (app/src/Services/BarkTtsService.php:57); BarkTtsService::generate (app/src/Services/BarkTtsService.php:91); BarkTtsService::buildAudioProxyUrl (app/src/Services/BarkTtsService.php:119); BarkTtsService::buildAudioAccessToken (app/src/Services/BarkTtsService.php:135); BarkTtsService::verifyAudioAccessToken (app/src/Services/BarkTtsService.php:150); BarkTtsService::fetchAudioStream (app/src/Services/BarkTtsService.php:169)

**Database operations:** No persistent database operation in this capability; browser/session/file state may apply as described.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed. Python process and relevant installed packages. Piper and provisioned voice; initial voice download may need network.

**Implementation / classification:** Yes / Partially working

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication. See the reproduced or source-confirmed limitation below.

**Limitations:** Local worker availability required; no audio generated or fetched. The isolated suffix-range request returned the whole file; audio delivery declares public cacheability. Real Piper synthesis and listening were not performed.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json; evidence/backend-isolated-checks.json

## F029. Filipino translation

**Module / role / route:** Results / session-bearing result viewer / translate_proxy.php

**Plain-language explanation and purpose:** As session-bearing result viewer, I can request a Filipino rendition of displayed summary text.

**Need addressed:** Provides the named filipino translation action within its stated role and route.

**User or operator steps:** Choose Translate on a completed result → Read returned Filipino translation with original as reference.

**Inputs:** Text; target language tl; CSRF

**System behavior:** Validate translation request; call configured translation provider through Python; return translated text

**Expected output:** Translation panel or error

**Related workflows:** Python integration

**Frontend:** app/public/result.php; app/public/assets/js/result.js

**Backend:** app/public/translate_proxy.php; app/src/Services/LocalPythonBridge.php

**Controller/service anchors:** See script/definition map for the cited entry points.

**Database operations:** No persistent database operation in this capability; browser/session/file state may apply as described.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed. Python process and relevant installed packages. External translation provider; selected text leaves the local runtime.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Worker not invoked. Endpoint accepts en/tl; UI requests Filipino.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F030. Rate, comment and update feedback

**Module / role / route:** Feedback / guest/user with summary access / feedback_submit.php

**Plain-language explanation and purpose:** As guest/user with summary access, I can submit a usefulness rating with optional reasons/comment.

**Need addressed:** Provides the named rate, comment and update feedback action within its stated role and route.

**User or operator steps:** Choose a star rating → Optionally add reasons and a short comment → Submit feedback; use Update feedback for an existing response.

**Inputs:** 1–5 stars; optional reasons; comment up to 500 UI characters; CSRF

**System behavior:** Validate one-to-five rating and permitted summary context; update latest rating and append comment events

**Expected output:** Saved/updated feedback confirmation or error

**Related workflows:** Rating and comments

**Frontend:** app/public/partials/rating-widget.php; app/public/assets/js/result.js

**Backend:** app/public/feedback_submit.php; app/src/Services/FeedbackService.php; app/src/Repositories/FeedbackRepository.php; app/src/Controllers/FeedbackHandler.php

**Controller/service anchors:** FeedbackService::submitFeedback (app/src/Services/FeedbackService.php:33); FeedbackService::submitFullFeedback (app/src/Services/FeedbackService.php:148); FeedbackHandler::submitFeedback (app/src/Controllers/FeedbackHandler.php:12); FeedbackHandler::submitFullFeedback (app/src/Controllers/FeedbackHandler.php:24)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: feedback, feedback_comments, summaries.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** No rating submitted; anonymous presentation does not mean no user/guest identifier is stored.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F031. Unavailable result recovery

**Module / role / route:** Results / visitor / result.php?id=…

**Plain-language explanation and purpose:** As visitor, I can receive a safe unavailable-result message.

**Need addressed:** Provides the named unavailable result recovery action within its stated role and route.

**User or operator steps:** Review the unavailable message → Use Back to summary settings or obtain an authorized link.

**Inputs:** Missing, unauthorized or inaccessible result id

**System behavior:** Resolve requested result; render unavailable or recovery view when it cannot be returned

**Expected output:** 404 recovery view or missing-id redirect

**Related workflows:** History and sharing

**Frontend:** app/public/result.php

**Backend:** app/src/Services/HistoryService.php; app/src/Support/ProcessingError.php; app/src/Repositories/HistoryRepository.php; app/src/Controllers/HistoryHandler.php

**Controller/service anchors:** HistoryService::getSummaryById (app/src/Services/HistoryService.php:35); HistoryRepository::findSummaryById (app/src/Repositories/HistoryRepository.php:23); HistoryHandler::getSummaryById (app/src/Controllers/HistoryHandler.php:17)

**Database operations:** SELECT for display/aggregation: summaries, summary_artifacts.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** VERIFIED_UI_IN_ISOLATION

**Observed result:** browser-readonly-results.json (template/local interaction scope only); source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** 404 template verified with synthetic absent id; live authorization not tested.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F032. Personal dashboard and recent summaries

**Module / role / route:** Dashboard / registered non-admin / dashboard.php

**Plain-language explanation and purpose:** As registered non-admin, I can return to recent work and inspect personal activity.

**Need addressed:** Provides the named personal dashboard and recent summaries action within its stated role and route.

**User or operator steps:** Open Dashboard after signing in → Choose an available recent result or start a summary.

**Inputs:** Account session

**System behavior:** Read recent summaries and Nutshell activity; calculate account dashboard counters and display recent work

**Expected output:** Recent five summaries and aggregate/empty dashboard

**Related workflows:** Personal summary/Nutshell history and activity; administrator scope where stated

**Frontend:** app/public/dashboard.php; app/public/partials/analytics-graphs-card.php

**Backend:** app/src/Controllers/AnalyticsController.php; app/src/Repositories/HistoryRepository.php; app/src/Services/HistoryService.php; app/src/Controllers/HistoryHandler.php; app/src/Repositories/UserRepository.php

**Controller/service anchors:** AnalyticsController::getDashboardData (app/src/Controllers/AnalyticsController.php:330); HistoryService::getSummaryHistory (app/src/Services/HistoryService.php:24); HistoryHandler::getSummaryHistory (app/src/Controllers/HistoryHandler.php:12)

**Database operations:** SELECT for display/aggregation: summaries, summary_artifacts, nutshell_generations.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Empty state rendered; no live account statistics read.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F033. Reuse inferred preferred settings

**Module / role / route:** Dashboard / registered non-admin / dashboard.php → summarizer.php

**Plain-language explanation and purpose:** As registered non-admin, I can reuse settings inferred from previous completed summaries.

**Need addressed:** Provides the named reuse inferred preferred settings action within its stated role and route.

**User or operator steps:** If preferred settings are shown, choose the reuse link → Review the resulting controls before submitting.

**Inputs:** Preferred-settings link

**System behavior:** Infer frequently used settings from the account’s saved summary/artifact history and expose reuse controls

**Expected output:** Validated mode/format/depth preselection

**Related workflows:** Personal summary/Nutshell history and activity; administrator scope where stated

**Frontend:** app/public/dashboard.php; app/public/partials/summarizer-form.php

**Backend:** app/src/Controllers/AnalyticsController.php; app/src/Repositories/UserRepository.php

**Controller/service anchors:** AnalyticsController::getUserPreferredSettings (app/src/Controllers/AnalyticsController.php:519)

**Database operations:** SELECT for display/aggregation: summaries, summary_artifacts.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** No separate editable custom-profile storage interface found; history-derived preference is conditional.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F034. Browse summary and nutshell history

**Module / role / route:** History / registered user or admin / history.php

**Plain-language explanation and purpose:** As registered user or admin, I can reopen retained results and read prior nutshells.

**Need addressed:** Provides the named browse summary and nutshell history action within its stated role and route.

**User or operator steps:** Open History → Choose a title or Access Full Report → Read Nutshell History when available.

**Inputs:** Account; optional page query

**System behavior:** Read the requested history page and list summary and Nutshell records for the current context

**Expected output:** History previews and full-report links

**Related workflows:** Personal summary/Nutshell history and activity; administrator scope where stated

**Frontend:** app/public/history.php

**Backend:** app/src/Controllers/HistoryHandler.php; app/src/Repositories/HistoryRepository.php; app/src/Services/HistoryService.php; app/src/Controllers/AnalyticsController.php; app/src/Repositories/UserRepository.php

**Controller/service anchors:** HistoryHandler::getSummaryHistory (app/src/Controllers/HistoryHandler.php:12); HistoryHandler::getNutshellHistory (app/src/Controllers/HistoryHandler.php:22); HistoryService::getSummaryHistory (app/src/Services/HistoryService.php:24); HistoryService::getNutshellHistory (app/src/Services/HistoryService.php:56)

**Database operations:** SELECT for display/aggregation: summaries, summary_artifacts, nutshell_generations.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Partially working

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication. See the reproduced or source-confirmed limitation below.

**Limitations:** The history page displays 10 summaries per page by default (service cap 50) and 20 Nutshell records; no visible history pagination controls are supplied. Saved history was not exercised against production records.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F035. Personal/system activity and date filters

**Module / role / route:** Analytics / registered user or admin / analytics.php

**Plain-language explanation and purpose:** As registered user or admin, I can inspect activity over a chosen period.

**Need addressed:** Provides the named personal/system activity and date filters action within its stated role and route.

**User or operator steps:** Open Analytics through an available dashboard link → Choose a preset or From/To dates → Apply filter and review available charts.

**Inputs:** 7d/30d/3m/year/custom dates; role

**System behavior:** Apply date bounds and role context to summary/Nutshell activity counts, distributions and trends

**Expected output:** Counts, reduction, charts, performance and recent activity

**Related workflows:** Personal summary/Nutshell history and activity; administrator scope where stated

**Frontend:** app/public/analytics.php; app/public/assets/js/analytics.js

**Backend:** app/src/Controllers/AnalyticsController.php; app/src/Repositories/UserRepository.php

**Controller/service anchors:** AnalyticsController::getDashboardData (app/src/Controllers/AnalyticsController.php:330)

**Database operations:** SELECT for display/aggregation: summaries, nutshell_generations, users.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Partially working

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication. See the reproduced or source-confirmed limitation below.

**Limitations:** Empty state rendered; populated charts not validated; admin scope differs from personal scope. Stored upload source counts and elapsed times can be incomplete, affecting derived activity measures.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F036. View account details

**Module / role / route:** Profile / registered account / profile.php

**Plain-language explanation and purpose:** As registered account, I can view username, email and join date.

**Need addressed:** Provides the named view account details action within its stated role and route.

**User or operator steps:** Open Profile → Review account details → Use sidebar to return to dashboard or history.

**Inputs:** Authenticated account

**System behavior:** Find the current account profile and render stored account details

**Expected output:** Read-only identity card

**Related workflows:** Account display, self-deactivation and logout

**Frontend:** app/public/profile.php

**Backend:** app/src/Repositories/UserRepository.php; app/src/Controllers/AuthController.php; app/src/Utils/PasswordPolicy.php

**Controller/service anchors:** UserRepository::findProfileById (app/src/Repositories/UserRepository.php:63)

**Database operations:** SELECT for display/aggregation: users.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Synthetic identity rendered; no edit username/email/photo form exists.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F037. Deactivate own account

**Module / role / route:** Profile / registered account subject to backend protections / profile.php → auth.php?action=deactivateAccount

**Plain-language explanation and purpose:** As registered account subject to backend protections, I can deactivate account after confirming password.

**Need addressed:** Provides the named deactivate own account action within its stated role and route.

**User or operator steps:** Read the deactivation description → Enter current password → Submit only when deactivation is intended.

**Inputs:** Current password; CSRF

**System behavior:** Validate authenticated account action; deactivate the account and end applicable access

**Expected output:** Inactive account and sign-out; summaries retained

**Related workflows:** Login and logout; Account administration

**Frontend:** app/public/profile.php

**Backend:** app/src/Controllers/AuthController.php; app/src/Repositories/UserRepository.php; app/src/Utils/SessionManager.php; app/src/Controllers/AdminController.php; app/public/admin_dashboard.php

**Controller/service anchors:** AuthController::logout (app/src/Controllers/AuthController.php:337); AuthController::deactivateAccount (app/src/Controllers/AuthController.php:354); UserRepository::deactivateAccount (app/src/Repositories/UserRepository.php:91); AdminController::deactivateUser (app/src/Controllers/AdminController.php:262)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: users, user_tokens.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Not executed; backend has administrator protections; source retention is not hard deletion.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F038. Logout confirmation

**Module / role / route:** Accounts / signed-in account / auth.php?action=logout

**Plain-language explanation and purpose:** As signed-in account, I can confirm leaving the active session.

**Need addressed:** Provides the named logout confirmation action within its stated role and route.

**User or operator steps:** Choose Logout → Choose Cancel to remain or Log out to leave.

**Inputs:** Logout link; Cancel/Log out

**System behavior:** Confirm logout in the browser; clear session and applicable remembered-login state

**Expected output:** Cancelled modal or logout redirect

**Related workflows:** Login and logout; Remembered login

**Frontend:** app/public/assets/js/logout-confirmation.js

**Backend:** app/public/auth.php; app/src/Controllers/AuthController.php; app/src/Utils/SessionManager.php; app/src/Services/RememberMeService.php

**Controller/service anchors:** AuthController::logout (app/src/Controllers/AuthController.php:337); RememberMeService::clearCookie (app/src/Services/RememberMeService.php:55)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: user_tokens.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** VERIFIED_UI_IN_ISOLATION

**Observed result:** browser-readonly-results.json supplementalInteractions; synthetic data/client-only action; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Escape closed modal on desktop/mobile; actual logout not executed; GET logout target remains source-supported only.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F039. System overview and storage counters

**Module / role / route:** Administration / administrator / admin_dashboard.php

**Plain-language explanation and purpose:** As administrator, I can inspect aggregate system and upload counters.

**Need addressed:** Provides the named system overview and storage counters action within its stated role and route.

**User or operator steps:** Open Admin → Choose a statistics period → Review overview and storage cards.

**Inputs:** Stats-period select

**System behavior:** Read aggregate system counters and inspect upload storage totals for the administrator

**Expected output:** User/summary/guest/rating counts; storage size/count

**Related workflows:** Usage analytics; Storage administration

**Frontend:** app/public/admin_dashboard.php

**Backend:** app/src/Controllers/AdminController.php; app/src/Controllers/AnalyticsController.php; app/src/Repositories/UserRepository.php

**Controller/service anchors:** AdminController::getSystemStats (app/src/Controllers/AdminController.php:59); AdminController::getUploadOrphanStats (app/src/Controllers/AdminController.php:121)

**Database operations:** SELECT aggregate users, summaries and feedback; inspect filesystem upload counts. Cleanup is a separate action in F055.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** All captured zeros are synthetic; cleanup/delete-file dispatcher actions exist but visible maintenance buttons were not found.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F040. Search and filter users

**Module / role / route:** Administration / administrator / admin_dashboard.php

**Plain-language explanation and purpose:** As administrator, I can filter the displayed account table.

**Need addressed:** Provides the named search and filter users action within its stated role and route.

**User or operator steps:** Enter username/email/id text → Choose role and status → Use Clear filters to reset.

**Inputs:** Search text; role; active-status filters

**System behavior:** Retrieve and filter the administrator’s account list by supplied search/status criteria

**Expected output:** Visible rows and count

**Related workflows:** Account administration

**Frontend:** app/public/assets/js/admin-user-filters.js; app/public/admin_dashboard.php

**Backend:** app/src/Controllers/AdminController.php; app/public/admin_dashboard.php

**Controller/service anchors:** AdminController::getAllUsers (app/src/Controllers/AdminController.php:49)

**Database operations:** SELECT for display/aggregation: users.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Client-side filter over loaded rows; fixture had no users, so nonempty match behavior not browser-tested.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F041. Deactivate managed user

**Module / role / route:** Administration / administrator / admin_dashboard.php?action=deactivate_user

**Plain-language explanation and purpose:** As administrator, I can disable an eligible user account.

**Need addressed:** Provides the named deactivate managed user action within its stated role and route.

**User or operator steps:** Find the intended account → Read the confirmation on Deactivate → Confirm only when intended.

**Inputs:** User id; CSRF; confirmation

**System behavior:** Validate administrator status-changing request and update target account status; dedicated and alternate paths have different safeguards

**Expected output:** Account deactivation or error

**Related workflows:** Account administration

**Frontend:** app/public/admin_dashboard.php

**Backend:** app/src/Controllers/AdminController.php; app/public/admin_dashboard.php

**Controller/service anchors:** AdminController::toggleUserStatus (app/src/Controllers/AdminController.php:112); AdminController::deactivateUser (app/src/Controllers/AdminController.php:262)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: users, admin_audit_logs.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Partially working

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication. See the reproduced or source-confirmed limitation below.

**Limitations:** Destructive/stateful action source-inspected only; not executed. The alternate toggle-status action bypasses protections present in the dedicated deactivation method.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F042. Permanently delete managed user

**Module / role / route:** Administration / administrator / admin_dashboard.php?action=delete_user

**Plain-language explanation and purpose:** As administrator, I can remove an eligible account and associated data.

**Need addressed:** Provides the named permanently delete managed user action within its stated role and route.

**User or operator steps:** Find and verify the intended account → Read the explicit permanent-deletion warning → Confirm only under approved administration procedures.

**Inputs:** User id; CSRF; confirmation

**System behavior:** Validate authorized hard-delete action; remove target account and process related cleanup according to source and constraints

**Expected output:** Deletion or protected-account/error result

**Related workflows:** Account administration

**Frontend:** app/public/admin_dashboard.php

**Backend:** app/src/Controllers/AdminController.php; app/public/admin_dashboard.php

**Controller/service anchors:** AdminController::hardDeleteUser (app/src/Controllers/AdminController.php:212)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: users, admin_audit_logs.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Not executed; deletion is distinct from deactivation and may remove linked data.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F043. Filter feedback and quality telemetry

**Module / role / route:** Administration / administrator / admin_dashboard.php

**Plain-language explanation and purpose:** As administrator, I can review anonymous-presented feedback and summary setting patterns.

**Need addressed:** Provides the named filter feedback and quality telemetry action within its stated role and route.

**User or operator steps:** Review quality telemetry → Filter rating/comment state and sort order → Open a result link where authorized.

**Inputs:** Rating filter; has-comment filter; sort

**System behavior:** Filter and aggregate feedback and retrieve related summary information for administrator display

**Expected output:** Feedback/comment tables; mode/depth/reason aggregates

**Related workflows:** Rating and comments

**Frontend:** app/public/admin_dashboard.php

**Backend:** app/src/Controllers/FeedbackHandler.php; app/src/Repositories/FeedbackRepository.php; app/src/Services/FeedbackService.php

**Controller/service anchors:** FeedbackHandler::getSystemFeedbackStats (app/src/Controllers/FeedbackHandler.php:59); FeedbackHandler::getAllFeedbackForAdmin (app/src/Controllers/FeedbackHandler.php:64); FeedbackHandler::getFeedbackTelemetryBreakdown (app/src/Controllers/FeedbackHandler.php:69); FeedbackRepository::getSystemFeedbackStats (app/src/Repositories/FeedbackRepository.php:83); FeedbackRepository::getAllFeedbackForAdmin (app/src/Repositories/FeedbackRepository.php:115); FeedbackRepository::getFeedbackTelemetryBreakdown (app/src/Repositories/FeedbackRepository.php:183); FeedbackService::getSystemFeedbackStats (app/src/Services/FeedbackService.php:287); FeedbackService::getAllFeedbackForAdmin (app/src/Services/FeedbackService.php:298); FeedbackService::getFeedbackTelemetryBreakdown (app/src/Services/FeedbackService.php:309)

**Database operations:** SELECT/filter feedback LEFT JOIN summaries; the displayed administrator sections do not read the append-only feedback_comments ledger.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Empty fixture only; underlying records are linkable internally despite anonymous display.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F044. Landing telemetry dashboard

**Module / role / route:** Administration / administrator / admin_dashboard.php

**Plain-language explanation and purpose:** As administrator, I can inspect landing engagement metrics.

**Need addressed:** Provides the named landing telemetry dashboard action within its stated role and route.

**User or operator steps:** Scroll to Landing Page Analytics → Read labels and applicable date periods → Interpret missing values as unavailable.

**Inputs:** Stored landing sessions/events

**System behavior:** Collect permitted landing session/event telemetry and aggregate visit/conversion/device/referrer indicators

**Expected output:** Session, CTA, dwell, LCP, device, scroll, trend and referrer panels

**Related workflows:** Usage analytics

**Frontend:** app/public/admin_dashboard.php; app/public/assets/js/landing-analytics.js

**Backend:** app/public/api/track_landing.php; app/src/Controllers/AnalyticsController.php; app/src/Repositories/UserRepository.php

**Controller/service anchors:** AnalyticsController::getKpis (app/src/Controllers/AnalyticsController.php:35); AnalyticsController::getDeviceBreakdown (app/src/Controllers/AnalyticsController.php:125); AnalyticsController::getScrollFunnel (app/src/Controllers/AnalyticsController.php:155); AnalyticsController::getDailyTrend (app/src/Controllers/AnalyticsController.php:185); AnalyticsController::getTopReferrers (app/src/Controllers/AnalyticsController.php:492)

**Database operations:** INSERT/UPDATE landing_page_sessions and INSERT landing_page_events during permitted ingestion; displayed visit/device/scroll/referrer charts SELECT landing_page_sessions.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** No telemetry emitted; the landing view itself normally sends beacons; counters are not human survey evaluation.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F045. Audit log viewer

**Module / role / route:** Administration / administrator / admin_audit_logs.php

**Plain-language explanation and purpose:** As administrator, I can review privileged action logs.

**Need addressed:** Provides the named audit log viewer action within its stated role and route.

**User or operator steps:** Open Audit Logs → Choose filters and submit → Use pagination when present.

**Inputs:** Action/admin/date filters; page

**System behavior:** Require administrator access; filter audit records and join actor identity for display

**Expected output:** Filtered log rows and pagination

**Related workflows:** Administrative action accountability

**Frontend:** app/public/admin_audit_logs.php

**Backend:** app/src/Services/AdminSecurityService.php; app/src/Controllers/AuthController.php; app/src/Services/EmailService.php

**Controller/service anchors:** See script/definition map for the cited entry points.

**Database operations:** SELECT for display/aggregation: admin_audit_logs, users.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Synthetic missing-table empty state captured; real audit log contents not read.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F046. Database health JSON

**Module / role / route:** Administration / administrator / admin_db_health.php

**Plain-language explanation and purpose:** As administrator, I can check required-table/column presence.

**Need addressed:** Provides the named database health json action within its stated role and route.

**User or operator steps:** Open the diagnostic endpoint under administrator access and interpret status fields.

**Inputs:** Authenticated administrator session

**System behavior:** Require administrator context; query database metadata and return health information

**Expected output:** Status JSON and development-dependent detail

**Related workflows:** Navigation and result presentation

**Frontend:** app/public/admin_db_health.php

**Backend:** app/src/Database.php

**Controller/service anchors:** Database::getInstance (app/src/Database.php:38); Database::getConnection (app/src/Database.php:45); Database::__wakeup (app/src/Database.php:51)

**Database operations:** SELECT for display/aggregation: information_schema (metadata only).

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Anonymous isolated 403 verified; authenticated database health not checked against live schema.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F047. Assigned independent summary review

**Module / role / route:** Evaluation / active account with assignment / evaluate.php

**Plain-language explanation and purpose:** As active account with assignment, I can read an assigned source/summary and score it independently.

**Need addressed:** Provides the named assigned independent summary review action within its stated role and route.

**User or operator steps:** Open the assigned review link while signed in → Read source and blinded summary → Score each criterion separately → Submit once; contact administrator for corrections.

**Inputs:** Assignment token; seven 1–5 criteria; optional comment; CSRF

**System behavior:** Check current assignment access; display blinded output and accept the required review criteria when submitted

**Expected output:** Submitted-and-locked assessment or error

**Related workflows:** Blind evaluation assignments

**Frontend:** app/public/evaluate.php; app/public/partials/evaluation-ui.php

**Backend:** app/src/Services/EvaluationAccess.php; app/src/Services/EvaluationService.php; app/public/evaluate.php

**Controller/service anchors:** EvaluationService::createAssignments (app/src/Services/EvaluationService.php:366); EvaluationService::listAssignments (app/src/Services/EvaluationService.php:395); EvaluationService::getBlindAssignment (app/src/Services/EvaluationService.php:399); EvaluationService::validateRatings (app/src/Services/EvaluationService.php:408); EvaluationService::submitRating (app/src/Services/EvaluationService.php:419); EvaluationService::getAssignmentsForRun (app/src/Services/EvaluationService.php:446)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: evaluation_assignments, evaluation_ratings, evaluation_outputs.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** Empty/denied UI captured; no assignment or rating created; this 1–5 content rubric is not the capstone ISO usability instrument.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F048. Evaluation export and report rendering

**Module / role / route:** Evaluation / administrator / evaluation_export.php?run=…&format=json|csv|html

**Plain-language explanation and purpose:** As administrator, I can export an existing evaluation run.

**Need addressed:** Provides the named evaluation export and report rendering action within its stated role and route.

**User or operator steps:** Use an existing approved run identifier →  request the selected format →  protect exported records.

**Inputs:** Positive run id; json/csv/html format

**System behavior:** Require administrator access; build available evaluation report/export with supported identifier removal and spreadsheet safeguards

**Expected output:** Download or HTML report

**Related workflows:** Evaluation reports and export

**Frontend:** app/public/evaluation_export.php; app/public/partials/evaluation-report.php; app/public/partials/evaluation-output.php

**Backend:** app/src/Services/EvaluationService.php; app/public/evaluation_export.php

**Controller/service anchors:** EvaluationService::getRunReport (app/src/Services/EvaluationService.php:474); EvaluationService::exportRun (app/src/Services/EvaluationService.php:481); EvaluationService::saveReport (app/src/Services/EvaluationService.php:625); EvaluationService::reportFailure (app/src/Services/EvaluationService.php:639)

**Database operations:** SELECT for display/aggregation: evaluation_runs, evaluation_outputs, evaluation_metrics, evaluation_ratings, evaluation_errors.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Partially working

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication. See the reproduced or source-confirmed limitation below.

**Limitations:** No real run exported; only anonymous 403 tested. evaluation.php now redirects, so legacy management tabs are not operational entry points. Export code exists, but links to the retired evaluation-management page redirect; no real evaluation report was generated.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F049. Document Q&A endpoint

**Module / role / route:** API-only / authorized summary viewer / api/qa.php

**Plain-language explanation and purpose:** As authorized summary viewer, I can request source-grounded answers through the API.

**Need addressed:** Provides the named document q&a endpoint action within its stated role and route.

**User or operator steps:** Use an authorized API client →  provide an accessible source summary and question →  inspect source evidence.

**Inputs:** POST JSON summary_id, question, optional share_token; no CSRF check found in this endpoint

**System behavior:** Accept a source-related question; obtain permitted source context; rank passages and return a grounded answer or unavailable result

**Expected output:** Answer/passage/confidence JSON or error

**Related workflows:** Python integration; History and sharing

**Frontend:** app/public/api/qa.php

**Backend:** app/src/Services/LocalPythonBridge.php; app/src/Services/HistoryService.php; app/src/Repositories/HistoryRepository.php; app/src/Controllers/HistoryHandler.php

**Controller/service anchors:** HistoryService::getSummaryById (app/src/Services/HistoryService.php:35); HistoryRepository::findSummaryById (app/src/Repositories/HistoryRepository.php:23); HistoryHandler::getSummaryById (app/src/Controllers/HistoryHandler.php:17)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: summaries, summary_artifacts.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed. Python process and relevant installed packages.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication.

**Limitations:** No Q&A interaction widget found in current result.js/result.php. Do not advertise a visible chat feature. The API-only endpoint has no main-interface chat widget; a source-grounded Python answer passed in isolation. The route lacks a CSRF check and may disclose raw exception detail.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F050. Document and multi-document REST operations

**Module / role / route:** API-only / Intended document caller; dispatcher does not enforce authentication or ownership / api/router.php and aliases

**Plain-language explanation and purpose:** As session/role controlled by dispatcher, I can access document operations through routed API.

**Need addressed:** Provides the named document and multi-document rest operations action within its stated role and route.

**User or operator steps:** Consult controller dispatch contract before using an integration.

**Inputs:** Method/path and controller-specific payload

**System behavior:** Dispatch document creation, retrieval, cached-summary combinations, listing, batch or streaming operations; ownership enforcement is missing

**Expected output:** Document/status/summary response

**Related workflows:** Document combination API

**Frontend:** app/public/api/router.php; app/public/api/documents.php; app/public/api/index.php

**Backend:** app/src/Controllers/DocumentSummaryController.php; app/src/Services/DocumentSummaryService.php; app/src/Services/SummarizerPromptTemplate.php

**Controller/service anchors:** DocumentSummaryController::getLastStatusCode (app/src/Controllers/DocumentSummaryController.php:26); DocumentSummaryController::dispatch (app/src/Controllers/DocumentSummaryController.php:34); DocumentSummaryController::handleCreateDocument (app/src/Controllers/DocumentSummaryController.php:115); DocumentSummaryController::handleGetOrGenerateSummary (app/src/Controllers/DocumentSummaryController.php:142); DocumentSummaryController::handleGetSummaries (app/src/Controllers/DocumentSummaryController.php:163); DocumentSummaryController::handleBatchSummaries (app/src/Controllers/DocumentSummaryController.php:172); DocumentSummaryService::createDocument (app/src/Services/DocumentSummaryService.php:40); DocumentSummaryService::getDocument (app/src/Services/DocumentSummaryService.php:62); DocumentSummaryService::validateProfileAndLength (app/src/Services/DocumentSummaryService.php:78); DocumentSummaryService::isNonsenseRequest (app/src/Services/DocumentSummaryService.php:101); DocumentSummaryService::countWords (app/src/Services/DocumentSummaryService.php:122); DocumentSummaryService::calculateCompressionRatio (app/src/Services/DocumentSummaryService.php:135); DocumentSummaryService::calculateReadingTimeSeconds (app/src/Services/DocumentSummaryService.php:147); DocumentSummaryService::generateSummary (app/src/Services/DocumentSummaryService.php:158); DocumentSummaryService::getOrGenerateSummary (app/src/Services/DocumentSummaryService.php:216); DocumentSummaryService::createDocumentWithDefaultSummary (app/src/Services/DocumentSummaryService.php:284); DocumentSummaryService::getSummaries (app/src/Services/DocumentSummaryService.php:299); DocumentSummaryService::batchGetOrGenerateSummaries (app/src/Services/DocumentSummaryService.php:323); SummarizerPromptTemplate::isAllowedProfile (app/src/Services/SummarizerPromptTemplate.php:77); SummarizerPromptTemplate::isAllowedLength (app/src/Services/SummarizerPromptTemplate.php:82); SummarizerPromptTemplate::getProfileInstruction (app/src/Services/SummarizerPromptTemplate.php:87); SummarizerPromptTemplate::getLengthInstruction (app/src/Services/SummarizerPromptTemplate.php:93); SummarizerPromptTemplate::renderPrompt (app/src/Services/SummarizerPromptTemplate.php:102)

**Database operations:** Read and/or write through the cited service; exact method and constraints are recorded in the backend audit: documents, document_summaries.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed. Python process and relevant installed packages.

**Implementation / classification:** Yes / Partially working

**Testing:** SOURCE-SUPPORTED

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; source inspection establishes implementation; template rendering does not establish successful persistence or authentication. See the reproduced or source-confirmed limitation below.

**Limitations:** No multi-file selector in main form; result can render multi_document metadata when present. Actual controller/service fixtures returned another owner’s document and an anonymous document with HTTP 200. The dispatcher also lacks a CSRF check.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json; evidence/backend-isolated-checks.json

## F051. Static service health

**Module / role / route:** Health / visitor or monitor / health.php

**Plain-language explanation and purpose:** As visitor or monitor, I can confirm the PHP health endpoint responds.

**Need addressed:** Provides the named static service health action within its stated role and route.

**User or operator steps:** Request health.php → Check HTTP status and JSON service label.

**Inputs:** GET only in this audit

**System behavior:** Return a static JSON health response without loading the application bootstrap

**Expected output:** status ok / service ai-summarizer JSON

**Related workflows:** Navigation and result presentation

**Frontend:** app/public/health.php

**Backend:** No backend for the named browser-only action.

**Controller/service anchors:** See script/definition map for the cited entry points.

**Database operations:** No persistent database operation in this capability; browser/session/file state may apply as described.

**External dependencies:** Browser and PHP runtime; MariaDB where tables are listed.

**Implementation / classification:** Yes / Verified working

**Testing:** Isolated local interaction / static route only

**Observed result:** Source inspection; UI rendered in isolation where listed in screenshot register; the named local interaction passed; no server persistence is claimed.

**Limitations:** This static check does not establish database, NLP worker or full application readiness.

**Evidence:** ui-feature-notes.json; browser-readonly-results.json; UI_UX_AUDIT.md; evidence/backend-feature-notes.json

## F052. Guest lifecycle

**Module / role / route:** Backend services / guest, user / Session start, account sign-in or expiry processing

**Plain-language explanation and purpose:** Guest access keeps a temporary session so eligible results can be retrieved before an account is used.

**Need addressed:** Supports guest lifecycle.

**User or operator steps:** Use the named authorized entry point in an isolated deployment → Provide the specified inputs → Inspect the response and related records.

**Inputs:** Temporary token and existing guest-owned records

**System behavior:** create token; touch expiry; claim summaries/feedback; purge expired guest data

**Expected output:** Guest access token; eligible records transferred or expired

**Related workflows:** Main processing, administration and reproducible operation

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** app/src/Services/GuestSessionService.php; app/src/whitereaper.php

**Controller/service anchors:** GuestSessionService::getCurrentGuestToken (app/src/Services/GuestSessionService.php:38); GuestSessionService::getOrCreateGuestToken (app/src/Services/GuestSessionService.php:48); GuestSessionService::claimGuestDataForUser (app/src/Services/GuestSessionService.php:58); GuestSessionService::clearGuestIdentity (app/src/Services/GuestSessionService.php:108); GuestSessionService::purgeExpiredGuests (app/src/Services/GuestSessionService.php:118)

**Database operations:** create token; touch expiry; claim summaries/feedback; purge expired guest data against guest_sessions, summaries, feedback.

**External dependencies:** Configured PHP/Python/database runtime as applicable; worker and model dependencies must be provisioned separately.

**Implementation / classification:** Yes / Partially working

**Testing:** Source inspection; method existence is not an executed workflow

**Observed result:** Source-confirmed; GET bootstrap mutates state; lifecycle incomplete for other stores.

**Limitations:** Source-confirmed; GET bootstrap mutates state; lifecycle incomplete for other stores.

**Evidence:** BACKEND_AND_DATABASE_AUDIT.md; SECURITY_AND_RELIABILITY_AUDIT.md; evidence/backend-feature-notes.json

## F053. Python integration

**Module / role / route:** Backend services / internal / Internal request via LocalPythonBridge

**Plain-language explanation and purpose:** The PHP application passes processing work to Python and interprets its response.

**Need addressed:** Supports python integration.

**User or operator steps:** Use the named authorized entry point in an isolated deployment → Provide the specified inputs → Inspect the response and related records.

**Inputs:** Action, normalized payload and worker configuration

**System behavior:** daemon dispatch; CLI dispatch; structured response; timeout; terminate child tree

**Expected output:** Structured Python response or bounded error

**Related workflows:** Main processing, administration and reproducible operation

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** app/src/Services/LocalPythonBridge.php

**Controller/service anchors:** LocalPythonBridge::summarize (app/src/Services/LocalPythonBridge.php:31); LocalPythonBridge::summarizeV2 (app/src/Services/LocalPythonBridge.php:36)

**Database operations:** daemon dispatch; CLI dispatch; structured response; timeout; terminate child tree against process/filesystem state.

**External dependencies:** Configured PHP/Python/database runtime as applicable; worker and model dependencies must be provisioned separately.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** Source inspection; method existence is not an executed workflow

**Observed result:** Source-confirmed; authenticated loopback protocol3; Python behavior separately audited.

**Limitations:** Source-confirmed; authenticated loopback protocol3; Python behavior separately audited.

**Evidence:** BACKEND_AND_DATABASE_AUDIT.md; SECURITY_AND_RELIABILITY_AUDIT.md; evidence/backend-feature-notes.json

## F054. Summary persistence

**Module / role / route:** Backend services / internal / Successful background summary completion

**Plain-language explanation and purpose:** Successful processing is intended to save a summary and supporting source for later display.

**Need addressed:** Supports summary persistence.

**User or operator steps:** Use the named authorized entry point in an isolated deployment → Provide the specified inputs → Inspect the response and related records.

**Inputs:** Normalized output, source and metadata

**System behavior:** normalize worker contract; store summary; store source/artifact; attach metadata

**Expected output:** Saved summary identifier and artifact

**Related workflows:** Main processing, administration and reproducible operation

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** app/src/Services/ArticleService.php

**Controller/service anchors:** ArticleService::storeSummary (app/src/Services/ArticleService.php:263)

**Database operations:** SELECT users to verify account existence; INSERT summaries and summary_artifacts and attach normalized metadata.

**External dependencies:** Configured PHP/Python/database runtime as applicable; worker and model dependencies must be provisioned separately.

**Implementation / classification:** Yes / Partially working

**Testing:** Source inspection; method existence is not an executed workflow

**Observed result:** Source-confirmed; uploaded source word counts and processing time incomplete.

**Limitations:** Source-confirmed; uploaded source word counts and processing time incomplete.

**Evidence:** BACKEND_AND_DATABASE_AUDIT.md; SECURITY_AND_RELIABILITY_AUDIT.md; evidence/backend-feature-notes.json

## F055. Storage administration

**Module / role / route:** Backend services / admin / Administrator storage controls

**Plain-language explanation and purpose:** Administrators can inspect upload storage and invoke cleanup controls.

**Need addressed:** Supports storage administration.

**User or operator steps:** Use the named authorized entry point in an isolated deployment → Provide the specified inputs → Inspect the response and related records.

**Inputs:** Authorized cleanup action and upload directory

**System behavior:** count uploads; delete aged uploads; delete all uploads

**Expected output:** File counts or deletion outcome

**Related workflows:** Main processing, administration and reproducible operation

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** app/src/Controllers/AdminController.php; app/src/Services/AdminSecurityService.php

**Controller/service anchors:** AdminController::getUploadOrphanStats (app/src/Controllers/AdminController.php:121); AdminController::cleanOrphanedFiles (app/src/Controllers/AdminController.php:144); AdminController::deleteAllFiles (app/src/Controllers/AdminController.php:175)

**Database operations:** Inspect or delete upload files as requested; record successful administrator cleanup in admin_audit_logs. No database-reference check establishes orphan status.

**External dependencies:** Configured PHP/Python/database runtime as applicable; worker and model dependencies must be provisioned separately.

**Implementation / classification:** Yes / Partially working

**Testing:** Source inspection; method existence is not an executed workflow

**Observed result:** Source-confirmed destructive capability; age-only cleanup is not reference-aware orphan detection; not executed.

**Limitations:** Source-confirmed destructive capability; age-only cleanup is not reference-aware orphan detection; not executed.

**Evidence:** BACKEND_AND_DATABASE_AUDIT.md; SECURITY_AND_RELIABILITY_AUDIT.md; evidence/backend-feature-notes.json

## F056. Evaluation dataset preparation

**Module / role / route:** Evaluation / admin service caller, CLI maintainer / EvaluationService and scripts/evaluation_import.php

**Plain-language explanation and purpose:** A maintainer can prepare material for controlled algorithm evaluation through implemented services and import tools.

**Need addressed:** Supports evaluation dataset preparation.

**User or operator steps:** Use the named authorized entry point in an isolated deployment → Provide the specified inputs → Inspect the response and related records.

**Inputs:** Dataset metadata, source documents and reference annotations

**System behavior:** create dataset; add document/provenance/split; add references; add content units; add challenge cases; activate/deactivate

**Expected output:** Dataset, reference and content-unit records

**Related workflows:** Main processing, administration and reproducible operation

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** app/src/Services/EvaluationService.php; scripts/evaluation_import.php

**Controller/service anchors:** EvaluationService::createDataset (app/src/Services/EvaluationService.php:144); EvaluationService::addDocument (app/src/Services/EvaluationService.php:158); EvaluationService::addReference (app/src/Services/EvaluationService.php:177); EvaluationService::addContentUnit (app/src/Services/EvaluationService.php:191); EvaluationService::addChallengeCase (app/src/Services/EvaluationService.php:202); EvaluationService::listDatasets (app/src/Services/EvaluationService.php:216); EvaluationService::listDocuments (app/src/Services/EvaluationService.php:217); EvaluationService::getDocument (app/src/Services/EvaluationService.php:218); EvaluationService::setDatasetActive (app/src/Services/EvaluationService.php:227); EvaluationService::setDocumentActive (app/src/Services/EvaluationService.php:236); EvaluationService::verifyContentUnit (app/src/Services/EvaluationService.php:461)

**Database operations:** create dataset; add document/provenance/split; add references; add content units; add challenge cases; activate/deactivate against evaluation_datasets, evaluation_documents, evaluation_references, evaluation_content_units, evaluation_challenge_cases, evaluation_audit_log.

**External dependencies:** Configured PHP/Python/database runtime as applicable; worker and model dependencies must be provisioned separately.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** Source inspection; method existence is not an executed workflow

**Observed result:** Source-confirmed service/CLI; not evidence of reachable browser controls or completed research corpus.

**Limitations:** Source-confirmed service/CLI; not evidence of reachable browser controls or completed research corpus.

**Evidence:** BACKEND_AND_DATABASE_AUDIT.md; SECURITY_AND_RELIABILITY_AUDIT.md; evidence/backend-feature-notes.json

## F057. Evaluation run control

**Module / role / route:** Evaluation / admin service caller / EvaluationService run methods

**Plain-language explanation and purpose:** Evaluation run control records the settings used for a comparison so results can be traced to their inputs.

**Need addressed:** Supports evaluation run control.

**User or operator steps:** Use the named authorized entry point in an isolated deployment → Provide the specified inputs → Inspect the response and related records.

**Inputs:** Dataset, profile/length/system combinations and configuration

**System behavior:** queue immutable snapshot; cancel; resume; code fingerprint; bounded combinations

**Expected output:** Queued immutable run snapshot and output work items

**Related workflows:** Main processing, administration and reproducible operation

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** app/src/Services/EvaluationService.php

**Controller/service anchors:** EvaluationService::queueRun (app/src/Services/EvaluationService.php:245); EvaluationService::cancelRun (app/src/Services/EvaluationService.php:335); EvaluationService::resumeRun (app/src/Services/EvaluationService.php:344)

**Database operations:** Read dataset/document/reference/unit/challenge snapshot, seal the dataset, and write run/output/audit state. Profiles are supplied by evaluation-document settings.

**External dependencies:** Configured PHP/Python/database runtime as applicable; worker and model dependencies must be provisioned separately.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** Source inspection; method existence is not an executed workflow

**Observed result:** Source-confirmed; five lengths/eight profiles/five systems/ten ablations; optionalLLM may unavailable.

**Limitations:** Source-confirmed; five lengths/eight profiles/five systems/ten ablations; optionalLLM may unavailable.

**Evidence:** BACKEND_AND_DATABASE_AUDIT.md; SECURITY_AND_RELIABILITY_AUDIT.md; evidence/backend-feature-notes.json

## F058. Offline evaluation worker

**Module / role / route:** Evaluation / CLI maintainer / scripts/evaluation_worker.php

**Plain-language explanation and purpose:** An offline worker processes queued comparison items and records available measurements.

**Need addressed:** Supports offline evaluation worker.

**User or operator steps:** Use the named authorized entry point in an isolated deployment → Provide the specified inputs → Inspect the response and related records.

**Inputs:** Queued run and installed evaluation dependencies

**System behavior:** single-database lock; generate outputs; persist metrics; record failures; refresh report

**Expected output:** Generated outputs, metric records and error states

**Related workflows:** Main processing, administration and reproducible operation

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** scripts/evaluation_worker.php; app/src/Services/EvaluationPythonBridge.php; app/src/Services/EvaluationService.php

**Controller/service anchors:** EvaluationPythonBridge::request (app/src/Services/EvaluationPythonBridge.php:19); EvaluationPythonBridge::close (app/src/Services/EvaluationPythonBridge.php:80); EvaluationService::getOutputs (app/src/Services/EvaluationService.php:304); EvaluationService::getOutputDetail (app/src/Services/EvaluationService.php:308); EvaluationService::getRunReport (app/src/Services/EvaluationService.php:474); EvaluationService::pendingOutputs (app/src/Services/EvaluationService.php:515); EvaluationService::workerPayload (app/src/Services/EvaluationService.php:517); EvaluationService::persistOutput (app/src/Services/EvaluationService.php:534); EvaluationService::failOutput (app/src/Services/EvaluationService.php:571); EvaluationService::saveReport (app/src/Services/EvaluationService.php:625); EvaluationService::reportFailure (app/src/Services/EvaluationService.php:639)

**Database operations:** single-database lock; generate outputs; persist metrics; record failures; refresh report against evaluation_runs, evaluation_outputs, evaluation_metrics.

**External dependencies:** Configured PHP/Python/database runtime as applicable; worker and model dependencies must be provisioned separately.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** Source inspection; method existence is not an executed workflow

**Observed result:** Source-confirmed; production worker not run; missing metric statuses retained.

**Limitations:** Source-confirmed; production worker not run; missing metric statuses retained.

**Evidence:** BACKEND_AND_DATABASE_AUDIT.md; SECURITY_AND_RELIABILITY_AUDIT.md; evidence/backend-feature-notes.json

## F059. Evaluation review and correction

**Module / role / route:** Evaluation / admin service caller / EvaluationService review methods

**Plain-language explanation and purpose:** Authorized review services record corrections and detailed analysis without treating automatic measurements as human ratings.

**Need addressed:** Supports evaluation review and correction.

**User or operator steps:** Use the named authorized entry point in an isolated deployment → Provide the specified inputs → Inspect the response and related records.

**Inputs:** Output, rating correction reason, error label or content-unit review

**System behavior:** correct rating with reason; classify error; verify content unit representation

**Expected output:** Recorded correction/review with audit history

**Related workflows:** Main processing, administration and reproducible operation

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** app/src/Services/EvaluationService.php

**Controller/service anchors:** EvaluationService::validateRatings (app/src/Services/EvaluationService.php:408); EvaluationService::correctRating (app/src/Services/EvaluationService.php:435); EvaluationService::classifyError (app/src/Services/EvaluationService.php:450); EvaluationService::verifyContentUnit (app/src/Services/EvaluationService.php:461)

**Database operations:** Read run, output and assignment context; record reviewed/corrected rows and audit events; update run report_pending state.

**External dependencies:** Configured PHP/Python/database runtime as applicable; worker and model dependencies must be provisioned separately.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** Source inspection; method existence is not an executed workflow

**Observed result:** Source-confirmed; source method existence does not establish current UI reachability.

**Limitations:** Source-confirmed; source method existence does not establish current UI reachability.

**Evidence:** BACKEND_AND_DATABASE_AUDIT.md; SECURITY_AND_RELIABILITY_AUDIT.md; evidence/backend-feature-notes.json

## F060. Migration and installation

**Module / role / route:** Operations / CLI maintainer / tools/import_schema.php and tools/migrate.php

**Plain-language explanation and purpose:** Installation tools establish or update database structures; they must be rehearsed against a disposable database.

**Need addressed:** Supports migration and installation.

**User or operator steps:** Use the named authorized entry point in an isolated deployment → Provide the specified inputs → Inspect the response and related records.

**Inputs:** Database configuration and checked-in SQL/migrations

**System behavior:** import base schemas; apply migrations; migration ledger/status

**Expected output:** Schema changes and migration ledger

**Related workflows:** Main processing, administration and reproducible operation

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** tools/import_schema.php; tools/migrate.php

**Controller/service anchors:** See script/definition map for the cited entry points.

**Database operations:** Installer and migration DDL can create/alter the full application schema; schema_migrations records the ledger. Status initialization can also write ledger/baseline state.

**External dependencies:** Configured PHP/Python/database runtime as applicable; worker and model dependencies must be provisioned separately.

**Implementation / classification:** Yes / Partially working

**Testing:** Source inspection; method existence is not an executed workflow

**Observed result:** Source-confirmed; status maywrite; passwordresetfreshinstalldrift; not executed.

**Limitations:** Source-confirmed; status maywrite; passwordresetfreshinstalldrift; not executed.

**Evidence:** BACKEND_AND_DATABASE_AUDIT.md; SECURITY_AND_RELIABILITY_AUDIT.md; evidence/backend-feature-notes.json

## F061. Retention cleanup

**Module / role / route:** Operations / CLI maintainer, bootstrap / Cleanup CLI scripts and guest lifecycle

**Plain-language explanation and purpose:** Retention helpers limit selected old telemetry, comments and temporary recovery material.

**Need addressed:** Supports retention cleanup.

**User or operator steps:** Use the named authorized entry point in an isolated deployment → Provide the specified inputs → Inspect the response and related records.

**Inputs:** Retention interval or expiry timestamps

**System behavior:** landing90day default; audit90day default; feedbackcomments90day default; expired job payloads; guest expiry

**Expected output:** Pruned eligible records/payloads

**Related workflows:** Main processing, administration and reproducible operation

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** scripts/cleanup_landing_analytics.php; scripts/cleanup_admin_audit_logs.php; scripts/cleanup_feedback_events.php; scripts/cleanup_summary_recovery.php; app/src/Services/GuestSessionService.php; app/src/Services/SummaryJobService.php; app/src/Services/FeedbackService.php

**Controller/service anchors:** See script/definition map for the cited entry points.

**Database operations:** Delete or prune eligible telemetry, audit, feedback-comment, job and guest records; guest purge includes summaries/feedback and landing session deletion can cascade to events. Coverage is incomplete for other stores.

**External dependencies:** Configured PHP/Python/database runtime as applicable; worker and model dependencies must be provisioned separately.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** Source inspection; method existence is not an executed workflow

**Observed result:** Source-confirmed; notproofscheduled; dryrunbootstrapcanwrite; no automaticbackupfound.

**Limitations:** Source-confirmed; notproofscheduled; dryrunbootstrapcanwrite; no automaticbackupfound.

**Evidence:** BACKEND_AND_DATABASE_AUDIT.md; SECURITY_AND_RELIABILITY_AUDIT.md; evidence/backend-feature-notes.json

## F062. Windows worker supervision

**Module / role / route:** Operations / CLI maintainer / Windows worker PowerShell scripts

**Plain-language explanation and purpose:** Maintainers can start, monitor and stop the local Python worker using supplied Windows scripts.

**Need addressed:** Supports windows worker supervision.

**User or operator steps:** Use the named authorized entry point in an isolated deployment → Provide the specified inputs → Inspect the response and related records.

**Inputs:** Python executable, worker paths and task settings

**System behavior:** install logon task; healthcheck30seconds; hidden launch; selftest; verify process before stop; remove task

**Expected output:** Worker lifecycle, health checks and scheduled-task configuration

**Related workflows:** Main processing, administration and reproducible operation

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** scripts/install_nlp_worker_task.ps1; scripts/start_nlp_worker.ps1; scripts/monitor_nlp_worker.ps1; scripts/stop_stale_nlp_workers.ps1; scripts/remove_nlp_worker_task.ps1

**Controller/service anchors:** See script/definition map for the cited entry points.

**Database operations:** install logon task; healthcheck30seconds; hidden launch; selftest; verify process before stop; remove task against process/filesystem state.

**External dependencies:** Configured PHP/Python/database runtime as applicable; worker and model dependencies must be provisioned separately.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** Source inspection; method existence is not an executed workflow

**Observed result:** Source-confirmed; installed/runningtaskstate not inspected.

**Limitations:** Source-confirmed; installed/runningtaskstate not inspected.

**Evidence:** BACKEND_AND_DATABASE_AUDIT.md; SECURITY_AND_RELIABILITY_AUDIT.md; evidence/backend-feature-notes.json

## F063. Bearer-token result access

**Module / role / route:** Results / owner and share-token holder / result.php share-token lookup

**Plain-language explanation and purpose:** A person holding an authorized share link can retrieve its associated result without becoming its owner.

**Need addressed:** Supports bearer-token result access.

**User or operator steps:** Open the relevant result or call the documented internal action through its intended boundary → Provide the required source or access token → Inspect the resulting output and any warnings.

**Inputs:** Summary identifier and bearer share token

**System behavior:** Look up matching token and summary; load result artifact

**Expected output:** Result display for token holder

**Related workflows:** Source intake, processing and result retrieval

**Frontend:** app/public/result.php

**Backend:** app/src/Services/HistoryService.php; app/src/Repositories/HistoryRepository.php

**Controller/service anchors:** HistoryService::getSummaryById / viewerOwnsSummary / viewerHasSharedAccess; HistoryRepository::findSummaryById

**Database operations:** SELECT summaries and summary_artifacts for bearer retrieval.

**External dependencies:** PHP session/context handling and MariaDB; no Python computation is required to retrieve a saved result.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** Source inspection only; live bearer access and revocation not exercised

**Observed result:** Source-supported; live share-link lifecycle and revocation were not exercised.

**Limitations:** Source-supported; live share-link lifecycle and revocation were not exercised.

**Evidence:** BACKEND_AND_DATABASE_AUDIT.md; evidence/backend-feature-notes.json; app/src/Services/HistoryService.php

## F064. PDF and DOCX source extraction

**Module / role / route:** Processing / internal Python process / maintainer / local_cli / summarizer_core extraction

**Plain-language explanation and purpose:** The engine converts supported document content into text and structural hints before summarization.

**Need addressed:** Supports pdf and docx source extraction.

**User or operator steps:** Open the relevant result or call the documented internal action through its intended boundary → Provide the required source or access token → Inspect the resulting output and any warnings.

**Inputs:** Supported source document

**System behavior:** Read pages/paragraphs/tables; reconstruct text; apply readability checks

**Expected output:** Extracted source and document context

**Related workflows:** Source intake, processing and result retrieval

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** python-engine/local_cli.py; python-engine/summarizer_core/extraction.py

**Controller/service anchors:** extraction.load_source_document:456; extraction.extract_pdf_source:137; extraction.extract_pdf_source_from_reader:63

**Database operations:** No direct SQL in this Python capability; PHP handles persistence separately.

**External dependencies:** PHP/MariaDB for retrieval; installed Python packages and optional model/OCR resources for processing.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** Scoped existing Python tests and/or source review; see evidence

**Observed result:** Parsing fixtures run in isolation; representative real uploads were not submitted. Blank-first-page alignment probe was inconclusive.

**Limitations:** Parsing fixtures run in isolation; representative real uploads were not submitted. Blank-first-page alignment probe was inconclusive.

**Evidence:** PYTHON_ENGINE_AUDIT.md; evidence/python-test-case-register.csv

## F065. Bounded OCR fallback

**Module / role / route:** Processing / internal Python process / maintainer / summarizer_core extraction OCR helpers

**Plain-language explanation and purpose:** Image-only PDF content can be passed to an OCR fallback when suitable local tools are installed.

**Need addressed:** Supports bounded ocr fallback.

**User or operator steps:** Open the relevant result or call the documented internal action through its intended boundary → Provide the required source or access token → Inspect the resulting output and any warnings.

**Inputs:** Unreadable selected PDF pages

**System behavior:** Select at most 12 pages; render at 150 DPI; use a 15-second OCR-call timeout and check a 55-second elapsed budget between pages

**Expected output:** Recovered text or a controlled unreadable-content error

**Related workflows:** Source intake, processing and result retrieval

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** python-engine/summarizer_core/extraction.py

**Controller/service anchors:** extraction.select_ocr_page_numbers:195; extraction.extract_pdf_source_with_ocr:215

**Database operations:** No direct SQL in this Python capability; PHP handles persistence separately.

**External dependencies:** PHP/MariaDB for retrieval; installed Python packages and optional model/OCR resources for processing.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** Scoped existing Python tests and/or source review; see evidence

**Observed result:** Source-supported only. The 55-second value is a between-page elapsed-budget check, not a hard total deadline: rasterization has no local timeout. Real OCR tools and scanned-document accuracy were not demonstrated.

**Limitations:** Source-supported only. The 55-second value is a between-page elapsed-budget check, not a hard total deadline: rasterization has no local timeout. Real OCR tools and scanned-document accuracy were not demonstrated.

**Evidence:** PYTHON_ENGINE_AUDIT.md; evidence/python-test-case-register.csv

## F066. Source cleaning and reconstruction

**Module / role / route:** Processing / internal Python process / maintainer / summarizer_core cleaning

**Plain-language explanation and purpose:** The engine attempts to remove navigation and extraction noise while preserving useful paragraphs.

**Need addressed:** Supports source cleaning and reconstruction.

**User or operator steps:** Open the relevant result or call the documented internal action through its intended boundary → Provide the required source or access token → Inspect the resulting output and any warnings.

**Inputs:** Extracted or pasted text

**System behavior:** Normalize and classify blocks; remove detected noise; retain readable content

**Expected output:** Cleaned paragraphs or an error when content is unavailable

**Related workflows:** Source intake, processing and result retrieval

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** python-engine/summarizer_core/cleaning.py; python-engine/summarizer_core/constants.py

**Controller/service anchors:** cleaning.prepare_document_paragraphs:1196; cleaning.should_drop_paragraph:1134; cleaning.looks_like_navigation_noise:977

**Database operations:** No direct SQL in this Python capability; PHP handles persistence separately.

**External dependencies:** PHP/MariaDB for retrieval; installed Python packages and optional model/OCR resources for processing.

**Implementation / classification:** Yes / Partially working

**Testing:** Scoped existing Python tests and/or source review; see evidence

**Observed result:** A synthetic valid paragraph containing register was discarded. Rewording or paragraph separation retained content; this is a confirmed over-filtering defect.

**Limitations:** A synthetic valid paragraph containing register was discarded. Rewording or paragraph separation retained content; this is a confirmed over-filtering defect.

**Evidence:** PYTHON_ENGINE_AUDIT.md; evidence/isolated-smoke-followup.json

## F067. Default legacy summary selection

**Module / role / route:** Processing / internal Python process / maintainer / local_cli.handle_summarize

**Plain-language explanation and purpose:** The configured engine ranks and organizes source passages using text statistics, document hints and length constraints.

**Need addressed:** Supports default legacy summary selection.

**User or operator steps:** Open the relevant result or call the documented internal action through its intended boundary → Provide the required source or access token → Inspect the resulting output and any warnings.

**Inputs:** Source, mode, format, depth, title and focus

**System behavior:** Extract; clean; rank sentence candidates; select; simplify; check heuristic facts; format

**Expected output:** Structured summary payload and evidence metadata

**Related workflows:** Source intake, processing and result retrieval

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** python-engine/local_cli.py; python-engine/summarizer_core/pipeline.py

**Controller/service anchors:** local_cli.handle_summarize:633; SummarizationPipeline.summarize:140

**Database operations:** No direct SQL in this Python capability; PHP handles persistence separately.

**External dependencies:** PHP/MariaDB for retrieval; installed Python packages and optional model/OCR resources for processing.

**Implementation / classification:** Yes / Partially working

**Testing:** Scoped existing Python tests and/or source review; see evidence

**Observed result:** The checked-in NIST fixture returned a nonempty summary. General quality, omission rates and saved web workflows remain unverified; the preceding cleaning stage has a reproduced defect.

**Limitations:** The checked-in NIST fixture returned a nonempty summary. General quality, omission rates and saved web workflows remain unverified; the preceding cleaning stage has a reproduced defect.

**Evidence:** PYTHON_ENGINE_AUDIT.md; evidence/isolated-smoke-followup.json

## F068. Optional v2 summary engine

**Module / role / route:** Processing / internal Python process / maintainer / light_engine / light_engine_cli

**Plain-language explanation and purpose:** An alternative engine implementation provides a configurable experimental processing path.

**Need addressed:** Supports optional v2 summary engine.

**User or operator steps:** Open the relevant result or call the documented internal action through its intended boundary → Provide the required source or access token → Inspect the resulting output and any warnings.

**Inputs:** Document and supported engine configuration

**System behavior:** Build evidence representation and compose output under the alternative engine contract

**Expected output:** Alternative summary result

**Related workflows:** Source intake, processing and result retrieval

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** python-engine/light_engine_cli.py; python-engine/light_engine/__init__.py; python-engine/light_engine/pipeline.py

**Controller/service anchors:** light_engine.pipeline.summarize:81

**Database operations:** No direct SQL in this Python capability; PHP handles persistence separately.

**External dependencies:** PHP/MariaDB for retrieval; installed Python packages and optional model/OCR resources for processing.

**Implementation / classification:** Yes / Partially working

**Testing:** Scoped existing Python tests and/or source review; see evidence

**Observed result:** Not enabled by the audited default configuration. One regression failed because an inline-heading fixture omitted the introductory outlier definition.

**Limitations:** Not enabled by the audited default configuration. One regression failed because an inline-heading fixture omitted the introductory outlier definition.

**Evidence:** PYTHON_ENGINE_AUDIT.md; evidence/python-tests-confirmation.xml

## F069. Automated comparison metrics and statistics

**Module / role / route:** Processing / internal Python process / maintainer / evaluation_cli and evaluation package

**Plain-language explanation and purpose:** Evaluation helpers compare generated text with supplied references and summarize available measurements.

**Need addressed:** Supports automated comparison metrics and statistics.

**User or operator steps:** Open the relevant result or call the documented internal action through its intended boundary → Provide the required source or access token → Inspect the resulting output and any warnings.

**Inputs:** Sources, outputs, references and paired document-level observations

**System behavior:** Compute supported overlap/fact proxies; report unavailable metrics; group comparisons; apply statistical helpers

**Expected output:** Metric records and statistical report payload

**Related workflows:** Source intake, processing and result retrieval

**Frontend:** No dedicated interface; internal/API/CLI capability.

**Backend:** python-engine/evaluation_cli.py; python-engine/evaluation/runner.py; python-engine/evaluation/statistics.py

**Controller/service anchors:** evaluation_cli.dispatch:22; evaluation.runner.evaluate_item:146; evaluation.statistics.build_statistical_report:236; evaluation.statistics.descriptive_statistics:35; evaluation.statistics.paired_comparison:110; evaluation.statistics.holm_adjust:92; evaluation.statistics.krippendorff_alpha:176

**Database operations:** No direct SQL in this Python capability; PHP handles persistence separately.

**External dependencies:** PHP/MariaDB for retrieval; installed Python packages and optional model/OCR resources for processing.

**Implementation / classification:** Yes / Implemented but not verified

**Testing:** Scoped existing Python tests and/or source review; see evidence

**Observed result:** Tests establish selected contracts in isolation. No human survey responses or approved research dataset were collected; optional metrics require their resources.

**Limitations:** Tests establish selected contracts in isolation. No human survey responses or approved research dataset were collected; optional metrics require their resources.

**Evidence:** PYTHON_ENGINE_AUDIT.md; evidence/python-test-case-register.csv
