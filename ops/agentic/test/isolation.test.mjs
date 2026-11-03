import test from 'node:test';
import assert from 'node:assert/strict';
import { assessIsolation, isSubscriptionAuth, isSubscriptionStatus, subscriptionStatusArguments } from '../isolation.mjs';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
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
test('pinned CLI accepts the subscription status command and an empty home stays unauthenticated', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'herokid-login-status-'));
  try {
    const execute = promisify(execFile);
    const options = {env: isolatedEnvironment(directory, tmpdir()), timeout: 5000};
    const help = await execute(process.execPath, [...subscriptionStatusArguments(), '--help'], options);
    assert.match(help.stdout, /Show login status/);
    await assert.rejects(execute(process.execPath, subscriptionStatusArguments(), options), error => {
      assert.equal(error.code, 1);
      assert.match(error.stderr, /Not logged in/);
      assert.equal(isSubscriptionStatus(error), false);
      return true;
    });
  } finally { await rm(directory, {recursive: true, force: true}); }
});
test('subscription status admits only its exact read-only helper warning and ChatGPT status', () => {
  const status = 'Logged in using ChatGPT';
  const warning = 'WARNING: proceeding, even though we could not create PATH aliases: Read-only file system (os error 30)';
  for (const result of [{stdout: '', stderr: status}, {stdout: status, stderr: warning}, {stdout: '', stderr: `${warning}\n${status}`}]) {
    assert.equal(isSubscriptionStatus(result), true);
  }
  for (const stderr of ['', 'Not logged in', 'Logged in using an API key', `${status}\nError loading configuration`,
    `${warning}\n${warning}\n${status}`, `${status}\nWARNING: unknown auth warning`, `${status}\n${status}`]) {
    assert.equal(isSubscriptionStatus({stdout: '', stderr}), false);
  }
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
