import test from 'node:test';
import assert from 'node:assert/strict';
import { changedPersonalization, filteredProducts } from '../../resources/js/order-quick-edit.js';

test('unchanged missing legacy fields and integer values are not submitted as edits', () => {
    assert.deepEqual(changedPersonalization({ child_name: 'ليلى', child_age: '7', school_name: '' }, { child_name: 'ليلى', child_age: 7 }), {});
});

test('explicit changes including clearing an optional field are submitted verbatim', () => {
    assert.deepEqual(changedPersonalization({ child_name: 'ليلى أحمد', school_name: '' }, { child_name: 'ليلى', school_name: 'الأمل' }), { child_name: 'ليلى أحمد', school_name: '' });
});

test('Arabic product search is compact and does not mutate catalog', () => {
    const products = [{ id: 1, name: 'كتاب تلوين' }, { id: 2, name: 'ستيكر' }];
    assert.deepEqual(filteredProducts(products, '  تلوين  '), [products[0]]);
    assert.deepEqual(filteredProducts(products, ''), products);
    assert.equal(products.length, 2);
});
