# Password recovery availability check

Checked 24 October 2026, Asia/Manila. This was a diagnostic check, not an application change.

## Availability

Recovery looks up the email stored on the account; the message recipient is that normalized email, not the project administrator's address. All **5 current student accounts** have syntactically valid, non-placeholder emails. Among 33 older non-administrator accounts, 31 are active; 29 active accounts have non-placeholder email addresses and 2 have reserved/example addresses unsuitable for real inbox delivery. Both administrators have non-placeholder addresses.

The configured production SMTP connection on port **587** successfully authenticated over **TLSv1.3**. The check connected/authenticated and disconnected without sending mail. This establishes transport readiness, not the existence, ownership, spam-folder placement or actual delivery of every user's mailbox. No recovery request was submitted for a real user.

Users can open **Sign In → Forgot Password**, enter their saved email, receive a six-digit code, and choose a replacement password. Codes are hashed, valid for approximately 15 minutes and single-use. A subsequent request invalidates the previous code. Existing limits are one reset-email request per minute per IP and five incorrect verification attempts per 15-minute window per IP; shared-campus networks therefore share these limits.

## Executed tests

**26 checks passed, 0 assertions failed**, with two reporting findings below. Tests ran through PHP 8.3/XAMPP Apache HTTP against an isolated MySQL database and a loopback SMTP capture server. They included:

- Actual student registration with a chosen email, followed by recovery to that exact normalized address.
- Recovery-page/link availability, CSRF, invalid/unknown email handling and request rate limiting.
- Hashed code storage, measured 15-minute expiry, incorrect/malformed/cross-account codes, replacement-code invalidation and code reuse rejection.
- Weak/mismatched password rejection, successful password replacement, successful new-password login and old-password rejection.
- Verification-attempt limiting, expired-code rejection and an injected SMTP outage.
- Denied production access for QA credentials, production integrity and isolated database/user cleanup.

Test passwords changed **only** in the isolated database. The production database snapshot did not change; real user passwords, summaries, feedback and reset records were preserved. Temporary SMTP/Apache processes, QA databases and credentials were removed. No real emails were sent.

Receipts: `docs/qa/password-recovery/{checks,summary,integrity}.json`. Reusable diagnostics: `scripts/qa/check_password_recovery.py` and `scripts/qa/recovery_probe.php`. These were added for testing; authentication/recovery application code and mail configuration were not modified.

## Findings requiring a separate fix request

1. **Mail-delivery failure is ignored.** `AuthController::forgotPassword()` creates a token and invokes `sendPasswordResetEmail()`, but does not inspect its boolean result. In the injected outage no message was captured, yet the session still stated that a recovery code was sent. This can make an unavailable mail service look successful. Any correction must keep public feedback generic to avoid revealing registered accounts.
2. **The request-success notice is not rendered on the reset page.** `forgotPassword()` stores a success flash and redirects to `reset_password.php`, but that page renders only errors. The prefilled email and code form work, but the confirmation notice is absent.

Neither issue was silently fixed because the request was to check availability. Actual inbox delivery remains untested; confirming it requires an explicitly chosen recipient and authorization to send a real test/recovery email. Legacy placeholder addresses need an authorized account-email correction before real email recovery can work; do not overwrite them or transfer claims automatically.
