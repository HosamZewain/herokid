import { initializeAdminOrderWorkflow } from './admin-order-workflow.js';

// Keep the original form (including browser-owned FileLists) mounted after
// validation, network and server errors. Never automatically retry a write.
export function syncOrderProductVariant(row) {
    const select = row.querySelector('[data-product-variant]');
    if (!select) return;
    const quantity = Number(row.querySelector('[data-product-quantity]')?.value || 0);
    const legacyQuantity = Number(row.dataset.legacyVariantlessQuantity || 0);
    const legacy = legacyQuantity > 0 && quantity === legacyQuantity;
    if (quantity > 0 && !select.value && row.dataset.soleVariant && !legacy) {
        select.value = row.dataset.soleVariant;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }
    select.required = quantity > 0 && !legacy;
    row.querySelector('[data-product-variant-required]')?.toggleAttribute('hidden', !select.required);
    row.querySelector('[data-product-variant-hint]')?.toggleAttribute('hidden', quantity <= 0);
}

export function orderFormData(form, FormDataType = FormData) {
    const data = new FormDataType(form);
    const unselected = new Set();
    for (const [name, value] of data.entries()) {
        const match = name.match(/^products\[(\d+)\]\[quantity\]$/);
        if (match && (String(value).trim() === '' || Number(value) === 0)) unselected.add(match[1]);
    }
    // Large catalogs otherwise submit hundreds of irrelevant inputs and can
    // exhaust PHP max_input_vars. Negative/invalid quantities still validate.
    for (const name of Array.from(data.keys())) {
        const product = name.match(/^products\[(\d+)\]/);
        if (product && unselected.has(product[1])) data.delete(name);
    }
    return data;
}

export async function saveAdminOrderForm(form, {
    fetchImpl = fetch,
    makeBody = orderFormData,
    navigate = (url) => window.location.assign(url),
    showErrors = () => {},
    setStatus = () => {},
    refreshSession = async (target, http) => {
        const response = await http(window.location.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin', cache: 'no-store' });
        if (!response.ok || response.redirected) throw new Error('Session unavailable');
        const document = new DOMParser().parseFromString(await response.text(), 'text/html');
        const token = document.querySelector('[data-order-form] input[name="_token"]')?.value;
        if (!token) throw new Error('Session unavailable');
        target.querySelector('input[name="_token"]').value = token;
        return token;
    },
} = {}) {
    if (form.dataset.saving === '1') return;
    if (!form.reportValidity()) return;
    const body = makeBody(form);
    const controls = Array.from(form.querySelectorAll('input, select, textarea, button'));
    const states = controls.map((control) => control.disabled);
    form.dataset.saving = '1';
    form.setAttribute('aria-busy', 'true');
    controls.forEach((control) => { control.disabled = true; });
    showErrors({});
    setStatus('جارٍ رفع الصور وحفظ الطلب… لا تغلق الصفحة.');
    let saved = false;
    try {
        if (form.dataset.refreshSession === '1') {
            // Only on a user-initiated retry after expiry, never retry the write
            // automatically. Read a fresh CSRF token without replacing the form.
            body.set('_token', await refreshSession(form, fetchImpl));
            delete form.dataset.refreshSession;
        }
        const response = await fetchImpl(form.action, {
            method: 'POST', // Retains Laravel's existing _method=PUT for edits.
            body,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const isJson = response.headers.get('content-type')?.includes('application/json');
        const payload = isJson && !response.redirected ? await response.json() : null;
        if (response.ok && payload?.success && typeof payload.redirect_url === 'string') {
            saved = true;
            setStatus('تم حفظ الطلب. جارٍ فتح تفاصيله…');
            navigate(payload.redirect_url);
            return;
        }
        if (response.status === 422 && payload?.errors) {
            showErrors(payload.errors);
            setStatus('صحح الحقول الموضحة ثم احفظ مرة أخرى. الصور والبيانات ما زالت موجودة في الصفحة.');
        } else {
            if (response.status === 419 || response.status === 401 || response.redirected) form.dataset.refreshSession = '1';
            const message = response.status === 419 || response.status === 401 || response.redirected
                ? 'انتهت جلسة الدخول أو الحفظ. افتح الموقع وسجّل الدخول في تبويب آخر، ثم أعد المحاولة هنا دون تحديث هذه الصفحة.'
                : response.status === 413
                    ? 'حجم الصور تجاوز الحد المسموح للخادم. قلّل عدد أو حجم الصور ثم أعد المحاولة؛ بقية البيانات محفوظة في الصفحة.'
                    : response.status === 403
                        ? 'ليس لديك صلاحية حفظ هذا الطلب. راجع مسؤول النظام؛ الصور والبيانات ما زالت موجودة هنا.'
                        : 'تعذر تأكيد حفظ الطلب. الصور والبيانات ما زالت موجودة هنا. تحقق من قائمة الطلبات في تبويب آخر قبل إعادة الحفظ لتجنب التكرار.';
            showErrors({ form: [message] });
            setStatus(message);
        }
    } catch {
        const message = 'انقطع الاتصال أثناء الحفظ. الصور والبيانات ما زالت موجودة هنا. تحقق من قائمة الطلبات في تبويب آخر قبل إعادة الحفظ لتجنب التكرار.';
        showErrors({ form: [message] });
        setStatus(message);
    } finally {
        if (!saved) {
            controls.forEach((control, index) => { control.disabled = states[index]; });
            delete form.dataset.saving;
            form.removeAttribute('aria-busy');
        }
    }
}

export function initializeAdminOrderForms() {
    document.querySelectorAll('[data-admin-order-form]').forEach((root) => {
        const form = root.querySelector('[data-order-form]');
        if (!form || form.dataset.asyncBound === '1') return;
        form.dataset.asyncBound = '1';
        initializeAdminOrderWorkflow(root);
        const bindVariant = (row) => {
            if (row.dataset.variantBound) return;
            row.dataset.variantBound = '1';
            row.querySelector('[data-product-quantity]')?.addEventListener('input', () => syncOrderProductVariant(row));
            syncOrderProductVariant(row);
        };
        root.querySelectorAll('[data-product-row]').forEach(bindVariant);
        root.addEventListener('admin:product-added', (event) => bindVariant(event.detail.row));
        const errors = root.querySelector('[data-order-form-errors]');
        const status = root.querySelector('[data-order-save-status]');
        const showErrors = (messages) => {
            root.querySelectorAll('[data-async-field-error]').forEach((node) => node.remove());
            form.querySelectorAll('[data-order-invalid]').forEach((input) => {
                input.removeAttribute('aria-invalid');
                delete input.dataset.orderInvalid;
            });
            errors.replaceChildren();
            errors.hidden = Object.keys(messages).length === 0;
            if (errors.hidden) return;
            const heading = document.createElement('p');
            heading.className = 'mb-2 font-black';
            heading.textContent = 'راجع البيانات التالية — الصور والبيانات المختارة لم تُحذف:';
            errors.append(heading);
            Object.entries(messages).forEach(([field, values]) => {
                let parts = field.split('.');
                let input;
                while (parts.length && !input) {
                    const name = parts[0] + parts.slice(1).map((part) => `[${part}]`).join('');
                    input = Array.from(form.elements).find((element) => element.name === name || element.name === `${name}[]`);
                    parts.pop();
                }
                const text = (Array.isArray(values) ? values : [values]).join(' ');
                const line = document.createElement('p');
                line.textContent = `• ${text}`;
                errors.append(line);
                if (input) {
                    input.setAttribute('aria-invalid', 'true');
                    input.dataset.orderInvalid = '1';
                    const inline = document.createElement('p');
                    inline.dataset.asyncFieldError = '1';
                    inline.className = 'mt-1 text-xs font-bold text-red-600';
                    inline.textContent = text;
                    input.insertAdjacentElement('afterend', inline);
                }
            });
            errors.scrollIntoView({ behavior: 'smooth', block: 'center' });
            errors.focus({ preventScroll: true });
        };
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            saveAdminOrderForm(form, {
                showErrors,
                setStatus: (message) => { if (status) status.textContent = message; },
            });
        });
    });
}
