# Nutshell production audit

## Definition and contract

Nutshell is a secondary extractive compression of the source document. It ranks complete source sentences for topic, finding, mode relevance, section placement, quantities, and meaning changing qualifiers, then selects a small set under a content sensitive word budget. It preserves selected sentence wording instead of inventing paraphrases. The effective output format is always one plain text paragraph. Analysis Mode is inherited from a saved summary or accepted on standalone requests. Summary Depth and the primary summary's output format do not change.

It returns `completed`, `not_needed`, or `insufficient_content` from the worker. The synchronous HTTP API exposes completed output in both the legacy `nutshell` string and `generation.content`, with measurements, effective format/mode, source summary ID, version, and warnings.

## Architecture and prior mismatch table

| Area | Prior behavior / failure | Audited behavior |
| --- | --- | --- |
| Input | Saved summary source was preferred; source-less records fell back to a rendered summary. Standalone accepted text, URL, or upload. | Saved summary requires completed state and readable original source plus primary summary. Standalone requires exactly one source. |
| Permissions | Logged-in session and HistoryService lookup; CSRF was verified after upload save. | Active registered user, Terms acceptance, CSRF before upload handling, strict owner query, completed-state check, prepared SQL. Shared viewers/admins do not generate through this route. |
| Processing | Local Python CLI `generate_nutshell`, previous rewrite/synthesis logic could alter factual qualifiers and enforced a brand-specific 80–160 word minimum. | Extractive sentence ranking, concise density budget, mode cues, numeric/qualifier boosts, no rephrasing. |
| Persistence | Ledger insert and summary snapshot update were separate; failed output could leak exception text; snapshot update errors were suppressed. | Migration 013 adds output contract metadata and FK. Summary-linked ledger insert and snapshot update share a transaction. Files are removed in `finally`. Responses are sanitized. |
| Duplicates | No request idempotency; repeated requests made new rows and worker calls. | Hashed request key unique in ledger; summary row is locked and an existing snapshot is returned. A completed standalone retry replays its ledger record. |
| Analytics | Counted ledger rows without a success-state filter. | Only `status='completed'` contributes to Nutshell generated, stored, average, and trend metrics. |
| Reload | Summary snapshots existed but result page did not show or generate Nutshell. | Result page displays escaped stored snapshot and provides owner-only generation with loading/error/retry states. |

## Persistence and analytics

`nutshell_generations` is the canonical append-only generation ledger and success analytics source. `summaries.nutshell_text`, `nutshell_word_count`, and `nutshell_generated_at` are the latest successful summary-bound snapshot for fast reopening. Both are committed together for saved summaries. There is no `nutshell_analytics` table in this schema. Migration 013 adds `status`, `failure_reason`, mode, paragraph format, source/primary counts, compression ratio, duration, algorithm version, idempotency key, and a nullable summary foreign key (`ON DELETE SET NULL`). Existing dangling summary references were checked before applying it; count was zero.

Successful generation means a validated, persisted `completed` row. Failed worker/output validation attempts are recorded as `failed` and excluded from success analytics. Rejections before worker execution do not enter analytics. One successful Nutshell is retained per saved summary snapshot; repeated calls return it. Standalone requests can create separate entries with new request keys.

## Security and failure isolation

The endpoint requires an active logged-in user, accepted terms, a valid CSRF token, and ownership of saved summaries. It does not grant admin or share-token generation rights. IDs and source sizes are validated, queries are parameterized, and rendered results use HTML escaping or DOM `textContent`. The worker receives validated source via the existing local bridge. Raw document text and exception details are not written to application logs or returned to clients. Failed secondary generation does not modify the primary summary.

## Quality, format, and known limitations

The tests cover negation and statistical significance, cohort and percentage facts, mode-specific priorities, news dates, very short primary summaries, paragraph shape, compression metrics, and extractive grounding. The algorithm is deterministic and sentence-extractive, which limits invented claims; it cannot guarantee semantic completeness or detect every contradiction across selected sentences. It may omit an essential fact when sentence ranking or the compact budget does not surface it. Texts beyond the direct text cap need file input. Only paragraph format is implemented and advertised; bullets, hybrid, and structured Nutshell are unsupported.

## Verification status

- Migration 013 applied successfully; preflight found zero orphan summary IDs.
- Full Python suite after the final algorithm changes: 132 tests and 14 subtests passed. Focused Nutshell regression suite: 11 passed.
- PHP lint passed for endpoint, result page, history service, and analytics controller. Node syntax checks passed for both modified JavaScript files.
- Direct endpoint smoke checks: invalid CSRF returned `csrf_invalid`; a second active user tampering with a valid summary ID received `summary_not_found`.
- Successful endpoint integration generated and persisted one completed ledger row and summary snapshot. Repeating the same summary request returned the saved result; the fixture was removed afterward.
- Analytics storage check showed 14 existing ledger rows, all completed, and zero failed rows before and after fixture cleanup.
- Performance on the local Windows environment, 5 CLI worker runs over a small document: median 6.0 s, observed p95/max 7.9 s (min 5.8 s). The in-process Python generation function measured median 73 ms and observed p95 101 ms over 12 runs. The new worker startup dominates latency. These small local samples are not a production capacity estimate.
- Real browser interaction, mobile, keyboard, double click, page reload rendering, and UI permission tampering remain outstanding; the authenticated browser automation environment was not available. Endpoint persistence/replay was verified directly, but it does not replace browser verification.
