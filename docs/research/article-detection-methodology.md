# Automatic article detection

Status: implemented as an opt-in backend capability, 22 October 2026. Production defaults remain disabled pending quality review.

The research question is whether extracted prose can be screened for article-like structure without forcing receipts, forms, resumes, source code, or internal reports through an article-specific selector. The implementation is [`article_detector.py`](../../python-engine/article_detector.py). Its output includes `is_article`, `article_type`, `confidence`, `detected_features`, and `classification_reason`.

The confidence field is a reproducible heuristic score, **not a probability or a measured accuracy rate**. Independent features count complete sentences, prose coverage, paragraphs, heading structure, a possible title, and distinct language cues. Negative features identify operational document structure, code density, and list density. Neither file extension nor URL hostname is used. Multiple cues and continuous prose are necessary even when the score is high. A research article's tables do not automatically reject its explanatory prose.

The feature score is:

```text
s = .26 min(1, complete_sentences / 8)
  + .20 prose_word_ratio
  + .16 min(1, paragraphs / 4)
  + .08 possible_title
  + .20 min(1, distinct_category_cues / 4)
  + .10 min(1, recognized_headings / 3)
  - .60 negative_document_marker
  - .80 code_line_ratio
  - .30 predominantly_list_content
```

Clamp the score to [0,1]. Eligibility additionally requires at least five complete sentences, 100 words, 65% prose coverage, two distinct category cues, low code/list density, and multiple paragraphs. Flattened text can satisfy the paragraph gate with eight complete sentences and four cues. This accommodation followed a development-PDF extraction test: the existing extractor merged paragraph boundaries. It does not claim to reconstruct the missing layout.

Category cues identify news, research, science, technology, business, opinion, educational, and feature content. Academic and research articles share the research category. These categories are tentative descriptions, not independently validated domain classifiers. The detector returns uncertain when evidence is insufficient; uncertain content keeps the original engine.

## Calibration and evaluation

`evaluation/article_routing_fixtures.py` contains 11 original engineering-authored development examples and four flattened versions of its development articles. The latter are format augmentations, not four independent articles. The experiment selects a threshold that maximizes development recall subject to zero observed development false positives, breaking ties toward the highest threshold. The measured threshold is **0.70** and is retained with the detector version, input hash, and per-example scores in [`detection_calibration.json`](../../python-engine/article_engine/detection_calibration.json). Missing or invalid calibration disables positive routing.

The fixed regression subset contains 17 examples: eight article labels and nine non-article/uncertain labels. Final observed decisions were 8 true positives, 9 true negatives, 0 false positives, and 0 false negatives. This is a small synthetic engineering sample; it does not establish population accuracy. Earlier results were inspected during development, so the retained subset is a regression benchmark, not a pristine independent final test. A new external test set and reviewer-assigned labels remain required for production validation.

News-PDF, research-DOCX, receipt-PDF and URL-content tests exercise extraction or routing boundaries. The URL test uses a mocked fetch; it does not establish external-site reliability. Long internal reports, code, configuration, CVs, receipts, and ambiguous short prose remain on the original route or retain existing extraction errors.

The guide introduces preprocessing and classification building blocks, but this particular detector, score, calibration procedure, and negative-document rules are LIGHT-specific additions. They must not be attributed to the guide's appendix.
