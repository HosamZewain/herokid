import { test } from 'node:test';
import assert from 'node:assert/strict';
import { detectAppPlatform, initializeAppDownload } from '../../resources/js/app-download.js';

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
