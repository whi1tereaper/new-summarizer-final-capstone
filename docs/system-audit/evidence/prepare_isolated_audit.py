from pathlib import Path
import ast,shutil,json,subprocess,os
root=Path.cwd(); out=root/'docs/system-audit/evidence'; iso=out/'isolated-project'; iso.mkdir(exist_ok=True)
for top in ['python-engine','app','scripts','tools','database']:
    for p in (root/top).rglob('*'):
        if not p.is_file() or '__pycache__' in p.parts: continue
        if p.suffix.lower() not in ['.py','.php','.json','.sql','.txt','.md','.ps1','.csv']: continue
        dest=iso/p.relative_to(root); dest.parent.mkdir(parents=True,exist_ok=True); shutil.copyfile(p,dest)
for name in ['.env.example','composer.json','QUICK_START.txt','SETUP_GUIDE.txt']:
    shutil.copyfile(root/name,iso/name)
records=[]
for p in (root/'python-engine').rglob('*.py'):
    if '__pycache__' in p.parts:continue
    try:
        tree=ast.parse(p.read_text(encoding='utf-8-sig'))
        funcs=[{'name':n.name,'line':n.lineno,'kind':type(n).__name__} for n in ast.walk(tree) if isinstance(n,(ast.ClassDef,ast.FunctionDef,ast.AsyncFunctionDef))]
        imports=[ast.unparse(n) for n in ast.walk(tree) if isinstance(n,(ast.Import,ast.ImportFrom))]
        records.append({'file':p.relative_to(root).as_posix(),'syntax':'pass','definitions':funcs,'imports':imports})
    except Exception as e:records.append({'file':p.relative_to(root).as_posix(),'syntax':'fail','error':str(e)})
(out/'python-source-map.json').write_text(json.dumps(records,indent=2),encoding='utf-8')
print('Isolated source copy:',iso,'Python files parsed:',len(records),'syntax errors:',sum(x['syntax']=='fail' for x in records))
