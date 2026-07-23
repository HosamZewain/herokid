import { prepareImageForUpload } from './image-upload-preparer';

export function initializeIdentityPhotoUploader() {
    const form = document.querySelector('[data-identity-intake]');
    const input = form?.querySelector('[data-identity-photo-input]');
    const queue = form?.querySelector('[data-identity-photo-queue]');
    const hiddenInputs = form?.querySelector('[data-identity-photo-ids]');
    const error = form?.querySelector('[data-identity-photo-error]');
    const count = form?.querySelector('[data-identity-photo-count]');
    const submit = form?.querySelector('[data-identity-submit]');
    const configNode = form?.querySelector('[data-identity-upload-config]');

    if (!form || !input || !queue || !hiddenInputs || !submit || !configNode) {
        return;
    }

    let config = {};

    try {
        config = JSON.parse(configNode.textContent || '{}');
    } catch {
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const maximum = Number(config.maxFiles || 5);
    const minimum = Number(config.minimumFiles || 2);
    const maximumBytes = Number(config.maxSizeMb || 15) * 1024 * 1024;
    const concurrency = Math.max(1, Number(config.concurrency || 2));
    const maxLongEdge = Number(config.maxLongEdge || 2560);
    const jpegQuality = Math.min(1, Math.max(0.5, Number(config.jpegQuality || 90) / 100));
    const storageKey = 'herokid:child-identity:photo-upload-ids';
    const items = [];
    let activeUploads = 0;
    let submitting = false;

    const uid = () => crypto?.randomUUID
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(16).slice(2)}`;

    function setError(message = '') {
        error.textContent = message;
        error.classList.toggle('hidden', message === '');
    }

    function uploadedItems() {
        return items.filter((item) => item.status === 'uploaded' && item.uploadId);
    }

    function saveIds() {
        sessionStorage.setItem(storageKey, JSON.stringify(uploadedItems().map((item) => item.uploadId)));
    }

    function updateState() {
        const uploaded = uploadedItems();
        const busy = items.some((item) => ['waiting', 'preparing', 'uploading'].includes(item.status));
        const failed = items.some((item) => item.status === 'failed');

        hiddenInputs.replaceChildren(...uploaded.map((item) => {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'photo_upload_ids[]';
            hidden.value = item.uploadId;

            return hidden;
        }));
        count.textContent = `${uploaded.length} / ${maximum}`;
        submit.disabled = submitting || busy || failed || uploaded.length < minimum;
        submit.classList.toggle('opacity-60', submit.disabled);
        submit.classList.toggle('cursor-not-allowed', submit.disabled);
        saveIds();
    }

    function statusLabel(status) {
        return {
            waiting: 'في الانتظار',
            preparing: 'تجهيز الصورة',
            uploading: 'جاري الرفع',
            uploaded: 'تم الرفع',
            failed: 'فشل الرفع',
        }[status] || status;
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        })[character]);
    }

    function previewMarkup(item) {
        if (!item.previewUrl) {
            return '<div class="h-full w-full bg-slate-100"></div>';
        }

        const previewUrl = String(item.previewUrl);

        if (previewUrl.startsWith('blob:')) {
            return `<img src="${escapeHtml(previewUrl)}" alt="" class="h-full w-full object-cover">`;
        }

        let parsedUrl;

        try {
            parsedUrl = new URL(previewUrl, window.location.origin);
        } catch {
            return '<div class="h-full w-full bg-slate-100"></div>';
        }

        if (parsedUrl.origin !== window.location.origin) {
            return '<div class="h-full w-full bg-slate-100"></div>';
        }

        return `<img src="${escapeHtml(parsedUrl.href)}" alt="" class="h-full w-full object-cover">`;
    }

    function render() {
        queue.innerHTML = items.map((item) => `
            <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" data-identity-photo-row="${item.id}">
                <div class="relative aspect-square bg-slate-100">
                    ${previewMarkup(item)}
                    <span class="absolute right-2 top-2 rounded-full bg-white/95 px-2.5 py-1 text-[11px] font-black text-indigo-700 shadow">${escapeHtml(statusLabel(item.status))}</span>
                </div>
                <div class="p-3">
                    <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full ${item.status === 'failed' ? 'bg-red-500' : 'bg-indigo-600'} transition-all" style="width:${item.progress}%"></div>
                    </div>
                    <p class="mt-2 min-h-10 text-xs font-bold leading-5 ${item.status === 'failed' ? 'text-red-600' : 'text-slate-500'}">${escapeHtml(item.message)}</p>
                    <div class="mt-2 flex gap-2">
                        ${item.status === 'failed' ? '<button type="button" data-identity-photo-retry class="flex-1 rounded-lg bg-indigo-600 px-2 py-2 text-xs font-black text-white">إعادة</button>' : ''}
                        <button type="button" data-identity-photo-remove class="flex-1 rounded-lg bg-red-50 px-2 py-2 text-xs font-black text-red-600">حذف</button>
                    </div>
                </div>
            </article>
        `).join('');

        queue.querySelectorAll('[data-identity-photo-row]').forEach((row) => {
            const item = items.find((candidate) => candidate.id === row.dataset.identityPhotoRow);
            row.querySelector('[data-identity-photo-retry]')?.addEventListener('click', () => retry(item.id));
            row.querySelector('[data-identity-photo-remove]')?.addEventListener('click', () => remove(item.id));
        });
        updateState();
    }

    function patch(id, changes) {
        const item = items.find((candidate) => candidate.id === id);

        if (!item) {
            return;
        }

        Object.assign(item, changes);
        render();
    }

    function enqueue(files) {
        setError();
        const remaining = maximum - items.length;

        if (remaining <= 0) {
            setError(`يمكنك رفع ${maximum} صور كحد أقصى.`);
            return;
        }

        const selected = Array.from(files);

        if (selected.length > remaining) {
            setError(`تم اختيار أول ${remaining} صور فقط لأن الحد الأقصى ${maximum}.`);
        }

        selected.slice(0, remaining).forEach((file) => {
            const tooLarge = file.size > maximumBytes;
            items.push({
                id: uid(),
                file,
                name: file.name,
                previewUrl: URL.createObjectURL(file),
                status: tooLarge ? 'failed' : 'waiting',
                progress: 0,
                message: tooLarge
                    ? `حجم الصورة أكبر من ${config.maxSizeMb || 15} ميجا.`
                    : 'سيتم رفعها تلقائيًا.',
                uploadId: null,
                xhr: null,
            });
        });
        render();
        pump();
    }

    function pump() {
        while (activeUploads < concurrency) {
            const next = items.find((item) => item.status === 'waiting');

            if (!next) {
                break;
            }

            upload(next);
        }

        updateState();
    }

    async function upload(item) {
        activeUploads += 1;
        patch(item.id, { status: 'preparing', progress: 4, message: 'جاري تجهيز الصورة...' });
        let preparedFile;

        try {
            preparedFile = await prepareImageForUpload(item.file, { maxLongEdge, jpegQuality });
        } catch (conversionError) {
            activeUploads = Math.max(0, activeUploads - 1);
            patch(item.id, {
                status: 'failed',
                progress: 0,
                message: conversionError.message || 'تعذر تجهيز الصورة قبل الرفع.',
            });
            pump();

            return;
        }

        if (preparedFile !== item.file) {
            const previousPreview = item.previewUrl;
            patch(item.id, { previewUrl: URL.createObjectURL(preparedFile) });

            if (previousPreview?.startsWith('blob:')) {
                URL.revokeObjectURL(previousPreview);
            }
        }

        patch(item.id, { status: 'uploading', progress: 8, message: 'جاري الرفع تلقائيًا...' });
        const data = new FormData();
        data.append('photo', item.file);

        if (preparedFile !== item.file) {
            data.append('prepared_photo', preparedFile);
        }

        data.append('upload_session_token', config.sessionToken);
        data.append('upload_batch_token', config.batchToken || '');
        const xhr = new XMLHttpRequest();
        item.xhr = xhr;
        xhr.open('POST', config.uploadUrl);
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                patch(item.id, { progress: Math.min(95, Math.max(10, Math.round((event.loaded / event.total) * 90))) });
            }
        });
        xhr.onreadystatechange = () => {
            if (xhr.readyState !== XMLHttpRequest.DONE) {
                return;
            }

            activeUploads = Math.max(0, activeUploads - 1);
            let body = {};

            try {
                body = JSON.parse(xhr.responseText || '{}');
            } catch {
                body = {};
            }

            if (xhr.status >= 200 && xhr.status < 300 && body.id) {
                patch(item.id, {
                    status: 'uploaded',
                    progress: 100,
                    uploadId: body.id,
                    previewUrl: body.preview_url || item.previewUrl,
                    message: 'تم الرفع بنجاح.',
                });
            } else {
                patch(item.id, {
                    status: 'failed',
                    progress: 0,
                    message: body.message || 'تعذر رفع الصورة. حاول مرة أخرى.',
                });
            }

            pump();
        };
        xhr.onerror = () => {
            activeUploads = Math.max(0, activeUploads - 1);
            patch(item.id, {
                status: 'failed',
                progress: 0,
                message: 'انقطع الاتصال أثناء الرفع. حاول مرة أخرى.',
            });
            pump();
        };
        xhr.send(data);
    }

    function retry(id) {
        patch(id, { status: 'waiting', progress: 0, message: 'سيتم رفع هذه الصورة مرة أخرى.', uploadId: null });
        pump();
    }

    function remove(id) {
        const index = items.findIndex((item) => item.id === id);

        if (index === -1) {
            return;
        }

        const [item] = items.splice(index, 1);
        item.xhr?.abort?.();
        if (item.previewUrl?.startsWith('blob:')) {
            URL.revokeObjectURL(item.previewUrl);
        }

        if (item.uploadId && config.deleteUrlTemplate) {
            fetch(config.deleteUrlTemplate.replace('__ID__', item.uploadId), {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            }).catch(() => {});
        }

        render();
        pump();
    }

    input.addEventListener('change', (event) => {
        enqueue(event.target.files || []);
        input.value = '';
    });

    form.addEventListener('submit', (event) => {
        const uploaded = uploadedItems().length;
        const busy = items.some((item) => ['waiting', 'preparing', 'uploading'].includes(item.status));
        const failed = items.some((item) => item.status === 'failed');

        if (busy || failed || uploaded < minimum) {
            event.preventDefault();
            setError(
                busy
                    ? 'انتظر حتى يكتمل رفع الصور.'
                    : `ارفع ${minimum} صور ناجحة على الأقل، واحذف أو أعد محاولة أي صورة فشلت.`,
            );
            queue.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        submitting = true;
        submit.querySelector('[data-submit-label]').textContent = 'جاري بدء إنشاء الهوية...';
        updateState();
    });

    if (config.serverRejectedUploads) {
        sessionStorage.removeItem(storageKey);
    }

    try {
        const stored = JSON.parse(sessionStorage.getItem(storageKey) || '[]');

        if (Array.isArray(stored)) {
            stored.slice(0, maximum).forEach((id) => items.push({
                id: uid(),
                file: null,
                name: 'صورة مرفوعة',
                previewUrl: config.previewUrlTemplate?.replace('__ID__', id) || null,
                status: 'uploaded',
                progress: 100,
                uploadId: id,
                message: 'الصورة جاهزة.',
                xhr: null,
            }));
        }
    } catch {
        sessionStorage.removeItem(storageKey);
    }

    render();
}

export function initializeIdentityHeicRecovery() {
    const form = document.querySelector('[data-identity-heic-recovery]');
    const configNode = form?.querySelector('[data-identity-heic-recovery-config]');
    const error = form?.querySelector('[data-identity-heic-recovery-error]');
    const submit = form?.querySelector('button[type="submit"]');

    if (!form || !configNode || !submit) {
        return;
    }

    let config = {};

    try {
        config = JSON.parse(configNode.textContent || '{}');
    } catch {
        return;
    }

    const photos = Array.isArray(config.photos) ? config.photos : [];

    if (photos.length === 0) {
        return;
    }

    let recovered = false;
    let working = false;

    form.addEventListener('submit', async (event) => {
        if (recovered) {
            return;
        }

        event.preventDefault();

        if (working) {
            return;
        }

        working = true;
        submit.disabled = true;
        submit.textContent = 'جاري تجهيز صور iPhone...';
        error?.classList.add('hidden');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        try {
            for (const photo of photos) {
                const sourceResponse = await fetch(photo.sourceUrl, {
                    credentials: 'same-origin',
                    headers: { Accept: photo.mimeType || 'application/octet-stream' },
                });

                if (!sourceResponse.ok) {
                    throw new Error('تعذر قراءة إحدى صور iPhone المحفوظة.');
                }

                const sourceBlob = await sourceResponse.blob();
                const sourceFile = new File(
                    [sourceBlob],
                    photo.fileName || 'child-photo.heic',
                    { type: photo.mimeType || 'image/heic' },
                );
                const prepared = await prepareImageForUpload(sourceFile, {
                    maxLongEdge: Number(config.maxLongEdge || 2560),
                    jpegQuality: Number(config.jpegQuality || 0.9),
                });
                const payload = new FormData();
                payload.append('prepared_photo', prepared);
                const uploadResponse = await fetch(photo.uploadUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        Accept: 'application/json',
                    },
                    body: payload,
                });

                if (!uploadResponse.ok) {
                    const body = await uploadResponse.json().catch(() => ({}));
                    throw new Error(body.message || 'تعذر حفظ النسخة المتوافقة من صورة iPhone.');
                }
            }

            recovered = true;
            submit.textContent = 'جاري بدء المحاولة...';
            form.requestSubmit();
        } catch (recoveryError) {
            working = false;
            submit.disabled = false;
            submit.textContent = 'تجهيز صور iPhone وإعادة المحاولة';

            if (error) {
                error.textContent = recoveryError.message || 'تعذر تجهيز صور iPhone. أعد فتح الصفحة وحاول مرة أخرى.';
                error.classList.remove('hidden');
            }
        }
    });
}
