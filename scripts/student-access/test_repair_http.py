"""Backend-only HTTP regression against the migrated captured-schema fixture."""
import http.cookiejar
import json
import re
import secrets
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path

BASE='http://127.0.0.1:58139'
checks=[]
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None
class Client:
    def __init__(self):
        self.jar=http.cookiejar.CookieJar()
        self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar),NoRedirect())
    def request(self,path,data=None,headers=None):
        request=urllib.request.Request(BASE+path,data=urllib.parse.urlencode(data).encode() if data is not None else None,headers=headers or {})
        try: response=self.opener.open(request,timeout=20)
        except urllib.error.HTTPError as error: response=error
        return response.code,response.read().decode('utf-8','replace'),response.headers
def check(name,condition):
    checks.append(dict(name=name,passed=bool(condition)))
    print(('PASS ' if condition else 'FAIL ')+name,flush=True)
    if not condition: raise AssertionError(name)
def csrf(html): return re.search(r'name="csrf_token" value="([a-f0-9]+)"',html).group(1)
def version(html): return re.search(r'name="version" value="(\d+)"',html).group(1)

fixture={'X-Fixture-Key':'isolated-fixture-only'}
student=Client();student.request('/__fixture_reset_limits',headers=fixture)
code,registration,_=student.request('/register.php')
check('existing registration form renders',code==200)
suffix=secrets.token_hex(6);email=suffix+'@example.invalid';number='PEN-HTTP-REPAIR-'+suffix
form=dict(csrf_token=csrf(registration),full_name='Synthetic HTTP Repair Student',student_number=number,email=email,password='Strong123!',confirm_password='Strong123!',terms_accept='1',privacy_consent='1',eligibility_ack='1')
code,_,headers=student.request('/auth.php?action=register',form)
check('real registration controller uses migrated schema',code in (302,303) and headers.get('Location')=='student_verification.php')
code,status,_=student.request('/student_verification.php')
check('new applicant status remains pending',code==200 and 'Under Review' in status)
code,body,_=student.request('/access_status.php');public=json.loads(body)
check('pending applicant retains only three trial successes',code==200 and not public['continued'] and public['remaining']==3)
check('public access status contains no student email or number',email not in body and number not in body and set(public)=={'continued','remaining','reserved'})
anonymous=Client();code,body,_=anonymous.request('/admin_db_health.php')
check('anonymous health metadata is protected',code==403 and 'student_test' not in body)
admin=Client();admin.request('/__fixture_admin',headers=fixture)
code,queue,_=admin.request('/__fixture_queue',headers=fixture)
ref=re.search(r'admin_student_review.php\?ref=([a-f0-9]+)',queue).group(1)
code,review,_=admin.request('/admin_student_review.php?ref='+ref)
check('protected admin detail reads full application',code==200 and number in review and email in review)
code,body,_=student.request('/admin_student_review.php?ref='+ref)
check('ordinary student cannot view admin detail',code==403 and number not in body and email not in body)
code,_,_=admin.request('/admin_student_review.php?ref='+ref,dict(csrf_token='bad',version=version(review),review_action='decline',reason='Synthetic reason'))
check('review mutation requires CSRF',code==403)
code,declined,_=admin.request('/admin_student_review.php?ref='+ref,dict(csrf_token=csrf(review),version=version(review),review_action='decline',reason='Synthetic correction needed'))
check('real decline transition succeeds',code==200 and 'Decision saved.' in declined)
code,owner,_=student.request('/student_verification.php')
check('declined applicant receives own reason',code==200 and 'Synthetic correction needed' in owner)
form.update(csrf_token=csrf(owner),version=version(owner));code,owner,_=student.request('/student_verification.php',form)
check('declined applicant resubmits without duplicate account',code==200 and 'pending administrator review' in owner)
code,review,_=admin.request('/admin_student_review.php?ref='+ref)
decision=dict(csrf_token=csrf(review),version=version(review),review_action='accept',reason='Synthetic authorized eligibility review',evidence_kind='registrar_confirmation',evidence_reference='SYNTHETIC TEST: registrar fixture',ownership_reference='SYNTHETIC TEST: campus contact',verification_period='Synthetic fixture only',expires_date=(datetime.now(timezone.utc)+timedelta(days=30)).date().isoformat(),evidence_confirmed='1')
code,approved,_=admin.request('/admin_student_review.php?ref='+ref,decision)
check('real evidence-recorded admin approval succeeds',code==200 and 'Decision saved.' in approved)
code,body,_=student.request('/access_status.php')
check('approval changes continued access immediately',code==200 and json.loads(body)['continued'])
for page in ('admin_dashboard.php','admin_audit_logs.php','analytics.php'):
    code,body,_=admin.request('/'+page)
    check('existing administrative page: '+page,code==200 and 'Fatal error' not in body and 'Database error' not in body)
code,body,_=admin.request('/admin_db_health.php')
check('existing PostgreSQL database-health endpoint succeeds',code==200 and json.loads(body)['status']=='OK')
code,body,_=admin.request('/analytics.php?action=data&range=all')
check('existing PostgreSQL analytics JSON endpoint succeeds',code==200 and 'kpis' in json.loads(body))
for page in ('history.php','profile.php','terms.php','forgot_password.php'):
    code,body,_=student.request('/'+page)
    check('existing owner page: '+page,code==200 and 'Fatal error' not in body)
code,body,_=student.request('/history.php')
check('another account historical content is not disclosed','Historical summary is preserved verbatim.' not in body)
code,_,_=student.request('/auth.php?action=logout')
check('existing logout works',code in (302,303))
code,login,_=student.request('/login.php')
code,_,_=student.request('/auth.php?action=login',dict(csrf_token=csrf(login),username=email,password='Strong123!'))
code,body,_=student.request('/access_status.php')
check('existing password login preserves approval',code==200 and json.loads(body)['continued'])
report=dict(recorded_utc=datetime.now(timezone.utc).isoformat(),configured_database_modified=False,nlp_executed=False,passed=len(checks),failed=0,checks=checks)
(Path(__file__).resolve().parents[2]/'docs/student-number-access/tests-postgres-repair-http.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
print(json.dumps(dict(passed=len(checks),failed=0)))
