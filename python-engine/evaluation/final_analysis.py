"""Reproducible descriptive tables; no semantic accuracy or human scores."""
from collections import Counter
from pathlib import Path
import sys

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
from evaluation.final_validation import OUT,PILOT,SYSTEMS,DEPTHS,read,write,verify,runs,corpus


def build():
    verify();old=runs();new={s:read(OUT/('new-runs-'+s+'.json')) for s in SYSTEMS}
    all_new=[r for d in new.values() for r in d['rows']]
    controls=[r for r in all_new if '_control_' in r['kind']]
    control_sets=[];repeat_checks=[]
    for system in SYSTEMS:
        for doc in corpus():
            for depth in ['balanced','comprehensive']:
                group=[r for r in controls if r['system']==system and r['article']==doc['id'] and r['depth']==depth]
                if not group:continue
                native=next(r for r in group if r['kind'].endswith('_native'))
                pilot=next(r for r in old[system] if r['article']==doc['id'] and r['depth']==depth and r['kind']=='general')['trials'][0]
                repeat_checks.append({'system':system,'article':doc['id'],'depth':depth,
                    'same_sentences_as_pilot_native':native['sentences']==pilot['sentences'],
                    'new_words':native['actual_words'],'pilot_words':pilot['words']})
                control_sets.append({'system':system,'article':doc['id'],'depth':depth,'outputs':[
                    {'kind':r['kind'],'words':r['actual_words'],'sentence_count':len(r['sentences']),
                     'request_controls':r['request_controls'],'same_body_as_control_native':r['sentences']==native['sentences'],
                     'advertised_range':r.get('readability',{}).get('target_word_range')}
                    for r in group]})
    focus=[r for r in read(OUT/'focus-output-audit.json')['outputs'] if r['view_role']!='pilot_registered_selected'];focus_tables={}
    for system in SYSTEMS:
        focus_tables[system]={}
        for kind in ['supported','partial','near_unsupported','paraphrased','ambiguous','far_unsupported']:
            rows=[r for r in focus if r['system']==system and r['kind']==kind]
            focus_tables[system][kind]={'queries':len(rows),'nonempty_responses':sum(r['returned_sentences']>0 for r in rows),
                'explicit_unsupported':sum(r['focus_status']=='unsupported' for r in rows),
                'agent_false_acceptance_flags':sum(r['false_acceptance_flag'] for r in rows),
                'agent_false_rejection_flags':sum(r['false_rejection_flag'] for r in rows),
                'execution_errors':sum(r['error'] is not None for r in rows),'independent_correctness':None}
    audits=read(OUT/'context-factual-review.json')['outputs'];sentences=[s for o in audits for s in o['sentence_audits']]
    flag_names=['short_or_fragment_candidate','possible_unresolved_anaphor','possible_missing_negation',
                'possible_lost_uncertainty','possible_lost_attribution','possible_added_causal_language']
    warnings={f:sum(bool(s[f]) for s in sentences) for f in flag_names}
    warnings.update(sentences_with_missing_manual_neighbors=sum(bool(s['missing_manual_neighbor_ids']) for s in sentences),
        sentences_with_number_literal_warning=sum(bool(s['numbers_absent_as_literals_from_source']) for s in sentences),
        audited_unique_outputs=len(audits),audited_sentence_occurrences=len(sentences),
        nonverbatim_tentative_alignments=sum(s['alignment']['status']!='verbatim_span' for s in sentences),
        independent_verified_errors=None)
    sensitivity=read(OUT/'cross-depth-sensitivity.json');strata={}
    for method,analysis in sensitivity['analyses'].items():
        strata[method]={}
        for system,value in analysis['systems'].items():
            strata[method][system]={'per_category':value['per_article'],'per_legacy_depth':{
                depth:{'pairs':len(rows:=[p for p in value['pairs'] if p['legacy_depth']==depth]),
                    'legacy_words':sum(p['legacy']['words'] for p in rows),
                    'candidate_words':sum(p['candidate']['words'] for p in rows),
                    'candidate_depths':dict(Counter(p['candidate_depth'] for p in rows))} for depth in DEPTHS},
                'unmatched_original_legacy_cases':len(value['unmatched_legacy_cases']),
                'cross_depth_pairs':value['cross_depth_pair_count']}
    resources=[]
    for path in sorted(OUT.glob('process-profile-*.json')):
        r=read(path)
        item={'artifact':path.name,'complete':r['complete'],'mode':r['mode'],'error':r.get('error')}
        if r['complete']:
            observer=r['observer'];peak=r['contributions_at_largest_sampled_cgroup_current']
            item.update(cgroup_peak_mib=observer['memory_peak_bytes']/1048576,
                peak_headroom_mib=r['limit_mib']-observer['memory_peak_bytes']/1048576,
                largest_sampled_cgroup_mib=peak['memory_current_bytes']/1048576,
                sampled_process_groups={k:{f.replace('_bytes','_mib'):round(v/1048576,6) if f.endswith('_bytes') else v for f,v in group.items()}
                    for k,group in peak['groups'].items()},
                unreadable_or_exited_processes_at_largest_sample=peak['unreadable_or_exited_processes'],
                memory_events=observer['memory_events'],samples=len(observer['samples']),
                startup_seconds=r['startup_seconds'],
                php_allocated_heap_peak_mib=max(q['response'].get('php_allocated_heap_peak_bytes',0) for q in r['runs'])/1048576,
                php_used_heap_peak_mib=max(q['response'].get('php_used_heap_peak_bytes',0) for q in r['runs'])/1048576,
                requests=[{'label':q['label'],'http':q['http_status'],'seconds':q['wall_seconds'],
                    'engine_version':q['response'].get('engine_version')} for q in r['runs']],
                process_map_instrumentation=r.get('process_map_instrumentation','default container permissions'))
        resources.append(item)
    write('analysis-summary.json',{'status':'descriptive diagnostics, not independent accuracy',
        'new_generation_records':len(all_new),'new_generation_errors':sum(r['error'] is not None for r in all_new),
        'new_control_records':len(controls),'new_focus_records':len(all_new)-len(controls),
        'control_sets':control_sets,'native_repeat_checks':repeat_checks,'focus_behavior_by_kind':focus_tables,
        'warning_screens':warnings,'cross_depth_strata':strata,'process_profiles':resources,
        'human_review_status':'pending','freeze_verification':verify()})
    print('New generations',len(all_new),'errors',sum(r['error'] is not None for r in all_new),
          '; native exact repeats',sum(r['same_sentences_as_pilot_native'] for r in repeat_checks),'/',len(repeat_checks))
    print('Warning screens',warnings)
    print('Process profiles',[(r['artifact'],r.get('cgroup_peak_mib'),r.get('unreadable_or_exited_processes_at_largest_sample')) for r in resources])


if __name__=='__main__':build()
