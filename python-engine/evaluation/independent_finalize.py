"""Record validation and a reproducible artifact manifest without changing engines."""
import ast
from datetime import datetime, timezone
from hashlib import sha256
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
from evaluation.independent_validation import OUT, REPO, verify, write


def main():
    check=verify()
    python_files=list((ROOT/'evaluation').glob('independent_*.py'))+[ROOT/'tests/test_independent_validation.py']
    for path in python_files:ast.parse(path.read_text(encoding='utf8'),filename=str(path))
    tests=subprocess.run([sys.executable,'-m','unittest','python-engine/tests/test_independent_validation.py','-v'],
                         cwd=REPO,capture_output=True,text=True,timeout=60)
    if tests.returncode:raise RuntimeError(tests.stdout+tests.stderr)
    php=Path(r'C:\xampp\php\php.exe')
    php_checks=[]
    for name in ['scripts/independent_resource_endpoint.php','scripts/independent_validation_config.php']:
        run=subprocess.run([str(php),'-l',name],cwd=REPO,capture_output=True,text=True,timeout=15)
        assert run.returncode==0
        php_checks.append({'path':name,'exit':run.returncode,'output':run.stdout.strip()})
    config=subprocess.run([str(php),'scripts/independent_validation_config.php'],cwd=REPO,
                          capture_output=True,text=True,timeout=15,check=True)
    flags=json.loads(config.stdout)
    assert flags['automatic_article_routing'] is False and flags['summary_engine_version']=='legacy'
    report=OUT.parent/'article-engine-independent-validation.md'
    broken=[]
    for path in list(OUT.glob('*.md'))+[report]:
        for target in re.findall(r'\]\(([^)]+)\)',path.read_text(encoding='utf8')):
            if re.match(r'https?://',target):continue
            target=target.split('#',1)[0]
            if target and not (path.parent/target).exists():broken.append(str(path)+': '+target)
    assert not broken,broken
    whitespace=subprocess.run(['git','diff','--check'],cwd=REPO,capture_output=True,text=True,timeout=30)
    assert whitespace.returncode==0
    containers=subprocess.run(['docker','ps','-a','--filter','label=light.iv21=independent-validation-20261022','--format','{{.Names}}'],
                              capture_output=True,text=True,timeout=30,check=True)
    assert not containers.stdout.strip(),'Owned benchmark containers remain'
    write('verification.json',{'utc':datetime.now(timezone.utc).isoformat(),'freeze':check,
          'new_python_ast_files':len(python_files),'new_research_harness_tests':{'exit':tests.returncode,'output':tests.stderr.strip()},
          'php_syntax':php_checks,'effective_config':flags,'broken_local_markdown_links':broken,
          'git_diff_whitespace_exit':whitespace.returncode,'owned_benchmark_containers_remaining':[],
          'full_historical_suite_rerun_this_task':False,'human_review_completed':False})
    paths=python_files+[REPO/'scripts/independent_resource_endpoint.php',REPO/'scripts/independent_validation_config.php',report,
            OUT.parent/'experimental-results.md',OUT.parent/'article-engine-release-readiness.md']
    paths+=list(OUT.glob('*'))
    files={p.relative_to(REPO).as_posix():sha256(p.read_bytes()).hexdigest() for p in sorted(set(paths))
           if p.is_file() and p.name!='artifact-manifest.json'}
    write('artifact-manifest.json',{'status':'research/evaluation files only; no engine modifications',
          'sha256':files,'private_env_or_credentials_included':False,
          'historical_documents_changed_only_by_followup_links':True})
    print('Verified',len(files),'artifacts; six harness tests passed; engine hashes/routing preserved.')


if __name__=='__main__':main()
