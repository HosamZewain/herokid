import test from 'node:test';
import assert from 'node:assert/strict';
import { assessIsolation, isSubscriptionAuth, isSubscriptionStatus, subscriptionStatusArguments } from '../isolation.mjs';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { mkdtemp, rm, readFile, writeFile, unlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { consumeDecision, DecisionRuntime } from '../runtime.mjs';
import { configuration, isolatedEnvironment } from '../runtime-policy.mjs';
import { probeTools } from '../probe-tools.mjs';
import { createDecisionWorkspace, credentialReferenceIntact, removeDecisionWorkspace } from '../workspace.mjs';
import { runDecisionStream } from '../decision-stream.mjs';
import { hasDirectDefaultRoute } from '../network-isolation.mjs';

const mount = (path, mode = 'ro') => `1 0 0:1 / ${path} ${mode} - tmpfs tmpfs ${mode}`;
const confined = { platform: 'linux', uid: 1000, rootPath: '/opt/herokid-agentic',
  status: 'NoNewPrivs:\t1\nCapEff:\t0000000000000000\nSeccomp:\t2\n',
  mounts: [mount('/'), mount('/run/agentic-auth'), mount('/tmp', 'rw'), mount('/run/bridge', 'rw')].join('\n') };

test('OS guard refuses privilege, source mounts, host home, writable root and missing auth boundary', () => {
  assert.equal(assessIsolation(confined).verified, true);
  assert.equal(assessIsolation({...confined, mounts: confined.mounts + '\n' + mount('/usr/sbin/docker-init')}).verified, true);
  for (const change of [{uid: 0}, {platform: 'darwin'}, {status: 'NoNewPrivs:\t0'},
    {mounts: confined.mounts + '\n' + mount('/app')}, {mounts: confined.mounts + '\n' + mount('/root/.codex')},
    {mounts: confined.mounts.replace('/ ro ', '/ rw ')}, {mounts: mount('/')},
    {mounts: confined.mounts + '\n' + mount('/usr/sbin/docker-init', 'rw')}]) {
    assert.equal(assessIsolation({...confined, ...change}).verified, false);
  }
});
test('SDK environment is an explicit allowlist and API authentication cannot substitute', () => {
  process.env.OPENAI_API_KEY = 'synthetic-must-not-inherit'; process.env.APP_KEY = 'synthetic-application-secret';
  process.env.HTTPS_PROXY = 'http://untrusted.invalid:1234'; process.env.NO_PROXY = '*';
  const env = isolatedEnvironment('/dedicated-auth', '/scratch');
  assert.equal(env.OPENAI_API_KEY, undefined); assert.equal(env.APP_KEY, undefined);
  assert.equal(env.HTTPS_PROXY, 'http://herokid-autopilot-egress:3128');
  assert.equal(env.NO_PROXY, '127.0.0.1,localhost');
  assert.equal(configuration.forced_login_method, 'chatgpt');
  assert.equal(isSubscriptionAuth({auth_mode: 'api', OPENAI_API_KEY: 'synthetic'}), false);
  assert.equal(isSubscriptionAuth({auth_mode: 'chatgpt', tokens: {access_token: 'synthetic'}, OPENAI_API_KEY: 'synthetic'}), false);
  assert.equal(isSubscriptionAuth({auth_mode: 'chatgpt', tokens: {access_token: 'synthetic'}}), true);
});
test('runtime network guard rejects direct IPv4 and IPv6 routes', () => {
  const header = 'Iface Destination Gateway Flags RefCnt Use Metric Mask MTU Window IRTT\n';
  assert.equal(hasDirectDefaultRoute(header + 'eth0 000015AC 00000000 0001 0 0 0 0000FFFF 0 0 0', ''), false);
  assert.equal(hasDirectDefaultRoute(header + 'eth0 00000000 010015AC 0003 0 0 0 00000000 0 0 0', ''), true);
  assert.equal(hasDirectDefaultRoute(header, '00000000000000000000000000000000 00 00000000000000000000000000000000 00 fe800000000000000000000000000001 00000400 00000000 00000000 00000003 eth0'), true);
  assert.equal(hasDirectDefaultRoute(header, '00000000000000000000000000000000 00 00000000000000000000000000000000 00 00000000000000000000000000000000 ffffffff 00000001 00000000 00200200 lo'), false);
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
test('pinned CLI accepts the complete production configuration without provider overrides', async () => {
  const workspace = await createDecisionWorkspace();
  const argumentsFor = (value, prefix = '') => Object.entries(value).flatMap(([key, item]) => {
    const path = prefix ? `${prefix}.${key}` : key;
    return item && typeof item === 'object' && ! Array.isArray(item)
      ? argumentsFor(item, path) : ['-c', `${path}=${JSON.stringify(item)}`];
  });
  try {
    const result = await promisify(execFile)(process.execPath,
      [subscriptionStatusArguments()[0], ...argumentsFor(configuration), 'features', 'list'],
      {env: isolatedEnvironment(workspace.home, workspace.directory), timeout: 5000});
    assert.match(result.stdout, /shell_tool\s+\S+\s+false/);
  } finally { await removeDecisionWorkspace(workspace); }
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
test('ephemeral CLI state references credentials without copying and rejects replacement', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'herokid-auth-fixture-'));
  const credential = join(directory, 'auth.json');
  await writeFile(credential, 'synthetic-only', {mode: 0o400});
  const workspace = await createDecisionWorkspace(credential);
  try {
    assert.notEqual(workspace.home, workspace.work);
    assert.equal(await credentialReferenceIntact(workspace), true);
    assert.equal(await readFile(join(workspace.home, 'auth.json'), 'utf8'), 'synthetic-only');
    await unlink(join(workspace.home, 'auth.json'));
    await writeFile(join(workspace.home, 'auth.json'), 'replacement');
    assert.equal(await credentialReferenceIntact(workspace), false);
    await removeDecisionWorkspace(workspace);
    assert.equal(await readFile(credential, 'utf8'), 'synthetic-only');
  } finally { await removeDecisionWorkspace(workspace); await rm(directory, {recursive: true, force: true}); }
});
test('real CLI reaches only the fake provider with linked credentials and the production schema', {timeout: 30000}, async () => {
  const directory = await mkdtemp(join(tmpdir(), 'herokid-auth-fixture-'));
  const credential = join(directory, 'auth.json');
  const value = JSON.stringify({auth_mode: 'apikey', OPENAI_API_KEY: 'synthetic-local-only'});
  await writeFile(credential, value, {mode: 0o400});
  try {
    const result = await probeTools(null, credential);
    assert.equal(result.verified, true);
    assert.equal(result.credential_reference_preserved, true);
    assert.deepEqual(result.tools, []);
    assert.equal(await readFile(credential, 'utf8'), value);
  } finally { await rm(directory, {recursive: true, force: true}); }
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
test('only the exact pinned disabled-code-mode diagnostic is tolerated', async () => {
  const warning = 'Code Mode is unavailable because code-mode host is disabled. Code mode will fail closed; enable `features.code_mode_host` and install `codex-code-mode-host`.';
  const events = async function* (message) {
    yield {type: 'item.completed', item: {type: 'error', message}};
    yield {type: 'item.completed', item: {type: 'agent_message', text: '{}'}};
    yield {type: 'turn.completed', usage: {input_tokens: 1, output_tokens: 1}};
  };
  assert.deepEqual((await consumeDecision(events(warning))).decision, {});
  for (const message of ['other CLI failure', `${warning} Additional instructions`]) {
    await assert.rejects(consumeDecision(events(message)), /TOOL_EVENT_REJECTED/);
  }
});
test('stream rejection aborts before SDK cleanup and successful completion does not abort afterward', async () => {
  for (const rejected of [true, false]) {
    let signal, closed = false, abortedWhileOpen = false;
    const thread = {async runStreamed(prompt, options) {
      signal = options.signal;
      signal.addEventListener('abort', () => { assert.equal(closed, false); abortedWhileOpen = true; });
      async function* events() {
        try {
          if (rejected) yield {type: 'turn.failed'};
          else {
            yield {type: 'item.completed', item: {type: 'agent_message', text: '{}'}};
            yield {type: 'turn.completed', usage: {input_tokens: 1, output_tokens: 1}};
          }
        } finally { closed = true; }
      }
      return {events: events()};
    }};
    if (rejected) await assert.rejects(runDecisionStream(thread, 'synthetic', {}, 1000), /PROVIDER_RESULT_UNCERTAIN/);
    else assert.deepEqual((await runDecisionStream(thread, 'synthetic', {}, 1000)).decision, {});
    assert.equal(closed, true);
    assert.equal(abortedWhileOpen, rejected);
    assert.equal(signal.aborted, rejected);
  }
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
