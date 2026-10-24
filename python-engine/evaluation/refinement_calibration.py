"""Development-only core calibration; no held-out annotations enter this search."""
from hashlib import sha256
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path = [p for p in sys.path if p != str(Path(__file__).resolve().parent)]
from evaluation.coverage_corpus import development, units
from evaluation.coverage_experiment import metrics
from article_engine.coverage import analyze, article_sentences, TOKEN
from article_engine.selection import select

DEPTHS = {'very_brief':(.10,2,80), 'brief':(.18,3,150), 'short':(.26,5,280),
          'balanced':(.38,8,500), 'detailed':(.55,11,850), 'comprehensive':(.75,15,1600)}


def calibration_documents():
    docs = development()
    extras = [
        ('DEV-NARRATIVE-CORE', 'A repair policy reduces repeated tunnel closures', [
            'The first morning train entered the hillside tunnel before sunrise.',
            'Drivers had become accustomed to seeing maintenance vehicles beside the track.',
            'A maintenance team compared inspection records from seven districts over two years.',
            'The main finding was that preventive tunnel repairs reduced repeated closures without increasing the annual maintenance budget.',
            'Scheduled inspections found 34 damaged joints before emergency repairs became necessary.',
            'However, the comparison was observational and cannot establish how every repair policy would perform.',
            'The team recommended testing preventive tunnel repairs in additional districts.'],
         units(('main','main finding','preventive tunnel repairs'), ('finding','34 damaged joints'),
               ('limit','observational','cannot establish'))),
        ('DEV-OPPOSITION-CORE', 'The case for retaining free neighborhood workshops', [
            'Opponents say that neighborhood workshops should charge every participant an entry fee.',
            'Their argument begins with the observation that teaching materials cost money.',
            'The budget report lists printing, insurance, room hire, and staff supervision separately.',
            'I argue that neighborhood workshops should remain free because entry fees would exclude residents who most need practical training.',
            'A voluntary materials fund could pay part of the cost without restricting attendance.',
            'The evidence does not yet show whether donations would cover every workshop expense.',
            'Councils should consult participants before introducing any permanent fee.'],
         units(('main','I argue','remain free'), ('proposal','voluntary materials fund'),
               ('uncertainty','does not yet show','donations'))),
        ('DEV-METHOD-FIRST', 'A river-monitoring pilot improves early warning', [
            'Field teams placed sensors at twelve river stations during the first week of the pilot.',
            'Each station recorded water levels every fifteen minutes and sent a daily equipment report.',
            'The purpose of the river-monitoring pilot was to improve early warning before communities experienced dangerous flooding.',
            'The pilot found that river warnings arrived 24 minutes earlier than warnings from the previous reporting system.',
            'The researchers concluded that automatic river monitoring improved warning speed but did not establish a reduction in flood damage.',
            'A larger study is needed because the pilot covered only one rainy season.'],
         units(('main','concluded','improved warning speed','did not establish'),
               ('result','24 minutes earlier'), ('scope','one rainy season'))),
    ]
    for identifier,title,sentences,annotations in extras:
        docs.append({'id':identifier,'source_text':title+'\n\n'+' '.join(sentences),
                     'key_units':annotations,'critical_facts':[], 'profile':'general',
                     'provenance':'engineering_authored_adversarial_development_not_independent_review'})
    return docs


def tune():
    profiles = [(True,.4,.3,.2,.1),(True,.2,.4,.2,.2),(True,.6,.2,.1,.1),
                (True,0,.6,.2,.2),(True,1,0,0,0),(False,0,0,0,0)]
    docs = calibration_documents()
    cached = []
    for doc in docs:
        records,_ = article_sentences(doc['source_text'])
        cached.append((doc,records,analyze(records,enhanced=False)))
    trials = []
    for enabled,lead,title,centroid,claim in profiles:
        core = dict(enabled=enabled,lead=lead,title=title,centroid=centroid,claim=claim)
        for lam in [1,.95]:
            from article_engine.coverage import parameters
            config = dict(parameters(), **{'lambda':lam})
            total = dict(main=0,units=0,unit_total=0,context_errors=0,words=0,cases=0)
            for doc,records,analysis in cached:
                source_words=len(TOKEN.findall(doc['source_text']))
                for ratio,count,cap in DEPTHS.values():
                    budget=min(cap,max(45,round(source_words*ratio)))
                    chosen,_,_=select(records,analysis,count,budget,doc.get('profile','general'),'',
                                      title=doc['source_text'].splitlines()[0],core_config=core,config=config)
                    score=metrics(doc,[records[i].text for i in chosen])
                    total['main']+=score['main_idea_preserved']; total['units']+=score['unit_covered']
                    total['unit_total']+=score['unit_count']; total['context_errors']+=score['context_dependency_errors']
                    total['words']+=score['word_count']; total['cases']+=1
            trials.append({'core':core,'lambda':lam,**total})
    best=max(trials,key=lambda r:(-r['context_errors'],r['main'],r['units'],-r['words'],r['lambda']))
    return {'core_parameters':best['core'],'selected_lambda':best['lambda'],
            'development_only':True,'selected_result':best,'trials':trials,
            'development_ids':[d['id'] for d in docs],
            'development_sha256':sha256(json.dumps(docs,sort_keys=True).encode()).hexdigest(),
            'selection_rule':'min context errors, max main-idea checks, max unit checks, min words; ties prefer no MMR',
            'human_verified':False}


if __name__=='__main__':
    result=tune()
    (ROOT/'article_engine/refinement_parameters.json').write_text(json.dumps(result,indent=2)+'\n',encoding='utf8')
    print(json.dumps(result['selected_result'],indent=2))
