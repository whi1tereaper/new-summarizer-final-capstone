"""Run workspace browser QA against restricted local PHP/MySQL and the real NLP worker."""
import argparse
import getpass
import json
import os
import subprocess
import tempfile
import time
from pathlib import Path

import requests

ROOT = Path(__file__).resolve().parents[2]
REPORT = ROOT / "docs/qa/workspace-redesign"
PHP = "C:/php83/php.exe"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=["setup", "start", "before", "browser", "supplementary", "stop"])
    args = parser.parse_args()
    if args.action == "setup":
        REPORT.mkdir(parents=True, exist_ok=True)
        folder = Path(tempfile.mkdtemp(prefix="light-workspace-qa-", dir=os.environ["LOCALAPPDATA"])).resolve()
        subprocess.run(["icacls", str(folder), "/inheritance:r", "/grant:r", getpass.getuser()+":(OI)(CI)F"], capture_output=True, check=True)
        (REPORT / "last-run.txt").write_text(str(folder), encoding="utf8")
        subprocess.run([PHP, "scripts/qa/control.php", "setup-benchmark", str(folder)], cwd=ROOT, check=True)
        return
    folder = Path((REPORT / "last-run.txt").read_text().strip())
    cfg = json.loads((folder / "config.json").read_text())
    env = os.environ.copy()
    env.update(cfg["env"])
    env.update(APP_URL="http://127.0.0.1:58142", PYTHON_WORKER_PORT="58152", QA_WORKSPACE_REPORT=str(REPORT), QA_WORKSPACE_PRIVATE=str(folder))
    if args.action == "start":
        subprocess.run([PHP, "scripts/qa/isolation.php"], cwd=ROOT, env=env, check=True)
        public = (ROOT / "app/public").as_posix()
        private = folder.as_posix()
        conf = f'''ServerRoot "C:/xampp/apache"
Listen 127.0.0.1:58142
ServerName 127.0.0.1:58142
PidFile "{private}/httpd.pid"
ErrorLog "{private}/apache-error.log"
LoadModule alias_module modules/mod_alias.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule env_module modules/mod_env.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadFile "C:/php83/php8ts.dll"
LoadFile "C:/php83/libcrypto-3-x64.dll"
LoadFile "C:/php83/libssl-3-x64.dll"
LoadModule php_module "C:/php83/php8apache2_4.dll"
PHPINIDir "C:/php83"
DocumentRoot "{public}"
DirectoryIndex index.php
TypesConfig "C:/xampp/apache/conf/mime.types"
<Directory "C:/xampp/htdocs">
 AllowOverride None
</Directory>
<Directory "{public}">
 Require local
 AllowOverride All
 Options -Indexes
</Directory>
<FilesMatch "\\.php$">
 SetHandler application/x-httpd-php
</FilesMatch>
php_admin_value session.save_path "{private}"
php_admin_value error_log "{private}/php-error.log"
'''
        (folder / "apache.conf").write_text(conf)
        subprocess.run(["C:/xampp/apache/bin/httpd.exe", "-t", "-f", str(folder / "apache.conf")], cwd=ROOT, env=env, check=True)
        startup = subprocess.STARTUPINFO()
        startup.dwFlags |= subprocess.STARTF_USESHOWWINDOW
        startup.wShowWindow = subprocess.SW_HIDE
        children = {}
        for name, command in {
            "apache": ["C:/xampp/apache/bin/httpd.exe", "-f", str(folder / "apache.conf")],
            "nlp": [str(ROOT / ".venv-nlp/Scripts/python.exe"), "python-engine/worker_daemon.py", "--port", "58152", "--deadline", "600"],
        }.items():
            with (folder / (name + ".log")).open("wb") as log:
                child = subprocess.Popen(command, cwd=ROOT, env=env, stdout=log, stderr=log, startupinfo=startup)
            children[name] = child.pid
        (folder / "processes.json").write_text(json.dumps(children))
        for _ in range(45):
            try:
                response = requests.get("http://127.0.0.1:58142/summarizer.php", timeout=2)
                if response.status_code == 200:
                    print("Isolated workspace HTTP 200; production denied to QA credentials.")
                    return
            except requests.RequestException:
                pass
            time.sleep(.25)
        raise RuntimeError("Isolated Apache did not start")
    elif args.action in ("before", "browser", "supplementary"):
        env["QA_WORKSPACE_PHASE"] = args.action
        if args.action == "browser":
            import sys
            sys.path.insert(0, str(ROOT / "scripts"))
            from test_runtime_repair import SOURCE
            from docx import Document
            from pypdf import PdfWriter
            from pypdf.generic import DictionaryObject, NameObject, DecodedStreamObject
            (folder / "source.txt").write_text(SOURCE, encoding="utf8")
            doc = Document()
            for paragraph in SOURCE.split("\n\n"):
                doc.add_paragraph(paragraph)
            doc.save(folder / "source.docx")
            writer = PdfWriter()
            page = writer.add_blank_page(width=612, height=792)
            font = DictionaryObject({NameObject("/Type"): NameObject("/Font"), NameObject("/Subtype"): NameObject("/Type1"), NameObject("/BaseFont"): NameObject("/Helvetica")})
            page[NameObject("/Resources")] = DictionaryObject({NameObject("/Font"): DictionaryObject({NameObject("/F1"): writer._add_object(font)})})
            stream = DecodedStreamObject()
            lines = SOURCE.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)").splitlines()
            stream.set_data(("BT /F1 10 Tf 40 740 Td " + " ".join("(" + line + ") Tj 0 -14 Td" for line in lines) + " ET").encode("ascii"))
            page[NameObject("/Contents")] = writer._add_object(stream)
            writer.write(folder / "source.pdf")
            session_helper = '''<?php
require_once $argv[1].'/app/src/Support/config.php';
require_once $argv[1].'/app/src/Database.php';
$db=\\App\\Src\\Database::getInstance()->getConnection();
$sessions=[];
foreach(['qa_admin','qa_existing'] as $username){
 $q=$db->prepare('SELECT id,username,role FROM users WHERE username=?');$q->execute([$username]);$user=$q->fetch();
 session_save_path($argv[2]);session_name('LIGHT_QA_SESSID');session_id(bin2hex(random_bytes(16)));session_start();
 $_SESSION=['user_id'=>(int)$user['id'],'username'=>$user['username'],'role'=>$user['role'],'terms_accepted'=>true,'admin_challenge_verified'=>true];
 $sessions[$user['role']]=session_id();session_write_close();
}
echo json_encode($sessions);
'''
            (folder / "sessions.php").write_text(session_helper)
            sessions = subprocess.check_output([PHP, str(folder / "sessions.php"), str(ROOT), str(folder)], cwd=ROOT, env=env)
            (folder / "sessions.json").write_bytes(sessions)
        subprocess.run(["node", "scripts/qa/workspace_redesign_browser.cjs"], cwd=ROOT, env=env, check=True)
    else:
        for pid in json.loads((folder / "processes.json").read_text()).values():
            subprocess.run(["taskkill", "/PID", str(pid), "/T", "/F"], capture_output=True)
        subprocess.run([PHP, "scripts/qa/control.php", "verify", str(folder)], cwd=ROOT, check=True)
        (REPORT / "integrity.json").write_bytes((folder / "integrity.json").read_bytes())
        subprocess.run([PHP, "scripts/qa/control.php", "cleanup", str(folder)], cwd=ROOT, check=True)
        (folder / "config.json").unlink()
        print("Owned processes stopped; isolated schemas removed; production core rows preserved.")


if __name__ == "__main__":
    main()
