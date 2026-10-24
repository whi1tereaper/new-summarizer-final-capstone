"""New process-contribution observations; old resource artifacts remain immutable."""
import json
from pathlib import Path
import subprocess
import sys
import time
import uuid

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
from evaluation.final_validation import OUT,PILOT,REPO,verify,write,read
from evaluation.independent_resources import docker


def profile(mode,readable_maps=False):
    import requests
    artifact='process-profile-'+('readable-' if readable_maps else '')+mode+'.json'
    if (OUT/artifact).exists():raise RuntimeError('Preserve completed observation '+artifact+'; use a versioned study for replay')
    name='light-final-'+uuid.uuid4().hex[:10];token=uuid.uuid4().hex
    image='ai-summarizer-render:latest' if mode=='light' else 'light-iv21-enhanced:eval'
    image_id=docker('image','inspect',image,'--format','{{.Id}}')
    old=read(PILOT/('resource-warm-'+mode+'-512.json'))
    assert image_id==old['image_id'],'Benchmark image tag no longer identifies frozen image'
    command=['run','-d','--name',name,'--label','light.final-validation=20261023',
        '--memory','512m','--memory-swap','512m','--cpus','1','-p','127.0.0.1::10000',
        '--tmpfs','/var/www/html/python-engine/output:rw,size=16m,mode=1777',
        '--tmpfs','/var/www/html/python-engine/.tmp:rw,size=16m,mode=1777']
    if readable_maps:command+=['--cap-add','SYS_PTRACE']
    for source,target in [(REPO/'app','/var/www/html/app'),(ROOT,'/var/www/html/python-engine'),
        (PILOT,'/ivdata'),(REPO/'scripts/final_resource_endpoint.php','/ivbench/final_resource_endpoint.php'),
        (ROOT/'evaluation/final_process_sampler.py','/ivbench/final_process_sampler.py'),
        (OUT/'apache-resource.conf','/etc/apache2/conf-enabled/final-resource.conf')]:
        command+=['--mount',f'type=bind,source={source},target={target},readonly']
    env={'IV_RESOURCE_SANDBOX':'true','IV_BENCH_TOKEN':token,'PYTHON_WORKER_SECRET':token,
        'PORT':'10000','LOCAL_PYTHON_BIN':'/opt/venv/bin/python','LOCAL_PYTHON_TIMEOUT':'180',
        'PYTHON_WORKER_PORT':'5005','SUMMARIZER_AUTO_ARTICLE_ROUTING':'true',
        'SUMMARIZER_ENGINE_VERSION':'legacy','LIGHT_ARTICLE_NLP_ENHANCED':str(mode=='enhanced').lower(),
        'HF_HUB_OFFLINE':'1','TRANSFORMERS_OFFLINE':'1','OPENBLAS_NUM_THREADS':'1','OMP_NUM_THREADS':'1',
        'MKL_NUM_THREADS':'1','TOKENIZERS_PARALLELISM':'false'}
    for k,v in env.items():command+=['-e',k+'='+v]
    command+=[image,'sh','-c','python /var/www/html/python-engine/worker_daemon.py --port 5005 --deadline 180 > /var/www/html/storage/logs/final-supervisor.log 2>&1 & exec apache2-foreground']
    data={'mode':mode,'image_id':image_id,'scope':'local isolated Apache/mod_php + bridge + supervisor/NLP/extraction; no DB/controller/persistence',
          'limit_mib':512,'cpu_limit':1,'python_deadline_seconds':180,'outer_read_deadline_seconds':185,'runs':[],
          'process_map_instrumentation':'SYS_PTRACE capability added to isolated evaluation container' if readable_maps else 'default container permissions',
          'instrumentation_note':'Permission-only follow-up; original default-permission results preserved. Not production security equivalence.'}
    observer=None;started=False;began=time.perf_counter()
    try:
        docker(*command);started=True
        port=docker('port',name,'10000/tcp').rsplit(':',1)[1];url='http://127.0.0.1:'+port+'/iv_benchmark.php'
        for _ in range(100):
            try:
                if requests.get(url,timeout=1).status_code==403:break
            except requests.RequestException:pass
            time.sleep(.1)
        else:raise RuntimeError('Apache did not become ready')
        health="$c=stream_context_create(['http'=>['header'=>'X-Worker-Token: '.getenv('PYTHON_WORKER_SECRET')]]); echo file_get_contents('http://127.0.0.1:5005/health',false,$c);"
        for _ in range(30):
            try:
                if json.loads(docker('exec',name,'php','-r',health,check=False)).get('status')=='ok':break
            except ValueError:pass
            time.sleep(.1)
        else:raise RuntimeError('Supervisor did not become ready')
        data['startup_seconds']=time.perf_counter()-began
        observer=subprocess.Popen(['docker','exec',name,'python','/ivbench/final_process_sampler.py','--seconds','30'],
                                  stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,encoding='utf8')
        requests_began=time.perf_counter()
        for label,payload in [('cold',{}),('warm',{}),('first_focus',{'focus':'walking intensity and frailty'}),
                              ('warm_focus',{'focus':'walking intensity and frailty'}),('pdf',{'pdf':True})]:
            t=time.perf_counter();response=requests.post(url,json=payload,headers={'X-IV-Token':token},timeout=(5,185))
            data['runs'].append({'label':label,'http_status':response.status_code,'wall_seconds':time.perf_counter()-t,'response':response.json()})
        data['request_sequence_seconds']=time.perf_counter()-requests_began
        raw,err=observer.communicate(timeout=40)
        assert observer.returncode==0,err[-400:]
        data['observer']=json.loads(raw)
        samples=data['observer']['samples']
        peak_sample=max(samples,key=lambda s:s['memory_current_bytes'] or 0)
        data['contributions_at_largest_sampled_cgroup_current']=peak_sample
        data['complete']=True
        print(mode,'peak MiB',round(data['observer']['memory_peak_bytes']/1048576,2),'requests',len(data['runs']),flush=True)
    except Exception as error:
        data['complete']=False;data['error']=type(error).__name__+': '+str(error)[:400]
    finally:
        if started:
            docker('stop','--timeout','2',name,check=False);docker('rm',name,check=False)
        if observer and observer.poll() is None:
            try:observer.communicate(timeout=5)
            except subprocess.TimeoutExpired:observer.terminate()
        data['freeze_verification']=verify();write(artifact,data)
    return data


if __name__=='__main__':
    verify()
    # Must run only after model-comparison workers have exited; never overlap the
    # host's heavy quality run and Docker measurement on this 8 GiB workstation.
    assert all(read(OUT/('new-runs-'+s+'.json'))['complete'] for s in ['legacy','guide_lsa','article21_light','article21_enhanced'])
    for mode in ['light','enhanced']:profile(mode,'--readable-maps' in sys.argv)
