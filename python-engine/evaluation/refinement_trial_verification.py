"""Recheck failed search budgets without changing any selected scores/parameters."""
from importlib.metadata import version, PackageNotFoundError
import json
import os
from pathlib import Path
import platform
import sys

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
os.environ.update(OPENBLAS_NUM_THREADS='1',OMP_NUM_THREADS='1',MKL_NUM_THREADS='1',HF_HUB_OFFLINE='1',TRANSFORMERS_OFFLINE='1')
from evaluation import refinement_experiment as experiment

data=json.loads((ROOT.parent/'docs/research/refinement-results.json').read_text(encoding='utf8'))
jobs={d['id']+':'+depth+':'+kind:(d,depth,focus,kind) for d,depth,focus,kind in experiment.jobs()}
original=experiment.jobs
checks=[]
try:
    for row in data['rows']:
        failed=[g for g in row['valid_generation_trials'] if g['error']]
        if not failed: continue
        experiment.jobs=lambda: iter([jobs[row['case_id']]])
        rerun=experiment.worker(row['system'],{row['case_id']:row['target_words']})[0]
        by_budget={g['budget']:g for g in rerun['valid_generation_trials']}
        for trial in failed:
            actual=by_budget[trial['budget']]
            checks.append({'system':row['system'],'case_id':row['case_id'],'budget':trial['budget'],
                           'reproduced':actual['error']==trial['error'],'failure_code':actual['failure_code']})
finally:
    experiment.jobs=original
versions={}
for package in ['numpy','scipy','scikit-learn','nltk','sumy','spacy','en_core_web_sm','psutil','sentence-transformers']:
    try: versions[package]=version(package)
    except PackageNotFoundError: versions[package]='unavailable'
report={'purpose':'Independent replay of failed tight-budget trials only; original successful outputs/scores unchanged.',
        'python':platform.python_version(),'platform':platform.platform(),'versions':versions,
        'checks':checks,'all_reproduced':all(c['reproduced'] for c in checks)}
(ROOT.parent/'docs/research/refinement-trial-verification.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf8')
print(json.dumps({'checks':len(checks),'all_reproduced':report['all_reproduced'],
                  'failure_codes':sorted({c['failure_code'] for c in checks})}),flush=True)
