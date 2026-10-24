"""Frozen comparisons at native UI depths and regenerated realized lengths.

Previously inspected synthetic/historical examples are diagnostic, not a new
holdout. Output-length search never sees annotations and never truncates output.
"""
import argparse
from hashlib import sha256
import json
import math
import os
from pathlib import Path
import subprocess
import sys
from time import perf_counter

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p != str(Path(__file__).resolve().parent)]
from evaluation.coverage_corpus import heldout
from evaluation.article_engine_experiment import quality_documents
from evaluation.coverage_experiment import metrics, MemorySampler
from evaluation.refinement_calibration import DEPTHS
from article_engine.core import core_parameters
from article_engine.coverage import parameters, TOKEN

SYSTEMS=['legacy','guide_lsa','article_v1','article_v2','article_v2_enhanced',
         'refined_light','refined_enhanced','refined_no_core','refined_no_mmr']


def documents():
    docs=heldout()+[d for d in quality_documents() if d.get('key_units')]
    return [{**d,'evaluation_role':'previously_inspected_diagnostic_not_new_holdout'} for d in docs]


def jobs():
    for doc in documents():
        for depth in DEPTHS:
            yield doc,depth,'','general'
        if doc.get('focus'):
            yield doc,'balanced',doc['focus']['query'],'focus'
            yield doc,'balanced','volcanic seismology on Jupiter','unsupported_focus'


def worker(system,targets):
    from summarizer_core.models import SourceDocument
    from article_detector import detect_article
    from local_cli import handle_summarize
    from article_engine.summarizer import summarize
    from evaluation.article_v1_snapshot.summarizer import summarize as v1
    from evaluation.article_v2_snapshot.summarizer import summarize as v2
    from article_engine.baseline import guide_lsa
    rows=[]
    for doc,depth,focus,kind in jobs():
        case=doc['id']+':'+depth+':'+kind
        _,count,cap=DEPTHS[depth]
        payload={'text':doc['source_text'],'summary_depth':depth,'summary_length':depth,
                 'sentence_count':count,'analysis_mode':doc.get('profile','general'),
                 'output_format':'paragraph','focus_query':focus,'automatic_article_routing':False,
                 'performance_profile':True}
        def generate(budget=None,sentence_count=None):
            request=dict(payload)
            if budget is not None: request['target_word_budget']=budget
            if sentence_count is not None: request['sentence_count']=sentence_count
            started=perf_counter()
            try:
                with MemorySampler() as memory:
                    if system=='legacy':
                        result=handle_summarize(request)
                    elif system=='guide_lsa':
                        result={'sentences':guide_lsa(doc['source_text'],request['sentence_count'])}
                    else:
                        source=SourceDocument(doc['source_text'],'text')
                        detection=detect_article(doc['source_text'])
                        if system=='article_v1': result=v1(request,source,detection)
                        elif system.startswith('article_v2'):
                            result=v2(request,source,detection,enhanced=system.endswith('enhanced'))
                        else:
                            options={'enhanced':system=='refined_enhanced'}
                            if system=='refined_no_core': options['core_config']=dict(core_parameters(),enabled=False)
                            if system=='refined_no_mmr': options['config']=dict(parameters(),**{'lambda':1.0})
                            result=summarize(request,source,detection,**options)
                sentences=result['sentences']
                return {'sentences':sentences,'metrics':metrics(doc,sentences,query=focus),
                        'focus_status':result.get('source_metadata',{}).get('article_analysis',{}).get('focus_status'),
                        'analysis':result.get('source_metadata',{}).get('article_analysis',{}),
                        'seconds':perf_counter()-started,'sampled_peak_rss_mib':memory.peak/1048576,
                        'requested_budget':budget,'requested_sentences':request['sentence_count'],'error':None}
            except Exception as error:
                return {'sentences':[],'metrics':metrics(doc,[],query=focus),'focus_status':None,
                        'seconds':perf_counter()-started,'requested_budget':budget,'error':type(error).__name__,
                        'failure_code':getattr(error,'failure_code',None)}
        native=generate()
        target=targets.get(case,native['metrics']['word_count'])
        generated=[native]
        if system!='legacy' and kind!='unsupported_focus' and target>0:
            if system=='guide_lsa':
                generated.extend(generate(sentence_count=k) for k in range(1,count) if k!=count)
            else:
                budgets={max(1,target-10),max(1,target),min(cap,target+10),min(cap,max(1,math.ceil(1.35*target)))}
                generated.extend(generate(budget=b) for b in sorted(budgets))
        successful=[r for r in generated if r['error'] is None and (r['sentences'] or kind!='general')]
        matched=min(successful,key=lambda r:(abs(r['metrics']['word_count']-target),r['metrics']['word_count'])) if successful else native
        tolerance=max(5,.1*target)
        rows.append({'system':system,'id':doc['id'],'depth':depth,'kind':kind,'focus':focus,
                     'case_id':case,'native':native,'matched':matched,'target_words':target,
                     'realized_length_matched':bool(not matched['error'] and abs(matched['metrics']['word_count']-target)<=tolerance),
                     'absolute_length_difference':abs(matched['metrics']['word_count']-target),
                     'valid_generation_trials':[{'budget':g['requested_budget'],'words':g['metrics']['word_count'],
                                                 'error':g['error'],'failure_code':g.get('failure_code')} for g in generated]})
    return rows


def totals(rows,which='native'):
    keys=['unit_covered','unit_count','main_idea_preserved','fact_preserved','fact_count','source_exact_count',
          'sentence_count','context_dependency_errors','duplicate_count','word_count','focus_unit_covered',
          'focus_unit_count','focus_relevant_sentences','focus_sentence_count']
    data={key:sum(r[which]['metrics'][key] for r in rows) for key in keys}
    data.update(cases=len(rows),failures=sum(r[which]['error'] is not None for r in rows),
                realized_length_matched_cases=sum(r['realized_length_matched'] for r in rows),
                mean_absolute_word_difference=sum(r['absolute_length_difference'] for r in rows)/max(1,len(rows)),
                mean_annotated_sentence_precision=sum(r[which]['metrics']['annotated_sentence_precision'] for r in rows)/max(1,len(rows)),
                mean_seconds=sum(r[which]['seconds'] for r in rows)/max(1,len(rows)),
                sampled_peak_rss_mib=max((r[which].get('sampled_peak_rss_mib',0) for r in rows),default=0))
    return data


def evaluate():
    rows=[]; targets={}; processes={}
    env=dict(os.environ,PYTHONIOENCODING='utf-8',OPENBLAS_NUM_THREADS='1',OMP_NUM_THREADS='1',
             MKL_NUM_THREADS='1',HF_HUB_OFFLINE='1',TRANSFORMERS_OFFLINE='1',TOKENIZERS_PARALLELISM='false')
    for system in SYSTEMS:
        began=perf_counter()
        result=subprocess.run([sys.executable,str(Path(__file__).resolve()),'--worker',system],
                              input=json.dumps(targets),capture_output=True,text=True,encoding='utf8',env=env,timeout=1200)
        if result.returncode: raise RuntimeError(system+' comparison worker failed: '+result.stderr[-1000:])
        current=json.loads(result.stdout); rows.extend(current)
        if system=='legacy': targets={r['case_id']:r['native']['metrics']['word_count'] for r in current}
        processes[system]={'wall_seconds':perf_counter()-began,'cases':len(current),'stderr_tail':result.stderr[-400:]}
        print('Finished '+system+': '+str(len(current))+' cases',file=sys.stderr,flush=True)
    aggregates={}
    for system in SYSTEMS:
        for kind in ['general','focus','unsupported_focus']:
            group=[r for r in rows if r['system']==system and r['kind']==kind]
            aggregates[system+'/'+kind]={which:totals(group,which) for which in ['native','matched']}
    per_depth={depth:{system:totals([r for r in rows if r['system']==system and r['kind']=='general' and r['depth']==depth],'matched')
                      for system in SYSTEMS} for depth in DEPTHS}
    per_article={doc['id']:{system:totals([r for r in rows if r['system']==system and r['kind']=='general' and r['id']==doc['id']],'matched')
                            for system in SYSTEMS} for doc in documents()}
    legacy={r['case_id']:r for r in rows if r['system']=='legacy'}
    comparable={system:{'candidate':totals([r for r in rows if r['system']==system and r['kind']=='general' and r['realized_length_matched']],'matched'),
                        'paired_legacy':totals([legacy[r['case_id']] for r in rows if r['system']==system and r['kind']=='general' and r['realized_length_matched']],'native')}
                for system in SYSTEMS if system!='legacy'}
    return {'protocol':'native_ui_depths_and_regenerated_summaries_nearest_legacy_realized_words_no_prefix_truncation',
            'target_selection':'Legacy native word count per article/depth; output selection sees length/validity only, never annotations.',
            'matching_tolerance':'max(5 words, 10 percent of target); report unmatched cases, no padding.',
            'human_review':{'completed':False,'status':'pending_independent_review'},
            'data_role':'All annotated comparison articles were previously inspected; diagnostic, not held-out validation.',
            'inputs':documents(),'depths':DEPTHS,'parameter_artifact':json.loads((ROOT/'article_engine/refinement_parameters.json').read_text()),
            'source_sha256':{str(p.relative_to(ROOT)):sha256(p.read_bytes()).hexdigest() for p in sorted((ROOT/'article_engine').glob('*.py'))},
            'rows':rows,'aggregate':aggregates,'per_depth':per_depth,'per_article':per_article,
            'within_tolerance_pairs':comparable,'processes':processes,
            'limitations':['Literal unit/precision/main/fact proxies are not independent semantic judgments.',
                           'The word-budget search cannot force equal-length complete-sentence output.',
                           'Timing observations can include concurrent regression work; separate performance measurements are required.',
                           'Two historical annotations require multiple sentences; denominator/attainability remain visible.']}


if __name__=='__main__':
    parser=argparse.ArgumentParser(); parser.add_argument('--worker',choices=SYSTEMS)
    args=parser.parse_args()
    if args.worker: print(json.dumps(worker(args.worker,json.loads(sys.stdin.read() or '{}'))))
    else:
        data=evaluate()
        (ROOT.parent/'docs/research/refinement-results.json').write_text(json.dumps(data,indent=2)+'\n',encoding='utf8')
        print('Wrote refinement-results.json',flush=True)
