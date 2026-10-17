import { test } from 'node:test';
import assert from 'node:assert/strict';
import { detectAppPlatform, initializeAppDownload, initializeAppMetrics } from '../../resources/js/app-download.js';

test('recognizes iPhone, iPad, iPod and Android without treating desktop Macs as iOS', () => {
    for (const ua of ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)', 'Mozilla/5.0 (iPad; CPU OS 18_0)', 'iPod']) {
        assert.equal(detectAppPlatform({ userAgent: ua }), 'ios');
    }
    assert.equal(detectAppPlatform({ userAgent: 'Mozilla/5.0 (Linux; Android 15; Pixel 9)' }), 'android');
    assert.equal(detectAppPlatform({ userAgent: 'Mozilla/5.0 Macintosh', platform: 'MacIntel', maxTouchPoints: 5 }), 'ios');
    assert.equal(detectAppPlatform({ userAgent: 'Mozilla/5.0 Macintosh', platform: 'MacIntel', maxTouchPoints: 0 }), 'desktop');
    assert.equal(detectAppPlatform({ userAgent: 'Windows NT 10.0', maxTouchPoints: 10 }), 'desktop');
    assert.equal(detectAppPlatform(), 'desktop');
});

test('metrics wait for visibility, share one view ID across both stores and send only once', async () => {
    const links = ['ios', 'android', 'ios', 'android'].map(platform => ({ dataset: { downloadTracked: 'true' }, href: `https://hero-kid.com/app/download/${platform}` }));
    const root = { dataset: { appVisitUrl: 'https://hero-kid.com/app/metrics/visit' }, querySelectorAll: () => links };
    let listener;
    const doc = { visibilityState: 'hidden', addEventListener: (event, callback) => { listener = callback; }, removeEventListener: () => {} };
    const requests = [];
    const runtime = { document: doc, crypto: { randomUUID: () => '6554b82b-5a47-4c39-8716-b5bb0b4d6fa7' }, fetch: (url, options) => { requests.push({ url, options }); return Promise.resolve({ ok: true }); } };
    initializeAppMetrics(root, runtime);
    assert.equal(requests.length, 0);
    links.forEach(link => assert.equal(new URL(link.href).searchParams.get('v'), '6554b82b-5a47-4c39-8716-b5bb0b4d6fa7'));
    doc.visibilityState = 'visible';
    listener();
    listener();
    initializeAppMetrics(root, runtime);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].options.credentials, 'same-origin');
    assert.equal(requests[0].options.cache, 'no-store');
    assert.equal(requests[0].options.headers['X-HeroKid-App-Metric'], '1');
});

test('failed metric fetch does not prevent store links being available', async () => {
    const link = { dataset: { downloadTracked: 'true' }, href: 'https://hero-kid.com/app/download/ios' };
    const root = { dataset: { appVisitUrl: 'https://hero-kid.com/app/metrics/visit' }, querySelectorAll: () => [link] };
    initializeAppMetrics(root, { document: { visibilityState: 'visible', removeEventListener: () => {} }, crypto: { randomUUID: () => '6554b82b-5a47-4c39-8716-b5bb0b4d6fa7' }, fetch: () => Promise.reject(new Error('offline')) });
    await new Promise(resolve => setImmediate(resolve));
    assert.ok(link.href.startsWith('https://hero-kid.com/app/download/ios?'));
});

test('a synchronously blocked fetch cannot break the download UI', () => {
    const link = { dataset: { downloadTracked: 'true' }, href: 'https://hero-kid.com/app/download/android' };
    const root = { dataset: { appVisitUrl: 'https://hero-kid.com/app/metrics/visit' }, querySelectorAll: () => [link] };
    assert.doesNotThrow(() => initializeAppMetrics(root, { document: { visibilityState: 'visible', removeEventListener: () => {} }, crypto: { randomUUID: () => '6554b82b-5a47-4c39-8716-b5bb0b4d6fa7' }, fetch: () => { throw new Error('blocked'); } }));
    assert.ok(link.href.includes('/app/download/android?v='));
});

test('highlights only the matching store in both CTA groups and never redirects', () => {
    const links = Array.from({ length: 2 }, () => ['ios', 'android'].map(platform => {
        const badge = { hidden: false };
        return { dataset: { storeLink: platform }, badge, recommended: null,
            classList: { toggle(name, value) { this.owner.recommended = value; }, owner: null },
            querySelector: () => badge };
    })).flat();
    links.forEach(link => { link.classList.owner = link; });
    const messages = [{ textContent: '' }, { textContent: '' }];
    const root = { dataset: {}, querySelectorAll: selector => selector === '[data-store-link]' ? links : messages };
    for (const [ua, expected] of [['Android', 'android'], ['iPhone', 'ios'], ['Windows', 'desktop']]) {
        assert.equal(initializeAppDownload(root, { userAgent: ua }), expected);
        assert.equal(root.dataset.platform, expected);
        links.forEach(link => {
            assert.equal(link.recommended, link.dataset.storeLink === expected);
            assert.equal(link.badge.hidden, link.dataset.storeLink !== expected);
        });
        assert.ok(messages.every(message => message.textContent.length > 0));
    }
});
