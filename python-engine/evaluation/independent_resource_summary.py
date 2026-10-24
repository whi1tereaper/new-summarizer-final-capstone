"""Summarize cgroup evidence without confusing host failures with engine OOMs."""
import json
from pathlib import Path
import re
import sys

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
from evaluation.independent_validation import OUT, write


def summarize():
    rows=[]
    for path in sorted(OUT.glob('resource-*.json')):
        data=json.loads(path.read_text(encoding='utf8'))
        if 'mode' not in data:continue
        samples=[data.get('initial',{}),data.get('final',{}),data.get('after_concurrency',{})]
        samples.extend(row.get('after',{}) for row in data.get('runs',[]))
        def values(pattern):
            return [int(match.group(1)) for sample in samples
                    if (match:=re.search(pattern,sample.get('cgroup_v2','')))]
        peak=max(values(r'memory\.peak\n(\d+)'),default=0)
        limit=data['memory_limit_mib']*1048576
        runs=data.get('runs',[])
        rows.append({'artifact':path.name,'mode':data['mode'],'persistent_supervisor':data.get('persistent_supervisor',False),
            'limit_mib':data['memory_limit_mib'],'completed':data.get('completed',False),
            'harness_error':data.get('harness_error'),
            'cgroup_peak_mib':peak/1048576 if peak else None,
            'cgroup_peak_headroom_mib':(limit-peak)/1048576 if peak else None,
            'oom_events':max(values(r'(?m)^oom (\d+)'),default=0),
            'oom_kills':max(values(r'(?m)^oom_kill (\d+)'),default=0),
            'startup_seconds':data.get('startup_seconds'),
            'request_count':len(runs),'http_successes':sum(r.get('http_status')==200 and r.get('response',{}).get('ok') for r in runs),
            'article21_successes':sum(r.get('response',{}).get('engine_version')=='recipe-article-2.1' for r in runs),
            'legacy_fallback_successes':sum(r.get('response',{}).get('routing',{}).get('selected_engine')=='legacy' for r in runs),
            'runs':[{'label':r['label'],'http':r.get('http_status'),'failure_code':r.get('response',{}).get('failure_code'),
                     'seconds':r['wall_seconds'],'engine_version':r.get('response',{}).get('engine_version'),
                     'daemon_used':r.get('response',{}).get('daemon_elapsed_seconds') is not None,
                     'outer_timeout':r.get('outer_timeout',False)} for r in runs]})
    write('resource-summary.json',{'benchmarks':rows,'actual_deployment_certified':False,
          'timing_caution':'Initial CLI measurements overlapped a host model comparison; host paging disrupted the 1 GiB matrix. Later warm measurements run serially.',
          'plan_caution':'512 MiB/1 CPU/180 s is an enforced declared envelope, not a verified live Render plan.',
          'scope_caution':'Apache/PHP/service/bridge/extraction/NLP plus supervisor when declared. DB/controller/job persistence not exercised.',
          'observer_caution':'cgroup peak includes server, child processes, mapped/file-cache pages and small in-cgroup measurement commands; not just Python RSS.'})
    print(json.dumps(rows,indent=2))


if __name__=='__main__':summarize()
