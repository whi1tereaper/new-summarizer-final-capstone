# Summarizer engine architecture audit

Audited 2026-10-05 (Asia/Manila) against the checked-out source. This is a source and test-coverage audit, not a claim that every path is correct in production. The checkout had extensive staged, unstaged, and untracked work before this audit; that work was retained.

## Current request path

For the primary summary path, PHP `LocalPythonBridge` sends a JSON request to `python-engine/local_cli.py`, which invokes `SummarizationPipeline` in `summarizer_core/pipeline.py`. The pipeline resolves text, uploaded PDF/DOCX, or URL input through `extraction.py`; prepares paragraphs using `cleaning.py` and `structure.py`; segments sentences in `tokenization.py`; derives TF-IDF/title and profile/section/position scores in `scoring.py`; optionally ranks request-local chunks in `retrieval.py`; selects by topic/section coverage and redundancy controls in `selection.py`; builds paragraph, key-point, conclusion, bullet, and structured outputs; applies cleanup/profile checks; and maps final sentences back to source candidates/chunks when mapping succeeds. A separate Nutshell path exists in `summarizer_core/nutshell.py`.

## Component assessment

| Area | Assessment | Source-grounded finding |
| --- | --- | --- |
| Ingestion | Implemented; needs environment-specific verification | `extraction.py` handles text, PDF, DOCX, and URL extraction. PDF has an optional OCR path. URL fetching has public-address checks. OCR binaries and real deployments were not verified in this audit. |
| Cleaning and structure | Implemented; heuristic | `cleaning.py` removes common extraction noise; `structure.py` repairs paragraph boundaries, detects known headings, and guesses titles. Unknown structures fall back to `body`. PDF/OCR cleanup can still alter or retain text incorrectly. |
| Sentence segmentation | Implemented; fallback exists | `tokenization.py` selects available tokenizers and `pipeline.py` falls back to a local sentence splitter. Abbreviations, fragments, OCR, and joined words remain documented risks. |
| TF-IDF and candidate ranking | Implemented | `pipeline.py` fits request-local word/bigram TF-IDF. `scoring.py` combines lexical, title, section, position, article-type and profile signals. This is extractive ranking, not claim understanding. |
| Title similarity | Implemented; heuristic | A guessed or supplied title is compared through the fitted vectorizer. Bad title detection can affect the signal. |
| Section importance | Implemented; heading-dependent | Recognized section labels feed section scoring and coverage. Detection is not universal and the confidence of heading classification is not empirically calibrated. |
| Profile scoring and policies | Implemented; partly heuristic | `profiles.py` defines profile weights, preferred sections, preservation settings, and length policy. Some profile completeness checks rely on cue words and cannot prove that absent content exists. |
| Topic/coverage selection | Implemented; lexical clustering | `selection.py` forms deterministic TF-IDF cosine clusters and applies topic/section coverage with MMR-style novelty. It is not semantic clustering unless a local dense embedding model is configured for retrieval. Small or lexically diffuse inputs can yield weak clusters. |
| Embeddings and fallback | Optional; fail-soft in the request path | `embeddings.py` uses a configured local SentenceTransformer directory only; there is no paid remote API. Otherwise it explicitly falls back to request-local TF-IDF. Retrieval exceptions are caught by the pipeline and lexical scoring continues. There is no hard execution timeout inside model loading/inference, so a hung native dependency is not proven safe. |
| Redundancy | Implemented; lexical heuristic | `selection.py` and `scoring.py` reject exact/near duplicates and penalize overlap. This can miss paraphrases or suppress distinct facts with shared wording. |
| Numeric, negation, and qualifier handling | Partially implemented | Scoring/compression protections and profile checks exist. They do not reliably model number-to-claim relationships, scope, causal meaning, or every paraphrase. |
| Fact ledger and factual gate | Basic diagnostics plus optional synthesis gate | `fact_ledger.py` records candidate-level number/unit spans, dates, qualifiers, negations, and comparisons. Optional LLM candidates must cite selected source sentences and pass literal-span, lexical-overlap, sentence, and word-budget checks. Any failed candidate falls back to the extractive output. These checks do not establish semantic entailment or factual accuracy. |
| Evidence provenance | Partially implemented | Retrieval evidence maps output sentences to source candidates/chunks. Matching is heuristic and may fail; the output can still be returned without provenance. This is not a complete evidence graph. |
| Output construction and validation | Implemented; partly duplicative | Pipeline builders create plain, bullet, key-point, conclusion, and structured forms. `_validate_summary_output` cleans and deduplicates multiple output fields after selection, which can make generated representations differ. It does not validate factual entailment. |
| Counts and reading time | Implemented | Word counts and estimated reading times are calculated in the pipeline. Estimates use the project tokenizer/convention, not a measured user reading rate. |
| Length modes | Implemented; mixed budget and coverage policy | `constants.py` defines depth configuration; the pipeline derives a target and word budget; selection uses depth settings for breadth and section coverage. Modes remain heuristics and do not guarantee coverage of every requested information type. |
| Evaluation and persistence | Implemented as a separate research workflow | Python `evaluation/` provides metrics/statistics; PHP `EvaluationService` stores datasets, runs, outputs, human assignments/ratings, and reports using migration 012. Missing metrics retain status/null. Evaluation sources and annotations are separate from production summary persistence. Existing audit documentation records no recruited human judgments or approved references. |
| Tests | Broad automated suites exist; not run for this change | `python-engine/tests/` includes legacy quality/profile tests and architecture, retrieval, text-processing, and evaluation tests. Test existence does not establish real-world quality; this implementation turn did not run the suites. |

## Duplicated, fragile, and unverified areas

- `pipeline.py` contains orchestration plus a large collection of construction, cleanup, profile detection, and validation helpers. Some imports are repeated inside `summarize`; this makes responsibilities harder to isolate.
- Production candidate selection and evaluation fact metrics are separate implementations with different purposes. Evaluation heuristics must not be treated as a production factuality gate.
- TF-IDF clustering is being used as topic approximation. The optional embedding path can improve retrieval relevance only when a compatible local model is installed and configured; the default path remains lexical TF-IDF.
- The prior article-type score was a hand-scaled heuristic exposed as a numeric confidence. That value had no calibrated interpretation. It has been removed from the internal `DocumentProfile` model rather than presented as evidence.
- No independent human quality ratings, approved references, representative held-out corpus, or calibrated factuality measurement are established by this code audit. The separately recorded evaluation audit gives the current dataset/run limitations.
- Real PDF/OCR deployment dependencies, long-document resource limits, model memory behavior, timeout behavior, and production privacy/logging behavior need environment-specific verification.

## Changes made during this audit

Removed the uncalibrated article-type `confidence_score` from `DocumentProfile` and its heuristic construction. Article type remains available with its label and detection signals; no confidence percentage is reported. This does not change sentence candidate scoring or selection.

Added a small, fail-soft production fact-ledger diagnostic. It flags unsupported literal numeric/date/qualifier/negation/comparison spans and possible loss of meaning markers. The result is explicitly labeled heuristic, adds no document text to diagnostics, and does not reject or rewrite summaries. This is a traceability aid, not a factuality claim.

## Evidence-controlled synthesis follow-up

An opt-in OpenAI-compatible Chat Completions stage now runs after extractive ranking, coverage selection, and fact-ledger construction. It receives selected evidence sentences, their section labels, and literal fact markers; it does not receive the full source document. User requests and server configuration must both opt in. Configuration is disabled by default, defaults to a local endpoint, and requires explicit permission for HTTPS remote endpoints. Structured Sections remain extractive.

The model must return sentence text with evidence IDs. Invalid JSON, missing/invalid citations, weak lexical overlap, unsupported literal facts, word-limit failures, endpoint errors, or timeouts retain the existing extractive result. Result metadata records the selected method, model name, evidence citations, and the heuristic verification limit. The result page does not describe these checks as semantic entailment.

The evaluation workflow exposes a separate `hybrid_llm` system. Queued outputs that cannot produce an accepted LLM summary fail as unavailable; evaluation never labels an extractive fallback as an LLM output. Runs retain safe provider/model configuration metadata. See `docs/evidence-controlled-synthesis.md` for operator setup and limits.
