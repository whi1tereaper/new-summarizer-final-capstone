# Student registry activation

Subsequent workflow change: registration now requires an active imported ID and never retains a new pending account. See [registry-required-registration.md](registry-required-registration.md). The activation checks below describe the preceding configuration-only change.

Date: 2026-10-23. Scope: remove the backend authorization hold shown on the registry page, as requested by the operator.

## Change

Set the existing private `.env` setting `STUDENT_ENROLLMENT_AUTHORIZED=true`. No application authorization check was deleted or hidden. The configured privacy contact, stable student-number protection key, administrator permissions, second-factor login, CSRF protection and registry matching remain required. `.env.example` retains its safe default of `false`; `.env` is ignored by Git.

This enables administrator enrollment imports and student registration. A matching eligible imported ID automatically verifies a pending or new student application; unlisted and duplicate-claimed IDs do not receive verified access. Refresh the registry page and submit the intended enrollment list again: entries typed while imports were closed were not saved by that failed request.

The flag records the operator's configuration choice; it does not independently establish campus authority or prove that a claimant owns an eligible number. Use only enrollment records the operator is authorized to process.

## Executed validation

Command: `.venv-nlp\Scripts\python.exe scripts/qa/run.py --registry-only`.

The final run passed all seven execution groups, including 15 real HTTP checks on a separate installed XAMPP Apache process and eight configuration checks. Mutation tests used a disposable MariaDB database and a restricted database user demonstrably denied access to production.

- Registration is enabled; both pictured authorization-closed messages are absent.
- Administrator login still requires the existing second-factor challenge.
- Anonymous and ordinary users cannot open the registry module.
- An administrator can import synthetic student numbers; matching pending applications are approved in the database.
- New matching registrations are verified automatically and receive continued summarizer access.
- Duplicate student-number claims are rejected. Unlisted registrations remain restricted even when submitting forged role/status fields.
- Imports with a forged CSRF token are rejected.
- Missing/invalid privacy contact, a missing/short protection key, and false/invalid authorization values still fail closed.
- The read-only live preflight confirms all nine access/registry tables and all three configuration prerequisites are present.

The initial configuration-test run had one test-fixture failure: a zero-length Windows process environment value disappeared, allowing the now-enabled local configuration to be read. The invalid-authorization test now supplies an actual non-empty invalid value. All eight configuration checks passed on rerun; no application parsing change was needed. The first run is retained in the activation evidence directory.

Evidence: `docs/qa/registry-activation/results.json`, `http-results.json`, `integrity.json`, and `docs/qa/privacy-gate-results.json`. The integrity check confirmed original users, summaries and feedback rows were preserved, with no concurrent live activity observed during the final run. Test databases and the test database user were removed. No real enrollment list, production student account or production password was created or changed by these fixtures.

This focused activation check did not rerun NLP, document summarization, translation, audio or the full browser suite; it makes no new claims about those features.

## Rollback

The pre-change environment is preserved outside the web root with a current-user-only ACL at `C:\Users\Robert Martin\AppData\Local\light-registry-config-b6b656a1\before-activation.env`. To restore only this hold without overwriting unrelated future settings, set `STUDENT_ENROLLMENT_AUTHORIZED=false`. No database migration was needed for activation. Disabling the flag does not undo later legitimate imports or account decisions; those require the existing audited administrative workflow.
