"""Exhaustive source-addressed omission and Focus diagnostics, not human ratings."""
from hashlib import sha256
import json
from pathlib import Path
import re
import sys

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
from evaluation.independent_validation import OUT, verify, write
from evaluation.independent_comparison import inputs, SYSTEMS


def analyze():
    docs={d['id']:d for d in inputs()}; rows=[]
    for system in SYSTEMS:
        data=json.loads((OUT/('runs-'+system+'.json')).read_text(encoding='utf8'))
        assert data['complete'];rows.extend(data['rows'])
    failures=[];focus=[]
    for row in rows:
        doc=docs[row['article']];inventory={s['id']:s for s in doc['source_sentences']}
        for which,index in [('native',0),('selected',row['selected_trial'])]:
            output=row['trials'][index];diag=output['diagnostics']
            for unit in doc['draft_units']:
                if unit['id'] in diag['draft_unit_hits']: continue
                failures.append({'system':row['system'],'case_id':row['case_id'],'output':which,
                    'category':doc['category'],'depth':row['depth'],'kind':row['kind'],
                    'flag':'draft_main_omission' if unit['main'] else 'draft_unit_omission',
                    'unit_id':unit['id'],'unit_meaning':unit['meaning'],
                    'evidence_alternatives':[[inventory[i] for i in bundle] for bundle in unit['evidence_any']],
                    'summary_sentences':output['sentences'],'actual_words':output['words'],
                    'matched':row['matched']['0.1'],
                    'human_error_or_severity':None,
                    'interpretation':'Missing complete source evidence; partial paraphrase/semantic retention and importance need independent review.'})
            for flag in diag['draft_context_flags']:
                ids=flag['trigger_sentence_ids']+[i for bundle in flag['missing_alternative_context_ids'] for i in bundle]
                failures.append({'system':row['system'],'case_id':row['case_id'],'output':which,
                    'category':doc['category'],'depth':row['depth'],'kind':row['kind'],
                    'flag':'draft_context_omission','context_rule':flag,'source_evidence':[inventory[i] for i in dict.fromkeys(ids)],
                    'summary_sentences':output['sentences'],'human_error_or_severity':None})
            if diag['nonverbatim_output_indices']:
                failures.append({'system':row['system'],'case_id':row['case_id'],'output':which,
                    'flag':'nonverbatim_provenance_check','summary_sentences':output['sentences'],
                    'indices':diag['nonverbatim_output_indices'],'human_factual_error':None,
                    'interpretation':'A nonverbatim string is not automatically a false proposition; check source cleaning/sentence boundaries.'})
        if row['kind']!='general':
            output=row['trials'][0]
            probe=next(p for p in doc['focus_probes'] if p['query']==row['focus_query'])
            present=output['diagnostics']['source_sentence_ids']
            status=output.get('focus_status')
            focus.append({'system':row['system'],'case_id':row['case_id'],'query':row['focus_query'],
                'expected_support_agent_draft':row['kind'],'support_rationale':probe['source_only_rationale'],
                'reported_focus_status':status,'returned_excerpt_count':len(output['sentences']),
                'returned_information':output['plain_summary'],'source_evidence':[inventory[i] for i in present],
                'draft_focus_units_retained':output['diagnostics']['draft_focus_unit_hits'],
                'draft_focus_unit_denominator':len(probe['unit_ids']),
                'candidate_false_rejection_flag':row['kind']=='supported' and status=='unsupported',
                'candidate_near_topic_false_acceptance_flag':row['kind']=='unsupported' and bool(output['sentences']),
                'partial_unavailable_scope_explicitly_reported':None,
                'independent_support_relevance_and_severity':None,'execution_error':output['error'],
                'note':'Native full output. Guide LSA is query-blind; other absent statuses are not invented.'})
    write('failure-traces.json',{'status':'source-addressed diagnostic flags; independent adjudication pending',
          'failures':failures,'unit_or_context_flags_are_not_verified_errors':True})
    write('focus-analysis.json',{'status':'agent support labels, pending independent review','probes':focus,
          'per_system':{s:{'probes':len([r for r in focus if r['system']==s]),
             'supported_rejection_flags':sum(r['candidate_false_rejection_flag'] for r in focus if r['system']==s),
             'unsupported_nonempty_answer_flags':sum(r['candidate_near_topic_false_acceptance_flag'] for r in focus if r['system']==s),
             'supported_draft_unit_hits':sum(len(r['draft_focus_units_retained']) for r in focus if r['system']==s and r['expected_support_agent_draft']=='supported'),
             'supported_draft_unit_denominator':sum(r['draft_focus_unit_denominator'] for r in focus if r['system']==s and r['expected_support_agent_draft']=='supported')}
            for s in SYSTEMS}})
    print('Wrote',len(failures),'trace flags and',len(focus),'Focus output records')


def overlap_audit():
    """Check new prose against all old source-bearing JSONs, not only exact URLs."""
    prior=[]
    def walk(value,path):
        if isinstance(value,dict):
            if isinstance(value.get('source_text'),str):prior.append((value.get('id','unnamed'),path,value['source_text']))
            for v in value.values():walk(v,path)
        elif isinstance(value,list):
            for v in value:walk(v,path)
    for folder in [ROOT/'evaluation/corpus',ROOT/'tests/fixtures',OUT.parent]:
        for path in folder.glob('*.json'):
            if path.stat().st_size>50_000_000: continue
            try:walk(json.loads(path.read_text(encoding='utf8')),str(path.relative_to(ROOT.parent)))
            except (ValueError,UnicodeDecodeError):pass
    def shingles(text):
        tokens=re.findall(r'\w+',text.casefold())
        return {tuple(tokens[i:i+5]) for i in range(max(0,len(tokens)-4))}
    old={sha256(text.encode()).hexdigest():(identity,path,shingles(text)) for identity,path,text in prior}
    result=[]
    for d in inputs():
        new=shingles(d['source_text']);best=None
        for identity,path,other in old.values():
            similarity=len(new&other)/max(1,len(new|other))
            if best is None or similarity>best['jaccard_5gram']:
                best={'prior_id':identity,'prior_path':path,'jaccard_5gram':similarity}
        result.append({'id':d['id'],'nearest_prior':best,'exact_source_duplicate':sha256(d['source_text'].encode()).hexdigest() in old})
    write('source-overlap-audit.json',{'distinct_prior_texts':len(old),'new_sources':result,
          'interpretation':'Lexical overlap screen supplements identity audit; not a semantic/event-independence proof.'})
    assert not any(r['exact_source_duplicate'] or r['nearest_prior']['jaccard_5gram']>.3 for r in result)


def attainability():
    # Full-evidence retention is a stricter condition than preserving meaning.
    # Expose impossible bundles rather than interpreting their misses as errors.
    from evaluation.independent_comparison import DEPTHS, WORD
    ratios=[.10,.18,.26,.38,.55,.75];rows=[]
    for d in inputs():
        inventory={s['id']:s for s in d['source_sentences']}
        for (depth,(count,cap)),ratio in zip(DEPTHS.items(),ratios):
            count=min(count,2 if depth=='very_brief' else count)
            budget=min(cap,max(45,round(d['word_count']*ratio)))
            for unit in d['draft_units']:
                alternatives=[{'sentences':len(bundle),'words':sum(len(WORD.findall(inventory[i]['text'])) for i in bundle)}
                              for bundle in unit['evidence_any']]
                rows.append({'id':d['id'],'depth':depth,'unit':unit['id'],'article_sentence_limit':count,
                    'native_word_ceiling':budget,'exact_evidence_alternatives':alternatives,
                    'some_exact_bundle_fits_without_added_context':any(a['sentences']<=count and a['words']<=budget for a in alternatives)})
    write('draft-unit-attainability.json',{'status':'exact-bundle feasibility only, not semantic attainability',
        'note':'Meaning can survive without a whole annotated sentence, especially in legacy compression. These strict-copy diagnostics favor exact extractors and cannot establish semantic recall/accuracy.',
        'infeasible_exact_bundle_cases':sum(not r['some_exact_bundle_fits_without_added_context'] for r in rows),'rows':rows})


if __name__=='__main__':
    verify();overlap_audit();attainability();analyze()
