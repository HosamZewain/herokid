import { readFile, readdir } from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createHash } from 'node:crypto';
import { resolve } from 'node:path';
import { root, version, configuration, isolatedEnvironment } from './runtime-policy.mjs';

export const authDirectory = '/run/agentic-auth';
export const socketPath = '/run/bridge/decision.sock';
const allowedWritable = new Set(['/tmp', '/run/bridge', '/dev', '/dev/pts', '/dev/mqueue', '/dev/shm', '/proc', '/proc/interrupts', '/proc/kcore', '/proc/keys', '/proc/latency_stats', '/proc/timer_list']);
const allowedMounts = new Set(['/', '/tmp', '/run/bridge', authDirectory, '/etc/resolv.conf', '/etc/hosts', '/etc/hostname']);

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
    return { ...result, digest: createHash('sha256').update(JSON.stringify({ version, configuration, result })).digest('hex') };
  } catch { return { verified: false, errors: ['ISOLATION_UNVERIFIED'] }; }
}

export function isSubscriptionAuth(value) {
  return value?.auth_mode === 'chatgpt' && ! value.OPENAI_API_KEY && typeof value.tokens?.access_token === 'string' && value.tokens.access_token.length > 0;
}

export async function verifyAuthentication() {
  try {
    // Only this dedicated credential directory is admitted; never the desktop home.
    const files = await readdir(authDirectory);
    if (files.length !== 1 || files[0] !== 'auth.json') return false;
    if (! isSubscriptionAuth(JSON.parse(await readFile(`${authDirectory}/auth.json`, 'utf8')))) return false;
    const result = await promisify(execFile)(process.execPath,
      [resolve(root, 'node_modules/@openai/codex/bin/codex.js'), '--ignore-user-config', '-c', 'forced_login_method="chatgpt"', 'login', 'status'],
      { env: isolatedEnvironment(authDirectory, '/tmp'), timeout: 5000, maxBuffer: 8192 });
    return `${result.stdout}\n${result.stderr}`.trim() === 'Logged in using ChatGPT';
  } catch { return false; }
}
