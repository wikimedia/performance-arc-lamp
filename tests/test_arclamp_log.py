import datetime
import importlib
import os
import sys
import tempfile
import unittest
from pathlib import Path

import yaml


sys.path.append(os.path.dirname(os.path.dirname(__file__)))
arclamp_log = importlib.import_module("arclamp-log")
SAMPLE_CONFIG = """
base_path: {base_path}
logs:
  - period: hourly
    format: "%Y-%m-%d_%H.excimer"
    retain: 4
  - period: daily
    format: "%Y-%m-%d.excimer"
    retain: 2
"""


class TestArclampLog(unittest.TestCase):
    def test_init(self):
        base_path = tempfile.mkdtemp(prefix='testarclamplogs')
        config_str = SAMPLE_CONFIG.format(base_path=base_path)
        config = yaml.safe_load(config_str)
        logs = arclamp_log.init_logs_from_config(config)

        self.assertEqual(2, len(logs))

        self.assertEqual('hourly', logs[0].period)
        self.assertEqual('daily', logs[1].period)

        self.assertTrue('hourly', os.path.basename(logs[0].path))
        self.assertTrue('daily', os.path.basename(logs[1].path))

        self.assertTrue(os.path.exists(logs[0].path))
        self.assertTrue(os.path.exists(logs[1].path))

    def test_prune_files(self):
        base_path = tempfile.mkdtemp(prefix='testarclamplogs')
        config_str = SAMPLE_CONFIG.format(base_path=base_path)
        config = yaml.safe_load(config_str)
        logs = arclamp_log.init_logs_from_config(config)

        base_path = Path(base_path)
        # prune_files sorts by mtime rather than filename to separate concerns
        # and avoid deletions until writes are settled.
        # The clock in CI is not precise enoguh to simply touch() in order
        # (files end up with the same mtime and we delete random files).
        fake_time = datetime.datetime(2011, 4, 1, 0, 0, 0).timestamp()
        for file_path in [
            (base_path / 'daily' / '2011-04-01.excimer.all.log'),
            (base_path / 'daily' / '2011-04-01.excimer.index.log'),
            (base_path / 'daily' / '2011-04-02.excimer.all.log'),
            (base_path / 'daily' / '2011-04-02.excimer.index.log'),
            (base_path / 'daily' / '2011-04-03.excimer.all.log'),
            (base_path / 'daily' / '2011-04-03.excimer.index.log'),
            (base_path / 'daily' / '2011-04-04.excimer.all.log'),
            (base_path / 'daily' / '2011-04-04.excimer.index.log'),
            (base_path / 'daily' / '2011-04-05.excimer.all.log'),
            (base_path / 'daily' / '2011-04-05.excimer.index.log'),
            (base_path / 'hourly' / '2011-04-01_09.excimer.all.log'),
            (base_path / 'hourly' / '2011-04-01_09.excimer.index.log'),
            (base_path / 'hourly' / '2011-04-01_14.excimer.all.log'),
            (base_path / 'hourly' / '2011-04-01_14.excimer.index.log'),
            (base_path / 'hourly' / '2011-04-01_23.excimer.all.log'),
            (base_path / 'hourly' / '2011-04-01_23.excimer.index.log'),
            (base_path / 'hourly' / '2011-04-02_08.excimer.all.log'),
            (base_path / 'hourly' / '2011-04-02_08.excimer.index.log'),
            (base_path / 'hourly' / '2011-04-03_12.excimer.all.log'),
            (base_path / 'hourly' / '2011-04-03_12.excimer.load.log'),
            (base_path / 'hourly' / '2011-04-04_09.excimer.all.log'),
            (base_path / 'hourly' / '2011-04-04_09.excimer.index.log'),
            (base_path / 'hourly' / '2011-04-05_21.excimer.all.log'),
            (base_path / 'hourly' / '2011-04-05_21.excimer.api.log'),
        ]:
            file_path.touch()
            os.utime(file_path, (fake_time, fake_time))
            fake_time += 1

        for log in logs:
            log.prune_files('all')

        # Keep 2 daily "all"
        self.assertSetEqual(set([
            '2011-04-04.excimer.all.log',
            '2011-04-05.excimer.all.log',
            '2011-04-01.excimer.index.log',
            '2011-04-02.excimer.index.log',
            '2011-04-03.excimer.index.log',
            '2011-04-04.excimer.index.log',
            '2011-04-05.excimer.index.log',
        ]), set(os.listdir(base_path / 'daily')))

        for log in logs:
            log.prune_files('index')

        # Keep 2 daily "index"
        self.assertSetEqual(set([
            '2011-04-04.excimer.all.log',
            '2011-04-05.excimer.all.log',
            '2011-04-04.excimer.index.log',
            '2011-04-05.excimer.index.log',
        ]), set(os.listdir(base_path / 'daily')))

        # Keep 4 hourly "all" and "index"
        self.assertSetEqual(set([
            '2011-04-02_08.excimer.all.log',
            '2011-04-03_12.excimer.all.log',
            '2011-04-04_09.excimer.all.log',
            '2011-04-05_21.excimer.all.log',
            '2011-04-05_21.excimer.api.log',
            '2011-04-01_14.excimer.index.log',
            '2011-04-01_23.excimer.index.log',
            '2011-04-02_08.excimer.index.log',
            '2011-04-04_09.excimer.index.log',
            '2011-04-03_12.excimer.load.log',
        ]), set(os.listdir(base_path / 'hourly')))


if __name__ == "__main__":
    unittest.main()
