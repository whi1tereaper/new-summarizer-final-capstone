# Independent reviewer instructions — review not yet conducted

No independent reviewers participated in the automated run. Blank/null fields
mean pending, never zero performance. Agent draft units and context flags are
unverified hypotheses. Do not show implementer draft scores to output reviewers.

## Phase 1: source only

Two reviewers who did not implement the system independently read the full
licensed sources in `human-source-packet.json`. Write the central proposition,
stance, must-preserve units and their evidence spans, required attribution,
scope, negation and uncertainty. A unit may span several sentences. Explicitly
review late conclusions and method/results limitations. Judge the three supplied
Focus queries independently (supported / partly supported / unsupported), record
what can and cannot be answered, and propose harder queries if needed. New queries
are follow-up work, not retroactively part of the frozen automated run.

The source's own inconsistencies must be distinguished from summarizer changes.
For example, the research article has inconsistent demographic percentages and
registration identifiers. A faithful quotation does not make the source true.

Retain both original annotations and an adjudicated version. Record reviewer ID,
qualifications, independence/conflicts, date, duration, disagreements and reasons.
Freeze the adjudicated source annotations **before opening output packets**.
The agent's drafts may then be audited against these labels, not silently adopted
as independent work. Label any already output-exposed reviewer accordingly.

## Phase 2: masked summaries

Use `human-output-packet.json` and `human-review-blank.csv`. Do not open the
separate `blinding-key.json`, run files, or automated diagnostic tables until
ratings are locked. The key and order are deterministic for reproducibility,
not cryptographically secret. Summary lengths and source/query remain visible.
Review selected complete outputs; unmatched cases are retained but must not enter
the matched-length superiority analysis. Read native outputs separately for UI
behavior. Never splice trials, edit prose or choose by an annotation score.

Score each dimension independently on 0–4. Do not average away a critical error.

| Dimension | 0 | 2 | 4 |
|---|---|---|---|
| Main idea | Central proposition absent, reversed or seriously misleading | Partial thesis; an essential relation or stance missing | Central proposition and stance preserved with necessary scope |
| Important information | Most essential units absent | Mixed coverage of adjudicated units | Appropriate essential coverage at this length, with valid qualifiers |
| Factual consistency | Critical wrong relation/number/negation | Material unsupported implication or qualifier omission | Claims consistent with the supplied source; no material distortion |
| Attribution/context | Wrong speaker/population/causal scope | Some unresolved or ambiguous attribution/context | Speaker, population, uncertainty and temporal context clear |
| Coherence | Fragments or disconnected/contradictory sequence | Understandable with substantial jumps | Logical standalone progression with resolved references |
| Readability | Difficult to understand | Understandable but awkward or overly dense | Clear and fluent within source terminology |
| Nonredundancy | Largely repeated information | Noticeable repeated meaning | Each included sentence adds useful information |
| Focus relevance | Does not answer supported query, or invents absent evidence | Partly relevant, incomplete or overbroad | Direct answer to supported scope; unavailable scope honestly distinguished |

Use 1 and 3 for intermediate judgments, with an explanation. Focus is N/A for
general summaries. Unsupported queries need an honest unavailable-information
outcome, not topical but unresponsive excerpts. Partial queries should preserve
the supported part and make the unavailable part clear. Guide LSA has no Focus
conditioning; assess its actual output without inventing such capability.

For every potential error, record source and summary sentence/span IDs, the
proposition affected, whether the error is introduced vs inherited from source,
and severity: critical (reverses central meaning or unsafe false implication),
major (materially wrong/unsupported information or missing essential context),
minor (local noncritical ambiguity), or none. Unsupported evidence remains distinct
from ordinary omission due to a short budget. Independent factuality here means
consistency with the source, not external truth verification.

## Analysis and release

Report paired human ratings and adjudicated unit recall/precision by article,
category and depth; include complete matched pairs, the common intersection,
all unmatched and generation failures. Aggregate uncertainty by article, not by
repeated depths. One article per category is not replicated category evidence.
This eight-article pilot does not fulfill the earlier 30-article confirmatory
protocol. Add a new untouched confirmatory corpus and independent reviewers
before making general superiority or non-inferiority claims.

Review material business, opinion, technical multi-finding, scientific uncertainty,
late-information and near-topic Focus failures explicitly. Complete the real
deployment's controller/persistence, live resource envelope and warm-supervisor
concurrency audit as well. Keep global routing disabled until all gates pass.
