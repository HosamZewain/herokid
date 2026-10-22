<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-bold">أتمتة الطلبات</h1></x-slot>
    <div class="mx-auto max-w-7xl space-y-6 p-6" dir="rtl">
        @include('admin.order-automation.notice')
        <p class="rounded-xl bg-blue-50 p-4">ابدأ بمحاكاة بدون تكلفة، ثم جرّب طلبًا واحدًا. التشغيل التلقائي {{ config('order_automation.enabled') ? 'مسموح من الخادم، ويحتاج تفعيل المسار' : 'مقفول من الخادم' }}. الطلبات القديمة لا تبدأ تلقائيًا.</p>
        @can('order_automation.manage')
        <form method="POST" action="{{ route('admin.order-automation.create') }}" class="flex flex-wrap gap-3">@csrf
            <label>اسم المسار الجديد <input name="name" required maxlength="150" class="rounded-lg border-gray-300"></label><button class="rounded-lg bg-indigo-600 px-4 py-2 text-white">إنشاء مسار</button>
        </form>
        @endcan
        <div class="grid gap-4 md:grid-cols-2">
        @foreach($workflows as $workflow)
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
    </div>
</x-admin-layout>
