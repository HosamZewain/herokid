export function mergeMessages(previous, incoming) {
    const byId = new Map(previous.map(message => [message.id, message]));
    for (const message of incoming) {
        const previous = byId.get(message.id);
        const attachments = (message.attachments ?? []).map((attachment, index) => Object.hasOwn(attachment, 'url')
            ? attachment : { ...attachment, url:previous?.attachments?.[index]?.url });
        byId.set(message.id, { ...message, attachments });
    }
    return [...byId.values()].sort((a, b) => a.date.localeCompare(b.date) || a.id - b.id);
}

export function safeAttachmentUrl(url) {
    try {
        const parsed = new URL(url);
        return parsed.protocol === 'https:' && !parsed.username && !parsed.password ? url : null;
    } catch { return null; }
}

export function shouldPoll(state, visibility) {
    return Boolean(state.configured && !state.closed && !state.suspended && !state.minimized && visibility === 'visible');
}

export function replyWindowOpen(value, now = Date.now()) {
    if (typeof value !== 'string') return false;
    const timestamp = new Date(value).getTime();
    return Number.isFinite(timestamp) && now >= timestamp && now - timestamp < 86400000;
}

// Persist only window identity/state, never customer names, messages or URLs.
export function normalizeConversationWindows(value) {
    if (!Array.isArray(value)) return [];
    const seen = new Set();
    return value.filter(item => {
        if (!item || !Number.isSafeInteger(item.orderId) || item.orderId < 1 || typeof item.minimized !== 'boolean' || seen.has(item.orderId)) return false;
        seen.add(item.orderId); return true;
    }).slice(0, 50).map(({ orderId, minimized }) => ({ orderId, minimized }));
}

export function readConversationWindows(storage, key) {
    try {
        const saved = JSON.parse(storage.getItem(key));
        return saved?.version === 1 ? normalizeConversationWindows(saved.windows) : [];
    } catch { return []; }
}

export function writeConversationWindows(storage, key, windows) {
    try {
        storage.setItem(key, JSON.stringify({ version:1, windows:normalizeConversationWindows(windows) }));
    } catch { /* Disabled storage/quota must not prevent conversation viewing. */ }
}

export function shortContactName(value) {
    const letters = typeof Intl.Segmenter === 'function'
        ? [...new Intl.Segmenter('ar', {granularity:'grapheme'}).segment(value)].map(item => item.segment) : Array.from(value);
    return letters.slice(0, 8).join('') + (letters.length > 8 ? '…' : '');
}

export function safeOrderUrl(value, origin) {
    try {
        const url = new URL(value, origin);
        return url.origin === origin && !url.username && !url.password && !url.search && !url.hash
            && /^\/admin\/orders\/groups\/[1-9]\d*$/.test(url.pathname) ? url.href : null;
    } catch { return null; }
}
