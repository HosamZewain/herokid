import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source = fs.readFileSync(new URL('../../resources/views/admin/orders/_codex-scenes-poll.blade.php', import.meta.url), 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];
test('polling updates only the scene panel, preserves unchanged DOM and recovers from outages', async () => {
    let callback, calls = 0, writes = 0, fail = false;
    const note = {textContent: ''};
    const panel = {dataset: {url: '/progress'}, parentElement: {querySelector: () => note}, set innerHTML(value) { writes++; this.html = value; }};
    const document = {hidden: false, querySelectorAll: () => [panel]};
    vm.runInNewContext(source, {
        document, AbortSignal,
        window: {setTimeout: (fn, ms) => { assert.equal(ms, 10000); callback = fn; }},
        fetch: async (url, options) => {
            calls++;
            assert.equal(url, '/progress'); assert.equal(options.cache, 'no-store');
            if (fail) throw Error('offline');
            return {ok: true, redirected: false, json: async () => ({html: '<p>2 / 13</p>', version: 'same'})};
        },
    });
    await callback(); assert.equal(writes, 1); assert.match(panel.html, /2 \/ 13/);
    await callback(); assert.equal(writes, 1);
    fail = true; await callback(); assert.equal(writes, 1); assert.match(note.textContent, /تعذر/);
    fail = false; await callback(); assert.match(note.textContent, /تلقائيًا/);
    document.hidden = true; const previous = calls; await callback(); assert.equal(calls, previous);
});
