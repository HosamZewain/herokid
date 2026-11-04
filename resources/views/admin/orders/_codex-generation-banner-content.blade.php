@isset($workspaceSummary)
<section class="mb-4 rounded-2xl border-2 border-indigo-200 bg-white p-5" aria-label="وضع الطلب في الأوتوميشن">
    <h3 class="mb-3 text-lg font-black">وضع الطلب في الأوتوميشن</h3>
    @include('admin.orders._automation-summary', ['group'=>['representative_id'=>$workspaceOrder->id,'automation_summary'=>$workspaceSummary]])
    @if(($workspaceSummary['recheck'] ?? null) && app(\App\Services\Orders\OrderAccess::class)->controls($workspaceOrder, auth()->user())
        && auth()->user()->hasPermission('orders.photos.view') && auth()->user()->hasPermission('orders.production_prompt.manage'))
        <form class="mt-3" method="POST" action="{{ route('admin.orders.workspace.control', $workspaceOrder) }}">
            @csrf
            <input type="hidden" name="action" value="recheck">
            <input type="hidden" name="version" value="{{ $workspaceSummary['control_version'] }}">
            <input type="hidden" name="run_id" value="{{ $workspaceSummary['recheck']['run_id'] }}">
            <input type="hidden" name="run_version" value="{{ $workspaceSummary['recheck']['version'] }}">
            <p class="mb-2 text-sm">هذا المسار توقف قبل أي توليد. الفحص يوضح البيانات الناقصة؛ وإذا كانت سليمة يبدأ تجهيز المعاينة بالصور الصالحة لنفس الطفل.</p>
            <button class="rounded-lg bg-indigo-700 px-4 py-2 font-bold text-white">فحص واستكمال تجهيز المعاينة</button>
        </form>
    @endif
    @if($workspaceSummary['state'] === 'intervention')
        @can('orders.update')<a class="mt-3 inline-block font-bold underline" href="{{ route('admin.orders.groups.edit', $workspaceOrder) }}">تصحيح بيانات الطلب والصور</a>@endcan
        <p class="mt-2 text-xs text-gray-600">رقم المتابعة: {{ $workspaceOrder->id }}. تغيير حالة الطلب وحده لا يحل سبب التوقف.</p>
    @endif
</section>
@endisset
@if($generationRows->isNotEmpty())
<section class="rounded-2xl border-2 border-indigo-400 bg-indigo-50 p-5 shadow-sm" aria-label="حالة توليد الطلب في Codex">
    <h3 class="text-lg font-black text-indigo-900">الطلب محجوز لمسار Codex</h3>
    <p class="mt-1 text-sm font-bold text-indigo-900">لا تبدأ العمل على الصفحات يدويًا قبل انتهاء المسار أو تأكيد إيقافه واستلام الطلب.</p>
    @foreach($generationRows as $generationRow)
        <div class="mt-4 rounded-xl bg-white p-3" data-generation-run="{{ $generationRow['id'] }}">
            <p class="text-sm font-bold text-gray-900">{{ $generationRow['label'] }} @if($generationRow['child']) · {{ $generationRow['child'] }} @endif</p>
            <p class="mt-1 font-black text-indigo-900">{{ $generationRow['status'] === 'running' ? 'جاري توليد الصفحات في Codex' : (\App\Models\CodexSceneRun::LABELS[$generationRow['status']] ?? 'حالة تحتاج مراجعة') }}</p>
            <p class="mt-1 text-sm font-bold">تم حفظ {{ $generationRow['done'] }} صفحة من إجمالي {{ $generationRow['total'] }}</p>
            <progress class="mt-2 h-3 w-full accent-indigo-600" value="{{ $generationRow['done'] }}" max="{{ $generationRow['total'] }}" aria-label="الصفحات المحفوظة — {{ $generationRow['label'] }}">{{ $generationRow['done'] }} / {{ $generationRow['total'] }}</progress>
            @can('orders.update')
            @can('orders.photos.view')
            <a class="mt-2 inline-block text-xs font-bold text-indigo-700 underline" href="{{ route('admin.orders.show', $generationRow['order_id']) }}#codex-scenes-{{ $generationRow['order_id'] }}">عرض الصفحات وتفاصيل التوليد</a>
            @endcan
            @endcan
        </div>
    @endforeach
</section>
@endif
