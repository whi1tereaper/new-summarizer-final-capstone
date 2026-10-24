from pathlib import Path
import sys,json,os
r=Path('docs/system-audit/evidence').resolve();sys.path.insert(0,str(r/'isolated-project/python-engine'));os.environ['LOCALAPPDATA']=str(r/'isolated-project')
from summarizer_core.cleaning import prepare_document_paragraphs
from summarizer_core.models import PreprocessingOptions
from local_cli import handle_summarize
text='The community library opened a new reading room on Monday. The room contains 120 books and has space for 24 visitors. Staff will provide reading support each afternoon. The program is a six month pilot and has not been evaluated. Visitors must register at the front desk before using the room. The library will collect anonymous feedback after the pilot.'
variants={'original':text,'without_register':text.replace('register','check in'),'paragraphs':text.replace('. ','.\n\n')}
result=[]
for k,t in variants.items():
 cleaned=prepare_document_paragraphs(t,PreprocessingOptions());result.append({'id':k,'cleaned_paragraph_count':len(cleaned),'cleaned':cleaned})
fixture=json.loads(Path('python-engine/tests/fixtures/evaluation_validation_documents.json').read_text(encoding='utf-8'))['documents'][2]
try:
 s=handle_summarize({'text':fixture['source_text'],'analysis_mode':'technical','summary_depth':'balanced','output_format':'paragraph'})
 result.append({'id':'T-SUMMARY-NIST','status':'PASS','source_fixture':fixture['id'],'result':s})
except Exception as e:result.append({'id':'T-SUMMARY-NIST','status':'ERROR','error':str(e)})
(r/'isolated-smoke-followup.json').write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8');print([{k:v for k,v in x.items() if k not in ['result','cleaned']} for x in result])
