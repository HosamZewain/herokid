@once
    @push('scripts')
        <script>
            document.addEventListener('submit', async (event) => {
                const form = event.target.closest('[data-order-ajax-delete]');
                if (!form) return;

                event.preventDefault();
                if (!window.confirm(form.dataset.deleteConfirm || 'هل تريد حذف هذا الملف؟')) return;

                const button = form.querySelector('button[type="submit"], button:not([type])');
                const originalLabel = button?.textContent;
                if (button) {
                    button.disabled = true;
                    button.textContent = 'جارٍ الحذف…';
                }

                try {
                    const response = await fetch(form.action, {
                        method: form.method || 'POST',
                        body: new FormData(form),
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok || payload.success === false) {
                        throw new Error(payload.message || 'تعذر حذف الملف. حاول مرة أخرى.');
                    }

                    const scope = form.closest('[data-ajax-delete-scope]');
                    const deletedItem = form.closest('[data-ajax-delete-item]');
                    if (payload.deleted_attachment_id) {
                        document.querySelectorAll(`[data-order-attachment-id="${payload.deleted_attachment_id}"]`).forEach((element) => element.remove());
                    } else {
                        deletedItem?.remove();
                    }

                    if (scope) {
                        const count = scope.querySelectorAll('[data-ajax-delete-item]').length;
                        scope.querySelectorAll('[data-ajax-delete-count]').forEach((counter) => {
                            counter.textContent = `${count} ${counter.dataset.countLabel || ''}`.trim();
                        });
                        scope.querySelector('[data-ajax-delete-empty]')?.classList.toggle('hidden', count > 0);
                        scope.querySelectorAll('[data-ajax-hide-when-empty]').forEach((element) => {
                            element.classList.toggle('hidden', count === 0);
                        });
                    }

                    const notice = document.createElement('div');
                    notice.className = 'fixed bottom-5 left-5 z-[100] rounded-xl bg-emerald-700 px-4 py-3 text-sm font-black text-white shadow-xl';
                    notice.setAttribute('role', 'status');
                    notice.textContent = payload.message || 'تم الحذف بنجاح.';
                    document.body.appendChild(notice);
                    window.setTimeout(() => notice.remove(), 2500);
                } catch (error) {
                    window.alert(error.message || 'تعذر حذف الملف. حاول مرة أخرى.');
                    if (button) {
                        button.disabled = false;
                        button.textContent = originalLabel;
                    }
                }
            });
        </script>
    @endpush
@endonce
