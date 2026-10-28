import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/front/cart/index.blade.php', import.meta.url), 'utf8');
const calculation = view.slice(view.indexOf('function updateTotals(fee)'), view.indexOf('function filterGovernorates()'));

for (const [type, value, fee, expected, free] of [
    ['fixed', 3000, 100, 70, false], ['fixed', 3000, 150, 120, false],
    ['percent', 3000, 100, 70, false], ['percent', 3000, 150, 105, false],
    ['fixed', 3000, 20, 0, false], ['percent', 1250, 99.99, 87.49, false],
    ['fixed', 3000, 150, 0, true],
]) {
    test(`cart ${type} ${value}: ${fee} becomes ${expected}, free=${free}`, () => {
        const nodes = new Map();
        const field = {};
        const context = vm.createContext({
            shippingDiscount: { enabled: true, type, value },
            shippingOffer: free ? { minimum_cents: 10000, ends_at: '2099-01-01T00:00:00Z' } : null,
            shippingClockOffset: 0, subtotal: 200, discount: 0, lastDeliveryFee: 0,
            checkoutForm: { querySelector: () => field },
            document: { querySelectorAll: (selector) => {
                if (!nodes.has(selector)) nodes.set(selector, {});
                return [nodes.get(selector)];
            } },
            formatMoney: (number) => number,
        });
        vm.runInContext(`${calculation}\nupdateTotals(${fee});`, context);
        assert.equal(Number(field.value), expected);
        assert.equal(nodes.get('[data-delivery-fee]').textContent, expected);
        assert.equal(nodes.get('[data-cart-total]').textContent, 200 + expected);
        assert.ok(Math.abs(nodes.get('[data-shipping-discount]').textContent - (fee - expected)) < 0.00001);
        // Recalculation after a cart update must start from the base fee, never compound.
        vm.runInContext('updateTotals(lastDeliveryFee);', context);
        assert.equal(Number(field.value), expected);
    });
}
