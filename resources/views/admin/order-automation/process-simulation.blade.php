<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-bold">محاكاة #{{ $run->id }} — {{ $run->automation->name }}</h1></x-slot>
    <div class="mx-auto max-w-5xl space-y-5 p-6" dir="rtl">
        <a class="text-indigo-700 underline" href="{{ route('admin.order-automation.process.edit', $run->automation) }}">← المسار وسجل المحاكاة</a>
        @if($errors->any())<div role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @if(session('success'))<p role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-800">{{ session('success') }}</p>@endif
        <p class="rounded-xl border border-amber-200 bg-amber-50 p-4">بيانات وهمية فقط — لم تُرسل رسالة ولم يتغير أي طلب أو رصيد مالي. النسخة المستخدمة: #{{ $run->revision_id }}.</p>
        <section class="grid gap-4 rounded-2xl border bg-white p-5 md:grid-cols-3"><div><p class="text-slate-500">حالة المحاكاة</p><p class="font-bold">{{ \App\Services\OrderAutomation\Process\ProcessSimulator::STATES[$run->status] ?? $run->status }}</p></div><div><p class="text-slate-500">حالة الطلب الافتراضية</p><p class="font-bold">{{ \App\Support\OrderStatusRegistry::labels('order', false)[$run->context['status']] ?? $run->context['status'] }}</p></div><div><p class="text-slate-500">العناصر المطابقة</p><p class="font-bold">{{ count($run->context['matched_items']) }}</p></div></section>
        @php($node = collect($run->revision->definition['nodes'])->firstWhere('id', $run->node_id))
        @if(in_array($run->status, ['waiting_response','waiting_staff']))
        <section class="space-y-4 rounded-2xl border border-indigo-200 bg-indigo-50 p-5"><h2 class="text-xl font-bold">{{ $node['label'] }}</h2>
            @if($run->status === 'waiting_response')<p>اختار ردًا تجريبيًا لاختبار الفرع. مهلة الانتظار: {{ $node['config']['timeout_hours'] }} ساعة. زر انتهاء المهلة يحاكي مرور الوقت فورًا.</p>@else<p>{{ $node['config']['instructions'] }}</p>@endif
            <div class="flex flex-wrap gap-3">
                @php($choices = $run->status === 'waiting_response' ? array_merge(array_column($node['config']['cases'], 'text', 'key'), ['__timeout'=>'محاكاة انتهاء المهلة']) : ['__continue'=>'محاكاة موافقة الموظف'])
                @foreach($choices + ['__stop'=>'إيقاف المحاكاة'] as $decision=>$label)
                <form method="POST" action="{{ route('admin.order-automation.process.respond', $run) }}">@csrf<input type="hidden" name="version" value="{{ $run->version }}"><input type="hidden" name="decision" value="{{ $decision }}"><button class="rounded-lg border bg-white px-4 py-2 font-bold text-indigo-700">{{ $label }}</button></form>
                @endforeach
            </div>
        </section>
        @endif
        @if($run->status === 'not_matched')<p class="rounded-xl bg-slate-100 p-4">الحدث أو الحالة أو الأصناف غير مطابقة للشروط. لم تبدأ أي خطوة.</p>@endif
        @if($run->status === 'blocked')<p class="rounded-xl bg-red-50 p-4 text-red-800">المسار توقف بسبب تعارض في الشروط أو الحالة. راجع آخر خطوة، وعدّل المسودة ثم اختبر نسخة جديدة.</p>@endif
        <section class="space-y-3"><h2 class="text-xl font-bold">تسلسل التنفيذ التجريبي</h2>
            @foreach($run->trace as $step)
            <article class="space-y-2 rounded-xl border bg-white p-4"><h3 class="font-bold">{{ $loop->iteration }}. {{ $step['label'] }}</h3><p class="text-sm text-slate-500">{{ \App\Services\OrderAutomation\Process\ProcessDefinition::TYPES[$step['type']] ?? $step['type'] }} · {{ \Carbon\Carbon::parse($step['at'])->timezone('Africa/Cairo')->format('H:i:s') }}</p>
                @isset($step['template'])<p>قالب تجريبي: <bdi>{{ $step['template'] }}</bdi></p><ol class="list-inside list-decimal">@foreach($step['variables'] as $value)<li>{{ $value }}</li>@endforeach</ol>@endisset
                @isset($step['from'])<p>{{ \App\Support\OrderStatusRegistry::labels('order',false)[$step['from']] ?? $step['from'] }} ← {{ \App\Support\OrderStatusRegistry::labels('order',false)[$step['to']] ?? $step['to'] }}</p>@endisset
                @isset($step['branch'])<p>{{ $step['branch']==='yes' ? 'الشرط تحقق' : 'الشرط لم يتحقق' }}</p>@endisset
                @isset($step['decision'])<p>{{ $step['decision']==='__timeout' ? 'انتهاء مهلة افتراضي' : ($step['decision']==='__stop' ? 'تم الإيقاف' : 'تم اختيار فرع تجريبي') }}</p>@endisset
                @if($step['result']==='state_conflict')<p class="text-red-700">الحالة الحالية لا تطابق الحالة المطلوبة لهذه الخطوة.</p>@endif
            </article>
            @endforeach
        </section>
    </div>
</x-admin-layout>
