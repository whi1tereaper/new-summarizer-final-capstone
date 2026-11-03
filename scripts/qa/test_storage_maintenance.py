"""Storage status tests on restricted isolated MySQL and private synthetic files only."""
import getpass,json,os,socket,subprocess,tempfile,time
from pathlib import Path
import requests
ROOT=Path(__file__).resolve().parents[2];PHP='C:/php83/php.exe';BASE='http://127.0.0.1:58144/';REPORT=ROOT/'docs/qa/storage-maintenance'

def main():
    REPORT.mkdir(parents=True,exist_ok=True)
    if (REPORT/'summary.json').exists():
        prior={n:json.loads((REPORT/n).read_text()) for n in ['summary.json','checks.json','browser.json','service.json'] if (REPORT/n).exists()}
        (REPORT/('attempt-'+str(time.time_ns())+'.json')).write_text(json.dumps(prior,indent=2),encoding='utf8')
    for name in ['service.json','browser.json']:
        if (REPORT/name).exists():(REPORT/name).unlink()
    folder=Path(tempfile.mkdtemp(prefix='light-storage-qa-',dir=os.environ['LOCALAPPDATA'])).resolve()
    subprocess.run(['icacls',str(folder),'/inheritance:r','/grant:r',getpass.getuser()+':(OI)(CI)F'],capture_output=True,check=True)
    (REPORT/'last-run.txt').write_text(str(folder),encoding='utf8');process=None;env=None;checks=[]
    def check(name,ok):
        checks.append({'name':name,'passed':bool(ok)});(REPORT/'checks.json').write_text(json.dumps(checks,indent=2),encoding='utf8');print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    def php(*args):return subprocess.check_output([PHP,*map(str,args)],cwd=ROOT,env=env,text=True,encoding='utf8')
    def probe(*args):return json.loads(php('scripts/qa/storage_probe.php',*args))
    def session(cookie=None):
        s=requests.Session();s.trust_env=False
        if cookie:s.cookies.set('LIGHT_QA_SESSID',cookie)
        return s
    try:
        subprocess.run([PHP,'scripts/qa/control.php','setup-benchmark',str(folder)],cwd=ROOT,capture_output=True,check=True)
        env=os.environ.copy();env.update(json.loads((folder/'config.json').read_text())['env']);env.update(APP_URL=BASE.rstrip('/'),QA_UI_SESSION_DIR=str(folder),QA_STORAGE_PRIVATE=str(folder),QA_STORAGE_FIXTURE=str(folder/'fixture.json'),QA_STORAGE_REPORT=str(REPORT),QA_REGISTRY_REPORT=str(REPORT))
        php('scripts/qa/isolation.php');check('QA credentials cannot access production MySQL',True)
        fixture=json.loads(php('scripts/qa/registry_ui_fixture.php'));(folder/'fixture.json').write_text(json.dumps(fixture),encoding='utf8');probe('seed')
        core_service=subprocess.run([PHP,'scripts/qa/storage_probe.php','core'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=60);print(core_service.stdout,flush=True);check('service metrics authorization safe path and partial measurement contracts',core_service.returncode==0)
        if core_service.returncode:print(core_service.stderr,flush=True)
        with (folder/'php.log').open('wb') as log:
            process=subprocess.Popen([PHP,'-d','session.save_path='+str(folder),'-S','127.0.0.1:58144','-t','app/public'],cwd=ROOT,env=env,stdout=log,stderr=log)
            for _ in range(50):
                try:
                    with socket.create_connection(('127.0.0.1',58144),timeout=.2):break
                except OSError:time.sleep(.1)
            admin=session(fixture['sessions']['admin']);user=session(fixture['sessions']['user']);guest=session()
            for label,s in [('anonymous',guest),('ordinary user',user)]:
                denied=s.get(BASE+'admin_storage_status.php',timeout=25);check(label+' cannot access storage measurements',denied.status_code==403 and denied.json()['success'] is False and 'snapshot' not in denied.json())
            # Warm normal application session bootstrap before comparing read-only measurements.
            first=admin.get(BASE+'admin_storage_status.php',timeout=25)
            check('authenticated endpoint connects to actual MySQL and storage',first.status_code==200 and first.json()['snapshot']['database']['saved_summaries']==2 and first.json()['snapshot']['runtime']['folders']['uploads']['bytes']==13)
            before=probe('snapshot');r=admin.get(BASE+'admin_storage_status.php',timeout=25)
            check('GET measurement does not mutate existing accounts documents jobs or runtime files',before==probe('snapshot'))
            check('sensitive status responses are JSON no-store and no-referrer',r.headers.get('Cache-Control')=='no-store' and r.headers.get('Referrer-Policy')=='no-referrer' and r.headers.get('X-Content-Type-Options')=='nosniff' and 'application/json' in r.headers.get('Content-Type',''))
            head=admin.head(BASE+'admin_storage_status.php',timeout=25);check('HEAD supports status checks without returning a body',head.status_code==200 and head.content==b'')
            rejected=admin.post(BASE+'admin_storage_status.php',data={'action':'delete_files'},timeout=25);check('POST cannot turn read-only refresh into file deletion',rejected.status_code==405 and rejected.headers.get('Allow')=='GET, HEAD' and before==probe('snapshot'))
            text=r.text;check('JSON exposes neither absolute paths nor document filenames or contents',str(folder) not in text and 'source.pdf' not in text and 'Synthetic source' not in text and 'DB_PASSWORD' not in text)
            dashboard=admin.get(BASE+'admin_dashboard.php',timeout=25)
            check('existing admin dashboard renders the connected partial and assets',dashboard.status_code==200 and 'id="storage-maintenance"' in dashboard.text and admin.get(BASE+'assets/css/admin-storage.css').status_code==200 and admin.get(BASE+'assets/js/admin-storage.js').status_code==200 and 'Fatal error' not in dashboard.text)
            browser=subprocess.run(['node','scripts/qa/storage_browser.cjs'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=240);print(browser.stdout,flush=True);check('real browser refresh failure handling keyboard and responsive themes',browser.returncode==0)
            if browser.returncode:print(browser.stderr,flush=True)
            probe('admin-active','0')
            try:check('stale administrator cookie cannot bypass live active-account enforcement',admin.get(BASE+'admin_storage_status.php',timeout=25).status_code==403)
            finally:probe('admin-active','1')
            core=subprocess.run([PHP,'scripts/qa/allowlist_core.php'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=600);print(core.stdout,flush=True);check('security analytics lifecycle feedback and history regressions',core.returncode==0)
            if core.returncode:print(core.stderr,flush=True)
    except Exception as error:
        check('test execution completed without harness errors: '+type(error).__name__,False)
        raise
    finally:
        if process:process.terminate();process.wait(timeout=15)
        if (folder/'config.json').exists():
            verified=subprocess.run([PHP,'scripts/qa/control.php','verify',str(folder)],cwd=ROOT,capture_output=True);check('live accounts passwords summaries and feedback preserved',verified.returncode==0)
            cleanup=subprocess.run([PHP,'scripts/qa/control.php','cleanup',str(folder)],cwd=ROOT,capture_output=True);check('restricted QA databases and credentials removed',cleanup.returncode==0)
            if (folder/'integrity.json').exists():(REPORT/'integrity.json').write_bytes((folder/'integrity.json').read_bytes())
            if cleanup.returncode==0:
                (folder/'config.json').unlink()
                if (folder/'fixture.json').exists():(folder/'fixture.json').unlink()
        nested={}
        for name in ['service','browser']:
            cases=json.loads((REPORT/(name+'.json')).read_text()) if (REPORT/(name+'.json')).exists() else []
            nested[name]={'passed':sum(c['passed'] for c in cases),'failed':sum(not c['passed'] for c in cases)}
        summary={'passed':sum(c['passed'] for c in checks),'failed':sum(not c['passed'] for c in checks),'nested':nested,'live_migrations_applied':False,'live_cleanup_executed':False,'http_server':'isolated PHP 8.3 server; not live Apache authenticated session'}
        (REPORT/'summary.json').write_text(json.dumps(summary,indent=2),encoding='utf8');print(json.dumps(summary),flush=True)
    return int(any(not c['passed'] for c in checks))
if __name__=='__main__':raise SystemExit(main())
