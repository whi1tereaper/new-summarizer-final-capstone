"""Read-only source-processing probe, with safe stage stack/timing evidence."""
import faulthandler
import json
import os
from pathlib import Path
import sys
from time import perf_counter

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
sys.path = [p for p in sys.path if p != str(Path(__file__).resolve().parent)]
os.environ.update(OPENBLAS_NUM_THREADS="1", OMP_NUM_THREADS="1", MKL_NUM_THREADS="1",
                  HF_HUB_OFFLINE="1", TOKENIZERS_PARALLELISM="false",
                  SUMMARIZER_LOCAL_EMBEDDING_MODEL=str(ROOT.parent / "storage/models/all-MiniLM-L6-v2"))
faulthandler.dump_traceback_later(45, repeat=True)
began = perf_counter()
from local_cli import handle_summarize
result = handle_summarize({"file_path": sys.argv[1], "summary_depth": "brief", "sentence_count": 3,
                           "analysis_mode": "general", "output_format": "paragraph",
                           "performance_profile": True, "automatic_article_routing": False})
faulthandler.cancel_dump_traceback_later()
metadata = result.get("source_metadata", {})
out = {"seconds": perf_counter()-began, "source_words": result.get("readability", {}).get("original_word_count"),
       "sentences": result.get("sentence_count"), "metadata_performance": metadata.get("performance", {}),
       "keys": [k for k in metadata if "perform" in k or "tim" in k]}
path = ROOT.parent / "docs/research/runtime-python-profile.json"
path.write_text(json.dumps(out, indent=2)+"\n", encoding="utf-8")
print(json.dumps(out, indent=2), flush=True)
