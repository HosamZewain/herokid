import { prepareImageForUpload } from './image-upload-preparer';

function trackEvent(name, properties = {}, standard = false) {
    if (typeof window.gtag === 'function') {
        window.gtag('event', name, properties);
    }

    if (typeof window.fbq === 'function') {
        window.fbq(standard ? 'track' : 'trackCustom', name, properties);
    }
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

export function initializeFootballStories() {
    const root = document.querySelector('[data-football-landing]');
    const form = root?.querySelector('[data-football-form]');

    if (!root || !form) {
        return;
    }

    const cards = Array.from(form.querySelectorAll('[data-football-story-card]'));
    const startButtons = Array.from(root.querySelectorAll('[data-start-customization], [data-sticky-continue]'));
    const count = root.querySelector('[data-selected-count]');
    const names = root.querySelector('[data-selected-names]');
    const total = root.querySelector('[data-selected-total]');
    const childAge = form.querySelector('[data-child-age]');
    const ageWarning = form.querySelector('[data-age-warning]');
    const customization = root.querySelector('[data-customization-section]');
    const floatingWhatsApp = document.querySelector('[data-floating-whatsapp]');
    const storageKey = 'herokid:football-landing:draft';
    let submitting = false;

    if (!sessionStorage.getItem('herokid:analytics:ViewFootballLanding')) {
        trackEvent('ViewFootballLanding', { content_category: 'football_stories' });
        sessionStorage.setItem('herokid:analytics:ViewFootballLanding', '1');
    }

    const selectedCards = () => cards.filter((card) => card.querySelector('[data-story-checkbox]')?.checked);

    function formatMoney(value) {
        return `${Number(value || 0).toLocaleString('ar-EG', { maximumFractionDigits: 2 })} ج.م`;
    }

    function updateAgeWarning() {
        const age = Number(childAge?.value || 0);
        const incompatible = selectedCards().filter((card) => {
            const ages = String(card.dataset.ages || '').split(',').map(Number).filter(Boolean);

            return age > 0 && ages.length > 0 && !ages.includes(age);
        });

        if (!ageWarning) {
            return;
        }

        if (incompatible.length === 0) {
            ageWarning.classList.add('hidden');
            ageWarning.textContent = '';
            return;
        }

        ageWarning.textContent = `تنبيه: عمر الطفل خارج الفئة العمرية المقترحة لـ ${incompatible.map((card) => `«${card.dataset.storyTitle}»`).join('، ')}. يمكنك الاستمرار إذا كان المحتوى مناسبًا لطفلك.`;
        ageWarning.classList.remove('hidden');
    }

    function saveDraft() {
        const data = {
            selectedStoryIds: selectedCards().map((card) => Number(card.dataset.storyId)),
            childName: form.elements.child_name?.value || '',
            childAge: form.elements.child_age?.value || '',
            childGender: form.elements.child_gender?.value || '',
            giftNote: form.elements.gift_note?.value || '',
            interests: form.elements.interests?.value || '',
            parentNotes: form.elements.parent_notes?.value || '',
        };

        sessionStorage.setItem(storageKey, JSON.stringify(data));
    }

    function renderSelection() {
        const selected = selectedCards();
        const value = selected.reduce((sum, card) => sum + Number(card.dataset.price || 0), 0);

        cards.forEach((card) => {
            const checked = card.querySelector('[data-story-checkbox]')?.checked;
            card.classList.toggle('border-indigo-600', checked);
            card.classList.toggle('ring-2', checked);
            card.classList.toggle('sm:ring-4', checked);
            card.classList.toggle('ring-indigo-100', checked);
            card.classList.toggle('border-slate-200', !checked);
            card.querySelector('[data-selected-badge]')?.classList.toggle('hidden', !checked);
            const toggle = card.querySelector('[data-story-toggle]');
            if (toggle) {
                toggle.textContent = checked ? 'إلغاء الاختيار' : 'اختار القصة';
                toggle.setAttribute('aria-pressed', checked ? 'true' : 'false');
            }
        });

        if (count) count.textContent = selected.length.toLocaleString('ar-EG');
        if (names) names.textContent = selected.length
            ? selected.map((card) => card.dataset.storyTitle).join('، ')
            : 'اختار قصة للبدء';
        if (total) total.textContent = formatMoney(value);
        startButtons.forEach((button) => { button.disabled = selected.length === 0; });
        updateAgeWarning();
        saveDraft();
    }

    cards.forEach((card) => {
        const checkbox = card.querySelector('[data-story-checkbox]');
        card.querySelector('[data-story-toggle]')?.addEventListener('click', () => {
            if (!checkbox) return;

            checkbox.checked = !checkbox.checked;
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        });
        checkbox?.addEventListener('change', () => {
            renderSelection();
            trackEvent('SelectStory', {
                content_ids: [card.dataset.storyId],
                content_name: card.dataset.storyTitle,
                value: Number(card.dataset.price || 0),
                currency: 'EGP',
                selected: checkbox.checked,
            });
        });
        card.querySelectorAll('[data-story-detail-link]').forEach((link) => {
            link.addEventListener('click', () => {
                trackEvent('ViewContent', {
                    content_type: 'product',
                    content_ids: [card.dataset.storyId],
                    content_name: card.dataset.storyTitle,
                    value: Number(card.dataset.price || 0),
                    currency: 'EGP',
                }, true);
            });
        });
    });

    function updateFloatingWhatsApp() {
        if (!floatingWhatsApp || !customization) return;

        floatingWhatsApp.classList.toggle('hidden', window.innerWidth < 768);
    }

    window.addEventListener('scroll', updateFloatingWhatsApp, { passive: true });
    window.addEventListener('resize', updateFloatingWhatsApp);
    updateFloatingWhatsApp();

    startButtons.forEach((button) => button.addEventListener('click', () => {
        if (selectedCards().length === 0) {
            document.getElementById('football-story-selection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }

        trackEvent('StartCustomization', {
            num_items: selectedCards().length,
            value: selectedCards().reduce((sum, card) => sum + Number(card.dataset.price || 0), 0),
            currency: 'EGP',
        });
        customization?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        window.setTimeout(() => form.elements.child_name?.focus({ preventScroll: true }), 450);
    }));

    childAge?.addEventListener('change', () => {
        updateAgeWarning();
        saveDraft();
    });

    form.querySelectorAll('input:not([type="file"]), select, textarea').forEach((field) => {
        field.addEventListener('change', saveDraft);
        field.addEventListener('input', saveDraft);
    });

    try {
        const draft = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
        const hasServerInput = form.elements.child_name?.value
            || form.querySelectorAll('[data-story-checkbox]:checked').length > 0;

        if (!hasServerInput && draft && typeof draft === 'object') {
            cards.forEach((card) => {
                card.querySelector('[data-story-checkbox]').checked = (draft.selectedStoryIds || []).includes(Number(card.dataset.storyId));
            });
            if (form.elements.child_name) form.elements.child_name.value = draft.childName || '';
            if (form.elements.child_age) form.elements.child_age.value = draft.childAge || '';
            if (draft.childGender && form.elements.child_gender) form.elements.child_gender.value = draft.childGender;
            if (form.elements.gift_note) form.elements.gift_note.value = draft.giftNote || '';
            if (form.elements.interests) form.elements.interests.value = draft.interests || '';
            if (form.elements.parent_notes) form.elements.parent_notes.value = draft.parentNotes || '';
        }
    } catch {
        sessionStorage.removeItem(storageKey);
    }

    renderSelection();
    initializePhotoUploader(form, trackEvent);
    initializeValidation(form, selectedCards, () => submitting, (value) => { submitting = value; });
}

function initializePhotoUploader(form, trackEventCallback) {
    const container = form.querySelector('[data-football-photo-uploader]');
    const input = container?.querySelector('[data-football-photo-input]');
    const queue = container?.querySelector('[data-football-photo-queue]');
    const hiddenInputs = container?.querySelector('[data-football-photo-ids]');
    const count = container?.querySelector('[data-football-photo-count]');
    const error = container?.querySelector('[data-football-photo-error]');
    const configNode = form.querySelector('[data-football-upload-config]');

    if (!container || !input || !queue || !hiddenInputs || !count || !error || !configNode) {
        return;
    }

    let config = {};
    try {
        config = JSON.parse(configNode.textContent || '{}');
    } catch {
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const maximum = Number(config.maxFiles || 3);
    const minimum = Number(config.minimumFiles || 2);
    const maximumBytes = Number(config.maxSizeMb || 15) * 1024 * 1024;
    const concurrency = Math.max(1, Number(config.concurrency || 2));
    const storageKey = 'herokid:football-landing:photo-ids';
    const items = [];
    let activeUploads = 0;

    const uploaded = () => items.filter((item) => item.status === 'uploaded' && item.uploadId);
    const setError = (message = '') => {
        error.textContent = message;
        error.classList.toggle('hidden', !message);
    };

    function persist() {
        sessionStorage.setItem(storageKey, JSON.stringify(uploaded().map((item) => item.uploadId)));
    }

    function render() {
        hiddenInputs.replaceChildren(...uploaded().map((item) => {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'photo_upload_ids[]';
            hidden.value = item.uploadId;
            return hidden;
        }));
        count.textContent = `${uploaded().length.toLocaleString('ar-EG')} / ${maximum.toLocaleString('ar-EG')}`;
        input.disabled = items.length >= maximum;
        queue.innerHTML = items.map((item) => `
            <article data-football-photo-row="${escapeHtml(item.id)}" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="relative aspect-square bg-slate-100">
                    ${item.previewUrl ? `<img src="${escapeHtml(item.previewUrl)}" alt="معاينة ${escapeHtml(item.name)}" class="h-full w-full object-cover">` : ''}
                    <span class="absolute start-2 top-2 rounded-full bg-white/95 px-2 py-1 text-[10px] font-black ${item.status === 'failed' ? 'text-red-600' : 'text-indigo-700'}">${item.status === 'uploaded' ? 'تم الرفع' : item.status === 'failed' ? 'فشل الرفع' : 'جاري الرفع'}</span>
                </div>
                <div class="p-2">
                    <p class="truncate text-center text-[11px] font-bold text-slate-600" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</p>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full ${item.status === 'failed' ? 'bg-red-500' : 'bg-indigo-600'}" style="width:${item.progress}%"></div></div>
                    <div class="mt-2 flex gap-2">
                        ${item.status === 'failed' ? '<button type="button" data-photo-retry class="min-h-11 flex-1 rounded-lg bg-indigo-600 px-2 text-xs font-black text-white">إعادة</button>' : ''}
                        <button type="button" data-photo-remove class="min-h-11 flex-1 rounded-lg bg-red-50 px-2 text-xs font-black text-red-600">حذف</button>
                    </div>
                </div>
            </article>
        `).join('');
        queue.querySelectorAll('[data-football-photo-row]').forEach((row) => {
            const item = items.find((candidate) => candidate.id === row.dataset.footballPhotoRow);
            row.querySelector('[data-photo-retry]')?.addEventListener('click', () => {
                Object.assign(item, { status: 'waiting', progress: 0, uploadId: null });
                render();
                pump();
            });
            row.querySelector('[data-photo-remove]')?.addEventListener('click', () => remove(item));
        });
        persist();
    }

    function patch(item, changes) {
        Object.assign(item, changes);
        render();
    }

    async function upload(item) {
        activeUploads += 1;
        patch(item, { status: 'preparing', progress: 5 });
        let prepared;

        try {
            prepared = await prepareImageForUpload(item.file, {
                maxLongEdge: Number(config.maxLongEdge || 2560),
                jpegQuality: Math.min(1, Math.max(0.5, Number(config.jpegQuality || 90) / 100)),
            });
        } catch (exception) {
            activeUploads -= 1;
            patch(item, { status: 'failed', progress: 0 });
            setError(exception.message || 'تعذر تجهيز الصورة.');
            pump();
            return;
        }

        if (prepared !== item.file) {
            const oldPreview = item.previewUrl;
            item.previewUrl = URL.createObjectURL(prepared);
            if (oldPreview?.startsWith('blob:')) URL.revokeObjectURL(oldPreview);
        }

        patch(item, { status: 'uploading', progress: 10 });
        const payload = new FormData();
        payload.append('photo', item.file);
        if (prepared !== item.file) payload.append('prepared_photo', prepared);
        payload.append('upload_session_token', config.sessionToken);
        payload.append('upload_batch_token', config.batchToken || '');
        const xhr = new XMLHttpRequest();
        item.xhr = xhr;
        xhr.open('POST', config.uploadUrl);
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) patch(item, { progress: Math.min(95, Math.round((event.loaded / event.total) * 90)) });
        });
        xhr.onreadystatechange = () => {
            if (xhr.readyState !== XMLHttpRequest.DONE) return;
            activeUploads = Math.max(0, activeUploads - 1);
            let body = {};
            try { body = JSON.parse(xhr.responseText || '{}'); } catch { body = {}; }

            if (xhr.status >= 200 && xhr.status < 300 && body.id) {
                patch(item, { status: 'uploaded', progress: 100, uploadId: body.id, previewUrl: body.preview_url || item.previewUrl });
                trackEventCallback('UploadPhoto', { upload_count: uploaded().length });
                setError();
            } else {
                patch(item, { status: 'failed', progress: 0 });
                setError(body.message || 'تعذر رفع الصورة. حاول مرة أخرى.');
            }
            pump();
        };
        xhr.onerror = () => {
            activeUploads = Math.max(0, activeUploads - 1);
            patch(item, { status: 'failed', progress: 0 });
            setError('انقطع الاتصال أثناء رفع الصورة. حاول مرة أخرى.');
            pump();
        };
        xhr.send(payload);
    }

    function pump() {
        while (activeUploads < concurrency) {
            const next = items.find((item) => item.status === 'waiting');
            if (!next) break;
            upload(next);
        }
    }

    function remove(item) {
        const index = items.indexOf(item);
        if (index < 0) return;
        items.splice(index, 1);
        item.xhr?.abort?.();
        if (item.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(item.previewUrl);
        if (item.uploadId && config.deleteUrlTemplate) {
            fetch(config.deleteUrlTemplate.replace('__ID__', item.uploadId), {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            }).catch(() => {});
        }
        render();
        pump();
    }

    input.addEventListener('change', () => {
        setError();
        const remaining = maximum - items.length;
        const selected = Array.from(input.files || []);
        if (selected.length > remaining) setError(`تم اختيار أول ${remaining} صور فقط لأن الحد الأقصى ${maximum}.`);
        const oversized = selected.slice(0, remaining).filter((file) => file.size > maximumBytes);
        if (oversized.length > 0) {
            setError(`حجم كل صورة يجب ألا يزيد عن ${Number(config.maxSizeMb || 15).toLocaleString('ar-EG')} ميجا.`);
        }
        selected.slice(0, remaining).forEach((file) => items.push({
            id: window.crypto?.randomUUID ? window.crypto.randomUUID() : `${Date.now()}-${Math.random()}`,
            file,
            name: file.name,
            previewUrl: URL.createObjectURL(file),
            status: file.size > maximumBytes ? 'failed' : 'waiting',
            progress: 0,
            uploadId: null,
            xhr: null,
        }));
        input.value = '';
        render();
        pump();
    });

    if (config.serverRejectedUploads) sessionStorage.removeItem(storageKey);
    try {
        const stored = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
        if (Array.isArray(stored)) stored.slice(0, maximum).forEach((id) => items.push({
            id: `stored-${id}`,
            file: null,
            name: 'صورة طفل مرفوعة',
            previewUrl: config.previewUrlTemplate?.replace('__ID__', id),
            status: 'uploaded',
            progress: 100,
            uploadId: id,
            xhr: null,
        }));
    } catch {
        sessionStorage.removeItem(storageKey);
    }
    render();
}

function initializeValidation(form, selectedCards, isSubmitting, setSubmitting) {
    function clearClientErrors() {
        form.querySelectorAll('[data-client-error]').forEach((node) => node.remove());
        form.querySelectorAll('[aria-invalid="true"][data-client-invalid]').forEach((field) => {
            field.removeAttribute('aria-invalid');
            field.removeAttribute('data-client-invalid');
        });
    }

    function addError(field, message) {
        const anchor = field?.closest('fieldset, [data-football-photo-uploader]') || field;
        if (!anchor) return;
        const error = document.createElement('p');
        error.dataset.clientError = 'true';
        error.className = 'mt-2 text-sm font-bold text-red-600';
        error.setAttribute('role', 'alert');
        error.textContent = message;
        anchor.insertAdjacentElement('afterend', error);
        field?.setAttribute('aria-invalid', 'true');
        field?.setAttribute('data-client-invalid', 'true');
    }

    form.addEventListener('submit', (event) => {
        if (isSubmitting()) {
            event.preventDefault();
            return;
        }

        clearClientErrors();
        const errors = [];
        const name = form.elements.child_name;
        const age = form.elements.child_age;
        const gender = form.querySelector('[name="child_gender"]:checked');
        const photoInputs = form.querySelectorAll('[name="photo_upload_ids[]"]');
        if (selectedCards().length === 0) errors.push([form.querySelector('[data-story-checkbox]'), 'اختر قصة كرة قدم واحدة على الأقل.']);
        if (!name?.value.trim()) errors.push([name, 'اكتب اسم الطفل الأول.']);
        if (!age?.value) errors.push([age, 'اختر عمر الطفل.']);
        if (!gender) errors.push([form.querySelector('[name="child_gender"]'), 'اختر جنس الطفل.']);
        if (photoInputs.length < 2) errors.push([form.querySelector('[data-football-photo-uploader]'), 'ارفع صورتين واضحتين للطفل على الأقل وانتظر اكتمال الرفع.']);

        if (errors.length > 0) {
            event.preventDefault();
            errors.forEach(([field, message]) => addError(field, message));
            const first = errors[0][0];
            first?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            window.setTimeout(() => first?.focus?.({ preventScroll: true }), 450);
            return;
        }

        setSubmitting(true);
        form.querySelector('[data-football-submit]')?.setAttribute('aria-busy', 'true');
    });
}

export function trackHeroKidEvent(name, properties = {}, standard = false) {
    trackEvent(name, properties, standard);
}
