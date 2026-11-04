<section class="space-y-3 rounded-xl border bg-white p-5" aria-label="تنفيذ Auto Pilot">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-bold">خطوات التنفيذ الفعلية</h2>
        @if(($allowStop ?? false) && $execution['enabled'] && !$execution['control']?->paused && auth()->user()->hasPermission('order_automation.manage') && auth()->user()->hasPermission('order_automation.execute'))
            <form method="POST" action="{{ route('admin.agentic.pause-execution') }}">@csrf<button type="submit" class="rounded border px-4 py-2 font-bold text-red-700">إيقاف تسليم خطوات جديدة</button></form>
        @endif
    </div>
    <p>{{ !$execution['enabled'] ? 'التنفيذ غير مفعّل؛ القرارات للمراجعة فقط.' : ($execution['control']?->paused ? 'تسليم الخطوات الجديدة متوقف بأمر الموظف.' : 'التنفيذ متاح للأوردرات المؤهلة داخل النطاق المعتمد.') }}</p>
    <p class="text-sm">الخطوات المقبولة اليوم: <bdi>{{ $execution['control']?->quota_day === now('Africa/Cairo')->toDateString() ? $execution['control']->admissions : 0 }}@unless($execution['unlimited']) / {{ $execution['daily_limit'] }}@endunless</bdi>. @if($execution['unlimited'])بدون سقف يومي لخطوات التنفيذ.@endif حد قرارات الذكاء الاصطناعي مستقل. قبول الخطوة في الطابور لا يعني اكتمالها.</p>
    @if($execution['enabled'] && !$execution['unlimited'] && $execution['control']?->quota_day === now('Africa/Cairo')->toDateString() && $execution['control']->admissions >= $execution['daily_limit'])
        <p class="font-bold text-amber-700">اكتمل حد خطوات التنفيذ اليوم؛ الخطوات الجديدة تنتظر تجدد الحد. متابعة العمل الجاري مستمرة.</p>
    @endif
    <p>مسارات التنفيذ أو الانتظار الجاري متابعتها: {{ $execution['active_count'] }}.</p>
    @foreach($execution['rows'] as $row)
        @php($receipt = $row['receipt'])
        <article class="rounded border p-3">
            <p class="font-bold">{{ __('agentic.actions.'.$receipt->action) }} · <bdi>{{ $receipt->target_id }}</bdi>
                @if($row['order']) · <a class="text-indigo-700 underline" href="{{ route('admin.orders.show',$row['order']) }}">{{ $row['order']->checkoutReference?->short_reference ?: 'Order '.$row['order']->id }}</a>@endif</p>
            <p>{{ $receipt->status === 'admitted' ? 'سُلّمت للمسار الحالي' : 'لم تُسلّم للتنفيذ' }} · {{ \Illuminate\Support\Facades\Lang::has('agentic.execution_reasons.'.$receipt->reason_code) ? __('agentic.execution_reasons.'.$receipt->reason_code) : \App\Services\Orders\OrderAutomationGuidance::reason($receipt->reason_code) }}</p>
            @if($row['current_status'])<p>حالة المسار الآن: {{ __('agentic.executor_states.'.$row['current_status']) }}</p>@endif
            <p class="text-xs">{{ $receipt->created_at->timezone('Africa/Cairo')->format('Y-m-d H:i:s') }} بتوقيت القاهرة · إيصال {{ $receipt->id }} · <bdi>{{ $receipt->executor_type }}:{{ $receipt->executor_id }}</bdi></p>
        </article>
    @endforeach
    @if($execution['rows']->isEmpty())<p>لا توجد خطوات تنفيذ مسجلة بواسطة Auto Pilot.</p>@endif
    @if($execution['total'] > 20)<p class="text-sm">المعروض يشمل أحدث 20 إيصالًا والعمل الجاري الأقدم؛ {{ $execution['total'] }} إيصالًا محفوظًا في سجلات الطلبات.</p>@endif
</section>
