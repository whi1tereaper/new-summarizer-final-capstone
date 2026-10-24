# Article Engine v2.1 — final validation and release gate

23 October 2026 (Asia/Manila). **NOT READY. Keep automatic article routing disabled.**

The evidence does **not establish** that Article Engine v2.1 preserves more
important information and central meaning than original LIGHT at comparable
summary lengths without unacceptable factual/contextual or deployment failures.
Independent human validation remains pending. This is a completed engineering
and evaluation-methodology follow-up, **not completed independent human quality
certification**. No algorithm, detector, parameter, frontend, six-depth control,
Focus interface, opt-in or production routing change was made.

## A. Research objectives

Audit the [external pilot](article-engine-independent-validation.md), explain its
9/48 realized-length matches per article configuration, prepare genuinely
independent review, investigate source-grounded Focus/context concerns, and
verify the scope of enforced-resource evidence. Distinguish three evidence types:

- **Recorded behavior:** complete outputs, requests, lengths, errors and resource
  counters. These can establish what the frozen system actually did.
- **Agent/development diagnostics:** draft information bundles, source-role maps,
  tentative alignment and warning rules. These are not semantic accuracy.
- **Independent human judgments:** none supplied; all scores, critical-error
  adjudications and inter-rater agreement remain pending/null.

The [follow-up protocol](final-validation/protocol.md) and
[input lock](final-validation/followup-input-lock.json) precede the new outputs.
The original 288 records, 1,220 saved generation attempts, unmatched cases and
all 59 pilot artifact files remain unchanged. This follow-up adds **200 complete
generation records: 104 diagnostic controls and 96 Focus outputs, with zero
recorded execution exceptions**. This does not erase earlier interrupted work or
certify quality. New native control bodies repeat the corresponding pilot native
bodies exactly in all **34/34** checked system/article/depth cases.

## B. Frozen engine versions

[freeze.json](final-validation/freeze.json) identifies `recipe-article-2.1`, the
dirty-workspace Git HEAD for context, 192 protected production files, all pilot
artifact hashes and the locked protocol. The manifest, not a claim of a clean
commit, defines this evaluated system. Existing unrelated user edits are retained.

Frozen production-file manifest SHA-256:
`0a19ecc8fdc07e8748ea9cc589b4e21d5d692db3b2bddb934e5f0974748f62b8`.

| Component | SHA-256 |
|---|---|
| Article summarizer | `a282a416d85d6367a69e86302d5776f1909104b1ecc61812655f921453167012` |
| Article selector | `893d348a8fe5d41d859b9a0a4a49a9fbaf618a375cfcc6462e0fc19fdd73b982` |
| v2.1 refinement parameters | `9acfb2d2ce845f2290631c0bf530e01ed8fc47c2d418b4c62d8f5e0d7d12e195` |
| Original LIGHT pipeline | `94b6641ac5b230979ef04e734fcf40aa2f77cfb71b4b3021297858feb1053efb` |
| Guide Sumy LSA | `f046b1971dd741a6340149178ad03cc60853b24309cad1a876467c629019ad76` |
| External corpus with draft labels | `047c1e15655066f91b01ded03a01f975eec73ea03d1ee0aec4dd5bf5f54526e0` |

“Original LIGHT” is the existing frozen `legacy` path, not an unmodified archival
release. Lightweight and enhanced v2.1 are separate configurations; enhanced
loads the existing local spaCy model when available. Guide LSA is query-blind.
The final configuration probe reports `automatic_article_routing=false` and
`summary_engine_version=legacy`. Isolated benchmark containers alone use the
existing opt-in. Global routing was never enabled by this work.

## C. External article corpus

Use the pilot's eight authentic English sources, **16,378 words**, with complete
snapshots, author credits, source dates, hashes and Unicode `[start,end)` evidence
addresses in [corpus.json](independent-validation/corpus.json). Full acquisition,
licensing, exclusions and inclusion criteria are in the
[provenance log](independent-validation/corpus-provenance.md).

| ID / category | Authentic source | Words / source sentences | Prose reuse basis |
|---|---|---:|---|
| IV-NEWS / news | USGS, New Jersey aftershock kits | 570 / 22 | US federal public-domain prose |
| IV-SCIENCE / scientific reporting | NIH/NCI, multivitamins and mortality | 345 / 13 | US federal public-domain prose |
| IV-RESEARCH / original research | PLOS One, walking intensity/frailty trial | 5,381 / 227 | CC BY |
| IV-BUSINESS / business analysis | EIA, US energy production/consumption/exports | 472 / 21 | US federal public-domain prose |
| IV-EDUCATION / educational | Our World in Data, energy safety | 2,535 / 128 | CC BY article prose |
| IV-TECHNICAL / technical | Eric Snow, PEP 684 | 4,670 / 240 | Public domain / CC0 declaration |
| IV-EDITORIAL / opinion | Frontiers, net-zero decision-making editorial | 1,568 / 58 | CC BY |
| IV-FEATURE / feature | NASA Earth Observatory, Hektoria Glacier | 837 / 40 | US federal public-domain prose |

Inclusion was 300–8,000 words, accessible authentic English article prose,
documented reuse permission and no known prior tuning/inspection identity at
pilot selection. Tables, images, code and bibliographies were excluded before
locking; their findings are outside this prose-only comparison. Sentence IDs use
the engine's segmentation for addressing, **not an independent linguistic gold**.

The inspected [prior-data audit](independent-validation/prior-data-audit.json)
and [overlap audit](independent-validation/source-overlap-audit.json) distinguish
nine v2 development articles, six formerly reserved but subsequently inspected
synthetic CE-HO sources, twelve v2.1 development articles, ten historical sources,
older NASA/detector fixtures and external development nominations. These groups
overlap. `refinement_calibration.py` tuned coefficients/MMR; its data and
`refinement-results.json` / `refinement-performance.json` are not independent
confirmation. The earlier 30-article external protocol was not completed.

These eight sources were external and non-tuning for the pilot, but are **now
inspected**. This follow-up is retrospective on them, not a newly untouched
holdout. No tuning was performed. There is one source per category, substantial
agency/energy/climate representation, no general-audience essay or corporate
earnings article, and no replicated empirical technical category. A broader
untouched corpus is required for a later confirmatory release claim.

## D. Independent reviewer protocol

**No qualified independent reviewer or adjudicator has participated.** There are
42 source-first **agent draft** units, not independently identified gold units.
The independently reviewed must-preserve set, human quality scores, error
severities, disagreements and agreement estimates cannot honestly be supplied.

The new [reviewer instructions](final-validation/reviewer-instructions.md) specify
qualification/independence/conflict/exposure declarations, source-first unit
identification, attribution and scope/dependency annotation, source-query support
judgments, locked adjudication, then blinded output review. Nine separate 0–4
axes cover main idea, coverage, factual consistency, attribution, context,
coherence, readability, nonredundancy and Focus relevance. Critical errors need
source/output spans and rationale; N/A is explicit, not zero. Original scores
must survive adjudication.

Prepared, genuinely unrated materials:

- [Source-only packet](final-validation/source-only-packet.json), blank source-unit
  forms and 96 blank support-rating rows for 48 unique source/query pairs.
- [385 unique masked outputs](final-validation/masked-output-packet.json), with
  770 blank output-rating rows and a separate decoding key. They include original
  native/registered-selected views, all new Focus outputs and cross-depth matches.
- [1,560 unique Focus sentence items](final-validation/masked-focus-sentence-packet.json),
  3,120 blank sentence-rating rows, mapped to **2,329 sentence occurrences** across
  288 output views: 192 native Focus outputs plus 96 original registered-selected
  views. Deduplication avoids repeated rating work, not deletion of observations.
- Empty reviewer registry, adjudication template and
  [agreement results](final-validation/agreement-results.json): **zero submitted
  rating cells; agreement null**.

The coordinator must withhold decoding keys, run metadata, agent labels/warnings
and this report until ratings lock. Random tokens do not guarantee perfect
blinding because summary style may reveal a system. The agreement utility accepts
only actual registered, qualified, independent, dated submissions, rejects invalid
scales/duplicates/unknown items, retains disagreements and reports paired counts,
unweighted and ordinal quadratic-weighted Cohen kappa. Constant-marginal kappa
can be undefined; agreement is not accuracy. See the
[official metric definition](https://scikit-learn.org/stable/modules/generated/sklearn.metrics.cohen_kappa_score.html).
Synthetic metric fixtures exist **only in unit tests**, not research ratings.

## E. Word-length comparison validity

### Original registered comparison: reproduced, not replaced

All six actual UI depths remain unchanged. The tolerance is
`max(5 words, 0.10 × legacy native words)`. The pilot selected the nearest valid,
nonempty **complete generated** output using length alone; no annotation score
was used. All original trials and unmatched outputs remain available.

| Depth | UI sentence request | Article effective count cap* | Article word-ratio / maximum |
|---|---:|---:|---:|
| Very Brief | 3 | 2 | .10 / 80 |
| Brief | 3 | 3 | .18 / 150 |
| Short | 5 | 5 | .26 / 280 |
| Balanced | 8 | 8 | .38 / 500 |
| Detailed | 11 | 11 | .55 / 850 |
| Comprehensive | 15 | 15 | .75 / 1,600 |

*Also limited by available source sentences and context-bundle feasibility.
The internal depth count limits are 2/4/6/10/18/32; the lower UI requests normally
bind. Article's native word ceiling is the depth maximum capped against
`max(45, round(source_words × ratio))`; a ceiling is not a fill target. Pilot
regeneration varied supported word ceilings, not these UI counts. Guide varied
whole-sentence requests; legacy's existing internal compression is retained.

| Same-depth selected outputs | Matched /48 | Main-bundle hits /48 | Unit-bundle hits /252 | Total words |
|---|---:|---:|---:|---:|
| LIGHT anchor | 48 | 16 | 56 | 13,598 |
| Guide LSA | 10 | 10 | 51 | 8,027 |
| Article light | 9 | 18 | 49 | 6,300 |
| Article enhanced | 9 | 18 | 46 | 6,347 |

Main/unit counts are **unreviewed full-evidence-copy diagnostics**, not semantic
quality. This all-case table includes unmatched lengths and cannot establish
comparable-length superiority. There are **39 unmatched cases per v2.1 mode**;
neither research nor technical matches at any same depth. The only common
all-system matched subset has five cases from three articles. On each v2.1
pairwise nine-case subset, light has 19 versus LIGHT 11 unit-bundle hits, enhanced
18 versus LIGHT 12; differing subsets and copy bias prohibit a quality conclusion.
The [original results](independent-validation/comparison-results.json) retain the
5%/10%/20% sensitivity and every unmatched case.

### Why 9/48: constraints, selector behavior and control semantics

[length-diagnosis.json](final-validation/length-diagnosis.json) checks every case
with an exact cardinality-constrained knapsack over full source sentences. It
tracks **both** the engine budget tokenizer and evaluator word regex, relaxing
all context/selector restrictions. A feasible relaxed length is not a valid
summary; infeasibility is a bound under this registered-source inventory, not
a proof about arbitrary resegmentation or compression.

For **each** v2.1 mode:

- **12/48 targets remain infeasible even at the full existing depth word maximum**
  under the effective sentence cap. They are editorial Detailed/Comprehensive,
  educational Detailed/Comprehensive, feature Balanced/Detailed/Comprehensive,
  news Detailed, research Comprehensive and technical Balanced/Detailed/Comprehensive.
- Another **27 unmatched targets are feasible in the relaxed searched envelope**,
  but none of the saved selector outputs reaches the tolerance. This does not
  mean a coherent/context-complete set would be feasible or selector-reachable.
- The remaining **nine match**. Native sentence caps saturate in **44/48 light**
  and **43/48 enhanced** cases. All trial bodies are identical in **38/48 light**
  and **37/48 enhanced** cases despite different ceilings.

Selection seeds a central sentence, then favors coverage gain divided by cost
(`cost_power=1`) with the frozen MMR .95 and context rules. A hard count cap can
be consumed by short fragments; increasing the word ceiling does not force
longer choices. This source inspection plus controls supports a length-selection
explanation; it is not a causal quality ablation.

The **96 new article control calls** compare native, maximum-word/same-count,
and existing-internal-count/maximum-word requests on all eight sources at Balanced
and Comprehensive. Merely increasing words leaves outputs unchanged for seven
of eight sources in both modes/depths; the short NCI source is the exception.
Increasing offline internal counts does lengthen outputs, without demonstrating
better meaning. Light research Comprehensive moves **193 → 193 → 540 words**;
technical **169 → 169 → 366**. Enhanced counterparts are **223 → 223 → 577** and
**171 → 171 → 357**. These diagnostic API controls are **not** replacements for
the actual UI settings or additional quality-comparison candidates.

The **eight new legacy calls** confirm a second, distinct constraint: at Balanced,
business stays **253 words**, research **317 words**, with native request,
`target_word_budget=50`, count 3 and count 15. Explicit depth normalizes count;
source inspection shows the explicit word budget is assigned after the existing
final extractive budget step, affecting advertised range/optional synthesis
rather than enforcing that final extractive output. Both budget-50 responses
advertise maximum 50 while exceeding it. Reversing the length anchor through
that ineffective control would not create fair matched outputs. It was **not
patched** in this validation. Full requests, bodies and ranges are in the four
`new-runs-*.json` files and [analysis summary](final-validation/analysis-summary.json).

### Separate retrospective methodology improvement

[cross-depth-sensitivity.json](final-validation/cross-depth-sensitivity.json)
uses complete existing outputs within each article, deduplicates identical bodies,
and performs one-to-one **maximum-cardinality, then minimum absolute-word-distance**
matching, with stable ties and the original tolerance. It sees only lengths and
validity. Native-only and all-saved-trial pools are separate. All unmatched legacy
cases and candidate output IDs remain listed. No evaluator truncation, clause
cutting, stitching, padding, rewriting or annotation-driven selection occurs.

| Comparator | Original same-depth matches | Cross-depth native-only pairs | Cross-depth all-saved-trial pairs | All-trial candidate / LIGHT words |
|---|---:|---:|---:|---:|
| Guide LSA | 10 | 18 | 29 | 5,460 / 5,539 |
| Article light | 9 | 20 | 23 | 3,445 / 3,446 |
| Article enhanced | 9 | 18 | 20 | 2,837 / 2,780 |

All-trial unmatched original legacy cases are 19/25/28 respectively. Fifteen of
the 23 light pairs and twelve of the 20 enhanced pairs have **different depth
labels**. The common all-comparator legacy intersection is 13 cases, versus the
pilot's five. These are more useful review materials at comparable realized
length, **not same-depth equivalence, a newly independent study, or a replacement
for the unfavorable pilot match rate**. Pools/search space differ by comparator.
The finite saved pool may miss possible outputs. Per-category and per-legacy-depth
pair listings, original depths, budgets and unmatched cases are retained.

## F. Main idea and information-coverage evaluation

Independent central-meaning and must-preserve-unit judgments are **pending**.
The following descriptive cells are `main-bundle hits / unit-bundle hits` over
the original selected full summaries, **including unmatched cases**. They are
not independently verified recall, accuracy, or equivalent-length effects.
Exact-evidence rules penalize legacy compression and unannotated paraphrases;
conversely, a copied positive claim can score while losing an essential caveat.

| Category; six depths each | LIGHT | Guide LSA | Article light | Article enhanced |
|---|---:|---:|---:|---:|
| Business | 0 / 9 | 1 / 11 | 0 / 11 | 0 / 11 |
| Editorial | 2 / 4 | 0 / 0 | 0 / 0 | 0 / 0 |
| Educational | 4 / 5 | 0 / 0 | 0 / 0 | 0 / 0 |
| Feature | 1 / 10 | 0 / 3 | 0 / 2 | 0 / 2 |
| News | 2 / 13 | 5 / 9 | 6 / 10 | 6 / 9 |
| Research | 2 / 4 | 0 / 5 | 6 / 7 | 6 / 7 |
| Scientific | 5 / 11 | 4 / 21 | 6 / 19 | 6 / 17 |
| Technical | 0 / 0 | 0 / 2 | 0 / 0 | 0 / 0 |

Each category has six main checks and 30 repeated unit checks, except research
and technical with 36. Category and article identity cannot be separated at n=1.
Zero strict-copy main hits do **not** establish that no central meaning survived.

| Depth; eight articles each | LIGHT | Guide LSA | Article light | Article enhanced |
|---|---:|---:|---:|---:|
| Very Brief | 0 / 1 | 0 / 1 | 3 / 5 | 3 / 4 |
| Brief | 1 / 3 | 1 / 4 | 3 / 5 | 3 / 4 |
| Short | 2 / 6 | 2 / 7 | 3 / 6 | 3 / 6 |
| Balanced | 2 / 7 | 2 / 9 | 3 / 8 | 3 / 8 |
| Detailed | 5 / 17 | 2 / 13 | 3 / 11 | 3 / 11 |
| Comprehensive | 6 / 22 | 3 / 17 | 3 / 14 | 3 / 13 |

Each depth has eight main and 42 repeated unit checks. Article same-depth matched
counts by depth are 4/1/0/1/1/2 for either mode. All length-matched and unmatched
case IDs remain available, so a short-depth apparent advantage cannot conceal
deep-summary coverage failures.

In the **retrospective all-trial length pairs**, strict unit hits are guide 43
versus LIGHT 31 (29 pairs), light 33 versus LIGHT 20 (23 pairs), enhanced 27 versus
LIGHT 18 (20 pairs). Main hits are 9/7, 10/5 and 8/4 respectively (candidate/LIGHT).
On the common 13-case subset, each candidate has five main hits versus LIGHT
three; unit hits are guide 24, light/enhanced 22, LIGHT 15. These remain copy-biased
draft diagnostics; neither the larger pool nor length matching turns them into
independent semantic evidence.

Source-grounded selection concerns remain:

- **Business:** light Balanced keeps consumption, production and export subfigures
  but omits explicit surplus/record-total-export/net-trade `s0000–s0002`, while
  spending two sentences on Btu conversion `s0019–s0020`. Readers may infer part
  of the surplus; inference is not the same as explicit preservation.
- **Editorial:** central critique `s0001–s0002` and late institutional synthesis
  `s0051–s0056` are not recovered as complete draft bundles. This one academic
  editorial cannot establish general essay/opinion quality.
- **Technical:** PEP 684's isolation/parallelism `s0003–s0004`, compatibility
  `s0120–s0123`, linked-library locking `s0166,s0168` and maintainer burden
  `s0175–s0177` require a multi-proposition review, not a single lexical score.
- **Late information:** education `s0123–s0124`, feature `s0036–s0038`, and the
  trial's late limitations/sensitivity evidence need explicit semantic review.

Exact implicated source sentences/offsets and whole summaries are preserved in
the [pilot traces](independent-validation/failure-traces.json) and new
[context/factual packet](final-validation/context-factual-review.json), not just
the abbreviated addresses here. No new ranking or tokenization fix was made.

## G. Factual consistency results

**No independent factual-error rate or critical-error clearance is available.**
Selected general v2.1 outputs have 348/348 exact source-member sentences per mode;
legacy has 402/504, guide 340/341. This establishes provenance only. Compression
is not automatically a factual error; copying is not automatically safe.

The new packet screens **all 385 unique review outputs / 3,128 sentence
occurrences**. It preserves full-source neighbors and exact spans where found;
219 nonverbatim alignments are explicitly tentative lexical candidates, never
entailment decisions. Screens flag 76 short/fragment candidates, 209 possible
unresolved anaphors, 123 missing manually mapped neighbor dependencies, and one
possible attribution loss. Negation, uncertainty, added-causality and novel-number
literal screens each flag zero in this narrow implementation. **Zero screen flags
do not prove absence of errors**: these rules miss semantic reversals, changed
relationships and omissions, and their flags overlap. All human severity/error
fields remain null. Rules and tentative alignment require independent checking.

The research trial is a concrete qualification concern. Both v2.1 modes retain
favorable main-claim evidence at all six general depths, while omitting the
registered nonsignificant missing-data alternatives `s0144–s0146,s0219`.
Light Comprehensive retains favorable `s0011,s0015` without these sensitivity
qualifiers. Continuous-outcome non-significance `s0148`, attrition `s0216`, and
longer-term/co-intervention limits `s0220–s0221` also require review. A guard's
`issues=[]` does not resolve selective evidence or its effect on interpretation.

For NCI, preserve the negative mortality finding `s0000,s0009–s0010` together
with generally healthy population `s0006`, observational scope and untested
populations/other outcomes `s0012`. No dementia-prevention or causal-benefit claim
can be inferred from a mortality association. Numerical literals must preserve
denominators, comparators, units and uncertainty, not merely occur somewhere in
the source. The trial itself contains inconsistent female-percentage and
registration strings; those source inconsistencies are logged separately, not
automatically charged as engine hallucinations. No external-truth certification
or independent medical interpretation is claimed.

## H. Focus evaluation

Retain the original eight supported, eight partial and eight near-topic unsupported
queries, and add eight paraphrased supported, eight ambiguous and eight far-topic
unsupported requests, locked before generation. All use Balanced and the same
existing Focus path; guide remains query-blind. The table reports **nonempty
native excerpts / eight requests**, not relevance accuracy.

| Source-query draft type | LIGHT | Guide LSA | Article light | Article enhanced |
|---|---:|---:|---:|---:|
| Supported | 8 | 8 | 8 | 8 |
| Partially supported | 8 | 8 | 8 | 8 |
| Near-topic unsupported | 8 | 8 | 8 | 8 |
| Paraphrased supported | 8 | 8 | 8 | 8 |
| Ambiguous | 8 | 8 | 8 | 8 |
| Far-topic unsupported | 8 | 8 | 6 | 6 |

Each article mode explicitly rejects the two far-topic probes on NCI and NASA;
none of the original supported or new paraphrased queries is explicitly rejected.
That is **not proof of a correct answer**. All eight near-topic and six far-topic
requests per article mode have unsupported-response flags under **agent-draft
source support labels**, not independently verified false-positive percentages.
Partial support is not explicitly resolved by a nonempty supported outcome;
ambiguous queries have no forced binary gold label.

Examples with exact full outputs/source neighbors in
[focus-output-audit.json](final-validation/focus-output-audit.json) and
[focus-sentence-audit.json](final-validation/focus-sentence-audit.json):

- NCI `multivitamin prevention of dementia` returns mortality/population evidence,
  not a dementia estimate; business `energy company profit margins` returns energy
  volumes, not earnings. Technical `GPU performance benchmarks` returns
  CPU/interpreter material. A hospitalization background mention in the walking
  paper is not an intervention effect on admissions.
- Far-topic `penguin migration routes` on USGS returns tectonic/reactivation and
  shaking reports; `coral spawning dates` on EIA returns imports/exports and energy
  consumption; `orchid watering frequency` on PEP returns interpreter/GIL material.
  `ocean salinity forecasts` on the trial returns frailty outcomes. Both modes
  mark these nonempty responses `semantic_wordnet_supported`.
- Paraphrases such as “Did the daily supplements help people live longer?” and
  “What are the odds of another strong tremor?” return excerpts without explicit
  rejection, but directly answering the relation and preserving scope remain
  separate review questions.

The frozen gate checks matches to source vocabulary via stems/WordNet expansions,
accepts sufficient query-term coverage, then retrieves permitted lexical/latent
evidence. It does not independently verify that the requested relation exists.
This source inspection is consistent with these failures, **not a causal ablation
or justification for a new heuristic**. Similarity/status is not correctness.

Every selected Focus sentence in original native, original registered-selected
and new native views has a review row. Agent draft roles identify 411
direct-evidence candidates, 423 possible context sentences (necessity unreviewed)
and 756 unrelated-to-request flags under absent-topic drafts. The remaining
739 are explicitly unresolved (168 partial/nonverbatim alignment, 315 semantic,
14 fragment, 242 ambiguous-scope). These are **not human role judgments**.
Separate fields cover preceding/following dependency and misleading isolation;
all human determinations remain pending. Reviewers must classify D/C/U, identify
which answer needs any C sentence, and cite source evidence. Where qualification
or meaning is unresolved, this report does not pretend a completed determination.

## I. Context and coherence analysis

Exact spans can be fragments or depend on omitted neighbors. Concrete review
addresses include:

- Editorial `s0043` continues an attributed clause in `s0042`; light Comprehensive
  includes the continuation without that subject. `s0053` announces two questions,
  but `s0054–s0055` contain their substance and are absent from that output.
- PEP `s0088–s0089` split the allocator-hook parenthesis/`tracemalloc` example;
  `s0144–s0145` split an extension-library phrase. Source fragments such as these
  appear in light Comprehensive. API flag `s0104` supplies context for conditions
  in `s0105,s0109`; enumerated conditions are not standalone conclusions.
- Research `s0167–s0168` and `s0186–s0187` split author abbreviations from their
  propositions; `s0180–s0181` divide the COM-B attribution from the claim.
  Co-intervention limits `s0220–s0221` also span an example boundary.
- NCI's population/uncertainty and the trial's missing-data sensitivity limits
  show why a coherent positive sentence can still invite misleading isolation.

The exhaustive packet includes selected text, complete summary, source neighbors,
manual dependency hypotheses and tentative anaphor flags. A mapped missing
neighbor is a review prompt: required meaning may occur elsewhere, and not every
leading “This” is unresolved. Conversely, passing the map is not a coherence
certificate. Independent discourse coherence, readability, semantic redundancy,
causal/contrast relations and acceptable isolation are **unrated**. Original
exact-duplicate counts are zero; this says nothing about repeated meaning.

## J. Memory and execution performance

### Preserved enforced-resource evidence

The authoritative [resource ledger](independent-validation/resource-summary.json)
distinguishes completed runs from aborted harness attempts and engine fallbacks.
The older generic `resource-results.json` is not an all-success final matrix.
Completed tests enforce aggregate Docker cgroup memory, no swap, one CPU, a
180-second Python deadline and 185-second outer HTTP read deadline.

| Existing warm-supervisor matrix | Peak MiB | Peak headroom MiB | Cold / warm article s | First / warm Focus s | PDF s | Successes / requests |
|---|---:|---:|---:|---:|---:|---:|
| Light /512 MiB | 444.48 | 67.52 | 2.144 / .144 | 1.325 / .485 | .234 | 6/7 |
| Enhanced /512 MiB | 431.56 | 80.44 | 3.555 / .863 | 1.909 / 1.097 | .829 | 6/7 |
| Light /1024 MiB | 275.50 | 748.50 | 2.026 / .153 | 1.652 / .511 | .236 | 6/7 |
| Enhanced /1024 MiB | 373.13 | 650.87 | 3.092 / .825 | 1.968 / 1.508 | .987 | 6/7 |

All five serial requests succeed per matrix. One of two overlapping requests
is actually rejected as `WORKER_UNAVAILABLE` in each warm-supervisor matrix;
that is a capacity limit, not successful two-request admission. No observed
cgroup OOM or timeout occurs in these completed matrices. Cold requests include
lazy model/import startup; readiness and request latency are separate.

At 512 MiB, CLI/subprocess matrices complete 7/7 each, peaking **396.06 MiB light**
and **491.43 MiB enhanced**. Only four requests per matrix use Article 2.1; three
EIA requests are uncertain-detector legacy fallback. Enhanced CLI leaves only
**20.57 MiB** observed peak headroom. CLI admits both overlapping requests in
this small matrix, unlike the single-worker supervisor; no sustained-load SLO or
safe general concurrency level follows.

The known **162-page / 6,828,558-byte academic PDF** completes through extraction
and legacy fallback in **21.25/37.83 seconds**, at **300.12/387.33 MiB** light/enhanced.
Its 1,365-sentence detection inventory exceeds Article's 1,000-candidate limit;
the typed failure falls back. This is **not successful large-document Article
summarization** or fresh quality data. Its hash and route evidence remain in the
old ledger; the copyrighted guide is not redistributed in this corpus.

Initial startup/mount/argument attempts failed; the 1 GiB CLI matrix remained
incomplete. Earlier overlapping Windows quality/Docker work hit paging-capacity
failure and an import MemoryError; legacy exited `0xC0000005` after 47 saved cases.
The precise access-violation cause is unproved. Original saved cases and incidents
are retained, remaining cases were resumed serially. These are not relabeled as
cgroup OOM or an uninterrupted all-pass experiment.

### New PHP/Python contribution measurements

Four additional isolated 512 MiB runs each make five serial real-article/Focus/PDF
requests and sample process maps for 30 seconds. All **20/20 HTTP requests**
return Article 2.1 successfully; no observed OOM or timeout. The first
default-permission runs cannot read seven process maps at the largest sample.
Their incomplete process attribution is preserved, **not treated as full PHP
memory**. A separate follow-up adds evaluation-container `SYS_PTRACE` permission
only to read those maps; it changes neither engine nor production security.

| New profile /512 MiB | Kernel cgroup peak MiB | Peak headroom MiB | Cold / warm article s | First / warm Focus s | PDF s |
|---|---:|---:|---:|---:|---:|
| Light, default permissions | 437.41 | 74.59 | 5.857 / .305 | 1.661 / .562 | .292 |
| Enhanced, default permissions | 427.43 | 84.57 | 5.047 / 1.022 | 3.284 / 1.850 | 1.245 |
| Light, readable maps | 274.74 | 237.26 | 2.104 / .155 | 1.371 / .424 | .302 |
| Enhanced, readable maps | 370.51 | 141.49 | 3.587 / .881 | 2.079 / 1.111 | .960 |

At the **largest sampled cgroup-current instant** in the readable-map runs:

| Sampled process-map measure, MiB | Light | Enhanced |
|---|---:|---:|
| Cgroup current (separate charged-memory measure) | 274.73 | 370.50 |
| Apache + mod_php PSS, eight processes | 36.89 | 37.22 |
| Supervisor + Python/NLP PSS, three processes | 279.66 | 392.00 |
| Observer PSS | 9.34 | 9.33 |
| Unreadable/exited maps at that sample | 0 | 0 |

Process RSS/private/PSS, hundreds of samples, image digests and cgroup events are
retained in the four `process-profile-*.json` files and
[analysis summary](final-validation/analysis-summary.json). PHP's observed maximum
allocated request-heap peak is **2 MiB light /4 MiB enhanced**, used-heap peak
**1.55/1.91 MiB**. Heap is a subset, not the whole PHP process and not an additive
term alongside Apache PSS. mod_php and Apache share processes; separate
whole-process PHP versus Apache attribution cannot honestly be supplied here.

**Do not add the PSS rows to derive cgroup usage.** RSS counts shared mappings
repeatedly; PSS apportions mapped pages. Cgroup accounting charges a page to a
memory-owning cgroup, which need not match proportional ownership. Shared page
charging, unmapped cache/kernel charges and non-atomic sampling differ; process
PSS can exceed the container's charged total. Kernel peak may occur between map
samples. These are sampled process contributions, not an exact decomposition of
kernel peak. See the [Linux process-map documentation](https://www.kernel.org/doc/html/latest/filesystems/proc.html)
and [cgroup accounting/ownership documentation](https://www.kernel.org/doc/html/latest/admin-guide/cgroup-v2.html).
Cache/host state and observer overhead are not controlled away; the peak ordering
does not establish that enhanced is smaller or that extra map permission saves
memory. No deliberately cache-evicted worst-case reserve is measured.

### Actual deployment limits remain unverified

These are real Linux Apache/PHP → ArticleService → bridge → authenticated
supervisor/Python → extraction/NLP calls, with frozen source read-only mounts.
They bypass the constructor's database work and do **not** exercise the complete
controller/session/job/database/history/authentication path or deployed network.
New endpoints fail closed without sandbox flag/token; no real user/database rows
were written. Owned disposable containers were stopped and removed.

The base image is
`sha256:911ae89cb2304103890bd0b5061990ccff39b05abb42fa0ee4bd5ae96248a007`.
The enhanced evaluation-only image is
`sha256:2861f4cf1edfebbfc015c8f9f384fa23b677b7dce9837b7649fd7578417bfcbd`,
adding spaCy 3.8.11 / model 3.8.0; it is **not** the current production dependency
image. Windows quality uses the existing spaCy 3.8.16 / model 3.8.0 and optional
legacy resources not all installed in Linux. Output/resource equivalence is not
assumed. `render.yaml` declares timeout, not the actual plan's memory/CPU.

Thus 512 MiB/one CPU is an enforced **test envelope**, not verified production
equivalence. Minimum observed enhanced CLI reserve is small; repeat soak, broader
large/scanned/OCR files, real live-site extraction, concurrent full jobs and
deliberate deadline-expiry/recovery trials remain absent. No 512 MB hosting
compatibility, production uptime, p95 latency or timeout-handling certification
is justified by these runs.

## K. Statistical interpretation

The sampling unit is the **article (n=8)**, not 48 depths, 192 native Focus
outputs, 385 unique summaries, 1,220 saved trials or thousands of sentences.
Depths, queries, regenerations and duplicate views within an article are
correlated. Each category has n=1. Cross-depth length matching is registered as
a follow-up before its calculation, but is still **post-pilot sensitivity on
inspected data**, not untouched confirmatory evidence. Increased pair count does
not remove output-pool or matched-subset selection bias.

No meaningful independently verified accuracy percentage, quality effect size,
confidence interval, significance test, inter-rater agreement or human error
rate can be reported from empty human forms. Automated diagnostics are stated
as counts with explicit denominators and limitations, not accuracy estimates.
Future actual review must preserve paired comparable-length outputs, source-first
unit definitions, category/depth strata, raw disagreements and adjudication;
any inference should be registered and clustered by article on an adequately
replicated corpus. The present data support descriptive failure hypotheses only.

## L. Limitations and verification scope

Independent reviewer enrollment, source-unit adjudication, query support labels,
all nine human dimensions and per-sentence semantic judgments are pending.
Context/factual screens and agent role maps are incomplete and may be wrong;
their explicit unresolved fields are not passes. No factual critical-error gate
is cleared. Segmentation-dependent evidence annotations favor source copying;
15/252 native draft unit/depth bundles cannot fit even the native article limits.
The small inspected convenience corpus cannot support general-category claims.
Cross-depth sensitivity improves review coverage but changes depth identity and
still leaves many unmatched outputs. Known extraction/source inconsistencies and
excluded tables are outside an independent truth audit. Deployment scope and
platform/model differences remain material.

Verification of the **new evaluation harness**: 13 tests passed, covering freeze/
pilot immutability, original nine-match reproduction, dual-tokenizer knapsack
against brute force, length-only maximum matching, intact whole-output origins,
200 saved records, 34 exact native repeats, query lock/ambiguity, exhaustive Focus
rows, genuinely blank ratings, agreement/validation fixtures, and enforced
resource evidence. The six preserved pilot-integrity tests were also rerun and
passed (19 integrity tests total). New PHP helper syntax and final flag probes passed. See the
[verification record](final-validation/verification.json) and
[artifact/reproduction index](final-validation/README.md).

This is **not a new full application regression, authenticated browser or live
production test**; historical suite counts are not relabeled as newly executed.
Engine hashes and original artifacts are checked unchanged. Passing these
integrity tests is not the semantic/release gate.

## M. Final release recommendation

**NOT READY. Retain `SUMMARIZER_AUTO_ARTICLE_ROUTING=false`, the legacy default,
existing opt-in mechanism and rollback. Do not enable routing because tests pass.**

Exact remaining gate blockers:

1. **Independent meaning/coverage evidence:** recruit qualified independent
   source-first annotators, adjudicate important units and main meaning, then
   lock blinded semantic ratings. None exists yet. Replicate categories and use
   a genuinely untouched corpus for a confirmatory claim after any future tuning.
2. **Comparable-length validity:** same-depth 39/48 unmatched cases per mode;
   cross-depth sensitivity is not confirmation or UI equivalence. Independently
   assess complete actual-length pairs and report all unmatched cases, rather
   than treating higher copy-proxy hits as superiority.
3. **Factual/context clearance:** independently determine severity of omitted
   uncertainty/sensitivity analyses, attribution/dependency fragments, numerical
   scope, missing late conclusions and contradictory evidence. Exact-source
   membership cannot clear this gate.
4. **Focus correctness:** validate source-query support and sentence roles; resolve
   near-topic and far-topic unsupported-response behavior, partial support and
   isolation concerns. Nonempty supported/paraphrased responses are not validated
   direct answers. Ambiguity needs an explicit review interpretation.
5. **Coherence/readability:** qualify segmentation, dangling clauses and referents
   through independent review; zero exact duplicates is not semantic coherence.
6. **Deployment and regression gate:** verify the actual plan/image/full job path,
   headroom target, worst-case/cold-start resources, concurrency admission/SLO,
   timeout/recovery and end-to-end regressions. Scoped local success plus a
   20.57 MiB observed enhanced CLI reserve is insufficient certification.

**Answer to the release question: not established by the available evidence.**
The methodology investigation and review materials are complete; independent
human validation and production certification are not. Preserve this frozen
failure record before any separately versioned algorithm work. Do not tune on
these now-inspected sources while continuing to describe them as held out.
