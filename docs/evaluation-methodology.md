# Reproducible summarizer evaluation protocol

Protocol version: **1.0**, recorded **2026-10-02** (Asia/Manila). Statistical implementation: `evaluation-statistics/1.0.0`. This protocol defines measurements and analysis decisions; it contains **no research results**. Generated scores, human assessments and conclusions must come from identified evaluation runs. Software validation fixtures are not a completed capstone experiment.

## Architecture and scope

The application remains PHP with its existing authenticated sessions, database and presentation conventions. Evaluation management requires a signed-in, active administrator account; the separate administrator challenge was removed at the user's request on 2026-10-02. Ordinary evaluators retain access only to their assigned assessments. Evaluation uses separate dataset/run/output/metric/assignment/rating records. The offline worker invokes `python-engine/evaluation_cli.py`, which reuses `summarizer_core.pipeline.SummarizationPipeline` through an evaluation-only subclass. Normal summaries continue through their existing path. Evaluation cannot redefine ordinary user ratings as independent research assessments.

The queue processes an output at a time, persists individual metric statuses and retains successful outputs when another item fails. Statistical aggregation is an offline operation, persisted for web display and export. Optional transformer inference does not execute in the ordinary summarization request. Database migrations and the deployment commands are documented in the implementation audit.

The PHP bridge retains one Python process per worker so optional model objects can remain cached. On Windows, stdout/stderr use private temporary regular-file streams with bounded reads and deadlines instead of relying on nonblocking process pipes; closing the bridge removes the temporary files. A database worker lock prevents concurrent evaluation workers from loading competing model copies. These controls limit evaluation concurrency, not the application's ordinary summarization requests.

## Dataset construction and independence

Plan approximately **48 independent documents**, stratified across academic/research, news/informational, technical, and reports/general professional material, approximately 12 per category. These are recruitment targets, not software limits or recorded sample sizes. Define eligibility, language, length ranges, source date ranges, document formats and exclusion rules before collecting final results. Retain provenance, category, profile, inclusion date, active status, source snapshot/hash and dataset version for every document.

Use separate `development`, `validation` and `test` splits. Algorithm changes can use development material; validation provides engineering and protocol feedback. Freeze the final test split before running the final experiment. If a test result leads to a change, report that contamination and collect a new held-out test set. Do not silently relabel an inspected test set as fresh evidence.

A document, rather than a summary output, is the sampling unit. Four modes applied to one source are repeated measurements. Syndicated articles, overlapping excerpts of the same report and near-duplicate revisions are dependent sources: deduplicate them or assign a shared parent-document cluster before analysis. The implemented statistics expect independent document IDs; they do not infer those relationships automatically. A convenience sample supports claims about that sample, not automatically about all domains or languages.

The checked-in `python-engine/tests/fixtures/evaluation_validation_documents.json` contains four real, attributed public-agency text excerpts and two explicitly synthetic repetition/OCR stress cases. They provide engineering coverage of the four categories, short and long text, percentages, dates, decimals, units, qualifiers, negation and multiple named entities. They have no human references or ratings. Synthetic noisy text is not a scanned-document OCR benchmark. The Census fixture retains the agency's correction notice; the experiment measures fidelity to the supplied historical snapshot, not whether every underlying real-world claim remains current. Exclude synthetic stress cases from reports describing real-document quality, or report them as their own run.

## Run and configuration records

Freeze dataset selection, split, modes, systems, references and relevant settings at run creation. Retain generated text, timestamps, measured counts, errors, per-stage timers and algorithm/configuration versions. Never replace an earlier output to make a report look better. Resume the existing run only under its recorded configuration; changed code or references require a new run or an explicitly versioned remeasurement.

The Python configuration fingerprint is SHA-256 over canonical JSON of allowed settings, algorithm/source fingerprints and package versions. It excludes arbitrary environment variables and secrets. Record Git revision when available, code hashes, Python/package versions and the metric/baseline versions. Preserve the actual local model revision/configuration hash for successful semantic metrics. A Git commit alone is insufficient when the working tree differs from that commit.

## Reference summaries and information units

Recruit independent reference writers for a configurable subset, for example 24 documents. Give each writer the original source and the intended summary budget/mode before showing any system output. Record author identifier privately, reference version, creation date and review status. Only accepted human references should enter the final metric worker. Do not use generated output as its own reference or label AI-authored text a human reference.

Where possible obtain references for each mode at comparable length. If one reference is reused across modes, disclose its length and the resulting recall/precision bias. Multi-reference ROUGE/BERTScore use an arithmetic mean of per-reference components in this implementation; retain each component and the reference policy. References define one defensible expression of useful content and do not exhaust all acceptable summaries.

Researchers may also annotate essential information units independently of any output. Store objective, method, principal result, key statistic, conclusion or recommendation as separate units. Candidate literal matches are only review aids. The implementation calculates coverage only when **all units have output-specific human verification**:

`coverage = represented units / expected units`.

Report unverified and missing unit counts. Never describe keyword overlap as verified semantic coverage.

## Independent automatic measurements

| Measurement | Implementation and interpretation |
| --- | --- |
| ROUGE-1 / ROUGE-2 | Clipped unigram/bigram overlap against accepted reference text. Store precision, recall and F1. |
| ROUGE-L | Whole-text longest-common-subsequence precision, recall and F1; not sentence-union ROUGE-Lsum. |
| BERTScore | Optional `bert-score` package; contextual token similarity against references, P/R/F1 retained. |
| Compression | `summary_words / source_words`; reduction percentage is `100 * (1 - ratio)`. Zero source count yields null. |
| Redundancy | Fraction of summary sentences classified as duplicates or near-duplicates of an earlier sentence; normalized exact match or token-set Jaccard at least 0.8. Store both counts. Lower is less repetition under this definition. |
| Critical fact preservation | `100 * preserved / valid critical facts`, only when the denominator is positive. English lexical extraction and sentence alignment, or researcher annotations; missing, altered and suspected contradictions remain separate. |
| Source-summary consistency screen | Fraction of summary sentences without detected lexical warnings. This is a rule-based screen, not an entailment model or a validated probability of factual truth. |
| Processing cost | High-resolution wall timers for preprocessing, summarization, post-processing, metrics, budget calibration and total evaluation. |

ROUGE uses this repository's versioned Unicode word tokenizer, no stemming and no stopword removal. Consequently its values are not numerically interchangeable with implementations using a different tokenizer, stemming or ROUGE-Lsum. ROUGE measures reference overlap; it does not establish source truth, readable prose or overall accuracy. [Lin, 2004](https://aclanthology.org/W04-1013/)

BERTScore uses CPU, batch size one, one Torch thread, no IDF weighting and no baseline rescaling. Dependencies/models are optional and cache-only during evaluation. Scoring refuses silent context-window truncation. Disabled metrics, absent references, missing dependencies/models and overlong inputs produce explicit statuses rather than made-up zeros. Prepare and pin an appropriate model once before the final experiment; changing it creates a different metric configuration. Similar embeddings can still conceal wrong numbers or negation. [Zhang et al., 2020](https://arxiv.org/abs/1904.09675)

Critical-fact extraction identifies numeric/date patterns, candidate capitalized entities, comparisons, negations, qualifiers and causal phrases. Candidate sentence alignment is lexical. A preserved phrase must occur in an aligned summary claim, rather than merely somewhere in the summary. The named-entity candidates are **not** a trained named-entity recognizer. Review flagged claims against their displayed source context. An omitted fact can be an intended consequence of compression; omission is not automatically contradiction. Repeated facts, tokenization and alignment mistakes can affect the denominator. Treat automatically extracted fact rates as heuristic measurements until annotations and judgments are verified.

The source-consistency screen checks alignment, unsupported values/entities, negation mismatch, lost qualifiers and changed causal language. Absence of a warning means only that these implemented checks did not flag a problem. No metric is combined into an overall accuracy percentage.

## Fair baselines and mode analysis

Run proposed, Lead-N, basic TF-IDF and TextRank on the same valid sentence candidates. Lead-N ranks by source order. TF-IDF ranks cosine similarity to the document centroid. The TextRank variant uses a weighted TF-IDF cosine sentence graph with no self-loops, PageRank damping 0.85, uniform handling of dangling nodes, convergence tolerance `1e-8` and a 200-iteration cap. This is a documented TextRank-family implementation, not a claim to reproduce every setting of the original paper. [Mihalcea and Tarau, 2004](https://aclanthology.org/W04-3252/)

Baseline target length is the full proposed system's actual word count for that source and mode, unless an explicit shared budget is supplied. Each baseline chooses the closest ranked whole-sentence prefix and restores source order. It never truncates a sentence mid-claim merely to meet the budget. Record target, actual count and absolute/relative deviation. Review deviations exceeding 15%; whole-sentence granularity can make short sources especially hard to match. Paired p-values do not correct a length imbalance. Report or restrict incomparable pairs using a rule selected before inspecting quality scores.

Analyze Brief, Balanced, Detailed and Comprehensive separately. Larger output may increase recall while decreasing conciseness. Do not nominate a winning mode from ROUGE alone. Baseline preprocessing includes the application's valid-candidate filtering, which must be disclosed when describing the baseline. Baseline timing separates proposed-output budget calibration from baseline ranking; compare end-to-end or ranking-only timings consistently.

## Blind human evaluation

Plan a configurable subset, for example 24 documents × 4 modes × 3 independent evaluators = 288 proposed-system assessments. Including three additional systems multiplies this workload; report the actual system allocation. Select manageable sessions and allow breaks. Explain the rubric with development examples that will not be included in final statistics. Reference authors and evaluators should not rate their own work where feasible; record any overlap as a design limitation.

Server-side assignments randomize summary labels/order reproducibly and keep system/configuration mappings private. Assigned evaluators read source and summary, then submit the seven individual 1–5 scores and optional comments. They must not see metric results before rating. Blinding covers application metadata; recognizable writing style and visible length can still reveal clues and should be disclosed. Submission locks the assessment; approved corrections preserve an audit trail.

| Criterion | Rater's question |
| --- | --- |
| Relevance | Does the selection focus on the source's main purpose and useful content? |
| Factual consistency | Are included claims faithful to the source, including values, entities, negations and qualifiers? |
| Coverage | Does this summary include the essential information expected at the requested length? |
| Coherence | Do ideas follow a comprehensible order with understandable references? |
| Readability / fluency | Are sentences grammatical, complete and easy to understand? |
| Conciseness | Is the chosen content expressed economically for this length mode? |
| Non-redundancy | Does each sentence add information without unnecessary repetition? |

Use the common anchors: **1** unusable/serious weaknesses; **2** major weaknesses; **3** acceptable with noticeable issues; **4** good with minor weaknesses; **5** excellent for that criterion. Score each dimension independently. A fluent false sentence may deserve a high fluency score and low factual-consistency score. Optional overall usefulness never replaces the seven criteria. Display anonymized evaluator codes in research exports and protect identifying account data.

## Statistical analysis

Pre-specify primary outcomes, contrast families, exclusion rules and significance level (default `0.05`) before examining test results. All comparisons generated by the software are descriptive/exploratory unless a frozen analysis plan identifies them as confirmatory. The software does not choose whichever test yields the smallest p-value.

`descriptive_statistics` reports attempted observations, valid observations, missing observations and independent documents. Within each system × mode × split × metric/version, it averages repeated observations within a document, then gives each document equal weight. Mean, median, sample SD, minimum and maximum describe these **document means**. Human-rating frequency counts also preserve the original ordinal distribution. SD of document-averaged ratings is different from raw-rating SD; label the table accordingly.

The 95% interval is a percentile bootstrap of equal-weight document means, with 2,000 resamples and seed `20261002` by default. The count and seed are recorded and configurable. One document produces no CI or sample SD. Whole documents, including their repeated observations, form the resampling units; four modes or three raters do not increase independent N. The interval is conditional on the sampled/fixed evaluators, not a claim about a new evaluator population. Correlated source families require higher-level clustering outside the current document-ID contract. Small samples and convenience sampling limit interpretation. [Field and Welsh, 2007](https://doi.org/10.1111/j.1467-9868.2007.00593.x)

Paired comparisons match document IDs. Within a mode they compare systems; within a system they compare modes. Two-sided Wilcoxon signed-rank requires independent documents and an approximately symmetric distribution of paired differences. Differences are rounded to 12 decimals to prevent floating-point artifacts from breaking ties. Zero differences are excluded from signed ranks. Untied samples up to 50 nonzero pairs use SciPy's exact distribution; ties or larger samples use its seeded sign-permutation route, exhaustive when feasible or 9,999 draws in batches of 128. All-zero differences return a documented no-difference result; insufficient pairs or unavailable dependencies return null inference. Report paired/unpaired and nonzero counts, mean/median difference, document-bootstrap difference CI and matched-pairs rank-biserial effect `(W+ - W-) / (W+ + W-)`. [SciPy Wilcoxon documentation](https://docs.scipy.org/doc/scipy/reference/generated/scipy.stats.wilcoxon.html)

Holm step-down correction controls each reported family: metric/criterion, metric implementation version, split and contrast kind (system or mode). System families include the mode-specific contrasts; mode families include system-specific mode contrasts. Correction does not automatically extend across all different metrics. Record the family and number of available tests, distinguish raw and adjusted p-values, and pre-specify a broader family externally if required by the hypothesis. [Holm, 1979](https://www.ime.usp.br/~abe/lista/pdf4R8xPVzCnX.pdf)

Do not treat ordinal Likert means as interval measurements without discussion. Retain score distributions and medians; document averaging is a pragmatic summary conditional on this rating design. A cross-classified document/rater model, a pre-specified Friedman omnibus test or a sign test for substantially asymmetric differences may be appropriate for a different analysis plan; the framework does not silently fit these or claim they were run. No automatically generated prose claims superiority or equates statistical significance with practical quality.

## Inter-rater reliability

Calculate Krippendorff's **ordinal alpha** separately by criterion and split. The matrix uses summary outputs as items and evaluators as columns. `alpha = 1 - observed disagreement / expected disagreement`. Only items with at least two observed ratings contribute to pairable marginals; missing assignments and unsubmitted assigned slots are counted separately.

For ordinal categories `c < k`, the implemented squared distance is `[sum(n_g, g=c..k) - (n_c+n_k)/2]^2`, using the pairable marginal category frequencies. Squared numeric differences alone would instead be interval alpha. Constant ratings make expected disagreement zero, so reliability is undefined, even if all raters chose the same score. Negative alpha is allowed. Report actual raters/items, pairable items/ratings and missingness; never substitute percent agreement or a blanket pass/fail threshold. Alpha measures reproducibility of judgments, not their validity or summary quality. The implementation is tested against the published missing-data example. [Krippendorff, Computing Alpha-Reliability](https://www.asc.upenn.edu/sites/default/files/2021-03/Computing%20Krippendorff%27s%20Alpha-Reliability.pdf)

## Ablation and controlled challenges

Use a preselected development/validation subset (for example 20 documents), then compare the full system with one genuine mechanism removed at a time. The capability response records the precise switch scope. Available ranking removals concern title, section, sentence position, paragraph position, article relevance and profile base weights. Other switches affect selection redundancy and qualifier/negation/numeric ranking/compression protection. Other coverage, retrieval or validation mechanisms can remain active: a switch is not evidence that all related information has vanished from the algorithm. Record the exact description and output lengths. An ablation's effect can reflect interactions; it is not the independent causal value of a feature in every configuration.

Controlled challenge cases store source, expected fact phrases and prohibited distortions. Include approximately 20–30 independently reviewed cases spanning negation, percentages, decimals, dates, units, entities, qualifiers, comparisons, statistical significance and causal/correlational wording. Synthetic cases are appropriate here when labeled. Store generated output and the exact failed assertions. Phrase assertions support these specific constraints; they cannot prove complete factual correctness or replace real-document evaluation.

## Error review, data quality and limitations

Review source, accepted reference, output, independent metrics and submitted ratings together after blinding is no longer needed. Multiple error categories may apply to one output. Preserve reviewer comments and representative examples, including counterexamples to a favorable aggregate. Percentages need an explicit denominator (for example outputs with a particular error / reviewed outputs); multi-label percentages can sum above 100%.

Exclude failed/empty generation from valid metric N while retaining attempted N. Keep missing references, metric failures, unavailable models and incomplete ratings visible. Missing observations are not zeros. Do not pool different splits, algorithms, metrics or mode budgets. A report regenerated after human corrections must be versioned/auditable.

Populate limitations from actual observations under these headings: extractive selection; scientific/domain language; source length; OCR/preprocessing; tables/equations; ambiguous references; entity/number/negation alignment; semantic metric context limits; convenience sampling; reference subjectivity; evaluator count/coverage; output-budget mismatch. Empty evidence is a readiness gap, not evidence of excellent performance.

## Reproduction and defense checklist

1. Apply evaluation migrations and verify role grants with the existing application administrator.
2. Import a versioned corpus with provenance and explicit split. Review duplicate/source-family dependence and category/length distribution.
3. Register accepted independent references, reviewed essential units and challenge assertions. Pin local optional model dependencies if used.
4. Freeze configuration and analysis plan. Queue the desired system × document × mode matrix and any separate ablation/challenge runs.
5. Run the offline worker with bounded concurrency; retain partial failures and resume only under the same frozen configuration.
6. Assign blinded human tasks, collect independent assessments and audit corrections. Do not populate missing ratings with demonstrations.
7. Generate the offline statistical report, inspect actual denominators, paired overlap and budget deviations, and review errors.
8. Export JSON/CSV and printable HTML alongside provenance/configuration records. Retain source/reference versions and model identity with the manuscript's tables.
9. Write claims naming the metric, split, N and uncertainty. For example: "Mean ROUGE-L F1 was [measured value] on [N] valid test documents under [reference policy]." Replace brackets only from the persisted run. Do not auto-complete a favorable conclusion.

The four-source validation corpus establishes execution coverage only. Defense readiness still requires a representative frozen corpus, independent accepted references, actual evaluator participation, complete experiment execution, review of errors and length fairness, and a manuscript that accurately reflects the resulting evidence.
