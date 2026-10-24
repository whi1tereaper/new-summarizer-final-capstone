# Article Engine v2.1: independent-validation release gate

22 October 2026. Candidate: `recipe-article-2.1`. **Release recommendation: keep automatic article routing disabled.**

**Answer to the release question: not established.** This prospective external
pilot does not demonstrate that v2.1 preserves more important information and
central meaning at comparable lengths without unacceptable contextual or
deployment failures. Independent human evaluation is pending. Most regenerated
outputs could not match LIGHT's realized length; important context, segmentation
and near-topic Focus problems remain. Scoped container success is not complete
production certification. No engine, parameter, legacy algorithm, six-depth UI,
Focus interface or production routing change was made for this study.

## 1. Freeze, comparator identity and prior-data audit

[freeze.json](independent-validation/freeze.json) records the 14:11:11 UTC source
freeze, Python/platform/package versions and 191 file hashes. The source/label
lock followed at 14:26:14 UTC, before any new summary was generated.

| Frozen component | SHA-256 |
|---|---|
| Article summarizer | `a282a416d85d6367a69e86302d5776f1909104b1ecc61812655f921453167012` |
| Article selector | `893d348a8fe5d41d859b9a0a4a49a9fbaf618a375cfcc6462e0fc19fdd73b982` |
| v2.1 refinement parameters | `9acfb2d2ce845f2290631c0bf530e01ed8fc47c2d418b4c62d8f5e0d7d12e195` |
| Original LIGHT pipeline comparator | `94b6641ac5b230979ef04e734fcf40aa2f77cfb71b4b3021297858feb1053efb` |
| Guide Sumy LSA baseline | `f046b1971dd741a6340149178ad03cc60853b24309cad1a876467c629019ad76` |
| Locked external corpus, including draft labels | `047c1e15655066f91b01ded03a01f975eec73ea03d1ee0aec4dd5bf5f54526e0` |

“Original LIGHT” means the application's existing `legacy` path at this freeze,
not an assertion that the dirty workspace equals pristine Git HEAD or an older
archived experiment. Existing user changes were preserved. Final checks find no
evaluation-relevant engine/baseline/parameter/routing/transport hash changes.
Concurrent edits to analytics/theme/navigation files were detected, retained and
reported separately; this study did not make or revert them.

The audit inspected [release readiness](article-engine-release-readiness.md),
[refinement results](refinement-results.json), [refinement performance](refinement-performance.json),
[external protocol](external-evaluation-protocol.md), `refinement_calibration.py`,
and existing source/annotation manifests. [prior-data-audit.json](independent-validation/prior-data-audit.json)
retains identities and manifest hashes. The groups overlap:

- Nine v2 development articles informed coverage development.
- Six synthetic CE-HO articles were once reserved, but were subsequently inspected
  repeatedly. They are not independent held-out evidence for this study.
- Twelve v2.1 development articles, including three adversarial core examples,
  selected core coefficients and MMR. Despite its filename,
  `refinement_calibration.py` is algorithm tuning, not independent validation.
- Ten historical sources, earlier NASA pilots, detector/evaluation fixtures and
  six external development nominations were also excluded. Historical unannotated
  articles do not acquire gold labels by being counted in a comparison.
- The uploaded academic guide is excluded from the new quality corpus. It is used
  separately below only as an already-inspected large-file resource fixture.

A supplementary [text-overlap audit](independent-validation/source-overlap-audit.json)
checked 54 distinct historical source texts. No new source was an exact duplicate;
the largest observed five-word-shingle Jaccard overlap was approximately .00167.
This supplements URL/identity checks, not proof of event/semantic independence.

## 2. New corpus and human evaluation status

Eight authentic English articles from eight publisher families total **16,378
words**, one article in each requested category. The complete
[provenance/licensing/inclusion log](independent-validation/corpus-provenance.md)
provides authors, dates, topics, acquisition failures, extraction decisions and
reuse policies. [corpus.json](independent-validation/corpus.json) contains the
actual prose, hashes and exact Unicode source spans.

| Category | Source | Words |
|---|---|---:|
| News | USGS: New Jersey aftershock-kit deployment | 570 |
| Scientific reporting | NIH/NCI: multivitamins and mortality | 345 |
| Original research | PLOS One: walking intensity/frailty cluster trial | 5,381 |
| Business analysis | EIA: US energy production, consumption and exports | 472 |
| Educational | Our World in Data: comparative energy safety | 2,535 |
| Technical | Python PEP 684: per-interpreter GIL | 4,670 |
| Editorial/opinion | Frontiers: net-zero decision-making under uncertainty | 1,568 |
| Feature | NASA Earth Observatory: Hektoria Glacier retreat | 837 |

Sources are agency-authored US public-domain prose or explicitly CC BY/CC0
material. Images, tables, code and bibliographies were excluded, not silently
rewritten. Official article links and author credits accompany every snapshot.
NIH's blocked endpoint was replaced with the official NCI mirror of the same
release. EIA required a corrected HTML parser before locking. No source was
chosen or replaced after inspecting a summary.

This is a **prospective non-tuning convenience pilot**, not completion of the
earlier 30-article confirmatory protocol. One article per genre provides no
within-category replication. Agency, energy/climate and academic material is
overrepresented. Business is sector analysis rather than earnings reporting;
opinion is an academic editorial rather than a general essay; the technical
article is a normative proposal rather than a systems-experiment paper. These
coverage gaps remain release limitations.

There are **42 source-first agent draft information units**, eight main-claim
drafts, required-context hypotheses and 24 Focus probes, locked before outputs.
They are **not independently reviewed must-preserve gold units**. No independent
reviewer or adjudicator participated; all human ratings, semantic unit judgments,
error severities and reviewer identities remain null/blank. No reference-summary,
ROUGE, human-accuracy or agreement figure is invented.

Prepared materials include [source-only packets](independent-validation/human-source-packet.json),
[288 masked selected outputs](independent-validation/human-output-packet.json),
[576 blank reviewer rows](independent-validation/human-review-blank.csv), a separate
decoding key, and [reviewer instructions/rubric](independent-validation/reviewer-instructions.md).
Two independent reviewers should identify/adjudicate source units before viewing
outputs. The 0–4 rubric separately covers main idea, important information,
factual consistency, attribution/context, coherence, readability, nonredundancy
and Focus relevance, with critical errors recorded separately.

## 3. Method and validity boundaries

The [registered protocol](independent-validation/protocol.md) compares existing
LIGHT, unchanged guide Sumy LSA, v2.1 lightweight and v2.1 enhanced. General mode,
paragraph format and identical source text are used. All six actual depths are
retained, with PHP sentence requests **3/3/5/8/11/15**. Article applies its existing
caps, giving **2/3/5/8/11/15**. Actual returned counts are retained even when legacy
processing produces a different count; evaluation does not cut them to fit.

Each source has six general cases and three Balanced Focus cases: supported,
partially supported, and semantically near but unsupported. This yields **288
system/case records and 1,220 completed, saved native/regeneration attempts**. All completed native
and selected outputs have no recorded execution exception, and none of the saved
generation trials failed. A separate host process interruption is disclosed below;
these counts do not mean an uninterrupted all-pass run or include an unknown
amount of work interrupted before a case could be saved.

The length target is each legacy native output's actual word count. Article
regenerates at the fixed target-relative budgets specified in the protocol;
guide regenerates counts 1 through the UI request count. Selection sees only
length, nonempty output and execution validity, never annotations. All complete
trials are saved. No evaluator prefix truncation, clause cutting, padding,
rewriting, or annotation-favorable length selection was used. Existing internal
legacy compression and guide cleaning remain part of those baselines.

The registered tolerance is `max(5 words, 10% of target)`, with 5% and 20%
sensitivity. The comparison uses one shared word-count regex; engine-reported
source/output counts may differ. Unsupported Focus informational outcomes are
not forced into zero-word length matches. Guide LSA is query-blind, not a
Focus-capable system.

Quality comparisons call the frozen candidate directly to evaluate its output,
not to claim detector coverage or production routing success. Global routing
stays false. The real opt-in EIA resource request was classified uncertain and
used legacy despite confidence above the numeric threshold, because eligibility
also matters. No detector rule was adjusted to accommodate it.

### What the automated counts mean

The following counts are **strict full-source-evidence retention diagnostics,
not semantic recall or independently verified accuracy**. A draft unit registers
only when an entire annotated evidence alternative survives, possibly spanning
several sentences. That penalizes meaningful legacy compression and incomplete
alternative annotations, while favoring exact extractors. Fifteen of the 252
article/depth exact-evidence bundles cannot fit the native article sentence/word
limits even before added context; see [attainability](independent-validation/draft-unit-attainability.json).
Their misses are not automatically avoidable semantic omissions. A central idea
may survive without matching the draft main bundle. Conversely, copying a central
assertion without its caveat may register a main hit while misleading a reader.

Source-span membership is provenance, not factuality. The guard's own context
pass is not an independent evaluator. Human main-idea, coherence, readability,
semantic redundancy, factuality and contextual-accuracy judgments remain pending.

## 4. Complete outputs and matched-length comparison

[comparison-results.json](independent-validation/comparison-results.json) and the
four `runs-*.json` artifacts retain every full native/regenerated summary, trial
budget, chosen trial, actual length, configuration and diagnostic. All unmatched
general cases are separately listed in the comparison artifact.

| All 48 general cases: selected complete output | Main-bundle hits /48 | Unit-bundle hits /252 | Words | Matched cases /48 |
|---|---:|---:|---:|---:|
| Original LIGHT | 16 | 56 | 13,598 | 48 |
| Guide LSA | 10 | 51 | 8,027 | 10 |
| Article 2.1 lightweight | 18 | 49 | 6,300 | 9 |
| Article 2.1 enhanced | 18 | 46 | 6,347 | 9 |

This table includes unmatched outputs and **cannot establish comparable-length
superiority**. Native totals were guide 11 main/55 unit hits/8,521 words;
lightweight 18/47/6,137; enhanced 18/45/6,168. Legacy native is its anchor above.
The v2.1 outputs are substantially shorter on this corpus, not equivalent in
length merely because the UI depth and ceiling agree.

| Genuinely matched subset | Pairs | Candidate / LIGHT main hits | Candidate / LIGHT unit hits | Unit denominator | Candidate / LIGHT words |
|---|---:|---:|---:|---:|---:|
| Guide LSA | 10 | 6 / 4 | 23 / 13 | 52 | 1,501 / 1,515 |
| Article lightweight | 9 | 6 / 3 | 19 / 11 | 45 | 1,299 / 1,345 |
| Article enhanced | 9 | 6 / 3 | 18 / 12 | 45 | 1,297 / 1,335 |

These pairwise subsets differ. Only **five cases** match across all systems:
business Comprehensive, news Brief, and scientific Balanced/Detailed/Comprehensive.
On that common subset, main hits are LIGHT 3, guide 5, light/enhanced 4; unit hits
are 11, 20, 16, 16 of 25 respectively. It covers three articles, with three
repeated depths from the same short scientific article. It is not representative
independent evidence of general superiority. No bootstrap confidence interval or
significance claim is justified from unreviewed copy-based diagnostics.

Matched counts at 5% / 10% / 20% tolerance (each also permits five words) are
guide **8/10/17**, lightweight **8/9/10**, enhanced **8/9/13** of 48. Thus **39 of
48 general cases per v2.1 configuration remain unmatched** at the primary rule.
Neither research nor technical article matches at any depth. Those failures to
realize length are retained, not removed to claim balanced quality.

### Per-category observations

Cells are `main-bundle hits / unit-bundle hits [matched cases]`, across six depths.
Each category has six main checks and 30 repeated unit checks, except research
and technical with 36. These are unreviewed diagnostics over complete selected
outputs, **including unmatched cases**, not category accuracy estimates.

| Category | LIGHT | Guide LSA | Article light | Article enhanced |
|---|---:|---:|---:|---:|
| Business | 0 / 9 | 1 / 11 [2] | 0 / 11 [1] | 0 / 11 [1] |
| Editorial | 2 / 4 | 0 / 0 [0] | 0 / 0 [1] | 0 / 0 [1] |
| Educational | 4 / 5 | 0 / 0 [0] | 0 / 0 [1] | 0 / 0 [0] |
| Feature | 1 / 10 | 0 / 3 [0] | 0 / 2 [0] | 0 / 2 [1] |
| News | 2 / 13 | 5 / 9 [2] | 6 / 10 [2] | 6 / 9 [2] |
| Research | 2 / 4 | 0 / 5 [1] | 6 / 7 [0] | 6 / 7 [0] |
| Scientific | 5 / 11 | 4 / 21 [4] | 6 / 19 [4] | 6 / 17 [4] |
| Technical | 0 / 0 | 0 / 2 [1] | 0 / 0 [0] | 0 / 0 [0] |

Zero strict-copy hits do not mean zero useful meaning; they also expose limits
of the draft evidence alternatives. News/scientific sources look more favorable
than editorial/educational/feature sources. The research main hit is particularly
unsafe to interpret as quality because the favorable claim loses sensitivity
context. Independent semantic review is necessary in every category.

### Per-depth observations

Same notation, now eight main checks and 42 unit-bundle checks per depth. LIGHT
is the anchor; bracketed counts show matches among the eight alternative outputs.

| Depth | LIGHT | Guide LSA | Article light | Article enhanced |
|---|---:|---:|---:|---:|
| Very Brief | 0 / 1 | 0 / 1 [3] | 3 / 5 [4] | 3 / 4 [4] |
| Brief | 1 / 3 | 1 / 4 [2] | 3 / 5 [1] | 3 / 4 [1] |
| Short | 2 / 6 | 2 / 7 [1] | 3 / 6 [0] | 3 / 6 [0] |
| Balanced | 2 / 7 | 2 / 9 [1] | 3 / 8 [1] | 3 / 8 [1] |
| Detailed | 5 / 17 | 2 / 13 [1] | 3 / 11 [1] | 3 / 11 [1] |
| Comprehensive | 6 / 22 | 3 / 17 [2] | 3 / 14 [2] | 3 / 13 [2] |

Very Brief's LIGHT zero is a full-evidence matching result, **not a finding that
all eight legacy summaries lost their main idea**. Repeated depths are correlated
observations from the same eight articles.

## 5. Failure analysis: exact source addresses and actual outputs

[failure-traces.json](independent-validation/failure-traces.json) retains every
native/selected draft-unit omission, context flag and nonverbatim provenance
check, with exact source sentences/offsets and complete implicated summaries.
There are 2,577 diagnostic trace records, **not 2,577 independently established
errors**; native/selected views and related unit/depth flags overlap. Source IDs
below resolve in `corpus.json`. The following are agent inspection observations,
not fabricated reviewer assessments or adjudicated severity labels.

### Business central proposition

`IV-BUSINESS:balanced:general`, light: 176 words, versus legacy 253, so unmatched.
The selected source IDs are `s0003,s0009,s0011,s0013,s0014,s0015,s0019,s0020`.
It retains consumption/production and individual export figures, but omits the
explicit surplus/record-total-export statement in `s0000–s0001` and net-trade
context `s0002`. It spends two sentences on Btu conversion (`s0019–s0020`).
This is a documented selection/coverage concern, not proof that readers cannot
infer a surplus from the remaining figures. The business main draft is missed
at all six depths by both v2.1 variants; legacy's strict draft is also missed.

### Editorial, explanation and late information

The editorial's central critique is `s0001–s0002`; its late synthesis and
institutional prescription are `s0051–s0056`. Light Comprehensive selects `s0053`
(announcing two questions) without their actual content in `s0054–s0055`.
It also selects the dangling clause in `s0043` without the attributed subject
in `s0042`. Enhanced and other depths remain reviewable in the full outputs.
The educational and feature drafts likewise flag missing central/late bundles:
education `s0123–s0124`, and feature `s0036–s0038` qualify the broad narrative.
No general-essay quality conclusion is supported by one academic editorial.

### Technical multi-finding coverage and segmentation

Light Comprehensive on PEP 684 contains 169 words versus legacy 838 and is
unmatched. It includes short source fragments such as `s0089` and `s0145`, rather
than complete standalone explanations. The source index also counts a larger
sentence encompassing some selected fragments; addresses are therefore not
necessarily one output sentence per source ID. Important relations include
central isolation/parallelism (`s0003–s0004`), compatibility restrictions
(`s0120–s0123`), linked-library locking (`s0166,s0168`), and maintainer burden
(`s0175–s0177`). Their complete draft bundles are absent from that output.

The research output similarly includes fragments from abbreviations/labels,
including `s0167`, `s0180` and `s0186`. These are exact source spans, but exactness
does not make them grammatical, informative standalone sentences. The observations
are consistent with segmentation plus low-cost fragment selection; this study
does not prove a causal ablation or modify tokenization/selection to fix it.

### Scientific negation, uncertainty and attribution/context

The short NCI article retains negative mortality findings relatively well, but
scope must still distinguish generally healthy participants (`s0006`) from
untested deficient populations and other conditions (`s0012`). A missing scope
flag is a reviewer prompt, not automatically a false medical claim.

More consequentially, both v2.1 variants retain the walking trial's favorable
main assertion at every depth while omitting all registered nonsignificant
missing-data alternatives (`s0144,s0145,s0146,s0219`). In light Comprehensive,
`s0011` and `s0015` survive, but none of those sensitivity sentences does. The
late statement in `s0219` explicitly limits the significance of the finding.
All six general depths per variant trigger this draft context concern. It is
not repaired by an exact-source guard returning no issues.

Source inconsistencies must remain separate: the walking paper itself differs
between abstract/discussion female percentages and registration identifiers.
Copying these is not automatically an introduced hallucination. No independent
external-truth verification, factual-error rate or critical-error clearance exists.

Across selected general outputs, source membership is article **348/348** for
either variant, LIGHT **402/504**, guide **340/341**. Nonverbatim baseline outputs
are not thereby false. Draft context flags number light 7, enhanced 6, legacy 5,
guide 0; these sparse, implementer-defined rules are incomplete and do not measure
all misleading omissions. No exact duplicates were found; semantic redundancy,
coherence and readability remain independently unrated.

## 6. Focus: supported, partial and near-topic unsupported

[focus-analysis.json](independent-validation/focus-analysis.json) contains all 96
native Focus outputs, queries, draft support rationales, exact retrieved evidence,
and pending human fields. Each system has eight probes of each support kind.

Both article variants avoid explicit rejection of all eight draft-supported
queries, but each retains only **4 of 17 complete draft Focus bundles**; legacy
retains 3 and query-blind guide 4. These are strict-copy diagnostics, not semantic
Focus recall. A nonempty response or supported status does not establish relevance.

All **eight near-topic unsupported queries return nonempty excerpts** for each
article variant, rather than the unavailable-information outcome. The same is
true for the legacy and query-blind guide baselines, so this is not a claim of a
new regression unique to v2.1. The harder probes expose what unrelated Jupiter
queries in historical tests did not. Examples:

- NCI query `multivitamin prevention of dementia` returns mortality/population
  sentences `s0000,s0002,s0005,s0006,s0010` in lightweight mode, with
  `semantic_wordnet_supported`. None reports a dementia-prevention estimate.
- Business `energy company profit margins` returns energy-volume evidence, not
  corporate earnings evidence.
- Technical `GPU performance benchmarks` returns CPU/interpreter material; that
  does not answer a GPU benchmark question.
- Research `walking effects on hospital admissions` can retrieve material from a
  paper mentioning hospitalization as background risk, not a measured admissions
  effect of the intervention.

These are unsupported-response/relevance flags under source-first agent support
labels, not invented human false-positive percentages or proof of hallucinated
numerical claims. Partial queries similarly return excerpts without a dedicated
partial-support judgment; independent reviewers must check whether the missing
component is made clear. No separate Focus interface or new query heuristic was
introduced, and no algorithm was retuned after seeing these failures.

## 7. Enforced runtime and memory evidence

[resource-summary.json](independent-validation/resource-summary.json) is the
authoritative ledger. Unlike the earlier RSS-only refinement profile, these tests
enforce **aggregate Docker cgroup memory**, zero swap allowance, one CPU, the
existing **180-second Python deadline**, and a 185-second outer HTTP read deadline.
Kernel `memory.peak`, `memory.events` and configured limits are retained. Numbers
include Apache, PHP, Python children, extraction, NLP, supervisor where used,
file-cache pages and small in-container measurement overhead—not Python RSS alone.

Current frozen `app` and Python sources were read-only mounted into the existing
Apache/PHP deployment image. The production image lacks spaCy; a separately
identified evaluation-only image adds spaCy 3.8.11 and `en_core_web_sm` 3.8.0.
Its image digest and installed packages are retained. Windows quality runs use
the existing local spaCy 3.8.16/model 3.8.0 and existing optional legacy resources.
Linux fallback lacks the Windows embedding stack, so those paths are not assumed
performance- or output-equivalent. Production dependencies were not changed.

### Persistent-worker article path

Each row includes five serial requests (first/warm real article, first/warm Focus,
research PDF) and two overlapping real-article requests. All successful responses
confirm `recipe-article-2.1` and actual daemon use. This is the 5,381-word article,
not the historical synthetic scaling fixture.

| Configuration / enforced cap | Peak MiB | Minimum peak headroom MiB | Cold / warm article s | First / warm Focus s | PDF s | HTTP successes / requests |
|---|---:|---:|---:|---:|---:|---:|
| Light /512 MiB | 444.48 | 67.52 | 2.144 / .144 | 1.325 / .485 | .234 | 6/7 |
| Enhanced /512 MiB | 431.56 | 80.44 | 3.555 / .863 | 1.909 / 1.097 | .829 | 6/7 |
| Light /1024 MiB | 275.50 | 748.50 | 2.026 / .153 | 1.652 / .511 | .236 | 6/7 |
| Enhanced /1024 MiB | 373.13 | 650.87 | 3.092 / .825 | 1.968 / 1.508 | .987 | 6/7 |

All five serial calls passed in each row; exactly one overlapping request was
rejected as **`WORKER_UNAVAILABLE`** by bounded one-worker admission. There was
no observed cgroup OOM kill or timeout in these completed matrices. Rejection is
an actual capacity result, not counted as successful concurrency. No load SLO,
queue acceptance policy or sustained-concurrency capacity was established.
Apache readiness took roughly .57–1.28 seconds; cold request latency includes
lazy NLP startup. These are individual observations, not percentile estimates.
Different cache charging/host state makes the isolated peak ordering noisy;
do not infer that enhanced is intrinsically smaller from one 512 MiB run.

### CLI route, very large file and failures retained

At 512 MiB, the subprocess matrices completed seven requests per configuration,
with peaks **396.06 MiB light /491.43 MiB enhanced**. Only four per configuration
actually used Article 2.1. The first three EIA requests used legacy because
article eligibility was uncertain; they must not be labeled article timing or
article Focus results. Enhanced CLI left **20.57 MiB** peak headroom in this small
matrix—insufficient evidence of a robust production reserve.

The existing **162-page, 6,828,558-byte academic guide** was then tested separately
through capped Apache/PHP/extraction/authenticated supervisor, source SHA-256
`c2e33f097bc6db17ba6ab730b881c50fb73a047015dee8011c1f21f3bb33937a`.
Light/enhanced image configurations completed in **21.25/37.83 seconds**, peaking
at **300.12/387.33 MiB**, without observed OOM or timeout. Crucially, both were
**legacy fallback**, not successful Article 2.1 large-document summaries:
detection found 1,365 complete sentences, and article processing raised a typed
failure consistent with its 1,000-candidate limit. The fallback succeeded.
This known document was never reclassified as fresh quality evidence, and its
copyrighted PDF was not redistributed into the new corpus.

Initial harness attempts also failed before requests because of image-argument,
read-only mount/startup and port issues. These remain in attempt artifacts, not
quality failure counts. A later overlapping Windows evaluation/container run
encountered `WinError 1455` paging-capacity failure and a detector-import
`MemoryError`; the legacy worker then exited `0xC0000005` after 47 retained cases.
The precise access-violation cause is not diagnosed. The 1 GiB CLI matrix was
incomplete. Only owned disposable containers were stopped/removed. Remaining
quality cases and the subsequent warm matrices were run serially, retaining the
original completed cases and unchanged inputs; see [host-incidents.json](independent-validation/host-incidents.json).
No host failure is relabeled as an engine cgroup OOM or a clean uninterrupted run.

### Deployment boundaries

This measures actual Apache → PHP ArticleService → bridge → extraction/routing →
Python/models, with authenticated supervisor where declared. It deliberately
bypasses constructor database access and does **not** exercise controller/session,
durable job persistence, database, authentication, history or deployed network
paths. The disposable endpoint fails closed without its sandbox flag and token;
the host HTTP check returned 403. No real database/user rows were written.

`render.yaml` declares 180 seconds but no actual deployment plan/memory allocation.
Therefore 512 MiB/one CPU is an enforced **declared test envelope**, not verified
live production equivalence. The augmented enhanced image is not the current
production dependency image. No repeated soak, diverse live-URL extraction,
scanned/OCR PDF, worst-case document sweep, deliberate deadline-expiry trial or
full deployed transaction-path audit was executed. These remain pending;
container success here does not certify production readiness.

## 8. Verification, reproducibility and release decision

New research-harness tests: **six passed**, including fixed six-depth requests,
immutable input/freeze checks, valid exact source addresses, score-independent
length choice, complete 72-case records per system and null human scores. The
first overlapping run of these tests skipped the still-running output check;
the final run has no skip. New PHP helper syntax checks passed, and Git whitespace
checking found no whitespace errors. This task does not claim a new run of the
historical 678-test suite. Automated tests are not the quality activation gate.

The final CLI configuration check confirms
`automatic_article_routing=false`, `summary_engine_version=legacy`. Opt-in is
preserved and used only in isolated containers. Frozen evaluation-relevant hashes
remain unchanged. Temporary benchmark containers were removed; the separately
tagged evaluation image remains available for reproduction.

See [artifact index/reproduction](independent-validation/README.md) for source,
output, review, failure and resource artifacts. Freeze and input locks must be
checked before any replay. Further tuning requires a new version and a new
untouched evaluation corpus; do not tune against these now-inspected articles and
continue calling them held out.

**Do not approve global automatic routing.** The matched pilot subset is too small
and biased toward strict source copying; independent must-preserve/central-meaning
and factual/context review is absent; business/opinion/technical/late-context
problems and near-topic Focus responses are unresolved; full production resource
and transaction-path readiness is unverified. No scientifically defensible
accuracy percentage, broad superiority claim or factual-error clearance follows.

Before reconsideration: independently annotate a larger untouched, replicated
corpus; adjudicate main propositions and uncertainty/attribution dependencies;
review complete genuinely length-comparable outputs with clustered-by-article
analysis; resolve documented failure patterns in a separately versioned candidate;
then test the actual deployment image/plan, full job/database path and concurrency
policy under enforced limits with an explicit operational headroom/SLO target.
