"""Exhaustive review rows, source-grounded draft flags and genuinely blank ratings.

Draft source locations are evaluation aids, never production rules or human gold.
Similarity is used only for explicitly tentative source alignment, not relevance.
"""
from collections import Counter
import csv
from difflib import SequenceMatcher
from functools import lru_cache
from hashlib import sha256
import json
from pathlib import Path
import random
import re
import sys

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
from evaluation.final_validation import OUT,PILOT,SYSTEMS,corpus,runs,read,write,verify
from evaluation.independent_comparison import norm,WORD

DIMENSIONS=['main_idea','information_coverage','factual_consistency','attribution','context_preservation',
            'coherence','readability','nonredundancy','focus_relevance']
NEGATION=re.compile(r'\b(?:no|not|never|neither|cannot|without|failed)\b',re.I)
UNCERTAINTY=re.compile(r'\b(?:may|might|could|unclear|uncertain|suggest\w*|likely|possibly)\b',re.I)
ATTRIBUTION=re.compile(r'\b(?:said|according to|argue\w*|reported by|the authors|the researchers)\b',re.I)
CAUSAL=re.compile(r'\b(?:caus\w*|because|led to|resulted in|due to)\b',re.I)
ANAPHOR=re.compile(r'^(?:it|they|he|she|this|these|those|such|its|their|his|her|however|therefore|thus)\b',re.I)
NUMBERS=re.compile(r'(?<!\w)[-\u2212]?\d+(?:[,\u2009\u202f]\d{3})*(?:\.\d+)?(?:\s*%)?')


def span(*parts):
    result=set()
    for p in parts:
        if isinstance(p,tuple):result.update(range(p[0],p[1]+1))
        else:result.add(p)
    return result


# These point to propositions for reviewer inspection, not final correctness.
SOURCE_FACETS={
 'IV-NEWS':{'direct':span(5),'context':span(3,4,20)},
 'IV-SCIENCE':{'direct':span(0,9,10),'context':span((3,8),11,12)},
 'IV-BUSINESS':{'direct':span(0,1,2,(11,16)),'context':span(9,10,17,18)},
 'IV-EDUCATION':{'direct':span(1,2,15,16,28,(33,37),41,(45,49),(56,58),(101,124)),
                 'context':span((21,27),(29,32),(38,44),(50,55),(70,100))},
 'IV-TECHNICAL':{'direct':span(120,121,123,124,(126,133),(136,147),149,151,152,154,155,(157,161),163,164,166,168,(171,178)),
                'context':span(117,118,119,122,125,134,135,148,150,153,156,162,165,167,169,170,(90,116))},
 'IV-EDITORIAL':{'direct':span((1,4),(7,9),(11,13),(17,19),21,24,(28,30),39,40,(43,46),(49,57)),
                'context':span(0,10,(14,16),20,22,23,(25,27),(31,38),41,42,47,48)},
 'IV-FEATURE':{'direct':span(4,6,(11,25)),'context':span((1,3),5,7,10,(26,39))},
 'IV-RESEARCH':{'direct':span(11,15,(140,146),148,162,163,169,170,193,219,225,226),
                'context':span((2,10),(12,14),(134,139),(149,161),(171,192),(194,218),(220,224))},
}
NEIGHBOR_DEPENDENCIES={
 'IV-EDITORIAL':{15:[16],16:[15],26:[27],27:[26],31:[32],32:[31],42:[43],43:[42],53:[54,55],54:[53],55:[53]},
 'IV-RESEARCH':{167:[168],168:[167],180:[181],181:[180],186:[187],187:[186],220:[221],221:[220]},
 'IV-TECHNICAL':{82:[83],83:[82],88:[89],89:[88],101:[102],102:[101],105:[104],109:[104],112:[105],113:[109],144:[145],145:[144]},
}


def new_rows():
    result=[]
    for system in SYSTEMS:
        data=read(OUT/('new-runs-'+system+'.json'));assert data['complete']
        result.extend(data['rows'])
    return result


def output_records():
    """All original native/selected cases, new Focus, and length-sensitive pairs."""
    records=[]
    for system,rows in runs().items():
        for r in rows:
            for view,i in [('native',0),('registered_selected',r['selected_trial'])]:
                t=r['trials'][i]
                records.append({'origin':system+':'+r['case_id']+':'+view,'system':system,'article':r['article'],
                    'depth':r['depth'],'query':r['focus_query'],'kind':'near_unsupported' if r['kind']=='unsupported' else r['kind'],
                    'sentences':t['sentences'],'plain_summary':t['plain_summary'],'error':t['error'],
                    'focus_status':t.get('focus_status'),'role':'pilot_'+view,'trial_index':i})
    for r in new_rows():
        if not r['kind'].startswith('focus_'):continue
        records.append({'origin':r['record_id'],'system':r['system'],'article':r['article'],'depth':r['depth'],
            'query':r['query'],'kind':r['kind'].removeprefix('focus_'),'sentences':r['sentences'],
            'plain_summary':r['plain_summary'],'error':r['error'],
            'focus_status':r.get('source_metadata',{}).get('article_analysis',{}).get('focus_status'),'role':'new_native'})
    sensitivity=read(OUT/'cross-depth-sensitivity.json')
    for method,analysis in sensitivity['analyses'].items():
        for system,data in analysis['systems'].items():
            for index,pair in enumerate(data['pairs']):
                for side in ['legacy','candidate']:
                    o=pair[side];source=o['origins'][0]
                    records.append({'origin':'cross:'+method+':'+system+':'+str(index)+':'+side,
                        'system':'legacy' if side=='legacy' else system,'article':pair['article'],'depth':source['depth'],
                        'query':'','kind':'general','sentences':o['sentences'],'plain_summary':' '.join(o['sentences']),
                        'error':None,'focus_status':None,'role':'cross_depth_sensitivity'})
    return records


def role_for(ids,kind,fragment):
    if kind in {'near_unsupported','far_unsupported'}:
        return 'unrelated_to_requested_answer_under_agent_support_draft'
    if kind=='ambiguous':return 'unresolved_ambiguous_scope'
    if fragment:return 'unresolved_fragment_or_incomplete_proposition'
    if not ids:return 'unresolved_nonverbatim_or_partial_alignment'
    return None


def build():
    verify()
    # Regeneration must never discard subsequently supplied human work.
    if (OUT/'reviewer-registry.json').exists():
        existing=read(OUT/'reviewer-registry.json')
        if existing.get('reviewers') or existing.get('adjudicator'):
            raise RuntimeError('Human enrollment exists; preserve this package and create a versioned successor')
    for path in OUT.glob('human-*-ratings.csv'):
        with path.open(encoding='utf8',newline='') as handle:
            if any(row.get('reviewer_id') for row in csv.DictReader(handle)):
                raise RuntimeError('Human submissions exist; refusing to overwrite '+path.name)
    docs={d['id']:d for d in corpus()};records=output_records();registry={};key={};sentence_rows=[]
    source_cache={d['id']:[norm(s['text']) for s in d['source_sentences']] for d in docs.values()}
    @lru_cache(maxsize=None)
    def align(identity,sentence):
        d=docs[identity];text=norm(sentence)
        pattern=r'\s+'.join(re.escape(w) for w in sentence.split())
        found=re.search(pattern,d['source_text']) if pattern else None
        exact_ids=[i for i,s in enumerate(source_cache[identity]) if s==text]
        if found:
            overlaps=[i for i,s in enumerate(d['source_sentences']) if s['start']<found.end() and s['end']>found.start()]
            return {'status':'verbatim_span','start':found.start(),'end':found.end(),'source_indices':overlaps,
                    'complete_source_indices':exact_ids,'tentative_similarity':None}
        query_tokens=set(WORD.findall(text.casefold()))
        candidates=sorted(range(len(source_cache[identity])),key=lambda i:(
            -len(query_tokens&set(WORD.findall(source_cache[identity][i].casefold()))),i))[:6]
        best=max(candidates,key=lambda i:SequenceMatcher(None,text.casefold(),source_cache[identity][i].casefold(),autojunk=False).ratio()) if candidates else None
        score=SequenceMatcher(None,text.casefold(),source_cache[identity][best].casefold(),autojunk=False).ratio() if best is not None else 0
        return {'status':'tentative_lexical_alignment_not_entailment','start':None,'end':None,
                'source_indices':[best] if best is not None and score>=.45 else [],'complete_source_indices':[],
                'tentative_similarity':score}
    context_audit=[]
    for record in records:
        identifier=sha256(json.dumps([record['article'],record['query'],record['sentences'],record['plain_summary']],ensure_ascii=False).encode()).hexdigest()[:20]
        if identifier not in registry:
            registry[identifier]={'review_token':identifier,'source_id':record['article'],'query':record['query'],
                'actual_words':len(WORD.findall(' '.join(record['sentences']))),'summary_sentences':record['sentences'],
                'information_message':record['plain_summary'] if not record['sentences'] else None,
                'generation_failed':record['error'] is not None}
            key[identifier]=[]
        key[identifier].append({k:record[k] for k in ['origin','system','depth','kind','role']})
    # No engine identities or agent judgments are included in masked output packet.
    packet=list(registry.values());random.Random(20261023).shuffle(packet)
    write('masked-output-packet.json',{'human_rating_status':'pending','outputs':packet})
    write('blinding-key.json',{'keep_separate_until_ratings_locked':True,'key':key})
    write('source-only-packet.json',{'status':'independent_source_units_pending','sources':[
        {k:d[k] for k in ['id','title','authors','publisher','url','license','license_url','source_text','source_sentences']} for d in docs.values()]})
    # Inspect each unique output once for provenance/possible context issues.
    all_flags=[]
    for item in packet:
        identity=item['source_id'];doc=docs[identity]
        present={i for sentence in item['summary_sentences'] for i in align(identity,sentence)['complete_source_indices']}
        source_numbers={n.replace('\u2212','-').replace(',','').replace(' ','') for n in NUMBERS.findall(doc['source_text'])}
        flags=[]
        for index,sentence in enumerate(item['summary_sentences']):
            a=align(identity,sentence);indices=a['source_indices'];neighbors=[]
            for source_index in indices:
                neighbors.extend(doc['source_sentences'][max(0,source_index-2):source_index+3])
            neighbors=list({s['id']:s for s in neighbors}.values())
            fragment=bool(len(WORD.findall(sentence))<5 or re.search(r'\bet(?:\s+al)?\.$',sentence,re.I)
                          or re.match(r'^(?:tracemalloc\)|PyPI\)|al[,\.]|According to .*\]\.)',norm(sentence)))
            missing_neighbors=sorted({j for i in indices for j in NEIGHBOR_DEPENDENCIES.get(identity,{}).get(i,[]) if j not in present})
            preceding=bool(ANAPHOR.search(sentence) and any(i>0 and i-1 not in present for i in indices))
            draft={'review_token':item['review_token'],'summary_sentence_index':index,'sentence':sentence,
                'alignment':a,'source_neighbors':neighbors,'short_or_fragment_candidate':fragment,
                'possible_unresolved_anaphor':preceding,'missing_manual_neighbor_ids':[f's{i:04d}' for i in missing_neighbors],
                'possible_missing_negation':False,'possible_lost_uncertainty':False,'possible_lost_attribution':False,
                'possible_added_causal_language':False,'numbers_absent_as_literals_from_source':[],
                'independently_verified_factual_error':None,'independent_severity':None}
            if a['status']!='verbatim_span' and indices:
                candidate=doc['source_sentences'][indices[0]]['text']
                draft.update(possible_missing_negation=bool(NEGATION.search(candidate) and not NEGATION.search(sentence)),
                    possible_lost_uncertainty=bool(UNCERTAINTY.search(candidate) and not UNCERTAINTY.search(sentence)),
                    possible_lost_attribution=bool(ATTRIBUTION.search(candidate) and not ATTRIBUTION.search(sentence)),
                    possible_added_causal_language=bool(CAUSAL.search(sentence) and not CAUSAL.search(candidate)))
            draft['numbers_absent_as_literals_from_source']=[n for n in NUMBERS.findall(sentence)
                if n.replace('\u2212','-').replace(',','').replace(' ','') not in source_numbers]
            flags.append(draft)
        context_rules=[]
        for rule in doc['draft_context_rules']:
            triggers=[i for i in rule['trigger_any'] if i in present]
            if triggers and not any(set(bundle)<=present for bundle in rule['required_any']):
                context_rules.append({'rule':rule['id'],'trigger_ids':[f's{i:04d}' for i in triggers],
                    'missing_context_alternatives':[[f's{i:04d}' for i in b] for b in rule['required_any']],
                    'human_severity':None})
        late=[u['id'] for u in doc['draft_units'] if ('late' in u['id'] or u['id']=='imputation')
              and not any({int(i[1:]) for i in b}<=present for b in u['evidence_any'])]
        context_audit.append({'review_token':item['review_token'],'article':identity,'sentence_audits':flags,
            'draft_context_rules':context_rules,'draft_missing_late_or_limitation_bundles':late,
            'known_source_inconsistencies':doc.get('source_inconsistencies',[]),'human_conclusion':None})
        all_flags.extend(flags)
    lookup={(f['review_token'],f['summary_sentence_index']):f for f in all_flags}
    # Include original registered-selected views as well as native views. Even
    # duplicate bodies keep occurrence rows; masked rating items are deduplicated.
    native_focus=[r for r in records if r['query'] and r['role'] in {'pilot_native','pilot_registered_selected','new_native'}]
    focus_outputs=[]
    new_probes=read(OUT/'focus-probes.json')['probes']
    for r in native_focus:
        doc=docs[r['article']]
        expected=next((p['expected'] for p in new_probes if p['article']==r['article'] and p['query']==r['query']),None)
        if r['role'].startswith('pilot_'):expected='unsupported' if r['kind']=='near_unsupported' else r['kind']
        token=sha256(json.dumps([r['article'],r['query'],r['sentences'],r['plain_summary']],ensure_ascii=False).encode()).hexdigest()[:20]
        focus_outputs.append({'origin':r['origin'],'view_role':r['role'],'review_token':token,'system':r['system'],'article':r['article'],'kind':r['kind'],
            'query':r['query'],'expected_support_agent_draft':expected,'focus_status':r['focus_status'],
            'returned_sentences':len(r['sentences']),'error':r['error'],
            'false_acceptance_flag':bool(expected=='unsupported' and r['sentences'] and not r['error']),
            'false_rejection_flag':bool(expected=='supported' and r['focus_status']=='unsupported' and not r['error']),
            'independent_correctness':None})
        for index,sentence in enumerate(r['sentences']):
            audit=lookup[token,index];ids=audit['alignment']['complete_source_indices']
            role=role_for(ids,r['kind'],audit['short_or_fragment_candidate'])
            if role is None:
                facets=SOURCE_FACETS[r['article']]
                role=('direct_answer_evidence_candidate' if set(ids)&facets['direct'] else
                      'supporting_context_candidate_necessity_unreviewed' if set(ids)&facets['context'] else
                      'unresolved_requires_semantic_review')
            dependencies=audit['missing_manual_neighbor_ids']
            preceding=[i for i in dependencies if int(i[1:])<max(audit['alignment']['source_indices'],default=0)]
            following=[i for i in dependencies if int(i[1:])>min(audit['alignment']['source_indices'],default=10000)]
            sentence_rows.append({'occurrence_id':r['origin']+':sentence:'+str(index),'review_token':token,
                'summary_sentence_index':index,'source_id':r['article'],'query':r['query'],'query_kind':r['kind'],
                'selected_sentence':sentence,'full_summary_sentences':r['sentences'],
                'source_alignment':audit['alignment'],'source_neighbors':audit['source_neighbors'],
                'agent_draft_role':role,'agent_possible_preceding_dependency':bool(preceding or audit['possible_unresolved_anaphor']),
                'agent_possible_following_dependency':bool(following),
                'agent_possible_misleading_isolation':bool(dependencies or audit['possible_unresolved_anaphor'] or audit['short_or_fragment_candidate']),
                'draft_rationale':'Source-proposition map / explicit absent-topic draft / structural dependency flags; not semantic similarity or independent judgment.',
                'human_primary_role':None,'human_requires_preceding':None,'human_requires_following':None,
                'human_misleading_when_isolated':None,'human_rationale':None})
    assert len(sentence_rows)==sum(len(r['sentences']) for r in native_focus)
    write('focus-output-audit.json',{'status':'agent-draft behavior flags; independent support/correctness pending','outputs':focus_outputs})
    write('focus-sentence-audit.json',{'status':'exhaustive occurrences; draft roles and structural flags, no human scores',
          'total_native_focus_outputs':sum(r['role']!='pilot_registered_selected' for r in native_focus),
          'total_focus_output_views':len(native_focus),'total_selected_sentence_occurrences':len(sentence_rows),'rows':sentence_rows})
    write('context-factual-review.json',{'status':'provenance and tentative warning screens, not verified accuracy',
        'alignment_similarity_used_only_for_source_candidates':True,'outputs':context_audit,
        'independent_factuality_result':None})
    masked_sentence_rows=[];seen=set()
    for r in sentence_rows:
        identity=(r['review_token'],r['summary_sentence_index'])
        if identity in seen:continue
        seen.add(identity)
        masked_sentence_rows.append({k:r[k] for k in ['review_token','summary_sentence_index','source_id','query','selected_sentence','full_summary_sentences']})
    random.Random(23).shuffle(masked_sentence_rows)
    write('masked-focus-sentence-packet.json',{'status':'unrated','rows':masked_sentence_rows})
    with (OUT/'human-output-ratings.csv').open('w',encoding='utf8',newline='') as handle:
        fields=['review_token','reviewer_id']+DIMENSIONS+['critical_error','evidence_source_spans','evidence_summary_spans','rationale','date','duration_minutes']
        writer=csv.DictWriter(handle,fieldnames=fields);writer.writeheader()
        for item in packet:
            for _ in range(2):writer.writerow({'review_token':item['review_token']})
    with (OUT/'human-focus-sentence-ratings.csv').open('w',encoding='utf8',newline='') as handle:
        fields=['review_token','summary_sentence_index','reviewer_id','primary_role_D_C_U','requires_preceding','requires_following','misleading_isolation','source_spans','rationale','date']
        writer=csv.DictWriter(handle,fieldnames=fields);writer.writeheader()
        for item in masked_sentence_rows:
            for _ in range(2):writer.writerow({'review_token':item['review_token'],'summary_sentence_index':item['summary_sentence_index']})
    with (OUT/'human-source-unit-ratings.csv').open('w',encoding='utf8',newline='') as handle:
        writer=csv.DictWriter(handle,fieldnames=['source_id','reviewer_id','unit_id','central_claim','proposition','importance','source_sentence_ids','source_start_end','required_attribution','negation_uncertainty_scope','context_dependencies','rationale','date'])
        writer.writeheader()
        for d in docs:
            for _ in range(2):writer.writerow({'source_id':d})
    write('reviewer-registry.json',{'status':'no qualified independent reviewers enrolled','reviewers':[],'adjudicator':None})
    queries=sorted({(r['article'],r['query']) for r in native_focus})
    with (OUT/'human-query-support-ratings.csv').open('w',encoding='utf8',newline='') as handle:
        writer=csv.DictWriter(handle,fieldnames=['source_id','query','reviewer_id','support_full_partial_absent_ambiguous',
            'supported_propositions','unsupported_components','source_spans','rationale','date'])
        writer.writeheader()
        for identity,query in queries:
            for _ in range(2):writer.writerow({'source_id':identity,'query':query})
    with (OUT/'adjudication-blank.csv').open('w',encoding='utf8',newline='') as handle:
        csv.writer(handle).writerow(['item_id','dimension','reviewer_a','reviewer_b','original_a','original_b',
            'adjudicator_id','decision','source_spans','rationale','date'])
    write('review-package-summary.json',{'unique_masked_outputs':len(packet),
        'native_focus_outputs':sum(r['role']!='pilot_registered_selected' for r in native_focus),
        'focus_output_views_including_registered_selected':len(native_focus),
        'focus_sentence_occurrences':len(sentence_rows),'unique_focus_sentence_rating_items':len(masked_sentence_rows),
        'human_output_rating_rows':2*len(packet),'human_sentence_rating_rows':2*len(masked_sentence_rows),
        'human_review_completed':False,'independently_identified_source_units':None,
        'draft_roles':dict(Counter(r['agent_draft_role'] for r in sentence_rows)),
        'note':'Unresolved draft roles are explicit; no complete independent semantic determination has been fabricated.'})
    print('Review package',len(packet),'unique outputs;',len(sentence_rows),'Focus sentence occurrences; human review pending')


if __name__=='__main__':build()
