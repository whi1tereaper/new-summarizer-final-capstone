# Independent reviewer instructions — pending enrollment

No reviewer has been recruited and no completed human assessment is represented
by this package. Machine/agent draft units, role maps and warnings are **not gold**.
An empty cell means unreviewed, never zero, pass, absence of error, or disagreement.

## Enrollment, independence and blinding

Use two reviewers with demonstrable NLP/linguistics/evaluation experience and
appropriate subject expertise; obtain additional domain advice for the clinical
trial, medical reporting and technical specification. Appoint a separate
adjudicator. Record genuine `reviewer_id`, qualifications, role, conflicts,
prior exposure to sources/outputs, `qualified`, `independent`, and date in
`reviewer-registry.json`. These declarations require real people and evidence;
they must not be auto-filled. Prior engine development or exposure to agent
judgments must be disclosed, not silently called independent.

A coordinator distributes only the source-only packet and blank source/query
forms first. Keep `blinding-key.json`, engine run files, all draft annotations,
warnings, source-role maps and this research report away from reviewers until
their ratings are locked. Masked outputs have stable random tokens and order,
not engine/depth identities. Formatting/style can still leak identity: report
this practical limitation. Do not claim perfect blinding.

## Phase 1: sources and questions, before viewing outputs

Read every complete source in `source-only-packet.json`. Independently write
atomic must-preserve propositions in `human-source-unit-ratings.csv`; add rows
as needed. Cite source sentence IDs plus Unicode `[start,end)` offsets. State
which propositions constitute the central claim; importance (essential,
important, optional); required speaker/attribution; population/time/comparison;
negation and uncertainty; dependencies; conflicting evidence and limitations.
Avoid sentence-copy-only definitions: list valid paraphrase/evidence alternatives.
Distinguish source inconsistency from engine-introduced error. Use source truth
for summary fidelity; any external-truth assessment needs a separate citation.

Complete `human-query-support-ratings.csv` for all 48 unique source/query pairs.
Classify full, partial, absent, or genuinely ambiguous support, citing exact
propositions and unavailable components. Mere shared words, background mentions
or semantic similarity do not establish that the requested relation is answered.
For ambiguity describe plausible readings; do not force an unsupported/supported
binary. Adjudicate source-unit and support disagreements **before** output
review, preserving both originals and decisions in `adjudication-blank.csv`.
Lock and hash the adjudicated annotation version and reviewer exposure log.

## Phase 2: blinded complete summaries

Use `masked-output-packet.json` with the complete source and the independently
locked units, never draft proxy hits. Rate each dimension separately, 0–4;
intermediate 1/3 denote the corresponding degree between anchors below. Use
`N/A` only if a dimension truly does not apply (Focus for general summaries;
attribution if no attributed proposition). Do not count an execution failure or
an unsupported-topic information message as an empty perfect summary. Explain
such outcomes separately; document whether a requested answer is available.

| Dimension | 0 | 2 | 4 |
|---|---|---|---|
| Main idea | Central claim absent or reversed | Recognizable but important central scope/relationship missing | Central meaning and essential scope preserved |
| Information coverage | No essential units conveyed | Some important units conveyed; material gaps | All units essential at this realized length conveyed |
| Factual consistency | Critical contradiction/invention | Noncritical unsupported distortion or material concern | Every stated proposition supported with correct scope |
| Attribution | Materially wrong speaker/source | Ambiguous or incomplete attribution | All necessary speaker/source qualifications preserved |
| Context preservation | Isolation changes essential interpretation | Some population/time/caveat/dependency gaps | Required context and limitations retained |
| Coherence | Incoherent fragments/relations | Understandable but weak referents/transitions | Logical standalone discourse with resolved dependencies |
| Readability | Not intelligible | Readable with awkward/fractured passages | Clear grammatical prose without obstructive artifacts |
| Nonredundancy | Predominantly repeated content | Noticeable avoidable overlap | No avoidable repeated meaning |
| Focus relevance | Does not answer available requested relation, or implies absent answer | Some requested content but unresolved partial support/unrelated material | Direct supported answer plus only necessary context; unavailable parts explicit |

Do not demand all article details at Very Brief length or reward verbosity by
itself. For coverage, identify the preserved/missed units explicitly, with a
length-appropriate essential subset defined from the source, not after seeing
which engine retained it. Preserve the full must-preserve list as well. Compare
paired complete outputs at their actual lengths. A length match alone does not
ensure equal information capacity or coherence.

Record critical factual/context errors separately (`critical_error=yes/no`),
with source and summary spans and a severity rationale. Critical means a reader
could materially misunderstand the central claim, causation, polarity, population,
uncertainty, risk, or result. Flag unsupported causation, lost negation/uncertainty,
wrong attribution, unresolved pronouns, fragments, altered numbers/units,
omitted sensitivity analyses/limitations, contradictions and omitted conclusions.
Do not convert exact source copying or a guard's pass into a factuality rating.

## Phase 3: every Focus sentence

`masked-focus-sentence-packet.json` contains each unique selected sentence with
the whole summary/query. The unmasked audit maps all 2,329 occurrences (native
and original registered-selected views) to 1,560 unique items; no occurrence is
discarded. Complete the sentence form:

- `D`: directly conveys a proposition answering the requested relation.
- `C`: does not itself answer, but is necessary for interpreting a D sentence;
  identify that sentence and explain necessity. Merely on-topic is insufficient.
- `U`: unrelated or unnecessary to answering the request.

Also mark `requires_preceding`, `requires_following`, `misleading_isolation` as
yes/no, with exact source spans and rationale. Assess whether needed material is
already present elsewhere in the summary. An ambiguous query must first be
assigned a documented interpretation, or remain unscored with an ambiguity
rationale; blank is not U. Give partially supported requests explicit missing
components. A nonempty excerpt for an absent answer is a response-policy concern,
not automatically fabricated facts. Both relevance and factuality need judgment.

## Disagreements, agreement and adjudication

Two raters work independently without discussing scores until submission lock.
Keep raw forms immutable, log any correction/version, then compare disagreements.
The adjudicator records reasons/evidence and decisions without overwriting raw
scores. Run `py -3.11 python-engine/evaluation/final_agreement.py` only on actual
submissions. It rejects unregistered/unqualified/nonindependent raters, invalid
scales and duplicate item/dimension ratings. It records paired counts, exact
agreement, unweighted Cohen kappa, and quadratic-weighted kappa for ordinal 0–4
scales; category/dependency labels use unweighted kappa. No paired data means
null agreement, not zero. Constant marginals can make kappa undefined. The
[official metric definition](https://scikit-learn.org/stable/modules/generated/sklearn.metrics.cohen_kappa_score.html)
supports this distinction; the utility uses the equivalent observed/expected
disagreement formula without adding a production dependency.

Report sample size and disagreements per dimension and reviewer pair; do not
average kappa across axes or equate agreement with accuracy. Do not assign
significance from 48 correlated depths, many trials or sentence repetitions.
Primary comparison is paired by article and realized length, with category/depth
stratification; inferential uncertainty requires enough independently reviewed
articles and an explicitly registered clustered analysis. This eight-article
convenience pilot is descriptive. No quality effect is presently estimable.
