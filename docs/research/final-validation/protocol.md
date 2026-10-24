# v2.1 quality-gate follow-up protocol — 23 October 2026 (Asia/Manila)

This is an evaluation-methodology follow-up on the **already inspected** external
pilot, not a new untouched holdout or an algorithm-development partition. Keep
the engine, detector, legacy path, UI, parameters and routing frozen. No production
flag, dependency, interface or ranking change is authorized by this protocol.
The original registered 288 records/1,220 saved trials and all unmatched cases
remain immutable. Human semantic/factual validation is pending.

## Length diagnosis and explicitly separate sensitivity analysis

1. Recompute the original same-depth nearest-length results exactly. Inspect every
   native and saved trial, actual output count, word use, source span length,
   requested budget and effective article count/ceiling. Classify hard ceiling
   impossibility separately from observed selector behavior and limited search.
2. Use a cardinality-constrained integer-word knapsack over complete source
   sentences as a **relaxation**: ignore context and selector restrictions. If no
   attainable length exists even here, the target is impossible under these
   simplified limits. A feasible relaxed set is not a valid summary or proof the
   engine can generate it. Do not output those knapsack sets as summaries.
3. Run diagnostic control probes, not quality-comparison candidates: both article
   configurations on all eight sources at Balanced and Comprehensive, using
   (a) native UI count/budget, (b) same count and existing maximum word ceiling,
   (c) existing engine-internal count limit and maximum word ceiling. These are
   offline supported API controls; the real six UI settings are not changed.
4. Probe legacy on business and research Balanced using native count 8, explicit
   word budget 50, count 3 and count 15, with all other settings identical. Retain
   full results and advertised ranges; do not patch an ineffective control.
5. Separately report **retrospective cross-depth matching** of the original saved
   whole summaries. Native-only and all-saved-trial pools are separate analyses.
   Within each article, deduplicate identical output text per system, preserving
   all original records/origins. Legacy native summaries are the left pool.
   Match one-to-one, maximizing pair count, then minimizing total absolute word
   difference, then stable pool order. A permitted pair satisfies the original
   `max(5 words, .10 * legacy words)` tolerance. Matching sees only lengths and
   validity, never annotations, relevance or quality. No output is clipped,
   rewritten, stitched, padded or selected for its content quality. Retain every
   unmatched original legacy case and alternative output. Different depth labels
   remain visible: this tests comparable *realized length*, not same-depth UI
   equivalence. Also report a common legacy-case intersection across comparators.
   Do not use this post-pilot sensitivity as independent confirmatory evidence.

## Focus extension and sentence review

Retain the 24 original supported/partial/near-topic-unsupported source/query pairs.
Before generating new outputs, lock eight paraphrased supported requests, eight
ambiguous requests and eight plainly unsupported far-topic requests. Ambiguous
requests have no forced binary gold support label. All labels are agent drafts,
not independent reviewer ground truth. Run the 24 new requests at Balanced through
all four unchanged systems. Guide LSA remains query-blind.

Prepare an exhaustive row for **every selected sentence** in all original and new
native Focus outputs. Include the full query/output, exact or explicitly tentative
source alignment, source neighbors, and separate fields for direct answer,
necessary context, unrelated content, preceding/following dependency, and misleading
isolation. Draft source-role maps may assist review, but lexical/semantic similarity
alone cannot decide correctness. Unresolved/nonverbatim relations stay unresolved;
never call an empty human field a negative rating. Preserve full general-output
context/negation/uncertainty/numerical/causal/attribution/conclusion review material.

## Independent reviewers and agreement

Recruit two qualified independent reviewers and an adjudicator. Collect role,
qualification, conflicts, output-exposure and independence declarations. Reviewers
identify source-grounded units before viewing masked outputs; retain disagreements
and adjudication history. Score 0–4 separately for main idea, information coverage,
factual consistency, attribution, context preservation, coherence, readability,
nonredundancy and Focus relevance (N/A for general). Detailed anchors accompany
the packet. Keep engine decoding keys separate from review packets.

Only actual submitted, validated ratings may enter agreement. For paired 0–4
ratings report pair count, exact agreement, unweighted and quadratic-weighted
Cohen kappa; distinguish undefined constant-marginal cases. For sentence categories
use unweighted kappa on common reviewed rows. Do not estimate a semantic quality
effect or confidence interval without real independent ratings. Repeated depths,
trials and sentences are clustered within eight articles, not independent samples.

## Resources and release

Audit all old enforced-cgroup matrices, routes, images, deadlines, startup,
large-file fallback and concurrency failures. They did not attribute peak process
contributions. Add a separate, serial 512 MiB/no-swap/one-CPU local-container run
per configuration: Apache/PHP plus authenticated supervisor, real research article,
first/warm Focus and PDF. Sample `/proc/*/smaps_rollup` RSS/PSS, cgroup current/peak
and PHP request-heap peak. Include observer overhead. Report contributions at one
common sampled instant; do not add independent high-water marks or equate summed
RSS with container memory. Apache and mod_php share processes, so do not invent
separate whole-process PHP/Apache attribution. No production/database writes.

The actual Render plan and full deployed transaction path remain unverified.
No hosting compatibility or release claim follows solely from scoped local
container success. Automatic routing stays false. Final recommendation is
**NOT READY** whenever independent meaning/factual review, Focus/context quality,
comparable-length evidence, operational envelope or regression gates remain open.
