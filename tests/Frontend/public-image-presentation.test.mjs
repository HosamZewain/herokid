import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { handleStoryCoverError } from '../../resources/js/story-cover-recovery.js';

const template = readFileSync(new URL('../../resources/views/front/shop/show.blade.php', import.meta.url), 'utf8');
const helper = template.match(/const applyDisplayImage = \(image, source, thumbnail = false\) => \{[\s\S]*?\n                \};/)[0];
const presentations = {
    '/original-a.png': { src: '/display/a-960.webp', srcset: '/display/a-320.webp 320w, /display/a-960.webp 960w', thumbnail: '/display/a-320.webp' },
    '/original-b.png': { src: '/display/b-960.webp', srcset: '/display/b-320.webp 320w, /display/b-960.webp 960w', thumbnail: '/display/b-320.webp' },
};
const apply = vm.runInNewContext(`${helper}\napplyDisplayImage`, { imagePresentations: presentations });
function image() {
    return { dataset: {}, src: '', srcset: '', sizes: '', onerror: null,
        removeAttribute(name) { delete this[name]; } };
}

test('variant changes replace both src and srcset without retaining the previous product image', () => {
    const target = image();
    apply(target, '/original-a.png');
    apply(target, '/original-b.png');
    assert.equal(target.src, presentations['/original-b.png'].src);
    assert.equal(target.srcset, presentations['/original-b.png'].srcset);
    assert.equal(target.dataset.publicImageOriginal, '/original-b.png');
    assert.ok(!target.srcset.includes('/display/a-'));
});

test('selecting an unprepared image clears a previous responsive source', () => {
    const target = image();
    apply(target, '/original-a.png');
    apply(target, '/unprepared.png');
    assert.equal(target.src, '/unprepared.png');
    assert.equal(target.srcset, undefined);
    assert.equal(target.sizes, undefined);
});

test('gallery thumbnails use their own small display source and size', () => {
    const target = image();
    apply(target, '/original-a.png', true);
    assert.equal(target.src, '/display/a-320.webp');
    assert.equal(target.sizes, '96px');
});

test('a broken variant falls back once to the exact original', () => {
    const target = image();
    apply(target, '/original-b.png');
    target.onerror();
    assert.equal(target.src, '/original-b.png');
    assert.equal(target.srcset, undefined);
    assert.equal(target.onerror, null);
});

test('story retry clears responsive candidates before retrying the original', () => {
    const target = image();
    target.srcset = '/display/broken.webp 640w';
    target.sizes = '320px';
    target.dataset = { originalSrc: 'https://example.test/original.jpg', fallbackSrc: '/fallback.svg', coverRetryState: 'original' };
    handleStoryCoverError(target, 123);
    assert.equal(target.srcset, undefined);
    assert.equal(target.sizes, undefined);
    assert.equal(target.src, 'https://example.test/original.jpg?cover_retry=123');
});
