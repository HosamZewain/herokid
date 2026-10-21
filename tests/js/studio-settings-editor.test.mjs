import { test } from 'node:test';
import assert from 'node:assert/strict';
import { newStudioEntry } from '../../resources/js/studio-settings-editor.js';

test('new reusable entries carry stable independent ids and typed defaults without device paths', () => {
    for (const key of ['text_style_presets', 'product_production_profiles', 'fields']) {
        const first = newStudioEntry(key), second = newStudioEntry(key);
        assert.notEqual(first.id, second.id);
        assert.match(first.id, /^[a-f0-9-]{36}$/);
        assert.equal(JSON.stringify(first).includes('/Users/'), false);
        if (first.updatedAt) assert.match(first.updatedAt, /^\d{4}-.*\.\d{3}Z$/);
    }
    const profile = newStudioEntry('product_production_profiles');
    assert.equal(profile.schemaVersion, 1);
    assert.equal(typeof profile.cut.showBorder, 'boolean');
    assert.equal(typeof profile.canvas.widthMm, 'number');
    assert.deepEqual(profile.templates, { front: null, back: null });
    assert.deepEqual(profile.match, { productId: null, sku: null, componentKey: null });
    assert.deepEqual(profile.fields, []);
    assert.equal(typeof newStudioEntry('fields').visible, 'boolean');
    assert.equal(typeof newStudioEntry('text_style_presets').style.backgroundOpacity, 'number');
});
