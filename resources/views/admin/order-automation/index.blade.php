<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-bold">أتمتة الطلبات</h1></x-slot>
    <div class="mx-auto max-w-7xl space-y-6 p-6" dir="rtl">
        @can('orders.view')
            @include('admin.orders._puzzle-generations', ['puzzleRuns' => \App\Models\PuzzleGeneration::with('item', 'order.checkoutReference')->latest()->limit(40)->get(), 'puzzleMonitor' => true])
        @endcan
        <div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="text-2xl font-bold">مسارات العمليات</h2><p class="mt-2 text-slate-500">ابنِ شروط البداية والخطوات وفروع الرد، وراجع نتيجة كل مسار.</p></div><span class="rounded-full bg-indigo-50 px-4 py-2 text-indigo-700">إدارة ومحاكاة وتشغيل المسارات</span></div>
        @can('orders.view')
        <a href="{{ route('admin.order-automation.codex-scenes') }}" class="block rounded-2xl border border-indigo-200 bg-indigo-50 p-5 font-bold text-indigo-900">متابعة توليد مشاهد القصص — Codex · الطابور والتقدم والنتائج</a>
        @endcan
        @can('order_automation.manage')
        <form method="POST" action="{{ route('admin.order-automation.process.create') }}" class="flex flex-wrap items-end gap-3 rounded-2xl border bg-white p-5">@csrf
            <label class="flex-1">اسم الأوتوميشن<input name="name" required maxlength="150" placeholder="مثال: تأكيد الطلب" class="mt-2 w-full rounded-lg border-gray-300"></label>
            <label>ابدأ من<select name="preset" class="mt-2 block rounded-lg border-gray-300"><option value="blank">مسار فارغ</option><option value="confirmation">نموذج تأكيد الطلب</option><option value="product_review">المعاينة المجمعة: قصص ومنتجات وكتب تلوين</option><option value="identity">توليد الهوية ومعاينتها ومراجعة الدفع</option></select></label>
            <button class="rounded-lg bg-indigo-600 px-5 py-3 font-bold text-white">+ إنشاء أوتوميشن جديد</button>
        </form>
        @endcan
        <div class="grid gap-4 md:grid-cols-2">
            <section class="space-y-3 rounded-2xl border border-emerald-200 bg-white p-5"><div class="flex items-center justify-between gap-2"><h3 class="text-xl font-bold">تأكيد الطلب — التشغيل الحالي</h3><span class="rounded-full bg-emerald-50 px-3 py-1 text-sm text-emerald-800">{{ $confirmationReady ? 'جاهز للتشغيل' : 'غير جاهز' }}</span></div><p class="text-sm text-slate-500">مسار قائم مستقل. بناء نموذج جديد لا يغيّر تشغيله ولا يعيد إرسال الرسائل.</p><div class="flex flex-wrap gap-3"><span>قُبل إرسالها: {{ $confirmationCounts['succeeded'] ?? 0 }}</span><span>تأكيدات العملاء: {{ $confirmedCount }}</span></div><a href="#current-confirmation" class="text-indigo-700 underline">تفاصيل التشغيل الحالي</a></section>
            @foreach($workflows->filter(fn($workflow) => ($workflow->draft['schema'] ?? 1) === 2) as $workflow)
            <section class="space-y-3 rounded-2xl border bg-white p-5"><div class="flex items-center justify-between gap-2"><h3 class="text-xl font-bold">{{ $workflow->name }}</h3><span class="rounded-full bg-indigo-50 px-3 py-1 text-sm text-indigo-700">{{ ! $workflow->revision_id ? 'مسودة' : (! $workflow->enabled ? 'غير مفعّل' : (! app(\App\Services\OrderAutomation\Process\ProcessLivePolicy::class)->enabled() || ! app(\App\Services\OrderAutomation\Process\ProcessLivePolicy::class)->allowsAutomation($workflow->id) ? 'التشغيل متوقف من الإعدادات' : (config('order_process.enrollment_mode') === 'trial' ? 'مفعّل للتجربة المحددة' : 'مفعّل تلقائيًا'))) }}</span></div><p>{{ count($workflow->draft['nodes'] ?? []) }} خطوات · {{ ($workflow->draft['trigger'] ?? '') === 'status_entered' ? 'عند انتقال الحالة' : 'عند وصول طلب' }}</p><p class="text-sm text-slate-500">{{ \App\Support\OrderStatusRegistry::labels('order',false)[data_get($workflow->draft,'conditions.status')] ?? data_get($workflow->draft,'conditions.status') }}</p><p>محاكاة مسجلة: {{ ($processCounts[$workflow->id] ?? collect())->sum('total') }}</p>@can('orders.view')<p>تشغيلات فعلية: {{ ($liveProcessCounts[$workflow->id] ?? collect())->sum('total') }}</p>@endcan
            @can('order_automation.manage')<a href="{{ route('admin.order-automation.process.edit', $workflow) }}" class="font-bold text-indigo-700 underline">فتح المسار والتعديل والإحصائيات</a>@endcan</section>
            @endforeach
        </div>
        <details id="current-confirmation" class="rounded-2xl border bg-white p-5"><summary class="cursor-pointer font-bold">تفاصيل مسار تأكيد الطلب الحالي</summary><div class="mt-4">@include('admin.order-automation.confirmation')</div></details>

        <details class="rounded-2xl border bg-white p-5"><summary class="cursor-pointer font-bold">مسارات التوليد السابقة وإعداداتها</summary>
        @include('admin.order-automation.notice')
        <p class="rounded-xl bg-blue-50 p-4">ابدأ بمحاكاة بدون تكلفة، ثم جرّب طلبًا واحدًا. التشغيل التلقائي {{ config('order_automation.enabled') ? 'مسموح من الخادم، ويحتاج تفعيل المسار' : 'مقفول من الخادم' }}. الطلبات القديمة لا تبدأ تلقائيًا.</p>
        @can('order_automation.manage')
        <form method="POST" action="{{ route('admin.order-automation.create') }}" class="flex flex-wrap gap-3">@csrf
            <label>اسم المسار الجديد <input name="name" required maxlength="150" class="rounded-lg border-gray-300"></label><button class="rounded-lg bg-indigo-600 px-4 py-2 text-white">إنشاء مسار</button>
        </form>
        @endcan
        <div class="grid gap-4 md:grid-cols-2">
        @foreach($workflows->filter(fn($workflow) => ($workflow->draft['schema'] ?? 1) === 1) as $workflow)
            <section class="space-y-3 rounded-2xl border bg-white p-5">
                <h2 class="text-xl font-bold">{{ $workflow->name }}</h2><p>{{ $workflow->enabled ? 'مفعّل' : 'غير مفعّل' }} · {{ $workflow->revision_id ? 'نسخة منشورة #'.$workflow->revision_id : 'مسودة فقط' }}</p>
                @can('order_automation.manage')<a class="text-indigo-700 underline" href="{{ route('admin.order-automation.edit', $workflow) }}">بناء المسار والإعدادات</a>@endcan
                @can('order_automation.activate')<form method="POST" action="{{ route('admin.order-automation.activate', $workflow) }}" class="space-y-2">@csrf
                    <input type="hidden" name="enabled" value="{{ $workflow->enabled ? 0 : 1 }}"><label class="block"><input type="checkbox" name="confirmed" value="1" required> أؤكد {{ $workflow->enabled ? 'إيقاف المسار' : 'التشغيل المدفوع للطلبات الجديدة وفق الميزانية المحددة، وراجعت أذونات معالجة صور الأطفال لدى المزوّد المختار' }}</label>
                    <button class="rounded border px-3 py-2">{{ $workflow->enabled ? 'إيقاف' : 'تفعيل' }}</button></form>@endcan
            </section>
        @endforeach
        </div>
        <h2 class="text-xl font-bold">سجل التشغيل</h2>
        <div class="overflow-x-auto rounded-xl border bg-white"><table class="w-full text-right"><thead><tr><th class="p-3">التشغيل</th><th>الطلب</th><th>النوع</th><th>الحالة</th><th>الخطوة</th></tr></thead><tbody>
        @forelse($runs as $run)<tr class="border-t"><td class="p-3">@can('orders.view')<a class="text-indigo-700 underline" href="{{ route('admin.order-automation.runs.show', $run) }}">#{{ $run->id }}</a>@else #{{ $run->id }} @endcan</td><td>{{ $run->order?->order_number }}</td><td>{{ $run->dry_run ? 'محاكاة' : ($run->trial ? 'تجربة منفردة' : 'تلقائي') }}</td><td>{{ \App\Services\OrderAutomation\WorkflowPresentation::status($run->status) }}</td><td>{{ $run->step_index }}</td></tr>
        @empty<tr><td colspan="5" class="p-6">لا توجد تشغيلات بعد.</td></tr>@endforelse
        </tbody></table></div>{{ $runs->links() }}
        </details>
    </div>
</x-admin-layout>
