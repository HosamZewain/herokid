import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('worker', Path(__file__).parents[2] / 'ops/codex-scenes/worker.py')
w = importlib.util.module_from_spec(spec)
spec.loader.exec_module(w)

FAKE = '''#!/usr/bin/env python3
import sys,pathlib,re,struct,zlib
if sys.argv[1:3]==['login','status']:
 print('Logged in using ChatGPT');sys.exit(0)
p=sys.stdin.read(); n=int(re.search(r'Target scene (\\d+)',p).group(1))
root=pathlib.Path(sys.argv[sys.argv.index('-C')+1])
with (root/'calls').open('a') as f: f.write(str(n)+'\\n')
if (root.parent.parent/'fail').exists(): sys.exit(1)
if (root.parent.parent/'low-res').exists():
 (root/f'scene-{n:02d}.png').write_bytes(b'\\x89PNG\\r\\n\\x1a\\n\\x00\\x00\\x00\\rIHDR'+struct.pack('>II',1774,887));sys.exit(0)
c=lambda t,b:struct.pack('>I',len(b))+t+b+struct.pack('>I',zlib.crc32(t+b))
color=1 if (root.parent.parent/'duplicate').exists() else n
b=b'\\x89PNG\\r\\n\\x1a\\n'+c(b'IHDR',struct.pack('>IIBBBBB',1536,1024,8,2,0,0,0))+c(b'IDAT',zlib.compress((b'\\0'+bytes([color,0,0])*1536)*1024))+c(b'IEND',b'')
(root/f'scene-{n:02d}.png').write_bytes(b)
'''


class WorkerTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        w.HOME = self.root / 'home'; w.HOME.mkdir()
        w.SPOOL = self.root / 'spool'
        (w.SPOOL / 'inbox').mkdir(parents=True)
        (w.SPOOL / 'outbox').mkdir()
        cli = self.root / 'fake-cli'; cli.write_text(FAKE); cli.chmod(0o700)
        w.CLI = str(cli)
        self.id = '00000000-0000-4000-8000-000000000001'
        self.inbox = w.SPOOL / 'inbox' / self.id; self.inbox.mkdir()
        (self.inbox / 'identity.img').write_bytes(b'synthetic-reference')
        (self.inbox / 'manifest.json').write_text(json.dumps({'id':self.id,'order_id':123,'scene_count':13,'prompt':'synthetic',
            'identity_checksum':w.hashlib.sha256(b'synthetic-reference').hexdigest(),
            'texts':[{'scene_number':n,'text':'synthetic'} for n in range(1,14)]}))
        self.sleep = patch.object(w.time,'sleep',lambda _: None); self.sleep.start()
        self.health = patch.object(w,'heartbeat',lambda: None); self.health.start()

    def tearDown(self):
        self.sleep.stop(); self.health.stop(); self.temp.cleanup()

    def state(self):
        return json.loads((w.SPOOL / 'outbox' / self.id / 'status.json').read_text())['status']

    def test_parallel_stories_keep_references_and_output_metadata_separate(self):
        second = w.SPOOL / 'inbox' / '00000000-0000-4000-8000-000000000002'; second.mkdir()
        identity = b'other-child-reference'
        manifest = json.loads((self.inbox/'manifest.json').read_text())
        manifest.update(id=second.name, order_id=456, identity_checksum=w.hashlib.sha256(identity).hexdigest())
        (second/'identity.img').write_bytes(identity)
        (second/'manifest.json').write_text(json.dumps(manifest))
        with w.concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            futures = [pool.submit(w.run_job, inbox) for inbox in [self.inbox, second]]
            for future in futures: future.result()
        for inbox, order_id in [(self.inbox,123),(second,456)]:
            output = w.SPOOL/'outbox'/inbox.name
            self.assertEqual('completed',json.loads((output/'status.json').read_text())['status'])
            for n in range(1,14):
                meta = json.loads((output/f'scene-{n:02d}.json').read_text())
                self.assertEqual(inbox.name,meta['id']); self.assertEqual(order_id,meta['order_id'])
                self.assertEqual(w.hashlib.sha256((inbox/'identity.img').read_bytes()).hexdigest(),meta['identity_checksum'])
            self.assertEqual((inbox/'identity.img').read_bytes(),(w.HOME/'jobs'/inbox.name/'identity.png').read_bytes())

    def test_dispatch_does_not_double_submit_active_work_and_caps_two(self):
        from concurrent.futures import Future
        from unittest.mock import Mock
        pool = Mock(); pool.submit.side_effect = lambda *args: Future()
        active = {}
        w.dispatch(pool, active); w.dispatch(pool, active)
        self.assertEqual(1, pool.submit.call_count)
        for n in [2,3]:
            inbox = w.SPOOL/'inbox'/f'00000000-0000-4000-8000-{n:012d}'; inbox.mkdir()
            (inbox/'manifest.json').write_text('{}')
        w.dispatch(pool, active)
        self.assertEqual(2,len(active)); self.assertEqual(2,pool.submit.call_count)

    def test_thirteen_outputs_one_call_each_and_restart_never_replays(self):
        w.run_job(self.inbox)
        self.assertEqual('completed',self.state())
        self.assertEqual(13,len(list((w.SPOOL/'outbox'/self.id).glob('scene-*.png'))))
        clock = json.loads((w.SPOOL/'outbox'/self.id/'status.json').read_text())['timing']
        self.assertGreater(clock['finished_at'], clock['started_at'])
        self.assertGreater(clock['elapsed_seconds'], 0)
        calls=(w.HOME/'jobs'/self.id/'calls').read_text()
        w.run_job(self.inbox)
        self.assertEqual(calls,(w.HOME/'jobs'/self.id/'calls').read_text())
        self.assertEqual(clock, json.loads((w.SPOOL/'outbox'/self.id/'status.json').read_text())['timing'])

    def test_uncertain_generation_not_retried(self):
        (w.HOME/'fail').touch(); w.run_job(self.inbox); w.run_job(self.inbox)
        self.assertEqual('uncertain',self.state())
        self.assertEqual('1\n',(w.HOME/'jobs'/self.id/'calls').read_text())

    def test_duplicate_output_stops_for_review(self):
        (w.HOME/'duplicate').touch(); w.run_job(self.inbox)
        self.assertEqual('quality_review',self.state())
        self.assertEqual('1\n2\n',(w.HOME/'jobs'/self.id/'calls').read_text())

    def test_known_quality_failure_is_specific_and_never_retried(self):
        (w.HOME/'low-res').touch(); w.run_job(self.inbox); w.run_job(self.inbox)
        self.assertEqual('quality_review', self.state())
        state = json.loads((w.SPOOL/'outbox'/self.id/'status.json').read_text())
        self.assertEqual('invalid_dimensions', state['code'])
        clock = state['timing']
        (self.inbox/'cancel').touch(); w.run_job(self.inbox)
        self.assertEqual(clock, json.loads((w.SPOOL/'outbox'/self.id/'status.json').read_text())['timing'])
        self.assertEqual('1\n', (w.HOME/'jobs'/self.id/'calls').read_text())
        self.assertFalse((w.SPOOL/'outbox'/self.id/'scene-01.png').exists())

    def test_interrupted_worker_does_not_restart_generation(self):
        w.atomic(w.SPOOL/'outbox'/self.id/'status.json',{'id':self.id,'status':'running','timing':{'started_at':123,'elapsed_seconds':1}})
        w.run_job(self.inbox); self.assertEqual('uncertain',self.state())
        self.assertFalse((w.HOME/'jobs').exists())
        timing = json.loads((w.SPOOL/'outbox'/self.id/'status.json').read_text())['timing']
        self.assertEqual({'started_at':123,'elapsed_seconds':1},timing)
        self.assertNotIn('finished_at', timing)

    def test_cancel_acknowledged_without_manifest(self):
        (self.inbox/'manifest.json').unlink(); (self.inbox/'cancel').touch()
        w.run_job(self.inbox); self.assertEqual('stopped',self.state())

    def test_input_symlink_rejected(self):
        (self.inbox/'identity.img').unlink()
        (self.inbox/'identity.img').symlink_to('/etc/passwd')
        w.run_job(self.inbox); self.assertEqual('uncertain',self.state())

    def resume_inbox(self, count=2):
        w.run_job(self.inbox)
        new = w.SPOOL/'inbox'/'00000000-0000-4000-8000-000000000002'; new.mkdir()
        manifest = json.loads((self.inbox/'manifest.json').read_text())
        manifest.update(id=new.name, protocol=3, resume_from=self.id, retained_scenes=[])
        (new/'identity.img').write_bytes((self.inbox/'identity.img').read_bytes())
        for n in range(1,count+1):
            source=w.SPOOL/'outbox'/self.id/f'scene-{n:02d}.png'
            (new/source.name).write_bytes(source.read_bytes())
            manifest['retained_scenes'].append({'scene':n,'sha256':w.hashlib.sha256(source.read_bytes()).hexdigest()})
        (new/'manifest.json').write_text(json.dumps(manifest))
        return new

    def test_explicit_resume_keeps_previous_bytes_and_generates_only_remaining_once(self):
        new=self.resume_inbox(11)
        prior=(w.SPOOL/'outbox'/self.id/'status.json').read_bytes()
        w.run_job(new); w.run_job(new)
        work=w.HOME/'jobs'/new.name
        self.assertEqual('12\n13\n',(work/'calls').read_text())
        self.assertEqual(prior,(w.SPOOL/'outbox'/self.id/'status.json').read_bytes())
        for n in range(1,12):
            self.assertEqual((new/f'scene-{n:02d}.png').read_bytes(),(work/f'scene-{n:02d}.png').read_bytes())
        self.assertEqual(2,len(list((w.SPOOL/'outbox'/new.name).glob('scene-*.png'))))

    def test_resume_corrupt_retained_image_never_calls_provider(self):
        new=self.resume_inbox()
        (new/'scene-02.png').write_bytes((new/'scene-01.png').read_bytes())
        w.run_job(new)
        self.assertEqual('uncertain',json.loads((w.SPOOL/'outbox'/new.name/'status.json').read_text())['status'])
        self.assertFalse((w.HOME/'jobs'/new.name/'calls').exists())

    def test_no_api_keys_or_application_credentials_in_child_environment(self):
        with patch.dict(os.environ,{'OPENAI_API_KEY':'secret','AWS_SECRET_ACCESS_KEY':'secret','DB_PASSWORD':'secret'}):
            self.assertFalse(set(w.environment()) & {'OPENAI_API_KEY','AWS_SECRET_ACCESS_KEY','DB_PASSWORD'})

    def test_no_auth_means_no_run(self):
        with patch.object(w,'authenticated',lambda:False): w.run_job(self.inbox)
        self.assertFalse((w.SPOOL/'outbox'/self.id/'status.json').exists())


if __name__ == '__main__': unittest.main()
