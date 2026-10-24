# Article engine algorithm design

Implementation references are `article_engine/preprocessing.py`, `ranking.py`, `summarizer.py`, and `baseline.py`. The methods below describe executable behavior rather than proposed future algorithms.

## Representation and LSA

Let X be the L2-normalized sentence-by-feature TF-IDF matrix. Features are unigrams and bigrams, with English stopwords excluded from ranking, a 20,000-feature ceiling, smoothed inverse document frequency, and sublinear term frequency. Evidence text itself is never stopword-stripped. If the existing local spaCy model is available, ranking uses lemmas, while noun phrases and entities are retained as annotations.

For each sentence, lexical relevance is the dot product of its row with the document centroid, normalized by the maximum sentence relevance. Truncated SVD produces `X ≈ U_k Σ_k V_k^T`, with `k = min(4, sentence_count-1, feature_count-1)`. LSA relevance is the normalized row norm of `U_k Σ_k`. A fixed random state makes this step repeatable. This is a sparse representation, without a dense all-sentence similarity matrix. [scikit-learn TruncatedSVD documentation](https://scikit-learn.org/stable/modules/generated/sklearn.decomposition.TruncatedSVD) describes this decomposition's use for LSA.

The principal research comparator exactly calls the guide's `LsaSummarizer()` through Sumy. Inspection of installed Sumy 0.11 shows that its default reduction ratio retains all singular components. Thus the appendix comparator and LIGHT's truncated TF-IDF candidate must not be described as mathematically identical LSA implementations.

## Ranking and collective selection

Initial score:

```text
score(i) = .55 lexical(i) + .20 lsa(i)
         + .15 mode_cues(i) + .10 evidence_cues(i)
```

Mode cues come from the existing analysis choice. Evidence cues count the presence of numerical tokens, qualifications/attribution, and optional entities. Add a small results/findings/conclusion/limitations-section bonus, or a smaller first-sentence bonus. The latter is insufficient by itself to dominate the ranking.

Greedy selection subtracts `.22 * maximum_cosine_similarity_to_selected` and adds a `.05` new-section bonus. Near duplicates above `.90` cosine are rejected. This is a small MMR-inspired adaptation, not a claim that the guide implements MMR. The independent research basis is Goldstein and Carbonell (1998), [*Summarization: (1) Using MMR for Diversity-Based Reranking and (2) Evaluating Summaries*](https://aclanthology.org/X98-1025/).

Reference-led sentences require preceding context from the same paragraph. Layout-dependent sentences referring to an absent image are excluded. Selected evidence is returned in source order. Word and sentence budgets depend on existing depth settings. A single complete evidence bundle may exceed a very small word budget; sentences are never cut mid-qualification to satisfy a numerical cap. This behavior is disclosed in the evaluation's realized lengths.

Focus transforms the existing query in the same lexical space (including lemmas when available). Require at least half of its non-stopword unigram terms to exist in the source vocabulary; this conservative support rule prevents one incidental match from answering a largely absent topic. Rank using `.70 normalized_query_similarity + .30 general_score`; filter zero-overlap candidates and retain only required predecessor context. Insufficient vocabulary support produces `focus_status=unsupported` and an insufficient-information result. A lexical match is not proof that the entire question has been answered.

## Pseudocode

```text
extract once into SourceDocument
classify content using calibrated structural and linguistic rules
if routing disabled: execute existing path without classification/extraction changes
if uncertain/nonarticle: execute original engine with the same SourceDocument
otherwise:
    preserve source sentence spans and section context
    annotate using the shared local model when available
    build TF-IDF matrix; compute centroid and truncated LSA scores
    apply mode and evidence signals
    apply existing Focus query, if requested
    select novel context-complete evidence within depth budgets
    restore source order and validate every source offset
    format selected evidence using the existing result contract
on article-component exception:
    invoke original engine once with the extracted SourceDocument
    record actual selected engine and safe failure type
```

No stage paraphrases numbers, negation, dates, names, attribution, or uncertainty. These are preserved in retained sentences but can still be omitted at the selection stage. Entity inventory and source matching support review; neither proves completeness or truth. Heuristic weights are engineering choices undergoing comparison, not experimentally established universal optima.
