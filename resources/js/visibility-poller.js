// At most one request; hidden and unloaded pages perform no background polling.
export function createVisibilityPoller(task, {interval = 10000, document: doc = document, window: win = window} = {}) {
    let timer, busy = false, stopped = false;
    const schedule = () => { win.clearTimeout(timer); if (!stopped && !doc.hidden) timer = win.setTimeout(tick, interval); };
    const tick = async () => {
        if (stopped || busy || doc.hidden) return;
        busy = true;
        try { await task(); } catch { /* The caller owns UI feedback. */ }
        finally { busy = false; schedule(); }
    };
    const visibility = () => { win.clearTimeout(timer); if (!doc.hidden) void tick(); };
    doc.addEventListener('visibilitychange', visibility);
    win.addEventListener('pagehide', () => { stopped = true; win.clearTimeout(timer); });
    win.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; void tick(); } });
    void tick();
    return {refresh: tick, stop: () => { stopped = true; win.clearTimeout(timer); doc.removeEventListener('visibilitychange', visibility); }};
}
