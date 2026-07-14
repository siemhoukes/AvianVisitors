import datetime
import json
import os
import tempfile
import unittest
from unittest.mock import patch

import scripts.utils.guesses as guesses
from scripts.utils.guesses import write_guesses
from tests.helpers import Settings


class FakeFile:
    def __init__(self):
        self.file_date = datetime.datetime(2026, 7, 14, 9, 12, 24)


class TestWriteGuesses(unittest.TestCase):

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.settings = Settings.with_defaults()
        self.settings['RECS_DIR'] = self.tmp.name
        guesses._pruned_on = None

    def tearDown(self):
        self.tmp.cleanup()

    def _records(self):
        path = os.path.join(self.tmp.name, 'guesses', 'guesses-2026-07-14.jsonl')
        with open(path, encoding='utf-8') as f:
            return [json.loads(line) for line in f if line.strip()]

    @patch('scripts.utils.helpers._load_settings')
    def test_statuses_and_top(self, mock_load_settings):
        mock_load_settings.return_value = self.settings
        raw = {
            '0.0;3.0': [('Pica pica', 0.9), ('Bird_B', 0.3), ('Bird_C', 0.01)],
            '3.0;6.0': [('Bird_B', 0.4)],
            '6.0;9.0': [('Bird_D', 0.02)],
            '9.0;12.0': [('Human_Human', 0.0)],
            '12.0;15.0': [('Bird_E', 0.8)],
        }
        predicted = ['Pica pica', 'Bird_B', 'Bird_D']
        write_guesses(FakeFile(), raw, {'Pica pica': 'Ekster'}, [], [], predicted, [])

        recs = self._records()
        self.assertEqual([r['status'] for r in recs],
                         ['confident', 'below_confidence', 'quiet', 'human', 'sf_thresh'])
        first = recs[0]
        self.assertEqual(first['t'], '2026-07-14T09:12:24')
        self.assertEqual(first['top'][0], {'sci': 'Pica pica', 'com': 'Ekster', 'conf': 0.9})
        # sub-QUIET_CONF runner-up guesses are dropped from `top`
        self.assertEqual(len(first['top']), 2)
        # slot start offsets shift the wall-clock timestamp
        self.assertEqual(recs[4]['t'], '2026-07-14T09:12:36')

    @patch('scripts.utils.helpers._load_settings')
    def test_prune_removes_old_days(self, mock_load_settings):
        mock_load_settings.return_value = self.settings
        d = os.path.join(self.tmp.name, 'guesses')
        os.makedirs(d)
        old = os.path.join(d, 'guesses-2020-01-01.jsonl')
        open(old, 'w').close()
        write_guesses(FakeFile(), {'0.0;3.0': [('Pica pica', 0.9)]}, {}, [], [], [], [])
        self.assertFalse(os.path.exists(old))

    @patch('scripts.utils.helpers._load_settings')
    def test_never_raises(self, mock_load_settings):
        # RECS_DIR pointing at an unwritable location must not blow up analysis
        self.settings['RECS_DIR'] = os.path.join(self.tmp.name, 'nope\0bad')
        mock_load_settings.return_value = self.settings
        write_guesses(FakeFile(), {'0.0;3.0': [('Pica pica', 0.9)]}, {}, [], [], [], [])


if __name__ == '__main__':
    unittest.main()
