"""Sequential real HTTP timing; no mocks, model changes or production writes."""
import datetime, importlib.util, json, os, re, secrets, statistics, subprocess, sys, time
from pathlib import Path
import requests
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts'))
from test_runtime_repair import SOURCE
BASE='http://127.0.0.1:58139/'
PRIVATE=Path(os.environ['QA_PRIVATE_DIR'])
REPORT=ROOT/'docs/qa/performance/speed.json'
results=[]
def save(running=None):
    REPORT.write_text(json.dumps({'measured_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'settings':{'analysis_mode':'general','output_format':'paragraph','summary_depth':'balanced'},'method':'Sequential HTTP submit through persisted result; fresh dedicated NLP worker for first text run. Polling interval 0.2 seconds. Fixture creation and login excluded.','running':running,'results':results},indent=2),encoding='utf8')
def token(session,page='summarizer.php'):
    response=session.get(BASE+page,timeout=30)
    found=re.search(r'name="csrf_token"\s+value="([^"]+)"',response.text)
    if not found:raise RuntimeError('No CSRF token for benchmark account')
    return found[1]
def run_case(session,kind,run):
    row={'kind':kind,'run':run,'worker_state':'fresh worker / first request' if kind=='text' and run==1 else 'reused worker','passed':False}
    fields={'csrf_token':token(session),'source_type':kind,'original_text':SOURCE if kind=='text' else '', 'document_title':'QA speed '+kind+' '+secrets.token_hex(5),'analysis_mode':'general','output_format':'paragraph','summary_depth':'balanced','request_token':secrets.token_hex(16)}
    files=None
    if kind=='url':fields['source_url']='https://www.nasa.gov/news-release/nasa-isro-satellite-lifts-off-to-track-earths-changing-surfaces/'
    if kind in ('pdf','docx'):
        fields['source_type']='file';path=PRIVATE/('speed-source.'+kind)
        if kind=='docx':
            from docx import Document
            doc=Document()
            for paragraph in SOURCE.split('\n\n'):doc.add_paragraph(paragraph)
            doc.save(path)
        else:
            spec=importlib.util.spec_from_file_location('speed_pdf',ROOT/'python-engine/tests/test_failure_feedback.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);module.pdf_fixture(path,text=SOURCE)
        files={'pdf_file':(path.name,path.read_bytes(),'application/octet-stream')};row['upload_bytes']=path.stat().st_size
    save({'kind':kind,'run':run})
    started=time.monotonic()
    try:
        response=session.post(BASE+'summarize.php',data=fields,files=files,headers={'Accept':'application/json'},timeout=90)
        row['submission_seconds']=round(time.monotonic()-started,3)
        payload=response.json()
        if not payload.get('success'):raise RuntimeError('Submission failed: '+str(payload.get('failure',{}).get('code') or response.status_code))
        job=payload['job_id']
        session.post(BASE+'summarize.php?action=execute',data={'csrf_token':fields['csrf_token'],'job_id':job},timeout=90)
        until=started+360
        while time.monotonic()<until:
            state=session.get(BASE+'summarize.php',params={'action':'status','job_id':job},timeout=30).json()
            if state.get('status') not in ('queued','processing'):break
            time.sleep(.2)
        if state.get('status')!='completed':raise RuntimeError('Job failed: '+str(state.get('failure',{}).get('code') or state.get('status')))
        row['completed_seconds']=round(time.monotonic()-started,3)
        response=session.get(BASE+state['redirect'],timeout=30)
        if response.status_code!=200 or fields['document_title'] not in response.text:raise RuntimeError('Persisted result unavailable')
        row['end_to_end_seconds']=round(time.monotonic()-started,3)
        summary_id=re.search(r'id=(\d+)',state['redirect'])[1]
        record=json.loads(subprocess.check_output(['C:/xampp/php/php.exe','scripts/qa/benchmark_fixture.php','inspect',summary_id],cwd=ROOT,text=True))
        row.update({'source_words':int(record['original_word_count']),'summary_words':int(record['summary_word_count']),'recorded_processing_seconds':float(record['processing_time']) if record['processing_time'] is not None else None,'recorded_job_seconds':int(record['recorded_job_seconds']) if record['recorded_job_seconds'] is not None else None,'passed':True})
    except Exception as exc:
        row['elapsed_seconds']=round(time.monotonic()-started,3);row['error']=type(exc).__name__+': '+str(exc)[:180]
    results.append(row);save();print(json.dumps(row),flush=True)
    return row['passed']
session=requests.Session()
auth=session.post(BASE+'auth.php?action=login',data={'csrf_token':token(session,'login.php'),'username':'qa_benchmark','password':'QaStrong123!'},allow_redirects=False,timeout=30)
if auth.headers.get('Location')!='index.php':raise RuntimeError('Controlled benchmark login failed')
for kind,run in [('text',1),('text',2),('text',3),('pdf',1),('docx',1),('url',1)]:
    if not run_case(session,kind,run):break
save();sys.exit(0 if len(results)==6 and all(row['passed'] for row in results) else 1)
