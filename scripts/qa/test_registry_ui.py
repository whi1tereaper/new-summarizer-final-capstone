"""Actual page/browser layout checks with restricted synthetic MySQL data only."""
import getpass,json,os,socket,subprocess,tempfile,time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
PHP='C:/php83/php.exe'
REPORT=ROOT/'docs/qa/registry-ui'
def main():
    REPORT.mkdir(parents=True,exist_ok=True)
    folder=Path(tempfile.mkdtemp(prefix='light-registry-ui-qa-',dir=os.environ['LOCALAPPDATA'])).resolve()
    subprocess.run(['icacls',str(folder),'/inheritance:r','/grant:r',getpass.getuser()+':(OI)(CI)F'],capture_output=True,check=True)
    (REPORT/'last-run.txt').write_text(str(folder),encoding='utf8')
    process=None;checks=[]
    def check(name,ok):
        checks.append({'name':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    try:
        subprocess.run([PHP,'scripts/qa/control.php','setup-benchmark',str(folder)],cwd=ROOT,capture_output=True,check=True)
        env=os.environ.copy();env.update(json.loads((folder/'config.json').read_text())['env'])
        env.update(APP_URL='http://127.0.0.1:58141',QA_UI_SESSION_DIR=str(folder),QA_REGISTRY_UI_FIXTURE=str(folder/'fixture.json'),QA_REGISTRY_UI_REPORT=str(REPORT))
        subprocess.run([PHP,'scripts/qa/isolation.php'],cwd=ROOT,env=env,capture_output=True,check=True);check('QA database credentials are denied production access',True)
        fixture=subprocess.check_output([PHP,'scripts/qa/registry_ui_fixture.php'],cwd=ROOT,env=env)
        (folder/'fixture.json').write_bytes(fixture)
        stats=json.loads(fixture)['stats'];check('fixture uses 38 synthetic approved IDs, 8 accounts and 18 rejection events',stats=={'total':38,'active':38,'unclaimed':30,'claimed':8,'accounts':8,'rejected':18,'recent':38})
        with (folder/'php.log').open('wb') as log:
            process=subprocess.Popen([PHP,'-d','session.save_path='+str(folder),'-S','127.0.0.1:58141','-t','app/public'],cwd=ROOT,env=env,stdout=log,stderr=log)
            for _ in range(50):
                try:
                    with socket.create_connection(('127.0.0.1',58141),timeout=.2):break
                except OSError:time.sleep(.1)
            result=subprocess.run(['node','scripts/qa/registry_ui_browser.cjs'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=240)
            print(result.stdout,flush=True)
            if result.returncode:print(result.stderr,flush=True)
            check('desktop/mobile light/dark browser layout and existing access guards',result.returncode==0)
    finally:
        if process:process.terminate();process.wait(timeout=15)
        if (folder/'config.json').exists():
            verified=subprocess.run([PHP,'scripts/qa/control.php','verify',str(folder)],cwd=ROOT,capture_output=True)
            check('live account passwords summaries and feedback are preserved',verified.returncode==0)
            cleanup=subprocess.run([PHP,'scripts/qa/control.php','cleanup',str(folder)],cwd=ROOT,capture_output=True)
            check('scoped QA schemas and database credentials are removed',cleanup.returncode==0)
            if (folder/'integrity.json').exists():(REPORT/'integrity.json').write_bytes((folder/'integrity.json').read_bytes())
            if cleanup.returncode==0:
                (folder/'config.json').unlink()
                if (folder/'fixture.json').exists():(folder/'fixture.json').unlink()
        browser=json.loads((REPORT/'browser.json').read_text()) if (REPORT/'browser.json').exists() else []
        summary={'checks':checks,'passed':sum(c['passed'] for c in checks),'failed':sum(not c['passed'] for c in checks),'browser_passed':sum(c['passed'] for c in browser),'browser_failed':sum(not c['passed'] for c in browser),'data':'synthetic isolated MySQL only','authentication_note':'pre-created private QA sessions exercise layout and role guards, not sign-in/OTP'}
        (REPORT/'summary.json').write_text(json.dumps(summary,indent=2),encoding='utf8');print(json.dumps(summary),flush=True)
    return int(any(not c['passed'] for c in checks))
if __name__=='__main__':raise SystemExit(main())
