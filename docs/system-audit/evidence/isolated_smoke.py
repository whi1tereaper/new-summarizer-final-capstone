from pathlib import Path
import sys,json,os
root=Path(__file__).resolve().parent
iso=root/'isolated-project'; sys.path.insert(0,str(iso/'python-engine'))
os.environ['LOCALAPPDATA']=str(iso);os.environ['SUMMARIZER_LOCAL_EMBEDDING_MODEL']='';os.environ['SUMMARIZER_LANGUAGETOOL_DIR']=''
from pypdf import PdfWriter,PdfReader
from pypdf.generic import DictionaryObject,NameObject,DecodedStreamObject
text='The community library opened a new reading room on Monday. The room contains 120 books and has space for 24 visitors. Staff will provide reading support each afternoon. The program is a six month pilot and has not been evaluated. Visitors must register at the front desk before using the room. The library will collect anonymous feedback after the pilot.'
w=PdfWriter();w.add_blank_page(612,792);page=w.add_blank_page(612,792)
font=DictionaryObject({NameObject('/Type'):NameObject('/Font'),NameObject('/Subtype'):NameObject('/Type1'),NameObject('/BaseFont'):NameObject('/Helvetica')})
page[NameObject('/Resources')]=DictionaryObject({NameObject('/Font'):DictionaryObject({NameObject('/F1'):w._add_object(font)})})
s=DecodedStreamObject();s.set_data(('BT /F1 12 Tf 30 700 Td ('+text+') Tj ET').encode());page[NameObject('/Contents')]=w._add_object(s)
fixture=root/'synthetic-blank-first-page.pdf'
with fixture.open('wb') as f:w.write(f)
from summarizer_core.extraction import extract_pdf_source
from local_cli import handle_qa,handle_summarize,handle_nutshell
results=[]
try:
    src=extract_pdf_source(str(fixture));results.append({'id':'T-PDF-PAGE','expected':'page_texts retains two physical page slots','actual':{'physical_pages':len(PdfReader(fixture).pages),'page_text_slots':len(src.page_texts)},'status':'FAIL' if len(src.page_texts)!=2 else 'PASS','scope':'direct isolated extraction function'})
except Exception as e:results.append({'id':'T-PDF-PAGE','status':'ERROR','error':str(e)})
for ident,func,payload in [('T-QA',handle_qa,{'question':'How many books does the reading room contain?','text':text}),('T-SUMMARY',handle_summarize,{'text':text,'analysis_mode':'general','summary_depth':'brief','output_format':'paragraph'}),('T-NUTSHELL',handle_nutshell,{'text':text,'analysis_mode':'general'})]:
    try:
        result=func(payload);results.append({'id':ident,'status':'PASS' if result else 'FAIL','scope':'direct isolated Python function; no PHP/database/browser integration','result':result})
    except Exception as e:results.append({'id':ident,'status':'ERROR','error':type(e).__name__+': '+str(e)})
(root/'isolated-smoke-results.json').write_text(json.dumps(results,indent=2,ensure_ascii=False),encoding='utf-8')
print([{k:v for k,v in x.items() if k!='result'} for x in results])
