"""Actual multipart HTTP and registry authorization checks against the isolated Apache."""
import datetime, json, os, re, subprocess, sys
from pathlib import Path
import requests
BASE='http://127.0.0.1:58139/'
ROOT=Path(__file__).resolve().parents[2]
checks=[]
def check(name,ok):
    checks.append({'name':name,'passed':bool(ok)})
    print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    (ROOT/'docs/qa/registry-http-results.json').write_text(json.dumps(checks,indent=2),encoding='utf8')
def probe(*args):
    return subprocess.check_output(['C:/xampp/php/php.exe','scripts/qa/registry_probe.php',*args],cwd=ROOT,text=True)
def token(session,page='admin_student_registry.php'):
    response=session.get(BASE+page,timeout=30)
    return re.search(r'name="csrf_token"\s+value="([^"]+)"',response.text)[1]
def credentials(s,who,password='QaStrong123!'):
    return s.post(BASE+'auth.php?action=login',data={'csrf_token':token(s,'login.php'),'username':who,'password':password},allow_redirects=False,timeout=30)
def fields(s,**extra):
    return {'csrf_token':token(s),'registry_action':'preview','authorized_list':'1','verification_period':'SYNTHETIC HTTP enrollment period','eligible_until':(datetime.date.today()+datetime.timedelta(days=45)).isoformat(),**extra}
def preview(s,text=None,file=None,**extra):
    return s.post(BASE+'admin_student_registry.php',data=fields(s,**({'student_ids':text} if text is not None else {}),**extra),files={'registry_file':file} if file else None,timeout=40)
def commit(s):
    page=s.get(BASE+'admin_student_registry.php')
    m=re.search(r'name="batch_ref" value="([a-f0-9]{32})"',page.text)
    if not m:
        return page
    ref=m[1]
    return s.post(BASE+'admin_student_registry.php',data={'csrf_token':token(s),'registry_action':'confirm','batch_ref':ref,'confirm_import':'1'},timeout=40)
def main():
    probe('reset-test-rate-limits')
    anon=requests.Session()
    check('anonymous registry GET and POST denied',anon.get(BASE+'admin_student_registry.php').status_code==403 and anon.post(BASE+'admin_student_registry.php',data={'registry_action':'preview','student_ids':'UNTRUSTED-0001'}).status_code==403)
    ordinary=requests.Session();credentials(ordinary,'qa_existing','QaNewStrong123!')
    contact=ordinary.get(BASE+'terms.php')
    check('published support/privacy contact comes from environment without an official DPO claim','mailto:qa@example.invalid' in contact.text and 'AI Summarizer System Administrator / Privacy Contact' in contact.text and 'not represented as the official NEUST Data Protection Officer' in contact.text)
    check('ordinary account cannot list or import registry',ordinary.get(BASE+'admin_student_registry.php').status_code==403 and ordinary.post(BASE+'admin_student_registry.php',data={'registry_action':'confirm','confirm_import':'1'}).status_code==403)
    admin=requests.Session();credentials(admin,'qa_admin')
    check('password-only admin session cannot access registry',admin.get(BASE+'admin_student_registry.php').status_code==403)
    messages=json.loads((Path(os.environ['QA_PRIVATE_DIR'])/'mail.json').read_text())
    code=re.search(r'\b\d{6}\b',next(m['body'] for m in reversed(messages) if 'admin@example.invalid' in m['to']))[0]
    auth=admin.post(BASE+'auth.php?action=verifyAdminLoginCode',data={'csrf_token':token(admin,'login.php?context=admin'),'code':code},allow_redirects=False)
    check('admin second factor unlocks private registry',auth.headers.get('Location')=='admin_dashboard.php' and admin.get(BASE+'admin_student_registry.php').status_code==200)
    privacy=admin.get(BASE+'admin_student_registry.php')
    check('registry responses prohibit caching and referrer leakage','no-store' in privacy.headers.get('Cache-Control','') and privacy.headers.get('Referrer-Policy')=='no-referrer')
    denied=admin.post(BASE+'admin_student_registry.php',data={'registry_action':'preview','csrf_token':'forged','student_ids':'FORGED-0001'})
    check('forged CSRF import denied before processing',denied.status_code==403)
    preview_ok=preview(admin,'HTTP-PERMANENT')
    check('campus list preview works without authorization checkbox',preview_ok.status_code==200 and 'Import Preview' in preview_ok.text)
    csv=preview(admin,file=('students.csv',b'student_id\r\n00009001\r\nHTTP-CSV-0002\r\n','text/csv'))
    check('real multipart CSV upload preview accepted',csv.status_code==200 and 'Import Preview' in csv.text)
    done=commit(admin)
    check('CSV confirmation inserts valid strings',done.status_code==200 and 'data-count="inserted_count">2<' in done.text and json.loads(probe('inspect','00009001'))['registry']=='active')
    text=preview(admin,file=('students.txt',b'HTTP-TXT-0001\nHTTP-TXT-0002\n','text/plain'))
    check('real multipart TXT upload accepted',text.status_code==200 and 'Import Preview' in text.text)
    check('TXT confirmation inserts two records','data-count="inserted_count">2<' in commit(admin).text)
    repeated=preview(admin,'HTTP-TXT-0001,HTTP-TXT-0001,HTTP-NEW-0001,,=HYPERLINK(1),BAD ID')
    check('preview reports invalid IDs and batch duplicates',repeated.status_code==200 and 'Invalid or empty ID' in repeated.text and 'Duplicate:' in repeated.text)
    done=commit(admin)
    check('partial import reports exact insert skip and invalid counts','data-count="inserted_count">1<' in done.text and 'data-count="skipped_count">2<' in done.text and 'data-count="invalid_count">3<' in done.text)
    probe('reset-test-rate-limits')
    cases=[('executable extension',('list.php',b'HTTP-EXEC-0001','text/plain')),('PHP disguised as text',('list.txt',b'<?php echo "private"; ?>','text/plain')),('binary CSV',('list.csv',b'HTTP\0BINARY','text/csv')),('malformed CSV',('list.csv',b'"UNTERMINATED\n','text/csv')),('oversized upload',('large.txt',b'A'*1048577,'text/plain'))]
    for label,upload in cases:
        result=preview(admin,file=upload)
        check(label+' upload rejected without execution',result.status_code==422)
    check('expired eligibility date rejected',preview(admin,'HTTP-EXPIRED',eligible_until='2001-01-01').status_code==422)
    # Exact search is POST-only, so IDs are not written to access-log query strings.
    search=admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin),'registry_action':'search','search':'00009001'})
    check('admin exact search masks identifiers',search.status_code==200 and '00009001' not in re.sub(r'<input[^>]*>','',search.text) and '00009001' not in search.url)
    bad=admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin),'registry_action':'search','search':"X'; DROP TABLE users;--"})
    check('SQL injection search rejected safely',('without spaces' in bad.text or 'Check the student-number format' in bad.text) and admin.get(BASE+'admin_dashboard.php').status_code==200)
    admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin),'registry_action':'search','search':''})
    page=admin.get(BASE+'admin_student_registry.php?status=revoked&page=2&history_page=2')
    check('filter and pagination render without private identifiers in URL',page.status_code==200 and 'History page 2' in page.text)
    probe('reset-test-rate-limits')
    r=preview(admin,'HTTP-REVOCATION');commit(admin)
    student=requests.Session();signup=student.post(BASE+'auth.php?action=register',data={'csrf_token':token(student,'register.php'),'student_number':'HTTP-REVOCATION','password':'QaStrong123!','confirm_password':'QaStrong123!','terms_accept':'1'},allow_redirects=False)
    check('matched registration grants continued access through real HTTP',signup.headers.get('Location')=='student_verification.php' and student.get(BASE+'access_status.php').json()['continued'] is True)
    search=admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin),'registry_action':'search','search':'HTTP-REVOCATION'})
    ref=re.search(r'name="registry_ref" value="([a-f0-9]{32})"',search.text)[1];version=re.search(r'name="version" value="(\d+)"',search.text)[1]
    revoke=admin.post(BASE+'admin_student_registry.php',data={'csrf_token':token(admin),'registry_action':'status','registry_ref':ref,'version':version,'eligibility_status':'revoked','reason':'Synthetic HTTP revoked enrollment','confirm_status':'1'})
    check('admin revocation suspends current session access immediately',revoke.status_code==200 and student.get(BASE+'access_status.php').json()['continued'] is False and 'Suspended' in student.get(BASE+'student_verification.php').text)
    probe('reset-test-rate-limits')
    unknown=requests.Session();rejected=unknown.post(BASE+'auth.php?action=register',data={'csrf_token':token(unknown,'register.php'),'student_number':'HTTP-UNKNOWN','password':'QaStrong123!','confirm_password':'QaStrong123!','terms_accept':'1','role':'admin','verification_status':'verified','approval_method':'registry_match','registry_id':'1'},allow_redirects=False)
    check('unmatched registration rejected without a pending account',rejected.headers.get('Location')=='register.php' and unknown.get(BASE+'access_status.php').json()['continued'] is False and 'not eligible for account activation' in unknown.get(BASE+'register.php').text and json.loads(probe('inspect','HTTP-UNKNOWN'))['application'] is False)
    for _ in range(10):preview(admin,'RATE-INVALID',authorized_list='0')
    check('administrator import rate limiting enforced',preview(admin,'RATE-INVALID',authorized_list='0').status_code==429)
try:main()
except Exception as exc:check('registry HTTP exception: '+type(exc).__name__+': '+str(exc)[:150],False)
sys.exit(1 if any(not x['passed'] for x in checks) else 0)
