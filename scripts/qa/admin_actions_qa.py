"""Targeted admin text-action QA using the existing restricted fixture harness."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys

import design_system_qa as shared

ROOT=Path(__file__).resolve().parents[2]
REPORT=ROOT/'docs/qa/admin-actions'
PHP='C:/php83/php.exe'


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('action',choices=['setup','browser','stop','all'])
    action=parser.parse_args().action
    REPORT.mkdir(parents=True,exist_ok=True)
    if action=='all':
        try:
            subprocess.run([sys.executable,__file__,'setup'],cwd=ROOT,check=True)
            subprocess.run([sys.executable,__file__,'browser'],cwd=ROOT,check=True)
        finally:
            if (REPORT/'last-run.txt').exists():
                folder=Path((REPORT/'last-run.txt').read_text().strip())
                if (folder/'config.json').exists():subprocess.run([sys.executable,__file__,'stop'],cwd=ROOT,check=True)
        return
    shared.REPORT=REPORT
    if action in ('setup','stop'):
        sys.argv=[shared.__file__,action]
        shared.main()
        if action=='stop':return
    folder=Path((REPORT/'last-run.txt').read_text().strip())
    cfg=json.loads((folder/'config.json').read_text())
    env=os.environ.copy();env.update(cfg['env']);env.update(APP_URL=shared.BASE.rstrip('/'),QA_ACTIONS_BASE=shared.BASE,QA_ACTIONS_REPORT=str(REPORT),QA_ACTIONS_PRIVATE=str(folder))
    if action=='setup':
        subprocess.run([PHP,'scripts/qa/admin_actions_fixture.php',str(folder)],cwd=ROOT,env=env,check=True)
        print('Targeted admin Actions fixtures ready.')
    elif action=='browser':
        subprocess.run([PHP,'scripts/qa/admin_actions_fixture.php',str(folder)],cwd=ROOT,env=env,check=True)
        subprocess.run(['node','scripts/qa/admin_actions_browser.cjs'],cwd=ROOT,env=env,check=True)


if __name__=='__main__':main()
