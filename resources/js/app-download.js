// Client-only detection keeps the same HTML safe for shared/CDN caches.
export function detectAppPlatform(device = {}) {
    const ua = device.userAgent || '';
    if (/android/i.test(ua)) return 'android';
    if (/iPad|iPhone|iPod/i.test(ua)
        || (device.platform === 'MacIntel' && device.maxTouchPoints > 1)) return 'ios';
    return 'desktop';
}

export function initializeAppDownload(root, device = navigator) {
    const platform = detectAppPlatform(device);
    root.dataset.platform = platform;
    root.querySelectorAll('[data-store-link]').forEach(link => {
        const recommended = link.dataset.storeLink === platform;
        link.classList.toggle('is-recommended', recommended);
        const badge = link.querySelector('[data-store-recommended]');
        if (badge) badge.hidden = !recommended;
    });
    root.querySelectorAll('[data-device-message]').forEach(label => {
        label.textContent = platform === 'ios' ? 'جهازك iPhone أو iPad؟ حمّل من App Store.'
            : platform === 'android' ? 'جهازك Android؟ حمّل من Google Play.'
                : 'اختار متجر موبايلك وابدأ رحلتك مع HeroKid.';
    });
    initializeAppMetrics(root);
    return platform;
}

export function initializeAppMetrics(root, runtime = {}) {
    if (!root.dataset.appVisitUrl || root.dataset.metricsInitialized === 'true') return;
    const doc = runtime.document ?? globalThis.document;
    const crypto = runtime.crypto ?? globalThis.crypto;
    const fetch = runtime.fetch ?? globalThis.fetch;
    if (!doc || !crypto?.randomUUID || !fetch) return;
    root.dataset.metricsInitialized = 'true';
    const view = crypto.randomUUID();
    root.querySelectorAll('[data-store-link]').forEach(link => {
        if (link.dataset.downloadTracked !== 'true') return;
        const url = new URL(link.href);
        url.searchParams.set('v', view);
        link.href = url.href;
    });
    let sent = false;
    const record = () => {
        if (sent || doc.visibilityState === 'hidden') return;
        sent = true;
        doc.removeEventListener('visibilitychange', record);
        const url = new URL(root.dataset.appVisitUrl);
        url.searchParams.set('v', view);
        // Separate no-store endpoint: cached page CSRF tokens are not relied on.
        try {
            Promise.resolve(fetch(url.href, { method: 'GET', credentials: 'same-origin',
                mode: 'same-origin', cache: 'no-store', keepalive: true,
                headers: { 'X-HeroKid-App-Metric': '1' } })).catch(() => {});
        } catch { /* Analytics must not affect store navigation. */ }
    };
    if (doc.visibilityState === 'hidden') doc.addEventListener('visibilitychange', record);
    else record();
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-app-download]').forEach(root => initializeAppDownload(root));
}
