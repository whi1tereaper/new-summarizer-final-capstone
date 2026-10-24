"""Run XAMPP QA with scoped MySQL credentials and a private rollback snapshot."""
import json, os, re, subprocess, tempfile, time, socketserver, threading, email, sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
PHP="C:/xampp/php/php.exe"
BENCHMARK="--benchmark-only" in sys.argv
REGISTRY_REQUIRED="--registry-required" in sys.argv
REGISTRY_CHECK="--registry-only" in sys.argv or REGISTRY_REQUIRED
ISOLATED_ONLY=BENCHMARK or REGISTRY_CHECK
REPORT=ROOT/"docs/qa/registry-required" if REGISTRY_REQUIRED else ROOT/"docs/qa/registry-activation" if REGISTRY_CHECK else ROOT/"docs/qa/performance" if BENCHMARK else ROOT/"docs/qa"

class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b"220 local QA capture\r\n"); self.wfile.flush()
        while line:=self.rfile.readline():
            command=line.decode("ascii","replace").strip().upper()
            if command.startswith("DATA"):
                self.wfile.write(b"354 end with dot\r\n"); self.wfile.flush()
                data=b""
                while (line:=self.rfile.readline()) and line!=b".\r\n": data+=line
                msg=email.message_from_bytes(data)
                body="\n".join(p.get_payload(decode=True).decode("utf8","replace") for p in msg.walk() if p.get_content_type()=="text/plain")
                self.server.messages.append({"to":msg["To"],"subject":msg["Subject"],"body":body})
                (self.server.folder/"mail.json").write_text(json.dumps(self.server.messages),encoding="utf8")
                self.wfile.write(b"250 captured\r\n")
            elif command.startswith("EHLO"): self.wfile.write(b"250-localhost\r\n250 SIZE 1000000\r\n")
            elif command.startswith("QUIT"): self.wfile.write(b"221 bye\r\n"); self.wfile.flush(); break
            else: self.wfile.write(b"250 OK\r\n")
            self.wfile.flush()

def run():
    REPORT.mkdir(parents=True,exist_ok=True)
    if (REPORT/"results.json").exists():
        (REPORT/("attempt-"+str(time.time_ns())+".json")).write_bytes((REPORT/"results.json").read_bytes())
    folder=Path(tempfile.mkdtemp(prefix="light-qa-",dir=os.environ["LOCALAPPDATA"])).resolve()
    import getpass
    subprocess.run(["icacls",str(folder),"/inheritance:r","/grant:r",getpass.getuser()+":(OI)(CI)F"],capture_output=True,check=True)
    (REPORT/"last-run.txt").write_text(str(folder),encoding="utf8")
    results=[]; apache=None; smtp=None; nlp=None
    def command(label,args,env=None,timeout=180):
        began=time.monotonic()
        try:
            r=subprocess.run(args,cwd=ROOT,env=env,capture_output=True,text=True,encoding="utf8",errors="replace",timeout=timeout)
            result={"name":label,"exit":r.returncode,"seconds":round(time.monotonic()-began,2),"output":r.stdout,"stderr":r.stderr}
        except subprocess.TimeoutExpired:
            result={"name":label,"exit":124,"seconds":round(time.monotonic()-began,2),"output":"Timed out"}
        results.append(result); (REPORT/"results.json").write_text(json.dumps(results,indent=2),encoding="utf8")
        print(("PASS " if result["exit"]==0 else "FAIL ")+label,flush=True)
        return result
    setup=command("isolated setup (existing verified rollback retained)" if ISOLATED_ONLY else "isolated setup and verified rollback", [PHP,"scripts/qa/control.php","setup-benchmark" if ISOLATED_ONLY else "setup",str(folder)],timeout=120)
    if setup["exit"]: return 1
    env=os.environ.copy();env.update(json.loads((folder/"config.json").read_text())["env"])
    env["QA_PRIVATE_DIR"]=str(folder)
    env["LIGHT_TEST_URL"]="http://127.0.0.1:58139/"
    env["QA_REGISTRY_REPORT"]=str(REPORT)
    try:
        if "--backup-only" in sys.argv:
            return 0
        if not REGISTRY_CHECK:
            nlp_log=(folder/"nlp.log").open("wb")
            nlp=subprocess.Popen([str(ROOT/".venv-nlp/Scripts/python.exe"),"python-engine/worker_daemon.py","--port","58149","--deadline","300"],cwd=ROOT,env=env,stdout=nlp_log,stderr=nlp_log)
            time.sleep(1)
        command("restricted credentials deny production",[PHP,"scripts/qa/isolation.php"],env)
        if REGISTRY_CHECK:
            command("privacy contact key and processing authorization gates",[PHP,"scripts/qa/test_privacy_gate.php"],env)
            if REGISTRY_REQUIRED:
                number_env=dict(env,QA_STUDENT_ID_SAMPLES=json.dumps(['QA-CHECK-0001','QA-CHECK-0002','QA-CHECK-0003']))
                command("student-number matching diagnostic",[PHP,"scripts/student-access/check_number_registry.php","isolated"],number_env)
                command("guest quota and historical account regression",[PHP,"scripts/student-access/test_access.php","mysql"],env)
                command("registry eligibility imports races and rollback",[PHP,"scripts/student-access/test_registry.php"],env)
                command("PHP 8.3 registry compatibility",["C:/php83/php.exe","scripts/qa/registry_php83.php"],env)
                command("operator link correction ownership rollback and preservation",[PHP,"scripts/student-access/test_link_repair.php"],env)
                env["QA_LINK_REPAIR_TESTED"]="1"
        elif BENCHMARK:
            command("isolated verified benchmark account",[PHP,"scripts/qa/benchmark_fixture.php","setup"],env)
        else:
            command("privacy contact key and processing authorization gates",[PHP,"scripts/qa/test_privacy_gate.php"],env)
            command("repeatable MySQL access migration",[PHP,"tools/migrate_student_access.php","--apply"],env)
            command("replay MySQL access migration",[PHP,"tools/migrate_student_access.php","--apply"],env)
            for script in ["test_phase1_security.php","test_phase2_decoupling.php","test_phase3_admin_analytics.php","test_phase4_database_lifecycle.php","test_feedback_hardening.php","test_history_repository_boundary.php"]:
                command(script,[PHP,"scripts/"+script],env,timeout=420 if script=="test_phase2_decoupling.php" else 180)
            command("reset migration both historical schemas",[PHP,"scripts/qa/test_reset_migration.php"],env)
            command("student access and eight concurrent clients",[PHP,"scripts/student-access/test_access.php","mysql"],env)
            command("student registry imports approval races and rollback",[PHP,"scripts/student-access/test_registry.php"],env)
            command("PHP 8.3 registry compatibility",["C:/php83/php.exe","scripts/qa/registry_php83.php"],env)
        # Separate XAMPP Apache process, shared installed PHP/Python, private DB and storage.
        public=(ROOT/"app/public").as_posix()
        logs=folder.as_posix()
        conf=f'''ServerRoot "C:/xampp/apache"
Listen 127.0.0.1:58139
ServerName 127.0.0.1:58139
PidFile "{logs}/httpd.pid"
ErrorLog "{logs}/apache-error.log"
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule env_module modules/mod_env.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadModule log_config_module modules/mod_log_config.so
LoadFile "C:/xampp/php/php8ts.dll"
LoadFile "C:/xampp/php/libpq.dll"
LoadFile "C:/xampp/php/libsqlite3.dll"
LoadModule php_module "C:/xampp/php/php8apache2_4.dll"
PHPINIDir "C:/xampp/php"
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
php_admin_value session.save_path "{logs}"
php_admin_value error_log "{logs}/php-error.log"
'''
        (folder/"apache.conf").write_text(conf,encoding="utf8")
        syntax=command("isolated Apache configuration",["C:/xampp/apache/bin/httpd.exe","-t","-f",str(folder/"apache.conf")],env)
        if syntax["exit"]: return 1
        smtp=socketserver.TCPServer(("127.0.0.1",58125),SMTP);smtp.messages=[];smtp.folder=folder
        threading.Thread(target=smtp.serve_forever,daemon=True).start()
        apache=subprocess.Popen(["C:/xampp/apache/bin/httpd.exe","-f",str(folder/"apache.conf")],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        time.sleep(2)
        if REGISTRY_CHECK:
            command("registry activation real HTTP workflows",[str(ROOT/".venv-nlp/Scripts/python.exe"),"scripts/qa/check_registry_required.py" if REGISTRY_REQUIRED else "scripts/qa/check_registry_activation.py"],env,timeout=120)
        elif BENCHMARK:
            command("real HTTP summarization speed",[str(ROOT/".venv-nlp/Scripts/python.exe"),"scripts/qa/benchmark.py"],env,timeout=2100)
        else:
            command("real Apache HTTP workflows",[str(ROOT/".venv-nlp/Scripts/python.exe"),"scripts/qa/workflows.py"],env,timeout=900)
            command("browser workflows",["node","scripts/qa/browser.cjs"],env,timeout=360)
            command("registry multipart HTTP and endpoint security",[str(ROOT/".venv-nlp/Scripts/python.exe"),"scripts/qa/registry_http.py"],env,timeout=240)
            command("public exposure and runtime dependency checks",[str(ROOT/".venv-nlp/Scripts/python.exe"),"scripts/qa/security.py"],timeout=45)
    finally:
        if apache:
            subprocess.run(["taskkill","/PID",str(apache.pid),"/T","/F"],capture_output=True)
        if nlp:
            subprocess.run(["taskkill","/PID",str(nlp.pid),"/T","/F"],capture_output=True)
            nlp_log.close()
        if smtp:smtp.shutdown();smtp.server_close()
        command("original core data preservation during live activity" if ISOLATED_ONLY else "production all-table integrity",[PHP,"scripts/qa/control.php","verify",str(folder)])
        command("isolated fixture cleanup",[PHP,"scripts/qa/control.php","cleanup",str(folder)])
        for name in ["backup.json","integrity.json"]:
            if (folder/name).exists():(REPORT/name).write_bytes((folder/name).read_bytes())
        for name in ["config.json","mail.json"]:
            (folder/name).unlink(missing_ok=True)
        print("Evidence: "+str(REPORT.relative_to(ROOT)/"results.json")+"; private rollback folder retained.",flush=True)
        if any(r["exit"] for r in results): return 1
    return 1 if any(r["exit"] for r in results) else 0

if __name__=="__main__": sys.exit(run())
