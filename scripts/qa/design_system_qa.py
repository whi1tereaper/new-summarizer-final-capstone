"""Isolated browser validation of LIGHT's shared design system; no production writes."""
import argparse
import getpass
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import requests

ROOT = Path(__file__).resolve().parents[2]
REPORT = ROOT / 'docs/qa/design-system'
PHP = 'C:/php83/php.exe'
BASE = 'http://127.0.0.1:58146/'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['all', 'setup', 'browser', 'stop'])
    args = parser.parse_args()
    REPORT.mkdir(parents=True, exist_ok=True)
    if args.action == 'all':
        try:
            subprocess.run([sys.executable, str(Path(__file__).resolve()), 'setup'], cwd=ROOT, check=True)
            subprocess.run([sys.executable, str(Path(__file__).resolve()), 'browser'], cwd=ROOT, check=True)
        finally:
            if (REPORT/'last-run.txt').exists():
                latest=Path((REPORT/'last-run.txt').read_text(encoding='utf8').strip())
                if (latest/'config.json').exists():
                    subprocess.run([sys.executable, str(Path(__file__).resolve()), 'stop'], cwd=ROOT, check=True)
        return
    if args.action == 'setup':
        folder = Path(tempfile.mkdtemp(prefix='light-design-system-', dir=os.environ['LOCALAPPDATA'])).resolve()
        subprocess.run(['icacls', str(folder), '/inheritance:r', '/grant:r', getpass.getuser()+':(OI)(CI)F'], check=True, capture_output=True)
        (REPORT / 'last-run.txt').write_text(str(folder), encoding='utf8')
        subprocess.run([PHP, 'scripts/qa/control.php', 'setup-benchmark', str(folder)], cwd=ROOT, check=True)
    else:
        folder = Path((REPORT / 'last-run.txt').read_text(encoding='utf8').strip())
    cfg = json.loads((folder / 'config.json').read_text())
    env = os.environ.copy()
    env.update(cfg['env'])
    env.update(APP_URL=BASE.rstrip('/'), QA_DESIGN_BASE=BASE, QA_DESIGN_REPORT=str(REPORT), QA_DESIGN_PRIVATE=str(folder))
    if args.action == 'setup':
        subprocess.run([PHP, 'scripts/qa/isolation.php'], cwd=ROOT, env=env, check=True)
        subprocess.run([PHP, 'scripts/qa/design_system_fixture.php', str(folder)], cwd=ROOT, env=env, check=True)
        public = (ROOT / 'app/public').as_posix()
        private = folder.as_posix()
        conf = f'''ServerRoot "C:/xampp/apache"
Listen 127.0.0.1:58146
ServerName 127.0.0.1:58146
PidFile "{private}/httpd.pid"
ErrorLog "{private}/apache-error.log"
LoadModule alias_module modules/mod_alias.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule env_module modules/mod_env.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadFile "C:/php83/php8ts.dll"
LoadFile "C:/php83/libcrypto-3-x64.dll"
LoadFile "C:/php83/libssl-3-x64.dll"
LoadModule php_module "C:/php83/php8apache2_4.dll"
PHPINIDir "C:/php83"
DocumentRoot "{public}"
DirectoryIndex index.php
TypesConfig "C:/xampp/apache/conf/mime.types"
<Directory "C:/xampp/htdocs">
 AllowOverride None
</Directory>
<Directory "{public}">
 Require local
 AllowOverride All
 Options -Indexes
</Directory>
<FilesMatch "\\.php$">
 SetHandler application/x-httpd-php
</FilesMatch>
php_admin_value session.save_path "{private}"
php_admin_value error_log "{private}/php-error.log"
'''
        (folder / 'apache.conf').write_text(conf, encoding='utf8')
        subprocess.run(['C:/xampp/apache/bin/httpd.exe', '-t', '-f', str(folder / 'apache.conf')], cwd=ROOT, env=env, check=True)
        startup = subprocess.STARTUPINFO()
        startup.dwFlags |= subprocess.STARTF_USESHOWWINDOW
        startup.wShowWindow = subprocess.SW_HIDE
        with (folder / 'apache.log').open('wb') as log:
            child = subprocess.Popen(['C:/xampp/apache/bin/httpd.exe', '-f', str(folder / 'apache.conf')], cwd=ROOT, env=env, stdout=log, stderr=log, startupinfo=startup)
        (folder / 'processes.json').write_text(json.dumps({'apache':child.pid}))
        for _ in range(60):
            try:
                response=requests.get(BASE+'summarizer.php', timeout=2)
                if response.status_code==200:
                    print('Restricted isolated design-system server ready at '+BASE)
                    return
            except requests.RequestException:
                pass
            time.sleep(.25)
        raise RuntimeError('Isolated Apache did not become ready.')
    if args.action == 'browser':
        subprocess.run([PHP, 'scripts/qa/design_system_fixture.php', str(folder), 'clear-drafts'], cwd=ROOT, env=env, check=True)
        subprocess.run(['node', 'scripts/qa/design_system_browser.cjs'], cwd=ROOT, env=env, check=True)
        return
    try:
        processes = json.loads((folder / 'processes.json').read_text()) if (folder / 'processes.json').exists() else {}
        for pid in processes.values():
            subprocess.run(['taskkill', '/PID', str(pid), '/T', '/F'], capture_output=True)
        verify = subprocess.run([PHP, 'scripts/qa/control.php', 'verify', str(folder)], cwd=ROOT, check=False)
        if (folder/'integrity.json').exists():
            (REPORT/'integrity.json').write_bytes((folder/'integrity.json').read_bytes())
    finally:
        subprocess.run([PHP, 'scripts/qa/control.php', 'cleanup', str(folder)], cwd=ROOT, check=True)
        (folder/'config.json').unlink(missing_ok=True)
    if verify.returncode:
        raise SystemExit(verify.returncode)
    print('Owned server stopped; restricted schemas/user removed; production core rows preserved.')


if __name__ == '__main__':
    main()
