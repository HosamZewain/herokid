export function initializeAdminFileUploads() {
    // Delegate so replacing the attachment/preview section does not lose handlers.
    document.addEventListener('submit', async (event) => {
        const form = event.target;
        const input = form.querySelector?.('input[type="file"][name="attachments[]"], input[type="file"][name="preview_images[]"]');
        if (!input || !window.location.pathname.startsWith('/admin/')) return;
        event.preventDefault();
        if (form.dataset.uploading === '1') return;
        const files = Array.from(input.files || []);
        if (!files.length) return;
        const message = form.querySelector('[data-upload-feedback]') || document.createElement('p');
        message.dataset.uploadFeedback = ''; message.setAttribute('role', 'status');
        message.className = 'col-span-full text-sm font-bold text-indigo-700';
        form.append(message);
        if (files.length > 10) { message.textContent = 'يمكن رفع 10 ملفات كحد أقصى.'; return; }
        const max = (input.name === 'attachments[]' ? 50 : 20) * 1024 * 1024;
        if (files.some((file) => file.size > max)) { message.textContent = `حجم الملف الواحد يجب ألا يتجاوز ${max / 1024 / 1024} ميجا.`; return; }
        const base = new FormData(form); base.delete(input.name);
        const buttons = Array.from(form.querySelectorAll('button[type="submit"], button:not([type])'));
        buttons.forEach(button => { button.disabled = true; });
        input.disabled = true; form.dataset.uploading = '1';
        let completed = 0;
        try {
            for (const file of files) {
                const body = new FormData();
                for (const [key, value] of base) body.append(key, value);
                body.append(input.name, file);
                await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', form.action); xhr.timeout = 300000;
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.upload.onprogress = (e) => { message.textContent = `تم حفظ ${completed} من ${files.length} — رفع الملف التالي ${e.lengthComputable ? Math.round(e.loaded / e.total * 100) + '%' : ''}`; };
                    xhr.onload = () => {
                        let data = {}; try { data = JSON.parse(xhr.responseText); } catch { /* Proxy response. */ }
                        if (xhr.status >= 200 && xhr.status < 300 && data.success) resolve();
                        else reject(new Error(xhr.status === 413 ? 'حجم الملف أكبر من حد الاستضافة.' : xhr.status === 419 ? 'انتهت الجلسة. حدّث الصفحة قبل إعادة المحاولة.' : data.message || 'تعذر حفظ الملف.'));
                    };
                    xhr.onerror = xhr.ontimeout = () => reject(new Error('انقطع الاتصال. تحقق من الملفات المحفوظة قبل إعادة محاولة الملف الحالي.'));
                    xhr.send(body);
                });
                completed++;
            }
            message.textContent = `تم حفظ ${completed} ملف بنجاح. جارٍ تحديث العرض…`;
            const response = await fetch(window.location.href, { headers: { Accept: 'text/html' }, cache: 'no-store' });
            if (!response.ok) throw new Error('الملفات محفوظة، لكن تعذر تحديث العرض. حدّث الصفحة.');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            // A first preview creates a customer link; refresh its WhatsApp actions too.
            const nextMessages = Array.from(page.querySelectorAll('[data-whatsapp-messages]'));
            document.querySelectorAll('[data-whatsapp-messages]').forEach((current, index) => {
                if (nextMessages[index]) current.replaceWith(nextMessages[index]);
            });
            const nextForm = Array.from(page.forms).find(candidate => candidate.action === form.action);
            const scope = form.closest('[data-ajax-delete-scope]');
            const nextScope = nextForm?.closest('[data-ajax-delete-scope]');
            if (scope && nextScope) {
                if (scope.tagName === 'DETAILS') nextScope.open = scope.open;
                scope.replaceWith(nextScope);
            } else message.textContent = `تم حفظ ${completed} ملف. حدّث الصفحة لعرضها.`;
        } catch (error) {
            message.textContent = `تم حفظ ${completed} من ${files.length}. ${error.message}`;
        } finally {
            // Successful files are never submitted again when retrying a partial batch.
            const pending = new DataTransfer(); files.slice(completed).forEach(file => pending.items.add(file));
            input.files = pending.files; input.disabled = false;
            buttons.forEach(button => { button.disabled = false; });
            delete form.dataset.uploading;
        }
    });
}
