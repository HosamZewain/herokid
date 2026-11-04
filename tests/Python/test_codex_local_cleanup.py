import hashlib
import importlib.util
import json
import os
import tempfile
import time
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('cleanup', Path(__file__).parents[2] / 'ops/codex-scenes/cleanup.py')
cleanup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cleanup)


class CleanupTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name).resolve()
        self.home, self.spool = self.root / 'home', self.root / 'spool'
        self.key = '11111111-1111-4111-8111-111111111111'
        self.now = int(time.time())
        self.old = self.now - 3 * 86400
        self.raw = b'synthetic image bytes'
        self.sha = hashlib.sha256(self.raw).hexdigest()
        self.work = self.home / 'jobs' / self.key
        self.inbox = self.spool / 'inbox' / self.key
        self.out = self.spool / 'outbox' / self.key
        self.cache = self.home / '.codex' / 'generated_images'
        for folder in [self.work, self.inbox, self.out, self.cache]:
            folder.mkdir(parents=True)
        manifest = {'id': self.key, 'order_id': 17, 'scene_count': 1}
        self.write(self.inbox / 'manifest.json', json.dumps(manifest).encode())
        manifest_sha = hashlib.sha256((self.inbox / 'manifest.json').read_bytes()).hexdigest()
        self.state = {'id': self.key, 'status': 'completed', 'manifest_sha256': manifest_sha}
        self.write(self.out / 'status.json', json.dumps(self.state).encode())
        for file in [self.work / 'scene-01.png', self.out / 'scene-01.png', self.cache / 'generated.png']:
            self.write(file, self.raw)
        self.write(self.work / 'identity.png', b'reference: must remain')
        self.write(self.work / 'private-scene-01.log', b'diagnostic: must remain')
        self.plan = {'version': 1, 'generated_at': self.now, 'retention_seconds': 172800, 'allow_generated_cache': True,
                     'runs': [{'id': self.key, 'order_id': 17, 'scene_count': 1, 'finished_at': self.old, 'scenes': {'1': self.sha}}]}

    def write(self, path, data):
        path.write_bytes(data)
        os.utime(path, (self.old, self.old))

    def tearDown(self):
        self.temp.cleanup()

    def clean(self, apply=True):
        return cleanup.clean(self.plan, self.home, self.spool, apply, self.now)

    def test_dry_run_then_apply_preserves_status_manifest_references_and_logs(self):
        self.assertEqual(3, self.clean(False)['candidate_files'])
        self.assertTrue((self.work / 'scene-01.png').exists())
        self.assertFalse((self.out / 'local-cleanup.json').exists())
        result = self.clean()
        self.assertEqual(3, result['deleted_files'])
        self.assertEqual(3 * len(self.raw), result['deleted_bytes'])
        for path in [self.out / 'status.json', self.inbox / 'manifest.json', self.work / 'identity.png', self.work / 'private-scene-01.log']:
            self.assertTrue(path.exists())
        receipt = json.loads((self.out / 'local-cleanup.json').read_text())
        self.assertTrue(receipt['local_images_removed'])
        self.assertTrue(receipt['shared_cache_done'])
        self.assertEqual(0, self.clean()['deleted_files'])

    def test_running_paused_uncertain_or_changed_manifest_is_untouched(self):
        for state in ['running', 'paused', 'uncertain', 'needs_manual', 'stopped']:
            self.write(self.out / 'status.json', json.dumps({**self.state, 'status': state}).encode())
            self.assertEqual(0, self.clean()['deleted_files'])
        self.write(self.out / 'status.json', json.dumps(self.state).encode())
        self.write(self.inbox / 'manifest.json', b'{}')
        self.assertEqual(0, self.clean()['deleted_files'])

    def test_corrupt_recent_symlink_and_hardlink_images_are_not_deleted(self):
        target = self.work / 'scene-01.png'
        self.write(target, b'changed')
        self.clean()
        self.assertTrue(target.exists())
        self.assertFalse(json.loads((self.out / 'local-cleanup.json').read_text())['local_images_removed'])
        target.unlink()
        external = self.root / 'external.png'
        self.write(external, self.raw)
        target.symlink_to(external)
        self.clean()
        self.assertTrue(external.exists())
        self.assertTrue(target.is_symlink())
        target.unlink()
        os.link(external, target)
        self.clean()
        self.assertTrue(target.exists())
        target.unlink()
        target.write_bytes(self.raw)
        self.clean()
        self.assertTrue(target.exists())

    def test_unreviewed_rejected_image_is_preserved(self):
        self.write(self.work / 'rejected-scene-01-attempt-1.png', b'rejected')
        self.assertEqual(3, self.clean()['deleted_files'])
        self.assertTrue((self.work / 'rejected-scene-01-attempt-1.png').exists())

    def test_expired_and_malformed_plans_do_nothing(self):
        for change in [{'generated_at': self.now - 301}, {'retention_seconds': 1}, {'version': 0}]:
            original = self.plan.copy()
            self.plan.update(change)
            with self.assertRaises(ValueError):
                self.clean()
            self.plan = original
        self.plan['runs'][0]['id'] = '../../outside'
        self.assertEqual(0, self.clean()['deleted_files'])

    def test_shared_cache_waits_for_active_or_not_yet_started_worker(self):
        second = self.spool / 'inbox' / '22222222-2222-4222-8222-222222222222'
        second.mkdir()
        self.write(second / 'manifest.json', b'{}')
        result = self.clean()
        self.assertFalse(result['shared_cache_idle'])
        self.assertEqual(2, result['deleted_files'])
        self.assertTrue((self.cache / 'generated.png').exists())
        self.assertFalse(json.loads((self.out / 'local-cleanup.json').read_text())['shared_cache_done'])

    def test_symbolic_run_directory_is_not_followed(self):
        external = self.root / 'external-work'
        self.work.rename(external)
        self.work.symlink_to(external, target_is_directory=True)
        self.assertEqual(0, self.clean()['deleted_files'])
        self.assertTrue((external / 'scene-01.png').exists())

    def legacy_receipts(self):
        identity = 'c' * 64
        manifest = json.loads((self.inbox / 'manifest.json').read_text())
        manifest.update(protocol=4, identity_checksum=identity)
        self.write(self.inbox / 'manifest.json', json.dumps(manifest).encode())
        state = self.state.copy()
        state.pop('manifest_sha256')
        self.write(self.out / 'status.json', json.dumps(state).encode())
        self.plan['runs'][0].update(protocol=4, identity_checksum=identity)
        meta = {'id': self.key, 'order_id': 17, 'scene': 1, 'identity_checksum': identity, 'sha256': self.sha}
        self.write(self.out / 'scene-01.json', json.dumps(meta).encode())

    def test_legacy_without_manifest_digest_requires_all_bound_scene_receipts(self):
        self.legacy_receipts()
        result = self.clean()
        self.assertEqual(3, result['deleted_files'])
        self.assertTrue((self.out / 'scene-01.json').exists())

    def test_legacy_wrong_identity_or_receipt_and_modern_missing_digest_are_preserved(self):
        self.legacy_receipts()
        meta_path = self.out / 'scene-01.json'
        meta = json.loads(meta_path.read_text())
        self.write(meta_path, json.dumps({**meta, 'order_id': 999}).encode())
        self.assertEqual(0, self.clean()['deleted_files'])
        self.write(meta_path, json.dumps(meta).encode())
        self.plan['runs'][0]['identity_checksum'] = 'd' * 64
        self.assertEqual(0, self.clean()['deleted_files'])
        self.plan['runs'][0].update(protocol=6, identity_checksum='c' * 64)
        manifest = json.loads((self.inbox / 'manifest.json').read_text())
        manifest['protocol'] = 6
        self.write(self.inbox / 'manifest.json', json.dumps(manifest).encode())
        self.assertEqual(0, self.clean()['deleted_files'])

    def test_symlink_receipt_temp_never_overwrites_another_file(self):
        target = self.root / 'protected.txt'
        target.write_bytes(b'preserve')
        (self.out / 'local-cleanup.json.tmp').symlink_to(target)
        result = self.clean()
        self.assertEqual(b'preserve', target.read_bytes())
        self.assertGreater(result['skipped_files'], 0)

    def test_wrong_s3_attestation_never_deletes_image(self):
        self.plan['runs'][0]['scenes']['1'] = 'b' * 64
        self.assertEqual(0, self.clean()['deleted_files'])
        self.assertTrue((self.out / 'scene-01.png').exists())


if __name__ == '__main__':
    unittest.main()
