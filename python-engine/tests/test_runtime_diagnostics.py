"""Configuration precedence and source-free private stage diagnostics."""
import contextlib
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from summarizer_core.runtime import request_scope, stage
from local_cli import load_project_env


class RuntimeDiagnosticsTests(unittest.TestCase):
    def test_process_settings_override_development_dotenv(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / '.env'
            path.write_text('LIGHT_PRECEDENCE=development\nLIGHT_NEW_SETTING="local"\n', encoding='utf8')
            with patch.dict(os.environ, {'LIGHT_PRECEDENCE': 'service'}, clear=True):
                load_project_env(path)
                self.assertEqual(os.environ['LIGHT_PRECEDENCE'], 'service')
                self.assertEqual(os.environ['LIGHT_NEW_SETTING'], 'local')

    def test_private_progress_records_only_allowlisted_stages(self):
        with tempfile.TemporaryDirectory() as directory:
            correlation = 'a' * 32
            with patch.dict(os.environ, {'LIGHT_STAGE_TRACE': '1', 'SUMMARIZER_DIAGNOSTICS_DIR': directory}):
                with contextlib.redirect_stderr(io.StringIO()):
                    with request_scope(correlation=correlation):
                        with stage('pdf_extraction'):
                            pass
                        with stage('private_source_secret'):
                            pass
            values = [json.loads(line) for line in (Path(directory) / ('job-' + correlation + '.jsonl')).read_text().splitlines()]
            self.assertEqual([v['event'] for v in values], ['START', 'END'])
            self.assertTrue(all(v['stage'] == 'pdf_extraction' for v in values))
            self.assertEqual(set(values[0]), {'event', 'stage', 'pid', 'elapsed_seconds'})

    def test_invalid_correlation_cannot_select_a_path(self):
        with tempfile.TemporaryDirectory() as directory:
            with patch.dict(os.environ, {'LIGHT_STAGE_TRACE': '1', 'SUMMARIZER_DIAGNOSTICS_DIR': directory}):
                with contextlib.redirect_stderr(io.StringIO()):
                    with request_scope(correlation='../secret'):
                        with stage('pdf_extraction'):
                            pass
            self.assertEqual(list(Path(directory).iterdir()), [])

    def test_failure_still_releases_request_context(self):
        with self.assertRaises(ValueError):
            with request_scope(correlation='b' * 32) as first:
                raise ValueError('test-only fault')
        with request_scope() as second:
            self.assertIsNot(first, second)
            self.assertEqual(second.correlation, '')


if __name__ == '__main__':
    unittest.main()
