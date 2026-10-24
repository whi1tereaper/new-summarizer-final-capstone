<?php
namespace App\Src\Utils;

class TermsContent
{
    public static function getSections(): array
    {
        return [
            [
                'heading'=>'Guest Trial and Student Access',
                'body'=>'Guests receive three successfully completed summaries across text, file, URL and other generation routes. Failed processing and invalid requests do not consume an allowance. Cached results remain readable. A server-side usage record and random HttpOnly trial cookie enforce this limit; deleting browser data or changing devices can bypass anonymous identification. No invasive fingerprinting is used and shared campus IPs are not treated as individual identities. Registration does not reset the trial. Continued student access requires an active administrator-approved Student ID registry match. Administrators retain their existing role-based access. Fair-use, security and concurrency limits still apply.',
            ],
            [
                'heading'=>'Student Verification Policy',
                'body'=>'The active administrator-managed MySQL Student ID registry is the only student eligibility authority. Registration uses a fixed PEN prefix, the complete normalized ID, a required account email and a chosen password. An active, unclaimed database match approves the account automatically; the student then signs in normally. Unknown, inactive, revoked, expired or conflicting IDs cannot register. No name, photograph, enrollment certificate, student OTP, email confirmation, secondary identity form or manual approval is required. Existing exact eligible accounts retain their credentials and history; unlisted accounts remain restricted. Syntax alone never establishes eligibility. A database match does not prove rightful ownership: someone who knows another student\'s unclaimed ID could impersonate that student under this intentionally simplified policy. Keep IDs and passwords private and report disputed claims to the administrator.',
            ],
            [
                'id'=>'student-privacy',
                'heading'=>'Student Privacy Notice',
                'body'=>'For enrollment matching and access control, LIGHT processes your official/normalized student number, a protected keyed duplicate-check identifier, account email, authentication data, status, policy versions, enrollment period, approval method, administrator identity and audit timestamps. Authorized administrators separately import official student numbers into a private enrollment registry; student submissions never populate it. New registrations require email for sign-in and account recovery, but do not require email confirmation. Existing account emails are not rewritten. Names and ID photographs are not requested. Registry lists are administrator-only and are not exposed in public analytics or notifications. Registration is optional and unused guest allowance remains available. The operator must establish its actual legal basis, authorized campus source, privacy authority and retention policy before processing real enrollment records.',
            ],
            [
                'heading'=>'Three-Day Retention and Minimal Approval Records',
                'body'=>'The existing three-day cleanup hides and erases detailed historical application data and old review notes. It does not impose a second verification step or a three-day approval expiry. Minimal status, campus, registry link, approval method, policy versions, audit timestamps and protected duplicate-check identifier remain while needed for account eligibility and uniqueness. The separate administrator-managed registry is retained under the operator\'s registry policy. Username/email, password hash, summaries and feedback are separate account data and are not deleted by application-detail cleanup. The protected identifier is pseudonymous, not anonymous. Scheduled maintenance must perform physical erasure; overdue application details are hidden even when maintenance is delayed. Backups require corresponding retention controls.',
            ],
            [
                'heading'=>'Enrollment Registry Retention and Revocation',
                'body'=>'The registry retains the minimum official ID, eligibility status/period, import provenance, durable claim/conflict state and audit records needed for account access. Import preview payloads expire after 15 minutes and are erased at confirmation or cleanup. Inactive, revoked, expired or conflicting records cannot grant student access. An explicit administrator reauthorization reconciles the exact linked account automatically, with no additional student review. Accounts, summaries and feedback are preserved when eligibility is withdrawn. The operator must define actual retention/review intervals for registry, audits and backups and arrange authorized erasure when no longer needed. No public registry lookup or full-list export is provided.',
            ],
            [
                'heading'=>'Privacy Rights and Contact',
                'body'=>'Request access, correction, withdrawal, deletion or an explanation of retention through the AI Summarizer System Administrator / Privacy Contact: '.(filter_var(config('student_access.privacy_contact',''),FILTER_VALIDATE_EMAIL)?config('student_access.privacy_contact',''):'not configured; registration is closed until the operator publishes a designated contact').'. This contact is not represented as the official NEUST Data Protection Officer. Your required registration email remains private and supports normal sign-in/recovery; no email confirmation is required. Student access status appears on the existing Profile page; there is no secondary verification page or decision-email requirement. Normal password-recovery codes and administrator second-factor login remain security features, not student-ID verification. Registry eligibility withdrawal may end continued student access. Rights and exceptions must follow applicable policy and law; software alone does not establish compliance.',
            ],
            [
                'heading'=>'Guest Usage and Cookie Retention',
                'body'=>'The random trial cookie lasts up to 180 days. Anonymous quota records and reservations are eligible for cleanup after 180 days of inactivity when not linked to an account. Account-linked quota records remain to prevent account-based trial resets. Guest summary history retains its existing session lifecycle and is not erased just because the trial is exhausted. Account summaries and feedback retain their existing deletion controls. Source documents are processed by the configured summarization service; do not upload material you are not authorized to process. Separately enabled external synthesis services, if any, are not used to verify student numbers.',
            ],
            [
                'heading' => 'Agreement',
                'body' => 'By using this Article Summarization Software, you agree to follow these Terms and Conditions. If you do not agree, please do not use the system.',
            ],
            [
                'heading' => 'Purpose of the Software',
                'body' => 'This software is designed to help users summarize articles, academic texts, and other written content into shorter and easier-to-understand summaries.',
            ],
            [
                'heading' => 'Data We Collect',
                'body' => 'The system may collect text or article content entered by the user, uploaded files such as PDF documents, summary preferences, account information if registration is required, summary history for logged-in users, feedback ratings, and basic technical data such as date, time, session information, and system errors.',
            ],
            [
                'heading' => 'Why We Collect Data',
                'body' => 'The data is collected to generate summaries, display results, save user history, improve summary quality, process feedback, detect errors, prevent misuse, and maintain system security.',
            ],
            [
                'heading' => 'User Responsibility',
                'body' => 'Users are responsible for the content they enter or upload. Users should not submit private, sensitive, confidential, copyrighted, or unauthorized content unless they have permission to do so.',
            ],
            [
                'heading' => 'Accuracy of Summaries',
                'body' => 'The system attempts to generate accurate and meaningful summaries, but it may still make mistakes. Users should review the original article and should not treat the generated summary as a complete replacement for the original content.',
            ],
            [
                'heading' => 'Data Privacy',
                'body' => 'The system will handle user data with care. Guest data may be stored temporarily during an active session and may be deleted after the session ends. Registered user data, such as summary history, may be stored until deleted by the user or administrator, depending on the system rules.',
            ],
            [
                'heading' => 'Prohibited Use',
                'body' => 'Users must not use the system to upload illegal, harmful, abusive, private, or unauthorized content. Users must not attempt to access another user\'s account, attack the system, overload the server, or misuse the summarization service.',
            ],
            [
                'heading' => 'Changes to the Terms',
                'body' => 'The developers may update these Terms and Conditions when needed. Student applications and corrections record the accepted terms and privacy versions and the acceptance timestamp. Material changes requiring renewed agreement must be presented for explicit acceptance; prior acceptance is not silently rewritten.',
            ],
            [
                'heading' => 'Acceptance',
                'body' => 'By using this Article Summarization Software, the user confirms that they understand and agree to these Terms and Conditions.',
            ],
        ];
    }
}
