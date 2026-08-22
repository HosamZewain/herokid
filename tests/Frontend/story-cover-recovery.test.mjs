import assert from 'node:assert/strict';
import test from 'node:test';

import { handleStoryCoverError } from '../../resources/js/story-cover-recovery.js';

function coverImage() {
    return {
        dataset: {
            coverRetryState: 'original',
            originalSrc: 'https://hero-kid.com/storage/stories/example.jpg?v=1787400000',
            fallbackSrc: 'https://hero-kid.com/images/site/featured_generic_herokid_v2.png',
        },
        src: 'https://hero-kid.com/storage/stories/example.jpg?v=1787400000',
        onerror: () => {},
    };
}

test('the first error retries the original cover with a cache buster', () => {
    const image = coverImage();

    assert.equal(handleStoryCoverError(image, 12345), 'retry');
    assert.equal(image.dataset.coverRetryState, 'retry');
    assert.equal(
        image.src,
        'https://hero-kid.com/storage/stories/example.jpg?v=1787400000&cover_retry=12345',
    );
    assert.equal(image.dataset.originalSrc, 'https://hero-kid.com/storage/stories/example.jpg?v=1787400000');
});

test('the second error switches to the fallback and disables the error callback', () => {
    const image = coverImage();
    handleStoryCoverError(image, 12345);

    assert.equal(handleStoryCoverError(image, 12346), 'fallback');
    assert.equal(image.dataset.coverRetryState, 'fallback');
    assert.equal(image.src, image.dataset.fallbackSrc);
    assert.equal(image.onerror, null);
});

test('additional errors cannot create a retry loop', () => {
    const image = coverImage();
    handleStoryCoverError(image, 12345);
    handleStoryCoverError(image, 12346);
    const fallbackSrc = image.src;

    assert.equal(handleStoryCoverError(image, 12347), 'fallback');
    assert.equal(image.src, fallbackSrc);
    assert.equal(image.onerror, null);
});
