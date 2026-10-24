"""Complete-output comparison; length selection cannot access unit annotations."""
from __future__ import annotations
import argparse
from concurrent.futures import ThreadPoolExecutor
from hashlib import sha256
import json
import math
import os
from pathlib import Path
import re
import subprocess
import sys
from time import perf_counter

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
from evaluation.independent_validation import OUT, verify, write

SYSTEMS=['legacy','guide_lsa','article21_light','article21_enhanced']
DEPTHS={'very_brief':(3,80),'brief':(3,150),'short':(5,280),
        'balanced':(8,500),'detailed':(11,850),'comprehensive':(15,1600)}
WORD=re.compile(r"\b[A-Za-z0-9]+(?:['’-][A-Za-z0-9]+)*\b")


def inputs():
    lock=json.loads((OUT/'input-lock.json').read_text(encoding='utf8'))
    assert sha256((OUT/'corpus.json').read_bytes()).hexdigest()==lock['corpus_sha256']
    assert sha256((OUT/'draft-labels.json').read_bytes()).hexdigest()==lock['draft_labels_sha256']
    return json.loads((OUT/'corpus.json').read_text(encoding='utf8'))


def cases():
    for doc in inputs():
        for depth in DEPTHS: yield doc,depth,None
        for probe in doc['focus_probes']: yield doc,'balanced',probe


def case_id(doc,depth,probe):
    return doc['id']+':'+depth+':'+(probe['expected_support'] if probe else 'general')


def norm(text): return ' '.join(text.split())


def choose_by_length(trials,target):
    # Deliberately takes no source, annotation, score, or query-support label.
    valid=[r for r in trials if r['error'] is None and r['sentences']]
    return min(valid,key=lambda r:(abs(r['words']-target),r['words'])) if valid else trials[0]


def diagnostics(doc,output,probe):
    sentences=output['sentences']; source=norm(doc['source_text'])
    joined=norm(' '.join(sentences))
    present={s['id'] for s in doc['source_sentences'] if norm(s['text']) in joined}
    hits=[unit['id'] for unit in doc['draft_units'] if any(set(bundle)<=present for bundle in unit['evidence_any'])]
    context=[]
    for rule in doc['draft_context_rules']:
        triggers=[f's{i:04d}' for i in rule['trigger_any'] if f's{i:04d}' in present]
        if triggers and not any(all(f's{i:04d}' in present for i in bundle) for bundle in rule['required_any']):
            context.append({'rule':rule['id'],'trigger_sentence_ids':triggers,
                            'missing_alternative_context_ids':[[f's{i:04d}' for i in b] for b in rule['required_any']],
                            'status':'draft_review_flag_not_verified_factual_error'})
    return {'draft_unit_hits':hits,'draft_unit_count':len(doc['draft_units']),
            'draft_main_hit':'main' in hits,'source_sentence_ids':sorted(present),
            'exact_source_output_sentences':sum(norm(s) in source for s in sentences),
            'output_sentence_count':len(sentences),'exact_duplicate_count':len(sentences)-len(set(map(norm,sentences))),
            'nonverbatim_output_indices':[i for i,s in enumerate(sentences) if norm(s) not in source],
            'draft_context_flags':context,
            'draft_focus_unit_hits':[u for u in (probe['unit_ids'] if probe else []) if u in hits],
            'draft_focus_unit_count':len(probe['unit_ids']) if probe else None,
            'human_main_idea':None,'human_factuality':None,'human_context':None}


def worker(system,targets,resume=False):
    verify()
    from local_cli import handle_summarize
    from article_detector import detect_article
    from article_engine.summarizer import summarize
    from article_engine.baseline import guide_lsa
    from summarizer_core.models import SourceDocument
    rows=[]
    path=OUT/('runs-'+system+'.json')
    if path.exists():
        if not resume: raise RuntimeError('Run exists; use --resume to retain completed cases')
        previous=json.loads(path.read_text(encoding='utf8'))
        if previous['complete']: return previous['rows']
        rows=previous['rows']
    completed={r['case_id'] for r in rows}
    for doc,depth,probe in cases():
        identity=case_id(doc,depth,probe); count,cap=DEPTHS[depth]
        if identity in completed: continue
        query=probe['query'] if probe else ''
        request={'text':doc['source_text'],'summary_depth':depth,'summary_length':depth,
                 'sentence_count':count,'analysis_mode':'general','selection_mode':'general',
                 'summary_style':'standard_paragraph','output_format':'paragraph',
                 'focus_query':query,'automatic_article_routing':False,'performance_profile':True}
        def generate(budget=None,sentence_count=count):
            payload=dict(request,sentence_count=sentence_count)
            if budget is not None: payload['target_word_budget']=budget
            started=perf_counter()
            try:
                if system=='legacy': result=handle_summarize(payload)
                elif system=='guide_lsa': result={'sentences':guide_lsa(doc['source_text'],sentence_count)}
                else: result=summarize(payload,SourceDocument(doc['source_text'],'text'),
                                       detect_article(doc['source_text']),enhanced=system.endswith('enhanced'))
                sentences=result['sentences']; metadata=result.get('source_metadata',{})
                analysis=metadata.get('article_analysis',{})
                return {'sentences':sentences,'plain_summary':result.get('plain_summary',' '.join(sentences)),
                        'words':len(WORD.findall(' '.join(sentences))),'error':None,
                        'budget':budget,'sentence_request':sentence_count,'seconds':perf_counter()-started,
                        'focus_status':analysis.get('focus_status'),'annotation_status':analysis.get('annotation_status'),
                        'summary_method':result.get('summary_method'),
                        'engine':metadata.get('engine'),'engine_version':metadata.get('engine_version'),
                        'engine_guard':metadata.get('source_guard'),'routing':metadata.get('engine_routing'),
                        'retrieval_metadata':result.get('retrieval_metadata')}
            except Exception as error:
                return {'sentences':[],'plain_summary':'','words':0,'error':type(error).__name__,
                        'failure_code':getattr(error,'failure_code',None),
                        'budget':budget,'sentence_request':sentence_count,'seconds':perf_counter()-started,'focus_status':None}
        trials=[generate()]
        target=targets.get(identity,trials[0]['words'])
        if system!='legacy' and target>0 and not (probe and probe['expected_support']=='unsupported'):
            if system=='guide_lsa': trials.extend(generate(sentence_count=k) for k in range(1,count))
            else:
                budgets=sorted({max(1,min(cap,b)) for b in [target-10,target,target+10,math.ceil(1.35*target)]})
                trials.extend(generate(budget=b) for b in budgets)
        selected=choose_by_length(trials,target)
        # Only after the output is selected do annotation diagnostics run.
        for trial in trials: trial['diagnostics']=diagnostics(doc,trial,probe)
        matchable=not probe or probe['expected_support']!='unsupported'
        row={'system':system,'case_id':identity,'article':doc['id'],'category':doc['category'],
             'depth':depth,'kind':probe['expected_support'] if probe else 'general',
             'focus_query':query,'focus_gold_status':'agent_draft_pending_review' if probe else None,
             'guide_is_query_blind':system=='guide_lsa' and bool(probe),
             'native_trial':0,'selected_trial':trials.index(selected),'trials':trials,
             'target_words':target,'absolute_word_difference':abs(selected['words']-target),
             'matched':{str(p):bool(matchable and selected['error'] is None and selected['sentences'] and
                                   abs(selected['words']-target)<=max(5,p*target)) for p in [.05,.1,.2]}}
        rows.append(row)
        # Incremental evidence survives a later timeout/worker failure.
        write('runs-'+system+'.json',{'system':system,'complete':False,'rows':rows})
        print(system+' '+identity+' native='+str(trials[0]['words'])+' selected='+str(selected['words']),file=sys.stderr,flush=True)
    write('runs-'+system+'.json',{'system':system,'complete':True,'rows':rows,'freeze_verification':verify()})
    return rows


def aggregate(rows,which='selected'):
    outputs=[r['trials'][0 if which=='native' else r['selected_trial']] for r in rows]
    return {'cases':len(rows),'words':sum(o['words'] for o in outputs),
            'execution_failures':sum(o['error'] is not None for o in outputs),
            'draft_main_hits':sum(o['diagnostics']['draft_main_hit'] for o in outputs),
            'draft_unit_hits':sum(len(o['diagnostics']['draft_unit_hits']) for o in outputs),
            'draft_unit_denominator':sum(o['diagnostics']['draft_unit_count'] for o in outputs),
            'source_exact_sentences':sum(o['diagnostics']['exact_source_output_sentences'] for o in outputs),
            'output_sentences':sum(len(o['sentences']) for o in outputs),
            'draft_context_flags':sum(len(o['diagnostics']['draft_context_flags']) for o in outputs),
            'exact_duplicates':sum(o['diagnostics']['exact_duplicate_count'] for o in outputs),
            'matched_cases':sum(r['matched']['0.1'] for r in rows),
            'independent_human_scores':None}


def report():
    rows=[]
    for system in SYSTEMS:
        data=json.loads((OUT/('runs-'+system+'.json')).read_text(encoding='utf8'))
        assert data['complete']
        rows.extend(data['rows'])
    general=[r for r in rows if r['kind']=='general']
    legacy={r['case_id']:r for r in rows if r['system']=='legacy'}
    paired={}; unmatched=[]
    for system in SYSTEMS[1:]:
        selected=[r for r in general if r['system']==system and r['matched']['0.1']]
        paired[system]={'candidate':aggregate(selected),'paired_legacy':aggregate([legacy[r['case_id']] for r in selected],'native'),
                        'case_ids':[r['case_id'] for r in selected]}
        unmatched.extend({k:r[k] for k in ['system','case_id','target_words','absolute_word_difference','selected_trial']}
                         for r in general if r['system']==system and not r['matched']['0.1'])
    common=set.intersection(*(set(paired[s]['case_ids']) for s in SYSTEMS[1:]))
    data={'engine':'recipe-article-2.1','input_lock_sha256':sha256((OUT/'input-lock.json').read_bytes()).hexdigest(),
          'human_review':{'status':'pending','reviewers':[],'scores':None},
          'general_native':{s:aggregate([r for r in general if r['system']==s],'native') for s in SYSTEMS},
          'general_selected':{s:aggregate([r for r in general if r['system']==s]) for s in SYSTEMS},
          'per_category':{d['category']:{s:aggregate([r for r in general if r['system']==s and r['article']==d['id']]) for s in SYSTEMS} for d in inputs()},
          'per_depth':{depth:{s:aggregate([r for r in general if r['system']==s and r['depth']==depth]) for s in SYSTEMS} for depth in DEPTHS},
          'matched_pairs':paired,'common_intersection':{'case_ids':sorted(common),
              'systems':{s:aggregate([r for r in general if r['system']==s and r['case_id'] in common]) for s in SYSTEMS}},
          'matching_sensitivity':{str(p):{s:sum(r['matched'][str(p)] for r in general if r['system']==s) for s in SYSTEMS} for p in [.05,.1,.2]},
          'generation_trials':sum(len(r['trials']) for r in rows),
          'trial_failures':sum(t['error'] is not None for r in rows for t in r['trials']),
          'unmatched_general_cases':unmatched,'freeze_verification':verify(),
          'limitations':['Eight convenience-sampled articles, one per genre; no category replication.',
                         'Draft unit hits require entire source evidence and are NOT independent accuracy or semantic judgments.',
                         'Repeated depths and outputs are clustered within articles; no independent-review statistical test exists.',
                         'Guide LSA ignores Focus. Complete-output length matching cannot always succeed.']}
    write('comparison-results.json',data)
    print(json.dumps(data['general_selected'],indent=2))


def evaluate(resume=False):
    verify(); inputs()
    env=dict(os.environ,PYTHONIOENCODING='utf8',OPENBLAS_NUM_THREADS='1',OMP_NUM_THREADS='1',MKL_NUM_THREADS='1',
             HF_HUB_OFFLINE='1',TRANSFORMERS_OFFLINE='1',TOKENIZERS_PARALLELISM='false')
    def run(system,targets):
        command=[sys.executable,str(Path(__file__).resolve()),'--worker',system]
        if resume: command.append('--resume')
        process=subprocess.run(command,
                    input=json.dumps(targets),text=True,encoding='utf8',env=env,timeout=1800)
        if process.returncode: raise RuntimeError(system+' worker failed: '+str(process.returncode))
    run('legacy',{})
    base=json.loads((OUT/'runs-legacy.json').read_text(encoding='utf8'))['rows']
    targets={r['case_id']:r['trials'][0]['words'] for r in base}
    # Serial processes avoid host commit contention with model imports. This
    # changes scheduling only, never a request, parameter or length-search rule.
    for system in SYSTEMS[1:]: run(system,targets)
    report()


if __name__=='__main__':
    sys.stdout.reconfigure(encoding='utf8')
    parser=argparse.ArgumentParser(); parser.add_argument('--worker',choices=SYSTEMS); parser.add_argument('--report',action='store_true'); parser.add_argument('--resume',action='store_true')
    args=parser.parse_args()
    if args.worker: worker(args.worker,json.loads(sys.stdin.read() or '{}'),args.resume)
    elif args.report: report()
    else: evaluate(args.resume)
