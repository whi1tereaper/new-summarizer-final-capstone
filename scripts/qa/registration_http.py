"""Required-email registration acceptance; mutations stay in the restricted QA schema."""
import json,os,re,secrets,subprocess,time
from pathlib import Path
import requests
ROOT=Path(__file__).resolve().parents[2];BASE='http://127.0.0.1:58139/'
REPORT=Path(os.environ['QA_REGISTRY_REPORT']);PRIVATE=Path(os.environ['QA_PRIVATE_DIR']);checks=[]
def check(name,ok):
    checks.append({'name':name,'passed':bool(ok)});(REPORT/'registration-http.json').write_text(json.dumps(checks,indent=2),encoding='utf8');print(('PASS ' if ok else 'FAIL ')+name,flush=True)
def probe(*args):return json.loads(subprocess.check_output(['C:/php83/php.exe','scripts/qa/allowlist_probe.php',*args],cwd=ROOT,text=True))
def reset():subprocess.check_output(['C:/php83/php.exe','scripts/qa/allowlist_probe.php','reset-limits'],cwd=ROOT)
def token(session,page='register.php'):
    return re.search(r'name="csrf_token"\s+value="([^"]+)"',session.get(BASE+page,timeout=20).text)[1]
def post(session,number='PEN2095-660001',**extra):
    reset();fields={'csrf_token':token(session),'student_number':number,'email':'student-'+secrets.token_hex(5)+'@local.invalid','password':'QaStrong123!','confirm_password':'QaStrong123!','terms_accept':'1'};fields.update(extra)
    return session.post(BASE+'auth.php?action=register',data=fields,allow_redirects=False,timeout=25)
def value(page,field):
    match=re.search(r'<input\b(?=[^>]*\bname="'+field+r'")[^>]*>',page)
    return re.search(r'\bvalue="([^"]*)"',match[0])[1] if match and re.search(r'\bvalue="([^"]*)"',match[0]) else ''
def main():
    admin=requests.Session();admin.post(BASE+'auth.php?action=login',data={'csrf_token':token(admin,'login.php'),'username':'qa_admin','password':'QaStrong123!'},timeout=20)
    for _ in range(30):
        if (PRIVATE/'mail.json').exists():break
        time.sleep(.1)
    code=re.search(r'\b\d{6}\b',next(m['body'] for m in reversed(json.loads((PRIVATE/'mail.json').read_text())) if 'admin@example.invalid' in m['to']))[0]
    admin.post(BASE+'auth.php?action=verifyAdminLoginCode',data={'csrf_token':token(admin,'login.php?context=admin'),'code':code},timeout=20)
    before=probe('counts');admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin,'admin_student_registry.php'),'registry_action':'preview','student_ids':'\n'.join('PEN2095-66000'+str(i) for i in range(1,6))},timeout=20)
    page=admin.get(BASE+'admin_student_registry.php').text;ref=re.search(r'name="batch_ref"\s+value="([^"]+)"',page)[1]
    admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin,'admin_student_registry.php'),'registry_action':'confirm','batch_ref':ref,'confirm_import':'1'},timeout=25)
    check('administrator imports authorize five new IDs without creating any accounts',probe('counts')['users']==before['users'] and probe('counts')['student_enrollment_registry']==before['student_enrollment_registry']+5)
    for label,email in [('missing',''),('invalid','not-an-email')]:
        session=requests.Session();before=probe('counts');events=probe('events');response=post(session,email=email);page=session.get(BASE+'register.php').text
        check(label+' email is rejected server-side and audited once',response.headers.get('Location')=='register.php' and 'id="email-error"' in page and 'aria-invalid="true"' in page and probe('counts')['users']==before['users'] and probe('events')['rejected']==events['rejected']+1)
    session=requests.Session();email='retain@example.invalid';response=post(session,email=email,confirm_password='Different123!');page=session.get(BASE+'register.php').text
    check('mismatched passwords show a specific confirmation error','Your passwords do not match' in page)
    check('server validation retains ID and email but never either password',value(page,'student_number')=='2095-660001' and value(page,'email')==email and value(page,'password')=='' and value(page,'confirm_password')=='' and 'QaStrong123!' not in page and 'Different123!' not in page)
    response=post(session,email='bad"><script>alert(1)</script>');page=session.get(BASE+'register.php').text
    check('retained invalid input is HTML-escaped without script injection','<script>alert(1)</script>' not in page and '&lt;script&gt;' in page)
    response=post(session,password='weak',confirm_password='weak');page=session.get(BASE+'register.php').text
    check('weak passwords are rejected by the PHP policy','Password must be at least 8 characters.' in page)
    response=post(session,terms_accept='');check('existing terms acceptance remains required','accept the Terms and Conditions' in session.get(BASE+'register.php').text)
    reset();events=probe('events');mail_before=len(json.loads((PRIVATE/'mail.json').read_text()));response=post(session,email='Chosen.Email@LOCAL.INVALID');record=probe('inspect','PEN2095-660001')['record']
    check('eligible registration returns to Sign In with an active verified registry-match account',response.headers.get('Location')=='login.php' and record['status']=='verified' and record['approval_method']=='registry_match' and record['active']==1 and record['role']=='user')
    page=session.get(BASE+'login.php').text
    check('the exact requested success message is shown','Account created successfully! Your Student ID was found in the approved enrollment registry. You can now log in.' in page)
    check('registration stores normalized chosen email and a verifiable password hash',record['email']=='chosen.email@local.invalid' and record['password_hashed'] and record['password_valid'])
    check('registration does not create a logged-in session or send any verification mail',session.get(BASE+'access_status.php').json()['continued'] is False and len(json.loads((PRIVATE/'mail.json').read_text()))==mail_before)
    check('successful registration has its own safe transactional audit event',probe('events')['created']==events['created']+1 and probe('events')['safe'])
    login=session.post(BASE+'auth.php?action=login',data={'csrf_token':token(session,'login.php'),'username':'chosen.email@local.invalid','password':'QaStrong123!'},allow_redirects=False)
    check('newly chosen email and password sign in without another approval step',login.headers.get('Location')=='index.php' and session.get(BASE+'access_status.php').json()['continued'] is True)
    check('new account can open student workspace summarizer and history',all(session.get(BASE+page).status_code==200 for page in ['index.php','summarizer.php','history.php']))
    duplicate=requests.Session();before=probe('counts');response=post(duplicate,'PEN2095-660002',email='CHOSEN.EMAIL@local.invalid');page=duplicate.get(BASE+'register.php').text
    check('duplicate email cannot create an account or claim a different eligible ID',response.headers.get('Location')=='register.php' and probe('counts')['users']==before['users'] and probe('inspect','PEN2095-660002')['record']['user_id'] is None)
    check('duplicate details receive privacy-preserving feedback without account information','These registration details are unavailable.' in page and 'Account #' not in page)
    response=post(duplicate,'PEN2095-660001');check('already claimed ID still rejects a second account',response.headers.get('Location')=='register.php' and probe('counts')['users']==before['users'])
    for number in ['PEN2095-669999','PEN2023-99999']:
        response=post(duplicate,number);check('unlisted registration stays ineligible without creating an account',response.headers.get('Location')=='register.php' and probe('counts')['users']==before['users'] and probe('inspect',number)['record'] is False)
    admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin,'admin_student_registry.php'),'registry_action':'search','search':'PEN2095-660003'})
    page=admin.get(BASE+'admin_student_registry.php').text;ref=re.search(r'name="registry_ref"\s+value="([^"]+)"',page)[1];version=re.search(r'name="version"\s+value="([^"]+)"',page)[1]
    admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin,'admin_student_registry.php'),'registry_action':'status','registry_ref':ref,'version':version,'eligibility_status':'revoked','reason':'Synthetic registration revocation fixture','confirm_status':'1'})
    response=post(duplicate,'PEN2095-660003');check('revoked newly imported ID cannot register',response.headers.get('Location')=='register.php' and probe('counts')['users']==before['users'])
    failure=requests.Session();fields={'csrf_token':token(failure),'student_number':'PEN2095-660004','email':'offline@local.invalid','password':'QaStrong123!','confirm_password':'QaStrong123!','terms_accept':'1'}
    offline=failure.post('http://127.0.0.1:58140/auth.php?action=register',data=fields,timeout=25)
    check('real unavailable database returns safe HTTP 503 without bypass',offline.status_code==503 and 'Registration temporarily unavailable' in offline.text and 'SQLSTATE' not in offline.text and 'DB_PASSWORD' not in offline.text and probe('counts')['users']==before['users'])
    recovered=failure.get(BASE+'register.php').text
    check('an outage retains only safe non-password form values for retry',value(recovered,'email')=='offline@local.invalid' and value(recovered,'student_number')=='2095-660004' and value(recovered,'password')=='' and 'QaStrong123!' not in recovered)
    subprocess.check_output(['C:/php83/php.exe','scripts/qa/allowlist_probe.php','fault-on'],cwd=ROOT)
    try:
        response=post(failure,'PEN2095-660004');check('failure after user insert rolls back both account creation and ID claim',response.status_code==503 and probe('counts')['users']==before['users'] and probe('inspect','PEN2095-660004')['record']['user_id'] is None)
    finally:subprocess.check_output(['C:/php83/php.exe','scripts/qa/allowlist_probe.php','fault-off'],cwd=ROOT)
    response=post(failure,'PEN2095-660004',email='retry@local.invalid');check('a failed transaction can be retried with the same active unclaimed ID',response.headers.get('Location')=='login.php' and probe('inspect','PEN2095-660004')['record']['status']=='verified')
    stats=probe('stats');check('claimed unclaimed and actual account counters remain separate and consistent',stats['claimed']+stats['unclaimed']==stats['total'] and stats['accounts']>=stats['claimed'] and stats['rejected']>0)
    check('ordinary student still cannot modify the trusted registry',session.post(BASE+'admin_student_registry.php',data={'csrf_token':token(session,'summarizer.php'),'registry_action':'confirm'}).status_code==403)
    reset()
try:main()
except Exception as error:check('registration HTTP acceptance exception: '+type(error).__name__,False)
raise SystemExit(1 if any(not row['passed'] for row in checks) else 0)
