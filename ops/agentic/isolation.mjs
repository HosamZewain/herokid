import { readFile, readdir } from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createHash } from 'node:crypto';
import { resolve } from 'node:path';
import { root, version, configuration, isolatedEnvironment, networkProfile } from './runtime-policy.mjs';
import { hasDirectDefaultRoute } from './network-isolation.mjs';

export const authDirectory = '/run/agentic-auth';
export const socketPath = '/run/bridge/decision.sock';
const allowedWritable = new Set(['/tmp', '/run/bridge', '/dev', '/dev/pts', '/dev/mqueue', '/dev/shm', '/proc', '/proc/interrupts', '/proc/kcore', '/proc/keys', '/proc/latency_stats', '/proc/timer_list']);
const allowedMounts = new Set(['/', '/tmp', '/run/bridge', authDirectory, '/etc/resolv.conf', '/etc/hosts', '/etc/hostname', '/usr/sbin/docker-init']);

export function assessIsolation({ platform, uid, status, mounts, rootPath }) {
  const errors = [];
  if (platform !== 'linux' || uid === 0 || uid == null) errors.push('NON_ROOT_LINUX_REQUIRED');
  if (! /^NoNewPrivs:\s+1$/m.test(status) || ! /^CapEff:\s+0+$/m.test(status) || ! /^Seccomp:\s+2$/m.test(status)) errors.push('OS_CONFINEMENT_REQUIRED');
  const rows = mounts.trim().split('\n').map(line => { const fields = line.split(' '); return { path: fields[4], options: fields[5]?.split(',') ?? [] }; });
  if (! rows.some(m => m.path === '/' && m.options.includes('ro'))) errors.push('READ_ONLY_ROOT_REQUIRED');
  if (! rows.some(m => m.path === authDirectory && m.options.includes('ro'))) errors.push('READ_ONLY_AUTH_REQUIRED');
  for (const mount of rows) {
    const system = mount.path?.startsWith('/proc/') || mount.path?.startsWith('/sys/') || ['/proc', '/sys', '/dev', '/dev/pts', '/dev/mqueue', '/dev/shm'].includes(mount.path);
    if (! allowedMounts.has(mount.path) && ! system) errors.push('UNEXPECTED_MOUNT');
    if (mount.options.includes('rw') && ! allowedWritable.has(mount.path)) errors.push('UNEXPECTED_WRITABLE_MOUNT');
  }
  if (rootPath !== '/opt/herokid-agentic') errors.push('UNTRUSTED_CODE_LOCATION');
  return { verified: errors.length === 0, errors: [...new Set(errors)] };
}

export async function verifyIsolation() {
  try {
    const result = assessIsolation({ platform: process.platform, uid: process.getuid?.(), rootPath: root,
      status: await readFile('/proc/self/status', 'utf8'), mounts: await readFile('/proc/self/mountinfo', 'utf8') });
    if (hasDirectDefaultRoute(await readFile('/proc/net/route', 'utf8'), await readFile('/proc/net/ipv6_route', 'utf8'))) {
      result.verified = false;
      result.errors.push('DIRECT_NETWORK_ROUTE_FORBIDDEN');
    }
    return { ...result, digest: createHash('sha256').update(JSON.stringify({ version, configuration, networkProfile, result })).digest('hex') };
  } catch { return { verified: false, errors: ['ISOLATION_UNVERIFIED'] }; }
}

export function isSubscriptionAuth(value) {
  return value?.auth_mode === 'chatgpt' && ! value.OPENAI_API_KEY && typeof value.tokens?.access_token === 'string' && value.tokens.access_token.length > 0;
}

export function subscriptionStatusArguments() {
  // --ignore-user-config belongs to `exec`, not `login`. This separate login
  // check has an allowlisted environment and a home containing only auth.json.
  return [resolve(root, 'node_modules/@openai/codex/bin/codex.js'),
    '-c', 'forced_login_method="chatgpt"', '-c', 'cli_auth_credentials_store="file"', 'login', 'status'];
}

export function isSubscriptionStatus(result) {
  // The pinned CLI attempts PATH-helper setup before `login status`. Denying
  // that write is intentional; admit this exact warning, never arbitrary stderr.
  const readOnlyWarning = 'WARNING: proceeding, even though we could not create PATH aliases: Read-only file system (os error 30)';
  const lines = `${result.stdout}\n${result.stderr}`.trim().split('\n').map(line => line.trim()).filter(Boolean);
  if (lines.filter(line => line === readOnlyWarning).length > 1) return false;
  return lines.filter(line => line !== readOnlyWarning).join('\n') === 'Logged in using ChatGPT';
}

export async function verifyAuthentication() {
  try {
    // Only this dedicated credential directory is admitted; never the desktop home.
    const files = await readdir(authDirectory);
    if (files.length !== 1 || files[0] !== 'auth.json') return false;
    if (! isSubscriptionAuth(JSON.parse(await readFile(`${authDirectory}/auth.json`, 'utf8')))) return false;
    const result = await promisify(execFile)(process.execPath,
      subscriptionStatusArguments(),
      { env: isolatedEnvironment(authDirectory, '/tmp'), timeout: 5000, maxBuffer: 8192 });
    return isSubscriptionStatus(result);
  } catch { return false; }
}
