const ORIGINAL_STATE = 'original';
const RETRY_STATE = 'retry';
const FALLBACK_STATE = 'fallback';

export function withCacheBuster(url, value = Date.now()) {
    const parsed = new URL(url, 'https://hero-kid.com');
    parsed.searchParams.set('cover_retry', String(value));

    return parsed.toString();
}

export function handleStoryCoverError(image, cacheBuster = Date.now()) {
    const state = image?.dataset?.coverRetryState || ORIGINAL_STATE;
    const originalSrc = image?.dataset?.originalSrc || '';
    const fallbackSrc = image?.dataset?.fallbackSrc || '';

    if (state === ORIGINAL_STATE && originalSrc !== '') {
        image.dataset.coverRetryState = RETRY_STATE;
        image.src = withCacheBuster(originalSrc, cacheBuster);

        return RETRY_STATE;
    }

    if (state === RETRY_STATE && fallbackSrc !== '') {
        image.dataset.coverRetryState = FALLBACK_STATE;
        image.onerror = null;
        image.src = fallbackSrc;

        return FALLBACK_STATE;
    }

    image.dataset.coverRetryState = FALLBACK_STATE;
    image.onerror = null;

    return FALLBACK_STATE;
}

export const storyCoverRecovery = Object.freeze({
    handleError: handleStoryCoverError,
});
