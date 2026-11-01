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
    @foreach($codexRuns as $codexRun)
        <div class="mt-4 rounded-xl border bg-white p-3">
            <p class="text-sm font-black">{{ ['preparing'=>'تجهيز الملفات','queued'=>'في انتظار Codex','running'=>'جاري توليد المشاهد','completed'=>'اكتملت المشاهد — راجعها قبل تجهيز Studio','uncertain'=>'تحتاج مراجعة — لن نكرر التوليد تلقائيًا','stopping'=>'جارٍ الإيقاف','stopped'=>'متوقفة'][$codexRun->status] ?? $codexRun->status }} · {{ count($codexRun->scenes ?? []) }} / 13</p>
            <p class="mt-1 text-xs text-gray-500">{{ app_datetime($codexRun->created_at,'d/m/Y h:i A') }} · حدّث الصفحة لمتابعة المشاهد الجديدة.</p>
            @if($codexRun->active_slot)
                <form method="POST" action="{{ route('admin.orders.codex-scenes.stop',[$order,$codexRun]) }}" class="mt-2">@csrf<button class="rounded-lg border border-red-200 px-3 py-2 text-xs font-bold text-red-700">إيقاف التجربة والاحتفاظ بالمشاهد</button></form>
            @endif
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($codexRun->scenes ?? [] as $sceneNumber=>$sceneData)
                    <a href="{{ route('admin.orders.codex-scenes.image',[$order,$codexRun,$sceneNumber]) }}" target="_blank" rel="noopener" class="rounded-lg border p-2">
                        <img src="{{ route('admin.orders.codex-scenes.image',[$order,$codexRun,$sceneNumber]) }}" alt="مشهد {{ $sceneNumber }} — {{ $order->child_name }}" class="w-full rounded-lg" loading="lazy">
                        <span class="text-xs font-bold">مشهد {{ $sceneNumber }} · {{ $sceneData['width'] }} × {{ $sceneData['height'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
</section>
@endcan
@endcan
