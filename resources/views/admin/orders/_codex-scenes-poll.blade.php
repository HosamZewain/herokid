@once
@push('scripts')
<script>
(() => {
    const panels = [...document.querySelectorAll('[data-codex-progress], [data-codex-generation-banner]')];
    const refresh = async () => {
        if (!document.hidden) {
            for (const panel of panels) {
                const isBanner = panel.hasAttribute?.('data-codex-generation-banner');
                const note = panel.parentElement.querySelector('[data-codex-refresh-note]');
                try {
                    const response = await fetch(panel.dataset.url, {
                        headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store',
                        signal: AbortSignal.timeout(15000),
                    });
                    if (!response.ok || response.redirected) throw new Error('refresh');
                    const data = await response.json();
                    if (typeof data.html !== 'string') throw new Error('refresh');
                    // Keep existing images/forms intact when nothing has changed.
                    if (panel.dataset.version !== data.version) {
                        panel.innerHTML = data.html;
                        panel.dataset.version = data.version;
                    }
                    if (isBanner) note.hidden = !data.html.trim();
                    note.textContent = isBanner ? 'حالة التوليد وعدد الصفحات المحفوظة يتحدثان تلقائيًا كل 10 ثوانٍ.' : 'تتحدث المشاهد وحالة التوليد تلقائيًا كل 10 ثوانٍ.';
                } catch (_) {
                    note.hidden = false;
                    note.textContent = isBanner ? 'تعذر تحديث حالة Codex؛ آخر حالة معروضة قد تكون قديمة. تحقق قبل بدء العمل اليدوي؛ سنحاول تلقائيًا.' : 'تعذر تحديث المشاهد مؤقتًا؛ سنحاول تلقائيًا. الصور الحالية محفوظة.';
                }
            }
        }
        window.setTimeout(refresh, 10000);
    };
    window.setTimeout(refresh, 10000);
})();
</script>
@endpush
@endonce
