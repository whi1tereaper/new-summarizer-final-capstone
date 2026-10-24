"""Length-only diagnosis and retrospective matching; never edits summaries."""
from functools import lru_cache
from hashlib import sha256
import json
from pathlib import Path
import sys

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
from evaluation.final_validation import OUT,PILOT,SYSTEMS,DEPTHS,corpus,runs,read,write,verify
from evaluation.independent_comparison import WORD,norm,choose_by_length
from article_engine.preprocessing import TOKEN


def relaxed_lengths(records,count,cap):
    """Exact two-tokenizer knapsack, relaxing ALL context/selection constraints.

    dp[k][delta] has engine-cost bits, with delta=evaluator_words-engine_words.
    This avoids silently treating the pilot's word regex as the engine's budget.
    """
    mask=(1<<(cap+1))-1
    dp=[{} for _ in range(count+1)];dp[0][0]=1
    for source in records:
        e=len(TOKEN.findall(source['text']));w=len(WORD.findall(source['text']));delta=w-e
        for k in range(count,0,-1):
            for old_delta,bits in list(dp[k-1].items()):
                value=(bits<<e)&mask
                if value:dp[k][old_delta+delta]=dp[k].get(old_delta+delta,0)|value
    result=0
    for by_delta in dp[1:]:
        for delta,bits in by_delta.items():
            result|=bits<<delta if delta>=0 else bits>>(-delta)
    return result&~1


def relaxed_match(bits,target):
    import math
    lo=max(1,math.ceil(target-max(5,.1*target)));hi=math.floor(target+max(5,.1*target))
    return bool((bits>>lo)&((1<<(hi-lo+1))-1))


def diagnose():
    data=runs();docs={d['id']:d for d in corpus()}
    base={r['case_id']:r for r in data['legacy']}
    rows=[]
    for system in SYSTEMS:
        for row in data[system]:
            assert row['trials'][row['selected_trial']]==choose_by_length(row['trials'],row['target_words'])
            if row['kind']!='general' or system not in SYSTEMS[2:]:continue
            doc=docs[row['article']];native=row['trials'][0];selected=row['trials'][row['selected_trial']]
            ui,ratio,internal,maximum=DEPTHS[row['depth']]
            count=min(ui,internal,len(doc['source_sentences']))
            ceiling=min(maximum,max(45,round(doc['word_count']*ratio)))
            searched=max([ceiling]+[t['budget'] for t in row['trials'] if t['budget'] is not None])
            bits_native=relaxed_lengths(doc['source_sentences'],count,ceiling)
            bits_searched=relaxed_lengths(doc['source_sentences'],count,searched)
            bits_maximum=relaxed_lengths(doc['source_sentences'],count,maximum)
            target=row['target_words']
            rows.append({'system':system,'case_id':row['case_id'],'category':row['category'],'depth':row['depth'],
                'target_legacy_words':target,'ui_requested_count':ui,'article_effective_count':count,
                'article_internal_depth_limit':internal,'native_ceiling_engine_words':ceiling,
                'max_searched_ceiling_engine_words':searched,'depth_maximum_engine_words':maximum,
                'native_words':native['words'],'selected_words':selected['words'],
                'native_output_count':len(native['sentences']),'selected_output_count':len(selected['sentences']),
                'native_engine_words':sum(len(TOKEN.findall(s)) for s in native['sentences']),
                'selected_engine_words':sum(len(TOKEN.findall(s)) for s in selected['sentences']),
                'sentence_cap_saturated':len(native['sentences'])==count,
                'matched':row['matched']['0.1'],'absolute_difference':row['absolute_word_difference'],
                'distinct_trial_outputs':len({tuple(t['sentences']) for t in row['trials']}),
                'relaxed_native_target_feasible':relaxed_match(bits_native,target),
                'relaxed_searched_target_feasible':relaxed_match(bits_searched,target),
                'relaxed_full_depth_target_feasible':relaxed_match(bits_maximum,target),
                'relaxed_maximum_evaluator_words':bits_maximum.bit_length()-1,
                'trial_requests':[{k:t[k] for k in ['budget','sentence_request','words','error']} for t in row['trials']]})
    summary={s:{'cases':len(g:=[r for r in rows if r['system']==s]),'matched':sum(r['matched'] for r in g),
        'sentence_saturated':sum(r['sentence_cap_saturated'] for r in g),
        'unmatched_but_relaxed_searched_feasible':sum(not r['matched'] and r['relaxed_searched_target_feasible'] for r in g),
        'hard_relaxed_searched_infeasible':sum(not r['relaxed_searched_target_feasible'] for r in g),
        'hard_relaxed_full_depth_infeasible':sum(not r['relaxed_full_depth_target_feasible'] for r in g),
        'same_output_all_trials':sum(r['distinct_trial_outputs']==1 for r in g)} for s in SYSTEMS[2:]}
    write('length-diagnosis.json',{'summary':summary,'rows':rows,
        'interpretation':'Relaxed feasibility does not ensure valid context, central meaning, nonredundancy or a selector-reachable output.',
        'two_word_metrics_explicit':True,'pilot_reproduced_without_reselection':True})
    print('Length diagnosis',json.dumps(summary))


def output_pool(rows,all_trials=False):
    result=[];by_text={}
    for row in rows:
        if row['kind']!='general':continue
        for i,t in enumerate(row['trials'] if all_trials else row['trials'][:1]):
            if t['error'] is not None or not t['sentences']:continue
            key=sha256(json.dumps(t['sentences'],ensure_ascii=False).encode()).hexdigest()
            origin={'case_id':row['case_id'],'depth':row['depth'],'trial_index':i,'budget':t['budget'],'sentence_request':t['sentence_request']}
            if key in by_text:by_text[key]['origins'].append(origin);continue
            entry={'output_id':key,'words':t['words'],'sentences':t['sentences'],'origins':[origin],
                   'diagnostics':t['diagnostics']}
            result.append(entry);by_text[key]=entry
    return result


def assign_lengths(left,right):
    """Maximum-cardinality/minimum-absolute-distance matching; input = lengths only.

    A left-bitmask DP assigns each right output at most once. Quality information
    is neither accepted by this function nor consulted by tie breaking.
    """
    dp={0:(0,())}
    for j,w in enumerate(right):
        updated=dict(dp)
        for mask,(cost,pairs) in dp.items():
            for i,t in enumerate(left):
                delta=abs(w-t)
                if mask&(1<<i) or delta>max(5,.1*t):continue
                new_mask=mask|(1<<i);candidate=(cost+delta,pairs+((i,j),))
                if new_mask not in updated or candidate<updated[new_mask]:updated[new_mask]=candidate
        dp=updated
    mask,(cost,pairs)=min(dp.items(),key=lambda item:(-item[0].bit_count(),item[1][0],item[1][1]))
    return {'pairs':sorted(pairs),'pair_count':mask.bit_count(),'sum_absolute_word_difference':cost}


def score_pairs(pairs):
    return {'pairs':len(pairs),'legacy_words':sum(p['legacy']['words'] for p in pairs),
        'candidate_words':sum(p['candidate']['words'] for p in pairs),
        'draft_legacy_main_hits':sum(p['legacy']['diagnostics']['draft_main_hit'] for p in pairs),
        'draft_candidate_main_hits':sum(p['candidate']['diagnostics']['draft_main_hit'] for p in pairs),
        'draft_legacy_unit_hits':sum(len(p['legacy']['diagnostics']['draft_unit_hits']) for p in pairs),
        'draft_candidate_unit_hits':sum(len(p['candidate']['diagnostics']['draft_unit_hits']) for p in pairs),
        'independent_semantic_quality':None}


def cross_depth():
    all_rows=runs();analyses={}
    for mode in ['native_only','all_saved_trials']:
        systems={}
        for system in SYSTEMS[1:]:
            pairs=[];articles=[];unmatched=[]
            for doc in corpus():
                lrows=[r for r in all_rows['legacy'] if r['article']==doc['id'] and r['kind']=='general']
                crows=[r for r in all_rows[system] if r['article']==doc['id'] and r['kind']=='general']
                left=output_pool(lrows);right=output_pool(crows,mode=='all_saved_trials')
                assignment=assign_lengths([o['words'] for o in left],[o['words'] for o in right])
                article_pairs=[{'article':doc['id'],'category':doc['category'],'legacy':left[i],'candidate':right[j],
                    'legacy_depth':left[i]['origins'][0]['depth'],'candidate_depth':right[j]['origins'][0]['depth'],
                    'absolute_word_difference':abs(left[i]['words']-right[j]['words'])} for i,j in assignment['pairs']]
                pairs+=article_pairs
                matched_legacy={p['legacy']['origins'][0]['case_id'] for p in article_pairs}
                unmatched.extend({'system':system,'article':doc['id'],'legacy_case_id':r['case_id'],
                                  'legacy_words':r['trials'][0]['words']} for r in lrows if r['case_id'] not in matched_legacy)
                articles.append({'article':doc['id'],'category':doc['category'],'left_unique_outputs':len(left),
                    'right_unique_outputs':len(right),'score':score_pairs(article_pairs),
                    'unmatched_candidate_output_ids':[o['output_id'] for j,o in enumerate(right) if j not in {j for _,j in assignment['pairs']}]})
            systems[system]={'score':score_pairs(pairs),'pairs':pairs,'per_article':articles,'unmatched_legacy_cases':unmatched,
                'cross_depth_pair_count':sum(p['legacy_depth']!=p['candidate_depth'] for p in pairs)}
        common=set.intersection(*({p['legacy']['origins'][0]['case_id'] for p in systems[s]['pairs']} for s in SYSTEMS[1:]))
        analyses[mode]={'systems':systems,'common_legacy_cases':sorted(common),
            'common_scores':{s:score_pairs([p for p in systems[s]['pairs'] if p['legacy']['origins'][0]['case_id'] in common]) for s in SYSTEMS[1:]}}
    write('cross-depth-sensitivity.json',{'role':'retrospective_length_only_sensitivity_not_registered_pilot_or_independent_holdout',
         'matching_rule':'one-to-one max cardinality, min absolute words, stable order; unchanged legacy-anchored tolerance',
         'quality_used_for_matching':False,'analyses':analyses})
    print('Cross-depth',json.dumps({m:{s:v['score'] for s,v in a['systems'].items()} for m,a in analyses.items()}))


if __name__=='__main__':verify();diagnose();cross_depth()
