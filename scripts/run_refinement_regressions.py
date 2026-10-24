"""Run full discovery and retain exact counts without flooding interactive output."""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
from time import perf_counter

root=Path(__file__).resolve().parents[1]
began=perf_counter()
command=[sys.executable,'-m','unittest','discover','-s','python-engine/tests','-p','test_*.py']
result=subprocess.run(command,cwd=root,env=dict(os.environ,PYTHONPATH=str(root/'python-engine')),
                      capture_output=True,text=True,encoding='utf8',errors='replace')
log=root/'artifacts/runtime-refinement/python-tests.log'
log.parent.mkdir(parents=True,exist_ok=True)
log.write_text(result.stdout+'\n'+result.stderr,encoding='utf8')
count=re.search(r'Ran (\d+) tests in ([\d.]+)s',result.stderr)
skips=re.search(r'skipped=(\d+)',result.stderr)
data={'command':command,'exit_code':result.returncode,'tests_run':int(count[1]) if count else None,
      'skipped':int(skips[1]) if skips else 0,'seconds':perf_counter()-began,
      'summary_tail':result.stderr.splitlines()[-7:],
      'full_log':'artifacts/runtime-refinement/python-tests.log'}
(root/'docs/research/refinement-regression-results.json').write_text(json.dumps(data,indent=2)+'\n',encoding='utf8')
print(json.dumps(data,indent=2),flush=True)
sys.exit(result.returncode)
