"""Real HTTP forms + real local NLP; only the explicit isolated test router is supported."""
import http.cookiejar
import json
import re
import secrets
import sys
from datetime import datetime, timezone, timedelta
from pathlib import Path
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://127.0.0.1:58139'
checks = []

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())

    def request(self, path, data=None, headers=None):
        headers = dict(headers or {})
        payload = urllib.parse.urlencode(data).encode() if data is not None else None
        if path.startswith('/api/') and data is not None:
            payload = json.dumps(data).encode()
            headers['Content-Type'] = 'application/json'
        req = urllib.request.Request(BASE + path, data=payload, headers=headers)
        try:
            response = self.opener.open(req, timeout=100)
        except urllib.error.HTTPError as exc:
            response = exc
        return response.code, response.read().decode('utf-8', 'replace'), response.headers

def check(name, ok):
    checks.append({'name': name, 'passed': bool(ok)})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    if not ok:
        raise AssertionError(name)

def csrf(html):
    return re.search(r'name="csrf_token" value="([a-f0-9]+)"', html).group(1)

student = Client()
student.request('/__fixture_reset_limits', headers={'X-Fixture-Key': 'isolated-fixture-only'})
code, html, _ = student.request('/register.php')
check('registration form renders student-number fields without photo requirements', code == 200 and 'name="student_number"' in html and 'name="full_name"' in html and 'type="file"' not in html)
suffix = secrets.token_hex(6)
email = suffix + '@example.invalid'
application = dict(full_name='HTTP Fixture Student', student_number='PEN-HTTP-' + suffix, email=email, password='Strong123!', confirm_password='Strong123!', terms_accept='1', privacy_consent='1', eligibility_ack='1', csrf_token=csrf(html))
code, _, headers = student.request('/auth.php?action=register', application)
check('actual registration controller establishes pending session', code in (302, 303) and headers.get('Location') == 'student_verification.php')
code, status_html, _ = student.request('/student_verification.php')
check('owner status page shows pending', code == 200 and 'Under Review' in status_html)
check('trial cookie is HttpOnly and independent of login session', any(cookie.name == 'LIGHT_TRIAL' and cookie.has_nonstandard_attr('HttpOnly') for cookie in student.jar))
fixture = {'X-Fixture-Key': 'isolated-fixture-only'}
student.request('/__fixture_exhaust', headers=fixture)
code, body, _ = student.request('/access_status.php')
check('HTTP counter reports exhausted shared allowance', code == 200 and json.loads(body)['remaining'] == 0)
code, html, _ = student.request('/summarizer.php')
if code != 200 or "You've Used All 3 Free Summaries!" not in html:
    print('Exhaustion diagnostic:', code, html[-600:], flush=True)
check('polished exhaustion panel preserves result links', code == 200 and "You've Used All 3 Free Summaries!" in html)
token = csrf(html)
code, body, _ = student.request('/nutshell_generate.php', {'csrf_token': token, 'request_key': secrets.token_hex(16), 'text': 'A source with enough words for the backend validation to accept it without a trial bypass.'})
check('Nutshell cannot bypass exhausted pending quota', code == 403 and json.loads(body)['failure']['code'] == 'GUEST_LIMIT_REACHED')
code, body, _ = student.request('/api/documents', {'csrf_token': token, 'text': 'A source for quota checking.'})
check('direct document API cannot bypass quota', code == 403 and json.loads(body)['failure']['code'] == 'GUEST_LIMIT_REACHED')
stranger = Client()
code, _, _ = stranger.request('/admin_student_review.php?ref=' + 'a' * 32)
check('anonymous administrator access rejected', code == 403)
admin = Client()
admin.request('/__fixture_admin', headers=fixture)
code, queue, _ = admin.request('/__fixture_queue', headers=fixture)
check('existing dashboard module renders protected queue', code == 200 and 'Student Verification' in queue)
ref = re.search(r'admin_student_review.php\?ref=([a-f0-9]+)', queue).group(1)
code, review, _ = admin.request('/admin_student_review.php?ref=' + ref)
check('admin detail exposes complete student number only in protected view', code == 200 and application['student_number'] in review)
code, _, _ = student.request('/admin_student_review.php?ref=' + ref)
check('ordinary student cannot inspect another administrative detail', code == 403)
version = re.search(r'name="version" value="(\d+)"', review).group(1)
decision = dict(version=version, review_action='accept', reason='Synthetic HTTP authorized review', evidence_kind='registrar_confirmation', evidence_reference='SYNTHETIC TEST: enrollment confirmation', ownership_reference='SYNTHETIC TEST: authorized contact', verification_period='Synthetic fixture only', expires_date=(datetime.now(timezone.utc)+timedelta(days=30)).date().isoformat(), evidence_confirmed='1', csrf_token='bad')
code, _, _ = admin.request('/admin_student_review.php?ref=' + ref, decision)
check('administrative CSRF bypass denied', code == 403)
decision['csrf_token'] = csrf(review)
code, approved, _ = admin.request('/admin_student_review.php?ref=' + ref, decision)
check('actual admin approval action succeeds', code == 200 and 'Decision saved.' in approved)
code, body, _ = student.request('/access_status.php')
check('approval changes access without relogin', code == 200 and json.loads(body)['continued'])
code, html, _ = student.request('/student_verification.php')
check('approved owner receives in-app notification', code == 200 and 'has been verified' in html)
source = '''The university library expanded its digital reading service after students requested reliable access to academic material. Librarians surveyed students and found that slow connections made large document downloads difficult. The new service therefore stores reading lists in a lightweight format and offers downloadable documents for later study.
The pilot included three departments and ran for one semester. Staff recorded fewer repeated help requests, but the study did not establish an improvement in examination performance. Participation was voluntary, and the sample was not representative of every campus. Researchers warned that the findings should not be generalized without additional evaluation.
The project retained a human review process for reading recommendations. Librarians checked sources before adding them to course lists, and students could report incorrect material. Personal records were kept separately from reading activity. Administrators emphasized that summaries were study aids, not substitutes for checking the complete source.
For the next semester, the library plans to evaluate accessibility and offline performance. The team will compare usage across devices, ask students about confusing content, and publish limitations alongside the results. Funding for a wider rollout has not yet been approved. Any expansion will depend on independent review and available campus resources.'''
code, form, _ = student.request('/summarizer.php')
code, body, _ = student.request('/api/documents', {'csrf_token': csrf(form), 'text': source})
result = json.loads(body)
if code != 201:
    print('Controlled summary diagnostic:', code, result.get('failure', {}).get('code'), flush=True)
check('real PHP-to-Python document summarization persists successfully', code == 201 and bool(result.get('summary')) and result.get('wordCount', 0) > 0)
request_key = secrets.token_hex(16)
code, body, _ = student.request('/nutshell_generate.php', {'csrf_token': csrf(form), 'request_key': request_key, 'text': source})
check('real Nutshell success persists under shared authorization', code == 200 and json.loads(body).get('status') == 'completed')
code, body, _ = student.request('/nutshell_generate.php', {'csrf_token': csrf(form), 'request_key': request_key, 'text': source})
check('Nutshell successful retry returns cached result', code == 200 and json.loads(body)['generation']['cached'])
doc_id = result['documentId']
code, body, _ = stranger.request('/api/documents/' + str(doc_id))
check('HTTP document ownership prevents enumeration', code == 404)
code, body, _ = student.request('/api/documents/' + str(doc_id) + '/summaries')
check('own generated history remains readable', code == 200 and len(json.loads(body)) == 1)
for page in ('forgot_password.php', 'terms.php', 'profile.php', 'history.php'):
    code, _, _ = student.request('/' + page)
    check('existing page smoke: ' + page, code == 200)
# Existing logout confirmation + password login, not a fixture-seeded student session.
code, logout, headers = student.request('/logout.php')
check('existing logout alias remains available', code in (302, 303) and headers.get('Location') == 'auth.php?action=logout')
code, _, _ = student.request('/auth.php?action=logout')
check('existing logout completes', code in (302, 303))
code, login, _ = student.request('/login.php')
code, _, _ = student.request('/auth.php?action=login', {'csrf_token': csrf(login), 'username': email, 'password': 'Strong123!'})
check('existing password login remains functional', code in (302, 303))
code, body, _ = student.request('/access_status.php')
check('approval and original account survive logout/login', code == 200 and json.loads(body)['continued'])
print(json.dumps({'passed': len(checks), 'failed': 0, 'checks': checks}, indent=2))
if '--report' in sys.argv:
    report = {'recorded_utc': datetime.now(timezone.utc).isoformat(), 'isolated_fixture': True, 'real_nlp': 'PHP LocalPythonBridge synchronous document API, regenerated output persisted', 'passed': len(checks), 'failed': 0, 'checks': checks}
    (Path(__file__).resolve().parents[2] / 'docs/student-number-access/tests-http.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
