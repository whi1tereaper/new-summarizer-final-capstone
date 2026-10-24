"""Linux container-only process/cgroup observer. Never logs command lines/env."""
import argparse
import json
import os
from pathlib import Path
import time


def read_number(path):
    try:return int(path.read_text().strip())
    except (OSError,ValueError):return None


def snapshot():
    began=time.monotonic();groups={};processes=[];unreadable=0
    cgroup=Path('/sys/fs/cgroup')
    current=read_number(cgroup/'memory.current');peak=read_number(cgroup/'memory.peak')
    for directory in Path('/proc').iterdir():
        if not directory.name.isdigit():continue
        try:
            name=(directory/'comm').read_text().strip();pid=int(directory.name)
            if pid==os.getpid():group='observer'
            elif name.startswith('apache'):group='apache_mod_php'
            elif name.startswith('python'):group='python_supervisor_and_nlp'
            elif name.startswith('php'):group='php_cli'
            else:group='other'
            fields={}
            for line in (directory/'smaps_rollup').read_text().splitlines():
                if line.startswith(('Rss:','Pss:','Private_Clean:','Private_Dirty:')):
                    key,value,*_=line.split();fields[key.rstrip(':')]=int(value)*1024
            process={'pid':pid,'group':group,'rss_bytes':fields['Rss'],'pss_bytes':fields['Pss'],
                'private_bytes':fields.get('Private_Clean',0)+fields.get('Private_Dirty',0)}
            processes.append(process)
            total=groups.setdefault(group,{'processes':0,'rss_bytes':0,'pss_bytes':0,'private_bytes':0})
            total['processes']+=1
            for key in ['rss_bytes','pss_bytes','private_bytes']:total[key]+=process[key]
        except (OSError,ValueError,KeyError):unreadable+=1
    return {'monotonic':began,'snapshot_seconds':time.monotonic()-began,'memory_current_bytes':current,
            'memory_peak_bytes':peak,'groups':groups,'processes':processes,'unreadable_or_exited_processes':unreadable}


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--seconds',type=float,default=30);args=parser.parse_args()
    samples=[];began=time.monotonic()
    while time.monotonic()-began<args.seconds:
        row=snapshot();row['relative_seconds']=row['monotonic']-began;samples.append(row)
        time.sleep(.05)
    cg=Path('/sys/fs/cgroup')
    print(json.dumps({'samples':samples,'elapsed_seconds':time.monotonic()-began,
        'memory_max':(cg/'memory.max').read_text().strip(),'swap_max':(cg/'memory.swap.max').read_text().strip(),
        'cpu_max':(cg/'cpu.max').read_text().strip(),'memory_events':(cg/'memory.events').read_text(),
        'memory_peak_bytes':read_number(cg/'memory.peak'),
        'semantics':'RSS/PSS are near-simultaneous sampled process maps; cgroup peak includes other charged memory and may occur between samples.'}))


if __name__=='__main__':main()
