<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-bold">تنفيذ المسار — {{ $workflow->name }}</h1></x-slot>
    <div class="mx-auto max-w-6xl space-y-6 p-6" dir="rtl">
        <a class="text-indigo-700 underline" href="{{ route('admin.order-automation.process.edit', $workflow) }}">← تعديل المسار والمحاكاة</a>
        @if($errors->any())<div role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @if(session('success'))<p role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-800">{{ session('success') }}</p>@endif
        <section class="space-y-3 rounded-2xl border bg-white p-5">
            <h2 class="text-xl font-bold">{{ $workflow->enabled ? 'المسار مفعّل' : 'المسار متوقف' }} · النسخة {{ $workflow->revision_id ? '#'.$workflow->revision_id : 'غير محفوظة' }}</h2>
            <p>التفعيل يلتقط الأحداث الجديدة فقط. كل عملية شراء تدخل المسار مرة واحدة. التشغيلات القائمة تحتفظ بنسختها؛ الإيقاف يمنع الخطوة التالية ولا يسحب رسالة بدأ إرسالها.</p>
            @if(!$runtimeReady)<p class="rounded-xl bg-amber-50 p-4 text-amber-900">التنفيذ الفعلي مقفول على الخادم. يلزم تجهيز عامل التنفيذ وحدّ الطلبات الجديدة ومراجعة نقل ملكية التأكيد قبل التفعيل.</p>@endif
            @foreach($limitations as $limitation)<p class="text-amber-800">{{ $limitation }}</p>@endforeach
            @if(auth()->user()->hasPermission('order_automation.activate') && auth()->user()->hasPermission('order_automation.execute') && auth()->user()->hasPermission('orders.update') && $workflow->revision_id)
            <form method="POST" action="{{ route('admin.order-automation.process.activate', $workflow) }}" class="space-y-3">@csrf
                <input type="hidden" name="enabled" value="{{ $workflow->enabled ? 0 : 1 }}"><input type="hidden" name="revision_id" value="{{ $workflow->revision_id }}">
                <label class="block"><input required type="checkbox" name="confirmed" value="1"> راجعت النسخة ونطاق التنفيذ. الإرسال يغلق المحادثة المفتوحة للعميل.</label>
                <button @disabled(!$workflow->enabled && (!$runtimeReady || count($limitations))) class="rounded-lg bg-indigo-600 px-4 py-2 text-white disabled:opacity-40">{{ $workflow->enabled ? 'إيقاف المسار' : 'تفعيل للأحداث التالية' }}</button>
            </form>@endif
        </section>
        <section class="space-y-3 rounded-2xl border bg-white p-5"><h2 class="text-xl font-bold">إحصائيات التنفيذ الفعلي</h2>
            <div class="flex flex-wrap gap-2">@forelse($counts as $status=>$count)<span class="rounded-full bg-indigo-50 px-3 py-2 text-indigo-800">{{ \App\Services\OrderAutomation\Process\ProcessRuntime::STATES[$status] ?? $status }}: {{ $count }}</span>@empty<p>لا توجد تشغيلات فعلية.</p>@endforelse</div>
            <p class="text-sm text-slate-500">اكتمال خطوة الإرسال يعني قبول الرسالة من المزود، وليس وصولها أو قراءتها. الإرسال غير المؤكد لا يُعاد تلقائيًا.</p>
        </section>
        @foreach($runs as $run)
        <details class="rounded-2xl border bg-white p-5">
            <summary class="cursor-pointer font-bold">تشغيل #{{ $run->id }} · {{ $run->order?->order_number }} · {{ \App\Services\OrderAutomation\Process\ProcessRuntime::STATES[$run->status] ?? $run->status }}</summary>
            <p class="mt-3">نسخة #{{ $run->revision_id }} · {{ $run->created_at->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</p>
            @if($run->identities->isNotEmpty())<p class="mt-3">هويات هذا الطلب: {{ $run->identities->where('status','succeeded')->count() }} مكتملة من {{ $run->identities->count() }}. اعتماد الهوية منفصل عن إثبات الدفع.</p>@endif
            @if($run->error_code)<p class="mt-2 rounded-lg bg-amber-50 p-3">توقف للمراجعة: <code>{{ $run->error_code }}</code>. لا تعِد الإرسال قبل فحص نتيجته.</p>@endif
            <ol class="my-4 space-y-2">@foreach($run->steps as $step)<li>{{ collect($run->revision->definition['nodes'])->firstWhere('id', $step->node_id)['label'] ?? $step->node_id }} — {{ ['pending'=>'منتظرة','processing'=>'قيد التنفيذ','waiting'=>'انتظار','completed'=>'مكتملة','timed_out'=>'انتهت مهلة الانتظار','blocked'=>'متوقفة للمراجعة','uncertain'=>'نتيجة غير مؤكدة'][$step->status] ?? $step->status }}</li>@endforeach</ol>
            @foreach($run->steps->where('type','whatsapp')->where('status','completed') as $messageStep)
                <p class="mb-3 rounded-lg p-3 {{ data_get($messageStep->receipt,'delivery.status') === 'failed' ? 'bg-red-50 text-red-800' : 'bg-slate-50 text-slate-700' }}">{{ collect($run->revision->definition['nodes'])->firstWhere('id',$messageStep->node_id)['label'] ?? $messageStep->node_id }}: {{ \App\Services\RoboDesk\TemplateDelivery::label(data_get($messageStep->receipt,'delivery.status')) }}. لا تتم إعادة الإرسال تلقائيًا.</p>
            @endforeach
            @if(auth()->user()->hasPermission('order_automation.execute') && auth()->user()->hasPermission('orders.update') && in_array($run->status,['queued','running','waiting_response','waiting_staff','blocked']))
            <form method="POST" action="{{ route('admin.order-automation.process.action', $run) }}" class="flex flex-wrap items-center gap-3">@csrf
                <input type="hidden" name="version" value="{{ $run->version }}"><input name="reason" required minlength="5" maxlength="500" placeholder="سبب الإجراء — بدون بيانات حساسة" aria-label="سبب الإجراء" class="rounded-lg border-gray-300">
                @if($run->status==='waiting_staff' && $runtimeReady && $workflow->enabled)<button name="action" value="continue" class="rounded-lg bg-indigo-600 px-4 py-2 text-white">تمت المراجعة — متابعة</button>@endif
                <button name="action" value="stop" class="rounded-lg border border-red-200 px-4 py-2 text-red-700">إيقاف هذا التشغيل</button>
            </form>@endif
        </details>
        @endforeach
        {{ $runs->links() }}
    </div>
</x-admin-layout>
