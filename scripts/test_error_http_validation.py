import io,json,os,re,zipfile
from pathlib import Path
import requests
ROOT=Path(__file__).resolve().parents[1]
BASE=os.environ.get("LIGHT_TEST_URL", "http://localhost/app/public/")
s=requests.Session(); html=s.get(BASE+"summarizer.php").text
csrf=re.search(r'name="csrf_token" value="([^"]+)"',html).group(1)
fields={"csrf_token":csrf,"source_type":"file","original_text":"","analysis_mode":"general","summary_depth":"brief","output_format":"paragraph"}
results=[]
def check(name,expected,**kwargs):
    response=s.post(BASE+"summarize.php",headers={"Accept":"application/json"},timeout=45,**kwargs)
    data=response.json(); assert data["failure"]["code"]==expected,(name,data)
    assert "job_id" not in data
    results.append({"name":name,"code":expected,"status":response.status_code,"passed":True})
    print("PASS",name,expected,flush=True)
check("actual file over30MiB","FILE_TOO_LARGE",data=fields,files={"pdf_file":("oversized.pdf",b"%PDF-1.7\n"+b"x"*(30*1024*1024),"application/pdf")})
check("text masquerading asPDF","FILE_UNSUPPORTED",data=fields,files={"pdf_file":("fake.pdf",b"This is just text.","application/pdf")})
buf=io.BytesIO()
with zipfile.ZipFile(buf,"w") as archive: archive.writestr("readme.txt","This is not a Word package.")
check("ZIP masquerading asDOCX","FILE_CORRUPTED",data=fields,files={"pdf_file":("fake.docx",buf.getvalue(),"application/vnd.openxmlformats-officedocument.wordprocessingml.document")})
check("existing255character title limit","TITLE_TOO_LONG",data=fields|{"source_type":"text","original_text":"The public library opens on Monday morning and provides free internet access for residents.","document_title":"x"*256})
check("unresolvable article host","URL_UNREACHABLE",data=fields|{"source_type":"url","source_url":"https://light-error-fixture.invalid/article"})
response=s.post(BASE+"nutshell_generate.php",data={"csrf_token":csrf},timeout=15)
assert response.status_code==401 and response.json()["failure"]["code"]=="AUTH_REQUIRED"
results.append({"name":"Nutshell guest restriction","code":"AUTH_REQUIRED","status":401,"passed":True})
(ROOT/"artifacts/error-feedback/extra-http-results.json").write_text(json.dumps(results,indent=2))
