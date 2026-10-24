"""Server-owned opt-in on an isolated PHP server; global Apache routing stays off."""
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import time
import requests

root=Path(__file__).resolve().parents[1]
with socket.socket() as probe:
    probe.bind(('127.0.0.1',0)); port=probe.getsockname()[1]
env=dict(os.environ,DATABASE_URL='mysql://local-runtime-fixture',SUMMARIZER_AUTO_ARTICLE_ROUTING='true',
         LIGHT_ARTICLE_NLP_ENHANCED='false',PYTHON_WORKER_PORT='1')
report={'kind':'isolated_local_php_http_server_not_apache','global_routing_changed':False,'cases':[]}
server=subprocess.Popen([r'C:\xampp\php\php.exe','-S','127.0.0.1:'+str(port),'-t','app/public'],
                        cwd=root,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
try:
    base='http://127.0.0.1:'+str(port)+'/'
    for _ in range(40):
        try:
            if requests.get(base+'health.php',timeout=1).status_code==200: break
        except requests.RequestException: pass
        time.sleep(.25)
    cases=[('article '+depth,['--depth',depth],'recipe_article') for depth in
           ['very_brief','brief','short','balanced','detailed','comprehensive']]
    cases += [('PDF opt-in',['--fixture','pdf'],{'recipe_article','legacy'}),
              ('DOCX opt-in',['--fixture','docx'],{'recipe_article','legacy'}),
              ('supported Focus',['--focus','staffing costs'],'recipe_article'),
              ('absent Focus',['--focus','volcanic seismology'],'recipe_article'),
              ('live URL opt-in',['--url','https://www.nasa.gov/news-release/nasa-isro-satellite-lifts-off-to-track-earths-changing-surfaces/'],{'recipe_article','legacy'}),
              ('nonarticle retains legacy',['--nonarticle'],'legacy')]
    for name,args,expected in cases:
        run=subprocess.run([sys.executable,'-X','utf8','scripts/test_runtime_repair.py','--base',base,'--host','127.0.0.1',
                            '--kind','isolated_php_server_opt_in',*args],cwd=root,capture_output=True,text=True,encoding='utf8',timeout=360)
        kind=args[args.index('--fixture')+1] if '--fixture' in args else 'url' if '--url' in args else 'text'
        evidence=json.loads((root/('docs/research/runtime-repair-http-'+kind+'.json')).read_text(encoding='utf8'))
        result_id=int(evidence['observations'][-1].get('redirect','id=0').split('id=')[-1])
        inspection=subprocess.run([r'C:\xampp\php\php.exe','scripts/inspect_summary_runtime.php','--local-mysql','--verify-result',str(result_id)],
                                  cwd=root,capture_output=True,text=True,encoding='utf8',check=True)
        data=json.loads(inspection.stdout)
        metadata=data.get('verified_result',{})
        selected=metadata.get('engine_routing',{}).get('selected_engine')
        passed=run.returncode==0 and (selected in expected if isinstance(expected,set) else selected==expected)
        report['cases'].append({'name':name,'passed':passed,'selected_engine':selected,
                                'metadata':metadata,'completed':evidence['completed']})
        print(('PASS ' if passed else 'FAIL ')+name+': '+str(selected),flush=True)
finally:
    server.terminate(); server.wait(timeout=10)
    (root/'docs/research/runtime-optin-http.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf8')
assert len(report['cases'])==len(cases) and all(c['passed'] for c in report['cases']),report
