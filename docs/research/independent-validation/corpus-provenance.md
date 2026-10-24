# External corpus provenance and inclusion log

Retrieved 22 October 2026, after engine freeze and before summary generation.
The exact licensed text, authors, acquisition URL/hash, extraction recipe and
source spans are in [corpus.json](corpus.json); [input-lock.json](input-lock.json)
locks texts, agent draft units and queries before outputs. No independent human
annotation is claimed. Text spans use Python Unicode character offsets, not bytes.

| ID / category | Source, date, authors | Words / sentences | Topic and inclusion rationale |
|---|---|---:|---|
| IV-NEWS / news | [USGS aftershock kits](https://www.usgs.gov/news/state-news-release/usgs-deploys-aftershock-kits-study-whitehouse-station-earthquakes), 11 Apr 2024, USGS Office of Communications | 570 / 22 | Event reporting, attributed expert comments, probabilistic forecast and explicit inability to predict earthquakes |
| IV-SCIENCE / scientific reporting | [NIH/NCI multivitamins](https://www.cancer.gov/news-events/press-releases/2024/multivitamins-do-not-lower-risk-of-death), 26 Jun 2024, National Cancer Institute | 345 / 13 | Negative observational finding, healthy-population scope, future-research limitation |
| IV-RESEARCH / original research | [Walking intensity cluster trial](https://journals.plos.org/plosone/article?id=10.1371/journal.pone.0357605), 30 Sep 2026, Margaret Danilovich, Daniel S. Rubin, David E. Conroy, Laura Diaz, Ying Cheung, Daniel Corcos, Lauren Balmert Bonner | 5,381 / 227 | Actual research article, multiple outcomes, attrition, null sensitivity analyses and late limitations |
| IV-BUSINESS / business analysis | [US energy production, consumption and exports](https://www.eia.gov/TodayinEnergy/detail.php?id=65524), 20 Jun 2025, Brett Marohl | 472 / 21 | Energy-sector economic analysis: record production/trade contrasted with nonrecord consumption; late import/refining context |
| IV-EDUCATION / educational explanation | [Safest and cleanest energy sources](https://ourworldindata.org/safest-sources-of-energy), Hannah Ritchie; page displays 10 Feb 2020, prose notes initial 2017 publication and March 2024 emissions update | 2,535 / 128 | Explanatory article with comparisons, denominator choice, data limitations and a late methodological discussion |
| IV-TECHNICAL / technical explanation | [PEP 684: A Per-Interpreter GIL](https://peps.python.org/pep-0684/), Eric Snow, created 8 Mar 2022; living accepted-PEP snapshot | 4,670 / 240 | Normative technical article, separate isolation/compatibility/thread-safety/API implications and maintainer burden |
| IV-EDITORIAL / editorial/opinion | [Net-zero decision-making editorial](https://www.frontiersin.org/journals/climate/articles/10.3389/fclim.2024.1355110/full), 22 Jan 2024, Mark Workman, Adrian Gault, Katy Roelich, Geoff Darch, Gireesh Shrimali | 1,568 / 58 | Attributed arguments and prescriptions, non-news structure, late institutional recommendations |
| IV-FEATURE / feature | [Record-setting Hektoria retreat](https://science.nasa.gov/earth/earth-observatory/record-setting-retreat-of-hektoria-glacier/), 4 May 2026, Lindsey Doermann | 837 / 40 | Narrative science feature, multiple mechanisms and timescales, quoted interpretation and late slowing-retreat qualification |

Eight publishers/families, eight articles, 16,378 words. Counts use the frozen
article tokenizer; sentence inventory uses its existing segmentation solely for
reproducible evidence addresses, not an independent linguistic gold standard.
Research JATS date concatenation in acquisition metadata is `3092026`; the
correct human-readable publication date is 30 September 2026 as displayed by
PLOS. This metadata clarification does not change the locked model input.

## Licensing and attribution

USGS, NIH/NCI, EIA and NASA prose is agency-authored US federal government
material, public domain in the United States. Agency logos, photographs, graphics
and third-party media are excluded. See [USGS policy](https://www.usgs.gov/information-policies-and-instructions/copyrights-and-credits),
[NIH website policy](https://www.nih.gov/important-links/website-policies),
[EIA reuse policy](https://www.eia.gov/about/copyrights_reuse.php) and
[NASA guidance](https://www.nasa.gov/nasa-brand-center/images-and-media/).
The PLOS and Frontiers articles explicitly permit reuse under Creative Commons
Attribution; the full author credits and original DOI/page links are above.
Our World in Data's article prose is [CC BY](https://ourworldindata.org/faqs#how-is-our-work-copyrighted),
not a blanket license for its third-party datasets. PEP 684's
[Copyright section](https://peps.python.org/pep-0684/#copyright) declares public
domain/CC0. No images, code, tables or third-party datasets are redistributed.
Summaries are engine-generated extracts of these credited source snapshots.

## Acquisition and exclusions

The first acquisition and later attempt records retain selector failures,
intermittent HTTP 503s and provisional outputs. The NIH endpoint returned 403;
the full official NCI release was acquired instead, not a secondary paraphrase.
EIA's malformed HTML required the lxml parser; an earlier parser placed the
whole article in a caption-like paragraph. Final EIA prose was reacquired and
checked, not selected on output quality. USGS related links, NIH institutional
boilerplate, NASA image downloads/credits, Frontiers author statements, and PEP
TOC/references were removed before locking. PLOS uses abstract plus full JATS body,
excluding tables, figure media/captions and bibliography. Headings and prose
lists remain. Scientific tables are outside this prose-only evaluation scope.

All final sources meet the registered 300–8,000-word range; none was substituted
after a summary was inspected. Draft information units were source-first agent
work, locked before summaries. Their difficulty/importance/semantic equivalence
are not independently validated. There are no independent reference summaries.

All previously inspected source identities are excluded through
[prior-data-audit.json](prior-data-audit.json). This includes the nine v2
development articles, six once-reserved synthetic CE-HO articles, twelve v2.1
development articles, ten historical comparison sources, earlier NASA pilot and
test fixtures, six external development nominations, and the uploaded academic
guide. These groups overlap; their counts must not be summed as distinct sources.
The 12-document script named `refinement_calibration.py` actually **tunes** the
core policy and MMR setting. The six CE-HO examples were repeatedly inspected
after their original reservation. Historical and refinement results are not
fresh independent holdouts under a new name/version.

## Representativeness limits

This is a prospective non-tuning convenience **pilot**, not the earlier proposed
30-article confirmatory corpus. Each category has only one article, so category
and publisher effects cannot be separated. Four sources are US agencies; news is
an agency release, business is sector analysis rather than company earnings,
opinion is an academic editorial rather than a general essay, and the technical
source is a normative proposal rather than an empirical systems-results paper.
Energy/climate topics are overrepresented despite distinct events. Living web
pages may change; hashes identify these snapshots only. Source-level accuracy
and completeness of extraction still need independent review. Broader newsroom,
corporate, general-opinion and multi-finding technical sampling remains required.
