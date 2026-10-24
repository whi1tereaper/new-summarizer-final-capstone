# Article-First NLP Summarizer Audit

Audit date: 2026-10-22 (Asia/Manila)

## Final assessment

**PARTIAL PASS — not fully verified for production-quality article summarization.**

The system has a functioning local NLP pipeline, source-grounded extractive
selection, configurable article/document profiles, focus ranking, bounded
length controls, and structured failure handling. It is not justified to claim
consistent article-level quality because the available corpus is below the
requested size, no blind human review was available, and the v2 replacement
engine failed its recorded quality gate against the frozen baseline.

The configured production default remains the legacy engine. The v2 engine
must not be promoted based on the current evidence.

## A. Active architecture and execution path

1. PHP receives pasted text, an article URL, or an upload through the
   summarization controller/service path.
2. URL resolution and article extraction occur in PHP; local PDF/DOCX
   extraction occurs in Python when the selected worker path receives a file.
3. PHP sends a versioned JSON request through `LocalPythonBridge` to the local
   Python worker. Worker errors are mapped to typed processing failures and
   bounded timeouts.
4. The default `summarizer_core` path normalizes and cleans source text,
   segments sentences, detects document structure/content type, calculates
   lexical and profile-based importance, applies redundancy controls, selects
   sentences, and formats the result.
5. The optional `light_engine` v2 path uses TF-IDF, bounded MMR, source order,
   section importance, factual/qualification cues, adjacency, and optional
   local sentence embeddings.
6. Selected sentences are source-validated and persisted with evidence and
   metadata. Neither active local path is a general abstractive paraphraser;
   v2 explicitly returns verbatim source excerpts.

Relevant implementation: `app/src/Services/LocalPythonBridge.php`,
`python-engine/local_cli.py`, `python-engine/summarizer_core/pipeline.py`, and
`python-engine/light_engine/pipeline.py`.

## B. Findings

| Severity | Finding | Evidence / root cause |
|---|---|---|
| High | Article-quality verification is incomplete | Available held-out corpus contains 9 documents; the independent pilot contains 3 NASA news articles at 3 depths. No blind human ratings or complete human source-grounded review are available. |
| High | v2 is not ready for cutover | Existing 27-case comparison scored v2 below the frozen baseline on key-unit coverage, lexical fact preservation, and semantic coverage. The 9-output NASA pilot also favored legacy on key-unit coverage and semantic coverage. |
| High | Legacy cleaning could discard valid article prose | `NAVIGATION_PATTERN` treated any occurrence of words such as `register` as navigation noise, causing a normal 64-word library paragraph to be reduced to no usable content. |
| Medium | Extractive output can be incoherent | Verbatim sentence selection can retain context-dependent starts such as “But” and layout references such as “the right image above”; it cannot rewrite or repair the source meaningfully. |
| Medium | PDF/document extraction is layout-sensitive | PDF reading order is explicitly warned as imperfect for multi-column pages and tables; encrypted PDFs are unsupported. |
| Medium | Semantic scoring is deployment-dependent | Local embeddings are optional and never downloaded at request time; missing models cause lexical-only ranking. |
| Medium | The English path is stronger than multilingual use | Stopword lists, cues, and sentence segmentation are English-oriented. |
| Low | Test environment has a non-NLP concurrency failure | One of 612 tests failed in translation cooldown persistence on Windows because concurrent `os.replace` encountered `WinError 5`; this is outside summarization. |

## C. Article evaluation evidence

The existing independent pilot is not a 30-article evaluation set, but it is
the strongest available article evidence and must be retained as exploratory
evidence rather than presented as proof.

| Metric | Legacy | v2 |
|---|---:|---:|
| Key-unit coverage | 0.5833 | 0.4167 |
| Engineering-curated fact preservation | 62.4525% | 41.6149% |
| Source-extracted fact preservation | 59.4432% | 44.1767% |
| Semantic coverage proxy | 0.6910 | 0.6448 |
| Mean output words | 248.1111 | 174.4444 |
| Mean processing seconds | 9.3596 | 6.8300 |
| Peak memory | 708.1 MB | 620.2 MB |

These are heuristic/proxy metrics, not human factuality scores. The pilot
reported 0/9 worker failures for both engines, but it did not include blind
human ratings, LanguageTool scoring, independent reference summaries, or a
diverse article sample.

The recorded v2 pilot also found that only 1/9 v2 outputs was within 10% of
the matched legacy output length. Example failures included omitted key
investigations in a research/news article and a context-dependent “But” plus
image-layout reference in a flood article.

## D. Factual consistency and intelligence assessment

Positive controls:

- Selected source spans are validated against the source.
- The local v2 path records sentence-level evidence and does not invent
  paraphrases.
- Negation, uncertainty, and attribution cues influence selection, but these
  are ranking cues rather than semantic entailment checks.

Remaining risk:

- Exact source matching proves provenance, not that the selected sentence is
  sufficient, well-contextualized, or correctly interpreted.
- Extractive selection can omit the qualification or attribution that makes a
  claim safe.
- High lexical similarity can still select repeated, peripheral, or visually
  dependent sentences.
- Opinion and quotation handling is not equivalent to a verified fact check.

## E. Input, focus, depth, and reliability limits

- Supported uploads are PDF and DOCX; pasted text and resolved URLs use the
  existing PHP intake path.
- The local v2 contract caps text at 2,000,000 characters, uploads at 30 MB,
  PDFs at 1,000 pages, and requested sentence count at 50.
- Depth settings use bounded sentence/word budgets; longer settings do not
  guarantee proportional coverage of all article sections.
- Focus ranking uses lexical similarity with optional semantic similarity and
  limited neighboring context. Unsupported focus topics are not guaranteed to
  produce a clear “not supported” result.
- The full suite exercises fallback and error paths, but production-quality
  equivalence across pasted text, URL, PDF, and DOCX was not established for
  the same article set.

## F. Changes implemented in this audit

The confirmed legacy cleaning defect was fixed in
`python-engine/summarizer_core/cleaning.py`.

Navigation noise is now removed only for short UI-like blocks or short blocks
containing multiple navigation labels. A single legitimate word such as
“register” no longer discards a long article paragraph.

Two regression tests were added to
`python-engine/tests/test_text_processing_architecture.py`:

- legitimate article prose containing “register” is preserved;
- a short “Home About Us Contact Us” navigation block is removed.

No external generative AI API or frontend redesign was introduced.

## G. Before/after verification

Before the fix, the recorded synthetic probe showed the ordinary library
paragraph produced zero cleaned paragraphs when it contained “register”; the
same paragraph without that word produced one cleaned paragraph.

After the fix, both regression tests pass in `.venv-nlp` and the paragraph is
preserved while the short navigation block is still rejected.

## H. Test results

- Targeted cleaning regressions: **2 passed**.
- Full unittest discovery: **612 tests run; 607 passed, 4 skipped, 1 failed**.
- The single failure was the unrelated Windows translation cooldown race:
  `PermissionError: [WinError 5]` during concurrent `os.replace`.
- The first attempted pytest environment was not usable because its compiled
  scientific packages were incompatible/missing; the project NLP environment
  `.venv-nlp` was used for the successful unittest run.

## I. Required next gates

1. Build a provenance-tracked, held-out corpus of at least 30 real articles
   across the requested categories. Do not fabricate articles or reference
   summaries.
2. Have independent reviewers annotate main idea, must-preserve facts,
   qualifiers, attribution, omissions, and misleading claims.
3. Run the same articles through pasted text, URL, PDF, and DOCX where each
   format is available, and compare extraction drift.
4. Add blind human ratings using the defined weighted rubric before considering
   v2 promotion.
5. Repair or explicitly document context-dependent sentence handling and
   improve focus-support reporting.
6. Resolve the separate Windows translation cooldown race and rerun the full
   suite.

Source evidence includes `docs/light-engine-v2.md`,
`docs/independent-article-pilot-20261013.md`,
`docs/system-audit/PYTHON_ENGINE_AUDIT.md`, and
`docs/system-audit/SECURITY_AND_RELIABILITY_AUDIT.md`.
