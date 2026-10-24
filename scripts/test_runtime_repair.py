"""Real local Apache request-path probes; generated evidence excludes session secrets."""
from __future__ import annotations
import argparse
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time
import uuid
import requests

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "python-engine"))
SOURCE = """City library extends evening opening hours

The city library announced that evening opening hours will be extended to help students and residents who work during the day. The new schedule covers three branches and begins next month.

Library managers surveyed 640 residents before proposing the change. The survey found that 58 percent of respondents wanted access after normal working hours. The survey was voluntary and does not represent every neighborhood equally.

The council approved a six month pilot rather than a permanent change. Staffing costs are estimated at 24000 dollars, and managers will publish monthly usage reports. No additional borrowing fees will be charged during the pilot.

Officials said the extended hours could improve access, but they have not promised that attendance will increase. The council will review costs and feedback before deciding whether to continue the schedule. Residents can submit comments through the existing library consultation service.
"""


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--reproduce", action="store_true")
    parser.add_argument("--base", default="http://127.0.0.1/app/public/")
    parser.add_argument("--host", default="ai-summarizer.local")
    parser.add_argument("--file", type=Path)
    parser.add_argument("--fixture", choices=["pdf", "docx"])
    parser.add_argument("--focus", default="")
    parser.add_argument("--url", default="")
    parser.add_argument('--kind',default='real_apache_http_guest_session')
    parser.add_argument('--nonarticle',action='store_true')
    parser.add_argument("--depth", default="brief")
    args = parser.parse_args()
    temporary = tempfile.TemporaryDirectory(prefix="light-runtime-fixture-") if args.fixture else None
    if args.fixture:
        args.file = Path(temporary.name) / ("runtime-example." + args.fixture)
        if args.fixture == "pdf":
            import importlib.util
            spec = importlib.util.spec_from_file_location("runtime_fixture", ROOT / "python-engine/tests/test_failure_feedback.py")
            fixture = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(fixture)
            fixture.pdf_fixture(args.file, text=SOURCE)
        else:
            from docx import Document
            document = Document()
            for paragraph in SOURCE.split("\n\n"):
                document.add_paragraph(paragraph)
            document.save(args.file)
    session = requests.Session()
    session.headers.update({"Host": args.host})
    response = session.get(args.base + "summarizer.php", timeout=20)
    token = re.search(r'name="csrf_token" value="([^"]+)"', response.text)
    assert response.status_code == 200 and token, "summarizer form/CSRF unavailable"
    fields = {"csrf_token": token.group(1), "request_token": uuid.uuid4().hex,
              "source_type": "file" if args.file else "text", "original_text": "" if args.file else SOURCE,
              "document_title": "Isolated runtime repair verification", "summary_depth": args.depth,
              "analysis_mode": "general", "output_format": "paragraph"}
    fields['focus_query'] = args.focus
    if args.nonarticle:
        fields['original_text']='Meeting Minutes\n\nDate: October 22\nAttendees: Alex and Sam\n\nAgenda\nThe team reviewed the office printer maintenance schedule. Alex reported that the old printer still works but needs a new toner cartridge. Sam confirmed that the maintenance contract will expire at the end of the month.\n\nAction Items\nAlex will replace the toner cartridge on Friday. Sam will request a new maintenance quotation from the supplier. The office manager will review the quotation before approving any purchase. No purchase was approved during this meeting.\n\nNext Meeting\nThe team will meet again next Thursday to check whether these actions have been completed.'
    if args.url:
        fields.update(source_type='url', original_text='', source_url=args.url)
    began = time.perf_counter()
    files = {"pdf_file": (args.file.name, args.file.read_bytes(), "application/octet-stream")} if args.file else None
    accepted = session.post(args.base + "summarize.php", data=fields, files=files,
                            headers={"Accept": "application/json"}, timeout=30)
    payload = accepted.json()
    assert payload.get("success"), payload
    job = payload["job_id"]
    print('Accepted isolated job ' + job, flush=True)
    execution = session.post(args.base + "summarize.php?action=execute",
                             data={"job_id": job, "csrf_token": token.group(1)}, timeout=30)
    observations = [execution.json()]
    deadline = time.monotonic() + (8 if args.reproduce else 330)
    while time.monotonic() < deadline:
        state = session.get(args.base + "summarize.php", params={"action": "status", "job_id": job}, timeout=20).json()
        observations.append(state)
        if state.get("status") not in {"queued", "processing"}:
            break
        time.sleep(1)
    kind = args.file.suffix[1:] if args.file else 'url' if args.url else 'text'
    evidence = {"test_kind": args.kind, "input": kind, 'base':args.base, 'fixture_nonarticle':args.nonarticle,
                "depth": args.depth, "job_id": job, "accept_http": accepted.status_code,
                "observations": observations, "seconds": time.perf_counter()-began,
                "expected_original_file_match_not_assumed": True}
    if args.reproduce:
        # Only our fresh job is passed to the normal worker. Capture a safe
        # classification, never a raw traceback or configured connection URL.
        worker = subprocess.run([r"C:\xampp\php\php.exe", str(ROOT / "app/public/summary_worker.php"), job],
                                capture_output=True, text=True, timeout=30)
        evidence["worker_exit"] = worker.returncode
        evidence["worker_sqlstate"] = re.findall(r"SQLSTATE\[([A-Z0-9]+)\]", worker.stderr)
        evidence["worker_failed_in_job_lookup"] = "SummaryJobService" in worker.stderr and "getJob" in worker.stderr
    elif observations[-1].get("status") == "completed":
        page = session.get(args.base + observations[-1]["redirect"], timeout=20)
        evidence["result_http"] = page.status_code
        evidence["result_contains_title"] = "Isolated runtime repair verification" in page.text
        assert page.status_code == 200 and evidence["result_contains_title"], "result retrieval failed"
    evidence["completed"] = observations[-1].get("status") == "completed"
    out = ROOT / "docs/research" / ("runtime-failure-reproduction.json" if args.reproduce else "runtime-repair-http-" + evidence['input'] + ".json")
    out.write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
    with (ROOT / "docs/research/runtime-http-attempts.jsonl").open("a", encoding="utf-8") as log:
        log.write(json.dumps(evidence) + "\n")
    print(json.dumps({**evidence, "observations": [observations[0], observations[-1]],
                      "poll_count": len(observations)}, indent=2), flush=True)
    if not args.reproduce:
        assert evidence["completed"], "real request did not complete"
    if temporary:
        temporary.cleanup()


if __name__ == "__main__":
    main()
