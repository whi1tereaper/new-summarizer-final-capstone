"""Fail-closed HTTP contract when database initialization cannot complete."""
import json, os, socket, subprocess, sys, time
from pathlib import Path
import requests

ROOT = Path(__file__).resolve().parents[2]
folder = Path(sys.argv[1]).resolve()
report = Path(sys.argv[2]).resolve()
config = json.loads((folder / 'config.json').read_text())
assert config['env']['DB_NAME'].startswith('qa_light_')
env = os.environ.copy()
env.update(config['env'])
env.update(DB_PORT='1', DB_HOST='127.0.0.1')
checks = []
with (folder / 'offline-php.log').open('wb') as output:
    process = subprocess.Popen(['C:/php83/php.exe', '-d', 'session.save_path=' + str(folder), '-S', '127.0.0.1:58140', '-t', 'app/public'], cwd=ROOT, env=env, stdout=output, stderr=output)
    try:
        for _ in range(40):
            try:
                with socket.create_connection(('127.0.0.1', 58140), timeout=.2):
                    break
            except OSError:
                time.sleep(.1)
        session = requests.Session();session.trust_env = False
        response = session.post('http://127.0.0.1:58140/nutshell_generate.php', json={'request_key': 'a' * 32}, timeout=25)
        body = response.json()
        checks.append({'name': 'database initialization failure returns safe JSON with HTTP 503', 'passed': response.status_code == 503 and body.get('success') is False and body.get('failure', {}).get('code') == 'WORKER_UNAVAILABLE' and 'application/json' in response.headers.get('Content-Type', '')})
        checks.append({'name': 'database initialization failure does not disclose SQL credentials or file paths', 'passed': not any(value in response.text for value in [config['env']['DB_NAME'], config['env']['DB_PASSWORD'], str(ROOT), 'SQLSTATE', 'Stack trace'])})
    finally:
        process.terminate();process.wait(timeout=15)
(report / 'initialization.json').write_text(json.dumps(checks, indent=2), encoding='utf8')
for check in checks:
    print(('PASS ' if check['passed'] else 'FAIL ') + check['name'])
raise SystemExit(int(any(not check['passed'] for check in checks)))
