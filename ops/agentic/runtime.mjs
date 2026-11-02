import { readFile, mkdtemp, rm } from 'node:fs/promises';
import { Codex } from '@openai/codex-sdk';
import { version, root, sdkOptions, threadOptions, isolatedEnvironment } from './runtime-policy.mjs';
import { authDirectory, verifyIsolation, verifyAuthentication } from './isolation.mjs';
import { probeTools } from './probe-tools.mjs';

const schema = JSON.parse(await readFile(`${root}/decision-schema.json`, 'utf8'));
const allowedItems = new Set(['reasoning', 'agent_message']);

export async function consumeDecision(events) {
  let answer, usage, completed = false;
  for await (const event of events) {
    if (event.type?.startsWith('item.') && ! allowedItems.has(event.item?.type)) throw new Error('TOOL_EVENT_REJECTED');
    if (event.type === 'error' || event.type === 'turn.failed') throw new Error('PROVIDER_RESULT_UNCERTAIN');
    if (event.type === 'item.completed' && event.item.type === 'agent_message') answer = event.item.text;
    if (event.type === 'turn.completed') { usage = event.usage; completed = true; }
  }
  if (! completed || typeof answer !== 'string' || answer.length > 16000) throw new Error('INCOMPLETE_DECISION');
  return { decision: JSON.parse(answer), usage };
}

export class DecisionRuntime {
  constructor() { this.inventory = null; this.busy = false; }
  async initialize() { this.inventory = await probeTools(); }
  async health() {
    const isolation = await verifyIsolation();
    const authentication = isolation.verified && await verifyAuthentication();
    const toolsVerified = this.inventory?.verified === true && this.inventory.tools.length === 0;
    return { ready: Boolean(isolation.verified && authentication && toolsVerified),
      authentication: authentication ? 'chatgpt' : 'unverified', isolation_verified: isolation.verified,
      tool_count: toolsVerified ? 0 : null, sdk_version: version, cli_version: version,
      isolation_digest: isolation.digest ?? null, error: ! isolation.verified ? 'ISOLATION_UNVERIFIED' : ! authentication ? 'SUBSCRIPTION_AUTH_UNVERIFIED' : ! toolsVerified ? 'TOOLS_UNVERIFIED' : null };
  }
  async decide(input) {
    if (this.busy) throw new Error('BUSY');
    this.busy = true;
    let work;
    const abort = new AbortController();
    const timer = setTimeout(() => abort.abort(), 80000);
    try {
      const health = await this.health();
      if (! health.ready) throw new Error(health.error);
      if (! input || ! Array.isArray(input.remaining_targets) || ! input.remaining_targets.length) throw new Error('INVALID_INPUT');
      work = await mkdtemp('/tmp/herokid-decision-');
      const codex = new Codex(sdkOptions(isolatedEnvironment(authDirectory, work)));
      const thread = codex.startThread(threadOptions(work));
      const { events } = await thread.runStreamed(JSON.stringify(input), { outputSchema: schema, signal: abort.signal });
      const result = await consumeDecision(events);
      return { decision: result.decision, metadata: { provider: 'codex', sdk_version: version, cli_version: version,
        authentication: 'chatgpt', tools_used: 0, usage: result.usage, isolation_digest: health.isolation_digest, model: 'subscription_default' } };
    } finally {
      // Abort also terminates the fixed CLI subprocess after a disallowed event.
      abort.abort(); clearTimeout(timer); this.busy = false;
      if (work) await rm(work, { recursive: true, force: true });
    }
  }
}
