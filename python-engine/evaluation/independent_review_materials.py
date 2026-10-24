"""Prepare blank human-review material; never fabricates an assessment."""
import csv
from hashlib import sha256
import json
from pathlib import Path
import random
import sys

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
from evaluation.independent_validation import OUT, verify, write
from evaluation.independent_comparison import inputs, SYSTEMS


def prepare():
    verify(); docs=inputs()
    sources=[]
    for d in docs:
        sources.append({k:d[k] for k in ['id','title','authors','publication_dates','publisher','url',
            'license','license_url','source_text','source_sentences']})
        sources[-1]['query_support_to_review']=[{'query':p['query'],'support':None,'rationale':None}
                                               for p in d['focus_probes']]
        sources[-1]['reviews']=[{'reviewer':None,'independence_declaration':None,'central_claim':None,
            'must_preserve_units':None,'context_dependencies':None,'source_inconsistencies':None,
            'date':None,'duration_minutes':None} for _ in range(2)]
    write('human-source-packet.json',{'status':'pending','sources':sources})
    if not all((OUT/('runs-'+s+'.json')).exists() for s in SYSTEMS):
        print('Source-only packet prepared; outputs not yet complete'); return
    rng=random.Random(211022); packet=[]; key={}
    for system in SYSTEMS:
        data=json.loads((OUT/('runs-'+system+'.json')).read_text(encoding='utf8'))
        assert data['complete']
        for row in data['rows']:
            token=sha256((system+'|'+row['case_id']+'|iv21').encode()).hexdigest()[:16]
            output=row['trials'][row['selected_trial']]
            packet.append({'review_token':token,'source_id':row['article'],'depth':row['depth'],
                           'query':row['focus_query'],'summary_sentences':output['sentences'],
                           'informational_text':output['plain_summary'] if not output['sentences'] else None,
                           'actual_words':output['words'],'generation_failed':output['error'] is not None,
                           'length_comparable':row['matched']['0.1']})
            key[token]={'system':system,'case_id':row['case_id'],'selected_trial':row['selected_trial']}
    rng.shuffle(packet)
    write('human-output-packet.json',{'status':'unrated_pending_independent_review','outputs':packet})
    write('blinding-key.json',{'instruction':'Keep separate from reviewers until judgments are locked.','key':key})
    fields=['review_token','reviewer_id','main_idea','important_information','factual_consistency','attribution_context',
            'coherence','readability','nonredundancy','focus_relevance','critical_error','source_spans',
            'summary_spans','explanation','duration_minutes','date']
    with (OUT/'human-review-blank.csv').open('w',encoding='utf8',newline='') as handle:
        writer=csv.DictWriter(handle,fieldnames=fields);writer.writeheader()
        for row in packet:
            for _ in range(2): writer.writerow({'review_token':row['review_token']})
    print('Prepared',len(packet),'masked outputs;',2*len(packet),'blank reviewer rows; no ratings')


if __name__=='__main__':prepare()
