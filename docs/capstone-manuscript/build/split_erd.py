"""Create printable ERD panels from audited Mermaid definitions without new edges."""
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
DIAGRAMS = ROOT / "diagrams"
source = (DIAGRAMS / "ERD_ACTUAL_SCHEMA.mmd").read_text(encoding="utf-8")
entities = {match[1]: match[0] for match in re.finditer(r"^    (\w+) \{\n.*?^    \}", source, re.M | re.S)}
relationships = []
for line in source.splitlines():
    match = re.match(r"\s+(\w+)\s+([|o{}\-]+)\s+(\w+)\s*:\s*(.*)", line)
    if match:
        relationships.append((match[1], match[3], line))

panels = [
    ("ERD_PANEL_01_ACCOUNTS", "LR", "Accounts and authentication", ["users", "admin_security_challenges", "password_resets", "user_tokens"]),
    ("ERD_PANEL_02_SUMMARY_OUTPUTS", "TB", "Summary ownership, jobs and generated artifacts", ["users", "summaries", "summary_artifacts", "summary_jobs", "nutshell_generations"]),
    ("ERD_PANEL_03_FEEDBACK", "TB", "Summary ratings and comments", ["users", "summaries", "feedback", "feedback_comments"]),
    ("ERD_PANEL_04_DOCUMENT_CACHE", "TB", "Document extraction and cached summaries", ["documents", "document_summaries"]),
    ("ERD_PANEL_05_LANDING_TELEMETRY", "TB", "Landing-page sessions and events", ["landing_page_sessions", "landing_page_events"]),
    ("ERD_PANEL_06_INDEPENDENT_STORES", "LR", "Tables without declared foreign-key relationships", ["admin_audit_logs", "guest_sessions", "rate_limits", "schema_migrations"]),
    ("ERD_PANEL_07_EVALUATION_PIPELINE", "TB", "Evaluation datasets, documents, runs and outputs", ["evaluation_datasets", "evaluation_documents", "evaluation_runs", "evaluation_outputs"]),
    ("ERD_PANEL_08_EVALUATION_CONTENT", "TB", "Evaluation content units, references and challenge cases", ["evaluation_documents", "evaluation_content_units", "evaluation_references", "evaluation_challenge_cases"]),
    ("ERD_PANEL_09_EVALUATION_SCORING", "TB", "Evaluation assignments, ratings, errors and metrics", ["evaluation_outputs", "evaluation_runs", "evaluation_assignments", "evaluation_ratings", "evaluation_errors", "evaluation_metrics"]),
    ("ERD_PANEL_10_UNIT_REVIEWS", "TB", "Content-unit reviews against generated outputs", ["evaluation_outputs", "evaluation_content_units", "evaluation_content_unit_reviews"]),
    ("ERD_PANEL_11_EVALUATOR_ACCOUNTS", "LR", "Evaluator, reviewer and audit actor account links", ["users", "evaluation_assignments", "evaluation_content_unit_reviews", "evaluation_errors", "evaluation_audit_log"]),
    ("ERD_PANEL_12_RESEARCH_AUTHORSHIP", "LR", "Research dataset, run and reference authorship", ["users", "evaluation_datasets", "evaluation_runs", "evaluation_references", "evaluation_content_units"]),
]
covered_entities, covered_edges = set(), set()
report = []
for name, direction, title, names in panels:
    keys = set(names)
    edges = [line for left, right, line in relationships if left in keys and right in keys]
    document = "erDiagram\n    direction " + direction + "\n" + "\n".join(entities[name] for name in names) + "\n" + "\n".join(edges) + "\n"
    (DIAGRAMS / f"{name}.mmd").write_text(document, encoding="utf-8")
    covered_entities.update(keys)
    covered_edges.update(edges)
    report.append({"source": f"diagrams/{name}.mmd", "title": title, "entities": names, "foreign_keys": len(edges)})
all_edges = {line for left, right, line in relationships}
missing_entities = set(entities) - covered_entities
missing_edges = all_edges - covered_edges
if missing_entities or missing_edges:
    raise RuntimeError(f"Incomplete ERD partition: missing entities={missing_entities}; missing edges={missing_edges}")
result = {"authoritative_source": "diagrams/ERD_ACTUAL_SCHEMA.mmd", "unique_entities": len(covered_entities), "unique_foreign_key_relationships": len(covered_edges), "all_entities_covered": not missing_entities, "all_foreign_keys_covered": not missing_edges, "note": "Entities and relationships can repeat in focused panels. No relationship is inferred from a column name.", "panels": report}
(ROOT / "build/ERD_PANEL_COVERAGE.json").write_text(json.dumps(result, indent=2), encoding="utf-8")
lines = ["# Focused ERD panels", "", "SOURCE-SUPPORTED: these panels are exact subsets of the audited full ERD. The union contains every one of the 31 entities and 35 declared foreign-key relationships. Shared entities repeat to make the panels understandable. Only primary/foreign-key fields are shown; the data dictionary supplies all attributes. The unconnected-store panel deliberately has no relationship lines.", "", "Full SVG overviews remain available for zooming; the focused panels are suitable for insertion on separate manuscript pages. The build coverage report programmatically checks that no declared entity or foreign key is omitted.", ""]
for panel in report:
    stem = Path(panel["source"]).stem
    lines.extend([f"## {panel['title']}", "", f"![{panel['title']}]({stem}.png)", "", f"Editable source: [{stem}.mmd]({stem}.mmd). Vector version: [{stem}.svg]({stem}.svg).", ""])
(DIAGRAMS / "ERD_PRINT_PANELS.md").write_text("\n".join(lines), encoding="utf-8")
print(json.dumps({"panels": len(panels), "unique_entities": len(covered_entities), "unique_foreign_keys": len(covered_edges)}))
