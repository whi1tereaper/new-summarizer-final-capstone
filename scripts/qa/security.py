"""Read-only checks against XAMPP's normal project URL."""
import json, os, subprocess
from pathlib import Path
import requests
ROOT=Path(__file__).resolve().parents[2]
base="http://localhost/ai-summarizer-123%20-%20quality%20upg/"
checks=[]
def check(name,ok,detail=None):
    checks.append(dict(name=name,passed=bool(ok),detail=detail));print(("PASS " if ok else "FAIL ")+name)
for path in (".env",".git/config","storage/backups/ai_summarizer_xampp_20261023_190448.sql","scripts/qa/control.php",".venv-nlp/pyvenv.cfg"):
    r=requests.get(base+path,timeout=10);check("HTTP protection "+path,r.status_code in (403,404),r.status_code)
for path in (".env","storage/backups/qa.sql"):
    r=subprocess.run(["git","check-ignore",path],cwd=ROOT,capture_output=True)
    check("Git ignores "+path,r.returncode==0)
r=subprocess.run(["git","ls-files",".env","storage/backups/*.sql"],cwd=ROOT,capture_output=True)
check("no tracked active credentials or SQL backups",not r.stdout.strip())
hits=[]
for path in (ROOT/"app").rglob("*.php"):
    if "vendor" in path.parts:continue
    text=path.read_text(encoding="utf8",errors="replace").lower()
    if any(t in text for t in ("neonauth","pdo_pgsql","on conflict","pgsql","postgresql","docker")):hits.append(str(path.relative_to(ROOT)))
check("application PHP has no removed database/container runtime branches",not hits,hits)
env=os.environ.copy();env["DB_PORT"]="1"
r=subprocess.run(["C:/xampp/php/php.exe","-r",'require "app/src/Support/config.php";require "app/src/Database.php";try{App\\Src\\Database::getInstance();exit(1);}catch(Throwable $e){echo $e->getMessage();exit(0);}'],cwd=ROOT,env=env,capture_output=True,text=True)
check("unavailable local MySQL fails safely without fallback",r.returncode==0 and r.stdout=="Local MySQL is unavailable. Start XAMPP MySQL and check the configured database.")
(ROOT/"docs/qa/security-results.json").write_text(json.dumps(checks,indent=2),encoding="utf8")
raise SystemExit(int(any(not c["passed"] for c in checks)))
