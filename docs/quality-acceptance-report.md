# LIGHT main-finding acceptance report

**Verification status:** Core regressions and integration checks pass. Broad
linguistic acceptance remains partial; this report does not claim universal
summary quality.

## Acceptance matrix

| Area | Status | Evidence and boundary |
|---|---|---|
| Census failure reproduced and cause traced | PASS | The source lead survived extraction, but its abbreviated dateline and weak result/purpose cues hurt ranking; selection could also displace protected evidence. |
| Generalized finding selection | PARTIALLY VERIFIED | Census, USGS, and NIST regressions pass; brief source spot-checks retain a primary finding for four public sources. This is not broad-domain proof. |
| Qualification and fact safeguards | PARTIALLY VERIFIED | Census significance/timeframe, the research causal limitation, and source-conditional NIST guidance survive targeted regressions. Automated corpus indicators remain mixed. |
| Modes, formats, depths — configuration contract | PASS | 240 mode × format × depth combinations passed; 248 contract tests total. |
| Real linguistic quality over all settings | PARTIALLY VERIFIED | Representative outputs were sampled, not all 240 combinations; no blinded human evaluation was conducted. |
| Python regression suite and strict malformed-PDF behavior | PASS | 580 tests passed; malformed-PDF rejection remains strict. |
| Warm performance and persistent worker | PASS | Repeat identical requests completed in 0.36–4.43 seconds across the three benchmark sizes. |
| Cold performance stability | PARTIALLY VERIFIED | Cold runs varied materially across repeats; see measurements below. |
| Apache submission, saved result, reload, Copy and TXT export | PASS | Clean-source Census upload at `localhost:8080`, result 565; reload retrieved saved summary; Copy and export succeeded. |
| Authenticated History retrieval | NOT TESTED | Anonymous History redirected to registration; no authenticated session was tested. |
| Independent public legal/policy document | NOT TESTED | The available eCFR access was blocked by CAPTCHA; Legal mode was exercised only on a synthetic policy fixture. |
| LIGHT UI identity | PASS | No frontend files were changed in this acceptance pass. Existing result page and themes were retained. |

## Census reproduction and source comparison

The original error was a selection failure, not a missing headline fact. The
source text contained the real-income finding. Sentence reconstruction split
the abbreviated month/date boundary, reducing the lead's integrity and score.
At the same time, generic “accuracy” and “important to examine” language made
the response-rate caveat look like a result or document purpose. The trace
ranked that secondary caveat above the damaged lead (1.0033 vs. 0.7795), and a
later depth-coverage replacement could evict a high-priority finding. The
failure therefore involved dateline reconstruction, claim classification and
ranking, and final evidence replacement—not a rule to favor every number or
the first sentence.

The frozen comparison fixture included an injected, unrelated `DATA USER NOTE`
about BLS thresholds dated July 17, 2026. That note is not part of the Census
release; it contaminated the baseline challenge and is disclosed here rather
than represented as source content. Both summaries below are the recorded
General / Paragraph / Brief comparison for that fixture:

**Before (frozen baseline):**

> On July 17, 2026, the Bureau of Labor Statistics re-released SPM thresholds
> for 2019-2024 following the discovery of coding errors. An estimated 26.4
> million or 8.0% of people did not have health insurance at any point during
> 2023, according to the 2024 Current Population Survey Annual Social and
> Economic Supplement (CPS ASEC), also not statistically different from the
> previous year. Since the response rates remain below pre-pandemic levels,
> which were regularly above 80%, it is important to examine how respondents
> differ from nonrespondents, as this difference could affect the accuracy of
> the estimates. For more details on how sample difference and the associated
> nonresponse bias impact income and official poverty estimates, refer to the
> Research Matters blog.

**After (current engine, same comparison fixture):**

> SEPT. 10, 2024 — The U.S. Census Bureau today announced that real median
> household income increased by 4.0% between 2022 and 2023. This is the first
> statistically significant annual increase in real median household income
> since 2019. Between 2022 and 2023, the official poverty rate decreased for
> White and non-Hispanic White individuals; women; 18- to 64-year-olds;
> unrelated individuals; all workers; less than full-time, year-round workers.
> For more details on how sample difference and the associated nonresponse
> bias impact income and official poverty estimates, refer to the Research
> Matters blog.

The current summary restores the headline, 2022–2023 measurement period, and
the “first statistically significant … since 2019” qualification. The
response-rate caveat no longer displaces the result in Brief; it enters the
sampled Census summaries at Short and longer depths. The Brief still includes
a long demographic detail and a blog reference, so its concision is imperfect.

Separately, a clean 1,423-word Census DOCX was submitted through Apache and
produced saved result **565**. Its displayed Brief retained the 4.0% increase,
2022–2023 period, and since-2019 significance qualification without the
response-rate caveat replacing the headline. The browser rendering preserved
the dateline punctuation. This clean upload corroborates the fix without the
injected note; the baseline was not rerun against that clean upload.

## Independent source checks

The automated comparison covered six documents × three depths (18
baseline/current output pairs): four public excerpts—USGS water-quality
research, a NASA climate release, NIST outlier guidance, and the Census
release—and two synthetic documents. These are engineering validation inputs,
not a representative or blinded corpus.

| Source | Brief finding check | Qualification / remaining concern |
|---|---|---|
| Census release | Preserves the 4.0% real-income increase and period. | Preserves statistical significance and since-2019 context; demographic detail and blog reference remain in Brief. |
| USGS water-quality report | Preserves the tributary-versus-Red River major-ion comparison. | Targeted regression confirms it does not displace the research example's noncausal limitation. |
| NASA climate release | Retains the 2024 warmest-on-record finding. | Prior qualification/date-range regressions remain; this is not an exhaustive NASA source audit. |
| NIST outlier guidance | Technical Brief now retains the nominal definition and conditional action guidance. | “May not” epistemic cautions are no longer mislabeled legal prohibitions. |
| Research fixture | Targeted summary retains the reported 4.1-point association and 95% CI. | Explicitly retains that cross-sectional evidence cannot establish causality. |
| Legal/policy | Synthetic policy cases exercised Legal mode. | No independent public policy source was verified; public legal-policy coverage remains unproven. |

The four public-source Brief outputs retained their manually spot-checked
central finding (4/4 in this small sample). This is a source review, not a
human-rated dataset score. Detailed policies and late-positioned findings were
also exercised in engineering-authored fixtures; their performance should not
be generalized to unseen legal sources.

## Quantitative quality signals

The final automated comparison reports the following aggregate values across
18 output pairs:

| Automated measure | Frozen baseline | Current | Interpretation |
|---|---:|---:|---|
| Mean source-fact preservation proxy | 40.04% | 43.51% | Mean-per-output proxy increased. |
| Pooled source facts preserved | 514 / 1,320 (38.94%) | 510 / 1,320 (38.64%) | Slight pooled regression; proxy alignment is heuristic. |
| Altered/contradicted flags per output | 2.89 | 2.50 | Improved heuristic count; not adjudicated factuality. |
| Consistency-warning sentences per output | 0.22 | 0.33 | Regressed; requires review. |
| LanguageTool issues per output | 3.17 | 1.72 | Improved automated diagnostics; nonzero issues remain. |
| Model semantic-coverage proxy | 0.6261 | 0.6298 | Small increase; not human semantic evaluation. |
| Mean output words | 186.3 | 178.4 | Current outputs are shorter on average. |
| Generation failures | 0 | 0 | No fixture output failed. |

No human-verified key-unit set was available; numeric, semantic, grammar,
relevance, and qualification scores are automated or limited source spot-checks.
The mixed fact and consistency measures prevent a blanket claim of improved
factuality. No invented human ratings are reported.

## Settings, tests, runtime, and application

- All eight Analysis Modes, five Output Formats, and six Summary Depths passed
  the configuration-contract suite: **240 combinations; 248 tests total**.
- Representative generated-output sampling covered all eight modes (8/8
  sample markers), all five formats (5/5 requested structures), and all six
  Census depths (6/6 retained headline and significance context). This is
  sampling, not full quality evaluation for all combinations.
- Full Python suite: **580 passed, 0 failed**. The malformed-PDF cases continue
  to reject malformed input; validation was not weakened.
- PHP Results-page integration suite: **39 passed, 0 failed**. The supervised
  worker reloaded and passed self-test. Apache at `localhost:8080` processed
  the clean Census upload; result 565 remained available after page reload.
  Copy announced success and TXT export contained the summary.
- Guest History was not an authenticated-history test: the anonymous route
  redirected to registration. Do not infer authenticated History correctness
  from the result reload.

Fresh-process cold summarization time varied between repeated runs:

| Input | Candidate sentences | Repeat A cold | Repeat B cold | Repeat B warm identical requests |
|---|---:|---:|---:|---:|
| Short | 4 | 37.43 s | 19.58 s | 0.36–0.43 s |
| Medium | 18 | 55.19 s | 51.65 s | 1.33–1.49 s |
| Long | 57 | 42.13 s | 29.36 s | 4.02–4.43 s |

These runs show useful warm reuse and large cold/warm differences, but also
material cold-run variability—especially for the short and long cases. They
are local measurements, not latency guarantees. The persistent-worker,
reused-model, batched-embedding, and bounded-cache architecture was retained;
these results do not prove cold performance is stable.

## Implementation and UI impact

The selection and analysis work in this verification is in
`python-engine/summarizer_core/document_analysis.py` and
`python-engine/summarizer_core/selection.py`, with regressions in
`python-engine/tests/test_document_analysis.py` and
`python-engine/tests/test_summarizer_quality.py`. Related source-grounded
ranking and structure repairs are in `tokenization.py`, `structure.py`,
`scoring.py`, `pipeline.py`, and `constants.py` under
`python-engine/summarizer_core/`. The report is the only documentation file
updated for this final verification.

No frontend files were changed in this acceptance pass; the LIGHT visual
system, both themes, and existing controls were left intact. Browser checks
confirmed the current result page displayed the clean-source saved summary.

## Limitations and decision

Main-finding prioritization is **verified for the traced Census failure and
targeted NIST/USGS regressions**, and is promising on a small public-source
sample. Overall linguistic acceptance remains **partial**, not complete:

- No blinded human quality review was performed.
- The comparison corpus is small, contains synthetic cases, and the Census
  baseline fixture included an unrelated injected note.
- The pooled lexical fact-preservation proxy decreased slightly, and
  consistency warnings increased.
- An independent public legal/policy source and authenticated History path
  were not tested.
- Representative settings samples do not establish output quality for every
  one of the 240 configurations.
- Cold performance has high run-to-run variation.

These results support the specific fixes and integration checks above; they do
not establish universal information coverage, factual accuracy, or linguistic
quality.
