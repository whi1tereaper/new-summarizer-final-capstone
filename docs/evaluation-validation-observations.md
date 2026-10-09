# Engineering validation observations

These observations concern the **validation** split of `EVAL-VALIDATION-2026-v1`. The accompanying [actual output records](evaluation-validation-records.json) contain generated summaries, source hashes, algorithm/configuration fingerprints, package versions, measured timers and independent metric statuses. They are actual local execution records, not fabricated example scores or a completed human study. The database experiment and its exported report remain the authoritative records for any later research claim.

The [source fixture](../python-engine/tests/fixtures/evaluation_validation_documents.json) preserves four real, provenance-linked federal-agency excerpts and two clearly identified synthetic stress documents. The corpus is intentionally small and convenient; its category coverage does not establish representative domain coverage. No human references or evaluator ratings were supplied. Reference-dependent metrics are therefore unavailable/not applicable, and no human quality scores or inter-rater results can be reported from this execution.

## Actual execution coverage

All **24 proposed-system outputs** completed: four real documents and two synthetic stress cases, each in four modes. The actual evaluation tokenizer, `unicode-words-v1`, produced the following counts:

| Source | Source words | Brief | Balanced | Detailed | Comprehensive |
| --- | ---: | ---: | ---: | ---: | ---: |
| USGS scientific report abstract | 953 | 109 | 251 | 436 | 700 |
| NASA news release | 765 | 94 | 257 | 535 | 676 |
| NIST short technical introduction | 142 | 36 | 76 | 90 | 123 |
| Census statistical report release | 1,465 | 94 | 286 | 494 | 785 |
| Synthetic repeated paragraphs | 135 | 26 | 44 | 53 | 75 |
| Synthetic OCR/noise | 105 | 18 | 32 | 45 | 67 |

Output length increased across the four modes for each of these six sources. This demonstrates a length difference in these cases, not which mode is best. The output JSON retains measured timings; they include local cache/resource effects and are not a controlled deployment benchmark. The first item can pay initialization costs that subsequent items avoid.

For these same six sources and four modes, a separate compatibility check compared `EvaluationPipeline()` without ablation with the canonical `SummarizationPipeline` using identical request fields. **All 24 plain-summary strings matched exactly.** This establishes parity for the inspected cases; the existing regression suite provides additional coverage.

## Manual source/output inspection

The following are engineering review findings, not blinded Likert ratings. They should be represented in error analysis and investigated on development material. They were not used to tune the held-out test split.

* **USGS abbreviation and compression fragments.** The brief output starts with “In cooperation with the U.S. Army Corps of Engineers.” and contains a sentence ending in an open “(U.S.”. Longer outputs also contain fragments around `N. Dak.` and station identifiers. Relevant numerical material, such as `0.03 milligram per liter`, appears, but sentence completeness is not consistently preserved. Appropriate review categories include sentence fragment, coherence failure and preprocessing issue. A source-consistency screen can still find lexical support for an incomplete sentence; it does not measure grammar.
* **NASA punctuation and spacing.** Outputs contain `Earth’saverage` and `NASA’sfull`, where spaces following possessive apostrophes disappeared. Some selected quotations retain closing quotation marks without matching openings, and shorter modes contain incomplete list text such as “Independent analyses by NOAA, Berkeley Earth.” The source text includes the spaces and complete statements. The actual JSON preserves Unicode correctly; terminal encoding artifacts are not counted as source/output defects.
* **Short NIST source prioritization.** The brief output begins “For example” and omits the source's direct definition of an outlier. It retains two qualified statements about erroneous data and robust techniques. This is evidence of a possible relevance/coherence trade-off for a very short source, not a measured human relevance score.
* **Census content selection and source caveat.** The brief output concentrates on insurance and survey nonresponse instead of covering all three report themes. The balanced and longer outputs include the source's correction notice; the brief output omits it. The fixture itself warns that the historical release's SPM thresholds were subsequently subject to correction. Researchers should decide before final scoring whether such caveats are essential content units. Headings and bullet markers are sometimes incorporated into prose in the comprehensive output.
* **Repeated-paragraph stress case.** Exact repeated paragraphs were not reproduced verbatim as repeated summary paragraphs. The comprehensive output retains the synthetic `12.7%, not 27%` and `did not significantly` wording. Shorter modes omit some facts, illustrating why omission must be separated from alteration.
* **Synthetic OCR case.** Line hyphenation in `demonstra-\ntion` was repaired where that statement was selected, while the injected spelling `confu5e` remained in outputs. The comprehensive output retains the negative claims about retention and mortality. This validates handling of constructed noisy text only; it does not establish PDF/image OCR performance.

## Evaluation defects discovered and addressed

Real-document inspection exposed a critical-fact matching defect: tokens extracted from `20th-century` and `1951-1980` could be marked altered even when the entire source sentence appeared unchanged in the summary. Boundary-aware matching now covers ordinals and numeric ranges, with regression tests that preserve distinctions such as `12`, `120` and `12.7`. The saved validation records were generated after that metric fix. Source-alignment false positives remain possible and require review.

Review also found that PHP whitespace word counts differed from the versioned Python tokenizer. PHP counting now follows the same definition, and output persistence verifies the worker count. This prevents different definitions from silently changing baseline budgets. Statistical input now carries metric implementation versions; reference/model failure statuses preserve the same metric identity; missing paired documents, including documents unavailable in both systems, remain visible. Reports retain their prior values in audit records when regenerated.

## Remaining research work

These records contain proposed-system execution only. They do not establish improvement over baselines, feature contributions or evaluator agreement. Use the separately persisted system/baseline/ablation runs for those comparisons. Collect independent reference summaries, recruit evaluators, freeze a representative test corpus and analysis plan, verify essential units, inspect budget differences, review flagged facts and conduct final experiments before writing defense conclusions.

The observed production fragments, spacing defects, short-source selection weaknesses and retained OCR errors remain documented limitations. Preserving ordinary summarizer behavior was a requirement of the evaluation implementation; these findings should motivate separately tested development work rather than silent changes to the evaluated algorithm.
