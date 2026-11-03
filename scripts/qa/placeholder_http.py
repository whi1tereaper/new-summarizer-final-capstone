"""Exercise the unchanged registration/login forms using synthetic restricted-QA shells."""
import concurrent.futures,json,os,re,secrets,subprocess
from pathlib import Path
import requests
ROOT=Path(__file__).resolve().parents[2];BASE='http://127.0.0.1:58139/'
REPORT=Path(os.environ['QA_REGISTRY_REPORT']);checks=[]
SESSION_NAME=os.environ['SESSION_NAME']
def check(name,ok):
    checks.append({'name':name,'passed':bool(ok)});(REPORT/'placeholder-http.json').write_text(json.dumps(checks,indent=2),encoding='utf8');print(('PASS ' if ok else 'FAIL ')+name,flush=True)
def probe(action,number=None):
    return json.loads(subprocess.check_output(['C:/php83/php.exe','scripts/qa/claim_probe.php',action]+([number] if number else []),cwd=ROOT,text=True))
def reset():subprocess.check_output(['C:/php83/php.exe','scripts/qa/allowlist_probe.php','reset-limits'],cwd=ROOT)
def token(session,page='register.php'):
    return re.search(r'name="csrf_token"\s+value="([^"]+)"',session.get(BASE+page,timeout=20).text)[1]
def fields(session,number,**extra):
    result={'csrf_token':token(session),'student_number':number,'email':'claimed-'+secrets.token_hex(6)+'@local.invalid','password':'QaStrong123!','confirm_password':'QaStrong123!','terms_accept':'1'};result.update(extra);return result
def post(session,number,**extra):
    reset();return session.post(BASE+'auth.php?action=register',data=fields(session,number,**extra),allow_redirects=False,timeout=25)
def main():
    probe('setup-http');number='PEN2092-448001';before=probe('inspect',number);session=requests.Session()
    check('HTTP fixture is explicitly unclaimed inactive and without a password',before['state']=='unclaimed' and before['active']==0 and not before['password_hashed'] and before['summaries']['count']>0)
    response=post(session,number,csrf_token='invalid-token')
    check('invalid CSRF cannot modify placeholder credentials',response.status_code==403 and probe('inspect',number)==before)
    for label,extra in [('invalid email',{'email':'bad-email'}),('duplicate email',{'email':'existing@example.invalid'}),('mismatched passwords',{'confirm_password':'Different123!'})]:
        response=post(session,number,**extra)
        check(label+' safely rejects a placeholder claim without mutations',response.headers.get('Location')=='register.php' and probe('inspect',number)==before)
    response=post(session,number+"' OR 1=1 --")
    check('SQL injection cannot claim a placeholder',response.headers.get('Location')=='register.php' and probe('inspect',number)==before)
    response=post(session,number[3:],email='HTTP.Chosen@LOCAL.INVALID',role='admin',verification_status='verified',account_claim_state='unclaimed');after=probe('inspect',number)
    check('existing Create Account POST safely claims a matching placeholder',response.headers.get('Location')=='login.php' and after['id']==before['id'] and after['users']==before['users'])
    check('HTTP claim keeps the original account and application identity',all(before[k]==after[k] for k in ['username','application_id','application_ref','created_at']))
    check('chosen email and hashed password are usable immediately',after['email']=='http.chosen@local.invalid' and after['password_valid'] and after['password_hashed'] and after['active']==1 and after['terms']==1)
    check('HTTP claim grants only student registry-match access despite client role fields',after['role']=='user' and after['status']=='verified' and after['state']=='claimed' and after['method']=='registry_match' and after['registry_id'] is not None)
    for relation in ['summaries','artifacts','feedback','trial']:check('HTTP claim retains all original '+relation,before[relation]==after[relation])
    check('HTTP claim records exactly one committed audit event',after['claim_events']==before['claim_events']+1)
    check('successful claim returns the existing Sign In success screen without auto-login','Account created successfully!' in session.get(BASE+'login.php').text and session.get(BASE+'access_status.php').json()['continued'] is False)
    wrong=requests.Session();reset();response=wrong.post(BASE+'auth.php?action=login',data={'csrf_token':token(wrong,'login.php'),'username':'http.chosen@local.invalid','password':'Incorrect123!'},allow_redirects=False,timeout=20)
    check('incorrect chosen-account password is still denied',response.headers.get('Location')=='login.php' and wrong.get(BASE+'access_status.php').json()['continued'] is False)
    for index,credential in enumerate([number,'http.chosen@local.invalid',after['username']]):
        signed=requests.Session();csrf=token(signed,'login.php');old=signed.cookies.get(SESSION_NAME);reset();response=signed.post(BASE+'auth.php?action=login',data={'csrf_token':csrf,'username':credential,'password':'QaStrong123!'},allow_redirects=False,timeout=20)
        check('claimed student signs in immediately by credential '+str(index+1),response.headers.get('Location')=='index.php' and signed.get(BASE+'access_status.php').json()['continued'] is True)
        check('login rotates the session for credential '+str(index+1),old is not None and signed.cookies.get(SESSION_NAME)!=old)
        if index==0:
            check('claimed account can retrieve existing history',signed.get(BASE+'history.php',timeout=20).status_code==200 and 'Synthetic preserved placeholder history' in signed.get(BASE+'history.php').text)
            check('claimed student remains blocked from administrator registry management',signed.get(BASE+'admin_student_registry.php',timeout=20).status_code==403)
        signed.get(BASE+'logout.php',timeout=20)
        check('claimed student logout removes continued authenticated access',signed.get(BASE+'access_status.php').json()['continued'] is False)
    established=probe('inspect',number);response=post(requests.Session(),number,email='takeover@local.invalid',account_claim_state='unclaimed')
    check('claim-state request forgery cannot overwrite an established account',response.headers.get('Location')=='register.php' and probe('inspect',number)==established)
    number='PEN2092-448002';before=probe('inspect',number);sessions=[requests.Session(),requests.Session()];payloads=[fields(s,number) for s in sessions];reset()
    def claim_request(i):return sessions[i].post(BASE+'auth.php?action=register',data=payloads[i],allow_redirects=False,timeout=30).headers.get('Location')
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:locations=list(pool.map(claim_request,range(2)))
    after=probe('inspect',number)
    check('two actual concurrent HTTP claims allow one success and one denial',locations.count('login.php')==1 and locations.count('register.php')==1)
    check('concurrent HTTP claim reuses the same user and commits a single claim audit',after['id']==before['id'] and after['users']==before['users'] and after['claim_events']==1)
    check('concurrent HTTP claiming preserves summaries artifacts feedback and trial links',all(before[k]==after[k] for k in ['summaries','artifacts','feedback','trial']))
    reset()
try:main()
except Exception as error:check('placeholder HTTP acceptance exception: '+type(error).__name__,False)
raise SystemExit(1 if any(not row['passed'] for row in checks) else 0)
