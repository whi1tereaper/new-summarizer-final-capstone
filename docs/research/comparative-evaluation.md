# Comparative evaluation protocol

Compare the original LIGHT engine, the guide's Sumy LSA appendix implementation, and the proposed recipe article engine. Use the same extracted input snapshots. Inputs, settings, outputs, source hashes, dependency versions, errors, durations, and process memory observations are retained in [`article-engine-results.json`](article-engine-results.json).

## Samples and separation

Detector calibration uses 11 development fixtures plus four format augmentations. Its 17-example regression subset is stored separately and never enters threshold fitting. Development included a PDF-format correction; earlier benchmark outputs were inspected, so these examples are not an untouched final validation sample.

The summarization comparison contains ten inputs: five existing synthetic articles covering research, news, technical explanation, opinion, and conflicting claims; two archived real NASA article snapshots; and three additional synthetic educational, business, and feature articles. Every source is explicitly labeled in the retained report. The annotated older subset contributes 37 key units and 44 literal fact strings. The three additional synthetic articles have no fabricated human annotations and are excluded from annotation denominators.

These convenience samples do not replace a diverse real-article corpus with independent reviewers. No reference summary, reviewer label, factuality score, or participant rating was invented.

## Length and measurement controls

Legacy runs with the comprehensive depth, eight requested sentences, and a 180-word target parameter. The legacy engine may still choose its own sentence/depth budgets. Its realized word/sentence counts become ceilings for the other systems. Sumy accepts a sentence count but not a word budget; therefore realized lengths differ and are reported. Coverage comparisons must be read with that confound, not treated as a length-controlled superiority test.

Each system executes in a separate Python process, which reuses resources across ten requests. First-case duration includes lazy model loading. Per-case RSS is read from psutil; Windows peak working set is cumulative for that system's process. On platforms without `peak_wset`, the field is a post-case RSS observation, not a measured peak. Process wall duration includes imports and measurement overhead. Concurrent local work affects timing; these are exploratory observations, not deployment performance guarantees.

Automatic screens measure literal key-unit/fact coverage, duplicate-sentence rate, exact source membership, compression, and realized size. They do not establish semantic factuality, coherent meaning, or absence of misleading omissions. ROUGE is not reported because independent reference summaries were unavailable. Semantic similarity is not substituted for human judgments. No significance test is claimed on ten convenience examples.

## Human review still required

Assign independent reviewers blinded outputs at comparable realized lengths. Score factual consistency (25%), main idea (20%), detail coverage (20%), relevance/conciseness (15%), coherence (10%), and readability (10%). Review source evidence for omissions, attribution loss, numbers, negation, uncertainty, and unsupported focus answers. Record participant provenance and inter-reviewer agreement before making a release-quality claim.

## Reproduce

```powershell
.\.venv-nlp\Scripts\python.exe python-engine/evaluation/article_engine_experiment.py --calibrate
.\.venv-nlp\Scripts\python.exe python-engine/evaluation/article_engine_experiment.py --out docs/research/article-engine-results.json
.\.venv-nlp\Scripts\python.exe -m unittest discover -s python-engine/tests -p test_article_routing.py
php scripts/test_article_routing.php all .\.venv-nlp\Scripts\python.exe
```

Calibration writes the runtime calibration artifact. Freeze it before final evaluation; changing rules or fixtures requires a new artifact and external validation. Sumy uses only already-installed local NLTK resources; missing resources fail the comparator explicitly rather than being downloaded silently. The new engine's tokenizer and annotation fallbacks are tested separately.
