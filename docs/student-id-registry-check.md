# Student-ID import and automatic-approval check

Current signup policy: only active imported IDs can register; unmatched signup is rejected instead of queued. See [registry-required-registration.md](registry-required-registration.md) for the implementation and 169-check retest. The original inspection below remains a historical receipt.

Update: the operator subsequently requested removal of the launch hold. The live flag is now enabled; see [registry-activation-report.md](registry-activation-report.md) for the change and newly executed validation. The closed-state inspection below is the earlier snapshot, not the current configuration.

Checked 2026-10-23 against the current XAMPP/MariaDB backend. All nine fully visible student-number examples in the supplied screenshot were tested; additional off-screen entries were not available and were not claimed as tested. Full identifiers are not embedded in these source files or reports.

## Result

**17 checks passed / 0 failed** in an isolated database, including:

- The exact submitted formats are accepted, with case normalization, meaningful separators and leading zeros preserved. The shorter numeric suffix is not automatically padded or merged into a different number.
- An administrator's pasted list is stored in `student_enrollment_registry`, with database-enforced unique normalized IDs and keyed identities, batch provenance and audit records.
- A matching current pending application is verified during the import transaction.
- New registrations for all remaining matching IDs become `verified` with `approval_method=registry_match`; no second manual approval is required.
- Matching accounts resolve through student-number/password login and pass the centralized continued-summarizer-access check.
- Repeated imports skip existing entries. Duplicate account claims roll back, are audited, and cannot create a second activated account.
- Ordinary users cannot import trusted entries. Unlisted and inactive IDs do not receive verified privileges. Unverified registration submissions never become trusted registry entries.

This was a backend integration test, not another browser/HTTP or NLP-performance test. No application implementation change was necessary for these ID formats. The supplied numbers were stored only in the disposable test database and invoking process environment; the database/account were removed after testing. No supplied number was inserted into the live registry, and no live account/password/status was changed.

## Live blocker

The read-only live inspection found:

| Check | Result |
|---|---|
| Dedicated registry table and unique constraints | Present |
| Visible sample ID formats | All accepted |
| Live trusted registry entries | 0 |
| Visible sample IDs currently saved in the live registry | 0 |
| Operator privacy contact | Configured |
| Secure student-number protection key | Configured |
| `STUDENT_ENROLLMENT_AUTHORIZED` | `false` |
| Live student registration | Closed |

`StudentEnrollmentRegistryService::preview()` checks `StudentVerificationService::configurationError()` before saving an import. Registration uses the same configuration gate. Therefore, typing IDs into the textarea does not save or authorize them while the processing-authorization hold is active. This is a deliberate launch restriction, not a student-number-format failure.

The earlier instruction explicitly required keeping registration closed until authority to process enrollment records was confirmed. This check did not remove that safeguard. Once the operator explicitly confirms that authorization, enabling the environment flag permits the already-tested administrator-import and automatic-matching workflow. Unknown, duplicate-claimed, inactive and revoked entries must remain restricted.

The current import handler supports a one-step import via `StudentEnrollmentRegistryService::import()`; it internally commits the batch and reconciles matching pending applications. A registry entry is eligibility evidence from an authorized administrator, not proof that a first claimant rightfully owns that number. No external campus system was queried to establish whether the screenshot's IDs represent currently enrolled students.

## Evidence and reproduction

- `docs/qa/student-id-registry-check.json`: redacted live inspection and the 17 individual check results.
- `scripts/student-access/check_number_registry.php`: reusable CLI-only diagnostic. Supply a bounded JSON array through `QA_STUDENT_ID_SAMPLES`; `live` mode starts a read-only transaction, while `isolated` mode refuses production credentials before any mutations.
- A fresh restricted `qa_light_*` database was initialized with migrations 001–019. Authorization was enabled only inside that synthetic test environment; production remained held.

No real enrollment list should be embedded in a tracked test fixture or public log when reproducing this check.
