import { initializeOrderWorkflow } from './order-workflow';

export function initializeOrderListPanels() {
    const load = async root => {
        if (!root || root.dataset.loaded || root.dataset.loading) return;
        root.dataset.loading = '1';
        const abort = new AbortController();
        const timeout = setTimeout(() => abort.abort(), 15000);
        const status = root.querySelector('[role="status"]');
        if (status) status.textContent = 'جارٍ تحميل التفاصيل…';
        try {
            const response = await fetch(root.dataset.url, {credentials:'same-origin', cache:'no-store', signal:abort.signal, headers:{Accept:'application/json'}});
            if (!response.ok || response.redirected) throw new Error('access');
            const data = await response.json();
            if (typeof data.html !== 'string') throw new Error('invalid');
            root.innerHTML = data.html || '<p class="p-3 text-xs">لا توجد رسائل متاحة لهذا الطلب.</p>';
            root.dataset.loaded = '1';
            initializeOrderWorkflow(root);
        } catch {
            if (status) status.textContent = 'تعذر تحميل التفاصيل. أعد المحاولة أو افتح الطلب.';
            if (!root.querySelector('[data-retry-list-panel]')) {
                const retry = document.createElement('button'); retry.type = 'button'; retry.dataset.retryListPanel = '1';
                retry.textContent = 'إعادة المحاولة'; retry.className = 'm-2 text-xs font-bold text-indigo-700 underline';
                retry.addEventListener('click', () => { retry.remove(); void load(root); });root.append(retry);
            }
        } finally { clearTimeout(timeout); delete root.dataset.loading; }
    };
    document.addEventListener('toggle', event => {
        if (event.target.matches('[data-order-list-details]') && event.target.open) void load(event.target.querySelector('[data-order-list-panel]'));
    }, true);
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-workflow-toggle]');
        if (!button) return;
        const row = document.querySelector(`[data-workflow-panel-row="${button.dataset.workflowToggle}"]`);
        if (row) { row.classList.toggle('hidden'); if (!row.classList.contains('hidden')) void load(row.querySelector('[data-order-list-panel]')); }
    });
}
