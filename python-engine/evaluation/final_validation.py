"""Shared immutable inputs and artifact utilities for the quality-gate follow-up."""
from __future__ import annotations
from datetime import datetime, timezone
from hashlib import sha256
import json
from pathlib import Path
import subprocess
import sys

ENGINE=Path(__file__).resolve().parents[1]
REPO=ENGINE.parent
PILOT=REPO/'docs/research/independent-validation'
OUT=REPO/'docs/research/final-validation'
sys.path.insert(0,str(ENGINE))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
SYSTEMS=['legacy','guide_lsa','article21_light','article21_enhanced']
DEPTHS={'very_brief':(3,.10,2,80),'brief':(3,.18,4,150),'short':(5,.26,6,280),
        'balanced':(8,.38,10,500),'detailed':(11,.55,18,850),'comprehensive':(15,.75,32,1600)}


def digest(path):return sha256(path.read_bytes()).hexdigest()


def read(path):return json.loads(path.read_text(encoding='utf8'))


def write(name,data):
    OUT.mkdir(parents=True,exist_ok=True)
    (OUT/name).write_text(json.dumps(data,indent=2,ensure_ascii=False)+'\n',encoding='utf8')


def corpus():return read(PILOT/'corpus.json')


def runs():return {s:read(PILOT/('runs-'+s+'.json'))['rows'] for s in SYSTEMS}


def initialize():
    from evaluation.independent_validation import verify as verify_pilot
    old_check=verify_pilot()
    if (OUT/'freeze.json').exists():raise RuntimeError('Follow-up freeze already exists')
    previous=read(PILOT/'freeze.json')
    protected={name:digest(REPO/name) for name in previous['protected_sha256']}
    protected['python-engine/worker_daemon.py']=digest(ENGINE/'worker_daemon.py')
    artifacts={p.relative_to(REPO).as_posix():digest(p) for p in sorted(PILOT.iterdir()) if p.is_file()}
    tree=sha256(json.dumps(protected,sort_keys=True,separators=(',',':')).encode()).hexdigest()
    commit=subprocess.run(['git','rev-parse','HEAD'],cwd=REPO,capture_output=True,text=True,check=True).stdout.strip()
    data={'client_date':'2026-10-23','client_timezone':'Asia/Manila','recorded_utc':datetime.now(timezone.utc).isoformat(),
          'engine':'recipe-article-2.1','git_head_context_only':commit,'workspace_is_not_pristine_commit':True,
          'code_tree_sha256':tree,'protected_sha256':protected,'pilot_artifact_sha256':artifacts,
          'protocol_sha256':digest(OUT/'protocol.md'),'pilot_freeze_verification':old_check,
          'independent_human_review':'pending; no reviewer submissions supplied',
          'analysis_role':'follow-up on inspected corpus, not untouched holdout'}
    write('freeze.json',data)
    print('Follow-up freeze',tree,';',len(artifacts),'immutable pilot artifacts')


def verify():
    frozen=read(OUT/'freeze.json')
    changes=[n for n,h in frozen['protected_sha256'].items() if not (REPO/n).exists() or digest(REPO/n)!=h]
    unrelated=[n for n in changes if '/assets/' in n or n in {'app/public/analytics.php','app/public/partials/site-nav.php'}]
    relevant=[n for n in changes if n not in unrelated]
    if relevant:raise RuntimeError('Frozen evaluation code changed: '+str(relevant))
    altered=[n for n,h in frozen['pilot_artifact_sha256'].items() if digest(REPO/n)!=h]
    if altered:raise RuntimeError('Pilot artifacts changed: '+str(altered))
    assert digest(OUT/'protocol.md')==frozen['protocol_sha256'],'Follow-up protocol changed'
    return {'evaluation_relevant_changes':relevant,'unrelated_workspace_changes_preserved':unrelated,
            'pilot_artifacts_changed':altered,'protocol_unchanged':True,'code_tree_sha256':frozen['code_tree_sha256']}


if __name__=='__main__':
    if len(sys.argv)>1 and sys.argv[1]=='init':initialize()
    else:print(json.dumps(verify()))
