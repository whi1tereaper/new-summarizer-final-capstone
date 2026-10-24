"""Academic appendix baseline: Sumy 0.11 LSA, without its Gradio interface.

Appendix cleaning is intentionally retained here for a faithful comparator;
the proposed engine never applies its global word-deletion rules to evidence.
"""
import re


def guide_lsa(text: str, count: int) -> list[str]:
    from sumy.parsers.plaintext import PlaintextParser
    from sumy.nlp.tokenizers import Tokenizer
    from sumy.summarizers.lsa import LsaSummarizer
    value = re.sub(r"[*_\-]{2,}", " ", text)
    value = re.sub(r"\S+@\S+", "", value)
    for phrase in ["International Journal", "Scientific Research", "Engineering Development",
                   "ISSN:", "www.", "Volume", "Issue", "Page", "Available at", "OPEN ACCESS"]:
        value = re.sub(phrase, "", value, flags=re.I)
    parser = PlaintextParser.from_string(" ".join(value.split()), Tokenizer("english"))
    return [str(s) for s in LsaSummarizer()(parser.document, count)]
