import { createServer } from 'node:net';
import { chmod } from 'node:fs/promises';
import { DecisionRuntime } from './runtime.mjs';
import { socketPath } from './isolation.mjs';

const runtime = new DecisionRuntime();
// No authentication or provider calls in this local capability probe.
await runtime.initialize();
const server = createServer(socket => {
  let body = '', bytes = 0, received = false;
  socket.setTimeout(85000, () => socket.destroy());
  socket.on('error', () => {});
  socket.on('data', async chunk => {
    if (received) return socket.destroy();
    bytes += chunk.length;
    if (bytes > 196608) return socket.destroy();
    body += chunk.toString('utf8');
    if (! body.includes('\n')) return;
    received = true;
    try {
      if (! body.endsWith('\n') || body.indexOf('\n') !== body.length - 1) throw new Error('INVALID_INPUT');
      const request = JSON.parse(body);
      const result = request.operation === 'health' ? await runtime.health()
        : request.operation === 'decide' ? await runtime.decide(request.input) : { error: 'UNKNOWN_OPERATION' };
      socket.end(JSON.stringify(result) + '\n');
    } catch {
      // Provider errors may contain transcripts/auth data: never return or log them.
      socket.end(JSON.stringify({ error: 'CODEX_RUNTIME_REFUSED_OR_UNCERTAIN' }) + '\n');
    }
  });
});
// Do not unlink an existing socket; it can belong to another running bridge.
server.listen(socketPath, async () => { await chmod(socketPath, 0o660); });
