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
    return platform;
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-app-download]').forEach(root => initializeAppDownload(root));
}
