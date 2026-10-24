"""Evaluation-integrity tests; synthetic fixtures are NOT human research data."""
import csv
from itertools import combinations
import json
from pathlib import Path
import tempfile
import unittest
import sys

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
from evaluation.final_validation import OUT,PILOT,SYSTEMS,DEPTHS,read,verify,runs
from evaluation.final_length_analysis import relaxed_lengths,relaxed_match,assign_lengths
from evaluation.final_agreement import agreement,load_actual
from evaluation.independent_comparison import WORD,choose_by_length
from article_engine.preprocessing import TOKEN


class FinalValidationIntegrity(unittest.TestCase):
    def test_frozen_engine_pilot_and_protocol(self):
        result=verify()
        self.assertEqual(result['evaluation_relevant_changes'],[])
        self.assertEqual(result['pilot_artifacts_changed'],[])
        self.assertEqual(len(read(OUT/'freeze.json')['pilot_artifact_sha256']),59)

    def test_original_selection_and_nine_matches_reproduce(self):
        data=runs()
        for system,rows in data.items():
            for r in rows:
                self.assertEqual(r['trials'][r['selected_trial']],choose_by_length(r['trials'],r['target_words']))
            if system.startswith('article21'):
                self.assertEqual(sum(r['matched']['0.1'] for r in rows if r['kind']=='general'),9)

    def test_dual_tokenizer_relaxation_equals_brute_force(self):
        records=[{'text':s} for s in ['Energy rose 4%.','U.S. cases grew.','well-being and long–term risks.',
                                     'They did not change.','NLP models: alpha_beta 2.0.']]
        for count in [1,2,3]:
            for cap in [5,10,20]:
                expected=0
                for k in range(1,count+1):
                    for subset in combinations(records,k):
                        if sum(len(TOKEN.findall(s['text'])) for s in subset)<=cap:
                            expected|=1<<sum(len(WORD.findall(s['text'])) for s in subset)
                self.assertEqual(relaxed_lengths(records,count,cap),expected&~1)
        self.assertTrue(relaxed_match(1<<95,100))
        self.assertFalse(relaxed_match(1<<80,100))

    def test_length_assignment_max_cardinality_then_min_cost(self):
        result=assign_lengths([100,111],[105,90])
        self.assertEqual(result['pair_count'],2)
        self.assertEqual(result['pairs'],[(0,1),(1,0)])
        self.assertEqual(assign_lengths([100],[95,100])['pairs'],[(0,1)])
        self.assertEqual(assign_lengths([100],[95,105])['pairs'],[(0,0)])
        self.assertEqual(assign_lengths([100],[50])['pair_count'],0)

    def test_matching_accepts_no_quality_argument(self):
        import inspect
        self.assertEqual(list(inspect.signature(assign_lengths).parameters),['left','right'])
        self.assertFalse(read(OUT/'cross-depth-sensitivity.json')['quality_used_for_matching'])

    def test_saved_sensitivity_bodies_are_whole_original_outputs(self):
        old=runs();sens=read(OUT/'cross-depth-sensitivity.json')
        for method,analysis in sens['analyses'].items():
            for system,value in analysis['systems'].items():
                seen_left=set();seen_right=set()
                for pair in value['pairs']:
                    self.assertLessEqual(pair['absolute_word_difference'],max(5,.1*pair['legacy']['words']))
                    for side,s,seen in [('legacy','legacy',seen_left),('candidate',system,seen_right)]:
                        o=pair[side];identity=(pair['article'],o['output_id'])
                        self.assertNotIn(identity,seen);seen.add(identity)
                        origin=o['origins'][0]
                        r=next(r for r in old[s] if r['case_id']==origin['case_id'])
                        self.assertEqual(o['sentences'],r['trials'][origin['trial_index']]['sentences'])
                        if side=='legacy' or method=='native_only':self.assertEqual(origin['trial_index'],0)

    def test_new_generations_and_native_repeats(self):
        expected={'legacy':32,'guide_lsa':24,'article21_light':72,'article21_enhanced':72}
        for system,n in expected.items():
            data=read(OUT/('new-runs-'+system+'.json'))
            self.assertTrue(data['complete']);self.assertEqual(len(data['rows']),n)
            self.assertTrue(all(r['error'] is None for r in data['rows']))
        summary=read(OUT/'analysis-summary.json')
        self.assertEqual(summary['new_generation_records'],200)
        self.assertTrue(all(r['same_sentences_as_pilot_native'] for r in summary['native_repeat_checks']))

    def test_input_lock_and_ambiguous_queries(self):
        from evaluation.final_validation import digest
        lock=read(OUT/'followup-input-lock.json')
        self.assertIn(digest(OUT/'focus-probes.json'),json.dumps(lock))
        probes=read(OUT/'focus-probes.json')['probes']
        self.assertEqual(len(probes),24)
        self.assertTrue(all(p['expected'] is None for p in probes if p['kind']=='ambiguous'))

    def test_all_focus_sentence_occurrences_have_review_rows(self):
        outputs=read(OUT/'focus-output-audit.json')['outputs']
        audit=read(OUT/'focus-sentence-audit.json')
        self.assertEqual(len(outputs),288)
        self.assertEqual(audit['total_native_focus_outputs'],192)
        self.assertEqual(len(audit['rows']),sum(o['returned_sentences'] for o in outputs))
        self.assertEqual(len({r['occurrence_id'] for r in audit['rows']}),len(audit['rows']))
        self.assertTrue(all(r['human_primary_role'] is None and r['human_misleading_when_isolated'] is None for r in audit['rows']))

    def test_no_fictional_human_results(self):
        self.assertEqual(read(OUT/'reviewer-registry.json')['reviewers'],[])
        result=read(OUT/'agreement-results.json')
        self.assertEqual(result['status'],'pending');self.assertIsNone(result['output_agreement'])
        self.assertIsNone(result['sentence_agreement'])
        with (OUT/'human-output-ratings.csv').open(encoding='utf8',newline='') as handle:
            rows=list(csv.DictReader(handle))
        self.assertEqual(len(rows),770)
        self.assertTrue(all(not r['reviewer_id'] and not r['main_idea'] for r in rows))

    def test_agreement_fixture_is_not_research_data(self):
        self.assertEqual(agreement([0,0,1,1],[0,1,0,1],[0,1])['kappa'],0)
        self.assertEqual(agreement([0,1,2],[0,1,2],list(range(5)),True)['kappa'],1)
        self.assertIsNone(agreement([1,1],[1,1],[0,1])['kappa'])
        self.assertIsNone(agreement([],[],list(range(5)))['exact_agreement'])
        with self.assertRaises(ValueError):agreement([0],[0,1],[0,1])

    def test_actual_submission_validation(self):
        with tempfile.TemporaryDirectory(prefix='light-rating-test-') as directory:
            path=Path(directory)/'ratings.csv'
            fields=['review_token','reviewer_id','main_idea','date','rationale']
            def fixture(rows):
                with path.open('w',newline='',encoding='utf8') as handle:
                    writer=csv.DictWriter(handle,fieldnames=fields);writer.writeheader();writer.writerows(rows)
            fixture([{'review_token':'synthetic-unit-fixture','main_idea':'4'}])
            with self.assertRaises(ValueError):load_actual(path,{},['main_idea'],['review_token'],{'main_idea':list('01234')})
            person={'synthetic-rater':{'qualified':True,'independent':True,'qualifications':'unit-test fixture only'}}
            row={'review_token':'synthetic-unit-fixture','reviewer_id':'synthetic-rater','main_idea':'4','date':'test','rationale':'test only'}
            fixture([row,row])
            with self.assertRaises(ValueError):load_actual(path,person,['main_idea'],['review_token'],{'main_idea':list('01234')})
            fixture([row])
            with self.assertRaises(ValueError):load_actual(path,person,['main_idea'],['review_token'],{'main_idea':list('01234')},set())

    def test_enforced_resource_measurements_not_rss_inference(self):
        for mode in ['light','enhanced']:
            data=read(OUT/('process-profile-readable-'+mode+'.json'))
            self.assertTrue(data['complete']);self.assertEqual(data['limit_mib'],512)
            self.assertEqual(data['observer']['memory_max'],str(512*1048576))
            self.assertEqual(data['observer']['swap_max'],'0')
            self.assertEqual(data['contributions_at_largest_sampled_cgroup_current']['unreadable_or_exited_processes'],0)
            self.assertEqual(len(data['runs']),5)
            self.assertTrue(all(q['response']['engine_version']=='recipe-article-2.1' for q in data['runs']))


if __name__=='__main__':unittest.main()
