@can('orders.update')
@can('orders.photos.view')
@php
    $codexService = app(\App\Services\CodexScenes\ManualSceneService::class);
    $codexRuns = \App\Models\CodexSceneRun::where('order_id',$order->id)->latest()->limit(3)->get();
    $codexReason = $codexService->reason($order);
@endphp
<section id="codex-scenes-{{ $order->id }}" class="mt-4 rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
    <h4 class="text-sm font-black text-indigo-900">تجربة مشاهد القصة — Codex</h4>
    <p class="mt-2 text-xs text-gray-700">تشغيل يدوي لهذه القصة فقط باستخدام اشتراك Codex. 13 مشهدًا بالهوية المعتمدة؛ تتم إضافة النص وتجهيز ملف الطباعة في Studio بعد مراجعة الصور. لا يتم إرسال معاينة للعميل.</p>
    @if($codexReason)
        <p class="mt-3 text-sm font-bold text-amber-800">{{ $codexReason }}</p>
    @else
        <form method="POST" action="{{ route('admin.orders.codex-scenes.start',$order) }}" class="mt-3">
            @csrf
            <label class="flex gap-2 text-xs font-bold"><input type="checkbox" name="confirm" value="1" required> تشغيل 13 مشهدًا لهذه القصة واستخدام حصة الاشتراك.</label>
            <button class="mt-3 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white">توليد مشاهد القصة عبر Codex</button>
        </form>
    @endif
    <p data-codex-refresh-note class="mt-2 text-xs text-gray-500" aria-live="polite">تتحدث المشاهد وحالة التوليد تلقائيًا كل 10 ثوانٍ.</p>
    <div data-codex-progress data-url="{{ route('admin.orders.codex-scenes.progress',$order) }}">
        @include('admin.orders._codex-scenes-progress')
    </div>
</section>
@endcan
@endcan

@once
@push('scripts')
<script>
(() => {
    const panels = [...document.querySelectorAll('[data-codex-progress]')];
    const refresh = async () => {
        if (!document.hidden) {
            for (const panel of panels) {
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
                    note.textContent = 'تتحدث المشاهد وحالة التوليد تلقائيًا كل 10 ثوانٍ.';
                } catch (_) {
                    note.textContent = 'تعذر تحديث المشاهد مؤقتًا؛ سنحاول تلقائيًا. الصور الحالية محفوظة.';
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
