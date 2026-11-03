// Local container acceptance probe only; contains no application or login credentials.
import assert from 'node:assert/strict';
import { writeFile, access, readFile } from 'node:fs/promises';
import { verifyIsolation } from './isolation.mjs';
import { probeTools } from './probe-tools.mjs';
import { DecisionRuntime } from './runtime.mjs';

const isolation = await verifyIsolation();
assert.equal(isolation.verified, true, JSON.stringify(isolation.errors));
assert.match(await readFile('/etc/ssl/certs/ca-certificates.crt', 'utf8'), /-----BEGIN CERTIFICATE-----/);
await assert.rejects(writeFile('/opt/herokid-agentic/forbidden.txt', 'must fail'));
await assert.rejects(access('/var/www/herokid/.env'));
await assert.rejects(access('/var/run/docker.sock'));
for (const tool of ['exec_command', 'shell', 'shell_command', 'read_file', 'apply_patch', 'apply_patch_custom']) {
  const result = await probeTools(tool);
  assert.equal(result.verified, true, tool); assert.equal(result.rejected_tool, true, tool);
  assert.equal(result.sentinel_unchanged, true, tool); assert.equal(result.secret_leaked, false, tool);
}
const runtime = new DecisionRuntime();
await runtime.initialize();
const health = await runtime.health();
assert.equal(health.ready, false); assert.equal(health.authentication, 'unverified');
await assert.rejects(runtime.decide({remaining_targets: ['story:1']}), /SUBSCRIPTION_AUTH_UNVERIFIED/);
console.log(JSON.stringify({os_isolation: true, hostile_tool_probes: 6, filesystem_write_denied: true,
  application_secrets_absent: true, system_ca_bundle_present: true, subscription_unverified_refuses_calls: true, paid_api_fallback: false}));
