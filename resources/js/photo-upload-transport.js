// Browser storage is optional. Blocked/quota-limited storage must never stop checkout.
export function safeStorage(name) {
    return Object.fromEntries(['getItem', 'setItem', 'removeItem'].map((method) => [method, (...args) => {
        try { return window[name][method](...args); } catch { return null; }
    }]));
}

let sessionRefresh;
export async function refreshPhotoSession(config, ids = []) {
    const url = new URL(config.sessionUrl || '/photo-uploads/session', window.location.origin);
    ids.forEach((id) => url.searchParams.append('ids[]', id));
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('تعذر التحقق من جلسة الصور. حاول مرة أخرى.');
    const data = await response.json();
    config.sessionToken = data.upload_session_token;
    document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', data.csrf_token);
    document.querySelectorAll('input[name="_token"]').forEach((input) => { input.value = data.csrf_token; });
    document.querySelectorAll('input[name="upload_session_token"]').forEach((input) => { input.value = data.upload_session_token; });
    window.dispatchEvent(new CustomEvent('herokid:photo-session', { detail: { token: data.upload_session_token } }));
    return data;
}

export function observePhotoSession(config, items, render, pump) {
    let knownToken = config.sessionToken;
    window.addEventListener('herokid:photo-session', ({ detail }) => {
        config.sessionToken = detail.token;
        if (knownToken === detail.token) return;
        knownToken = detail.token;
        for (let i = items.length - 1; i >= 0; i--) {
            const item = items[i];
            if (item.status !== 'uploaded') continue;
            if (!item.file) items.splice(i, 1);
            else Object.assign(item, { status: 'waiting', uploadId: null, progress: 0, message: 'تم تجديد الجلسة؛ إعادة رفع الصورة.' });
        }
        render(); pump();
    });
}

export function appendPhoto(body, original, prepared, preserveOriginal = true) {
    body.append('photo', preserveOriginal ? original : prepared);
    // Keep archival originals. Only send a second file when browsers/hosting need a
    // compatible derivative; ordinary JPEG/PNG/WebP do not need duplicate transfer.
    if (preserveOriginal && prepared !== original && /heic|heif|avif/i.test(`${original.type} ${original.name}`)) {
        body.append('prepared_photo', prepared);
    }
}

export async function uploadPhoto(config, body, { onProgress, signal, onRequest } = {}) {
    for (let attempt = 0; attempt < 2; attempt++) {
        if (signal?.aborted) throw new DOMException('Cancelled', 'AbortError');
        body.set('upload_session_token', config.sessionToken);
        body.set('upload_batch_token', config.batchToken || '');
        const result = await new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            onRequest?.(xhr);
            const abort = () => xhr.abort();
            const cleanup = () => signal?.removeEventListener('abort', abort);
            xhr.open('POST', config.uploadUrl);
            xhr.timeout = 180000;
            xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]')?.content || '');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.onprogress = (event) => { if (event.lengthComputable) onProgress?.(Math.min(95, Math.round(event.loaded / event.total * 90))); };
            xhr.onload = () => {
                cleanup();
                let data = {};
                try { data = JSON.parse(xhr.responseText); } catch { /* Non-JSON proxy error. */ }
                resolve({ status: xhr.status, data });
            };
            xhr.onerror = () => { cleanup(); reject(new Error('انقطع الاتصال أثناء الرفع. أعد محاولة هذه الصورة.')); };
            xhr.ontimeout = () => { cleanup(); reject(new Error('استغرق الرفع وقتًا طويلًا. تحقق من الاتصال ثم أعد المحاولة.')); };
            xhr.onabort = () => { cleanup(); reject(new DOMException('Cancelled', 'AbortError')); };
            signal?.addEventListener('abort', abort, { once: true });
            xhr.send(body);
        });
        if (result.status === 419 && attempt === 0) {
            sessionRefresh ??= refreshPhotoSession(config).finally(() => { sessionRefresh = null; });
            const session = await sessionRefresh;
            config.sessionToken = session.upload_session_token;
            continue;
        }
        if (result.status >= 200 && result.status < 300 && result.data.id) return result.data;
        const messages = { 413: 'حجم الصورة أكبر من حد الخادم. اختر صورة أصغر.', 419: 'انتهت الجلسة. حدّث الصفحة ثم أعد المحاولة.', 429: 'طلبات رفع كثيرة. انتظر دقيقة ثم أعد المحاولة.' };
        throw new Error(messages[result.status] || result.data.message || 'تعذر رفع الصورة. حاول مرة أخرى.');
    }
}

export async function restorePhotos(config, stored) {
    if (!Array.isArray(stored) || !stored.length) return [];
    const ids = stored.map((item) => typeof item === 'string' ? item : item?.id).filter((id) => typeof id === 'string');
    const session = await refreshPhotoSession(config, ids.slice(0, 10));
    const valid = new Set(session.valid_upload_ids || []);
    return stored.filter((item) => valid.has(typeof item === 'string' ? item : item?.id));
}
