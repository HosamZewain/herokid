import assert from 'node:assert/strict';
import test from 'node:test';
import { catalogMatches, customerPhoneKey } from '../../resources/js/admin-order-workflow.js';

test('catalog selection searches only the chosen type by name without translating names', () => {
    const catalog = [{ id: 1, name: 'كتاب تلوين', type: 'product' }, { id: 2, name: 'قصة مخترع', type: 'story' },
        { id: 3, name: 'Coloring book', type: 'product' }];
    assert.deepEqual(catalogMatches(catalog, 'product', '  كتاب '), [catalog[0]]);
    assert.deepEqual(catalogMatches(catalog, 'product', 'colorING'), [catalog[2]]);
    assert.deepEqual(catalogMatches(catalog, 'story', ''), [catalog[1]]);
    assert.deepEqual(catalogMatches(catalog, 'product', 'قصة'), []);
});

test('child lookup cache recognizes local Egyptian phone equivalents but distinguishes other customers', () => {
    assert.equal(customerPhoneKey('010 1234 5678'), customerPhoneKey('+201012345678'));
    assert.equal(customerPhoneKey('00123456789'), '123456789');
    assert.notEqual(customerPhoneKey('01012345678'), customerPhoneKey('01098765432'));
    assert.equal(customerPhoneKey('+44 7700 900123'), '447700900123');
});
