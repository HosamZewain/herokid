import assert from 'node:assert/strict';
import test from 'node:test';
import { CHECKOUT_DRAFT_FIELDS, initializeCheckoutDraft, clearCompletedCheckoutDrafts } from '../../resources/js/checkout-form-draft.js';

const scope = '11111111-1111-4111-8111-111111111111';
const nextScope = '22222222-2222-4222-8222-222222222222';
const key = `herokid:checkout-form:v1:${scope}`;
const time = 100000000;
function storage() {
    const values = new Map();
    return { get length() { return values.size; }, key(index) { return [...values.keys()][index]; },
        getItem(key) { return values.get(key) ?? null; }, setItem(key, value) { values.set(key, value); },
        removeItem(key) { values.delete(key); } };
}
function fixture({ old = [], id = scope } = {}) {
    const fields = Object.fromEntries(CHECKOUT_DRAFT_FIELDS.map((name) => [name, { name, value: '', tagName: 'INPUT', dataset: {}, disabled: false }]));
    fields.delivery_country_id = { value: '1', tagName: 'SELECT', options: [{ value: '' }, { value: '1' }, { value: '2' }] };
    fields.delivery_governorate_id = { value: '', tagName: 'SELECT', options: [{ value: '', dataset: {} },
        { value: '10', dataset: { countryId: '1' } }, { value: '20', dataset: { countryId: '2' } }] };
    fields.bosta_district_id.disabled = true;
    fields.bosta_district_id.tagName = 'SELECT';
    const zone = { value: '', disabled: true, dataset: {} };
    const hint = { hidden: true, textContent: '' };
    const events = {};
    const browserEvents = {};
    const form = { dataset: { checkoutDraftScope: id, checkoutDraftOldFields: JSON.stringify(old) },
        elements: { namedItem(name) { return fields[name]; } },
        querySelector(selector) { return selector === '[data-bosta-zone]' ? zone : hint; },
        addEventListener(name, callback, capture) { events[name] = callback; if (name === 'change') this.changeCapture = capture; },
        ownerDocument: { visibilityState: 'visible', addEventListener() {} } };
    const browser = { addEventListener(name, callback) { browserEvents[name] = callback; } };
    return { form, fields, zone, hint, events, browserEvents, browser };
}
function setup(target, disk = storage()) {
    const options = { storage: disk, now: () => time, browser: target.browser };
    return { disk, options, controller: initializeCheckoutDraft(target.form, options) };
}
function saved(disk, values, extra = {}) {
    disk.setItem(key, JSON.stringify({ version: 1, saved_at: time, values, ...extra }));
}

test('contact/address values are saved synchronously on input, including Arabic and leading-zero phones', () => {
    const target = fixture(); const { disk } = setup(target);
    target.fields.parent_name.value = '  ولي أمر تجريبي  ';
    target.fields.phone.value = '01012345678';
    target.fields.alternate_phone.value = '+966512345678';
    target.fields.street.value = 'شارع الاختبار ١٢';
    target.fields.address_details.value = 'الدور 3\nشقة 4';
    target.events.input();
    const values = JSON.parse(disk.getItem(key)).values;
    for (const name of ['parent_name', 'phone', 'alternate_phone', 'street', 'address_details']) assert.equal(values[name], target.fields[name].value);
});
test('a new cart render restores all saved fields before dependent address initialization', () => {
    const disk = storage(); saved(disk, { parent_name: 'Test', phone: '01012345678', delivery_country_id: '1',
        delivery_governorate_id: '10', bosta_city_id: 'cairo', bosta_district_id: 'district-5', bosta_zone_id: 'zone-2',
        city: 'مدينة الاختبار', street: 'Street 12', address_details: 'Flat 3' });
    const target = fixture(); setup(target, disk);
    assert.equal(target.fields.delivery_governorate_id.value, '10');
    assert.equal(target.fields.phone.value, '01012345678');
    assert.equal(target.fields.bosta_district_id.dataset.selectedId, 'district-5');
    assert.equal(target.fields.bosta_district_id.dataset.selectedName, 'مدينة الاختبار');
    assert.equal(target.zone.dataset.selectedId, 'zone-2');
});
test('the initial save preserves district metadata before the address loader disables the select', () => {
    const disk = storage(); saved(disk, { delivery_country_id: '1', delivery_governorate_id: '10', bosta_district_id: 'area', bosta_zone_id: 'zone' });
    const target = fixture(); target.fields.bosta_district_id.disabled = false; target.zone.disabled = false;
    setup(target, disk);
    assert.equal(JSON.parse(disk.getItem(key)).values.bosta_district_id, 'area');
    assert.equal(JSON.parse(disk.getItem(key)).values.bosta_zone_id, 'zone');
});
test('async area loading does not erase the saved official district or zone', () => {
    const disk = storage(); saved(disk, { delivery_country_id: '1', delivery_governorate_id: '10', bosta_district_id: 'area', bosta_zone_id: 'zone' });
    const target = fixture(); const { controller } = setup(target, disk);
    target.fields.parent_name.value = 'Changed while loading'; controller.save();
    assert.equal(JSON.parse(disk.getItem(key)).values.bosta_district_id, 'area');
    assert.equal(JSON.parse(disk.getItem(key)).values.bosta_zone_id, 'zone');
});
test('clearing optional inputs persists the empty values instead of resurrecting old defaults', () => {
    const target = fixture(); const { disk } = setup(target);
    target.fields.alternate_phone.value = '+966512345678'; target.events.input();
    target.fields.alternate_phone.value = ''; target.events.input();
    const restored = fixture(); restored.fields.alternate_phone.value = 'old-profile-phone'; setup(restored, disk);
    assert.equal(restored.fields.alternate_phone.value, '');
});
test('partially entered city text is retained even before the customer chooses a governorate', () => {
    const disk = storage(); saved(disk, { delivery_governorate_id: '', city: 'Entered before selecting a governorate' });
    const target = fixture(); setup(target, disk);
    assert.equal(target.fields.city.value, 'Entered before selecting a governorate');
});
test('validation old input wins over saved drafts, including intentionally empty fields', () => {
    const disk = storage(); saved(disk, { parent_name: 'Stale', phone: 'old', address_details: 'Old address' });
    const target = fixture({ old: ['parent_name', 'phone', 'address_details'] });
    target.fields.parent_name.value = 'Server submitted value'; target.fields.phone.value = 'Server normalized phone';
    setup(target, disk);
    assert.equal(target.fields.parent_name.value, 'Server submitted value');
    assert.equal(target.fields.phone.value, 'Server normalized phone');
    assert.equal(target.fields.address_details.value, '');
});
test('unknown country/governorate selections do not silently map to another address', () => {
    const disk = storage(); saved(disk, { delivery_country_id: 'removed', delivery_governorate_id: 'removed', bosta_district_id: 'old-area', city: 'Old' });
    const target = fixture(); setup(target, disk);
    assert.equal(target.fields.delivery_country_id.value, '');
    assert.equal(target.fields.delivery_governorate_id.value, '');
    assert.equal(target.fields.bosta_district_id.dataset.selectedId, '');
    assert.equal(target.fields.city.value, '');
});
test('a restored governorate belonging to a different country is cleared', () => {
    const disk = storage(); saved(disk, { delivery_country_id: '2', delivery_governorate_id: '10', bosta_district_id: 'old' });
    const target = fixture(); setup(target, disk);
    assert.equal(target.fields.delivery_governorate_id.value, '');
    assert.equal(target.fields.bosta_district_id.dataset.selectedId, '');
});
test('derived address updates and non-bubbling district changes are captured after existing handlers', async () => {
    const target = fixture(); const { disk } = setup(target);
    assert.equal(target.form.changeCapture, true);
    target.events.change();
    target.fields.city.value = 'New selected area'; target.fields.bosta_district_id.disabled = false;
    target.fields.bosta_district_id.value = 'new-area';
    await Promise.resolve();
    assert.equal(JSON.parse(disk.getItem(key)).values.city, 'New selected area');
    assert.equal(JSON.parse(disk.getItem(key)).values.bosta_district_id, 'new-area');
});
test('submission and leaving the page save but never clear an unconfirmed order draft', () => {
    const target = fixture(); const { disk } = setup(target);
    target.fields.parent_name.value = 'Submit'; target.events.submit();
    assert.equal(JSON.parse(disk.getItem(key)).values.parent_name, 'Submit');
    target.fields.parent_name.value = 'Leave'; target.browserEvents.pagehide();
    assert.equal(JSON.parse(disk.getItem(key)).values.parent_name, 'Leave');
});
test('confirmed success removes all draft customer data and blocks cached pages from writing it back', () => {
    const target = fixture(); const { disk, options, controller } = setup(target);
    target.fields.phone.value = '01012345678'; controller.save();
    clearCompletedCheckoutDrafts({ querySelectorAll() { return [{ dataset: { checkoutDraftCompleted: scope } }]; } }, options);
    assert.equal(JSON.parse(disk.getItem(key)).values, undefined);
    assert.equal(controller.save(), false);
    assert.equal(disk.getItem(key).includes('01012345678'), false);
});
test('new checkout/account/session scope cannot restore the previous customer and does not erase unrelated drafts', () => {
    const disk = storage(); saved(disk, { phone: 'old-private-phone' }); disk.setItem('story-photo-draft', 'keep');
    const target = fixture({ id: nextScope }); setup(target, disk);
    assert.equal(target.fields.phone.value, ''); assert.equal(disk.getItem(key), null);
    assert.equal(disk.getItem('story-photo-draft'), 'keep');
});
test('a cached old form cannot overwrite a newer checkout draft', () => {
    const target = fixture(); const { disk, controller } = setup(target);
    setup(fixture({ id: nextScope }), disk);
    assert.equal(controller.save(), false); assert.equal(disk.getItem(key), null);
});
test('blocked storage and quota errors never block checkout', () => {
    const target = fixture(); const disk = { getItem() { throw new Error('Denied'); }, setItem() { throw new Error('Quota'); } };
    const { controller } = setup(target, disk);
    assert.equal(controller.save(), false); assert.match(target.hint.textContent, /غير متاح/);
    assert.doesNotThrow(() => target.events.submit());
});
test('storage denied by the browser getter is handled safely', () => {
    const target = fixture(); Object.defineProperty(target.browser, 'sessionStorage', { get() { throw new Error('SecurityError'); } });
    assert.doesNotThrow(() => initializeCheckoutDraft(target.form, { browser: target.browser }));
    assert.match(target.hint.textContent, /غير متاح/);
});
for (const [label, payload] of [['malformed', '{'], ['expired', JSON.stringify({ version: 1, saved_at: time - 86400000, values: { phone: 'old' } })],
    ['unsupported version', JSON.stringify({ version: 2, saved_at: time, values: { phone: 'old' } })],
    ['future timestamp', JSON.stringify({ version: 1, saved_at: time + 1, values: { phone: 'old' } })]]) {
    test(`${label} drafts are ignored without breaking the form`, () => {
        const disk = storage(); disk.setItem(key, payload); const target = fixture();
        assert.doesNotThrow(() => setup(target, disk)); assert.equal(target.fields.phone.value, '');
    });
}
test('tokens, previous-order actions, prices and file inputs are not persisted or restored', () => {
    const disk = storage(); saved(disk, { _token: 'secret', checkout_submission_token: 'secret', previous_order_action: 'cancel_previous', phone: 123, parent_name: '<script>plain text</script>' });
    const target = fixture(); setup(target, disk);
    const values = JSON.parse(disk.getItem(key)).values;
    assert.equal(values._token, undefined); assert.equal(values.previous_order_action, undefined);
    assert.equal(values.checkout_submission_token, undefined); assert.equal(values.phone, '');
    assert.equal(target.fields.parent_name.value, '<script>plain text</script>');
});
test('initializer is idempotent and missing/invalid scope does not affect legacy forms', () => {
    const target = fixture(); const { controller, options } = setup(target);
    assert.equal(initializeCheckoutDraft(target.form, options), controller);
    assert.equal(initializeCheckoutDraft(null, options), undefined);
    assert.equal(initializeCheckoutDraft(fixture({ id: '' }).form, options), undefined);
});
