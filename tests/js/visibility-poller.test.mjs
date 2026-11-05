import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createVisibilityPoller} from '../../resources/js/visibility-poller.js';

test('polling suspends hidden pages, resumes once and never overlaps', async () => {
    const handlers = {}, timers = new Map(); let id = 0, calls = 0, settle;
    const doc = {hidden:true,addEventListener:(k,f)=>handlers[k]=f,removeEventListener:k=>delete handlers[k]};
    const win = {setTimeout:f=>{timers.set(++id,f);return id},clearTimeout:i=>timers.delete(i),addEventListener:(k,f)=>handlers[k]=f};
    const task = () => {calls++;return new Promise(resolve=>settle=resolve)};
    const p = createVisibilityPoller(task,{document:doc,window:win});
    assert.equal(calls,0);assert.equal(timers.size,0);
    doc.hidden=false;handlers.visibilitychange();assert.equal(calls,1);
    void p.refresh();handlers.visibilitychange();assert.equal(calls,1);
    settle();await new Promise(r=>setImmediate(r));assert.equal(timers.size,1);
    doc.hidden=true;handlers.visibilitychange();assert.equal(timers.size,0);
    doc.hidden=false;handlers.visibilitychange();assert.equal(calls,2);
    handlers.pagehide();settle();await new Promise(r=>setImmediate(r));assert.equal(timers.size,0);
    handlers.pageshow({persisted:true});assert.equal(calls,3);settle();await new Promise(r=>setImmediate(r));
    p.stop();assert.equal(timers.size,0);
});
