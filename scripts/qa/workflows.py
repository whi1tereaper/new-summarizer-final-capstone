"""Real XAMPP HTTP workflows; requires the runner's restricted database."""
import json, os, re, secrets, subprocess, time, sys
from pathlib import Path
import requests
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/"scripts"))
from test_runtime_repair import SOURCE
BASE="http://127.0.0.1:58139/"
PRIVATE=Path(os.environ["QA_PRIVATE_DIR"])
checks=[]
def check(name,ok,detail=None):
    checks.append({"name":name,"passed":bool(ok),"detail":detail})
    print(("PASS " if ok else "FAIL ")+name,flush=True)
    (ROOT/"docs/qa/http-results.json").write_text(json.dumps(checks,indent=2),encoding="utf8")
    return bool(ok)
def token(s,page="summarizer.php"):
    r=s.get(BASE+page,timeout=30)
    m=re.search(r'name="csrf_token"\s+value="([^"]+)"',r.text)
    if not m:raise AssertionError("CSRF not found: "+page+" HTTP "+str(r.status_code))
    return m[1]
def mail_code(to):
    for _ in range(20):
        if (PRIVATE/"mail.json").exists():
            for m in reversed(json.loads((PRIVATE/"mail.json").read_text())):
                if to in m["to"]:
                    found=re.search(r'\b(\d{6})\b',m["body"])
                    if found:return found[1]
        time.sleep(.1)
    raise AssertionError("No captured mail for controlled fixture")
def login(s,who,password="QaStrong123!"):
    return s.post(BASE+"auth.php?action=login",data={"csrf_token":token(s,"login.php"),"username":who,"password":password},allow_redirects=False,timeout=30)
def register(number,email=None):
    s=requests.Session();data={"student_number":number,"password":"QaStrong123!","confirm_password":"QaStrong123!","terms_accept":"1","csrf_token":token(s,"register.php")}
    if email:data["email"]=email
    return s,s.post(BASE+"auth.php?action=register",data=data,allow_redirects=False,timeout=30)
def summarize(s,kind="text"):
    fields={"csrf_token":token(s),"source_type":kind,"original_text":SOURCE if kind=="text" else "","document_title":"QA isolated "+kind+" "+secrets.token_hex(4),"analysis_mode":"general","output_format":"paragraph","summary_depth":"brief","request_token":secrets.token_hex(16)}
    files=None
    if kind=="url":fields["source_url"]="https://www.nasa.gov/news-release/nasa-isro-satellite-lifts-off-to-track-earths-changing-surfaces/"
    if kind in ("pdf","docx"):
        fields["source_type"]="file";p=PRIVATE/("source."+kind)
        if kind=="docx":
            from docx import Document
            doc=Document()
            for paragraph in SOURCE.split("\n\n"):doc.add_paragraph(paragraph)
            doc.save(p)
        else:
            import importlib.util
            spec=importlib.util.spec_from_file_location("qa_pdf",ROOT/"python-engine/tests/test_failure_feedback.py")
            mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod);mod.pdf_fixture(p,text=SOURCE)
        files={"pdf_file":(p.name,p.read_bytes(),"application/octet-stream")}
    r=s.post(BASE+"summarize.php",data=fields,files=files,headers={"Accept":"application/json"},timeout=40)
    payload=r.json()
    if not payload.get("success"):return None,payload
    job=payload["job_id"]
    s.post(BASE+"summarize.php?action=execute",data={"csrf_token":fields["csrf_token"],"job_id":job},timeout=40)
    end=time.monotonic()+240
    while time.monotonic()<end:
        state=s.get(BASE+"summarize.php",params={"action":"status","job_id":job},timeout=30).json()
        if state.get("status") not in ("queued","processing"):break
        time.sleep(.5)
    if state.get("status")!="completed":return None,state
    result=s.get(BASE+state["redirect"],timeout=30)
    check(kind+" result HTTP and saved title",result.status_code==200 and fields["document_title"] in result.text)
    return (int(re.search(r'id=(\d+)',state["redirect"])[1]),fields["document_title"]),state

def main():
    r=requests.get(BASE+"register.php")
    check("Apache registration renders ID only",r.status_code==200 and 'name="student_number"' in r.text and 'name="full_name"' not in r.text and 'type="file"' not in r.text)
    existing=requests.Session()
    check("existing controlled account authenticates",login(existing,"qa_existing").headers.get("Location")=="index.php")
    check("ordinary account denied admin",existing.get(BASE+"admin_dashboard.php",allow_redirects=False).status_code in (302,403))
    subprocess.check_output(['C:/xampp/php/php.exe','scripts/qa/registry_probe.php','prepare-legacy-workflow'],cwd=ROOT,text=True)
    s=requests.Session();r=login(s,"qa_legacy_workflow")
    if not check("historical unverified account can still sign in",r.headers.get("Location")=="index.php",r.status_code):
        check("legacy login failure response",False);return
    check("historical unverified student restricted",s.get(BASE+"access_status.php").json()["continued"] is False)
    check("existing account uses activation screen not waiting screen", "Your Student Account Is Not Verified" in s.get(BASE+"student_verification.php").text)
    other,dup=register("pen2023-02071")
    check("duplicate registration rejected safely",dup.headers.get("Location")=="register.php")
    check("anonymous review denied",requests.get(BASE+"admin_student_review.php?ref="+"a"*32).status_code==403)
    admin=requests.Session();before=admin.cookies.get("LIGHT_QA_SESSID")
    r=login(admin,"qa_admin")
    check("admin password requires second factor","login.php" in r.headers.get("Location","") and "pending_admin" not in r.text)
    code=mail_code("admin@example.invalid")
    r=admin.post(BASE+"auth.php?action=verifyAdminLoginCode",data={"csrf_token":token(admin,"login.php?context=admin"),"code":code},allow_redirects=False)
    check("admin captured-mail second factor login",r.headers.get("Location")=="admin_dashboard.php")
    dashboard=admin.get(BASE+"admin_dashboard.php",timeout=30)
    check("complete admin dashboard renders",dashboard.status_code==200 and "Student Verification" in dashboard.text and "Fatal error" not in dashboard.text)
    refs=re.findall(r'admin_student_review.php\?ref=([a-f0-9]{32})',dashboard.text)
    ref=next((ref for ref in refs if "PEN2023-02071" in admin.get(BASE+"admin_student_review.php?ref="+ref).text),None)
    if not ref:raise AssertionError("Pending request not in admin dashboard")
    check("student cannot inspect admin detail",s.get(BASE+"admin_student_review.php?ref="+ref).status_code==403)
    def review(ref,action,extra={}):
        page=admin.get(BASE+"admin_student_review.php?ref="+ref)
        version=re.search(r'name="version" value="(\d+)"',page.text)[1]
        data={"csrf_token":re.search(r'name="csrf_token" value="([^"]+)"',page.text)[1],"version":version,"review_action":action,"reason":"Synthetic QA trusted-record review",**extra}
        return admin.post(BASE+"admin_student_review.php?ref="+ref,data=data)
    page=admin.get(BASE+"admin_student_review.php?ref="+ref)
    r=admin.post(BASE+"admin_student_review.php?ref="+ref,data={"csrf_token":"bad","review_action":"accept","version":"1"})
    check("admin CSRF enforced",r.status_code==403)
    import datetime
    evidence={"evidence_kind":"registrar_confirmation","evidence_reference":"SYNTHETIC QA registrar record","ownership_reference":"SYNTHETIC QA campus ownership check","verification_period":"QA only","expires_date":(datetime.date.today()+datetime.timedelta(days=30)).isoformat(),"evidence_confirmed":"1"}
    r=review(ref,"accept",evidence)
    check("admin approval with required evidence",r.status_code==200 and "Decision saved" in r.text)
    check("approved account gains access without relogin",s.get(BASE+"access_status.php").json()["continued"] is True)
    stale=admin.post(BASE+"admin_student_review.php?ref="+ref,data={"csrf_token":token(admin,"admin_student_review.php?ref="+ref),"version":"1","review_action":"decline","reason":"Stale QA decision"})
    check("stale review cannot reverse approval","This request changed" in stale.text and s.get(BASE+"access_status.php").json()["continued"] is True)
    rejected,r=register("PEN2024-01234","decline@example.invalid")
    dashboard=admin.get(BASE+"admin_dashboard.php")
    ref2=next(ref for ref in re.findall(r'admin_student_review.php\?ref=([a-f0-9]{32})',dashboard.text) if "PEN2024-01234" in admin.get(BASE+"admin_student_review.php?ref="+ref).text)
    r=review(ref2,"decline")
    check("admin decline and student notification",r.status_code==200 and "Declined" in rejected.get(BASE+"student_verification.php").text)
    check("declined account has no continued privilege",rejected.get(BASE+"access_status.php").json()["continued"] is False)
    for page in ("admin_audit_logs.php","admin_db_health.php","analytics.php","admin_feedback_feed.php","dashboard.php"):
        r=admin.get(BASE+page,timeout=30);check("admin page "+page,r.status_code==200 and "Fatal error" not in r.text)
        if page=="admin_db_health.php":check("admin database health payload",r.json().get("status")=="OK",r.json().get("status"))
    result,state=summarize(s)
    check("text real NLP completes",result is not None,state.get("failure",{}).get("code"))
    if result:
        summary_id,title=result
        check("history persists own summary",title in s.get(BASE+"history.php").text)
        check("foreign result ownership blocked",title not in other.get(BASE+"result.php?id="+str(summary_id)).text)
        t=token(s)
        r=s.post(BASE+"translate_proxy.php",json={"csrf_token":t,"text":"The library helps students understand academic documents.","target_lang":"tl"},timeout=90)
        try:data=r.json()
        except ValueError:data={}
        check("real Filipino translation",r.status_code==200 and data.get("translated") and data["translated"]!="The library helps students understand academic documents.",{"http":r.status_code,"code":data.get("code")})
        r=s.post(BASE+"tts_generate.php",json={"csrf_token":t,"summary_id":summary_id,"language":"en","text":"The library helps students study."},timeout=180)
        try:data=r.json()
        except ValueError:data={}
        check("real local audio generation",r.status_code==200 and data.get("success"),{"http":r.status_code,"message":data.get("message")})
        if data.get("success"):
            audio=s.get(BASE+data["audio_url"],timeout=30)
            check("audio playback response contains WAV",audio.status_code==200 and audio.content[:4]==b"RIFF",{"http":audio.status_code,"type":audio.headers.get("Content-Type"),"bytes":len(audio.content)})
            partial=s.get(BASE+data["audio_url"],headers={"Range":"bytes=0-43"},timeout=30)
            check("audio supports browser seeking ranges",partial.status_code==206 and partial.content[:4]==b"RIFF" and len(partial.content)==44)
            check("audio URL denies foreign session",other.get(BASE+data["audio_url"]).status_code in (403,404))
        r=s.post(BASE+"feedback_submit.php",data={"csrf_token":t,"summary_id":summary_id,"rating":"5","comment":"Synthetic QA fixture feedback"},headers={"Accept":"application/json"})
        check("HTTP feedback submission",r.status_code==200,{"http":r.status_code})
    for kind in ("pdf","docx","url"):
        result,state=summarize(s,kind)
        first_failure=state.get("failure",{}).get("code")
        if kind=="url" and result is None and first_failure in ("URL_TIMEOUT","URL_FETCH_FAILED"):
            print("OBSERVED external URL "+first_failure+"; one controlled retry",flush=True)
            result,state=summarize(s,kind)
        check(kind+" real NLP completes",result is not None,{"first_failure":first_failure,"final_failure":state.get("failure",{}).get("code")})
    # Three actual successes across source types use one anonymous quota.
    guest=requests.Session()
    t=token(guest)
    failed=guest.post(BASE+"summarize.php",data={"csrf_token":t,"source_type":"text","original_text":""},headers={"Accept":"application/json"})
    check("invalid summary consumes zero guest attempts",failed.status_code==422 and guest.get(BASE+"access_status.php").json()["remaining"]==3)
    for i in range(3):
        result,state=summarize(guest)
        check("guest successful quota "+str(i+1),result is not None and guest.get(BASE+"access_status.php").json()["remaining"]==2-i)
    result,state=summarize(guest)
    check("fourth guest request denied before NLP",result is None and state.get("failure",{}).get("code")=="GUEST_LIMIT_REACHED")
    t=token(guest)
    api=guest.post(BASE+"api/documents",json={"csrf_token":t,"text":SOURCE})
    check("alternate document API enforces exhausted quota",api.status_code==403 and api.json().get("failure",{}).get("code")=="GUEST_LIMIT_REACHED")
    nutshell=guest.post(BASE+"nutshell_generate.php",data={"csrf_token":t,"text":SOURCE,"request_key":secrets.token_hex(16)})
    check("anonymous Nutshell retains account requirement",nutshell.status_code==401 and nutshell.json().get("error")=="registration_required",{"http":nutshell.status_code,"response_code":nutshell.json().get("error")})
    pending_data={"student_number":"PEN2025-09876","password":"QaStrong123!","confirm_password":"QaStrong123!","terms_accept":"1","csrf_token":token(guest,"register.php")}
    registration=guest.post(BASE+"auth.php?action=register",data=pending_data,allow_redirects=False)
    check("registering after trial exhaustion preserves zero quota",registration.headers.get("Location")=="student_verification.php" and guest.get(BASE+"access_status.php").json()["remaining"]==0)
    nutshell=guest.post(BASE+"nutshell_generate.php",data={"csrf_token":token(guest),"text":SOURCE,"request_key":secrets.token_hex(16)})
    check("pending Nutshell cannot bypass exhausted quota",nutshell.status_code==403 and nutshell.json().get("failure",{}).get("code")=="GUEST_LIMIT_REACHED")
    recovery=requests.Session()
    r=recovery.post(BASE+"auth.php?action=forgotPassword",data={"csrf_token":token(recovery,"forgot_password.php"),"email":"existing@example.invalid"},allow_redirects=False)
    otp=mail_code("existing@example.invalid")
    r=recovery.post(BASE+"auth.php?action=resetPassword",data={"csrf_token":token(recovery,"reset_password.php?email=existing%40example.invalid"),"email":"existing@example.invalid","otp":otp,"password":"QaNewStrong123!","confirm_password":"QaNewStrong123!"},allow_redirects=False)
    check("password reset through captured OTP",r.headers.get("Location","").startswith("login.php"))
    check("reset controlled credentials authenticate",login(requests.Session(),"qa_existing","QaNewStrong123!").headers.get("Location")=="index.php")
    replay=recovery.post(BASE+"auth.php?action=resetPassword",data={"csrf_token":token(recovery,"reset_password.php"),"email":"existing@example.invalid","otp":otp,"password":"QaAnother123!","confirm_password":"QaAnother123!"},allow_redirects=False)
    check("used reset OTP cannot be replayed",replay.headers.get("Location")=="reset_password.php")
    old=s.cookies.get("LIGHT_QA_SESSID")
    check("student number password login",login(s,"pen2023-02071").headers.get("Location")=="index.php")
    check("login rotates session ID",s.cookies.get("LIGHT_QA_SESSID")!=old)
    r=s.post(BASE+"auth.php?action=logout",allow_redirects=False)
    check("logout removes account privilege",s.get(BASE+"access_status.php").json()["continued"] is False)
    check("session cookie security",all(c.has_nonstandard_attr("HttpOnly") and c._rest.get("SameSite")=="Lax" for c in admin.cookies if c.name=="LIGHT_QA_SESSID"))

try:main()
except Exception as exc:check("workflow exception",False,type(exc).__name__+": "+str(exc)[:400])
sys.exit(1 if any(not c["passed"] for c in checks) else 0)
