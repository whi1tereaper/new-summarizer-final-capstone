"""Record executed checks and index this follow-up without touching pilot data."""
import ast
from datetime import datetime,timezone
from pathlib import Path
import re
import subprocess
import sys
from urllib.error import HTTPError,URLError
from urllib.request import urlopen

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
from evaluation.final_validation import OUT,REPO,read,write,digest,verify


def command(args):
    p=subprocess.run(args,cwd=REPO,capture_output=True,text=True,encoding='utf8',errors='replace',timeout=60)
    return {'command':args,'exit_code':p.returncode,'stdout':p.stdout,'stderr':p.stderr}


def finish():
    # Only research harnesses, configuration reads, syntax and integrity checks.
    tests=[command([sys.executable,'-m','unittest','discover','-s','python-engine/tests','-p',pattern,'-v'])
           for pattern in ['test_final_validation.py','test_independent_validation.py']]
    php=Path('C:/xampp/php/php.exe')
    syntax=command([str(php),'-l','scripts/final_resource_endpoint.php'])
    config=command([str(php),'scripts/independent_validation_config.php'])
    from json import loads
    flags=loads(config['stdout']);assert flags['automatic_article_routing'] is False
    assert flags['summary_engine_version']=='legacy'
    docker=command(['docker','ps','-a','--filter','label=light.final-validation=20261023','--format','{{.Names}} {{.Status}}'])
    assert docker['exit_code']==0 and not docker['stdout'].strip(),'Owned benchmark container remains'
    paths=sorted((ROOT/'evaluation').glob('final_*.py'))+[ROOT/'tests/test_final_validation.py',REPO/'scripts/final_resource_endpoint.php',
        REPO/'docs/research/article-engine-final-validation.md']+[p for p in OUT.iterdir() if p.suffix in {'.md','.conf'}]
    parsed=[];whitespace=[]
    for path in paths:
        source=path.read_text(encoding='utf8')
        if path.suffix=='.py':ast.parse(source,filename=str(path));parsed.append(path.relative_to(REPO).as_posix())
        whitespace.extend(path.relative_to(REPO).as_posix()+':'+str(i) for i,line in enumerate(source.splitlines(),1) if line.rstrip()!=line)
    assert not whitespace,whitespace
    gitcheck=command(['git','diff','--check','--']+[p.relative_to(REPO).as_posix() for p in paths])
    endpoint='http://localhost/ai-summarizer-123%20-%20quality%20upg/scripts/final_resource_endpoint.php'
    try:
        with urlopen(endpoint,timeout=5) as response:host={'status':response.status}
    except HTTPError as error:host={'status':error.code}
    except (URLError,TimeoutError) as error:host={'status':None,'unavailable':type(error).__name__}
    if host['status'] is not None:assert host['status']==403,'Endpoint not fail-closed on host'
    assert all(t['exit_code']==0 for t in tests+[syntax,config,gitcheck])
    checks={'recorded_utc':datetime.now(timezone.utc).isoformat(),'client_date':'2026-10-23','client_timezone':'Asia/Manila',
        'tests':tests,'php_syntax':syntax,'final_configuration':flags,'owned_containers':docker,
        'python_files_parsed':parsed,'custom_whitespace_errors':whitespace,'git_diff_check':gitcheck,
        'host_endpoint_without_token':host,'freeze_verification':verify(),
        'limits':'No full application regression, database writes or live deployment certification. Human review pending.'}
    write('verification.json',checks)
    links=[];broken=[]
    for path in [REPO/'docs/research/article-engine-final-validation.md']+sorted(OUT.glob('*.md')):
        for target in re.findall(r'\]\(([^)]+)\)',path.read_text(encoding='utf8')):
            if '://' in target or target.startswith('#'):continue
            location=target.split('#',1)[0].strip('<>')
            if not (path.parent/location).exists():broken.append((path.name,target))
            links.append({'document':path.relative_to(REPO).as_posix(),'target':target})
    assert not broken,broken
    checks['relative_markdown_links_checked']=len(links);checks['broken_relative_links']=broken
    write('verification.json',checks)
    indexed=set(p for p in OUT.iterdir() if p.is_file() and p.name!='artifact-manifest.json')
    indexed.update(paths)
    manifest={'recorded_utc':datetime.now(timezone.utc).isoformat(),'sha256':{
        p.relative_to(REPO).as_posix():digest(p) for p in sorted(indexed)},
        'pilot_evidence':'All 59 original pilot artifacts are separately protected in freeze.json; none changed.',
        'self_hash_omitted':True,'human_review_status':'pending'}
    write('artifact-manifest.json',manifest)
    print('Integrity verified:',len(manifest['sha256']),'indexed artifacts;',len(links),'valid local links; host endpoint',host['status'])


if __name__=='__main__':finish()
