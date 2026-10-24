"""Validate actual reviewer submissions; blank templates never become scores.

No synthetic ratings are supplied by this command. Unit-test fixtures are not
research observations. Unpaired ratings and disagreements are retained.
"""
from collections import Counter,defaultdict
import csv
from itertools import combinations
from pathlib import Path
import sys

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]
from evaluation.final_validation import OUT,read,write
from evaluation.final_review_package import DIMENSIONS


def agreement(a,b,labels,quadratic=False):
    if len(a)!=len(b):raise ValueError('Unpaired sequences')
    if any(x not in labels for x in a+b):raise ValueError('Unknown category')
    n=len(a)
    if n<2:return {'paired_items':n,'exact_agreement':None,'kappa':None,'reason':'insufficient paired ratings'}
    ranks={v:i for i,v in enumerate(labels)};ca=Counter(a);cb=Counter(b)
    def distance(x,y):return (ranks[x]-ranks[y])**2 if quadratic else int(x!=y)
    observed=sum(distance(x,y) for x,y in zip(a,b))/n
    expected=sum(ca[x]*cb[y]*distance(x,y) for x in labels for y in labels)/(n*n)
    return {'paired_items':n,'exact_agreement':sum(x==y for x,y in zip(a,b))/n,
            'kappa':1-observed/expected if expected else None,
            'reason':None if expected else 'undefined: zero expected disagreement',
            'weighting':'quadratic' if quadratic else 'unweighted'}


def load_actual(path,registry,fields,key_fields,allowed,known_items=None):
    result=defaultdict(dict);seen=set();submissions=0
    with path.open(encoding='utf8',newline='') as handle:
        for row in csv.DictReader(handle):
            values={f:row.get(f,'').strip() for f in fields}
            rated={f:v for f,v in values.items() if v and v.upper()!='N/A'}
            reviewer=row.get('reviewer_id','').strip()
            if not rated:continue
            if reviewer not in registry:raise ValueError('Unregistered reviewer: '+reviewer)
            person=registry[reviewer]
            if not (person.get('qualified') is True and person.get('independent') is True and person.get('qualifications')):
                raise ValueError('Missing qualification/independence declarations for '+reviewer)
            if not row.get('date') or not row.get('rationale'):
                raise ValueError('Dated evidence rationale required for actual submissions')
            key=tuple(row[f] for f in key_fields)
            if any(not k for k in key):raise ValueError('Missing rating item identity')
            if known_items is not None and key not in known_items:raise ValueError('Unknown rating item')
            for field,value in rated.items():
                if value not in allowed[field]:raise ValueError('Out-of-scale value '+field+': '+value)
                identity=(key,field,reviewer)
                if identity in seen:raise ValueError('Duplicate reviewer/item/dimension')
                seen.add(identity);result[key,field][reviewer]=value;submissions+=1
    return result,submissions


def paired_report(data,labels,weighted=False):
    grouped=defaultdict(list);disagreements=[]
    for (item,dimension),ratings in data.items():
        for a,b in combinations(sorted(ratings),2):
            grouped[dimension,a,b].append((item,ratings[a],ratings[b]))
            if ratings[a]!=ratings[b]:
                disagreements.append({'item':item,'dimension':dimension,'reviewer_a':a,'reviewer_b':b,
                                      'rating_a':ratings[a],'rating_b':ratings[b],'adjudication':None})
    reports=[]
    for (dimension,a,b),items in sorted(grouped.items()):
        left=[i[1] for i in items];right=[i[2] for i in items]
        report={'dimension':dimension,'reviewer_a':a,'reviewer_b':b,
                'unweighted':agreement(left,right,labels[dimension])}
        if weighted:report['quadratic_weighted']=agreement(left,right,labels[dimension],True)
        reports.append(report)
    return reports,disagreements


def run():
    people=read(OUT/'reviewer-registry.json')['reviewers']
    registry={p['reviewer_id']:p for p in people}
    if len(registry)!=len(people):raise ValueError('Duplicate reviewer enrollment')
    scale=[str(i) for i in range(5)]
    known_outputs={(r['review_token'],) for r in read(OUT/'masked-output-packet.json')['outputs']}
    known_sentences={(r['review_token'],str(r['summary_sentence_index'])) for r in read(OUT/'masked-focus-sentence-packet.json')['rows']}
    data,count=load_actual(OUT/'human-output-ratings.csv',registry,DIMENSIONS,['review_token'],
                           {f:scale for f in DIMENSIONS},known_outputs)
    reports,disagreements=paired_report(data,{f:scale for f in DIMENSIONS},True)
    sentence_fields=['primary_role_D_C_U','requires_preceding','requires_following','misleading_isolation']
    labels={f:(['D','C','U'] if f=='primary_role_D_C_U' else ['yes','no']) for f in sentence_fields}
    sentence_data,sentence_count=load_actual(OUT/'human-focus-sentence-ratings.csv',registry,sentence_fields,
        ['review_token','summary_sentence_index'],labels,known_sentences)
    sentence_reports,sentence_disagreements=paired_report(sentence_data,labels)
    write('agreement-results.json',{'status':'pending' if not count and not sentence_count else 'actual validated submissions only',
        'enrolled_reviewers':len(people),'submitted_output_dimension_ratings':count,
        'submitted_sentence_dimension_ratings':sentence_count,'output_agreement':reports or None,
        'sentence_agreement':sentence_reports or None,'disagreements':disagreements+sentence_disagreements,
        'independent_source_unit_adjudication':'pending; not inferred from output agreement',
        'interpretation':'Agreement is not accuracy. No inference from blank/unpaired rows; few pairs may be unstable.'})
    print('Actual rating cells:',count+sentence_count,'; independent review', 'pending' if not count+sentence_count else 'partially supplied')


if __name__=='__main__':run()
