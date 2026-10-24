"""Real NLP regression through approved-account HTTP and persisted MySQL data."""
import json, os, re, secrets, subprocess, sys, time
from pathlib import Path
import requests
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'scripts'))
from test_runtime_repair import SOURCE
BASE='http://127.0.0.1:58139/';REPORT=Path(os.environ['QA_REGISTRY_REPORT'])/'nlp-results.json';checks=[]
def check(name,ok,**detail):
    checks.append({'name':name,'passed':bool(ok),**detail});REPORT.write_text(json.dumps(checks,indent=2),encoding='utf8');print(('PASS ' if ok else 'FAIL ')+name,flush=True)
def token(session,page='summarizer.php'):
    response=session.get(BASE+page,timeout=30);return re.search(r'name="csrf_token"\s+value="([^"]+)"',response.text)[1]
try:
    ids=json.loads(Path(os.environ['QA_INITIAL_LIST_FILE']).read_text());session=requests.Session()
    auth=session.post(BASE+'auth.php?action=login',data={'csrf_token':token(session,'login.php'),'username':ids[0],'password':'QaStrong123!'},allow_redirects=False,timeout=30)
    check('approved initial student authenticates for real NLP',auth.headers.get('Location')=='index.php')
    fields={'csrf_token':token(session),'source_type':'text','original_text':SOURCE,'document_title':'QA registry-only real summary '+secrets.token_hex(4),'analysis_mode':'general','output_format':'paragraph','summary_depth':'balanced','request_token':secrets.token_hex(16)}
    started=time.monotonic();response=session.post(BASE+'summarize.php',data=fields,headers={'Accept':'application/json'},timeout=60);payload=response.json()
    check('real backend accepts an authorized summarization job',payload.get('success') is True)
    job=payload['job_id'];session.post(BASE+'summarize.php?action=execute',data={'csrf_token':fields['csrf_token'],'job_id':job},timeout=60)
    deadline=time.monotonic()+660
    while time.monotonic()<deadline:
        state=session.get(BASE+'summarize.php',params={'action':'status','job_id':job},timeout=30).json()
        if state.get('status') not in ('queued','processing'):break
        time.sleep(.5)
    check('existing Python engine completes real summarization',state.get('status')=='completed',seconds=round(time.monotonic()-started,2),status=state.get('status'))
    if state.get('status')!='completed':raise RuntimeError('Real NLP job did not complete')
    result=session.get(BASE+state['redirect'],timeout=30);summary_id=re.search(r'id=(\d+)',state['redirect'])[1]
    check('persisted summary result renders through the existing UI',result.status_code==200 and fields['document_title'] in result.text)
    record=json.loads(subprocess.check_output(['C:/php83/php.exe','scripts/qa/allowlist_probe.php','summary',summary_id],cwd=ROOT,text=True))
    owner=json.loads(subprocess.check_output(['C:/php83/php.exe','scripts/qa/allowlist_probe.php','inspect',ids[0]],cwd=ROOT,text=True))['record']['user_id']
    check('MySQL summary belongs to the same approved student',int(record['user_id'])==int(owner) and record['article_title']==fields['document_title'] and int(record['summary_word_count'])>0)
    history=session.get(BASE+'history.php',timeout=30)
    check('existing summary history retrieves the new result',history.status_code==200 and fields['document_title'] in history.text)
except Exception as error:check('real NLP regression exception: '+type(error).__name__,False)
raise SystemExit(1 if any(not item['passed'] for item in checks) else 0)
