import test from 'node:test';
import assert from 'node:assert/strict';
import { assessIsolation, isSubscriptionAuth } from '../isolation.mjs';
import { consumeDecision, DecisionRuntime } from '../runtime.mjs';
import { configuration, isolatedEnvironment } from '../runtime-policy.mjs';
import { probeTools } from '../probe-tools.mjs';

const mount = (path, mode = 'ro') => `1 0 0:1 / ${path} ${mode} - tmpfs tmpfs ${mode}`;
const confined = { platform: 'linux', uid: 1000, rootPath: '/opt/herokid-agentic',
  status: 'NoNewPrivs:\t1\nCapEff:\t0000000000000000\nSeccomp:\t2\n',
  mounts: [mount('/'), mount('/run/agentic-auth'), mount('/tmp', 'rw'), mount('/run/bridge', 'rw')].join('\n') };

test('OS guard refuses privilege, source mounts, host home, writable root and missing auth boundary', () => {
  assert.equal(assessIsolation(confined).verified, true);
  for (const change of [{uid: 0}, {platform: 'darwin'}, {status: 'NoNewPrivs:\t0'},
    {mounts: confined.mounts + '\n' + mount('/app')}, {mounts: confined.mounts + '\n' + mount('/root/.codex')},
    {mounts: confined.mounts.replace('/ ro ', '/ rw ')}, {mounts: mount('/')}]) {
    assert.equal(assessIsolation({...confined, ...change}).verified, false);
  }
});
test('SDK environment is an explicit allowlist and API authentication cannot substitute', () => {
  process.env.OPENAI_API_KEY = 'synthetic-must-not-inherit'; process.env.APP_KEY = 'synthetic-application-secret';
  const env = isolatedEnvironment('/dedicated-auth', '/scratch');
  assert.equal(env.OPENAI_API_KEY, undefined); assert.equal(env.APP_KEY, undefined);
  assert.equal(configuration.forced_login_method, 'chatgpt');
  assert.equal(isSubscriptionAuth({auth_mode: 'api', OPENAI_API_KEY: 'synthetic'}), false);
  assert.equal(isSubscriptionAuth({auth_mode: 'chatgpt', tokens: {access_token: 'synthetic'}, OPENAI_API_KEY: 'synthetic'}), false);
  assert.equal(isSubscriptionAuth({auth_mode: 'chatgpt', tokens: {access_token: 'synthetic'}}), true);
});
test('tool/file/network events fail closed even if followed by a valid answer', async () => {
  for (const type of ['command_execution', 'file_change', 'mcp_tool_call', 'web_search', 'unknown_new_tool']) {
    async function* events() { yield {type: 'item.started', item: {type}}; yield {type: 'item.completed', item: {type: 'agent_message', text: '{}'}}; }
    await assert.rejects(consumeDecision(events()), /TOOL_EVENT_REJECTED/);
  }
});
test('partial or failed provider answers are never accepted', async () => {
  async function* partial() { yield {type: 'item.completed', item: {type: 'agent_message', text: '{}'}}; }
  async function* failed() { yield {type: 'turn.failed', error: {message: 'synthetic'}}; }
  await assert.rejects(consumeDecision(partial()), /INCOMPLETE_DECISION/);
  await assert.rejects(consumeDecision(failed()), /PROVIDER_RESULT_UNCERTAIN/);
});
test('unverified runtime cannot make a decision or fall back to mock', async () => {
  const runtime = new DecisionRuntime();
  runtime.health = async () => ({ready: false, error: 'SUBSCRIPTION_AUTH_UNVERIFIED'});
  await assert.rejects(runtime.decide({remaining_targets: ['story:1']}), /SUBSCRIPTION_AUTH_UNVERIFIED/);
  assert.equal(runtime.busy, false);
});
test('installed real SDK/CLI advertises zero tools to a local fake provider', {timeout: 30000}, async () => {
  const result = await probeTools(); assert.equal(result.verified, true); assert.deepEqual(result.tools, []);
});
test('real CLI rejects hostile shell and patch tool calls without reading or changing a sentinel', {timeout: 60000}, async () => {
  for (const tool of ['exec_command', 'shell', 'shell_command', 'read_file', 'apply_patch', 'apply_patch_custom']) {
    const result = await probeTools(tool);
    assert.equal(result.verified, true); assert.equal(result.attack_sent, true);
    assert.equal(result.sentinel_unchanged, true); assert.equal(result.secret_leaked, false);
    assert.equal(result.rejected_tool, true);
  }
});
