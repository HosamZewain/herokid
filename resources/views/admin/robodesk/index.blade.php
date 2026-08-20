<x-admin-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-2xl font-black text-gray-900">تكامل RoboDesk وواتساب</h1>
            <p class="mt-1 text-sm text-gray-500">متابعة الرسائل الآلية وقرارات العملاء ومراجعة إثباتات InstaPay.</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if(session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">{{ session('success') }}</div>
        @endif

        <section class="grid gap-4 md:grid-cols-3">
            <div class="rounded-2xl border bg-white p-5 shadow-sm">
                <p class="text-sm text-gray-500">الحالة</p>
                <p class="mt-2 text-lg font-black {{ config('robodesk.enabled') ? 'text-emerald-700' : 'text-amber-700' }}">
                    {{ config('robodesk.enabled') ? 'مفعّل' : 'جاهز — غير مفعّل' }}
                </p>
            </div>
            <div class="rounded-2xl border bg-white p-5 shadow-sm">
                <p class="text-sm text-gray-500">RoboDesk Base URL</p>
                <p class="mt-2 break-all text-sm font-bold" dir="ltr">{{ config('robodesk.base_url') }}</p>
            </div>
            <div class="rounded-2xl border bg-white p-5 shadow-sm">
                <p class="text-sm text-gray-500">رقم واتساب</p>
                <p class="mt-2 text-lg font-black" dir="ltr">{{ config('robodesk.whatsapp_number') }}</p>
            </div>
        </section>

        @unless(config('robodesk.enabled'))
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                لن يرسل HeroKid أي رسالة الآن. الأحداث الجديدة تُحفظ بحالة <strong>معلّق</strong> إلى أن نستلم أسرار التوقيع من RoboDesk ونفعّل <code>ROBODESK_ENABLED=true</code>.
            </div>
        @endunless

        <section class="rounded-2xl border bg-white shadow-sm">
            <div class="border-b px-5 py-4"><h2 class="text-lg font-black">إثباتات الدفع</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600"><tr><th class="px-4 py-3 text-right">الطلب</th><th class="px-4 py-3 text-right">التاريخ</th><th class="px-4 py-3 text-right">الحالة</th><th class="px-4 py-3 text-right">الإجراءات</th></tr></thead>
                    <tbody class="divide-y">
                        @forelse($proofs as $proof)
                            <tr>
                                <td class="px-4 py-3 font-bold" dir="ltr">{{ $proof->checkout_group_key }}</td>
                                <td class="px-4 py-3">{{ $proof->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3">{{ ['pending' => 'قيد المراجعة', 'approved' => 'معتمد', 'rejected' => 'مرفوض'][$proof->status] ?? $proof->status }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        @can('robodesk.view_media')<a class="rounded-lg border px-3 py-2 font-bold text-indigo-700" target="_blank" href="{{ route('admin.robodesk.payment-proofs.show', $proof) }}">فتح الإثبات</a>@endcan
                                        @if($proof->status === 'pending')
                                            @can('robodesk.review_payments')
                                                <form method="POST" action="{{ route('admin.robodesk.payment-proofs.approve', $proof) }}">@csrf<button class="rounded-lg bg-emerald-600 px-3 py-2 font-bold text-white">اعتماد الدفع</button></form>
                                                <form method="POST" action="{{ route('admin.robodesk.payment-proofs.reject', $proof) }}" class="flex gap-2">@csrf<input required name="reason" class="w-40 rounded-lg border-gray-300 text-sm" placeholder="سبب الرفض"><button class="rounded-lg bg-red-50 px-3 py-2 font-bold text-red-700">رفض</button></form>
                                            @endcan
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">لا توجد إثباتات دفع بعد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4">{{ $proofs->links() }}</div>
        </section>

        <section class="rounded-2xl border bg-white shadow-sm">
            <div class="border-b px-5 py-4"><h2 class="text-lg font-black">سجل أحداث التكامل</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600"><tr><th class="px-4 py-3 text-right">الاتجاه</th><th class="px-4 py-3 text-right">الحدث</th><th class="px-4 py-3 text-right">الطلب</th><th class="px-4 py-3 text-right">الحالة</th><th class="px-4 py-3 text-right">المحاولات</th><th class="px-4 py-3"></th></tr></thead>
                    <tbody class="divide-y">
                        @forelse($events as $event)
                            <tr>
                                <td class="px-4 py-3">{{ $event->direction === 'outbound' ? 'صادر' : 'وارد' }}</td>
                                <td class="px-4 py-3 font-mono text-xs" dir="ltr">{{ $event->event_type }}</td>
                                <td class="px-4 py-3 text-xs" dir="ltr">{{ $event->checkout_group_key ?: '—' }}</td>
                                <td class="px-4 py-3">{{ ['held' => 'معلّق', 'pending' => 'ينتظر', 'processing' => 'جاري', 'succeeded' => 'نجح', 'failed' => 'فشل'][$event->status] ?? $event->status }}</td>
                                <td class="px-4 py-3">{{ $event->attempts }}</td>
                                <td class="px-4 py-3">
                                    @if($event->direction === 'outbound' && in_array($event->status, ['held', 'failed'], true))
                                        @can('robodesk.retry')<form method="POST" action="{{ route('admin.robodesk.events.retry', $event) }}">@csrf<button class="text-sm font-bold text-indigo-700">إعادة الإرسال</button></form>@endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">لا توجد أحداث بعد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4">{{ $events->links() }}</div>
        </section>
    </div>
</x-admin-layout>
