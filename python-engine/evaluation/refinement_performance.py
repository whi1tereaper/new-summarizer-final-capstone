"""Current-engine cold/warm, depth, Focus and scaling measurements."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys
from time import perf_counter

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
sys.path=[p for p in sys.path if p!=str(Path(__file__).resolve().parent)]


def worker(enhanced):
    began=perf_counter()
    from evaluation.coverage_experiment import MemorySampler
    from evaluation.coverage_corpus import heldout
    from evaluation.article_engine_experiment import quality_documents
    from evaluation.refinement_calibration import DEPTHS
    from article_engine.summarizer import summarize
    from summarizer_core.models import SourceDocument
    from article_detector import detect_article
    imports=perf_counter()-began
    short=next(d for d in heldout() if d['id']=='CE-HO-RESEARCH')
    long=next(d for d in quality_documents() if d['id']=='NASA-CRS35-HEALTH-RESEARCH')
    rows=[]
    def run(name,text,depth,focus='',phase='warm_matrix'):
        started=perf_counter()
        try:
            with MemorySampler() as memory:
                result=summarize({'summary_depth':depth,'sentence_count':DEPTHS[depth][1],
                                  'focus_query':focus,'output_format':'paragraph','performance_profile':True},
                                 SourceDocument(text,'text'),detect_article(text),enhanced=enhanced)
            analysis=result['source_metadata']['article_analysis']
            return {'source':name,'source_characters':len(text),'depth':depth,'focus':focus,'phase':phase,
                    'seconds':perf_counter()-started,'sampled_peak_rss_mib':memory.peak/1048576,
                    'stage_timings':analysis['timings'],'words':result['readability']['summary_word_count'],
                    'effective_word_budget':analysis['word_budget'],'sentences':result['sentence_count'],
                    'annotation_status':analysis['annotation_status'],'focus_status':analysis['focus_status'],'error':None}
        except Exception as error:
            return {'source':name,'depth':depth,'focus':focus,'phase':phase,
                    'seconds':perf_counter()-started,'error':type(error).__name__}
    rows.append(run('short_diagnostic',short['source_text'],'very_brief',phase='first_request_after_imports'))
    for name,doc,query in [('short_diagnostic',short,'attendance outcomes'),('long_real_nasa',long,'research experiments')]:
        for depth in DEPTHS:
            for focus in ['',query]: rows.append(run(name,doc['source_text'],depth,focus))
    stress='Monitoring changes across field stations\n\n'+' '.join(
        f'The monitoring study reported {i+20} observations at station {i+1}, with a measured change of {i%19+1} percent during the survey period.' for i in range(800))
    rows.append(run('synthetic_800_sentence_scaling',stress,'comprehensive',phase='stress_first'))
    rows.append(run('synthetic_800_sentence_scaling',stress,'comprehensive',phase='stress_repeat'))
    return {'enhanced':enhanced,'imports_seconds':imports,'runs':rows}


if __name__=='__main__':
    parser=argparse.ArgumentParser(); parser.add_argument('--worker',choices=['light','enhanced'])
    args=parser.parse_args()
    if args.worker: print(json.dumps(worker(args.worker=='enhanced')))
    else:
        env=dict(os.environ,OPENBLAS_NUM_THREADS='1',OMP_NUM_THREADS='1',MKL_NUM_THREADS='1',
                 HF_HUB_OFFLINE='1',TRANSFORMERS_OFFLINE='1',TOKENIZERS_PARALLELISM='false',PYTHONIOENCODING='utf-8')
        results=[]
        for mode in ['light','enhanced']:
            run=subprocess.run([sys.executable,str(Path(__file__).resolve()),'--worker',mode],env=env,
                               capture_output=True,text=True,encoding='utf8',timeout=480,check=True)
            results.append(json.loads(run.stdout))
            print('Finished performance '+mode,file=sys.stderr,flush=True)
        data={'engine':'recipe-article-2.1','processes':results,'scope':'article core plus detection; not extraction/PHP/database',
              'limits':['No deployment/container memory cap enforced.','RSS sampling at 10 ms may miss short peaks.',
                        'Short and NASA sources were previously inspected; this is not an accuracy experiment.',
                        '800-sentence source is synthetic scaling work, not realistic quality evidence.']}
        (ROOT.parent/'docs/research/refinement-performance.json').write_text(json.dumps(data,indent=2)+'\n',encoding='utf8')
        print('Wrote refinement-performance.json',flush=True)
