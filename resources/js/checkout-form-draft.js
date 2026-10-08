// Only this checkout form's contact/address fields, in the current tab, for up to 24 hours.
// Never store submission/CSRF tokens, files, cart contents, prices or previous-order decisions.
export const CHECKOUT_DRAFT_FIELDS = Object.freeze([
    'parent_name', 'phone', 'alternate_phone', 'delivery_country_id', 'delivery_governorate_id',
    'bosta_city_id', 'bosta_district_id', 'city', 'street', 'address_details',
]);
const PREFIX = 'herokid:checkout-form:v1:';
const ACTIVE_KEY = 'herokid:checkout-form:active';
const TTL = 24 * 60 * 60 * 1000;
const scopes = /^[a-f0-9-]{36}$/i;

function environment(options = {}) {
    const browser = options.browser ?? globalThis.window;
    let storage = options.storage;
    try { storage ??= browser?.sessionStorage; } catch { /* Storage can be blocked by privacy settings. */ }
    return { browser, storage, now: options.now ?? Date.now };
}

function read(storage, key, now) {
    const draft = JSON.parse(storage.getItem(key) || 'null');
    if (!draft || draft.version !== 1 || !Number.isFinite(draft.saved_at)
        || draft.saved_at > now() || now() - draft.saved_at >= TTL) return null;
    return draft;
}

export function clearCompletedCheckoutDrafts(root = document, options = {}) {
    const { storage, now } = environment(options);
    root.querySelectorAll('[data-checkout-draft-completed]').forEach((marker) => {
        const scope = marker.dataset.checkoutDraftCompleted;
        if (!scopes.test(scope || '')) return;
        try {
            // A tombstone also prevents a cached, pre-checkout page from saving the old draft again.
            storage?.setItem(PREFIX + scope, JSON.stringify({ version: 1, saved_at: now(), completed: true }));
        } catch { /* Successful checkout never depends on browser storage. */ }
    });
}

export function initializeCheckoutDraft(form, options = {}) {
    if (!form || form.checkoutDraft) return form?.checkoutDraft;
    const scope = form.dataset.checkoutDraftScope;
    if (!scopes.test(scope || '')) return;
    const { browser, storage, now } = environment(options);
    const key = PREFIX + scope;
    const field = (name) => form.elements.namedItem(name);
    const district = field('bosta_district_id');
    const zone = form.querySelector('[data-bosta-zone]');
    const hint = form.querySelector('[data-checkout-draft-status]');
    const status = (saved) => {
        if (!hint) return;
        hint.hidden = false;
        const text = saved
            ? 'بياناتك محفوظة مؤقتًا في هذا التبويب أثناء التسوق، وتُمسح بعد تأكيد الطلب.'
            : 'الحفظ المؤقت غير متاح في هذا المتصفح؛ لا تغادر السلة قبل تأكيد الطلب.';
        if (hint.textContent !== text) hint.textContent = text;
    };
    let usable = Boolean(storage);
    let oldFields = [];
    try { oldFields = JSON.parse(form.dataset.checkoutDraftOldFields || '[]'); } catch { /* Keep server defaults. */ }
    if (!Array.isArray(oldFields)) oldFields = [];
    let draft;
    try {
        if (!storage) throw new Error('storage_unavailable');
        // Isolate drafts across sign-in/account changes or a new Laravel session.
        const obsolete = [];
        for (let i = 0; i < storage.length; i++) {
            const existing = storage.key(i);
            if (existing?.startsWith(PREFIX) && existing !== key) obsolete.push(existing);
        }
        obsolete.forEach((existing) => storage.removeItem(existing));
        storage.setItem(ACTIVE_KEY, key);
        try { draft = read(storage, key, now); } catch { storage.removeItem(key); }
    } catch { usable = false; }

    if (draft?.values && !draft.completed && typeof draft.values === 'object') {
        CHECKOUT_DRAFT_FIELDS.forEach((name) => {
            const input = field(name);
            const value = draft.values[name];
            if (!input || oldFields.includes(name) || typeof value !== 'string' || value.length > 1000) return;
            if (name === 'bosta_district_id') {
                district.dataset.selectedId = value;
                district.dataset.selectedName = typeof draft.values.city === 'string' ? draft.values.city : '';
            } else if (input.tagName === 'SELECT') {
                // Do not restore an address selection removed/disabled in the delivery catalog.
                input.value = Array.from(input.options).some((option) => option.value === value) ? value : '';
            } else {
                input.value = value;
            }
        });
        if (zone && !oldFields.includes('bosta_district_id') && typeof draft.values.bosta_zone_id === 'string'
            && draft.values.bosta_zone_id.length <= 100) zone.dataset.selectedId = draft.values.bosta_zone_id;
        const country = field('delivery_country_id');
        const governorate = field('delivery_governorate_id');
        const option = Array.from(governorate?.options || []).find((entry) => entry.value === governorate.value);
        if (!oldFields.includes('delivery_governorate_id') && governorate?.value
            && option?.dataset.countryId !== country?.value) governorate.value = '';
        if (!governorate?.value && !oldFields.includes('bosta_district_id')) {
            if (district) district.dataset.selectedId = district.dataset.selectedName = '';
            if (zone) zone.dataset.selectedId = '';
            // Keep text typed before choosing a governorate. Clear only an obsolete, formerly selected address.
            if (draft.values.delivery_governorate_id && field('city') && !oldFields.includes('city')) field('city').value = '';
        }
    }

    const save = () => {
        if (!usable) return false;
        try {
            if (storage.getItem(ACTIVE_KEY) !== key || read(storage, key, now)?.completed) return false;
            const values = {};
            CHECKOUT_DRAFT_FIELDS.forEach((name) => {
                const input = field(name);
                if (input) values[name] = String(input.value ?? '').slice(0, 1000);
            });
            // Async official-area loading must not overwrite the saved selection with a loading placeholder.
            if (district) values.bosta_district_id = district.value || district.dataset.selectedId || '';
            if (zone) values.bosta_zone_id = zone.value || zone.dataset.selectedId || '';
            storage.setItem(key, JSON.stringify({ version: 1, saved_at: now(), values }));
            status(true);
            return true;
        } catch { usable = false; status(false); return false; }
    };
    const changed = () => {
        save();
        // Capture derived fields after existing country/governorate/area handlers, including non-bubbling changes.
        queueMicrotask(save);
    };
    form.addEventListener('input', changed, true);
    form.addEventListener('change', changed, true);
    form.addEventListener('submit', save);
    browser?.addEventListener('pagehide', save);
    form.ownerDocument?.addEventListener('visibilitychange', () => {
        if (form.ownerDocument.visibilityState === 'hidden') save();
    });
    form.checkoutDraft = { save };
    if (!usable) status(false);
    else save();
    return form.checkoutDraft;
}
