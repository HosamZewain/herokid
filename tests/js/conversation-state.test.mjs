import test from 'node:test';
import assert from 'node:assert/strict';
import { mergeMessages, safeAttachmentUrl, shouldPoll, normalizeConversationWindows, readConversationWindows, writeConversationWindows, shortContactName, safeOrderUrl, replyWindowOpen } from '../../resources/js/conversation-state.js';

test('reply window excludes absent/invalid/future timestamps and exactly 24 hours', () => {
    const now = Date.parse('2026-10-09T14:00:00Z');
    for (const value of [null, '', 'invalid', '2026-10-09T14:00:01Z', '2026-10-08T14:00:00Z']) assert.equal(replyWindowOpen(value, now), false);
    assert.equal(replyWindowOpen('2026-10-08T14:00:00.001Z', now), true);
});

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
    for (const changes of [{configured:false}, {closed:true}, {minimized:true}, {suspended:true}]) assert.equal(shouldPoll({...state, ...changes}, 'visible'), false);
    assert.equal(shouldPoll(state, 'hidden'), false);
});
const memoryStorage = () => {
    const data = new Map();
    return { getItem:key => data.get(key) ?? null, setItem:(key, value) => data.set(key, value) };
};
test('restores window order and minimized state across new sessions without private content', () => {
    const storage = memoryStorage(); const key = 'user-one';
    writeConversationWindows(storage, key, [{orderId:12, minimized:true, title:'PRIVATE NAME', messages:['PRIVATE MESSAGE'], url:'https://private.test'}, {orderId:11, minimized:false}]);
    assert.deepEqual(readConversationWindows(storage, key), [{orderId:12, minimized:true}, {orderId:11, minimized:false}]);
    assert.equal(storage.getItem(key).includes('PRIVATE'), false);
    assert.equal(storage.getItem(key).includes('https'), false);
});
test('window state is isolated by employee/account and close removes only the chosen window', () => {
    const storage = memoryStorage(); const first = [{orderId:12, minimized:true}, {orderId:11, minimized:false}];
    writeConversationWindows(storage, 'employee-one:account-one', first);
    assert.deepEqual(readConversationWindows(storage, 'employee-two:account-one'), []);
    assert.deepEqual(readConversationWindows(storage, 'employee-one:account-two'), []);
    writeConversationWindows(storage, 'employee-one:account-one', first.slice(1));
    assert.deepEqual(readConversationWindows(storage, 'employee-one:account-one'), first.slice(1));
    writeConversationWindows(storage, 'employee-one:account-one', []);
    assert.deepEqual(readConversationWindows(storage, 'employee-one:account-one'), []);
});
test('tampered window storage cannot restore URLs, invalid IDs, duplicates or malformed state', () => {
    assert.deepEqual(normalizeConversationWindows([{orderId:1,minimized:false}, {orderId:1,minimized:true},
        {orderId:'2',minimized:false}, {orderId:-1,minimized:false}, {orderId:1.2,minimized:false},
        {orderId:Number.MAX_SAFE_INTEGER + 1,minimized:false}, {orderId:2,minimized:'false'}, null]), [{orderId:1,minimized:false}]);
    assert.equal(normalizeConversationWindows(Array.from({length:100}, (_, i) => ({orderId:i + 1,minimized:false}))).length, 50);
    const storage = memoryStorage();
    for (const raw of ['broken JSON', 'null', '{"version":2,"windows":[]}', '{"version":1,"windows":"bad"}']) {
        storage.setItem('test', raw); assert.deepEqual(readConversationWindows(storage, 'test'), []);
    }
});
test('blocked/quota-exceeded browser storage never prevents the viewer working', () => {
    const storage = { getItem:() => { throw new Error('blocked'); }, setItem:() => { throw new Error('full'); } };
    assert.deepEqual(readConversationWindows(storage, 'test'), []);
    assert.doesNotThrow(() => writeConversationWindows(storage, 'test', [{orderId:1,minimized:false}]));
    assert.deepEqual(readConversationWindows(undefined, 'test'), []);
    assert.doesNotThrow(() => writeConversationWindows(undefined, 'test', []));
});
test('header shows exactly eight name graphemes with the full name left untouched', () => {
    const name = 'محمد عبد العزيز';
    assert.equal(shortContactName(name), 'محمد عبد…');
    assert.equal(name, 'محمد عبد العزيز');
    assert.equal(shortContactName('محمد'), 'محمد');
    assert.equal(shortContactName('مُحَمَّد'), 'مُحَمَّد');
});
test('order links can only navigate to same-origin order details', () => {
    const origin = 'https://hero-kid.com';
    assert.equal(safeOrderUrl('/admin/orders/groups/1945', origin), `${origin}/admin/orders/groups/1945`);
    for (const value of ['javascript:alert(1)', 'https://evil.test/admin/orders/groups/1945', '/admin/users/1',
        '/admin/orders/groups/1945?secret=test', '/admin/orders/groups/1945#test', null]) assert.equal(safeOrderUrl(value, origin), null);
});
