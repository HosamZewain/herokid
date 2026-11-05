import { Codex } from '@openai/codex-sdk';
import { DecisionRuntime } from './runtime.mjs';
import { version, sdkOptions, threadOptions, isolatedEnvironment } from './runtime-policy.mjs';
import { authDirectory } from './isolation.mjs';
import { createDecisionWorkspace, credentialReferenceIntact, removeDecisionWorkspace } from './workspace.mjs';
import { runDecisionStream } from './decision-stream.mjs';
import { assistantPrompt, assistantSchema } from './assistant-contract.mjs';

// A different entry point/socket/process; the Auto Pilot decision bridge is unchanged.
export class AssistantRuntime extends DecisionRuntime {
  async plan(input) {
    const prompt = assistantPrompt(input);
    if (this.busy) throw new Error('BUSY');
    this.busy = true;
    let workspace;
    const started = Date.now();
    try {
      const health = await this.health();
      if (!health.ready) throw new Error('ASSISTANT_NOT_READY');
      workspace = await createDecisionWorkspace(`${authDirectory}/auth.json`);
      const codex = new Codex(sdkOptions(isolatedEnvironment(workspace.home, workspace.directory)));
      const thread = codex.startThread({...threadOptions(workspace.work), threadSource:'herokid-admin-assistant'});
      const result = await runDecisionStream(thread, prompt, assistantSchema, 25000-(Date.now()-started));
      if (!await credentialReferenceIntact(workspace)) throw new Error('AUTH_REFERENCE_CHANGED');
      return {plan:result.decision, metadata:{authentication:'chatgpt', isolation_verified:true, tools_used:0, purpose:'admin_assistant', sdk_version:version}};
    } finally { this.busy=false; await removeDecisionWorkspace(workspace); }
  }
}
