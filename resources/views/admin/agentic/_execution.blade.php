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
    <div class="rounded border bg-slate-50 p-3 space-y-2">
        <p>جاهز للفحص وينتظر قرارًا بحسب آخر قراءة محفوظة: <bdi>{{ $execution['readiness']['targets'] }}</bdi> وحدة في <bdi>{{ $execution['readiness']['checkouts'] }}</bdi> طلب. الجاهزية لا تتجاوز موافقات وفحص الخدمة الحالية.</p>
        @if($execution['readiness']['attention_checkouts'])<p class="text-amber-700">منها {{ $execution['readiness']['attention_checkouts'] }} طلب متوقف عند خطأ ويحتاج مراجعة المسؤول.</p>@endif
        @if($execution['readiness']['oldest_at'])
            <p>أقدم انتظار جاهز منذ <bdi>{{ \Carbon\Carbon::parse($execution['readiness']['oldest_at'])->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</bdi> بتوقيت القاهرة ({{ (int) \Carbon\Carbon::parse($execution['readiness']['oldest_at'])->diffInMinutes(now(), true) }} دقيقة).</p>
        @endif
        <p>إجمالي الإيصالات: {{ $execution['admitted'] }} مقبولة، {{ $execution['not_admitted'] }} لم تدخل التنفيذ.</p>
        <p class="font-bold text-amber-800">خطوات مرفوضة ما زالت تحتاج متابعة في النسخة الحالية: {{ $execution['intervention_count'] }}.</p>
        @if($execution['recheck_requested_count'])<p>طُلبت إعادة مراجعة {{ $execution['recheck_requested_count'] }} خطوة؛ تابع آخر نتيجة تقييم من صفحة الطلب.</p>@endif
        <dl class="grid grid-cols-2 gap-2 md:grid-cols-3">
            @foreach($execution['outcomes'] as $outcome => $count)
                <div><dt>{{ __('agentic.execution_outcomes.'.$outcome) }}</dt><dd class="font-bold">{{ $count }}</dd></div>
            @endforeach
        </dl>
        <p class="text-xs">الحالات الحالية لمسارات العمل المسجلة منذ بدء Auto Pilot، دون تكرار المسار المشترك. اكتمال الإنتاج لا يعني اعتماد العميل أو تسليم الأوردر؛ قبول إرسال الرسالة لا يثبت وصولها للعميل. الأرقام تُحدّث من السجلات دون استدعاء AI.</p>
    </div>
    @foreach($execution['rows'] as $row)
        @php($receipt = $row['receipt'])
        <article class="rounded border p-3">
            <p class="font-bold">{{ __('agentic.actions.'.$receipt->action) }} · <bdi>{{ $receipt->target_id }}</bdi>
                @if($row['order']) · <a class="text-indigo-700 underline" href="{{ route('admin.orders.show',$row['order']) }}">{{ $row['order']->checkoutReference?->short_reference ?: 'Order '.$row['order']->id }}</a>@endif</p>
            <p>{{ $receipt->status === 'admitted' ? 'سُلّمت للمسار الحالي' : 'لم تُسلّم للتنفيذ' }} · {{ \Illuminate\Support\Facades\Lang::has('agentic.execution_reasons.'.$receipt->reason_code) ? __('agentic.execution_reasons.'.$receipt->reason_code) : \App\Services\Orders\OrderAutomationGuidance::reason($receipt->reason_code) }}</p>
            @if($row['needs_intervention'])
                <p class="text-sm text-amber-900">{{ \Illuminate\Support\Facades\Lang::has('agentic.execution_next.'.$receipt->reason_code) ? __('agentic.execution_next.'.$receipt->reason_code) : 'راجع شروط وبيانات هذه الوحدة والنتائج السابقة من صفحة الطلب. تقييم الاقتراح لا يعني قبول تنفيذه.' }}</p>
                @if($row['recheck_requested'])
                    <p class="text-sm">سُجل طلب إعادة الفحص؛ تابع القرار الجديد دون تكرار الطلب.</p>
                @elseif($row['recheck_available'] && $row['order'] && auth()->user()->hasPermission('order_automation.manage') && auth()->user()->hasPermission('order_automation.execute') && app(\App\Services\Orders\OrderAccess::class)->controls($row['order'], auth()->user()))
                    <form method="POST" action="{{ route('admin.orders.autopilot-executions.recheck', [$row['order'], $receipt]) }}" class="mt-2">
                        @csrf
                        <input type="hidden" name="context_version" value="{{ $row['context_version'] }}">
                        <button type="submit" class="rounded border px-3 py-2 font-bold text-indigo-800">إعادة فحص الخطوة</button>
                        <p class="mt-1 text-xs">بعد مراجعة السبب وتصحيحه؛ يُطلب قرار جديد لهذه الخطوة وتُحفظ المحاولات والنتائج السابقة.</p>
                    </form>
                @endif
            @endif
            @if($row['current_status'])<p>حالة المسار الآن: {{ $receipt->executor_type === 'confirmation' && $row['current_status'] === 'succeeded' ? __('agentic.execution_outcomes.message_accepted') : __('agentic.executor_states.'.$row['current_status']) }}</p>@endif
            <p class="text-xs">{{ $receipt->created_at->timezone('Africa/Cairo')->format('Y-m-d H:i:s') }} بتوقيت القاهرة · إيصال {{ $receipt->id }} · <bdi>{{ $receipt->executor_type }}:{{ $receipt->executor_id }}</bdi></p>
        </article>
    @endforeach
    @if($execution['rows']->isEmpty())<p>لا توجد خطوات تنفيذ مسجلة بواسطة Auto Pilot.</p>@endif
    @if($execution['total'] > 20)<p class="text-sm">المعروض يشمل أحدث 20 إيصالًا والعمل الجاري والخطوات المرفوضة الحالية الأقدم؛ {{ $execution['total'] }} إيصالًا محفوظًا في سجلات الطلبات.</p>@endif
</section>
