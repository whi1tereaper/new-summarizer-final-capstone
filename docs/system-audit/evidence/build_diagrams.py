"""Build original evidence-grounded diagrams and focused print panels; docs only."""
from pathlib import Path
import html
import json
import textwrap

ROOT = Path(__file__).resolve().parents[2] / "capstone-manuscript"
OUT = ROOT / "diagrams"
OUT.mkdir(exist_ok=True)
MODELS = {}


def n(kind, label):
    return {"kind": kind, "label": label}


def model(name, title, nodes, edges, note="SOURCE-SUPPORTED design; operation is separately verified.", direction="TB"):
    for left, right, label in edges:
        assert left in nodes and right in nodes, (name, left, right)
        if name.startswith("dfd"):
            assert not ({nodes[left]["kind"], nodes[right]["kind"]} == {"store", "entity"}), (name, left, right)
    MODELS[name] = {"title": title, "nodes": nodes, "edges": edges, "note": note, "direction": direction}
    lines = [f"%% {title}", f"%% {note}", f"flowchart {direction}"]
    for key, node in nodes.items():
        label = node["label"].replace("|", "<br/>").replace('"', "'")
        if node["kind"] == "process":
            lines.append(f'    {key}("{label}")')
        elif node["kind"] == "store":
            lines.append(f'    {key}@{{ shape: datastore, label: "{label}" }}')
        else:
            lines.append(f'    {key}["{label}"]')
    for left, right, label in edges:
        edge_label = '<br/>'.join(textwrap.wrap(label, width=24, break_long_words=False))
        lines.append(f'    {left} -->|"{edge_label}"| {right}')
    lines.extend(["    classDef process fill:#edf1f5,stroke:#222,color:#111;", "    classDef store fill:#f6f6f6,stroke:#222,color:#111;", "    classDef entity fill:#fff,stroke:#222,color:#111;"])
    for kind in ("process", "store", "entity"):
        ids = [key for key, node in nodes.items() if node["kind"] == kind]
        if ids:
            lines.append(f"    class {','.join(ids)} {kind};")
    (OUT / f"{name}.mmd").write_text("\n".join(lines) + "\n", encoding="utf-8")


U = n("entity", "Visitor / registered user|eligible requests only")
A = n("entity", "Administrator /|assigned evaluator")
W = n("entity", "Public source website")
M = n("entity", "Configured email service")
T = n("entity", "Translation provider")
D1 = n("store", "D1 Account / session stores|users; password_resets;|user_tokens; guest_sessions;|rate_limits; PHP session state")
D2 = n("store", "D2 Main summary stores|summaries; summary_artifacts;|summary_jobs; temporary files")
D3 = n("store", "D3 Reading-helper stores|documents; document_summaries;|nutshell_generations; audio files")
D4 = n("store", "D4 Feedback / telemetry|feedback; feedback_comments;|landing_page_sessions;|landing_page_events")
D5 = n("store", "D5 Administration audit|admin_audit_logs")
D6 = n("store", "D6 Evaluation stores|13 evaluation_* tables|See complete data dictionary")

model("dfd-context", "LIGHT summarizer: context diagram", {
    "U": U, "A": A, "P0": n("process", "0 LIGHT summarizer|PHP application and|local NLP / TTS workers"), "W": W, "M": M, "T": T,
}, [("U", "P0", "source / settings / account / feedback"), ("P0", "U", "results / account status"), ("A", "P0", "administration / evaluation data"), ("P0", "A", "reports / assigned outputs"), ("P0", "W", "URL request"), ("W", "P0", "source content"), ("P0", "M", "verification / reset mail"), ("M", "P0", "delivery status"), ("P0", "T", "selected text / language"), ("T", "P0", "translation / provider error")], note="System boundary includes local NLP and TTS. External synthesis is disabled in inspected configuration.")

level1_nodes = {
    "U": U, "A": A, "W": W, "M": M, "T": T,
    "P1": n("process", "1.0 Account and|session management"),
    "P2": n("process", "2.0 Source intake and|summary processing"),
    "P3": n("process", "3.0 Results and|reading helpers"),
    "P4": n("process", "4.0 Feedback and|usage analytics"),
    "P5": n("process", "5.0 Administration and|research evaluation"),
    "D1": D1, "D2": D2, "D3": D3, "D4": D4, "D5": D5, "D6": D6,
}
level1_edges = [
    ("U", "P1", "account request"), ("P1", "U", "account status"), ("P1", "M", "verification / reset mail"), ("M", "P1", "delivery status"), ("P1", "D1", "account / session changes"), ("D1", "P1", "identity / session data"),
    ("U", "P2", "source and settings"), ("P2", "U", "job status / result ID"), ("P2", "W", "URL request"), ("W", "P2", "source content"), ("P2", "D2", "job / summary / artifact records"), ("D2", "P2", "job state / existing result"),
    ("U", "P3", "result / helper request"), ("P3", "U", "authorized reading output"), ("D2", "P3", "owned or shared summary"), ("P3", "D3", "helper cache / audio"), ("D3", "P3", "cached source / helper data"), ("P3", "T", "text and target language"), ("T", "P3", "translation / error"),
    ("U", "P4", "feedback / visit event"), ("P4", "U", "confirmation / statistics"), ("P4", "D4", "feedback / event records"), ("D4", "P4", "feedback / usage data"), ("D2", "P4", "summary statistics"),
    ("A", "P5", "management / assessment request"), ("P5", "A", "reports / assigned outputs"), ("P5", "D1", "managed account changes"), ("D1", "P5", "account records"), ("D4", "P5", "feedback / telemetry"), ("P5", "D5", "audit entries"), ("D5", "P5", "audit history"), ("P5", "D6", "dataset / run / review records"), ("D6", "P5", "evaluation outputs / ratings"),
]
model("dfd-level1", "LIGHT summarizer: DFD Level 1", level1_nodes, level1_edges, note="Use focused panels for print. Logical stores group actual tables/files; PHP session state is not a SQL table.")
for key, title, ids in [
    ("accounts", "DFD Level 1: account and session management", ["U", "P1", "M", "D1"]),
    ("summary", "DFD Level 1: source intake and summarization", ["U", "P2", "W", "D2"]),
    ("results", "DFD Level 1: results and reading helpers", ["U", "P3", "T", "D2", "D3"]),
    ("feedback", "DFD Level 1: feedback and usage analytics", ["U", "P4", "D2", "D4"]),
    ("administration", "DFD Level 1: administration and research evaluation", ["A", "P5", "D1", "D4", "D5", "D6"]),
]:
    model(f"dfd-level1-{key}", title, {node: level1_nodes[node] for node in ids}, [edge for edge in level1_edges if edge[0] in ids and edge[1] in ids])

summary_nodes = {
    "U": U, "W": W, "D2": D2,
    "P21": n("process", "2.1 Validate request|CSRF, source type,|settings and upload"),
    "P22": n("process", "2.2 Resolve source and|persist / reuse job"),
    "P23": n("process", "2.3 Run local NLP|Extract, rank, select|and validate output"),
    "P24": n("process", "2.4 Save result or failure|Release temporary source"),
    "P25": n("process", "2.5 Return owned job status|Result ID or recovery action"),
}
summary_edges = [
    ("U", "P21", "source / settings"), ("P21", "U", "validation error"), ("P21", "P22", "validated request"), ("P22", "W", "URL request, when used"), ("W", "P22", "source bytes / text"), ("P22", "D2", "queued payload"), ("D2", "P22", "existing job"), ("P22", "P23", "resolved source"), ("P23", "P24", "summary / safe failure"), ("P24", "D2", "summary, artifacts, job state"), ("P24", "P25", "result state"), ("U", "P25", "status request"), ("D2", "P25", "owned job / result"), ("P25", "U", "status / result ID"),
]
model("dfd-level2-summary", "DFD Level 2: source intake and summary processing", summary_nodes, summary_edges, note="Expands process 2.0; requester, source website and D2 retain their Level 1 identities.")
for key, title, ids in [
    ("intake", "DFD 2.1-2.2: request and source intake", ["U", "W", "D2", "P21", "P22", "P23"]),
    ("output", "DFD 2.3-2.5: output persistence and status", ["U", "D2", "P23", "P24", "P25"]),
]:
    model(f"dfd-level2-summary-{key}", title, {node: summary_nodes[node] for node in ids}, [edge for edge in summary_edges if edge[0] in ids and edge[1] in ids], note="Focused view; repeated subprocesses connect adjacent panels of the full process-2.0 decomposition.")

auth_nodes = {
    "U": U, "M": M, "D1": D1,
    "P11": n("process", "1.1 Validate account request|Apply rate limits and|choose authentication flow"),
    "P12": n("process", "1.2 Check required identity|Password, applicable code|or existing session state"),
    "P13": n("process", "1.3 Update permitted account|Establish / revoke session|or remembered login"),
}
auth_edges = [
    ("U", "P11", "account request"), ("P11", "U", "validation failure"), ("P11", "M", "verification / reset mail"), ("M", "P11", "delivery status"), ("D1", "P11", "rate / account state"), ("P11", "D1", "rate / verification state"), ("P11", "P12", "validated request"), ("D1", "P12", "hash / code / account state"), ("P12", "D1", "consumed verification code"), ("P12", "P13", "verified identity"), ("P13", "D1", "account / session changes"), ("P13", "U", "access / recovery result"),
]
model("dfd-level2-auth", "DFD Level 2: account and session management", auth_nodes, auth_edges, note="OTP and PHP session state remain under D1. D5 is audit storage; legacy security-question tables are not current OTP storage.")
for key, title, ids in [
    ("verification", "DFD 1.1-1.2: account request and verification", ["U", "M", "D1", "P11", "P12"]),
    ("session", "DFD 1.2-1.3: identity and session changes", ["U", "D1", "P12", "P13"]),
]:
    model(f"dfd-level2-auth-{key}", title, {node: auth_nodes[node] for node in ids}, [edge for edge in auth_edges if edge[0] in ids and edge[1] in ids], note="Focused account-flow view; repeated process 1.2 links the two panels. All verification/session state remains D1.")


class Canvas:
    def __init__(self, title, width=600, height=800):
        self.width, self.height = width, height
        self.parts = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" viewBox="0 0 {width} {height}" role="img" aria-label="{html.escape(title)}">', '<defs><marker id="arrow" markerWidth="8" markerHeight="7" refX="7" refY="3.5" orient="auto"><path d="M0 0 L8 3.5 L0 7 Z" fill="#222"/></marker></defs>', f'<rect width="{width}" height="{height}" fill="white"/>']
        self.text(width / 2, 28, title, 22, 48, bold=True)

    def text(self, x, y, value, size=20, width=30, bold=False, background=False):
        lines = []
        for part in value.split("|"):
            lines.extend(textwrap.wrap(part, width=width, break_long_words=False) or [""])
        if background:
            box_width = max(len(line) for line in lines) * size * 0.54 + 10
            self.parts.append(f'<rect x="{x-box_width/2}" y="{y-size}" width="{box_width}" height="{len(lines)*(size+5)+4}" fill="white"/>')
        for index, line in enumerate(lines):
            self.parts.append(f'<text x="{x}" y="{y + index * (size + 5)}" text-anchor="middle" font-family="Arial, sans-serif" font-size="{size}" font-weight="{"bold" if bold else "normal"}">{html.escape(line)}</text>')
        return len(lines)

    def box(self, x, y, width, height, label, kind="entity", size=20):
        if kind == "usecase":
            self.parts.append(f'<ellipse cx="{x+width/2}" cy="{y+height/2}" rx="{width/2}" ry="{height/2}" fill="#fff" stroke="#222" stroke-width="2"/>')
        else:
            self.parts.append(f'<rect x="{x}" y="{y}" width="{width}" height="{height}" rx="{14 if kind == "process" else 0}" fill="{"#edf1f5" if kind == "process" else "white"}" stroke="#222" stroke-width="2"/>')
        width_chars = int(width / (size * 0.52)) - 3
        count = sum(max(1, len(textwrap.wrap(part, width=width_chars, break_long_words=False))) for part in label.split("|"))
        self.text(x + width / 2, y + height / 2 - (count - 1) * (size + 5) / 2 + size * 0.33, label, size, width_chars)

    def path(self, points, arrow=True):
        commands = ' '.join(("M" if index == 0 else "L") + str(x) + "," + str(y) for index, (x, y) in enumerate(points))
        marker = ' marker-end="url(#arrow)"' if arrow else ''
        self.parts.append(f'<path d="{commands}" fill="none" stroke="#222" stroke-width="2"{marker}/>')

    def save(self, name):
        (OUT / f"{name}.svg").write_text("".join(self.parts) + "</svg>", encoding="utf-8")


# A deliberately compact context layout keeps all external entities readable.
context = Canvas("LIGHT summarizer: context diagram", height=820)
for x, y, w, h, label, kind in [
    (20, 85, 260, 105, "Visitor /|registered user", "entity"),
    (320, 85, 260, 105, "Administrator /|assigned evaluator", "entity"),
    (180, 360, 240, 120, "0 LIGHT summarizer|PHP and local|NLP / TTS workers", "process"),
    (10, 650, 180, 90, "Public source|website", "entity"),
    (210, 650, 180, 90, "Configured|email service", "entity"),
    (410, 650, 180, 90, "Translation|provider", "entity"),
]:
    context.box(x, y, w, h, label, kind, 19)
for points in [
    [(160, 190), (160, 250), (220, 250), (220, 360)],
    [(280, 360), (280, 310), (240, 310), (240, 190)],
    [(380, 190), (380, 250), (340, 250), (340, 360)],
    [(400, 360), (400, 310), (440, 310), (440, 190)],
    [(220, 480), (220, 525), (80, 525), (80, 650)],
    [(140, 650), (140, 430), (180, 430)],
    [(280, 480), (280, 600), (265, 600), (265, 650)],
    [(335, 650), (335, 480)],
    [(420, 400), (540, 400), (540, 650)],
    [(480, 650), (480, 575), (400, 575), (400, 480)],
]:
    context.path(points)
for x, y, label in [
    (85, 225, "source / settings|account / feedback"), (280, 285, "results /|status"),
    (320, 215, "admin /|evaluation|requests"), (510, 305, "reports /|assigned outputs"),
    (85, 500, "URL request"), (145, 575, "source|content"),
    (257, 550, "verification /|reset mail"), (365, 610, "delivery|status"),
    (490, 475, "selected text /|target language"), (465, 605, "translation|or error"),
]:
    context.text(x, y, label, 14, 22, background=True)
context.text(300, 780, "Source-supported boundary; provider operation remains unverified.", 15, 64)
context.save("dfd-context")
MODELS["dfd-context"]["svg_preserved"] = True

c = Canvas("Proposed research paradigm", height=830)
paradigm = [
    ("I", "INPUT|Academic articles and user requirements|Actual system design and constraints|Approved evaluation criteria: pending"),
    ("P", "PROCESS|Refine and verify LIGHT summarizer|Apply implemented NLP workflows|Conduct human evaluation: pending"),
    ("O", "OUTPUT|LIGHT article summaries and features|User and maintenance documentation|Human evaluation findings: pending"),
    ("F", "FEEDBACK|Use technical findings and future|participant responses to refine|the system and research account"),
]
for index, (key, label) in enumerate(paradigm):
    y = 85 + index * 177
    c.box(55, y, 490, 135, label, "process" if key == "P" else "entity", 19)
    if index < 3:
        c.path([(300, y + 135), (300, y + 177)])
c.path([(545, 683), (575, 683), (575, 150), (545, 150)])
c.text(300, 810, "PROPOSED DRAFT: no measured benefit is implied.", 15, 65)
c.save("research-paradigm")
model("research-paradigm", "Proposed Input-Process-Output-Feedback research paradigm", {key: n("process" if key == "P" else "entity", label) for key, label in paradigm}, [("I", "P", "inputs"), ("P", "O", "documented results"), ("O", "F", "review"), ("F", "I", "revised requirements")], note="PROPOSED DRAFT; research organization, not completed human evaluation.")

role_panels = [
    ("guests", "Guest visitor", ["Provide text, URL, PDF or DOCX|and choose summary settings", "Generate a summary; inspect|progress, retry or cancel", "Read an authorized result;|copy / export / reading helpers", "Submit permitted rating|and feedback comments", "Register, sign in or|request password recovery"], "Guest context has no personal history or standalone Nutshell. Reading helpers remain subject to route guards."),
    ("registered", "Registered account", ["Use eligible summarization|and reading workflows", "View personal summary|history and analytics", "Maintain profile and|saved preferences", "Generate standalone Nutshell|when account guards permit", "Read permitted shared results|and give feedback"], "Registered users retain eligible guest workflows. These functions are source-supported, not successful live-test claims."),
    ("administrators", "Administrator", ["Use eligible ordinary|account workflows", "Manage permitted user|account operations", "Inspect audit history,|system records and analytics", "Manage evaluation datasets,|runs, assignments and reports"], "Stored roles are user/admin. Current email OTP is separate from the retired security-question flow."),
    ("evaluators", "Assigned evaluator", ["Open an eligible review|with its assignment token", "Inspect source material|and the assigned output", "Submit the permitted|assessment and ratings"], "Evaluator is an assignment to an existing account, not a third stored role. It does not grant administration."),
]
overview_nodes, overview_edges = {}, []
for stem, role, cases, note in role_panels:
    height = 185 + len(cases) * 120 + 100
    canvas = Canvas(f"LIGHT summarizer: {role.lower()}", height=height)
    canvas.box(25, 105, 150, 95, role, size=20)
    canvas.parts.append(f'<rect x="205" y="80" width="370" height="{len(cases)*120+35}" fill="none" stroke="#555" stroke-width="1.5"/>')
    canvas.text(390, 107, "LIGHT summarizer", 17, 28, bold=True)
    source = [f"%% {note}", "flowchart TB", f'    ACTOR["{role}"]', '    subgraph SYSTEM["LIGHT summarizer"]']
    overview_nodes[stem] = n("entity", role)
    for index, case in enumerate(cases):
        y = 130 + index * 120
        canvas.path([(175, 152), (218, y + 45)], arrow=False)
        canvas.box(218, y, 345, 90, case, "usecase", 19)
        label = case.replace("|", "<br/>")
        source.append(f'    C{index}(["{label}"])')
        overview_nodes[f"{stem}{index}"] = n("process", case)
        overview_edges.append((stem, f"{stem}{index}", "association"))
    source.append("    end")
    source.extend(f"    ACTOR --- C{index}" for index in range(len(cases)))
    canvas.text(300, height - 70, note, 15, 65)
    canvas.save(f"use-cases-{stem}")
    (OUT / f"use-cases-{stem}.mmd").write_text("\n".join(source) + "\n", encoding="utf-8")
    MODELS[f"use-cases-{stem}"] = {"title": f"Use cases: {role}", "note": note, "nodes": {}, "edges": [], "svg_preserved": True}
model("use-cases", "LIGHT summarizer: role and use-case overview", overview_nodes, overview_edges, note="Use four focused panels for printing. Guest access excludes standalone Nutshell and personal history.", direction="LR")
p = OUT / "use-cases.mmd"
p.write_text(p.read_text(encoding="utf-8").replace(' -->|"association"| ', ' --- '), encoding="utf-8")

MODELS["research-paradigm"]["svg_preserved"] = True
(ROOT / "build").mkdir(exist_ok=True)
coverage = []
for full, prefixes in [
    ("dfd-level1", ["dfd-level1-"]),
    ("dfd-level2-summary", ["dfd-level2-summary-"]),
    ("dfd-level2-auth", ["dfd-level2-auth-"]),
]:
    full_edges = {tuple(edge) for edge in MODELS[full]["edges"]}
    panel_edges = {tuple(edge) for name, info in MODELS.items() if any(name.startswith(prefix) for prefix in prefixes) for edge in info.get("edges", [])}
    missing = full_edges - panel_edges
    assert not missing, (full, missing)
    coverage.append({"full_diagram": full, "edges": len(full_edges), "edges_in_print_panels": len(full_edges & panel_edges), "missing_edges": []})
(ROOT / "build/DFD_PANEL_COVERAGE.json").write_text(json.dumps(coverage, indent=2), encoding="utf-8")
(OUT / "DIAGRAM_MODELS.json").write_text(json.dumps(MODELS, indent=2, ensure_ascii=False), encoding="utf-8")
(OUT / "DIAGRAM_README.md").write_text("""# Original diagram source register

These original diagrams derive from audited source and current schema, not AccredX or the researcher draft. Mermaid is editable diagram source; SVG preserves vector geometry; PNG is Word-compatible. The paradigm is PROPOSED DRAFT. DFD/use-case views are SOURCE-SUPPORTED descriptions and do not imply every flow passed functional testing.

## Logical stores and balance

D1 groups users, password_resets, user_tokens, guest_sessions, rate_limits and PHP session state. PHP sessions are runtime/file state, not a SQL table. Administrator OTP and reset verification remain under D1 in both levels. D2 includes summaries, summary_artifacts, summary_jobs and temporary source files. D3 groups documents, document_summaries, nutshell_generations and generated audio. D4 groups feedback, feedback_comments, landing_page_sessions and landing_page_events. D5 is administration audit via admin_audit_logs. D6 groups the 13 actual evaluation_* tables in the data dictionary.

admin_security_challenges exists but is legacy, not current email-OTP storage. schema_migrations records migration execution, not an active end-user flow. Both remain in the complete ERD/data dictionary. Names summary_records and feedback_records from the older draft are not used.

Level 2 expands processes 1.0 and 2.0. Actors, providers and logical stores retain their identities. No store connects directly to an external actor. Local NLP/TTS workers are inside the application boundary; website retrieval, configured email and translation providers are external. Optional external synthesis is disabled in inspected configuration and excluded; enabling it requires a disclosed external-provider flow.

## Roles and print use

Guest is a session context; user/admin are stored roles. Evaluator is an assignment to an existing account. Guest access excludes standalone Nutshell and personal history. Diagram eligibility is scoped to inspected route guards; separate authorization defects remain applicable.

Use five dfd-level1-* focused panels and two dfd-level2-summary-* panels when full overviews are too dense at the narrow manuscript width. Four use-cases-* role panels use ellipses and undirected associations. Full SVG overviews remain available for zooming. Render metadata records intrinsic sizes and estimated printed font sizes; small estimates identify supporting overviews, not appropriate printed figures.

Root evidence: ArticleController, ArticleService, SummaryJobService, AuthController, SourceResolver, HistoryService, FeedbackService, EvaluationService, LocalPythonBridge and local_cli.py. The audit reports record limitations. ERD coverage separately verifies all 31 entities and 35 declared foreign keys.
""", encoding="utf-8")
print(json.dumps({"models": len(MODELS), "dfd_direct_store_actor_edges": 0, "output": str(OUT)}))
