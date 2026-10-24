"""PHP 8.3/XAMPP allowlist acceptance tests with production-denied QA credentials."""
import argparse, getpass, json, os, re, socketserver, subprocess, tempfile, threading, time
from pathlib import Path
from run import SMTP
ROOT=Path(__file__).resolve().parents[2]
PHP='C:/php83/php.exe'
REPORT=ROOT/'docs/qa/allowlist-replacement'
class BackendOnlyComplete(Exception):pass

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--request-file',required=True)
    parser.add_argument('--backend-only',action='store_true')
    parser.add_argument('--report-dir',default='docs/qa/allowlist-replacement')
    args=parser.parse_args()
    global REPORT
    REPORT=(ROOT/args.report_dir).resolve()
    if not REPORT.is_relative_to((ROOT/'docs/qa').resolve()):raise RuntimeError('Reports must stay inside docs/qa')
    request=Path(args.request_file).read_text(encoding='utf-8-sig')
    match=re.search(r'```text\s*([^`]+)```',request)
    if not match:raise RuntimeError('Authoritative 38-ID input block is missing')
    ids=[line.strip() for line in match[1].splitlines() if line.strip()]
    if len(ids)!=38 or len(set(ids))!=38:raise RuntimeError('Expected exactly 38 unique initial IDs')
    REPORT.mkdir(parents=True,exist_ok=True)
    if (REPORT/'results.json').exists():(REPORT/('attempt-'+str(time.time_ns())+'.json')).write_bytes((REPORT/'results.json').read_bytes())
    folder=Path(tempfile.mkdtemp(prefix='light-allowlist-qa-',dir=os.environ['LOCALAPPDATA'])).resolve()
    subprocess.run(['icacls',str(folder),'/inheritance:r','/grant:r',getpass.getuser()+':(OI)(CI)F'],capture_output=True,check=True)
    (folder/'initial-list.json').write_text(json.dumps(ids),encoding='utf8')
    (REPORT/'last-run.txt').write_text(str(folder),encoding='utf8')
    results=[];apache=None;nlp=None;smtp=None;nlp_log=None;offline=None;offline_log=None
    def step(name,command,env=None,timeout=120):
        began=time.monotonic()
        try:
            completed=subprocess.run(command,cwd=ROOT,env=env,capture_output=True,text=True,encoding='utf8',errors='replace',timeout=timeout)
            result={'name':name,'exit':completed.returncode,'seconds':round(time.monotonic()-began,2),'output':completed.stdout,'stderr':completed.stderr}
        except subprocess.TimeoutExpired:result={'name':name,'exit':124,'seconds':round(time.monotonic()-began,2),'output':'Timed out'}
        results.append(result);(REPORT/'results.json').write_text(json.dumps(results,indent=2),encoding='utf8')
        print(('PASS ' if result['exit']==0 else 'FAIL ')+name,flush=True)
        return result['exit']==0
    if not step('isolated MySQL schema 001-020 and preserved live baseline',[PHP,'scripts/qa/control.php','setup-benchmark',str(folder)]):return 1
    env=os.environ.copy();env.update(json.loads((folder/'config.json').read_text())['env'])
    env.update(QA_PRIVATE_DIR=str(folder),QA_INITIAL_LIST_FILE=str(folder/'initial-list.json'),QA_REGISTRY_REPORT=str(REPORT),LIGHT_TEST_URL='http://127.0.0.1:58139/')
    try:
        step('restricted credentials deny production',[PHP,'scripts/qa/isolation.php'],env)
        if not step('38-ID registry-only backend, reconciliation and race acceptance',[PHP,'scripts/student-access/test_registry_only.php'],env,180):return 1
        if args.backend_only:raise BackendOnlyComplete()
        nlp_log=(folder/'nlp.log').open('wb')
        nlp=subprocess.Popen([str(ROOT/'.venv-nlp/Scripts/python.exe'),'python-engine/worker_daemon.py','--port','58149','--deadline','600'],cwd=ROOT,env=env,stdout=nlp_log,stderr=nlp_log)
        public=(ROOT/'app/public').as_posix();private=folder.as_posix()
        conf=f'''ServerRoot "C:/xampp/apache"
Listen 127.0.0.1:58139
ServerName 127.0.0.1:58139
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
LoadModule log_config_module modules/mod_log_config.so
LoadFile "C:/php83/php8ts.dll"
LoadFile "C:/php83/libcrypto-3-x64.dll"
LoadFile "C:/php83/libssl-3-x64.dll"
LoadModule php_module "C:/php83/php8apache2_4.dll"
PHPINIDir "C:/php83"
DocumentRoot "{public}"
Alias "/__allowlist_runtime" "{(ROOT/'scripts/qa/allowlist_runtime.php').as_posix()}"
<Directory "{(ROOT/'scripts/qa').as_posix()}">
 Require local
 AllowOverride None
</Directory>
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
        (folder/'apache.conf').write_text(conf,encoding='utf8')
        if not step('installed PHP 8.3 Apache module configuration',['C:/xampp/apache/bin/httpd.exe','-t','-f',str(folder/'apache.conf')],env):return 1
        smtp=socketserver.TCPServer(('127.0.0.1',58125),SMTP);smtp.messages=[];smtp.folder=folder
        threading.Thread(target=smtp.serve_forever,daemon=True).start()
        apache=subprocess.Popen(['C:/xampp/apache/bin/httpd.exe','-f',str(folder/'apache.conf')],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        offline_env=env.copy();offline_env['DB_PORT']='1'
        offline_log=(folder/'offline-http.log').open('wb')
        offline=subprocess.Popen([PHP,'-d','session.save_path='+str(folder),'-S','127.0.0.1:58140','-t',str(ROOT/'app/public')],cwd=ROOT,env=offline_env,stdout=offline_log,stderr=offline_log)
        time.sleep(2)
        step('PHP 8.3 real HTTP registration, login, admin imports and authorization',[str(ROOT/'.venv-nlp/Scripts/python.exe'),'scripts/qa/allowlist_http.py'],env,180)
        step('required-email registration, safe errors and database failure HTTP acceptance',[str(ROOT/'.venv-nlp/Scripts/python.exe'),'scripts/qa/registration_http.py'],env,180)
        step('mobile and desktop browser acceptance',['node','scripts/qa/allowlist_browser.cjs'],env,180)
        step('existing PHP core security and data regression',[PHP,'scripts/qa/allowlist_core.php'],env,600)
        step('real Python NLP and MySQL summary persistence',[str(ROOT/'.venv-nlp/Scripts/python.exe'),'scripts/qa/allowlist_nlp.py'],env,900)
    except BackendOnlyComplete:pass
    finally:
        if apache:subprocess.run(['taskkill','/PID',str(apache.pid),'/T','/F'],capture_output=True)
        if nlp:subprocess.run(['taskkill','/PID',str(nlp.pid),'/T','/F'],capture_output=True)
        if offline:subprocess.run(['taskkill','/PID',str(offline.pid),'/T','/F'],capture_output=True)
        if nlp_log:nlp_log.close()
        if offline_log:offline_log.close()
        if smtp:smtp.shutdown();smtp.server_close()
        step('original live users summaries and feedback preserved',[PHP,'scripts/qa/control.php','verify',str(folder)])
        cleaned=step('isolated databases and credentials cleaned up',[PHP,'scripts/qa/control.php','cleanup',str(folder)])
        if (folder/'integrity.json').exists():(REPORT/'integrity.json').write_bytes((folder/'integrity.json').read_bytes())
        # Keep private cleanup credentials only if a service outage prevented cleanup.
        # The operator can retry control.php cleanup against this exact run folder.
        for filename in ['mail.json','initial-list.json']+(['config.json'] if cleaned else []):(folder/filename).unlink(missing_ok=True)
    return 1 if any(row['exit'] for row in results) else 0

if __name__=='__main__':raise SystemExit(main())
