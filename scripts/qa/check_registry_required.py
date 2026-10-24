"""Actual Apache registration/activation checks using restricted synthetic fixtures."""
import json, os, re, subprocess, sys, time
from pathlib import Path
import requests
ROOT=Path(__file__).resolve().parents[2]
BASE='http://127.0.0.1:58139/'
REPORT=Path(os.environ['QA_REGISTRY_REPORT'])/'http-results.json'
PRIVATE=Path(os.environ['QA_PRIVATE_DIR'])
checks=[]

def check(name,ok):
    checks.append({'name':name,'passed':bool(ok)})
    REPORT.write_text(json.dumps(checks,indent=2),encoding='utf8')
    print(('PASS ' if ok else 'FAIL ')+name,flush=True)

def probe(*args):
    return subprocess.check_output(['C:/xampp/php/php.exe','scripts/qa/registry_probe.php',*args],cwd=ROOT,text=True)

def inspect(number):
    return json.loads(probe('inspect',number))

def token(session,page):
    response=session.get(BASE+page,timeout=30)
    match=re.search(r'name="csrf_token"\s+value="([^"]+)"',response.text)
    if not match:raise RuntimeError('Expected CSRF token missing')
    return match[1]

def login(session,username):
    return session.post(BASE+'auth.php?action=login',data={'csrf_token':token(session,'login.php'),'username':username,'password':'QaStrong123!'},allow_redirects=False,timeout=30)

def register(number,extra=None):
    session=requests.Session()
    data={'csrf_token':token(session,'register.php'),'student_number':number,'password':'QaStrong123!','confirm_password':'QaStrong123!','terms_accept':'1'}
    data.update(extra or {})
    response=session.post(BASE+'auth.php?action=register',data=data,allow_redirects=False,timeout=30)
    return session,response

def import_ids(admin,numbers):
    return admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin,'admin_student_registry.php'),'registry_action':'import','student_ids':'\n'.join(numbers)},timeout=30)

def main():
    probe('reset-test-rate-limits')
    registration=requests.get(BASE+'register.php',timeout=30)
    check('registration explains immediate activation without the old pending workflow',registration.status_code==200 and 'activates your account immediately' in registration.text and 'Registration starts Pending' not in registration.text and 'operator confirms authorization' not in registration.text)
    anon=requests.Session()
    check('anonymous registry access denied',anon.get(BASE+'admin_student_registry.php').status_code==403)
    ordinary=requests.Session();login(ordinary,'qa_existing')
    check('ordinary user registry access denied',ordinary.get(BASE+'admin_student_registry.php').status_code==403)
    admin=requests.Session();login(admin,'qa_admin')
    check('admin password alone does not bypass the login challenge',admin.get(BASE+'admin_student_registry.php').status_code==403)
    for _ in range(30):
        if (PRIVATE/'mail.json').exists():break
        time.sleep(.1)
    messages=json.loads((PRIVATE/'mail.json').read_text())
    code=re.search(r'\b\d{6}\b',next(m['body'] for m in reversed(messages) if 'admin@example.invalid' in m['to']))[0]
    auth=admin.post(BASE+'auth.php?action=verifyAdminLoginCode',data={'csrf_token':token(admin,'login.php?context=admin'),'code':code},allow_redirects=False)
    check('authorized administrator completes second-factor login',auth.headers.get('Location')=='admin_dashboard.php')
    page=admin.get(BASE+'admin_student_registry.php')
    check('registry imports remain enabled',page.status_code==200 and 'Registry imports are closed' not in page.text)

    before=inspect('QA-ACT-02053')['counts']
    unlisted,response=register('QA-ACT-02053',{'role':'admin','verification_status':'verified','status':'verified','registry_id':'1','approval_method':'registry_match'})
    check('unlisted ID rejected despite forged approval fields',response.headers.get('Location')=='register.php' and 'not eligible for account activation' in unlisted.get(BASE+'register.php').text)
    after=inspect('QA-ACT-02053')
    check('rejected signup retains no user application review-event or trial-link rows',after['application'] is False and after['counts']==before)
    check('rejected signup has no continued access',unlisted.get(BASE+'access_status.php').json()['continued'] is False)

    imported=import_ids(admin,['QA-ACT-02053','QA-ACT-02049'])
    check('administrator imports active IDs successfully',imported.status_code==200 and '2 student IDs imported successfully' in imported.text)
    matched,response=register(' qa-act-02053 ')
    record=inspect('QA-ACT-02053')['application']
    check('case-normalized matching signup commits verified not pending',response.headers.get('Location')=='student_verification.php' and record['status']=='verified' and record['approval_method']=='registry_match')
    check('matching account immediately receives continued access',matched.get(BASE+'access_status.php').json()['continued'] is True)
    status=matched.get(BASE+'student_verification.php').text
    check('verified page has no waiting heading or review-submit button','Your Student Account Has Been Verified!' in status and 'Under Review' not in status and 'Submit for Review' not in status)
    before=inspect('QA-ACT-02053')['counts']
    _,duplicate=register('QA-ACT-02053')
    check('duplicate ID rejected without another account',duplicate.headers.get('Location')=='register.php' and inspect('QA-ACT-02053')['counts']==before)
    matched.get(BASE+'logout.php')
    check('activated student can sign in again with ID and unchanged password',login(matched,'qa-act-02053').headers.get('Location')=='index.php' and matched.get(BASE+'access_status.php').json()['continued'] is True)

    probe('reset-test-rate-limits')
    import_ids(admin,['QA-ACT-INACTIVE','QA-ACT-REVOKED','QA-ACT-EXPIRED'])
    for state in ['inactive','revoked','expired']:
        number='QA-ACT-'+state.upper();probe('set-test-eligibility',number,state)
        before=inspect(number)['counts'];session,response=register(number)
        check(state+' ID rejected with no retained signup rows',response.headers.get('Location')=='register.php' and inspect(number)['application'] is False and inspect(number)['counts']==before and session.get(BASE+'access_status.php').json()['continued'] is False)

    fixture=json.loads(probe('prepare-legacy-activation'))
    legacy=requests.Session();login(legacy,'qa_legacy_activation')
    page=legacy.get(BASE+'student_verification.php')
    check('existing unmatched account preserved without a review waiting screen',page.status_code==200 and 'Your Student Account Is Not Verified' in page.text and 'Activate Student Account' in page.text and 'Under Review' not in page.text and '<span class="student-status">pending' not in page.text)
    original=inspect('QA-ACT-LEGACY');version=original['application']['version']
    failure=legacy.post(BASE+'student_verification.php',data={'csrf_token':token(legacy,'student_verification.php'),'version':version,'student_number':'QA-ACT-NOT-IMPORTED'},timeout=30)
    check('failed existing-account activation preserves original application',failure.status_code==200 and 'not eligible for account activation' in failure.text and inspect('QA-ACT-LEGACY')['application']==original['application'] and inspect('QA-ACT-LEGACY')['counts']==original['counts'])
    import_ids(admin,['QA-ACT-LEGACY-ACTIVE'])
    forged=legacy.post(BASE+'student_verification.php',data={'csrf_token':'forged','version':version,'student_number':'QA-ACT-LEGACY-ACTIVE'})
    check('existing-account activation requires valid CSRF',forged.status_code==403 and inspect('QA-ACT-LEGACY')['application']==original['application'])
    activated=legacy.post(BASE+'student_verification.php',data={'csrf_token':token(legacy,'student_verification.php'),'version':version,'student_number':'QA-ACT-LEGACY-ACTIVE'},timeout=30)
    record=inspect('QA-ACT-LEGACY-ACTIVE')['application']
    check('existing account activates immediately with imported corrected ID',activated.status_code==200 and 'Your Student Account Has Been Verified!' in activated.text and record['status']=='verified' and int(record['user_id'])==fixture['id'] and legacy.get(BASE+'access_status.php').json()['continued'] is True)
    check('ordinary existing account without application remains restricted',ordinary.get(BASE+'access_status.php').json()['continued'] is False)
    forged=admin.post(BASE+'admin_student_registry.php',data={'csrf_token':'forged','registry_action':'import','student_ids':'QA-ACT-FORGED'})
    check('registry import still requires valid CSRF',forged.status_code==403)
    dashboard=admin.get(BASE+'admin_dashboard.php')
    check('admin dashboard preserves legacy management without pending badge',dashboard.status_code==200 and 'existing unverified' in dashboard.text and 'Pending students' not in dashboard.text)
    probe('reset-test-rate-limits')
    session,response=register('QA-ACT-02049',{'terms_accept':'0'})
    check('terms acceptance remains required',response.headers.get('Location')=='register.php' and inspect('QA-ACT-02049')['application'] is False)
    session,response=register('QA-ACT-02049',{'csrf_token':'forged'})
    check('registration requires valid CSRF',response.status_code==403 and inspect('QA-ACT-02049')['application'] is False)

    # A valid registry number does not make duplicate account credentials usable.
    # Reproduce the exact public screenshot message without touching real accounts.
    probe('reset-test-rate-limits')
    conflict_message='We could not register these details. Check your information or contact the operator for account assistance.'
    before=inspect('QA-ACT-02049')['counts']
    session,response=register('QA-ACT-02049',{'email':'existing@example.invalid'})
    check('valid imported ID with reused email reproduces the duplicate-account message',response.headers.get('Location')=='register.php' and conflict_message in session.get(BASE+'register.php').text)
    session,response=register('QA-ACT-02049',{'username':'qa_existing'})
    check('valid imported ID with reused username reproduces the same message',response.headers.get('Location')=='register.php' and conflict_message in session.get(BASE+'register.php').text)
    check('credential conflicts retain no extra account and do not claim the valid student ID',inspect('QA-ACT-02049')['counts']==before and inspect('QA-ACT-02049')['application'] is False)
    existing=requests.Session()
    check('credential conflicts do not alter or activate the existing account',login(existing,'qa_existing').headers.get('Location')=='index.php' and existing.get(BASE+'access_status.php').json()['continued'] is False)
    session,response=register('QA-ACT-02049')
    check('same valid ID registers and activates when duplicate optional credentials are omitted',response.headers.get('Location')=='student_verification.php' and inspect('QA-ACT-02049')['application']['status']=='verified' and session.get(BASE+'access_status.php').json()['continued'] is True)
    if os.environ.get('QA_LINK_REPAIR_TESTED')=='1':
        repaired=requests.Session();response=login(repaired,'PENQA-LINK-PRIMARY')
        check('operator-repaired student ID signs into the original account through HTTP',response.headers.get('Location')=='index.php' and repaired.get(BASE+'access_status.php').json()['continued'] is True)
        check('operator-repaired account renders verified without pending access','Your Student Account Has Been Verified!' in repaired.get(BASE+'student_verification.php').text)
        old_id=requests.Session();response=login(old_id,'PEN-QA-LINK-PRIMARY')
        check('obsolete mistyped ID does not sign into the repaired account','login.php' in response.headers.get('Location','') and old_id.get(BASE+'access_status.php').json()['continued'] is False)

try:main()
except Exception as exc:check('registry-required workflow exception: '+type(exc).__name__,False)
sys.exit(1 if any(not item['passed'] for item in checks) else 0)
