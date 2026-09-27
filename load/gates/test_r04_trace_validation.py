"""R04 trace identity and nonempty evidence checks."""

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('p3_profile', GATES / 'p3_profile.py')
profile = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = profile
spec.loader.exec_module(profile)


class R04TraceValidationTest(unittest.TestCase):
    def test_empty_trace_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'query-trace.jsonl'
            path.write_text('')
            with self.assertRaisesRegex(profile.ProfileError, 'TRACE_EMPTY'):
                profile.verify_trace(path)

    def test_ordinal_may_restart_only_for_a_new_invocation(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'query-trace.jsonl'
            row = {
                'run_id': 'r', 'command_id': 'http:tree_list', 'connection': 'pgsql',
                'sql_template_hash': 'a' * 64, 'duration_ms': 1.0,
                'ordinal': 1, 'in_transaction': False,
            }
            first = {**row, 'invocation_id': 'a' * 16}
            second = {**row, 'invocation_id': 'b' * 16}
            path.write_text(json.dumps(first) + '\n' + json.dumps(second) + '\n')
            profile.verify_trace(path)
            path.write_text(json.dumps(first) + '\n' + json.dumps(first) + '\n')
            with self.assertRaisesRegex(profile.ProfileError, 'TRACE_DUPLICATE'):
                profile.verify_trace(path)


if __name__ == '__main__':
    unittest.main()
