# Recipe-based article NLP engine

The additive engine lives in `python-engine/article_engine/`; the original LIGHT engine remains in `summarizer_core/`. The same website inputs, Focus control, output modes, results, and persistence path consume the existing worker contract. No new frontend or database schema was introduced.

## Primary reference reviewed

Bulaclac, Jodell R. (n.d.). *Natural Language Processing: A Recipe-Based Academic Guide for BSDS Students*. User-supplied NEUST academic guide, 162 PDF pages. Local file: `Natural-Language-Processing-A-recipe-based-Academic-Guide.pdf`. SHA-256: `c2e33f097bc6db17ba6ab730b881c50fb73a047015dee8011c1f21f3bb33937a`.

The actual local PDF was read. Relevant sections: PDF pp. 10–14 (PDF/Word extraction), 30–53 (normalization, tokenization, stemming/lemmatization), 63–66 (TF-IDF and Word2Vec), 78–86 (noun phrases, cosine similarity, POS, entities, topic modeling), and especially **159–162**, the General Research Summarizer appendix. Printed page numbers are one lower than PDF viewer page numbers. No publication date is inferred.

| Guide method | Actual implementation/adaptation |
|---|---|
| Chapter 1, PDF/DOCX extraction | Existing PHP source resolver and `summarizer_core.extraction` retained; router reuses one `SourceDocument`. |
| Chapter 2, tokenization | Local NLTK Punkt spans; existing abbreviation-aware fallback when local resources are missing. |
| Chapter 2, normalization | Ranking-only lemmas; evidence retains source casing, punctuation, numbers, dates, and qualifications. |
| Recipe 3-3, TF-IDF | Sentence unigram/bigram matrix and document-centroid relevance. |
| Recipes 4-1, 4-3 | Optional shared spaCy POS, lemmas, and noun chunks, replacing an additional TextBlob loader. |
| Recipe 4-2, cosine | Sentence/query relevance and redundancy penalty. |
| Recipe 4-4, NER | Shared local spaCy entity annotations and a small evidence bonus. |
| Appendix, Sumy LSA | Faithful comparator in `article_engine/baseline.py`; production candidate uses sparse truncated SVD. |

The guide's global deletion of strings such as `Volume`, `Issue`, and `Page` is reproduced only in the comparison baseline. Applying those substitutions to live evidence could damage meaningful article text. The proposed engine removes only isolated boilerplate labels, preserves headings as context, and returns exact source spans.

Word2Vec training and LDA corpus modeling were reviewed but are not added to each article request. The current experiment does not justify their training cost or demonstrate a benefit. No external generative API, request-time resource download, or new runtime dependency was added. Sumy is already in local requirements and is needed only for the guide comparator; the Render runtime already includes the required NLTK/SciPy/scikit-learn libraries.

## Module responsibilities

- `preprocessing.py`: sentence records, offsets, paragraph/section identifiers, narrow boilerplate handling.
- `baseline.py`: appendix comparator using Sumy 0.11 LSA.
- `ranking.py`: optional existing spaCy annotations, TF-IDF, truncated LSA, focus, redundancy, and context bundles.
- `summarizer.py`: budgets, exact-span validation, output formats, evidence, and existing response fields.
- `article_detector.py` and `summary_engine_router.py`: detection, automatic selection, failure isolation, provenance.

The central topic is represented by full-article TF-IDF keywords plus optional noun phrases. It is not a generated explanation. The algorithm uses entities and qualification cues as ranking evidence; it does not guarantee that every relevant fact or caveat survives selection. Source provenance is not semantic entailment, and the candidate has **not** demonstrated better quality than legacy.

All six existing depths, eight analysis modes, and five formats are supported. Focus selects matching evidence with predecessor context where needed. Unsupported lexical focus produces an explicit insufficient-information message and no invented source evidence. Synonyms with no shared normalized vocabulary can still be missed.

See [algorithm design](algorithm-design.md), [experimental results](experimental-results.md), and [routing/rollback](engine-routing-architecture.md).
