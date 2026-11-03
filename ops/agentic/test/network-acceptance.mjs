// Run inside the actual confined container; no credentials or inference calls.
import assert from 'node:assert/strict';
import { createConnection } from 'node:net';
import { verifyIsolation } from '/opt/herokid-agentic/isolation.mjs';

async function connect(host, port, request = null) {
  return new Promise(resolve => {
    let done = false;
    const socket = createConnection({host, port});
    const finish = value => { if (!done) { done = true; socket.destroy(); resolve(value); } };
    socket.setTimeout(12000, () => finish('timeout'));
    socket.on('error', () => finish('denied'));
    socket.on('connect', () => request ? socket.write(request) : finish('connected'));
    socket.on('data', bytes => finish(bytes.toString().split('\r\n')[0]));
  });
}

assert.equal((await verifyIsolation()).verified, true);
const direct = {};
for (const [host, port] of [['1.1.1.1', 443], ['169.254.169.254', 80], ['10.0.0.1', 3306]]) {
  direct[host] = await connect(host, port);
  assert.notEqual(direct[host], 'connected', 'Direct network path unexpectedly open');
}
const denied = [];
for (const target of ['example.com:443', 'api.openai.com:443', '169.254.169.254:443', '127.0.0.1:443', 'chatgpt.com:80', 'chatgpt.com.example.com:443']) {
  const status = await connect('herokid-autopilot-egress', 3128, `CONNECT ${target} HTTP/1.1\r\nHost: ${target}\r\n\r\n`);
  assert.match(status, /^HTTP\/1\.1 403 /, target);
  denied.push(target);
}
const allowed = [];
for (const host of ['chatgpt.com', 'auth.openai.com']) {
  const status = await connect('herokid-autopilot-egress', 3128, `CONNECT ${host}:443 HTTP/1.1\r\nHost: ${host}:443\r\n\r\n`);
  assert.match(status, /^HTTP\/1\.1 200 /, host);
  allowed.push(host);
}
assert.match(await connect('herokid-autopilot-egress', 3128, 'GET http://chatgpt.com/ HTTP/1.1\r\nHost: chatgpt.com\r\n\r\n'), /^HTTP\/1\.1 403 /);
console.log(JSON.stringify({direct_paths: direct, forbidden_tunnels: denied, subscription_tunnels: allowed, general_http: 'denied', inference_calls: 0}));
