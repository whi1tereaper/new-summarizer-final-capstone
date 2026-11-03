"""Read/write HTTP audit checks against restricted isolated MySQL only."""
import getpass,json,os,re,socket,subprocess,tempfile,time
from pathlib import Path
import requests
ROOT=Path(__file__).resolve().parents[2];PHP='C:/php83/php.exe';BASE='http://127.0.0.1:58142/';REPORT=ROOT/'docs/qa/audit-trail'
def main():
    REPORT.mkdir(parents=True,exist_ok=True)
    if (REPORT/'summary.json').exists():
        prior={name:json.loads((REPORT/name).read_text()) for name in ['summary.json','checks.json','browser.json','service.json'] if (REPORT/name).exists()}
        (REPORT/('attempt-'+str(time.time_ns())+'.json')).write_text(json.dumps(prior,indent=2),encoding='utf8')
    folder=Path(tempfile.mkdtemp(prefix='light-audit-qa-',dir=os.environ['LOCALAPPDATA'])).resolve()
    subprocess.run(['icacls',str(folder),'/inheritance:r','/grant:r',getpass.getuser()+':(OI)(CI)F'],capture_output=True,check=True)
    (REPORT/'last-run.txt').write_text(str(folder),encoding='utf8');process=None;env=None;checks=[]
    def check(name,ok):
        checks.append({'name':name,'passed':bool(ok)});(REPORT/'checks.json').write_text(json.dumps(checks,indent=2),encoding='utf8');print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    def php(*args):return subprocess.check_output([PHP,*map(str,args)],cwd=ROOT,env=env,text=True,encoding='utf8')
    def probe(*args):return json.loads(php('scripts/qa/audit_probe.php',*args))
    def session(cookie=None):
        s=requests.Session();s.trust_env=False
        if cookie:s.cookies.set('LIGHT_QA_SESSID',cookie)
        return s
    def token(s):return re.search(r'name="csrf_token"\s+value="([^"]+)"',s.get(BASE+'admin_dashboard.php',timeout=30).text)[1]
    def mutate(s,action,target,**extra):
        return s.post(BASE+'admin_dashboard.php',data={'action':action,'user_id':target,'csrf_token':token(s),**extra},allow_redirects=False,timeout=30)
    try:
        subprocess.run([PHP,'scripts/qa/control.php','setup-benchmark',str(folder)],cwd=ROOT,capture_output=True,check=True)
        env=os.environ.copy();env.update(json.loads((folder/'config.json').read_text())['env']);env.update(APP_URL=BASE.rstrip('/'),QA_UI_SESSION_DIR=str(folder),QA_AUDIT_FIXTURE=str(folder/'fixture.json'),QA_AUDIT_REPORT=str(REPORT),QA_REGISTRY_REPORT=str(REPORT))
        php('scripts/qa/isolation.php');check('test credentials are denied production access',True)
        fixture=json.loads(php('scripts/qa/registry_ui_fixture.php'));(folder/'fixture.json').write_text(json.dumps(fixture),encoding='utf8');ids=probe('seed')
        with (folder/'php.log').open('wb') as log:
            process=subprocess.Popen([PHP,'-d','session.save_path='+str(folder),'-S','127.0.0.1:58142','-t','app/public'],cwd=ROOT,env=env,stdout=log,stderr=log)
            for _ in range(50):
                try:
                    with socket.create_connection(('127.0.0.1',58142),timeout=.2):break
                except OSError:time.sleep(.1)
            admin=session(fixture['sessions']['admin']);user=session(fixture['sessions']['user']);guest=session()
            for name,s in [('anonymous',guest),('ordinary user',user)]:
                denied=s.get(BASE+'admin_audit_logs.php',allow_redirects=False,timeout=25);check(name+' cannot read the unified audit trail',denied.status_code==302 and 'login.php' in denied.headers.get('Location',''))
            before=probe('snapshot');page=admin.get(BASE+'admin_audit_logs.php',timeout=25)
            check('administrator reads audit events with no-store and no-referrer',page.status_code==200 and page.headers.get('Cache-Control')=='no-store' and page.headers.get('Referrer-Policy')=='no-referrer')
            check('the audit GET does not modify or copy ledger records',probe('snapshot')==before)
            check('audit viewer rejects POST without changing ledger records',admin.post(BASE+'admin_audit_logs.php',timeout=25).status_code==405 and probe('snapshot')==before)
            for bad in ['source[]=admin','source=unknown','admin_id=-1','date_from=2026-02-30','date_from=2026-10-24&date_to=2026-10-23','page=999999999999999999999999']:
                r=admin.get(BASE+'admin_audit_logs.php?'+bad,timeout=25);check('invalid HTTP filter '+bad+' is safely rejected',r.status_code==400 and 'role="alert"' in r.text and 'SQLSTATE' not in r.text)
            check('Student ID registry HMACs do not appear in the viewer',('b'*64) not in admin.get(BASE+'admin_audit_logs.php?source=registry',timeout=25).text)
            response=mutate(admin,'toggle_status',ids['toggle'],status='0');data=probe('user',ids['toggle'])
            check('actual HTTP deactivation toggle writes a distinct actor/target transition event',response.status_code==302 and int(data['active'])==0 and data['events'][-1]=={'action':'admin_user_deactivated','details':'Account status: active -> inactive.'})
            previous=data['events'];mutate(admin,'toggle_status',ids['toggle'],status='0');check('repeated no-op toggle does not fabricate another change',probe('user',ids['toggle'])['events']==previous)
            mutate(admin,'toggle_status',ids['toggle'],status='1');data=probe('user',ids['toggle']);check('actual HTTP activation records the opposite transition',int(data['active'])==1 and data['events'][-1]['action']=='admin_user_activated')
            mutate(admin,'deactivate_user',ids['deactivate']);data=probe('user',ids['deactivate']);check('explicit deactivation no longer uses the deletion event code',int(data['active'])==0 and data['events'][-1]['action']=='admin_user_deactivated')
            previous=data['events'];mutate(admin,'deactivate_user',ids['deactivate']);check('repeated explicit deactivation is not recorded as another change',probe('user',ids['deactivate'])['events']==previous)
            mutate(admin,'delete_user',ids['delete']);data=probe('user',ids['delete']);check('actual HTTP deletion retains its own explicit audit event',data['active'] is False and data['events'][-1]['action']=='admin_user_deleted')
            before=probe('user',ids['fault']);probe('fault-on')
            try:
                for action,extra in [('toggle_status',{'status':'0'}),('deactivate_user',{}),('delete_user',{})]:
                    mutate(admin,action,ids['fault'],**extra);check(action+' rolls back account and summary relationships when audit persistence fails',probe('user',ids['fault'])==before)
            finally:probe('fault-off')
            before=probe('user',ids['toggle']);response=admin.post(BASE+'admin_dashboard.php',data={'action':'toggle_status','user_id':ids['toggle'],'status':'0','csrf_token':'invalid'},timeout=25)
            denied=probe('latest','admin_action_denied');check('invalid CSRF blocks mutation and records a bounded denial event',response.status_code==403 and probe('user',ids['toggle'])==before and denied['details']=='Invalid CSRF token for action: toggle_status')
            response=admin.post(BASE+'admin_dashboard.php',data={'action':'NeverLogRawRequestSecrets','csrf_token':'invalid'},timeout=25);check('denial logging excludes arbitrary request action text',response.status_code==403 and probe('latest','admin_action_denied')['details']=='Invalid CSRF token for action: unsupported_action')
            check('malformed action/CSRF arrays do not produce fatal errors',admin.post(BASE+'admin_dashboard.php',data={'action[]':'toggle_status'},timeout=25).status_code==400 and admin.post(BASE+'admin_dashboard.php',data={'action':'toggle_status','csrf_token[]':'invalid'},timeout=25).status_code==403)
            core_service=subprocess.run([PHP,'scripts/qa/audit_probe.php','core'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=60);print(core_service.stdout,flush=True);check('service filtering authorization metadata timezone and partial-ledger contracts',core_service.returncode==0)
            if core_service.returncode:print(core_service.stderr,flush=True)
            browser=subprocess.run(['node','scripts/qa/audit_browser.cjs'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=240);print(browser.stdout,flush=True);check('real browser filters pagination escaping legacy labels and responsive themes',browser.returncode==0)
            if browser.returncode:print(browser.stderr,flush=True)
            core=subprocess.run([PHP,'scripts/qa/allowlist_core.php'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=600);print(core.stdout,flush=True);check('existing security analytics lifecycle feedback and history regressions',core.returncode==0)
    finally:
        if process:process.terminate();process.wait(timeout=15)
        if (folder/'config.json').exists():
            verified=subprocess.run([PHP,'scripts/qa/control.php','verify',str(folder)],cwd=ROOT,capture_output=True);check('live passwords accounts summaries and feedback preserved',verified.returncode==0)
            cleanup=subprocess.run([PHP,'scripts/qa/control.php','cleanup',str(folder)],cwd=ROOT,capture_output=True);check('isolated QA schemas and credentials removed',cleanup.returncode==0)
            if (folder/'integrity.json').exists():(REPORT/'integrity.json').write_bytes((folder/'integrity.json').read_bytes())
            if cleanup.returncode==0:
                (folder/'config.json').unlink()
                if (folder/'fixture.json').exists():(folder/'fixture.json').unlink()
        nested={}
        for name in ['service','browser']:
            cases=json.loads((REPORT/(name+'.json')).read_text()) if (REPORT/(name+'.json')).exists() else []
            nested[name]={'passed':sum(c['passed'] for c in cases),'failed':sum(not c['passed'] for c in cases)}
        summary={'passed':sum(c['passed'] for c in checks),'failed':sum(not c['passed'] for c in checks),'nested':nested,'live_migrations_applied':False,'authentication_scope':'private QA sessions for protected HTTP workflows; login/OTP not re-tested'}
        (REPORT/'summary.json').write_text(json.dumps(summary,indent=2),encoding='utf8');print(json.dumps(summary),flush=True)
    return int(any(not c['passed'] for c in checks))
if __name__=='__main__':raise SystemExit(main())
