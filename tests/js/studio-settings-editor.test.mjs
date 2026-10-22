import { test } from 'node:test';
import assert from 'node:assert/strict';
import { newStudioEntry, studioDisplayScale, studioSettingsGroups } from '../../resources/js/studio-settings-editor.js';
import { uploadStudioAsset, studioUploadError } from '../../resources/js/studio-asset-upload.js';

test('daily settings have three tabs without a product-template editing section', () => {
    assert.deepEqual(studioSettingsGroups.map(([title]) => title), ['الصور واللوجو', 'النص والطباعة', 'توليد الأغلفة']);
    const keys = studioSettingsGroups.flatMap(([, fields]) => fields);
    assert.equal(keys.includes('product_production_profiles'), false);
    assert.equal(keys.includes('text_style_presets'), true);
    assert.equal(new Set(keys).size, keys.length);
});

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

test('friendly percentages preserve API fractions and do not scale product dimensions', () => {
    for (const path of ['brand_logo.placement.widthPercent', 'brand_logo.placement.opacity', 'brand_logo.placement.x', 'brand_logo.placement.y', 'default_back_cover.crop.focalX', 'text_style_presets.0.style.backgroundOpacity']) {
        assert.equal(studioDisplayScale(path), 100);
        assert.equal(28 / studioDisplayScale(path), 0.28);
    }
    for (const path of ['product_production_profiles.0.photo.rect.widthPercent', 'default_back_cover.crop.zoom', 'product_production_profiles.0.canvas.widthMm']) assert.equal(studioDisplayScale(path), 1);
});

function fakeRequest(onProgress) {
    const xhr = { upload: {}, headers: {}, open(method, url) { this.method = method; this.url = url; }, setRequestHeader(k, v) { this.headers[k] = v; }, send(body) { this.body = body; }, abort() { this.onabort(); } };
    const file = new File(['image-test'], 'test.png', { type: 'image/png' });
    const task = uploadStudioAsset({ url: '/admin/studio-settings/assets', csrf: 'test-csrf', file, onProgress, xhrFactory: () => xhr });
    return { xhr, ...task };
}

test('uploads use protected endpoint, report bytes then processing, and resolve only after server acceptance', async () => {
    const progress = [], { xhr, promise } = fakeRequest(value => progress.push(value));
    assert.equal(xhr.method, 'POST');
    assert.equal(xhr.url, '/admin/studio-settings/assets');
    assert.equal(xhr.timeout, 180000);
    assert.equal(xhr.withCredentials, true);
    assert.equal(xhr.headers['X-CSRF-TOKEN'], 'test-csrf');
    assert.equal(xhr.body.get('file').name, 'test.png');
    xhr.upload.onprogress({ lengthComputable: false });
    xhr.upload.onprogress({ lengthComputable: true, loaded: 5, total: 10 });
    xhr.upload.onload();
    assert.deepEqual(progress, [null, 50, 100]);
    let completed = false; promise.then(() => completed = true);
    await Promise.resolve(); assert.equal(completed, false);
    xhr.status = 201; xhr.responseText = JSON.stringify({ data: { id: 'asset-id', preview_url: '/protected-preview' } }); xhr.onload();
    assert.deepEqual(await promise, { id: 'asset-id', preview_url: '/protected-preview' });
});

test('HTTP failures have specific actionable messages and never report successful upload', async () => {
    for (const status of [401, 403, 413, 419, 422, 429, 500]) {
        const { xhr, promise } = fakeRequest();
        xhr.status = status; xhr.responseText = '<html>proxy failure or login</html>'; xhr.onload();
        await assert.rejects(promise, error => error.message === studioUploadError(status));
    }
    assert.match(studioUploadError(413), /حجم/);
    assert.match(studioUploadError(419), /جلسة/);
    assert.match(studioUploadError(429), /دقيقة/);
});

test('network loss, timeout, and cancellation reject safely and allow a fresh retry', async () => {
    for (const handler of ['onerror', 'ontimeout', 'onabort']) {
        const { xhr, promise, cancel } = fakeRequest();
        if (handler === 'onabort') cancel(); else xhr[handler]();
        await assert.rejects(promise);
    }
    const { xhr, promise } = fakeRequest();
    xhr.status = 201; xhr.responseText = JSON.stringify({ data: { id: 'retry', preview_url: '/preview' } }); xhr.onload();
    assert.equal((await promise).id, 'retry');
});

test('login redirects, malformed JSON and missing asset acknowledgement are not success', async () => {
    for (const response of ['<html>login</html>', '{}', '{"data":{"id":"missing-preview"}}']) {
        const { xhr, promise } = fakeRequest(); xhr.status = 200; xhr.responseText = response; xhr.onload();
        await assert.rejects(promise, /تأكيد حفظ/);
    }
});
