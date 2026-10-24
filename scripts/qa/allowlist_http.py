"""Actual PHP 8.3 Apache requests, all enrollment values private to QA input."""
import json, os, re, secrets, subprocess, sys, time
from pathlib import Path
import requests
ROOT=Path(__file__).resolve().parents[2];BASE='http://127.0.0.1:58139/'
REPORT=Path(os.environ['QA_REGISTRY_REPORT']);PRIVATE=Path(os.environ['QA_PRIVATE_DIR'])
IDS=json.loads(Path(os.environ['QA_INITIAL_LIST_FILE']).read_text());checks=[]
def check(name,ok):
    checks.append({'name':name,'passed':bool(ok)});(REPORT/'http-results.json').write_text(json.dumps(checks,indent=2),encoding='utf8');print(('PASS ' if ok else 'FAIL ')+name,flush=True)
def probe(*args):return subprocess.check_output(['C:/php83/php.exe','scripts/qa/allowlist_probe.php',*args],cwd=ROOT,text=True)
def token(session,page='register.php'):
    response=session.get(BASE+page,timeout=30);match=re.search(r'name="csrf_token"\s+value="([^"]+)"',response.text)
    if not match:raise RuntimeError('Missing CSRF token')
    return match[1]
def login(session,credential,password='QaStrong123!'):
    return session.post(BASE+'auth.php?action=login',data={'csrf_token':token(session,'login.php'),'username':credential,'password':password},allow_redirects=False,timeout=30)
def register(number,extra=None):
    session=requests.Session();data={'csrf_token':token(session),'student_number':number,'email':secrets.token_hex(8)+'@local.invalid','password':'QaStrong123!','confirm_password':'QaStrong123!','terms_accept':'1'};data.update(extra or {})
    return session,session.post(BASE+'auth.php?action=register',data=data,allow_redirects=False,timeout=30)
def preview(admin,text=None,upload=None):
    data={'csrf_token':token(admin,'admin_student_registry.php'),'registry_action':'preview'}
    if text is not None:data['student_ids']=text
    return admin.post(BASE+'admin_student_registry.php',data=data,files={'registry_file':upload} if upload else None,timeout=40)
def confirm(admin):
    page=admin.get(BASE+'admin_student_registry.php');reference=re.search(r'name="batch_ref"\s+value="([a-f0-9]{32})"',page.text)
    if not reference:raise RuntimeError('Server-owned import preview missing')
    return admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin,'admin_student_registry.php'),'registry_action':'confirm','batch_ref':reference[1],'confirm_import':'1'},timeout=40)
def main():
    runtime=requests.get(BASE+'__allowlist_runtime').json()
    check('HTTP runs PHP 8.3 Apache with production-denied MySQL credentials',runtime['php_version'].startswith('8.3.') and runtime['sapi']=='apache2handler' and runtime['production_access_denied'])
    form=requests.get(BASE+'register.php').text
    check('registration has a noneditable PEN prefix and suffix field','class="student-number-prefix"' in form and '>PEN</span>' in form and 'placeholder="2023-02071"' in form)
    check('registration has no student photo document OTP or email-verification fields','type="file"' not in form and 'name="code"' not in form and 'name="full_name"' not in form)
    for index,number in enumerate(IDS):
        student=requests.Session();response=login(student,number.lower())
        check('initial approved ID '+str(index+1)+' authenticates and has continued HTTP access',response.headers.get('Location')=='index.php' and student.get(BASE+'access_status.php').json()['continued'] is True)
    student=requests.Session();login(student,IDS[0]);profile=student.get(BASE+'profile.php')
    check('student Profile reports approved without a second identity form',profile.status_code==200 and 'data-student-status="verified"' in profile.text and 'name="student_number"' not in profile.text)
    check('obsolete student GET redirects to Profile and POST is retired',student.get(BASE+'student_verification.php',allow_redirects=False).headers.get('Location')=='profile.php' and student.post(BASE+'student_verification.php',data={'student_number':IDS[0]}).status_code==410)
    check('obsolete Verification link is absent from active navigation','class="nex-nav__link">Verification' not in profile.text)
    anonymous=requests.Session()
    check('anonymous registry GET and POST are denied',anonymous.get(BASE+'admin_student_registry.php').status_code==403 and anonymous.post(BASE+'admin_student_registry.php',data={'registry_action':'confirm'}).status_code==403)
    check('ordinary approved student cannot manage Student IDs',student.get(BASE+'admin_student_registry.php').status_code==403)
    admin=requests.Session();login(admin,'qa_admin')
    check('administrator password does not bypass existing second factor',admin.get(BASE+'admin_student_registry.php').status_code==403)
    for _ in range(30):
        if (PRIVATE/'mail.json').exists():break
        time.sleep(.1)
    messages=json.loads((PRIVATE/'mail.json').read_text());code=re.search(r'\b\d{6}\b',next(m['body'] for m in reversed(messages) if 'admin@example.invalid' in m['to']))[0]
    response=admin.post(BASE+'auth.php?action=verifyAdminLoginCode',data={'csrf_token':token(admin,'login.php?context=admin'),'code':code},allow_redirects=False)
    check('normal administrator second-factor authentication succeeds',response.headers.get('Location')=='admin_dashboard.php')
    page=admin.get(BASE+'admin_student_registry.php')
    check('Student ID Management differentiates all seven database statistics',page.status_code==200 and all('data-stat="'+key+'"' in page.text for key in ['total','active','claimed','accounts','unclaimed','rejected','recent']))
    check('manual student approval endpoint cannot mutate accounts',admin.post(BASE+'admin_student_review.php',data={'review_action':'accept','csrf_token':token(admin,'admin_student_registry.php')}).status_code==410)
    check('management imports require valid CSRF',admin.post(BASE+'admin_student_registry.php',data={'registry_action':'preview','csrf_token':'forged','student_ids':'PEN2099-777001'}).status_code==403)
    before=json.loads(probe('counts'));page=preview(admin,'PEN2099-777001')
    check('single-ID preview does not insert an approved record',page.status_code==200 and 'Import Preview' in page.text and json.loads(probe('inspect','PEN2099-777001'))['record'] is False and json.loads(probe('counts'))['student_enrollment_registry']==before['student_enrollment_registry'])
    check('preview requires explicit import confirmation',admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin,'admin_student_registry.php'),'registry_action':'confirm','batch_ref':'0'*32}).status_code==422)
    page=confirm(admin)
    check('confirmed individual insertion enables a real registry record',page.status_code==200 and 'Import Results' in page.text and json.loads(probe('inspect','PEN2099-777001'))['record']['eligibility_status']=='active')
    probe('reset-limits');registered,response=register('2099-777001',{'role':'admin','verification_status':'verified','student_prefix':'BAD'})
    record=json.loads(probe('inspect','PEN2099-777001'))['record']
    check('suffix signup approves immediately then redirects to normal sign-in',response.headers.get('Location')=='login.php' and record['status']=='verified' and record['approval_method']=='registry_match' and record['role']=='user' and registered.get(BASE+'access_status.php').json()['continued'] is False)
    login(registered,'PEN2099-777001')
    check('newly registered student receives continued access after password sign-in',registered.get(BASE+'access_status.php').json()['continued'] is True)
    probe('reset-limits');_,response=register('2099-777001')
    check('claimed ID cannot create a second account',response.headers.get('Location')=='register.php')
    for index,number in enumerate(['PEN2023-99999','PEN2023-02042','PEN2024-02071','PEN2023-12345']):
        probe('reset-limits');before=json.loads(probe('counts'));visitor,response=register(number,{'role':'admin','verification_status':'verified'})
        check('unlisted negative ID '+str(index+1)+' rejects by HTTP without creating an account',response.headers.get('Location')=='register.php' and 'not included in the approved NEUST' in visitor.get(BASE+'register.php').text and json.loads(probe('counts'))['users']==before['users'] and json.loads(probe('inspect',number))['record'] is False)
    probe('reset-limits');visitor,response=register('PEN')
    check('PEN prefix alone cannot register',response.headers.get('Location')=='register.php' and visitor.get(BASE+'access_status.php').json()['continued'] is False)
    visitor,response=register('2099-777002',{'csrf_token':'forged'})
    check('registration CSRF remains enforced',response.status_code==403)
    probe('reset-limits')
    csv=preview(admin,upload=('approved.csv',b'\xef\xbb\xbfstudent_id\r\nPEN2099-777002\r\nPEN2099-777003\r\n','text/csv'))
    check('actual CSV multipart upload produces a validated preview',csv.status_code==200 and 'Import Preview' in csv.text)
    confirm(admin)
    check('CSV confirmation preserves complete string IDs',json.loads(probe('inspect','PEN2099-777002'))['record']['eligibility_status']=='active')
    txt=preview(admin,upload=('approved.txt',b'PEN2099-777004\nPEN2099-777004\nBAD ID\n','text/plain'));done=confirm(admin)
    check('TXT import reports valid duplicate and rejected rows',txt.status_code==200 and 'data-count="inserted_count">1<' in done.text and 'data-count="skipped_count">1<' in done.text and 'data-count="invalid_count">1<' in done.text)
    for label,upload in [('executable extension',('bad.php',b'PEN2099-777005','text/plain')),('PHP disguised as TXT',('bad.txt',b'<?php echo "unsafe"; ?>','text/plain')),('binary contents',('bad.csv',b'PEN2099-777005\0','text/csv')),('oversized file',('large.txt',b'A'*1048577,'text/plain')),('broken CSV quotes',('bad.csv',b'"PEN2099-777005\n','text/csv'))]:
        probe('reset-limits');check(label+' is rejected safely',preview(admin,upload=upload).status_code==422)
    search=admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin,'admin_student_registry.php'),'registry_action':'search','search':IDS[0]})
    check('private exact search shows actual registered account status',search.status_code==200 and IDS[0] in search.text and 'Registered — approved' in search.text and IDS[0] not in search.url)
    check('active filter and pagination render',admin.get(BASE+'admin_student_registry.php?status=active&page=2').status_code==200)
    disputed=admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin,'admin_student_registry.php'),'registry_action':'search','search':'PEN2099-880008'})
    check('disputed historical claims are displayed as restricted rather than unclaimed',disputed.status_code==200 and 'Conflicting claims — restricted' in disputed.text)
    admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin,'admin_student_registry.php'),'registry_action':'search','search':'PEN2099-777001'})
    page=admin.get(BASE+'admin_student_registry.php');reference=re.search(r'name="registry_ref"\s+value="([a-f0-9]{32})"',page.text)[1];version=re.search(r'name="version"\s+value="(\d+)"',page.text)[1]
    response=admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin,'admin_student_registry.php'),'registry_action':'status','registry_ref':reference,'version':version,'eligibility_status':'revoked','reason':'Synthetic HTTP eligibility withdrawal','confirm_status':'1'})
    check('administrator revocation blocks an existing student session immediately',response.status_code==200 and registered.get(BASE+'access_status.php').json()['continued'] is False)
    page=admin.get(BASE+'admin_student_registry.php');version=re.search(r'name="version"\s+value="(\d+)"',page.text)[1]
    response=admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin,'admin_student_registry.php'),'registry_action':'status','registry_ref':reference,'version':version,'eligibility_status':'active','reason':'Synthetic HTTP eligibility reauthorization','confirm_status':'1'})
    check('administrator reauthorization restores registry-only access without a second form',response.status_code==200 and registered.get(BASE+'access_status.php').json()['continued'] is True)
    probe('reset-limits');response=admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin,'admin_student_registry.php'),'registry_action':'reconcile','confirm_reconcile':'1'})
    check('working administrator reconciliation control preserves normal accounts',response.status_code==200 and 'Existing accounts reconciled' in response.text)
    ordinary=requests.Session();response=login(ordinary,'qa_existing')
    check('old user still logs in but has no unearned student privileges',response.headers.get('Location')=='index.php' and ordinary.get(BASE+'access_status.php').json()['continued'] is False)
    probe('reset-limits')
    for _ in range(5):register('PEN2097-888888')
    limited,response=register('PEN2097-888888')
    check('registration rate limit remains enforced','Too many registration attempts' in limited.get(BASE+'register.php').text)
    probe('reset-limits')
try:main()
except Exception as error:check('HTTP acceptance exception: '+type(error).__name__,False)
raise SystemExit(1 if any(not item['passed'] for item in checks) else 0)
