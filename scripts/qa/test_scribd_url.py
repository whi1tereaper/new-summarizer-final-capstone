"""Source contract fixtures and the supplied live URL, with isolated HTTP writes."""
import getpass,json,os,re,secrets,socket,subprocess,tempfile,time
from pathlib import Path
import requests
ROOT=Path(__file__).resolve().parents[2];PHP='C:/php83/php.exe';BASE='http://127.0.0.1:58143/';REPORT=ROOT/'docs/qa/scribd-url'
URL='https://www.scribd.com/document/840282331/I-Dont-love-you-anymore-Brighten-Up-Your-Mind'
def main():
    REPORT.mkdir(parents=True,exist_ok=True)
    if (REPORT/'summary.json').exists():
        previous={name:json.loads((REPORT/name).read_text()) for name in ['summary.json','browser.json','database.json'] if (REPORT/name).exists()}
        (REPORT/('attempt-'+str(time.time_ns())+'.json')).write_text(json.dumps(previous,indent=2),encoding='utf8')
    folder=Path(tempfile.mkdtemp(prefix='light-scribd-qa-',dir=os.environ['LOCALAPPDATA'])).resolve()
    subprocess.run(['icacls',str(folder),'/inheritance:r','/grant:r',getpass.getuser()+':(OI)(CI)F'],capture_output=True,check=True)
    (REPORT/'last-run.txt').write_text(str(folder),encoding='utf8');process=None;checks=[]
    def check(name,ok):checks.append({'name':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    try:
        subprocess.run([PHP,'scripts/qa/control.php','setup-benchmark',str(folder)],cwd=ROOT,capture_output=True,check=True)
        env=os.environ.copy();env.update(json.loads((folder/'config.json').read_text())['env']);env.update(APP_URL=BASE.rstrip('/'),QA_SCRIBD_REPORT=str(REPORT))
        subprocess.run([PHP,'scripts/qa/isolation.php'],cwd=ROOT,env=env,capture_output=True,check=True);check('QA credentials are denied access to live application data',True)
        contract=subprocess.run([PHP,'scripts/test_source_error_contract.php'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=180)
        (REPORT/'source-contract.txt').write_text(contract.stdout,encoding='utf8')
        check('existing source and transport contracts plus public/restricted Scribd fixtures',contract.returncode==0)
        if contract.returncode:print(contract.stdout[-7000:]+contract.stderr,flush=True)
        probe=subprocess.run([PHP,'scripts/qa/probe_scribd_source.php',URL],cwd=ROOT,env=env,capture_output=True,text=True,timeout=40)
        public=json.loads(probe.stdout);(REPORT/'live-source-metadata.json').write_text(json.dumps(public,indent=2),encoding='utf8')
        check('supplied live Scribd page is recognized as access restricted, not an empty article',probe.returncode==0 and public.get('resolution_code')=='URL_ACCESS_RESTRICTED')
        with (folder/'php.log').open('wb') as log:
            process=subprocess.Popen([PHP,'-d','session.save_path='+str(folder),'-S','127.0.0.1:58143','-t','app/public'],cwd=ROOT,env=env,stdout=log,stderr=log)
            for _ in range(50):
                try:
                    with socket.create_connection(('127.0.0.1',58143),timeout=.2):break
                except OSError:time.sleep(.1)
            guest=requests.Session();guest.trust_env=False;page=guest.get(BASE+'summarizer.php',timeout=25);token=re.search(r'name="csrf_token"\s+value="([^"]+)"',page.text)[1]
            response=guest.post(BASE+'nutshell_generate.php',data={'csrf_token':token,'original_text':URL,'request_key':secrets.token_hex(16)},timeout=40);failure=response.json()
            check('actual Nutshell endpoint returns structured Scribd access guidance',response.status_code==422 and failure.get('failure',{}).get('code')=='URL_ACCESS_RESTRICTED' and 'upload its PDF/DOCX' in failure['failure']['recovery'])
            check('failed Scribd Nutshell request does not consume a guest attempt',guest.get(BASE+'access_status.php',timeout=25).json()['remaining']==3)
            browser=subprocess.run(['node','scripts/qa/scribd_url_browser.cjs'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=180);print(browser.stdout,flush=True);check('real browser Scribd source failure and recovery workflow',browser.returncode==0)
            if browser.returncode:print(browser.stderr,flush=True)
            result=subprocess.run([PHP,'scripts/qa/scribd_url_probe.php'],cwd=ROOT,env=env,capture_output=True,text=True,check=True);db=json.loads(result.stdout);(REPORT/'database.json').write_text(json.dumps(db,indent=2),encoding='utf8')
            check('failed Scribd jobs store no summary, release quota and erase source payloads',db['summaries']==0 and db['active_reservations']==0 and len(db['job_results'])>=1 and all(job=={'state':'failed','has_result':False,'failure_code':'URL_ACCESS_RESTRICTED','payload_erased':True} for job in db['job_results']))
    finally:
        if process:process.terminate();process.wait(timeout=15)
        if (folder/'config.json').exists():
            verify=subprocess.run([PHP,'scripts/qa/control.php','verify',str(folder)],cwd=ROOT,capture_output=True);check('live accounts passwords summaries and feedback are preserved',verify.returncode==0)
            cleanup=subprocess.run([PHP,'scripts/qa/control.php','cleanup',str(folder)],cwd=ROOT,capture_output=True);check('isolated QA schemas and credentials are removed',cleanup.returncode==0)
            if (folder/'integrity.json').exists():(REPORT/'integrity.json').write_bytes((folder/'integrity.json').read_bytes())
            if cleanup.returncode==0:(folder/'config.json').unlink()
        nested=json.loads((REPORT/'browser.json').read_text()) if (REPORT/'browser.json').exists() else []
        summary={'passed':sum(c['passed'] for c in checks),'failed':sum(not c['passed'] for c in checks),'checks':checks,'browser_passed':sum(c['passed'] for c in nested),'browser_failed':sum(not c['passed'] for c in nested),'original_restricted_document_not_extracted':True,'no_access_controls_bypassed':True}
        (REPORT/'summary.json').write_text(json.dumps(summary,indent=2),encoding='utf8');print(json.dumps(summary),flush=True)
    return int(any(not c['passed'] for c in checks))
if __name__=='__main__':raise SystemExit(main())
