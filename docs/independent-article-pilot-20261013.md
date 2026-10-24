# Independent article pilot: legacy vs LIGHT v2

**Decision: v2 is not approved for cutover.** This exploratory run gives no
evidence that v2 is generally better at article summarization. The measured
quality proxies favor the frozen legacy baseline, v2 was substantially shorter
than its matched legacy word-count caps, and no human review was performed.
Keep `SUMMARIZER_ENGINE_VERSION=legacy`.

## Scope and method

- Three real NASA news articles; one publisher; three depth settings each
  (`brief`, `balanced`, `detailed`): nine paired outputs per engine.
- The legacy baseline was loaded from
  `python-engine/evaluation/baseline_snapshot/summarizer_core`; v2 from
  `python-engine/light_engine`.
- Each v2 run received the corresponding legacy output word count as a maximum
  word budget. A maximum is not a fill target, and v2 still substantially
  underfilled it.
- Semantic coverage used the locally provisioned `all-MiniLM-L6-v2` model for
  v2. This is a similarity proxy, not evidence of summary quality.
- Factual screens used lexical alignment against source-extracted candidate
  facts and engineering-curated annotations. They are not human-verified,
  can miss paraphrases or distortions, and repeat facts across the three depth
  outputs.
- LanguageTool was disabled. No blind human ratings, independent references,
  or complete human source-grounded fact checks were collected.
- A user-approved blinded-review gate is now recorded below. This pilot has no
  human ratings, so that gate cannot be evaluated from these outputs.

Sources:

1. [NASA: NASA’s SpaceX CRS-35 Mission to Launch Health Research](https://science.nasa.gov/missions/station/nasas-spacex-crs-35-mission-to-launch-health-research/)
2. [NASA Earth Observatory: Floodwaters Overwhelm Thailand](https://science.nasa.gov/earth/earth-observatory/floodwaters-overwhelm-thailand/)
3. [NASA: Temperatures Rising: NASA Confirms 2024 Warmest Year on Record](https://www.nasa.gov/news-release/temperatures-rising-nasa-confirms-2024-warmest-year-on-record/)

## Exact aggregate results

Means are over successful outputs (nine each). Fact rates in this table are
mean per-output rates; the pooled counts below are the corresponding raw
heuristic status totals. `Legacy` is the frozen baseline; `v2` is the
replacement candidate.

| Metric | Legacy | v2 | Interpretation |
| --- | ---: | ---: | --- |
| Key-unit coverage | 0.5833 | 0.4167 | Only two articles had key-unit annotations |
| Engineering-curated critical-fact preservation | 62.4525% | 41.6149% | Lexical alignment; annotations are not independently verified |
| Source-extracted candidate-fact preservation | 59.4432% | 44.1767% | Heuristic lexical alignment, not complete fact verification |
| Semantic coverage | 0.6910 | 0.6448 | Local embedding proxy |
| Mean output words | 248.1111 | 174.4444 | Matched cap was the legacy output word count |
| Mean absolute word-count difference | \- | 73.6667 | Across nine paired successful outputs |
| Outputs within 10% of legacy word count | \- | 1/9 | Despite v2 receiving the legacy word count as its cap |
| Mean processing seconds | 9.3596 | 6.8300 | Exploratory local timings, not a controlled benchmark |
| Peak memory (MB) | 708.1 | 620.2 | Single-run process readings |
| Fact checks flagged altered/contradicted per output | 1.4444 | 0.0000 | Automated lexical flags; zero is not proof of factuality |
| Worker failures | 0/9 | 0/9 | No execution errors in this pilot |
| Prohibited-phrase hits | 0 | 0 | Heuristic screen |
| LanguageTool grammar issues | Not run | Not run | LanguageTool was disabled |

### Raw fact-audit counts

| Audit set | Legacy: preserved / missing / altered | v2: preserved / missing / altered |
| --- | --- | --- |
| Source-extracted candidate facts (360 repeated checks per engine) | 200 / 147 / 13 | 169 / 191 / 0 |
| Engineering-curated critical facts (42 checks per engine) | 27 / 15 / 0 | 14 / 28 / 0 |

These are repeated output-fact checks, not 360 or 42 independent facts: the
same source facts are checked against each of three depth outputs. In addition,
the fact checker is heuristic and is not a complete semantic entailment audit.

Across rows, v2 beat legacy on key-unit coverage in **0**, lost in **4**, and
tied in **2** comparable cases. On semantic coverage, v2 won **1**, lost **8**,
and tied **0**. The six key-unit comparisons exclude rows without annotations.

## Actual brief-output examples and quality failures

The following are verbatim brief outputs from the run report. They are
illustrative cases, not human-validated judgments. All nine paired outputs,
all per-output scores, errors, and fact-alignment records are in the machine
report linked below.

### CRS-35 health research

**Legacy**

> NASA experiments focused on human and plant health and drug-resistant organisms will travel to the International Space Station aboard SpaceX’s 35th Commercial Resupply Services mission for the agency from Cape Canaveral Space Force Station in Florida, advancing research that benefits life on Earth and future exploration. This research supports astronaut health during long-duration missions through four investigations from the agency’sBiological and Physical Sciences division, including 3D models of the human brain, a crop disease study. Tiny brain models, big questions The experiment sends small, lab-grown models of the human brain to the orbiting laboratory for five weeks.

**v2**

> NASA experiments focused on human and plant health and drug-resistant organisms will travel to the International Space Station aboard SpaceX’s 35th Commercial Resupply Services mission for the agency from Cape Canaveral Space Force Station in Florida, advancing research that benefits life on Earth and future exploration. A precision health investigation returns for its fourth and final planned stay aboard the space station to study how antibiotic-resistant bacteria survive and evolve in microgravity. As part of the investigation, astronauts repeatedly sample surfaces throughout the space station to monitor for drug-resistant organisms.

Both outputs include the mission lead, but each omits important investigations.
The v2 brief output misses four of six engineering key units, including the
four-investigation overview, brain experiment, crop disease study, and rapid
health testing. Its curated-fact rate is 40.00%, compared with legacy at
60.00%; source-extracted fact rates are 31.25% and 43.75%, respectively.
Legacy also contains a malformed join (`agency’sBiological`) and runs a heading
into a sentence, so the baseline is not a perfect result.

### Thailand flooding

**Legacy**

> September falls in the heart of the monsoon season in Thailand, where cities and towns are accustomed to the afternoon rains and thunderstorms that frequently roll through. But throughout late September and early October 2026, persistent heavy rains punctuated by intense downpours left low-lying areas across the country in Southeast Asia under sustained flooding. Prachin Buri province was among the areas where flooding was widespread.

**v2**

> But throughout late September and early October 2026, persistent heavy rains punctuated by intense downpours left low-lying areas across the country in Southeast Asia under sustained flooding. The right image above shows land along the Bang Pakong River and nearby agricultural areas and aquaculture facilities inundated on October 2, 2026. The bright red shapes are floating solar arrays.

The v2 output begins with a context-dependent “But” without the preceding
contrast and refers to “the right image above,” which depends on the original
article layout. Both brief outputs omit key location and impact figures,
including the affected population and agricultural damage. The source-fact
screen reports 20.00% for v2 and 10.00% for legacy on this brief case, but
does not resolve those omissions or verify factual completeness.

### 2024 temperature record

**Legacy**

> Earth’s average surface temperature in 2024 was the warmest on record, according to an analysis led by NASA scientists. Global temperatures in 2024 were 2.30 degrees Fahrenheit (1.28 degrees Celsius) above the agency’s 20th-century baseline (1951-1980), which tops the record set in 2023. Independent analyses by NOAA, Berkeley Earth, the Hadley Centre (part of the United Kingdom’s weather forecasting Met Office) and Copernicus Climate Services in Europe have also concluded that the global surface temperatures for 2024 were the highest since modern record-keeping began. NASA’s full dataset of global surface temperatures, as well as details of how NASA scientists conducted the analysis, are publicly available from GISS.

**v2**

> Earth’s average surface temperature in 2024 was the warmest on record, according to an analysis led by NASA scientists. Global temperatures in 2024 were 2.30 degrees Fahrenheit (1.28 degrees Celsius) above the agency’s 20th-century baseline (1951-1980), which tops the record set in 2023. NASA scientists further estimate Earth in 2024 was about 2.65 degrees Fahrenheit (1.47 degrees Celsius) warmer than the mid-19th century average (1850-1900).

Both summaries retain the headline finding and important temperature figures.
The v2 brief output adds the long-term comparison but omits the independent
analyses and public dataset context retained by legacy. Its measured
curated-fact rate is 21.6216%, compared with 24.3243% for legacy; the source
fact screen produces the same rates in this case.

## Release status and reproduction

- **Quality decision:** not demonstrated; v2 remains opt-in and must not replace
  legacy.
- **Human-review gate:** blocked; no blind ratings or complete independent
  source-grounded checks are available.
- **Corpus gate:** blocked; the three sources are all NASA articles and the
  corpus/annotations are an exploratory engineering convenience sample.
- **Length comparability:** failed; only one v2 result was within 10% of its
  legacy counterpart despite the legacy-derived cap.
- **Execution:** both engines completed all nine cases without worker errors.
- **Agreed human gate:** on blind 1–5 ratings, v2 must be at least 0.2 points
  higher than legacy on overall quality, no more than 0.2 points lower on any
  other rated dimension, and have zero critical unsupported or contradicted
  claims in the reviewed sample. This pilot has no human ratings, so the gate
  is not evaluated.

Reproduce the recorded scoring with the project’s `.venv-nlp` interpreter:

```powershell
.\.venv-nlp\Scripts\python.exe python-engine\evaluation\compare_engines.py score `
  python-engine\evaluation\article-pilot-legacy-run-20261013.json `
  python-engine\evaluation\article-pilot-v2-budget-matched-run-20261013.json `
  --out python-engine\evaluation\article-pilot-report-20261013.json `
  --no-languagetool
```

The JSON report contains all 18 exact outputs and per-output source-fact
alignment results. Source hashes and provenance are recorded there. The two
raw run files and the input corpus are also retained beside it.
