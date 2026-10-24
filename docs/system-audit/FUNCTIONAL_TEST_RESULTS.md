# Functional Test Results

Audit date:17October2026 (Asia/Manila). Tests were selected to preserve the application and production data. A passed isolated function or template test does not mark an entire web workflow verified. A source-confirmed implementation is not a functional pass.

## Execution boundaries

All normal live application pages load a guest-session service that can write and purge expired records. Therefore the audit did not perform live login, registration, recovery email, uploads, summary saving, account changes, deletion, feedback submission or evaluation queue writes. Original templates ran in an isolated harness with synthetic/empty records, and Python ran from a source copy without the project's .env. Three no-bootstrap routes were requested live without following redirects. MariaDB metadata was read directly with information_schema/SHOW queries, without row extraction or schema changes.

| Test group | Procedure and expected result | Actual observation | Status and evidence |
|---|---|---|---|
| PHP/JavaScript syntax | Parse original PHP with `php -l` and frontend JS with `node --check`; expect no parse failures |150files passed | PASS; evidence/syntax-checks.json |
| Python regression confirmation | Run existing tests from isolated source copy; expect asserted contracts |595passed,1failed,4skipped;62subtests passed | MIXED; evidence/python-tests-confirmation.xml |
| V2 inline heading | Technical/comprehensive output should retain the introductory outlier definition | Expected sentence absent | FAIL; test_light_engine.py:311 and confirmation log |
| Legacy ordinary prose | Summarize synthetic library announcement containing ordinary `register` wording | Whole paragraph dropped; CONTENT_UNREADABLE. Rewording retains it | FAIL; isolated-smoke-results.json and isolated-smoke-followup.json |
| Legacy summarization | Run direct handle_summarize on checked-in NIST fixture; expect nonempty structured response | Nonempty response returned | PASS at Python-function scope; isolated-smoke-followup.json |
| Source Q&A | Ask synthetic library source how many books; expect source passage with120 | Passage returned with120books | PASS at Python-function scope; isolated-smoke-results.json |
| Nutshell function | Call concise-summary function on synthetic text; inspect response | insufficient_content; empty nutshell; raw harness PASS means response returned only | GENERATION NOT DEMONSTRATED; isolated-smoke-results.json |
| PDF page alignment probe | Two physical pages with first blank; expect page slots retained | Probe rejected readability before page numbering | INCONCLUSIVE; no page-reference failure claimed |
| Cross-owner document API | Synthetic record belongs to user101; dispatch as202; expect denial | HTTP200with other owner's source | FAIL in isolated actual controller/service; backend-isolated-checks.json |
| Anonymous document API | Same synthetic record with no account; expect denial | HTTP200with source | FAIL in isolated actual controller/service; backend-isolated-checks.json |
| Audio suffix range | Request bytes=-100 from1000byte synthetic audio; expect206and100bytes |200and1000bytes | FAIL; backend-isolated-checks.json |
| Browser template captures | Render23states at1440desktop and390mobile widths |46captures; no document-level horizontal overflow in those fixtures | PASS for render geometry only; browser-readonly-results.json |
| Local UI interactions | Menu open/Escape, source tabs, settings, URL validation, password visibility, guest Nutshell gate |18of18 local assertions matched (baseline plus supplements) | PASS for named local actions only; browser-readonly-results.json |
| Anonymous page guards | Execute protected route guards with synthetic anonymous session |19of19 guard responses matched their source-based expectations | See each expected/actual response in browser-readonly-results.json; does not prove authenticated authorization |
| Live static health/redirects | GET root redirect, about redirect, static health without following | Three expected responses | PASS; evidence/live-safe-get-results.json |
| Live schema | Read server version, columns, keys, indexes and DDL |31tables,312columns,35FKs | VERIFIED metadata; evidence/live-schema.json |
| Mail, live translation, real audio | Requires production side effects/external service or voice execution | Not performed | NOT TESTED |
| Real CRUD, authenticated sessions, job save/retry/cancel | Requires a fully isolated database deployment | Source-traced only | NOT TESTED end-to-end |
| Accessibility, performance, backup restoration | Full assistive-technology, representative load and restore drills | Limited DOM/viewport checks only | Full evaluation NOT TESTED |

## Reproducibility and interpretation

The complete existing Python testcase register is `evidence/python-test-case-register.csv`; each row retains the assertion name and observed outcome. Confirmation tests were rerun because the initial restricted environment produced temporary-file permission errors; both original and confirmation logs remain available. The isolated project copy excludes .env, live database rows, credentials and model files. It is an audit fixture, not a deployment copy. Source hashes before/after support application preservation.

The browser harness intercepts external/network mutation requests and injects an audit provenance banner. It bypasses production startup and supplies a synthetic PDO fixture; screenshots show real source templates with artificial data. They must not be captioned as authenticated live sessions or completed operational workflows. Human survey scores and comparative performance benefits cannot be inferred from any results above. Full authenticated and persistence testing remains an explicit readiness gap.
