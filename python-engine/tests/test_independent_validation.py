"""Research-harness checks. Passing these is not an engine release gate."""
import json
from pathlib import Path
import sys
import unittest

sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from evaluation.independent_validation import OUT, verify
from evaluation.independent_comparison import inputs, choose_by_length, SYSTEMS, DEPTHS


class IndependentValidationTests(unittest.TestCase):
    def test_frozen_evaluation_code_and_protocol_unchanged(self):
        self.assertEqual(verify()['evaluation_relevant_changes'],[])

    def test_all_eight_categories_and_valid_exact_evidence(self):
        docs=inputs()
        self.assertEqual(len(docs),8)
        self.assertEqual({d['category'] for d in docs},{'news','scientific','research','business','educational','technical','editorial','feature'})
        for d in docs:
            self.assertTrue(300<=d['word_count']<=8000)
            self.assertEqual(d['independent_annotation_status'],'pending')
            self.assertTrue(d['license'] and d['license_url'] and d['url'])
            inventory={s['id']:s for s in d['source_sentences']}
            for s in inventory.values():self.assertEqual(d['source_text'][s['start']:s['end']],s['text'])
            for unit in d['draft_units']:
                self.assertIsNone(unit['human_review'])
                for bundle in unit['evidence_any']:
                    self.assertTrue(bundle)
                    self.assertTrue(all(i in inventory for i in bundle))
            self.assertEqual({p['expected_support'] for p in d['focus_probes']},{'supported','partial','unsupported'})

    def test_length_choice_ignores_scores_and_rejects_empty_or_error(self):
        trials=[{'error':None,'sentences':['Complete sentence.'],'words':50,'annotation_score':0},
                {'error':None,'sentences':['Another sentence.'],'words':60,'annotation_score':9999},
                {'error':None,'sentences':[],'words':51},
                {'error':'infeasible','sentences':['Bad contract.'],'words':51}]
        self.assertIs(choose_by_length(trials,51),trials[0])
        trials[0]['annotation_score']=-9999
        self.assertIs(choose_by_length(trials,51),trials[0])

    def test_tie_prefers_smaller_complete_output(self):
        low={'error':None,'sentences':['A.'],'words':45}
        high={'error':None,'sentences':['B.'],'words':55}
        self.assertIs(choose_by_length([high,low],50),low)

    def test_six_real_ui_sentence_requests(self):
        self.assertEqual([v[0] for v in DEPTHS.values()],[3,3,5,8,11,15])

    def test_completed_results_have_no_invented_human_scores(self):
        path=OUT/'comparison-results.json'
        if not path.exists():self.skipTest('Comparison still running')
        data=json.loads(path.read_text(encoding='utf8'))
        self.assertIsNone(data['human_review']['scores'])
        self.assertEqual(data['human_review']['reviewers'],[])
        for system in SYSTEMS:
            run=json.loads((OUT/('runs-'+system+'.json')).read_text(encoding='utf8'))
            self.assertTrue(run['complete'])
            self.assertEqual(len(run['rows']),72)
            self.assertEqual(len({r['case_id'] for r in run['rows']}),72)
            for row in run['rows']:
                self.assertIn(row['selected_trial'],range(len(row['trials'])))
                selected=row['trials'][row['selected_trial']]
                self.assertEqual(selected,choose_by_length(row['trials'],row['target_words']))
                for trial in row['trials']:
                    self.assertIsNone(trial['diagnostics']['human_factuality'])


if __name__=='__main__':unittest.main()
