"""Actual HTTP cases, separate from unit/bridge and authenticated-browser tests."""
import json
from pathlib import Path
import subprocess
import sys
from time import perf_counter

root=Path(__file__).resolve().parents[1]
cases=[(depth,['--depth',depth]) for depth in ['very_brief','brief','short','balanced','detailed','comprehensive']]
cases += [('DOCX upload',['--fixture','docx']),('PDF upload',['--fixture','pdf']),
          ('supported Focus',['--focus','staffing costs']),('absent Focus',['--focus','volcanic seismology']),
          ('live NASA article URL',['--url','https://www.nasa.gov/news-release/nasa-isro-satellite-lifts-off-to-track-earths-changing-surfaces/'])]
report={'kind':'actual_local_apache_http_guest_matrix','cases':[]}
for name,args in cases:
    began=perf_counter()
    run=subprocess.run([sys.executable,'-X','utf8','scripts/test_runtime_repair.py',*args],cwd=root,
                       capture_output=True,text=True,encoding='utf8',errors='replace',timeout=360)
    report['cases'].append({'name':name,'passed':run.returncode==0,'seconds':perf_counter()-began,
                            'output_tail':run.stdout[-1800:],'error_tail':run.stderr[-800:]})
    (root/'docs/research/runtime-http-matrix.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf8')
    print(('PASS ' if run.returncode==0 else 'FAIL ')+name,flush=True)
sys.exit(int(any(not c['passed'] for c in report['cases'])))
