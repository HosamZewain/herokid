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
        (self.inbox / 'manifest.json').write_text(json.dumps({'id':self.id,'scene_count':13,'prompt':'synthetic',
            'identity_checksum':w.hashlib.sha256(b'synthetic-reference').hexdigest(),
            'texts':[{'scene_number':n,'text':'synthetic'} for n in range(1,14)]}))
        self.sleep = patch.object(w.time,'sleep',lambda _: None); self.sleep.start()
        self.health = patch.object(w,'heartbeat',lambda: None); self.health.start()

    def tearDown(self):
        self.sleep.stop(); self.health.stop(); self.temp.cleanup()

    def state(self):
        return json.loads((w.SPOOL / 'outbox' / self.id / 'status.json').read_text())['status']

    def test_thirteen_outputs_one_call_each_and_restart_never_replays(self):
        w.run_job(self.inbox)
        self.assertEqual('completed',self.state())
        self.assertEqual(13,len(list((w.SPOOL/'outbox'/self.id).glob('scene-*.png'))))
        calls=(w.HOME/'jobs'/self.id/'calls').read_text()
        w.run_job(self.inbox)
        self.assertEqual(calls,(w.HOME/'jobs'/self.id/'calls').read_text())

    def test_uncertain_generation_not_retried(self):
        (w.HOME/'fail').touch(); w.run_job(self.inbox); w.run_job(self.inbox)
        self.assertEqual('uncertain',self.state())
        self.assertEqual('1\n',(w.HOME/'jobs'/self.id/'calls').read_text())

    def test_duplicate_output_stops_for_review(self):
        (w.HOME/'duplicate').touch(); w.run_job(self.inbox)
        self.assertEqual('uncertain',self.state())
        self.assertEqual('1\n2\n',(w.HOME/'jobs'/self.id/'calls').read_text())

    def test_interrupted_worker_does_not_restart_generation(self):
        w.atomic(w.SPOOL/'outbox'/self.id/'status.json',{'id':self.id,'status':'running'})
        w.run_job(self.inbox); self.assertEqual('uncertain',self.state())
        self.assertFalse((w.HOME/'jobs').exists())

    def test_cancel_acknowledged_without_manifest(self):
        (self.inbox/'manifest.json').unlink(); (self.inbox/'cancel').touch()
        w.run_job(self.inbox); self.assertEqual('stopped',self.state())

    def test_input_symlink_rejected(self):
        (self.inbox/'identity.img').unlink()
        (self.inbox/'identity.img').symlink_to('/etc/passwd')
        w.run_job(self.inbox); self.assertEqual('uncertain',self.state())

    def test_no_api_keys_or_application_credentials_in_child_environment(self):
        with patch.dict(os.environ,{'OPENAI_API_KEY':'secret','AWS_SECRET_ACCESS_KEY':'secret','DB_PASSWORD':'secret'}):
            self.assertFalse(set(w.environment()) & {'OPENAI_API_KEY','AWS_SECRET_ACCESS_KEY','DB_PASSWORD'})

    def test_no_auth_means_no_run(self):
        with patch.object(w,'authenticated',lambda:False): w.run_job(self.inbox)
        self.assertFalse((w.SPOOL/'outbox'/self.id/'status.json').exists())


if __name__ == '__main__': unittest.main()
