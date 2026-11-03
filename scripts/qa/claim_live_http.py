"""Read-only port-80 smoke checks. Never submits real credentials or registration."""
import json
from pathlib import Path
import requests
ROOT=Path(__file__).resolve().parents[2]
BASE='http://127.0.0.1/ai-summarizer-123%20-%20quality%20upg/app/public/'
session=requests.Session();session.trust_env=False;checks=[]
def check(name,ok):checks.append({'name':name,'passed':bool(ok)})
try:
    page=session.get(BASE+'register.php',timeout=30)
    check('live Apache registration is available without a new workflow',page.status_code==200 and 'name="student_number"' in page.text and 'name="email"' in page.text and 'name="confirm_password"' in page.text)
    check('live registration retains the fixed PEN prefix and required email','student-number-prefix' in page.text and '>PEN</span>' in page.text and 'type="file"' not in page.text)
    login=session.get(BASE+'login.php',timeout=30)
    check('existing Sign In and password recovery link remain available',login.status_code==200 and 'forgot_password.php' in login.text and 'name="password"' in login.text)
    admin=session.get(BASE+'admin_student_registry.php',timeout=30)
    check('live Student ID management denies anonymous visitors',admin.status_code==403)
except requests.RequestException as error:check('live read-only HTTP exception: '+type(error).__name__,False)
result={'mutating_requests_sent':False,'passed':sum(c['passed'] for c in checks),'failed':sum(not c['passed'] for c in checks),'checks':checks}
(ROOT/'docs/qa/student-account-claim/live-http.json').write_text(json.dumps(result,indent=2),encoding='utf8');print(json.dumps(result,indent=2))
raise SystemExit(1 if result['failed'] else 0)
