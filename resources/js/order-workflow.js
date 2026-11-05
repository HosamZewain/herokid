export function initializeOrderWorkflow(root = document) {
    const updateBadges = (id, group) => {
        document.querySelectorAll(`[data-workflow-badge-group="${id}"]`).forEach(container => {
            ['status', 'payment_status', 'printing_status', 'shipping_status'].forEach(key => {
                const badge = container.querySelector(`[data-workflow-badge="${key}"]`);
                if (badge) {
                    badge.textContent = group[`${key}_label`];
                    badge.className = `inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-black ${group[`${key}_color`] || 'bg-gray-100 text-gray-700'}`;
                }
            });
            const paid = container.querySelector('[data-workflow-paid]');
            const remaining = container.querySelector('[data-workflow-remaining]');
            if (paid) paid.textContent = group.paid_amount;
            if (remaining) remaining.textContent = group.remaining_amount;
        });
    };

    root.querySelectorAll('[data-workflow-status-form]').forEach(form => {
        if (form.dataset.workflowInitialized) return;
        form.dataset.workflowInitialized = '1';
        const paymentStatus = form.querySelector('[data-workflow-payment-status]');
        const amount = form.querySelector('[data-workflow-paid-amount]');
        const method = form.querySelector('[data-workflow-payment-method]');
        const partialField = form.querySelector('[data-workflow-partial-field]');
        const methodField = form.querySelector('[data-workflow-method-field]');
        const submit = form.querySelector('[data-workflow-submit]');
        const errors = form.querySelector('[data-workflow-errors]');
        const message = form.closest('[data-workflow-panel]').querySelector('[data-workflow-message]');

        const refreshPayment = () => {
            const value = paymentStatus.value;
            const behavior = paymentStatus.selectedOptions[0]?.dataset.behavior || value;
            const isPartial = behavior === 'partially_paid';
            const isUnpaid = behavior === 'unpaid';
            partialField.hidden = !isPartial;
            methodField.hidden = isUnpaid;
            amount.disabled = value !== 'partially_paid';
            if (behavior !== value) amount.disabled = !isPartial;
            amount.required = isPartial;
            method.disabled = isUnpaid;
            method.required = !isUnpaid;
        };

        paymentStatus.addEventListener('change', refreshPayment);
        refreshPayment();

        form.addEventListener('submit', async event => {
            event.preventDefault();
            errors.classList.add('hidden');
            errors.textContent = '';
            message.textContent = '';
            submit.disabled = true;
            const original = submit.textContent;
            submit.textContent = 'جاري الحفظ…';

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const payload = await response.json();
                if (!response.ok) {
                    const messages = payload.errors ? Object.values(payload.errors).flat() : [payload.message || 'تعذر تحديث الحالات.'];
                    throw new Error(messages.join(' '));
                }
                message.textContent = payload.message;
                updateBadges(payload.group.representative_id, payload.group);
            } catch (error) {
                errors.textContent = error.message || 'تعذر تحديث الحالات.';
                errors.classList.remove('hidden');
            } finally {
                submit.disabled = false;
                submit.textContent = original;
            }
        });
    });
}
