# Prospective external validation protocol, v1

Registered on 22 October 2026 before generating any new external summaries.
This study changes evaluation code only. Automatic routing stays false. Engine,
baseline, transport, UI and parameter hashes are checked before and after runs.
No tuning, reranking, hand editing, prefix cutting or output padding is allowed.

## Sampling and independence

Acquire eight authentic English articles, one each in news, scientific reporting,
original research, business analysis, educational explanation, technical
explanation, editorial/opinion, and narrative feature. Use at least six publisher
families. Require full legally reusable prose, a stable source URL, identifiable
publication date/author, and approximately 300–8,000 words. Keep acquisition
failures and exclusions. Prefer public-domain or CC BY/CC0 material. Remove
navigation, figure captions, references, publisher boilerplate and code blocks;
retain all article prose, section headings, numbers, limitations and conclusions.
Document extraction selectors and hashes. This is a small convenience pilot,
not a probability sample or sufficient category-level replication.

Exclude every previously inspected/tuned source, including all existing
evaluation corpora, NASA pilot articles, six external-source nominations and the
academic guide. Inspectors cannot become independent reviewers by changing roles.
New sources are prospective, non-tuning evaluation inputs, **not independently
annotated held-out gold data**. Human source-first review remains pending.

Before summaries are generated, lock source snapshots and agent-prepared draft
information units, main-claim units, necessary contexts, and Focus queries. Draft
labels are diagnostics, never independent human ground truth. Each unit records
exact source spans/sentences; multiple sentences may support one unit. Require
two independent source-only reviewers and an adjudicator before release claims.
Reviewers may correct drafts, retaining revisions and without seeing outputs.

## Comparisons

Run original LIGHT, unchanged guide Sumy LSA, Article 2.1 lightweight, and Article
2.1 enhanced. Use general mode, paragraph output and actual UI sentence requests
3/3/5/8/11/15 for the six existing depths. Article retains its own depth caps.
Record models/dependencies and missing-resource fallback. Preserve complete
native and regenerated outputs and every unsuccessful trial.

Target the original LIGHT native output's realized token count. Article budgets
are the fixed set {target-10, target, target+10, ceil(1.35*target)}, clipped to
positive integers and the existing depth maximum. Guide uses counts 1 through
the UI request count. Choose the successful complete output nearest the target;
ties prefer fewer words, then original trial order. Selection sees lengths and
execution/empty-output validity only, never annotations. An unsupported Focus
informational response is not a zero-word success for supported-query matching.
Record unmatched cases separately. Tolerance: max(5 words, 10% target), with 5%
and 20% sensitivity. Pairwise subsets and a common intersection must be explicit.
Do not mistake repeated depths for independent articles.

Run source-supported, partially supported and semantically close unsupported
custom Focus queries at Balanced. Freeze query support judgments before output
inspection, marked agent-prepared/pending adjudication. Guide LSA is query-blind;
retain its outputs as that limitation, not a Focus-capable competing system.

## Rubric and diagnostics

Independent reviewers score 0–4 (0 absent/unacceptable, 1 major failure, 2 mixed,
3 good/minor omissions, 4 strong) for main idea, important-unit coverage,
factual consistency, attribution/context, coherence, readability, nonredundancy,
and Focus relevance (N/A for general). Additionally record each critical error,
its exact summary/source spans, severity and explanation; do not average it away.
Unit retention means the proposition AND required qualifiers survive, not merely
that a word matches. Review blinded output packages in deterministically shuffled
order; keep the decoding key separate. No reviewer scores are auto-populated.

Automated diagnostics: output length, verbatim source spans, exact duplicates,
draft-unit exact-evidence retention, and missing mandatory context. These are
provenance/omission flags, **not factuality or accuracy percentages**. Main units
are explicit source-first drafts, not automatically the first sentence. Analyze
by category and depth, retaining source positions and late-unit failures.

## Resources and release

Benchmark an isolated Docker Apache/PHP/Python stack with aggregate cgroup
memory+swap limits and wall deadlines: 512 MiB and 180 seconds as a documented
test envelope (render.yaml declares timeout but no plan/memory allocation).
Also examine 1 GiB diagnostic headroom if 512 MiB fails. Use current frozen
sources, include model availability, extraction, server/child overhead, cold and
repeat requests, large input, and overlapping requests. Do not send benchmark
requests to the uncapped host NLP worker. Record OOM events, failures, cgroup peak,
startup, CPU limit, concurrency and any untested production dependencies.

Do not provision services or enable routing. Missing enhanced resources, missing
independent reviewers, unrepresentative sampling, unmatched lengths, unresolved
critical errors or unenforced production limits block release. Human fields stay
null, not zero. Final question may legitimately be answered **not established**.
