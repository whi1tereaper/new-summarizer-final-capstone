# Independent LIGHT summarization engine (opt-in)

The replacement summarizer currently runs beside the legacy engine. It is
selected for new requests by setting `SUMMARIZER_ENGINE_VERSION=v2` in the
local environment. The default remains `legacy`; existing records and routes
are unchanged. To roll back, restore the setting to `legacy`.

## Request and response

PHP sends JSON to `python-engine/light_engine_cli.py summarize`. Requests use
`schema_version: 1` and validate the existing analysis modes, six depth
values, five output formats, title, focus query, and sentence-count limits.
Responses retain the fields consumed by the current persistence and results
services and include the engine version, extraction warnings, summary settings,
and source evidence for each selected sentence.

The worker is local-only. It does not call hosted language models, download
models, or rewrite source sentences. PDF and DOCX extraction is local; PDF
layout and table extraction remain heuristic and can produce warnings or
incomplete reading order. URL fetching and safe redirect validation stay in the existing PHP source
resolver. HTML pages are reduced to article/main content while retaining
headings and paragraphs and removing common navigation, consent, advertisement,
and related-content blocks. Direct PDF URLs are handed to the v2 local
extractor rather than extracted by the legacy worker. Server-owned temporary
downloads are restricted to the configured temporary directory.

## Adaptive selection and safety

One shared pipeline preserves paragraph and recognizable heading boundaries,
then uses local spaCy sentence segmentation when its English model is
installed, with a sentence-boundary fallback. Lightweight classification uses
structure and language cues to identify likely news, research, technical,
business-report, opinion, educational, or general-article content. Low
confidence falls back to a general profile. An explicit non-general Analysis
Mode controls the selection profile without changing the detected type.

Selection combines one scikit-learn TF-IDF relevance score with bounded MMR,
source order, section importance, factual/qualification cues, and adjacency
preference. If an already-provisioned local Sentence Transformers model exists,
semantic relevance and redundancy also inform selection. Model loading is
local-only; when unavailable, the result records that semantic scoring was
disabled and lexical ranking continues. The weights adapt by content profile
instead of branching into separate summarizers. Depth controls sentence and
word budgets. Every returned sentence must be a verbatim source excerpt and is
linked to its source index. This is extractive traceability, not a guarantee
that extraction was complete or that sentence selection retained every
qualification.

Before returning a candidate, the worker revalidates each sentence's source
offset and exact text and rejects mismatches. If local LanguageTool is
provisioned, grammar diagnostics are recorded per selected sentence without
rewriting the source; otherwise the response explicitly marks grammar checking
unavailable. These statuses are persisted in source metadata. Exact-span
validation does not claim semantic entailment or repair source grammar.

Uploaded paths must resolve inside the configured upload directory. Input
size, upload size, PDF page count, setting values, output depth, and requested
word budget are bounded. Requests that ask for language-model synthesis fail
explicitly; the v2 worker does not silently substitute an extractive result
for a requested synthesis.
PHP limits this engine to two simultaneous worker processes and has a finite
wait deadline. The legacy implementation remains the default and has not been
deleted.

## Rollout gate

The first comparison against the frozen, engineering-authored held-out corpus
did not pass the quality gate. Across 27 document/depth cases, v2 scored
0.3470 versus 0.5303 for the frozen baseline on key-unit coverage, 27.6484
versus 46.5726 on lexical fact preservation, and 0.5849 versus 0.6594 on
semantic coverage.

A later exploratory pilot compared three real NASA news articles at three
depths each. It is still a single-publisher convenience sample, not independent
human quality evidence. Legacy/v2 results were: key-unit coverage
0.5833/0.4167; mean engineering-curated fact preservation 62.4525%/41.6149%;
mean source-extracted fact preservation 59.4432%/44.1767%; and semantic
coverage 0.6910/0.6448. V2 averaged 174.4444 words versus legacy at 248.1111;
only one of nine outputs was within 10% of legacy length despite the matched
word-count caps. Neither engine had worker errors. Full metrics, exact
examples, omissions, and the heuristic fact audit are in
[the independent article pilot report](independent-article-pilot-20261013.md)
and its linked JSON output.

These are deterministic proxies, not human evaluation; LanguageTool scoring
was disabled, and no blind human ratings or complete source-grounded human
fact checks are available. The user-approved blind-review gate is: on a 1–5 scale, v2 must be at
least 0.2 points higher than legacy on overall quality, no more than 0.2
points lower on any other rated dimension, and have zero critical unsupported
or contradicted claims in the reviewed sample. The pilot has no human ratings
and cannot be evaluated against this gate. Keep
`SUMMARIZER_ENGINE_VERSION=legacy`. Do not remove the legacy engine or promote
v2 until a diverse article-heavy corpus, real-document extraction checks,
source-verified factual review, and blind human ratings pass the gate.

## Local requirements

The existing `requirements-local.txt` provides scikit-learn, spaCy, pypdf,
and python-docx. `en_core_web_sm` is optional at runtime; without it, the
engine uses spaCy's blank English sentence segmenter if spaCy is installed,
then a regular-expression fallback. Python model provisioning is an
installation step, never an implicit request-time download.
