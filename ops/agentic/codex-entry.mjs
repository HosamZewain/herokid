#!/usr/bin/env node
import { spawn } from 'node:child_process';
import { resolve } from 'node:path';
import { root } from './runtime-policy.mjs';

const args = process.argv.slice(2);
if (args[0] !== 'exec' || args.some(arg => ['--yolo', '--dangerously-bypass-approvals-and-sandbox', '--full-auto', '--image', '-i', '--add-dir', 'resume'].includes(arg))) {
  process.exit(64);
}
// The SDK has no first-class ignore-user-config/ephemeral options in 0.166.0.
const child = spawn(process.execPath, [resolve(root, 'node_modules/@openai/codex/bin/codex.js'),
  'exec', '--ignore-user-config', '--ignore-rules', '--ephemeral', ...args.slice(1)], { env: process.env, stdio: 'inherit' });
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => child.kill(signal));
child.on('error', () => process.exit(70));
child.on('exit', code => process.exit(code ?? 70));
