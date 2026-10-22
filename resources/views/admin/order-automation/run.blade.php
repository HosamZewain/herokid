<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-bold">تشغيل الأتمتة #{{ $run->id }}</h1></x-slot>
    <div class="mx-auto max-w-5xl space-y-5 p-6" dir="rtl">
        <a href="{{ route('admin.order-automation.index') }}" class="text-indigo-700 underline">سجل التشغيل</a>
        @include('admin.order-automation.notice')
        <section class="space-y-3 rounded-xl border bg-white p-5"><h2 class="text-xl font-bold">{{ \App\Services\OrderAutomation\WorkflowPresentation::status($run->status) }}</h2>
            <p>المسار: {{ $run->automation->name }} · النسخة #{{ $run->revision_id }} · <a class="text-indigo-700 underline" href="{{ route('admin.orders.groups.show', $run->order_id) }}">{{ $run->order?->order_number }}</a></p>
            <p>{{ $run->dry_run ? 'محاكاة بدون تغيير الطلب أو الاتصال بالمزوّد' : ($run->trial ? 'تجربة منفردة — إرسال واتساب محجوب' : 'تشغيل تلقائي') }}</p>
            <p>التعرض المالي المحجوز: {{ number_format($run->reserved_microusd / 1000000, 2) }} دولار — ليس تكلفة فعلية مؤكدة.</p>
            @if($run->error_code)<p role="alert" class="text-amber-800">{{ \App\Services\OrderAutomation\WorkflowPresentation::error($run->error_code) }}</p>@endif
            <a href="{{ route('admin.order-automation.runs.show', $run) }}" class="text-indigo-700 underline">تحديث الحالة</a>
        </section>
        <ol class="space-y-2">@foreach($run->steps ?? [] as $step)<li class="rounded-lg border bg-white p-3">{{ \App\Services\OrderAutomation\WorkflowDefinition::LABELS[$step['type']] }} — {{ $step['status'] === 'simulated' ? 'محاكاة فقط' : 'تم' }} · {{ $step['at'] }}</li>@endforeach</ol>
        @can('orders.photos.view')
            @if(isset($run->context['output']))<figure class="rounded-xl border bg-white p-5"><img class="mx-auto max-h-96" src="{{ route('admin.order-automation.runs.image', $run) }}" alt="معاينة هوية الطفل غير المعتمدة"><figcaption>معاينة فقط — لا تغيّر اعتماد الهوية أو الدفع أو الطباعة.</figcaption></figure>@endif
            <details class="rounded-xl border bg-white p-5"><summary>البرومبت المستخدم</summary><pre class="mt-3 whitespace-pre-wrap break-words" dir="auto">{{ $run->context['prompt'] ?? 'لم تجهز المدخلات بعد.' }}</pre></details>
        @endcan
        @can('order_automation.execute')
        <form method="POST" action="{{ route('admin.order-automation.runs.action', $run) }}" class="space-y-3 rounded-xl border bg-white p-5">@csrf
            <label class="block">الإجراء<select name="action" class="mx-2 rounded-lg border-gray-300">@if($run->status === 'review')<option value="review">المعاينة مناسبة للإرسال (ليست موافقة العميل)</option>@endif @if(in_array($run->status,['blocked','waiting_inputs']))<option value="resume">استكمال الخطوة المتوقفة</option>@endif<option value="cancel">إلغاء التشغيل</option></select></label>
            <label class="block">السبب<input name="reason" required minlength="3" maxlength="500" class="mt-2 w-full rounded-lg border-gray-300"></label>
            <label class="block"><input type="checkbox" name="confirmed" value="1" required> أؤكد الإجراء. إلغاء التشغيل لا يلغي طلبًا وصل للمزوّد ولا يحذف المعاينة المحفوظة.</label><button class="rounded border px-4 py-2">تنفيذ الإجراء</button>
        </form>@endcan
    </div>
</x-admin-layout>
