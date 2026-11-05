"""Synthetic host metadata: never reads Docker, credentials or production state."""
import copy
import importlib.util
import pathlib
import unittest

spec = importlib.util.spec_from_file_location('attestation', pathlib.Path(__file__).parents[1] / 'deploy/verify-runtime.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class HostAttestationTest(unittest.TestCase):
    def setUp(self):
        self.image = 'sha256:' + 'a' * 64
        self.egress_image = 'sha256:' + 'b' * 64
        def container(identity, image, memory, pids, cpus):
            return {'Id': identity, 'Image': image, 'State': {'Running': True},
                    'Config': {'User': '1000:1000', 'Env': ['NODE_ENV=production'], 'Cmd': ['node', 'assistant-bridge.mjs'],
                               'WorkingDir': '/opt/herokid-agentic', 'Entrypoint': ['docker-entrypoint.sh']},
                    'HostConfig': {'ReadonlyRootfs': True, 'Privileged': False, 'CapDrop': ['ALL'],
                                   'SecurityOpt': ['no-new-privileges:true'], 'Init': True, 'PidsLimit': pids,
                                   'Memory': memory * 1024**2, 'NanoCpus': cpus, 'RestartPolicy': {'Name': 'no'},
                                   'Tmpfs': {'/tmp': f'rw,noexec,nosuid,size={memory // 4}m'}},
                    'Mounts': [], 'NetworkSettings': {'Networks': {'private': {}}}}
        self.decision = container('decision', self.image, 512, 128, 500_000_000)
        self.decision['Mounts'] = [{'Destination': '/run/agentic-auth', 'Source': '/auth', 'RW': False},
                                   {'Destination': '/run/bridge', 'Source': '/socket', 'RW': True}]
        self.egress = container('egress', self.egress_image, 128, 32, 250_000_000)
        self.egress['Config'].update(User='13:13', Cmd=['/usr/sbin/squid', '-N', '-f', '/etc/squid/squid.conf'], Entrypoint=None)
        self.egress['HostConfig']['Tmpfs']['/tmp'] = 'rw,noexec,nosuid,size=16m'
        self.egress['NetworkSettings']['Networks']['outbound'] = {}
        self.network = {'Name': 'private', 'Internal': True, 'EnableIPv6': False,
                        'Options': {'com.docker.network.bridge.gateway_mode_ipv4': 'isolated'},
                        'Containers': {'decision': {}, 'egress': {}}}

    def verify(self, purpose='assistant'):
        return module.verify(self.decision, self.egress, self.network, self.image, self.egress_image, '/auth', '/socket', purpose)

    def test_assistant_and_original_decision_profiles_are_distinct(self):
        self.assertTrue(self.verify()['verified'])
        with self.assertRaises(RuntimeError): self.verify('decision')
        self.decision['Config']['Cmd'] = ['node', 'bridge.mjs']
        self.assertTrue(self.verify('decision')['verified'])
        with self.assertRaises(RuntimeError): self.verify()

    def test_unknown_purpose_is_rejected(self):
        with self.assertRaises(RuntimeError): self.verify('arbitrary')

    def test_unexpected_network_peer_is_rejected(self):
        self.network['Containers']['app'] = {}
        with self.assertRaises(RuntimeError): self.verify()

    def test_extra_mount_is_rejected(self):
        self.decision['Mounts'].append({'Destination': '/app', 'Source': '/app', 'RW': False})
        with self.assertRaises(RuntimeError): self.verify()

    def test_direct_egress_is_rejected(self):
        self.decision['NetworkSettings']['Networks']['outbound'] = {}
        with self.assertRaises(RuntimeError): self.verify()

    def test_credentials_and_writeable_auth_are_rejected(self):
        original = copy.deepcopy(self.decision)
        self.decision['Config']['Env'].append('OPENAI_API_KEY=synthetic')
        with self.assertRaises(RuntimeError): self.verify()
        self.decision = original
        self.decision['Mounts'][0]['RW'] = True
        with self.assertRaises(RuntimeError): self.verify()


if __name__ == '__main__':
    unittest.main()
