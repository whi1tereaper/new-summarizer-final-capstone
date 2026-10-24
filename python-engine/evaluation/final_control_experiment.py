"""Registered control probes and new Focus requests; no algorithm edits."""
from __future__ import annotations
import argparse
from datetime import datetime,timezone
from hashlib import sha256
import json
import os
from pathlib import Path
import subprocess
import sys
from time import perf_counter

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
from evaluation.final_validation import OUT,PILOT,SYSTEMS,DEPTHS,corpus,read,write,digest,verify
from evaluation.independent_comparison import WORD


def lock_inputs():
    if (OUT/'followup-input-lock.json').exists():return
    write('followup-input-lock.json',{'locked_utc':datetime.now(timezone.utc).isoformat(),
        'pilot_corpus_sha256':digest(PILOT/'corpus.json'),'focus_probes_sha256':digest(OUT/'focus-probes.json'),
        'protocol_sha256':digest(OUT/'protocol.md'),'harness_sha256':digest(Path(__file__)),
        'new_generation_started':False,'independent_human_labels':None})


def verify_inputs():
    lock=read(OUT/'followup-input-lock.json')
    assert digest(OUT/'focus-probes.json')==lock['focus_probes_sha256']
    assert digest(PILOT/'corpus.json')==lock['pilot_corpus_sha256']
    assert digest(OUT/'protocol.md')==lock['protocol_sha256']


def worker(system):
    verify();verify_inputs()
    if (OUT/('new-runs-'+system+'.json')).exists():raise RuntimeError('Refusing to overwrite recorded run')
    from article_detector import detect_article
    from article_engine.summarizer import summarize
    from article_engine.baseline import guide_lsa
    from summarizer_core.models import SourceDocument
    if system=='legacy':from local_cli import handle_summarize
    docs={d['id']:d for d in corpus()};rows=[]
    def generate(doc,depth,kind,query='',count=None,budget=None):
        payload={'text':doc['source_text'],'summary_depth':depth,'summary_length':depth,
            'sentence_count':count if count is not None else DEPTHS[depth][0],
            'analysis_mode':'general','selection_mode':'general','summary_style':'standard_paragraph',
            'output_format':'paragraph','focus_query':query,'automatic_article_routing':False,
            'performance_profile':True}
        if budget is not None:payload['target_word_budget']=budget
        began=perf_counter()
        row={'system':system,'article':doc['id'],'category':doc['category'],'depth':depth,'kind':kind,'query':query,
             'request_controls':{k:v for k,v in payload.items() if k!='text'}}
        try:
            if system=='legacy':result=handle_summarize(payload)
            elif system=='guide_lsa':result={'sentences':guide_lsa(doc['source_text'],payload['sentence_count'])}
            else:result=summarize(payload,SourceDocument(doc['source_text'],'text'),detect_article(doc['source_text']),enhanced=system.endswith('enhanced'))
            metadata=result.get('source_metadata',{})
            row.update(error=None,sentences=result['sentences'],plain_summary=result.get('plain_summary',' '.join(result['sentences'])),
                actual_words=len(WORD.findall(' '.join(result['sentences']))),source_metadata=metadata,
                readability=result.get('readability'),evidence=result.get('evidence',[]),summary_method=result.get('summary_method'))
        except Exception as error:
            row.update(error=type(error).__name__,failure_code=getattr(error,'failure_code',None),sentences=[],plain_summary='',actual_words=0)
        row['seconds']=perf_counter()-began;row['record_id']=system+':'+doc['id']+':'+depth+':'+kind
        rows.append(row)
        write('new-runs-'+system+'.json',{'complete':False,'rows':rows})
        print(system,doc['id'],depth,kind,row['actual_words'],row['error'],flush=True)
    if system=='legacy':
        for identity in ['IV-BUSINESS','IV-RESEARCH']:
            for name,count,budget in [('native',8,None),('word_budget_50',8,50),('count_3',3,None),('count_15',15,None)]:
                generate(docs[identity],'balanced','legacy_control_'+name,count=count,budget=budget)
    if system.startswith('article21'):
        for doc in docs.values():
            for depth in ['balanced','comprehensive']:
                ui,ratio,internal,maximum=DEPTHS[depth]
                for name,count,budget in [('native',ui,None),('maximum_words',ui,maximum),('internal_count_and_words',internal,maximum)]:
                    generate(doc,depth,'article_control_'+name,count=count,budget=budget)
    for probe in read(OUT/'focus-probes.json')['probes']:
        generate(docs[probe['article']],'balanced','focus_'+probe['kind'],query=probe['query'])
    write('new-runs-'+system+'.json',{'complete':True,'rows':rows,'freeze_verification':verify(),
          'harness_at_execution_sha256':digest(Path(__file__))})


def run():
    verify();lock_inputs();verify_inputs()
    env=dict(os.environ,PYTHONIOENCODING='utf8',OPENBLAS_NUM_THREADS='1',OMP_NUM_THREADS='1',MKL_NUM_THREADS='1',
             HF_HUB_OFFLINE='1',TRANSFORMERS_OFFLINE='1',TOKENIZERS_PARALLELISM='false')
    for system in SYSTEMS:
        path=OUT/('new-runs-'+system+'.json')
        if path.exists() and read(path).get('complete'):continue
        command=[sys.executable,str(Path(__file__).resolve()),'--worker',system]
        done=subprocess.run(command,env=env,timeout=1800)
        if done.returncode:raise RuntimeError(system+' worker failed '+str(done.returncode))


if __name__=='__main__':
    sys.stdout.reconfigure(encoding='utf8')
    parser=argparse.ArgumentParser();parser.add_argument('--worker',choices=SYSTEMS);args=parser.parse_args()
    if args.worker:worker(args.worker)
    else:run()
