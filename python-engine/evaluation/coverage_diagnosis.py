"""Trace historical v1 omissions before algorithm changes; no tuning inputs at runtime."""
from __future__ import annotations
import json
from pathlib import Path
import sys
import numpy as np
import re

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from evaluation.article_v1_snapshot.preprocessing import sentences, TOKEN
from evaluation.article_v1_snapshot.ranking import analyze, QUALIFIER, MODES, REFERENCE, LAYOUT


def diagnose():
    report = json.loads((ROOT.parent / "docs/research/article-engine-results.json").read_text(encoding="utf-8"))
    results = []
    for doc in report["documents"]:
        old = next(r for r in report["rows"] if r["id"] == doc["id"] and r["system"] == "article")
        records, tokenizer = sentences(doc["source_text"])
        analysis = analyze(records)
        matrix, _, relevance, lsa, _, _, entities, _ = analysis
        mode = doc.get("profile", "general")
        profile = re.compile(r"\b(?:" + MODES[mode] + r")\b", re.I)
        cues = np.array([min(1, len(profile.findall(s.text)) / 2) for s in records])
        evidence = np.array([min(1, (bool(re.search(r"\d", s.text)) + bool(QUALIFIER.search(s.text)) + bool(entities[i])) / 3) for i, s in enumerate(records)])
        scores = .55 * relevance + .20 * lsa + .15 * cues + .10 * evidence
        scores += np.array([.06 if re.search(r"results|findings|conclusion|limitations", s.section, re.I) else .03 if i == 0 else 0 for i, s in enumerate(records)])
        selected = {s.index for s in records if s.text in old["sentences"]}
        ranked = list(np.argsort(-scores, kind="stable"))
        used = sum(len(TOKEN.findall(records[i].text)) for i in selected)
        budget = min(1600, max(45, round(len(TOKEN.findall(doc["source_text"])) * .75)), old["settings"]["target_word_budget"])
        missing = []
        items = [("unit", u["id"], u["all_of"]) for u in doc.get("key_units", [])]
        items += [("fact", f, [f]) for f in doc.get("critical_facts", [])]
        for kind, label, tokens in items:
            if any(all(str(t).casefold() in s.casefold() for t in tokens) for s in old["sentences"]):
                continue
            matching = [s for s in records if all(str(t).casefold() in s.text.casefold() for t in tokens)]
            raw_present = all(str(t).casefold() in doc["source_text"].casefold() for t in tokens)
            candidates = []
            for s in matching:
                previous = records[s.index - 1] if s.index else None
                context_blocked = bool(REFERENCE.search(s.text)) and (not previous or previous.paragraph != s.paragraph)
                overlap = float((matrix[s.index] @ matrix[list(selected)].T).max()) if selected else 0
                causes = []
                if context_blocked:
                    causes.append("cross_paragraph_context_rejected")
                if LAYOUT.search(s.text):
                    causes.append("layout_reference_rejected")
                if overlap > .90:
                    causes.append("pairwise_duplicate_cutoff")
                if used + len(TOKEN.findall(s.text)) > budget:
                    causes.append("remaining_word_budget_insufficient")
                if len(selected) >= min(old["settings"]["sentence_count"], int(np.ceil(len(records) * .75))):
                    causes.append("ratio_sentence_cap_reached")
                if not causes:
                    causes.append("lower_greedy_rank_no_concept_gain")
                candidates.append({"index": s.index, "text": s.text, "section": s.section,
                                   "score": round(float(scores[s.index]), 6), "rank": ranked.index(s.index) + 1,
                                   "words": len(TOKEN.findall(s.text)), "max_selected_cosine": overlap,
                                   "causes": causes})
            missing.append({"kind": kind, "label": label, "source_contains_tokens": raw_present,
                            "matching_sentences": candidates,
                            "annotation_limit": "requires_multiple_sentences_or_noncontiguous_source" if not matching and raw_present else None})
        results.append({"id": doc["id"], "source_text": doc["source_text"], "source_sentence_count": len(records),
                        "tokenizer": tokenizer, "word_budget": budget, "used_token_words": used,
                        "selected_indices": sorted(selected), "missing": missing,
                        "format_changes_source_text": any(s not in doc["source_text"] for s in old["sentences"])})
    return {"provenance": "historical_outputs_plus_frozen_v1_code", "articles": results,
            "limits": "Trace explains eligibility and final selection constraints; it does not infer a human importance ranking."}


if __name__ == "__main__":
    out = ROOT.parent / "docs/research/article-v1-root-causes.json"
    data = diagnose()
    out.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps({"articles": len(data["articles"]), "omissions": sum(len(a["missing"]) for a in data["articles"])}))
