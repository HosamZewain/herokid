@can('orders.update')
@can('orders.photos.view')
@php
    $codexService = app(\App\Services\CodexScenes\ManualSceneService::class);
    $codexRuns = \App\Models\CodexSceneRun::where('order_id',$order->id)->latest()->limit(3)->get();
    $codexReason = $codexService->reason($order);
@endphp
<section id="codex-scenes-{{ $order->id }}" class="mt-4 rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
    <h4 class="text-sm font-black text-indigo-900">توليد مشاهد القصة — Codex</h4>
    <p class="mt-2 text-xs text-gray-700">توليد هذه القصة باستخدام اشتراك Codex. 13 مشهدًا بالهوية المعتمدة؛ تتم إضافة النص وتجهيز ملف الطباعة في Studio بعد مراجعة الصور. لا يتم إرسال معاينة للعميل.</p>
    <p class="mt-2 text-xs text-gray-700">التشغيل التلقائي {{ config('codex_scenes.automatic') ? 'مفعّل للقصص المعتمدة والمدفوعة' : 'متوقف' }} · <a class="underline" href="{{ route('admin.order-automation.codex-scenes') }}">متابعة طابور القصص</a></p>
    @if($codexReason)
        <p class="mt-3 text-sm font-bold text-amber-800">{{ $codexReason }}</p>
    @else
        <form method="POST" action="{{ route('admin.orders.codex-scenes.start',$order) }}" class="mt-3">
            @csrf
            <label class="flex gap-2 text-xs font-bold"><input type="checkbox" name="confirm" value="1" required> إضافة هذه القصة إلى طابور التوليد واستخدام حصة الاشتراك.</label>
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

@include('admin.orders._codex-scenes-poll')
