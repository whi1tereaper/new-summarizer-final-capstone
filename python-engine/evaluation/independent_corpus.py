"""Source-only cleaning and sentence inventory, before summary inspection."""
import argparse
from hashlib import sha256
import json
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
sys.path = [p for p in sys.path if p != str(Path(__file__).resolve().parent)]
from evaluation.independent_validation import OUT, write, verify


def prepare():
    from article_engine.coverage import article_sentences, TOKEN
    verify()
    if (OUT / 'corpus.json').exists(): raise RuntimeError('Corpus already locked')
    docs = []
    for path in sorted(OUT.glob('acquisition-IV-*.json')):
        d = json.loads(path.read_text(encoding='utf8'))
        if d['id'] == 'IV-BUSINESS' and d.get('prose_words', 0) < 300:
            # Later intermittent HTTP responses returned only the heading; use
            # the already retained, successful full-body acquisition, not a new source.
            previous = json.loads((OUT / 'acquisition-final-index.json').read_text(encoding='utf8'))
            d = next(row for row in previous if row['id'] == 'IV-BUSINESS')
            d['acquisition_recovery'] = 'Earlier complete response retained in acquisition-final-index.json; later partial response excluded.'
        if 'source_text' not in d: raise RuntimeError('Missing acquired prose: ' + d['id'])
        text = d['source_text']
        if d['id'] == 'IV-NEWS':
            text = text.split('\n\nFor more detailed information')[0]
            text = text.replace(' | U.S. Geological Survey', '', 1)
            d['authors'] = ['US Geological Survey (corporate author; named source speakers retained)']
            d['publication_dates'] = ['2024-04-11']
        elif d['id'] == 'IV-BUSINESS':
            # Figure data-source/caption text is not article prose.
            text = '\n\n'.join(p for p in text.split('\n\n') if not p.startswith(('Data source:', 'Data values:', 'Note: Other')))
            d['authors'] = ['Brett Marohl']
            d['publication_dates'] = ['2025-06-20']
            d['title'] = 'U.S. primary energy production, consumption, and exports increased in 2024'
            text = d['title'] + '\n\n' + text.split('\n\n', 1)[1]
        elif d['id'] == 'IV-SCIENCE':
            text = text.split('\n\nWho\n')[0].replace('\n\nWhat\n\n', '\n\n', 1)
            d['authors'] = ['National Cancer Institute (corporate author)']
            d['publication_dates'] = ['2024-06-26']
        elif d['id'] == 'IV-FEATURE':
            text = text.split('\n\nNASA Earth Observatory images by')[0]
            d['authors'] = ['Lindsey Doermann']
        elif d['id'] == 'IV-EDITORIAL':
            text = text.split('\n\nStatements')[0]
        elif d['id'] == 'IV-TECHNICAL':
            d['authors'] = ['Eric Snow']
            d['publication_dates'] = ['2022-03-08 (created; living PEP snapshot at retrieval)']
        d['source_text'] = text
        d['source_sha256'] = sha256(text.encode()).hexdigest()
        d['word_count'] = len(TOKEN.findall(text))
        if not 300 <= d['word_count'] <= 8000: raise RuntimeError('Article length outside registered range: ' + d['id'])
        records, tokenizer = article_sentences(text)
        d['source_sentences'] = [{'id': f's{i:04d}', 'text': s.text, 'start': s.start,
                                  'end': s.end, 'section': s.section, 'paragraph': s.paragraph}
                                  for i,s in enumerate(records)]
        d['sentence_inventory_tokenizer'] = tokenizer
        d['cleaning_note'] = 'Source-only footer/TOC/media/reference removal; no summary inspected, no prose rewriting.'
        d['draft_units'] = []
        docs.append(d)
    write('corpus-prelock.json', docs)
    for d in docs: print(d['id'], d['word_count'], len(d['source_sentences']), 'replacement_chars', d['source_text'].count('\ufffd'))


def lock():
    from datetime import datetime, timezone
    verify()
    if (OUT / 'corpus.json').exists(): raise RuntimeError('Refusing to replace locked corpus')
    docs=json.loads((OUT/'corpus-prelock.json').read_text(encoding='utf8'))
    labels=json.loads((OUT/'draft-labels.json').read_text(encoding='utf8'))
    previous=(OUT/'prior-data-audit.json').read_text(encoding='utf8').lower()
    for d in docs:
        if d['url'].lower() in previous: raise RuntimeError('Previously inspected URL: '+d['id'])
        label=labels[d['id']]
        inventory=d['source_sentences']
        d['draft_units']=[{'id': name, 'meaning': meaning, 'main': name=='main',
                          'evidence_any': [[inventory[i]['id'] for i in bundle] for bundle in alternatives],
                          'human_review': None} for name,meaning,alternatives in label['units']]
        d['focus_probes']=[{'expected_support':support,'query':query,'unit_ids':units,
                            'source_only_rationale':reason,'human_support_judgment':None}
                           for support,query,units,reason in label['queries']]
        d['draft_context_rules']=label.get('context_rules',[])
        d['source_inconsistencies']=label.get('source_inconsistencies',[])
        assert 300 <= d['word_count'] <= 8000 and inventory
        assert all(d['source_text'][s['start']:s['end']]==s['text'] for s in inventory)
    write('corpus.json',docs)
    write('input-lock.json',{'locked_utc':datetime.now(timezone.utc).isoformat(),
          'corpus_sha256':sha256((OUT/'corpus.json').read_bytes()).hexdigest(),
          'draft_labels_sha256':sha256((OUT/'draft-labels.json').read_bytes()).hexdigest(),
          'human_review_completed':False,'independent_gold_units':None,
          'articles':len(docs),'draft_units':sum(len(d['draft_units']) for d in docs),
          'no_outputs_generated_before_lock':True})
    print('Locked',len(docs),'external articles; independent human review PENDING')


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf8')
    parser=argparse.ArgumentParser(); parser.add_argument('--lock',action='store_true'); parser.add_argument('--show'); parser.add_argument('--start',type=int,default=0)
    parser.add_argument('--end',type=int,default=10000); args=parser.parse_args()
    if args.lock: lock()
    elif args.show:
        docs=json.loads((OUT/'corpus-prelock.json').read_text(encoding='utf8'))
        for d in docs:
            if d['id']==args.show:
                for s in d['source_sentences'][args.start:args.end]: print(s['id'], ' '.join(s['text'].split()))
    else: prepare()
