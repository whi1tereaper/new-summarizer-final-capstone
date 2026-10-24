"""Enforced aggregate Docker benchmarks; never uses host services or databases."""
from concurrent.futures import ThreadPoolExecutor
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import uuid
from hashlib import sha256

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
from evaluation.independent_validation import OUT, REPO, verify, write


def docker(*args,timeout=45,check=True):
    result=subprocess.run(['docker',*args],capture_output=True,text=True,encoding='utf8',timeout=timeout)
    if check and result.returncode: raise RuntimeError(result.stderr[-600:])
    return result.stdout.strip() or (result.stderr.strip() if not check else '')


def make_pdf():
    # PDF is a generated transport wrapper around a real, locked article, not a
    # new quality source. Each line stays within the page and extraction is checked.
    import textwrap
    from pypdf import PdfWriter, PdfReader
    from pypdf.generic import DecodedStreamObject, DictionaryObject, NameObject
    corpus=json.loads((OUT/'corpus.json').read_text(encoding='utf8'))
    text=next(d['source_text'] for d in corpus if d['id']=='IV-RESEARCH')
    lines=[]
    for para in text.split('\n\n'):
        lines.extend(textwrap.wrap(para,width=88)); lines.append('')
    writer=PdfWriter()
    font=DictionaryObject({NameObject('/Type'):NameObject('/Font'),NameObject('/Subtype'):NameObject('/Type1'),NameObject('/BaseFont'):NameObject('/Helvetica')})
    for offset in range(0,len(lines),57):
        page=writer.add_blank_page(width=612,height=792)
        page[NameObject('/Resources')]=DictionaryObject({NameObject('/Font'):DictionaryObject({NameObject('/F1'):writer._add_object(font)})})
        content='BT /F1 10 Tf 40 752 Td 12 TL\n'
        for line in lines[offset:offset+57]:
            value=line.encode('cp1252',errors='replace').decode('cp1252').replace('\\','\\\\').replace('(','\\(').replace(')','\\)')
            content+='('+value+') Tj T*\n'
        stream=DecodedStreamObject(); stream.set_data((content+'ET').encode('cp1252'))
        page[NameObject('/Contents')]=writer._add_object(stream)
    path=OUT/'resource-large.pdf'
    writer.write(path)
    extracted='\n'.join(p.extract_text() or '' for p in PdfReader(path).pages)
    assert 'While sensitivity analyses' in extracted and len(extracted)>30000
    return {'pages':len(writer.pages),'bytes':path.stat().st_size,'source':'IV-RESEARCH',
            'encoding_note':'Helvetica WinAnsi fixture: unsupported glyphs replaced only in this transport test, never in quality inputs.'}


def sample(name):
    raw=docker('exec',name,'sh','-c',
        'for f in memory.current memory.peak memory.max memory.swap.max memory.events cpu.max; do echo "$f"; cat "/sys/fs/cgroup/$f"; done',check=False)
    state=json.loads(docker('inspect',name))[0]['State']
    return {'cgroup_v2':raw,'container_running':state['Running'],'container_exit_code':state['ExitCode'],
            'container_oom_killed':state['OOMKilled']}


def benchmark(mode,memory,warm=False,guide=None):
    import requests
    name='light-iv21-'+uuid.uuid4().hex[:10]
    token=uuid.uuid4().hex
    image='light-iv21-enhanced:eval' if mode=='enhanced' else 'ai-summarizer-render:latest'
    image_id=docker('image','inspect',image,'--format','{{.Id}}')
    cmd=['run','-d','--name',name,'--label','light.iv21=independent-validation-20261022','--memory',str(memory)+'m','--memory-swap',str(memory)+'m','--cpus','1',
         '--tmpfs','/var/www/html/python-engine/output:rw,size=16m,mode=1777',
         '--tmpfs','/var/www/html/python-engine/.tmp:rw,size=16m,mode=1777',
         '-p','127.0.0.1::10000','--mount',f'type=bind,source={REPO / "app"},target=/var/www/html/app,readonly',
         '--mount',f'type=bind,source={ROOT},target=/var/www/html/python-engine,readonly',
         '--mount',f'type=bind,source={OUT},target=/ivdata,readonly',
         '--mount',f'type=bind,source={REPO / "scripts/independent_resource_endpoint.php"},target=/ivbench/independent_resource_endpoint.php,readonly',
         '--mount',f'type=bind,source={OUT / "apache-benchmark.conf"},target=/etc/apache2/conf-enabled/iv-benchmark.conf,readonly']
    env={'IV_BENCH_TOKEN':token,'IV_RESOURCE_SANDBOX':'true','PORT':'10000','LOCAL_PYTHON_BIN':'/opt/venv/bin/python','LOCAL_PYTHON_TIMEOUT':'180',
         'PYTHON_WORKER_PORT':'5005' if warm else '1','PYTHON_WORKER_SECRET':token,
         'SUMMARIZER_AUTO_ARTICLE_ROUTING':'true','SUMMARIZER_ENGINE_VERSION':'legacy',
         'LIGHT_ARTICLE_NLP_ENHANCED':str(mode=='enhanced').lower(),'HF_HUB_OFFLINE':'1','TRANSFORMERS_OFFLINE':'1',
         'OPENBLAS_NUM_THREADS':'1','OMP_NUM_THREADS':'1','MKL_NUM_THREADS':'1','TOKENIZERS_PARALLELISM':'false'}
    for key,value in env.items(): cmd.extend(['-e',key+'='+value])
    if guide:cmd.extend(['--mount',f'type=bind,source={guide},target=/ivbook/guide.pdf,readonly'])
    cmd.append(image)
    if warm:
        cmd.extend(['sh','-c','python /var/www/html/python-engine/worker_daemon.py --port 5005 --deadline 180 > /var/www/html/storage/logs/iv-supervisor.log 2>&1 & exec apache2-foreground'])
    began=time.perf_counter(); container=None
    artifact=('resource-warm-' if warm else 'resource-')+mode+'-'+str(memory)+'.json'
    if guide:artifact='resource-large-guide-'+mode+'-'+str(memory)+'.json'
    data={'mode':mode,'persistent_supervisor':warm,'memory_limit_mib':memory,'memory_swap_limit_mib':memory,'cpu_limit':1,
          'configured_python_timeout_seconds':180,'outer_http_read_deadline_seconds':185,'image_id':image_id,
          'scope':'isolated actual Apache + PHP ArticleService + bridge + Python extraction/routing/models; excludes database, auth/controller/job persistence and host Docker daemon',
          'warm_definition':'one authenticated reusable NLP child + supervisor inside same cgroup' if warm else 'repeat HTTP requests; CLI Python is cold per call, no persistent supervisor',
          'runs':[]}
    if guide:data['external_resource_fixture']={'bytes':guide.stat().st_size,'sha256':sha256(guide.read_bytes()).hexdigest(),
        'description':'Previously inspected 162-page academic guide, resource-only, excluded from new quality corpus; original file mounted read-only, not redistributed.'}
    try:
        container=docker(*cmd)
        port=docker('port',name,'10000/tcp').rsplit(':',1)[1]
        url='http://127.0.0.1:'+port+'/iv_benchmark.php'
        # Wait for Apache using a forbidden GET: does not import PHP application/NLP.
        while time.perf_counter()-began<45:
            try:
                ready=requests.get(url,timeout=2)
                if ready.status_code==403: break
            except requests.RequestException: pass
            time.sleep(.2)
        else: raise RuntimeError('Apache startup deadline exceeded')
        data['startup_seconds']=time.perf_counter()-began
        if warm:
            health_code="$c=stream_context_create(['http'=>['header'=>'X-Worker-Token: '.getenv('PYTHON_WORKER_SECRET')]]); echo file_get_contents('http://127.0.0.1:5005/health',false,$c);"
            for _ in range(30):
                health=docker('exec',name,'php','-r',health_code,check=False)
                try:
                    data['supervisor_health']=json.loads(health)
                    if data['supervisor_health'].get('status')=='ok': break
                except ValueError:pass
                time.sleep(.2)
            else:raise RuntimeError('Supervisor failed startup health')
        data['installed_packages']=docker('exec',name,'python','-m','pip','freeze',check=False).splitlines()
        data['initial']=sample(name)
        def request(label,payload):
            start=time.perf_counter()
            try:
                result=requests.post(url,json=payload,headers={'X-IV-Token':token},timeout=(5,185))
                try: body=result.json()
                except ValueError: body={'non_json_response_bytes':len(result.content)}
                return {'label':label,'payload':payload,'http_status':result.status_code,'response':body,
                        'wall_seconds':time.perf_counter()-start,'transport_error':None}
            except requests.RequestException as error:
                return {'label':label,'payload':payload,'transport_error':type(error).__name__,
                        'wall_seconds':time.perf_counter()-start,'outer_timeout':isinstance(error,requests.Timeout)}
        requests_to_run=[
            ('cold_text',{'id':'IV-BUSINESS'}),('repeat_text',{'id':'IV-BUSINESS'}),
            ('first_focus',{'id':'IV-BUSINESS','focus':'energy exports'}),
            ('large_text',{'id':'IV-RESEARCH'}),('large_pdf',{'id':'IV-RESEARCH','pdf':True})]
        if warm:
            requests_to_run=[('cold_article',{'id':'IV-RESEARCH'}),('warm_article',{'id':'IV-RESEARCH'}),
                ('first_article_focus',{'id':'IV-RESEARCH','focus':'walking intensity and frailty'}),
                ('warm_article_focus',{'id':'IV-RESEARCH','focus':'walking intensity and frailty'}),
                ('large_pdf',{'id':'IV-RESEARCH','pdf':True})]
        if guide:requests_to_run=[('large_academic_pdf',{'id':'IV-RESEARCH','large_guide':True})]
        for label,payload in requests_to_run:
            row=request(label,payload); row['after']=sample(name); data['runs'].append(row)
            write(artifact,data)
            print(mode,memory,label,row.get('http_status'),row.get('response',{}).get('engine_version'),round(row['wall_seconds'],3),flush=True)
            if row.get('outer_timeout') or not row['after']['container_running']: break
        if not guide and data['runs'][-1]['after']['container_running']:
            with ThreadPoolExecutor(max_workers=2) as pool:
                pair=list(pool.map(lambda i:request('concurrent_'+str(i),{'id':'IV-RESEARCH'}),[1,2]))
            data['runs'].extend(pair)
            data['after_concurrency']=sample(name)
        data['final']=sample(name)
        # Only safe status/code metadata; do not collect environment or tokens.
        data['completed']=True
    except Exception as error:
        data['harness_error']=type(error).__name__+': '+str(error)[:500]
        # Startup errors contain static file paths/Apache errors, not secrets.
        try:data['startup_log_tail']=docker('logs','--tail','12',name,check=False)
        except OSError:data['startup_log_tail']='unavailable_due_to_host_process_launch_failure'
        data['completed']=False
    finally:
        owned=docker('inspect',name,'--format','{{index .Config.Labels "light.iv21"}}',check=False)
        if container or owned=='independent-validation-20261022':
            # Exact, newly owned disposable container only; no host or existing service is stopped.
            docker('stop','--time','2',name,check=False)
            docker('rm',name,check=False)
        data['whole_container_wall_seconds']=time.perf_counter()-began
        data['freeze_verification']=verify()
        write(artifact,data)
    return data


if __name__=='__main__':
    sys.stdout.reconfigure(encoding='utf8'); verify()
    parser=argparse.ArgumentParser();parser.add_argument('--warm',action='store_true')
    parser.add_argument('--memory',type=int,choices=[512,1024]);parser.add_argument('--mode',choices=['light','enhanced'])
    parser.add_argument('--large-guide',type=Path)
    args=parser.parse_args()
    if (OUT/'resource-results.json').exists():
        write('resource-harness-attempt-'+str(int(time.time()))+'.json',json.loads((OUT/'resource-results.json').read_text(encoding='utf8')))
    pdf=make_pdf(); runs=[]
    for memory in ([args.memory] if args.memory else [512,1024]):
        for mode in ([args.mode] if args.mode else ['light','enhanced']): runs.append(benchmark(mode,memory,args.warm,args.large_guide))
    write('resource-large-guide-results.json' if args.large_guide else 'resource-warm-results.json' if args.warm else 'resource-results.json',{'pdf_fixture':pdf,'benchmarks':runs,
          'production_compatibility':'not certified; test envelope is declared, actual Render plan/CPU/memory is not configured in render.yaml',
          'enhanced_image':'evaluation-only added spaCy 3.8.11/en_core_web_sm 3.8.0; production image lacks these resources',
          'swap':'memory-swap equals memory, so cgroup swap allowance is zero',
          'global_host_routing_changed':False})
