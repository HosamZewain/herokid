@once
    @push('scripts')
        <script>
            const syncBulkDeleteForm = (form) => {
                if (!form) return;

                const scope = form.closest('[data-ajax-delete-scope]');
                const checkboxes = Array.from(scope?.querySelectorAll('[data-bulk-delete-checkbox]') || []);
                const selected = checkboxes.filter((checkbox) => checkbox.checked);
                const selectAll = form.querySelector('[data-bulk-delete-all]');
                const button = form.querySelector('button[type="submit"]');
                const label = form.querySelector('[data-bulk-delete-selection]');

                if (selectAll) {
                    selectAll.checked = checkboxes.length > 0 && selected.length === checkboxes.length;
                    selectAll.indeterminate = selected.length > 0 && selected.length < checkboxes.length;
                }
                if (button) button.disabled = selected.length === 0;
                if (label) label.textContent = selected.length ? `تم تحديد ${selected.length}` : (label.dataset.emptyLabel || 'لم يتم تحديد ملفات');
            };

            document.addEventListener('change', (event) => {
                const selectAll = event.target.closest('[data-bulk-delete-all]');
                const checkbox = event.target.closest('[data-bulk-delete-checkbox]');
                if (!selectAll && !checkbox) return;

                const scope = event.target.closest('[data-ajax-delete-scope]');
                const form = selectAll
                    ? selectAll.closest('[data-order-bulk-delete]')
                    : document.getElementById(checkbox.getAttribute('form'));

                if (selectAll && scope) {
                    scope.querySelectorAll('[data-bulk-delete-checkbox]').forEach((item) => {
                        item.checked = selectAll.checked;
                    });
                }

                syncBulkDeleteForm(form);
            });

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
                    const deletedAttachmentIds = payload.deleted_attachment_ids || (payload.deleted_attachment_id ? [payload.deleted_attachment_id] : []);
                    const deletedPreviewIds = payload.deleted_preview_ids || (payload.deleted_preview_id ? [payload.deleted_preview_id] : []);

                    if (deletedAttachmentIds.length) {
                        deletedAttachmentIds.forEach((id) => {
                            document.querySelectorAll(`[data-order-attachment-id="${id}"]`).forEach((element) => element.remove());
                        });
                    } else if (deletedPreviewIds.length) {
                        deletedPreviewIds.forEach((id) => {
                            document.querySelectorAll(`[data-order-preview-id="${id}"]`).forEach((element) => element.remove());
                        });
                    } else if (payload.deleted_attachment_id) {
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
                        syncBulkDeleteForm(scope.querySelector('[data-order-bulk-delete]'));
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
