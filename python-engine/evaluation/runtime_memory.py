"""Sample only the correlated real test worker and shared Apache RSS."""
import argparse
import json
from pathlib import Path
import re
from time import monotonic, sleep
import psutil

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser()
parser.add_argument('job_id')
args = parser.parse_args()
assert re.fullmatch('[a-f0-9]{32}', args.job_id)
path = ROOT / 'storage/logs' / ('job-' + args.job_id + '.jsonl')
report = {'job_id': args.job_id, 'sampled_python_rss_mib': 0, 'python_process_high_water_mib': 0,
          'sampled_parent_php_rss_mib': 0, 'sampled_shared_apache_rss_mib': 0,
          'limits': ['Started after request admission; Python OS high-water mark supplements RSS samples.',
                     'Apache processes are shared; their RSS is not attributable solely to this request.',
                     'No container memory cap was enforced.']}
began = monotonic()
pid = None
while monotonic()-began < 340:
    if path.exists():
        try:
            pid = json.loads(path.read_text(encoding='utf8').splitlines()[-1])['pid']
        except (ValueError, KeyError, IndexError):
            pass
    if pid:
        try:
            process = psutil.Process(pid)
            memory = process.memory_info()
            report['sampled_python_rss_mib'] = max(report['sampled_python_rss_mib'], memory.rss/1048576)
            report['python_process_high_water_mib'] = max(report['python_process_high_water_mib'], getattr(memory,'peak_wset',memory.rss)/1048576)
            for parent in process.parents():
                if parent.name().lower() == 'php.exe':
                    report['sampled_parent_php_rss_mib'] = max(report['sampled_parent_php_rss_mib'],parent.memory_info().rss/1048576)
                    break
        except psutil.NoSuchProcess:
            break
        except psutil.AccessDenied:
            report['parent_lookup_access_denied'] = True
    apache = 0
    for process in psutil.process_iter(['name','memory_info'],ad_value=None):
        if process.info['name'] == 'httpd.exe' and process.info['memory_info']:
            apache += process.info['memory_info'].rss/1048576
    report['sampled_shared_apache_rss_mib'] = max(report['sampled_shared_apache_rss_mib'],apache)
    sleep(.1)
report['sample_seconds'] = monotonic()-began
out = ROOT / 'docs/research' / ('runtime-memory-' + args.job_id + '.json')
out.write_text(json.dumps(report,indent=2)+'\n',encoding='utf8')
print(json.dumps(report,indent=2),flush=True)
