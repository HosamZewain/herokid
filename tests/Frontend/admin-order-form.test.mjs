import assert from 'node:assert/strict';
import test from 'node:test';
import { orderFormData, saveAdminOrderForm, syncOrderProductVariant } from '../../resources/js/admin-order-form.js';

function row(quantity, { sole = '', legacy = 0, selected = '' } = {}) {
    const events = [];
    const select = { value: selected, required: false, dispatchEvent(event) { events.push(event.type); } };
    const hints = { toggleAttribute() {} };
    return { select, events, dataset: { soleVariant: sole, legacyVariantlessQuantity: String(legacy) },
        querySelector(selector) {
            return selector === '[data-product-variant]' ? select
                : selector === '[data-product-quantity]' ? { value: String(quantity) } : hints;
        } };
}

function form() {
    const photos = [{ name: 'child-original.jpg' }, { name: 'child-second.jpg' }];
    const photoInput = { files: photos, value: 'browser-file-list', disabled: false };
    const disabledInput = { value: 'hidden-unit', disabled: true };
    const submit = { disabled: false };
    return { dataset: {}, action: '/admin/orders', controls: [photoInput, disabledInput, submit],
        querySelectorAll() { return this.controls; }, reportValidity() { return true; },
        setAttribute() {}, removeAttribute() {}, photoInput, photos };
}

function response(status, payload, { redirected = false, json = true } = {}) {
    return { status, ok: status >= 200 && status < 300, redirected,
        headers: { get() { return json ? 'application/json' : 'text/html'; } },
        async json() { return payload; } };
}

test('the only active option is selected on a new line before pricing and saving', () => {
    const target = row(1, { sole: '42' });
    syncOrderProductVariant(target);
    assert.equal(target.select.value, '42');
    assert.equal(target.select.required, true);
    assert.deepEqual(target.events, ['change']);
});

test('multiple choices require an explicit selection; zero quantity does not', () => {
    const target = row(2);
    syncOrderProductVariant(target);
    assert.equal(target.select.required, true);
    assert.equal(target.select.value, '');
    const unselected = row(0, { sole: '42' });
    syncOrderProductVariant(unselected);
    assert.equal(unselected.select.required, false);
    assert.equal(unselected.select.value, '');
});

test('existing variantless purchases are not silently assigned a later catalog option', () => {
    const unchanged = row(2, { legacy: 2 });
    syncOrderProductVariant(unchanged);
    assert.equal(unchanged.select.required, false);
    assert.equal(unchanged.select.value, '');
    const changed = row(3, { legacy: 2 });
    syncOrderProductVariant(changed);
    assert.equal(changed.select.required, true);
    assert.equal(changed.select.value, '');
});

test('an explicit selected variant is never overridden', () => {
    const target = row(1, { sole: '42', selected: '99' });
    syncOrderProductVariant(target);
    assert.equal(target.select.value, '99');
    assert.deepEqual(target.events, []);
});

test('request body omits unused catalog inputs but keeps exact files, sparse unit keys, method and invalid quantities', () => {
    const file = { name: 'original.heic' };
    const data = new Map([
        ['_method', 'PUT'], ['products[1][quantity]', '0'], ['products[1][variant_id]', ''],
        ['products[2][quantity]', '1'], ['products[2][units][3][personalization][photos][]', file],
        ['products[3][quantity]', '-1'], ['products[4][quantity]', 'invalid'],
    ]);
    const body = orderFormData({}, class { constructor() { return data; } });
    assert.equal(body.has('products[1][quantity]'), false);
    assert.equal(body.has('products[1][variant_id]'), false);
    assert.equal(body.get('products[2][units][3][personalization][photos][]'), file);
    assert.equal(body.get('_method'), 'PUT');
    assert.equal(body.get('products[3][quantity]'), '-1');
    assert.equal(body.get('products[4][quantity]'), 'invalid');
});

test('422 retains the exact story and product FileLists and all values, then a corrected retry succeeds', async () => {
    const target = form();
    const body = { files: target.photos, parentName: 'Exact name' };
    let calls = 0;
    const errors = [];
    const navigations = [];
    const fetchImpl = async (_, options) => {
        assert.equal(options.body, body);
        assert.equal(options.headers.Accept, 'application/json');
        assert.equal(options.method, 'POST');
        assert.equal(target.photoInput.disabled, true);
        return ++calls === 1 ? response(422, { errors: { 'products.42.variant_id': ['اختر الخيار'] } })
            : response(201, { success: true, redirect_url: '/admin/orders/groups/10' });
    };
    const options = { fetchImpl, makeBody: () => body, showErrors: (error) => errors.push(error), navigate: (url) => navigations.push(url) };
    await saveAdminOrderForm(target, options);
    assert.equal(target.photoInput.files, target.photos);
    assert.equal(target.photoInput.value, 'browser-file-list');
    assert.equal(target.photoInput.disabled, false);
    assert.equal(target.controls[1].disabled, true);
    assert.equal(target.dataset.saving, undefined);
    assert.deepEqual(navigations, []);
    assert.deepEqual(errors.at(-1), { 'products.42.variant_id': ['اختر الخيار'] });
    await saveAdminOrderForm(target, options);
    assert.deepEqual(navigations, ['/admin/orders/groups/10']);
    assert.equal(calls, 2);
});

for (const [label, fetchImpl] of [
    ['500 HTML', async () => response(500, null, { json: false })],
    ['413 too large', async () => response(413, null, { json: false })],
    ['419 expired session', async () => response(419, {})],
    ['403 permission denied', async () => response(403, {})],
    ['login redirect', async () => response(200, null, { redirected: true, json: false })],
    ['lost connection', async () => { throw new Error('Network failed'); }],
]) {
    test(`${label} retains images, unlocks the form and never automatically retries or navigates`, async () => {
        const target = form();
        const errors = [];
        let calls = 0;
        await saveAdminOrderForm(target, {
            fetchImpl: (...args) => { calls++; return fetchImpl(...args); },
            makeBody: () => ({}), showErrors: (error) => errors.push(error),
            navigate: () => assert.fail('Must not navigate on failure'),
        });
        assert.equal(target.photoInput.files, target.photos);
        assert.equal(target.photoInput.value, 'browser-file-list');
        assert.equal(target.photoInput.disabled, false);
        assert.equal(target.controls[1].disabled, true);
        assert.equal(target.dataset.saving, undefined);
        assert.equal(calls, 1);
        assert.ok(errors.at(-1).form[0].length > 0);
    });
}

test('a second click while saving cannot create a duplicate request', async () => {
    const target = form();
    let finish;
    let calls = 0;
    const options = { makeBody: () => ({}), fetchImpl: () => { calls++; return new Promise((resolve) => { finish = resolve; }); } };
    const pending = saveAdminOrderForm(target, options);
    await saveAdminOrderForm(target, options);
    assert.equal(calls, 1);
    finish(response(422, { errors: { parent_name: ['Required'] } }));
    await pending;
    assert.equal(target.photoInput.disabled, false);
});

test('an explicit retry after session expiry refreshes CSRF without losing photos or automatically replaying the write', async () => {
    const target = form();
    const body = new Map([['_token', 'expired']]);
    let writes = 0;
    let refreshed = 0;
    const options = {
        makeBody: () => body,
        refreshSession: async () => { refreshed++; return 'fresh'; },
        fetchImpl: async () => ++writes === 1 ? response(419, {}) : response(422, { errors: { phone: ['Required'] } }),
    };
    await saveAdminOrderForm(target, options);
    assert.equal(writes, 1);
    assert.equal(refreshed, 0);
    assert.equal(target.dataset.refreshSession, '1');
    await saveAdminOrderForm(target, options);
    assert.equal(writes, 2);
    assert.equal(refreshed, 1);
    assert.equal(body.get('_token'), 'fresh');
    assert.equal(target.photoInput.files, target.photos);
});
