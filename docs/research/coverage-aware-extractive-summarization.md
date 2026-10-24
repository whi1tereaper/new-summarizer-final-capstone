# Coverage-aware extractive summarization

## Current v2.1 construction

The current general-summary path first selects a feasible central-claim bundle using development-calibrated title, centroid and claim cues, then fills the remaining budget using the unchanged weighted coverage objective. It does not reward a short numeric sentence over the thesis solely because the numeric sentence costs fewer words. The main seed is source-grounded, may occur late, and consumes its full context cost. Existing Focus retrieval skips this global seed and now adds bounded optional WordNet evidence retrieval and stricter predefined-method scope handling. All six depth names and all UI controls remain unchanged.

`refinement_parameters.json` selects core weights `(lead=0,title=.6,centroid=.2,claim=.2)` and experimental residual MMR `.95`. Coverage-only remains available as an ablation and frozen v2 comparator. The old v2 lambda=1 calibration described below is historical; the v2.1 diagnostic set does not establish a new general MMR benefit. See [the exact added equations](mathematical-formulation.md) and [release decision](article-engine-release-readiness.md).

```text
build exact source spans, lexical/latent representations and bounded concepts
retrieve supported Focus evidence if a Focus was requested
if Focus unsupported: return the explicit informational response with no invented evidence
if general: add the highest calibrated, feasible central-claim context bundle
while a positive-gain context bundle fits: add marginal coverage per word cost
restore source order, validate exact spans and required caveats/corrections, then format
```

An immediate later reanalysis negating an earlier finding must accompany that finding. This remains a local heuristic; distant contradictions and arbitrary attribution chains are not solved. PHP now accepts the narrowly recognized unsupported-Focus response without treating its intentionally empty evidence as a malformed summary; ordinary empty responses still fail quality validation.

The candidate uses a sentence-by-concept representation to reward important information that is not already covered. Each selected sentence remains an exact source span. The implementation is `article_engine/coverage.py`, `selection.py`, `validation.py`, and the existing `summarizer.py` response builder.

## Information units

The universe contains bounded TF-IDF term/bigram concepts, latent LSA themes, article sections, claim-role cues, contextual quantities, recurrent/relevant entities when the shared local NLP model is used, and distinct qualifications/attributions. Each sentence contributes its strongest local lexical features so that a one-off finding is not excluded merely because it is absent from the document's most repeated words. Lexical coverage is capped at 2,000 features; latent coverage uses at most four dimensions.

Quantity concepts require explanatory/claim cues or sufficient document relevance. Citation brackets are excluded. Entity concepts require recurrence or relevance in the lead. These are conservative heuristics, not a claim that every number or entity is important. Qualifications remain tied to their sentence's lexical signature. Original strings, punctuation, negation, dates, units, and attribution are not removed from the evidence representation.

Section and role coverage reward additional methods, findings, consequences, recommendations or caveats where present. No rigid section quota is imposed. Generic headings are recognized independently of detector preprocessing, and wrapped sentences keep their original newline-containing spans. Two- and three-word complete statements are preserved rather than automatically discarded.

## Selection and context

The objective credits each concept only up to its best coverage among selected sentences. Candidate bundles include the sentence and required antecedent/context sentences. A numerical result followed by an immediate statistical or causal qualification carries that qualification with it. Connective-led conclusions may use preceding context across a paragraph boundary. Bundle closure and all its words count against the budget.

Choose the largest marginal information gain per adjusted word cost, recomputing gain after every addition. Stop when no positive feasible gain remains. Restore source order. Exact duplicates add no concept value. Optional residual MMR is suppressed for a bundle that introduces a new protected quantity or qualification, so similarity alone cannot delete a distinct sample size or experimental result. Development compares coverage without MMR against nontrivial lambdas; the final setting is stored in the parameter artifact rather than taken on faith.

The selected development parameters are group masses `{lexical:.25, topic:.15, section:.05, role:.10, quantity:.30, entity:.05, qualification:.10}`, word-cost exponent `p=1`, residual MMR `lambda=1`, and modular `rank_mix=0`. Empty concept groups are removed and remaining weights renormalized. These values are engineering estimates on the small development corpus, not established scientific constants. The existing enhanced-NLP default is retained when its optional model is available.

```text
records := source-exact sentences with paragraph/section identifiers
X, Z := sentence TF-IDF and optional local truncated-SVD coordinates
A, w := normalized nonnegative source-concept coverage and weights
allowed, q := supported Focus evidence, or all sentences if no Focus
if Focus unsupported: return existing insufficient-information response
if Focus supported: condition w on evidence relevance q
bundles := context-closed candidates whose full cost fits the limits
S := empty; covered := zero vector
while a positive-gain feasible bundle exists:
    recompute gain from max(A[S union bundle]) minus covered
    choose gain-per-cost bundle, with optional protected-fact-aware MMR
    add only newly selected evidence; charge every added sentence
restore source order; validate source spans and context dependencies
format the same immutable evidence through the existing response contract
```

The source-ratio sentence cap was removed: a requested word ceiling and sentence-count limit already constrain output. An explicit evaluation word budget now replaces the implicit ratio budget up to the existing depth maximum. This prevents underfilling a fair comparison budget solely because an article contains relatively few sentences. Ordinary website requests still derive their word budgets from the existing six depths. No sentence is cut mid-clause to fit a cap.

## Focus

Existing Focus input is normalized using Porter stems and projected into the same TF-IDF/LSA space. Supported paragraphs can contribute differently worded latent-theme evidence. Existing generic scopes such as `study design`, `methods`, `results`, and `limitations` can retrieve claim-role concepts. Candidate concept weights are conditioned on query relevance; required context can be added even when it does not repeat the query word.

Absent or substantially unsupported queries retain the insufficient-information response. LSA is a local co-occurrence representation, not a general language model; wholly unseen synonyms can still fail. No additional embedding model, external API, or request-time download is introduced. Enhanced POS/NER uses the existing shared spaCy loader; the lightweight configuration avoids that optional model. Both are benchmarked.

## Validation and scope

`validate_selected` rejects changed source spans, changed identifiers, duplicate evidence indices, and omitted required context. Regression cases explicitly cover removal of negation, changed quantities, uncertainty-to-causality upgrades, unique late findings, and similar sentences containing different facts. Exact-span validation remains enabled even in the factual-context ablation.

These checks cannot establish that a quoted claim is true, that all qualifications were recovered, or that every pronoun was resolved. No unsupported connective or rewritten conclusion is generated. The [mathematical formulation](mathematical-formulation.md) specifies the exact scoring; the [ablation report](ablation-study.md) records which costs and components were justified by this experiment.
