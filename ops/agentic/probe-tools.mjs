// Local fake-provider probe. No subscription credentials or paid provider requests.
import { createServer } from 'node:http';
import { readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { Codex } from '@openai/codex-sdk';
import * as zlib from 'node:zlib';
import { configuration, isolatedEnvironment, sdkOptions, threadOptions, version, root } from './runtime-policy.mjs';
import { createDecisionWorkspace, credentialReferenceIntact, removeDecisionWorkspace } from './workspace.mjs';
import { runDecisionStream } from './decision-stream.mjs';

export async function probeTools(attack = null, credentialFile = null) {
  const workspace = await createDecisionWorkspace(credentialFile);
  const {directory, home: auth, work} = workspace;
  const sentinel = join(work, 'protected.txt');
  await writeFile(sentinel, 'synthetic-secret-must-not-leak');
  let inventory = null, diagnostic = null;
  let attackSent = false, rejectedTool = false, secretLeaked = false;
  const feedback = [];
  const requests = [];
  const server = createServer(async (request, response) => {
    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    requests.push({method: request.method, path: request.url, encoding: request.headers['content-encoding'] ?? null});
    if (request.method === 'GET') {
      response.writeHead(200, { 'content-type': 'application/json' });
      response.end(JSON.stringify({object: 'list', data: [{id: 'gpt-6.1-sol', object: 'model', owned_by: 'openai'}]}));
      return;
    }
    try {
      let bytes = Buffer.concat(chunks);
      if (request.headers['content-encoding'] === 'gzip') bytes = zlib.gunzipSync(bytes);
      if (request.headers['content-encoding'] === 'zstd') bytes = zlib.zstdDecompressSync(bytes);
      const payload = JSON.parse(bytes.toString());
      secretLeaked ||= JSON.stringify(payload).includes('synthetic-secret-must-not-leak');
      rejectedTool ||= (payload.input ?? []).some(item => ['function_call_output', 'custom_tool_call_output'].includes(item.type) && /unrecognized|unknown|unsupported|not supported|not found|aborted|rejected by user approval settings/i.test(JSON.stringify(item.output)));
      if (attackSent) feedback.push(...(payload.input ?? []).filter(item => ['function_call_output', 'custom_tool_call_output'].includes(item.type)).map(item => String(item.output).replaceAll(directory, '[scratch]').replaceAll('synthetic-secret-must-not-leak', '[LEAK]').slice(0, 500)));
      requests.at(-1).fields = Object.keys(payload);
      if (Array.isArray(payload.tools)) inventory = payload.tools.map(tool => ({ type: tool.type, name: tool.name ?? tool.function?.name ?? null }));
      else if (typeof payload.model === 'string' && Array.isArray(payload.input) && ! Object.hasOwn(payload, 'tools')) inventory = [];
    } catch { /* Ignore discovery requests. */ }
    if (attack && ! attackSent && inventory !== null) {
      attackSent = true;
      const argumentsValue = attack === 'apply_patch'
        ? JSON.stringify({patch: `*** Begin Patch\n*** Delete File: ${sentinel}\n*** End Patch`})
        : JSON.stringify({cmd: `cat '${sentinel}'; echo changed > '${sentinel}'`, command: `cat '${sentinel}'; echo changed > '${sentinel}'`});
      const custom = attack === 'apply_patch_custom';
      const item = custom ? {id: 'fc_probe', type: 'custom_tool_call', name: 'apply_patch', call_id: 'call_probe', input: `*** Begin Patch\n*** Delete File: ${sentinel}\n*** End Patch`, status: 'completed'}
        : {id: 'fc_probe', type: 'function_call', name: attack, call_id: 'call_probe', arguments: argumentsValue, status: 'completed'};
      const responseValue = {id: 'resp_probe', object: 'response', status: 'completed', output: [item], usage: {input_tokens: 1, output_tokens: 1, total_tokens: 2}};
      response.writeHead(200, {'content-type': 'text/event-stream'});
      for (const event of [
        {type: 'response.created', response: {...responseValue, status: 'in_progress', output: []}},
        {type: 'response.output_item.added', output_index: 0, item: {...item, arguments: ''}},
        ...(custom ? [] : [{type: 'response.function_call_arguments.delta', item_id: item.id, output_index: 0, delta: argumentsValue},
        {type: 'response.function_call_arguments.done', item_id: item.id, output_index: 0, arguments: argumentsValue}]),
        {type: 'response.output_item.done', output_index: 0, item},
        {type: 'response.completed', response: responseValue},
      ]) response.write(`event: ${event.type}\ndata: ${JSON.stringify(event)}\n\n`);
      response.end(); return;
    }
    response.writeHead(400, { 'content-type': 'application/json' });
    response.end(JSON.stringify({ error: { message: 'local_inventory_probe_complete', type: 'invalid_request_error' } }));
  });
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  const controller = new AbortController();
  const timer = attack ? setTimeout(() => controller.abort(), 20000) : null;
  try {
    const options = sdkOptions({ ...isolatedEnvironment(auth, directory), HEROKID_SYNTHETIC_PROBE_KEY: 'synthetic-local-only' });
    options.config = { ...configuration, forced_login_method: 'api', model_provider: 'isolation_probe', model_providers: {
      isolation_probe: { name: 'Local isolation probe', base_url: `http://127.0.0.1:${server.address().port}/v1`,
        env_key: 'HEROKID_SYNTHETIC_PROBE_KEY', wire_api: 'responses', requires_openai_auth: false,
        supports_websockets: false, request_max_retries: 0, stream_max_retries: 0 },
    } };
    const schema = JSON.parse(await readFile(`${root}/decision-schema.json`, 'utf8'));
    try {
      const thread = new Codex(options).startThread({ ...threadOptions(work), model: 'gpt-6.1-sol' });
      if (attack) await thread.run('Synthetic tool inventory probe. Return one word.', { signal: controller.signal, outputSchema: schema });
      else await runDecisionStream(thread, 'Synthetic tool inventory probe. Return one word.', schema, 20000);
    }
    catch (error) { diagnostic = String(error.message).slice(0, 1800); }
    const unchanged = await readFile(sentinel, 'utf8').then(value => value === 'synthetic-secret-must-not-leak').catch(() => false);
    const credentialReferencePreserved = await credentialReferenceIntact(workspace);
    return { sdk_version: version, cli_version: version, tools: inventory, verified: Array.isArray(inventory) && inventory.length === 0 && credentialReferencePreserved,
      credential_reference_preserved: credentialReferencePreserved,
      attack, attack_sent: attackSent, rejected_tool: rejectedTool, sentinel_unchanged: unchanged, secret_leaked: secretLeaked,
      requests, feedback, diagnostic: inventory === null ? diagnostic : null };
  } finally {
    clearTimeout(timer); server.closeAllConnections(); await new Promise(resolve => server.close(resolve));
    await removeDecisionWorkspace(workspace);
  }
}

if (process.argv[1] === new URL(import.meta.url).pathname) {
  const result = await probeTools(process.argv[2] ?? null); console.log(JSON.stringify(result)); process.exitCode = result.verified ? 0 : 1;
}
