import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/views/admin/orders/_ajax-delete-script.blade.php', import.meta.url), 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];
function fixture({fail = false} = {}) {
  const events = {}, calls = [], alerts = [];
  let clicked = 0;
  const checkboxes = [1, 2, 3].map(value => ({name: 'attachment_ids[]', value: String(value), checked: value !== 2}));
  const all = {checked: false}, label = {dataset: {emptyLabel: 'empty'}};
  const download = {disabled: true, dataset: {downloadUrl: '/admin/orders/groups/1/attachments/download'}, textContent: 'Download', closest: () => form};
  const remove = {disabled: true};
  const scope = {querySelectorAll: selector => selector.includes(':checked') ? checkboxes.filter(c => c.checked) : checkboxes};
  const form = {closest: () => scope, querySelector: selector => ({'[data-bulk-delete-all]': all, 'button[type="submit"]': remove, '[data-bulk-delete-selection]': label, '[data-order-bulk-download]': download}[selector])};
  const document = {
    addEventListener: (event, fn) => events[event] = fn,
    getElementById: () => form,
    body: {appendChild: () => {}},
    createElement: () => ({click: () => clicked++, remove: () => {}}),
  };
  class FakeURL extends URL {static createObjectURL() {return 'blob:test';} static revokeObjectURL() {}}
  vm.runInNewContext(source, {document, URL: FakeURL, window: {location: {origin: 'http://localhost'}, confirm: () => {throw Error('Deletion confirmation must not run');}, alert: m => alerts.push(m), setTimeout: () => {}}, fetch: async (url, options) => {
    calls.push([String(url), options]);
    return {ok: !fail, headers: {get: name => name === 'Content-Type' ? 'application/zip' : 'attachment; filename="HK11-1-attachments.zip"'}, blob: async () => ({}), json: async () => ({message: 'A file is unavailable'})};
  }});
  return {events, calls, alerts, download, all, checkboxes, clicked: () => clicked, change() {
    events.change({target: {closest: selector => selector === '[data-bulk-delete-checkbox]' ? {getAttribute: () => 'form'} : selector === '[data-ajax-delete-scope]' ? scope : null}});
  }};
}

test('download includes checked IDs only; does not submit delete or clear selection', async () => {
  const f = fixture(); f.change(); assert.equal(f.download.disabled, false);
  await f.events.click({target: {closest: () => f.download}});
  assert.deepEqual(new URL(f.calls[0][0]).searchParams.getAll('attachment_ids[]'), ['1', '3']);
  assert.equal(f.calls[0][1].method, undefined);
  assert.equal(f.clicked(), 1); assert.equal(f.download.disabled, false);
  assert.deepEqual(f.checkboxes.map(c => c.checked), [true, false, true]);
});
test('select all enables download and clear all disables it', () => {
  const f = fixture(); f.checkboxes.forEach(c => c.checked = true); f.change();
  assert.equal(f.all.checked, true); assert.equal(f.download.disabled, false);
  f.checkboxes.forEach(c => c.checked = false); f.change(); assert.equal(f.download.disabled, true);
});
test('failure shows server reason and allows retry without losing selection', async () => {
  const f = fixture({fail: true}); f.change(); await f.events.click({target: {closest: () => f.download}});
  assert.deepEqual(f.alerts, ['A file is unavailable']); assert.equal(f.clicked(), 0);
  assert.equal(f.download.disabled, false); assert.equal(f.download.textContent, 'Download');
});
