# Student-number access integration plan

23 October 2026, Asia/Manila. This supersedes the reverted photo-verification
proposal. No student ID images, sticker uploads, OCR, invented enrollment data or
new authentication provider are part of this implementation.

## Audit and boundaries

Reuse AuthController/UserRepository and password hashing, database PDO selection,
AdminSecurityService and existing admin login, durable ArticleController jobs,
DocumentSummaryService API/cache, Nutshell, current policies and design tokens.
The guest history identity is currently session-scoped and cleared at login;
trial accounting must use a separate persistent random identity to avoid resets.
Every successful generation path, including cached document variants and Nutshell,
needs the same accounting boundary. Existing results must remain readable at zero.
The document API also needs owner checks and CSRF protection.

The working tree already contains unrelated changes. Preserve them. The
configured PostgreSQL schema and MySQL fallback are inspected read-only in
`baseline.json`; do not apply migrations or seed accounts into that configured
database during tests. Use isolated local MySQL/PostgreSQL fixtures. The old
research reports/manifests remain historical, immutable evidence; access-related
PHP integration changes will intentionally differ from their production snapshot.
Do not alter either Python algorithm or enable automatic article routing.

## Implementation sequence

1. Add dialect-specific, additive schema migrations: no user/password/role reset.
2. Centralize database-owned eligibility, guest subjects, account-trial binding,
   atomic reservations, short-window fair-use and successful-commit accounting.
   Reserve before NLP; complete in the result transaction; release on failure,
   cancellation and expiry. Use independent attempt IDs to fence stale workers.
3. Register full name/student number, policy acknowledgements/versions and Pending
   state atomically with the existing user. Keep syntax configurable; format is
   not identity/enrollment proof. Return generic duplicate-claim messages.
4. Integrate an admin-dashboard verification section, protected detail page,
   recorded eligibility/ownership evidence, reasoned decisions, optimistic
   version checks, audit events and persistent in-app student notifications.
5. Add owner-only status/correction workflow and guest counter/exhaustion panel;
   preserve existing layout and all six depths. Update terms/privacy/verification
   sections without claiming institutional approval or invented privacy settings.
6. Verify real SQL constraints, rollback/idempotency, concurrent quota admissions,
   status transitions, ownership/CSRF, no PII in public outputs/logs, PHP/JS syntax
   and relevant regressions. Distinguish mocked NLP fixtures from genuine NLP runs.

## Required institutional settings

The supplied `PEN2023-02071` example does not establish all official formats.
Without authoritative documentation, the default is a conservative character/
length safety screen only, with an operator-configurable confirmed regex.
No registrar integration or authorized roster has been supplied. Approval must
require an actual administrator's recorded eligibility and ownership evidence,
review period and future expiry. Manual review does not guarantee enrollment or
prevent all stolen-number impersonation. The user confirmed three-day application
and review-detail retention while retaining minimal approval/expiry and protected
duplicate-check records. An actual designated operator privacy contact is still
required before collecting applications; students' active account emails are
private communications addresses, not a public privacy contact.
