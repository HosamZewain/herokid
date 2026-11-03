import { readFile } from 'node:fs/promises';
import { Codex } from '@openai/codex-sdk';
import { version, root, sdkOptions, threadOptions, isolatedEnvironment } from './runtime-policy.mjs';
import { authDirectory, verifyIsolation, verifyAuthentication } from './isolation.mjs';
import { probeTools } from './probe-tools.mjs';
import { createDecisionWorkspace, credentialReferenceIntact, removeDecisionWorkspace } from './workspace.mjs';
import { runDecisionStream } from './decision-stream.mjs';
export { consumeDecision } from './decision-stream.mjs';

const schema = JSON.parse(await readFile(`${root}/decision-schema.json`, 'utf8'));

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
    let workspace;
    const started = Date.now();
    try {
      const health = await this.health();
      if (! health.ready) throw new Error(health.error);
      if (! input || ! Array.isArray(input.remaining_targets) || ! input.remaining_targets.length) throw new Error('INVALID_INPUT');
      workspace = await createDecisionWorkspace(`${authDirectory}/auth.json`);
      const codex = new Codex(sdkOptions(isolatedEnvironment(workspace.home, workspace.directory)));
      const thread = codex.startThread(threadOptions(workspace.work));
      const result = await runDecisionStream(thread, JSON.stringify(input), schema, 80000 - (Date.now() - started));
      if (! await credentialReferenceIntact(workspace)) throw new Error('AUTH_REFERENCE_CHANGED');
      return { decision: result.decision, metadata: { provider: 'codex', sdk_version: version, cli_version: version,
        authentication: 'chatgpt', tools_used: 0, usage: result.usage, isolation_digest: health.isolation_digest, model: 'subscription_default' } };
    } finally {
      this.busy = false;
      await removeDecisionWorkspace(workspace);
    }
  }
}
