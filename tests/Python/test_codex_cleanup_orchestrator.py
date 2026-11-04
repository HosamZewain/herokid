import contextlib
import importlib.util
import io
import json
import subprocess
import unittest
from pathlib import Path
from unittest.mock import MagicMock, mock_open, patch

spec = importlib.util.spec_from_file_location('orchestrator', Path(__file__).parents[2] / 'ops/codex-scenes/cleanup-orchestrator.py')
orchestrator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(orchestrator)


class OrchestratorTest(unittest.TestCase):
    def invoke(self, apply=False, fail=False):
        producer = MagicMock()
        producer.stdout = io.StringIO(json.dumps({'runs': [], 'skipped': []}) + '\n')
        producer.stdin = io.StringIO()
        producer.poll.return_value = None
        result = subprocess.CompletedProcess([], 0, json.dumps({'deleted_files': 0}), '')
        effect = subprocess.TimeoutExpired('helper', 60) if fail else None
        with patch('builtins.open', mock_open()), patch.object(orchestrator.fcntl, 'flock'), patch.object(orchestrator.subprocess, 'Popen', return_value=producer) as start, patch.object(orchestrator.subprocess, 'run', return_value=result, side_effect=effect) as run, patch('sys.argv', ['cleanup'] + (['--apply'] if apply else [])), contextlib.redirect_stdout(io.StringIO()):
            if fail:
                with self.assertRaises(subprocess.TimeoutExpired):
                    orchestrator.main()
            else:
                orchestrator.main()
        self.assertEqual('release\n', producer.stdin.getvalue())
        producer.communicate.assert_called_once_with(timeout=10)
        self.assertEqual(['/usr/sbin/runuser', '-u', 'www-data', '--'], start.call_args.args[0][:4])
        self.assertEqual(['/usr/sbin/runuser', '-u', 'herokid-codex', '-g', 'herokid-codex-bridge', '--'], run.call_args_list[0].args[0][:6])
        self.assertEqual(apply, '--apply' in run.call_args_list[0].args[0])

    def test_dry_run_keeps_deletion_disabled_and_uses_restricted_accounts(self):
        self.invoke()

    def test_apply_is_explicit(self):
        self.invoke(apply=True)

    def test_timeout_releases_dispatch_fence(self):
        self.invoke(fail=True)


if __name__ == '__main__':
    unittest.main()
