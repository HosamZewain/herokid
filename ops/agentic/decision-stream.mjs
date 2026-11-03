const allowedItems = new Set(['reasoning', 'agent_message']);
const disabledCodeModeWarning = 'Code Mode is unavailable because code-mode host is disabled. Code mode will fail closed; enable `features.code_mode_host` and install `codex-code-mode-host`.';

export async function consumeDecision(events, rejectActiveStream = () => {}) {
  let answer, usage, completed = false;
  const reject = code => { rejectActiveStream(); throw new Error(code); };
  for await (const event of events) {
    // CLI 0.166.0 emits this exact diagnostic for our intentionally disabled
    // code-mode host before inference. It grants no capability and is not a tool.
    if (event.type === 'item.completed' && event.item?.type === 'error' && event.item.message === disabledCodeModeWarning) continue;
    if (event.type?.startsWith('item.') && ! allowedItems.has(event.item?.type)) reject('TOOL_EVENT_REJECTED');
    if (event.type === 'error' || event.type === 'turn.failed') reject('PROVIDER_RESULT_UNCERTAIN');
    if (event.type === 'item.completed' && event.item.type === 'agent_message') answer = event.item.text;
    if (event.type === 'turn.completed') { usage = event.usage; completed = true; }
  }
  if (! completed || typeof answer !== 'string' || answer.length > 16000) throw new Error('INCOMPLETE_DECISION');
  return { decision: JSON.parse(answer), usage };
}

export async function runDecisionStream(thread, prompt, schema, timeoutMs = 80000) {
  const abort = new AbortController();
  const timer = setTimeout(() => abort.abort(), Math.max(1, timeoutMs));
  try {
    const {events} = await thread.runStreamed(prompt, {outputSchema: schema, signal: abort.signal});
    // Abort while the SDK still owns the child listeners, before iterator return.
    return await consumeDecision(events, () => abort.abort());
  } finally {
    // SDK 0.166.0 closes/kills the child when its iterator returns, then removes
    // listeners. Aborting after that can emit an unhandled child-process error.
    clearTimeout(timer);
  }
}
