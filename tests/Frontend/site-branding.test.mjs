import assert from 'node:assert/strict';
import test from 'node:test';
import { drawContainedLogo, siteLogoUrl } from '../../resources/js/site-branding.js';

test('exports use the current same-origin site logo with a safe default', () => {
    assert.equal(siteLogoUrl({ querySelector: () => ({ content: 'https://hero-kid.com/site-logo/384.png' }) }), 'https://hero-kid.com/site-logo/384.png');
    assert.equal(siteLogoUrl({ querySelector: () => null }), '/site-logo/384.png');
});

test('wide and tall logos fit invoice boxes without stretching or cropping', () => {
    for (const [width, height] of [[2009, 783], [100, 300]]) {
        let result;
        const image = { naturalWidth: width, naturalHeight: height };
        drawContainedLogo({ drawImage: (...args) => { result = args; } }, image, 10, 20, 100, 100);
        const [, x, y, drawWidth, drawHeight] = result;
        assert.ok(x >= 10 && y >= 20 && drawWidth <= 100 && drawHeight <= 100);
        assert.ok(Math.abs(drawWidth / drawHeight - width / height) < 0.0001);
    }
});
