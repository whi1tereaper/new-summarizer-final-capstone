"""Isolated real-PHP guest Nutshell acceptance, controlled faults, and a real NLP sample."""
import concurrent.futures,getpass,json,os,re,secrets,subprocess,tempfile,threading,time
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path
import requests
import sys
ROOT=Path(__file__).resolve().parents[2];sys.path.insert(0,str(ROOT/'scripts'))
from test_runtime_repair import SOURCE
PHP='C:/php83/php.exe';BASE='http://127.0.0.1:58139/';REPORT=Path(os.environ.get('QA_NUTSHELL_REPORT',str(ROOT/'docs/qa/nutshell-trial')));REPORT.mkdir(parents=True,exist_ok=True)
checks=[]
def check(name,ok):
    checks.append({'name':name,'passed':bool(ok)});(REPORT/'checks.json').write_text(json.dumps(checks,indent=2),encoding='utf8');print(('PASS ' if ok else 'FAIL ')+name,flush=True)
class ControlledWorker(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def send(self,payload,status=200):
        body=json.dumps(payload).encode();self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
    def do_GET(self):
        if self.headers.get('X-Worker-Token')!=self.server.secret:return self.send({'error':'unauthorized'},401)
        self.send({'status':'ok','protocol':3,'supervised':True,'execution_deadline_seconds':600})
    def do_POST(self):
        if self.headers.get('X-Worker-Token')!=self.server.secret:return self.send({'error':'unauthorized'},401)
        payload=json.loads(self.rfile.read(int(self.headers['Content-Length'])));self.server.calls+=1;self.server.last_deadline=float(self.headers.get('X-Worker-Deadline','0'))
        if self.server.mode=='failure':return self.send({'failure':{'code':'WORKER_UNAVAILABLE'}},503)
        if self.server.mode=='timeout':return self.send({'failure':{'code':'PROCESSING_TIMEOUT'}},504)
        if self.server.mode=='not_needed':return self.send({'status':'not_needed','nutshell':''})
        if self.server.mode=='invalid':return self.send({'status':'completed','nutshell':'<script>invalid</script>'})
        time.sleep(48 if self.server.mode=='slow' else .25)
        self.send({'status':'completed','nutshell':'Students benefit from reliable academic learning resources.','source_word_count':200,'algorithm_version':'qa-controlled-worker'})
def main():
    if (REPORT/'summary.json').exists():
        previous={name:json.loads((REPORT/name).read_text(encoding='utf8')) for name in ['summary.json','checks.json','browser.json','browser-recovery.json','initialization.json','slow-worker.json','real-nlp.json','real-documents.json'] if (REPORT/name).exists()}
        (REPORT/('attempt-'+str(time.time_ns())+'.json')).write_text(json.dumps(previous,indent=2),encoding='utf8')
    folder=Path(tempfile.mkdtemp(prefix='light-nutshell-qa-',dir=os.environ['LOCALAPPDATA'])).resolve()
    subprocess.run(['icacls',str(folder),'/inheritance:r','/grant:r',getpass.getuser()+':(OI)(CI)F'],capture_output=True,check=True)
    (REPORT/'last-run.txt').write_text(str(folder),encoding='utf8');apache=None;worker=None;nlp=None;nlp_log=None;env=None
    def php(*args):return subprocess.check_output([PHP,*args],cwd=ROOT,env=env,text=True,encoding='utf8')
    def probe(action,*args):return json.loads(php('scripts/qa/nutshell_trial_probe.php',action,*map(str,args)))
    def csrf(s,page='summarizer.php'):return re.search(r'name="csrf_token"\s+value="([^"]+)"',s.get(BASE+page,timeout=25).text)[1]
    def visitor():
        s=requests.Session();s.trust_env=False;token=csrf(s);return s,token
    def remaining(s):return s.get(BASE+'access_status.php',timeout=25).json()['remaining']
    def generate(s,token,**extra):
        fields={'csrf_token':token,'original_text':SOURCE,'request_key':secrets.token_hex(16)};fields.update(extra)
        return s.post(BASE+'nutshell_generate.php',data=fields,timeout=120)
    def status(s,token,key,**extra):
        return s.post(BASE+'nutshell_generate.php',data={'csrf_token':token,'action':'status','request_key':key,**extra},timeout=120)
    def regular_blocked(s,token):
        return s.post(BASE+'summarize.php',data={'csrf_token':token,'source_type':'text','original_text':SOURCE,'analysis_mode':'general','output_format':'paragraph','summary_depth':'balanced','request_token':secrets.token_hex(16)},headers={'Accept':'application/json'},timeout=30).json()
    try:
        subprocess.run([PHP,'scripts/qa/control.php','setup-benchmark',str(folder)],cwd=ROOT,capture_output=True,check=True)
        env=os.environ.copy();env.update(json.loads((folder/'config.json').read_text())['env']);env.update(QA_NUTSHELL_REPORT=str(REPORT),QA_NUTSHELL_SOURCE=str(folder/'source.txt'),QA_REGISTRY_REPORT=str(REPORT))
        (folder/'source.txt').write_text(SOURCE,encoding='utf8')
        php('scripts/qa/isolation.php');check('test credentials are denied production access',True)
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
        (folder/'apache.conf').write_text(conf,encoding='utf8')
        syntax=subprocess.run(['C:/xampp/apache/bin/httpd.exe','-t','-f',str(folder/'apache.conf')],cwd=ROOT,env=env,capture_output=True);check('isolated PHP 8.3 Apache configuration is valid',syntax.returncode==0)
        offline=subprocess.run([sys.executable,'scripts/qa/test_nutshell_initialization.py',str(folder),str(REPORT)],cwd=ROOT,env=env,capture_output=True,text=True,timeout=40)
        check('database initialization failures return safe structured JSON',offline.returncode==0)
        worker=ThreadingHTTPServer(('127.0.0.1',58149),ControlledWorker);worker.secret=env['PYTHON_WORKER_SECRET'];worker.mode='success';worker.calls=0;threading.Thread(target=worker.serve_forever,daemon=True).start()
        apache=subprocess.Popen(['C:/xampp/apache/bin/httpd.exe','-f',str(folder/'apache.conf')],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);time.sleep(2)
        guest,token=visitor();check('guest starts with exactly three trial uses',remaining(guest)==3)
        calls=worker.calls;unknown=secrets.token_hex(16)
        check('read-only unknown status lookup does not start NLP or charge quota',status(guest,token,unknown).json().get('request_state')=='not_found' and worker.calls==calls and remaining(guest)==3)
        check('status lookup requires CSRF',status(guest,'invalid',unknown).status_code==403 and worker.calls==calls)
        check('Nutshell GET is rejected with JSON',guest.get(BASE+'nutshell_generate.php').status_code==405)
        check('unknown Nutshell action is rejected',status(guest,token,unknown,action='unsafe').status_code==400 and worker.calls==calls)
        calls=worker.calls;response=generate(guest,'invalid');check('guest Nutshell requires CSRF without consuming a use',response.status_code==403 and remaining(guest)==3 and worker.calls==calls)
        for label,fields in [('empty source',{'original_text':''}),('short source',{'original_text':'too short'}),('invalid key',{'request_key':'short'})]:
            response=generate(guest,token,**fields);check(label+' does not consume a use or start NLP',not response.json()['success'] and remaining(guest)==3 and worker.calls==calls)
        response=guest.post(BASE+'nutshell_generate.php',data={'csrf_token':token,'request_key':secrets.token_hex(16)},files={'pdf_file':('bad.exe',b'MZnotanimage','application/octet-stream')},timeout=30)
        check('invalid uploaded document does not consume trial',not response.json()['success'] and remaining(guest)==3 and worker.calls==calls)
        for mode in ['failure','invalid','not_needed']:
            worker.mode=mode;response=generate(guest,token);check(mode+' processing leaves all three attempts and no held reservation',remaining(guest)==3 and probe('status',guest.cookies['LIGHT_TRIAL'])['reserved']==0 and (response.json().get('status')=='not_needed' if mode=='not_needed' else not response.json()['success']))
        worker.mode='success';key=secrets.token_hex(16);response=generate(guest,token,request_key=key);first=response.json();generation=first['generation']['id']
        check('worker receives the configured bounded 90-second extraction budget',worker.last_deadline==90)
        check('anonymous Nutshell completes and consumes exactly one shared use',first['success'] and remaining(guest)==2)
        stored=probe('generation',generation);check('completed guest Nutshell persists privately without a user account',stored['guest_owned'] and stored['user_id'] is None and stored['status']=='completed')
        calls=worker.calls;cached=generate(guest,token,request_key=key).json();check('idempotent retry reuses the same result without charge or NLP',cached['generation']['id']==generation and cached['generation']['cached'] and remaining(guest)==2 and worker.calls==calls)
        recovered=status(guest,token,key).json();check('lost reply can be recovered without source or quota charge',recovered.get('request_state')=='completed' and recovered['generation']['id']==generation and worker.calls==calls and remaining(guest)==2)
        pending_key=secrets.token_hex(16);probe('hold-request',generation,guest.cookies['LIGHT_TRIAL'],pending_key)
        try:
            pending=status(guest,token,pending_key);check('in-flight reservation is reported without generating a second Nutshell',pending.status_code==202 and pending.json().get('request_state')=='processing' and worker.calls==calls and remaining(guest)==2)
        finally:probe('release-request',generation,guest.cookies['LIGHT_TRIAL'],pending_key)
        other,other_token=visitor();different=generate(other,other_token,request_key=key).json();check('different guests cannot share an idempotency result',different['generation']['id']!=generation and not different['generation']['cached'] and remaining(other)==2)
        unrelated,fresh_token=visitor();calls=worker.calls;check('status lookup cannot disclose another guest result with the same key',status(unrelated,fresh_token,key).json().get('request_state')=='not_found' and remaining(unrelated)==3 and worker.calls==calls)
        check('guest-owned history storage retrieves its own Nutshell',generation in probe('history',generation)['ids'])
        check('the existing account requirement for the History page is unchanged',guest.get(BASE+'history.php',allow_redirects=False).headers.get('Location')=='register.php')
        own_summary=probe('summary',generation)['id'];calls=worker.calls;denied=generate(other,other_token,summary_id=str(own_summary),original_text='');check('another guest cannot request a Nutshell for an unowned summary',denied.status_code==404 and remaining(other)==2 and worker.calls==calls)
        owned=generate(guest,token,summary_id=str(own_summary),original_text='').json();check('a guest can generate a Nutshell for its own saved summary',owned['success'] and remaining(guest)==1)
        last=generate(guest,token).json();check('third successful guest generation reaches zero',last['success'] and remaining(guest)==0)
        calls=worker.calls;blocked=generate(guest,token).json();check('fourth direct Nutshell request blocks before NLP',blocked.get('failure',{}).get('code')=='GUEST_LIMIT_REACHED' and worker.calls==calls)
        check('ordinary summarization shares Nutshell exhaustion',regular_blocked(guest,token).get('failure',{}).get('code')=='GUEST_LIMIT_REACHED')
        check('trial exhaustion does not delete existing Nutshell history',probe('generation',generation)['status']=='completed' and generation in probe('history',generation)['ids'])
        calls=worker.calls;check('previous completed request remains readable after quota exhaustion',generate(guest,token,request_key=key).json()['generation']['cached'] and worker.calls==calls and remaining(guest)==0)
        check('read-only recovery remains available at zero trial uses',status(guest,token,key).json()['generation']['id']==generation and worker.calls==calls and remaining(guest)==0)
        timeout_guest,timeout_token=visitor();timeout_key=secrets.token_hex(16);worker.mode='timeout';failed=generate(timeout_guest,timeout_token,request_key=timeout_key)
        check('worker timeout is structured JSON and releases the unused trial reservation',failed.status_code==504 and failed.json().get('request_state')=='failed' and failed.json()['failure']['code']=='PROCESSING_TIMEOUT' and probe('status',timeout_guest.cookies['LIGHT_TRIAL'])=={'completed':0,'reserved':0})
        worker.mode='success';calls=worker.calls;stored_timeout=status(timeout_guest,timeout_token,timeout_key).json()
        check('timeout status preserves a confirmed failure without running NLP again',stored_timeout.get('request_state')=='failed' and stored_timeout['failure']['code']=='PROCESSING_TIMEOUT' and worker.calls==calls)
        check('fresh retry key after a confirmed timeout succeeds without a permanent failure loop',generate(timeout_guest,timeout_token).json()['success'] and remaining(timeout_guest)==2)
        slow,slow_token=visitor();worker.mode='slow';started=time.monotonic();slow_reply=generate(slow,slow_token);elapsed=time.monotonic()-started;worker.mode='success'
        check('controlled 48-second extraction survives the former 45-second cutoff',slow_reply.json().get('success') and 47<elapsed<100 and remaining(slow)==2)
        (REPORT/'slow-worker.json').write_text(json.dumps({'controlled_worker':True,'seconds':round(elapsed,2),'http_status':slow_reply.status_code,'worker_deadline':worker.last_deadline},indent=2),encoding='utf8')
        mixed,mixed_token=visitor();probe('complete-regular',mixed.cookies['LIGHT_TRIAL']);probe('complete-regular',mixed.cookies['LIGHT_TRIAL']);check('two ordinary completion reservations leave one Nutshell use',remaining(mixed)==1)
        check('Nutshell uses that last shared attempt',generate(mixed,mixed_token).json()['success'] and remaining(mixed)==0)
        fault,fault_token=visitor();probe('fault-on')
        try:response=generate(fault,fault_token);check('persistence failure rolls back the result and quota charge',not response.json()['success'] and remaining(fault)==3 and probe('status',fault.cookies['LIGHT_TRIAL'])['reserved']==0)
        finally:probe('fault-off')
        limited,limited_token=visitor();probe('limit',limited.cookies['LIGHT_TRIAL']);calls=worker.calls;check('guest-specific rate limit rejects before NLP without charge',generate(limited,limited_token).status_code==429 and remaining(limited)==3 and worker.calls==calls)
        race,race_token=visitor();trial=race.cookies['LIGHT_TRIAL'];participants=[]
        for _ in range(8):
            s=requests.Session();s.trust_env=False;s.cookies.set('LIGHT_TRIAL',trial);participants.append((s,csrf(s)))
        def racing(pair):return generate(*pair).json()
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:responses=list(pool.map(racing,participants))
        check('eight concurrent HTTP guest requests complete exactly three Nutshells',sum(r.get('success') is True for r in responses)==3 and remaining(race)==0)
        check('race leaves exactly three charges and no live reservations',probe('status',trial)=={'completed':3,'reserved':0})
        transfer,transfer_token=visitor();saved=generate(transfer,transfer_token).json()['generation']['id'];login=transfer.post(BASE+'auth.php?action=login',data={'csrf_token':csrf(transfer,'login.php'),'username':'qa_existing','password':'QaStrong123!'},allow_redirects=False)
        record=probe('generation',saved);check('sign-in transfers guest Nutshell history to the same account',login.headers.get('Location')=='index.php' and record['user_id']==2 and not record['guest_owned'] and 'Nutshell History' in transfer.get(BASE+'history.php').text)
        check('unverified account sign-in does not reset the trial',remaining(transfer)==2)
        expire,expire_token=visitor();expired=generate(expire,expire_token).json()['generation']['id'];probe('expire',expired);check('existing 24-hour guest retention deletes expired guest Nutshells only',probe('generation',expired) is False and probe('generation',saved)['user_id']==2 and probe('generation',generation)['status']=='completed')
        verified_id=probe('verified-user')['id'];verified,verified_token=visitor();verified.post(BASE+'auth.php?action=login',data={'csrf_token':csrf(verified,'login.php'),'username':'qa_nutshell_verified','password':'QaStrong123!'},timeout=20);verified_token=csrf(verified)
        check('verified student can generate beyond the anonymous three-use limit',all(generate(verified,verified_token).json()['success'] for _ in range(4)) and remaining(verified) is None)
        browser=subprocess.run(['node','scripts/qa/nutshell_trial_browser.cjs'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=150);check('mobile browser guest Nutshell and live counter workflow',browser.returncode==0)
        if browser.returncode:print(browser.stdout+browser.stderr,flush=True)
        browser=subprocess.run(['node','scripts/qa/nutshell_recovery_browser.cjs'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=240);check('browser lost-response status recovery and error classification',browser.returncode==0)
        if browser.returncode:print(browser.stdout+browser.stderr,flush=True)
        # Replace only the isolated controlled worker with the existing real NLP engine.
        worker.shutdown();worker.server_close();worker=None
        nlp_log=(folder/'nlp.log').open('wb');nlp=subprocess.Popen([str(ROOT/'.venv-nlp/Scripts/python.exe'),'python-engine/worker_daemon.py','--port','58149','--deadline','600'],cwd=ROOT,env=env,stdout=nlp_log,stderr=nlp_log)
        for _ in range(120):
            try:
                if requests.get('http://127.0.0.1:58149/health',headers={'X-Worker-Token':env['PYTHON_WORKER_SECRET']},timeout=2).status_code==200:break
            except requests.RequestException:pass
            time.sleep(.2)
        real,real_token=visitor();started=time.monotonic();response=generate(real,real_token);payload=response.json()
        check('actual existing Python NLP completes a guest Nutshell',response.status_code==200 and payload.get('success') and payload.get('status')=='completed' and payload.get('generation',{}).get('algorithm_version')!='qa-controlled-worker')
        if payload.get('status')=='completed':check('real NLP guest result is stored and consumes one attempt',probe('generation',payload['generation']['id'])['guest_owned'] and remaining(real)==2)
        (REPORT/'real-nlp.json').write_text(json.dumps({'seconds':round(time.monotonic()-started,2),'http_status':response.status_code,'status':payload.get('status'),'failure_code':payload.get('failure',{}).get('code'),'generation':payload.get('generation')},indent=2),encoding='utf8')
        import importlib.util
        spec=importlib.util.spec_from_file_location('nutshell_document_fixture',ROOT/'python-engine/tests/test_failure_feedback.py');fixture=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixture)
        from docx import Document
        from PIL import Image, ImageDraw, ImageFont
        import textwrap
        pdf=folder/'source.pdf';fixture.pdf_fixture(pdf,text=SOURCE)
        document=Document()
        for paragraph in SOURCE.split('\n\n'):document.add_paragraph(paragraph)
        docx=folder/'source.docx';document.save(docx)
        scan=Image.new('RGB',(1600,2100),'white');draw=ImageDraw.Draw(scan);font=ImageFont.truetype('C:/Windows/Fonts/arial.ttf',28);y=90
        for paragraph in SOURCE.strip().split('\n\n'):
            for line in textwrap.wrap(paragraph,width=92):draw.text((80,y),line,font=font,fill='black');y+=42
            y+=20
        scanned=folder/'scanned-source.pdf';scan.save(scanned,'PDF',resolution=150)
        document_receipts=[]
        for path,mime in [(pdf,'application/pdf'),(docx,'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),(scanned,'application/pdf')]:
            document_guest,document_token=visitor();started=time.monotonic();request_key=secrets.token_hex(16)
            with path.open('rb') as upload:
                response=document_guest.post(BASE+'nutshell_generate.php',data={'csrf_token':document_token,'request_key':request_key},files={'pdf_file':(path.name,upload,mime)},timeout=120)
            payload=response.json();ok=response.status_code==200 and payload.get('status')=='completed' and payload.get('success') and payload['generation']['algorithm_version']!='qa-controlled-worker'
            label='scanned PDF (real OCR)' if path==scanned else path.suffix
            check('real '+label+' upload completes through Apache PHP and existing Python',ok)
            check('real '+label+' result persists in MySQL and status recovery avoids repeat charge',ok and probe('generation',payload['generation']['id'])['guest_owned'] and status(document_guest,document_token,request_key).json()['generation']['id']==payload['generation']['id'] and remaining(document_guest)==2)
            document_receipts.append({'input':label,'seconds':round(time.monotonic()-started,2),'http_status':response.status_code,'status':payload.get('status'),'failure_code':payload.get('failure',{}).get('code'),'generation':payload.get('generation')})
        (REPORT/'real-documents.json').write_text(json.dumps(document_receipts,indent=2),encoding='utf8')
        core=subprocess.run([PHP,'scripts/qa/allowlist_core.php'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=600)
        check('existing security analytics database feedback and history regressions',core.returncode==0)
    except Exception as error:check('Nutshell acceptance exception: '+type(error).__name__,False);raise
    finally:
        if apache:subprocess.run(['taskkill','/PID',str(apache.pid),'/T','/F'],capture_output=True)
        if nlp:subprocess.run(['taskkill','/PID',str(nlp.pid),'/T','/F'],capture_output=True)
        if nlp_log:nlp_log.close()
        if worker:worker.shutdown();worker.server_close()
        if (folder/'config.json').exists():
            verify=subprocess.run([PHP,'scripts/qa/control.php','verify',str(folder)],cwd=ROOT,capture_output=True);check('live account passwords summaries and feedback preserved',verify.returncode==0)
            cleanup=subprocess.run([PHP,'scripts/qa/control.php','cleanup',str(folder)],cwd=ROOT,capture_output=True);check('isolated databases and credentials removed',cleanup.returncode==0)
            if (folder/'integrity.json').exists():(REPORT/'integrity.json').write_bytes((folder/'integrity.json').read_bytes())
            if cleanup.returncode==0:(folder/'config.json').unlink()
        summary={'passed':sum(c['passed'] for c in checks),'failed':sum(not c['passed'] for c in checks),'controlled_worker_cases_are_not_real_nlp':True,'live_database_migrations_applied':False}
        (REPORT/'summary.json').write_text(json.dumps(summary,indent=2),encoding='utf8');print(json.dumps(summary),flush=True)
    return int(any(not c['passed'] for c in checks))
if __name__=='__main__':raise SystemExit(main())
