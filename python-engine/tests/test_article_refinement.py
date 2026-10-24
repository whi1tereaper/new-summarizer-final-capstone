"""Core-first selection, query support and corrective-context regressions."""
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
from article_engine.coverage import article_sentences, analyze, TOKEN
from article_engine.core import core_scores, core_parameters
from article_engine.selection import select
from article_engine.summarizer import summarize
from article_engine.validation import evidence_bundle, validate_selected
from article_detector import detect_article
from summarizer_core.models import SourceDocument
from failure_contract import ProcessingFailure
from evaluation.refinement_calibration import calibration_documents
from evaluation.coverage_corpus import heldout


class ArticleRefinementTests(unittest.TestCase):
    def result(self,text,budget=60,focus='',**options):
        return summarize({'summary_depth':'balanced','sentence_count':8,'target_word_budget':budget,
                          'focus_query':focus,'output_format':'paragraph'},SourceDocument(text,'text'),
                         detect_article(text),enhanced=False,**options)

    def test_central_message_not_short_fact_wins_tight_budget(self):
        text='A repair policy reduces repeated tunnel closures\n\nThe maintenance team recommended preventive tunnel repairs to reduce repeated closures and keep annual maintenance costs stable. The trial covered seven districts. Repairs cost 34 million dollars.'
        result=self.result(text,budget=26)
        self.assertIn('recommended preventive tunnel repairs',result['plain_summary'])
        self.assertLessEqual(result['readability']['summary_word_count'],26)

    def test_core_can_select_a_late_thesis_not_the_opening_hook(self):
        doc=next(d for d in calibration_documents() if d['id']=='DEV-OPPOSITION-CORE')
        result=self.result(doc['source_text'],budget=35)
        self.assertIn('I argue',result['plain_summary'])
        self.assertNotIn('Opponents say',result['plain_summary'])

    def test_larger_budget_retains_feasible_central_seed(self):
        doc=next(d for d in calibration_documents() if d['id']=='DEV-NARRATIVE-CORE')
        seeds=[]
        for budget in [45,75,110,160]:
            result=self.result(doc['source_text'],budget=budget)
            seeds.append(result['source_metadata']['article_analysis']['coverage_selection']['core_selection']['source_index'])
        self.assertEqual(len(set(seeds)),1)

    def test_focus_does_not_force_unrelated_global_core(self):
        text='A public transport pilot\n\nThe transit authority announced a new route to connect suburban districts.\n\nThe pilot enrolled 500 passengers. The project budget was not measured in this study.'
        result=self.result(text,budget=45,focus='participants')
        self.assertNotIn('new route',result['plain_summary'])

    def test_predefined_methods_scope_does_not_accept_every_study_sentence(self):
        doc=next(d for d in heldout() if d['id']=='CE-HO-RESEARCH')
        result=self.result(doc['source_text'],budget=140,focus='study design')
        self.assertIn('520',result['plain_summary'])
        self.assertNotIn('no significant difference in test scores',result['plain_summary'])
        self.assertLessEqual(result['sentence_count'],5)

    def test_known_finance_focus_is_source_supported(self):
        doc=next(d for d in heldout() if d['id']=='CE-HO-BUSINESS')
        result=self.result(doc['source_text'],budget=140,focus='financial results')
        self.assertNotEqual(result['source_metadata']['article_analysis']['focus_status'],'unsupported')
        self.assertIn('million',result['plain_summary'])

    def test_known_environment_focus_is_source_supported(self):
        doc=next(d for d in heldout() if d['id']=='CE-HO-SCIENCE')
        result=self.result(doc['source_text'],budget=140,focus='environmental threats')
        self.assertNotEqual(result['source_metadata']['article_analysis']['focus_status'],'unsupported')
        self.assertIn('pollution',result['plain_summary'])

    def test_absent_modifier_is_not_excused_by_generic_results_category(self):
        text='Researchers measured classroom attendance at eight colleges. The study found a nine percent increase in attendance. The difference was not statistically significant.'
        for query in ['volcanic results','seismology results','classroom volcanic seismology']:
            result=self.result(text,focus=query)
            self.assertEqual(result['sentences'],[],query)

    def test_missing_wordnet_fails_closed_without_download(self):
        text='The firm reported revenue of 84 million dollars. Operating profit fell during the quarter.'
        with patch('summarizer_core.tokenization.try_ensure_nltk_resource',return_value=False):
            result=self.result(text,focus='volcanic seismology')
        self.assertEqual(result['sentences'],[])

    def test_later_reanalysis_cannot_be_omitted_from_initial_finding(self):
        text='The trial found a 12 percent improvement in recovery. A later reanalysis found no improvement after correcting the sampling error.'
        records,_=article_sentences(text)
        self.assertIn(1,evidence_bundle(records,0))
        with self.assertRaises(ProcessingFailure):
            validate_selected([records[0]],records,text)
        result=self.result(text,budget=45)
        self.assertIn('no improvement',result['plain_summary'])

    def test_source_numbers_negation_attribution_and_spans_are_unchanged(self):
        text='The agency said that costs may reach 24 million dollars. The agency did not claim that the estimate was certain.'
        result=self.result(text,budget=45)
        for evidence in result['evidence']:
            self.assertEqual(text[evidence['start']:evidence['end']],evidence['source_sentence'])
        self.assertIn('may reach',result['plain_summary'])
        self.assertIn('did not claim',result['plain_summary'])

    def test_internal_lightweight_setting_does_not_add_frontend_control(self):
        text='The study reported an improvement in reading scores. The study included 500 participants at eight schools.'
        with patch.dict('os.environ',{'LIGHT_ARTICLE_NLP_ENHANCED':'false'}):
            result=summarize({'summary_depth':'brief','sentence_count':3},SourceDocument(text,'text'),detect_article(text))
        self.assertEqual(result['source_metadata']['article_analysis']['annotation_status'],'disabled_lightweight')


if __name__=='__main__':
    unittest.main()
