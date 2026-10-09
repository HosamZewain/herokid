import test from 'node:test';
import assert from 'node:assert/strict';
import { mergeMessages, safeAttachmentUrl, shouldPoll } from '../../resources/js/conversation-state.js';

test('deduplicates, updates status and orders equal timestamps deterministically', () => {
    const message = {id:2, date:'2026-10-09T10:00:00Z', status:'sent'};
    const result = mergeMessages([message], [{...message, status:'read'}, {id:1, date:message.date}]);
    assert.deepEqual(result.map(x => x.id), [1, 2]); assert.equal(result[1].status, 'read');
});
test('cached metadata does not erase a transient in-memory URL; live null clears it', () => {
    const previous = [{id:1, date:'2026-10-09', attachments:[{name:'test', url:'https://example.test'}]}];
    assert.equal(mergeMessages(previous, [{...previous[0], attachments:[{name:'test'}]}])[0].attachments[0].url, 'https://example.test');
    assert.equal(mergeMessages(previous, [{...previous[0], attachments:[{name:'test', url:null}]}])[0].attachments[0].url, null);
});
test('attachment links reject scripts, HTTP and embedded credentials', () => {
    for (const value of ['javascript:alert(1)', 'http://example.test', 'https://user:pass@example.test', null, '/local']) assert.equal(safeAttachmentUrl(value), null);
    assert.equal(safeAttachmentUrl('https://example.test/file?signature=short'), 'https://example.test/file?signature=short');
});
test('polls only open, expanded, configured panels in a visible tab', () => {
    const state = {configured:true, closed:false, minimized:false};
    assert.equal(shouldPoll(state, 'visible'), true);
    for (const changes of [{configured:false}, {closed:true}, {minimized:true}]) assert.equal(shouldPoll({...state, ...changes}, 'visible'), false);
    assert.equal(shouldPoll(state, 'hidden'), false);
});
