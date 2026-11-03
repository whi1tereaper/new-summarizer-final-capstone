"""Check password recovery without sending real mail or resetting real accounts."""
import getpass,json,os,re,socketserver,subprocess,tempfile,threading,time
from pathlib import Path
import requests
from run import SMTP
ROOT=Path(__file__).resolve().parents[2];PHP='C:/php83/php.exe';BASE='http://127.0.0.1:58139/'
REPORT=ROOT/'docs/qa/password-recovery';REPORT.mkdir(parents=True,exist_ok=True)
checks=[];findings=[]
def check(name,ok):
    checks.append({'name':name,'passed':bool(ok)});(REPORT/'checks.json').write_text(json.dumps(checks,indent=2),encoding='utf8');print(('PASS ' if ok else 'FAIL ')+name,flush=True)
class Capture(SMTP):
    def handle(self):
        if getattr(self.server,'reject',False):self.wfile.write(b'421 synthetic QA mail outage\r\n');self.wfile.flush();return
        super().handle()

def main():
    folder=Path(tempfile.mkdtemp(prefix='light-recovery-qa-',dir=os.environ['LOCALAPPDATA'])).resolve()
    subprocess.run(['icacls',str(folder),'/inheritance:r','/grant:r',getpass.getuser()+':(OI)(CI)F'],capture_output=True,check=True)
    (REPORT/'last-run.txt').write_text(str(folder),encoding='utf8')
    setup=subprocess.run([PHP,'scripts/qa/control.php','setup-benchmark',str(folder)],cwd=ROOT,capture_output=True,text=True,check=True)
    env=os.environ.copy();env.update(json.loads((folder/'config.json').read_text())['env']);env['QA_PRIVATE_DIR']=str(folder)
    apache=None;smtp=None
    def probe(action,*args):return json.loads(subprocess.check_output([PHP,'scripts/qa/recovery_probe.php',action,*args],cwd=ROOT,env=env,text=True))
    def csrf(session,page):return re.search(r'name="csrf_token"\s+value="([^"]+)"',session.get(BASE+page,timeout=20).text)[1]
    def mail_count():return len(smtp.messages)
    def request_reset(session,email='new-student@local.invalid',clear=True):
        if clear:probe('limits')
        return session.post(BASE+'auth.php?action=forgotPassword',data={'csrf_token':csrf(session,'forgot_password.php'),'email':email},allow_redirects=False,timeout=25)
    def code():return re.search(r'\b\d{6}\b',next(m['body'] for m in reversed(smtp.messages) if 'new-student@local.invalid' in m['to']))[0]
    def reset(session,otp,password='QAResetStrong456!',email='new-student@local.invalid',confirmation=None):
        return session.post(BASE+'auth.php?action=resetPassword',data={'csrf_token':csrf(session,'reset_password.php'),'email':email,'otp':otp,'password':password,'confirm_password':password if confirmation is None else confirmation},allow_redirects=False,timeout=25)
    try:
        boundary=subprocess.run([PHP,'scripts/qa/isolation.php'],cwd=ROOT,env=env,capture_output=True)
        check('QA credentials cannot access the production database',boundary.returncode==0)
        probe('setup')
        old_folder=Path((ROOT/'docs/qa/student-account-registration/last-run.txt').read_text().strip())
        conf=(old_folder/'apache.conf').read_text(encoding='utf8').replace(old_folder.as_posix(),folder.as_posix())
        (folder/'apache.conf').write_text(conf,encoding='utf8')
        syntax=subprocess.run(['C:/xampp/apache/bin/httpd.exe','-t','-f',str(folder/'apache.conf')],cwd=ROOT,env=env,capture_output=True)
        check('isolated XAMPP Apache and PHP 8.3 configuration validates',syntax.returncode==0)
        if syntax.returncode:raise RuntimeError('QA Apache configuration failed')
        smtp=socketserver.TCPServer(('127.0.0.1',58125),Capture);smtp.messages=[];smtp.folder=folder;smtp.reject=False
        threading.Thread(target=smtp.serve_forever,daemon=True).start()
        apache=subprocess.Popen(['C:/xampp/apache/bin/httpd.exe','-f',str(folder/'apache.conf')],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);time.sleep(2)
        student=requests.Session();registration=student.post(BASE+'auth.php?action=register',data={'csrf_token':csrf(student,'register.php'),'student_number':'PEN2093-550001','email':'New-Student@LOCAL.INVALID','password':'QaStrong123!','confirm_password':'QaStrong123!','terms_accept':'1'},allow_redirects=False)
        check('student creates their own account and email through real registration',registration.headers.get('Location')=='login.php' and probe('state')['password_original_valid'])
        check('Forgot Password is linked from Sign In and both recovery pages open','forgot_password.php' in student.get(BASE+'login.php').text and student.get(BASE+'forgot_password.php').status_code==200 and student.get(BASE+'reset_password.php').status_code==200)
        bad=student.post(BASE+'auth.php?action=forgotPassword',data={'csrf_token':'invalid','email':'new-student@local.invalid'},allow_redirects=False)
        check('reset requests require valid CSRF',bad.status_code==403 and mail_count()==0)
        invalid=request_reset(student,'invalid-email');check('invalid email is rejected without sending a code',invalid.headers.get('Location')=='forgot_password.php' and mail_count()==0)
        unknown=requests.Session();before=probe('state')['reset_rows'];response=request_reset(unknown,'unknown@local.invalid')
        check('unknown email has generic feedback without outgoing mail or token rows',response.headers.get('Location')=='reset_password.php' and mail_count()==0 and probe('state')['reset_rows']==before)
        response=request_reset(student,'  NEW-STUDENT@LOCAL.INVALID  ');first=code();state=probe('state')
        check('the code is addressed to the normalized user-provided email',response.headers.get('Location')=='reset_password.php' and mail_count()==1 and 'new-student@local.invalid' in smtp.messages[-1]['to'])
        check('the code is hashed and expires in approximately 15 minutes',state['token']['hashed'] and 840<=state['token']['seconds_remaining']<=900)
        response=request_reset(student,clear=False);check('repeat email requests are rate limited',response.headers.get('Location')=='forgot_password.php' and mail_count()==1)
        probe('limits');response=reset(student,'000000');check('incorrect code cannot change the password',response.headers.get('Location')=='reset_password.php' and probe('state')['password_original_valid'])
        response=reset(student,'abc');check('non-numeric codes are rejected',response.headers.get('Location')=='reset_password.php' and probe('state')['password_original_valid'])
        response=reset(student,first,confirmation='DifferentStrong789!');check('password confirmation mismatch is rejected',response.headers.get('Location')=='reset_password.php' and probe('state')['password_original_valid'])
        response=reset(student,first,password='weak');check('weak replacement passwords are rejected',response.headers.get('Location')=='reset_password.php' and probe('state')['password_original_valid'])
        response=reset(student,first,email='existing@example.invalid');check('a code cannot reset another email account',response.headers.get('Location')=='reset_password.php' and probe('state','existing@example.invalid')['password_original_valid'])
        response=request_reset(student);second=code();check('a new recovery request invalidates the previous code',first!=second and reset(student,first).headers.get('Location')=='reset_password.php' and probe('state')['password_original_valid'])
        forged=student.post(BASE+'auth.php?action=resetPassword',data={'csrf_token':'invalid','email':'new-student@local.invalid','otp':second,'password':'QAResetStrong456!','confirm_password':'QAResetStrong456!'},allow_redirects=False)
        check('password replacement itself requires CSRF',forged.status_code==403 and probe('state')['password_original_valid'])
        response=reset(student,second);state=probe('state');check('a valid email and code update only the QA password and consume the code',response.headers.get('Location')=='login.php' and state['password_new_valid'] and not state['password_original_valid'] and state['token']['used'])
        response=reset(student,second,password='AnotherStrong789!');check('a consumed code cannot be reused',response.headers.get('Location')=='reset_password.php' and probe('state')['password_new_valid'])
        login=requests.Session();r=login.post(BASE+'auth.php?action=login',data={'csrf_token':csrf(login,'login.php'),'username':'new-student@local.invalid','password':'QAResetStrong456!'},allow_redirects=False)
        check('the recovered email and new password sign in successfully',r.headers.get('Location')=='index.php' and login.get(BASE+'access_status.php').json()['continued'])
        old_login=requests.Session();r=old_login.post(BASE+'auth.php?action=login',data={'csrf_token':csrf(old_login,'login.php'),'username':'new-student@local.invalid','password':'QaStrong123!'},allow_redirects=False)
        check('the old password no longer authenticates',r.headers.get('Location')=='login.php')
        request_reset(student);expired=code();probe('expire');r=reset(student,expired)
        check('an expired code cannot change the password',r.headers.get('Location')=='reset_password.php' and probe('state')['password_new_valid'])
        request_reset(student);current=code();probe('limits')
        for _ in range(5):reset(student,'000000')
        r=reset(student,current);check('five wrong attempts block further verification in the current window',r.headers.get('Location')=='reset_password.php' and 'Too many verification attempts' in student.get(BASE+'reset_password.php').text and probe('state')['password_new_valid'])
        smtp.reject=True;before=mail_count();response=request_reset(student)
        says_sent=probe('flash',student.cookies.get('LIGHT_QA_SESSID'))['says_sent']
        check('mail transport failure does not change the password or send a message',mail_count()==before and probe('state')['password_new_valid'])
        if response.headers.get('Location')=='reset_password.php' and says_sent:
            findings.append({'severity':'warning','finding':'SMTP failure is ignored by forgotPassword: the session still says a code was sent, although no message was delivered.'})
        reset_html=student.get(BASE+'reset_password.php').text
        if 'If your email exists, a 6-digit recovery code was sent.' not in reset_html:
            findings.append({'severity':'ui','finding':'The reset page does not render the success flash set by the recovery request.'})
        smtp.reject=False
    except Exception as error:check('password recovery exception: '+type(error).__name__,False)
    finally:
        if apache:subprocess.run(['taskkill','/PID',str(apache.pid),'/T','/F'],capture_output=True)
        if smtp:smtp.shutdown();smtp.server_close()
        verify=subprocess.run([PHP,'scripts/qa/control.php','verify',str(folder)],cwd=ROOT,capture_output=True,text=True)
        check('original production user passwords summaries and feedback are preserved',verify.returncode==0)
        cleanup=subprocess.run([PHP,'scripts/qa/control.php','cleanup',str(folder)],cwd=ROOT,capture_output=True)
        check('isolated databases and credentials are removed',cleanup.returncode==0)
        if (folder/'integrity.json').exists():(REPORT/'integrity.json').write_bytes((folder/'integrity.json').read_bytes())
        for filename in ['mail.json']+(['config.json'] if cleanup.returncode==0 else []):(folder/filename).unlink(missing_ok=True)
        summary={'passed':sum(row['passed'] for row in checks),'failed':sum(not row['passed'] for row in checks),'findings':findings,'real_messages_sent':0,'real_passwords_changed':0}
        (REPORT/'summary.json').write_text(json.dumps(summary,indent=2),encoding='utf8');print(json.dumps(summary),flush=True)
    return int(any(not row['passed'] for row in checks))
if __name__=='__main__':raise SystemExit(main())
