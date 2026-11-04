<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-bold">تفاصيل قرار HeroKid Auto Pilot — {{ $context['checkout_ref'] }}</h1></x-slot>
    <main class="mx-auto max-w-7xl space-y-6 p-6" dir="rtl">
        <a class="text-indigo-700 underline" href="{{ route('admin.agentic.index') }}">كل الطلبات</a> · <a class="text-indigo-700 underline" href="{{ route('admin.orders.show',$order) }}">فتح الطلب</a>
        <div class="rounded-xl border bg-indigo-50 p-4"><strong>{{ $execution['enabled'] ? 'قرارات ومتابعة تنفيذ Auto Pilot' : 'Shadow Mode — التنفيذ غير مفعّل' }}</strong><p>القراءة الحالية بتوقيت القاهرة: <bdi dir="ltr">{{ \Carbon\Carbon::parse($context['observed_at'])->tz('Africa/Cairo')->format('Y-m-d H:i:s') }}</bdi> · {{ $matches ? 'مطابقة للنسخة المرصودة' : 'لم تُرصد هذه النسخة بعد؛ الاقتراحات السابقة قديمة' }}</p><p>المالك: {{ $context['automation_owned'] ? 'الأوتوميشن الحالي' : ($context['assignee_ref'] ?: 'غير معيّن') }} · إيقاف يدوي: {{ $context['hold'] ? 'نعم' : 'لا' }}</p></div>
        @include('admin.agentic._execution')
        @if(array_key_exists('paid_amount_cents', $context['operational']))<p>الرصيد المسجل للـCheckout (مرة واحدة): {{ $context['operational']['paid_amount_cents'] === null ? 'بيانات غير متطابقة' : number_format($context['operational']['paid_amount_cents'] / 100, 2).' جنيه' }}</p>@endif
        <section class="space-y-3"><h2 class="text-xl font-bold">الوحدات والإنتاج الفعلي الحالي</h2>
            @forelse($context['units'] as $unit)
                <article class="rounded-xl border bg-white p-4"><h3 class="font-bold" dir="ltr">{{ $unit['unit_key'] }} · {{ $unit['capability'] }}</h3><p>الطفل: {{ $unit['child_ref'] }} · الحالة: {{ $unit['order_status'] }} · نسخ مطلوبة: {{ $unit['purchased_copies'] }}</p><p>الإنتاج الفعلي: {{ $unit['production']['status'] ?: 'لا توجد محاولة' }} · المخرجات المطلوبة: {{ $unit['expected_outputs'] }} · مكتمل: {{ count($unit['production']['completed_numbers']) }} · متبقٍ: {{ count($unit['production']['missing_numbers']) }}</p><p>الصور المرجعية: {{ $unit['has_photos'] ? 'متاحة' : 'غير متاحة' }} · الهوية: {{ $unit['identity_approved'] ? 'معتمدة' : ($unit['identity_status'] ?: 'لا توجد') }}</p></article>
            @empty <p>لا توجد وحدات إنتاج معرّفة؛ تحتاج مراجعة بشرية.</p> @endforelse
        </section>
        <section><h2 class="text-xl font-bold">الاحتياجات الحالية حسب قواعد HeroKid</h2><p class="text-sm">هذه قراءة بالقواعد، مستقلة عن توافر الموديل. كل اقتراح يخضع لفحص الخدمة الأصلية قبل تسليمه للتنفيذ.</p>
            <ul class="list-inside list-disc">@foreach($targets as $target)<li><span dir="ltr">{{ $target['target_id'] }}</span>: {{ __('agentic.decisions.'.$target['decision']) }} · {{ __('agentic.reasons.'.$target['reason_code']) }}</li>@endforeach</ul>
        </section>
        <section><h2 class="text-xl font-bold">مهام بشرية مقترحة</h2><ul class="list-inside list-disc">@forelse($tasks as $task)<li>{{ $task['target_id'] }} · {{ __('agentic.reasons.'.$task['reason_code']) }} · {{ $task['assignee_ref'] ?: 'غير معيّن' }}</li>@empty<li>لا توجد مهام إضافية مقترحة؛ خطوات الطباعة والتغليف والشحن تظل بشرية.</li>@endforelse</ul></section>
        <section><h2 class="text-xl font-bold">المخرجات المتاحة</h2><p>{{ count($context['operational']['outputs']) }} مرفقات مسجلة. افتح <a href="{{ route('admin.orders.show',$order) }}" class="text-indigo-700 underline">صفحة الطلب</a> للوصول إلى الملفات عبر صلاحياتها الحالية.</p></section>
        <section class="space-y-2"><h2 class="text-xl font-bold">الذاكرة المستخدمة حاليًا</h2>
            @foreach($context['memory']['entries'] ?? [] as $entry)<p><a class="text-indigo-700 underline" href="{{ route('admin.agentic.memory.show',$entry['id']) }}#revision-{{ $entry['revision'] }}">{{ $entry['title'] }} · نسخة {{ $entry['revision'] }}</a></p>@endforeach
            <p class="text-sm">التأكيد اليدوي ورفع الملفات واستكمال الإيقاف تتم من <a class="text-indigo-700 underline" href="{{ route('admin.orders.show',$order) }}">صفحة الطلب الحالية</a> بصلاحيات الفريق؛ يقرأ Auto Pilot التغيير المسجل في مراجعته التالية.</p>
        </section>
        <section class="space-y-3"><h2 class="text-xl font-bold">سجل الاقتراحات والمحاكاة</h2>
            @forelse($history as $run)
                <article class="rounded-xl border bg-white p-4"><p>{{ $run->started_at }} · نسخة {{ $run->context_version }} · {{ $run->provider }} · {{ $run->status }}</p>
                    @if($run->decision)<p><strong>{{ __('agentic.decisions.'.$run->decision->decision['decision']) }}</strong> · {{ $run->decision->decision['target_id'] }} · {{ $run->decision->decision['action'] }}</p><p>{{ $run->decision->decision['reason'] }}</p><p>السياسة: {{ $run->decision->policy['reason_code'] }} · المحاكاة: {{ $run->decision->simulation['result'] }} · {{ $run->decision->execution?->status === 'admitted' ? 'يوجد إيصال تسليم منفصل للخطوة' : 'لم تُسلّم هذه الخطوة للتنفيذ' }}</p>@endif
                    @if($run->error_code)<p class="text-red-700">{{ $run->error_code }}</p>@endif
                    @if(auth()->user()->hasPermission('order_automation.manage'))<a class="text-indigo-700 underline" href="{{ route('admin.agentic.memory.create',['run'=>$run->id]) }}">تصحيح الاقتراح وحفظه في الذاكرة</a>@endif
                    @foreach($run->snapshot['memory']['entries'] ?? [] as $entry)<p class="text-sm">استخدم قاعدة <a class="text-indigo-700 underline" href="{{ route('admin.agentic.memory.show',$entry['id']) }}#revision-{{ $entry['revision'] }}">{{ $entry['title'] }} · نسخة {{ $entry['revision'] }}</a></p>@endforeach
                    <details><summary>مرجع التدقيق</summary><p dir="ltr">{{ $run->id }} · {{ $run->fingerprint }}</p><p>سياسة {{ $run->policy_version }} · تعليمات {{ $run->prompt_version }}</p></details>
                </article>
            @empty <p>لم يُقيّم هذا الطلب بعد.</p> @endforelse
            {{ $history->links() }}
        </section>
    </main>
</x-admin-layout>
