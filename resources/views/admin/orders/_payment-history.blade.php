@php
    $paymentEventLabels = [
        'payment_initialized' => 'بدء سجل الدفع',
        'payment_received' => 'دفعة مستلمة',
        'payment_reversed' => 'عكس / تخفيض مبلغ مدفوع',
        'payment_status_changed' => 'تغيير حالة الدفع',
        'payment_balance_adjusted' => 'تسوية المبلغ بعد تعديل الطلب',
        'merge_reconciliation' => 'تسوية دمج طلبات',
        'legacy_baseline' => 'حالة تاريخية قبل تفعيل السجل',
    ];
    $paymentSourceLabels = [
        'admin_payment_update' => 'تحديث الدفع من صفحة الطلب',
        'admin_full_order_update' => 'تعديل الطلب بالكامل',
        'admin_order_creation' => 'إنشاء طلب من الإدارة',
        'checkout_creation' => 'إنشاء طلب من الموقع',
        'order_group_merge' => 'دمج عمليتي شراء',
        'legacy_baseline' => 'ترحيل الحالة التاريخية',
    ];
@endphp

<details class="group overflow-hidden rounded-3xl border border-emerald-200 bg-white shadow-sm" @if(collect($paymentEvents ?? [])->whereIn('event_type', ['payment_received', 'payment_reversed'])->isNotEmpty()) open @endif>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 bg-gradient-to-l from-emerald-50 via-white to-cyan-50 px-5 py-4 text-right marker:hidden sm:px-6">
        <span class="rounded-full bg-white px-3 py-1 text-xs font-black text-emerald-700 shadow-sm">{{ collect($paymentEvents ?? [])->count() }} حركة</span>
        <span class="min-w-0 flex-1">
            <span class="block text-base font-black text-slate-950">سجل الدفع المعتمد</span>
            <span class="mt-1 block text-xs font-bold text-slate-500">مصدر الحقيقة للمبالغ وحالات الدفع ووقت تنفيذ كل تغيير.</span>
        </span>
        <span class="text-lg font-black text-emerald-700 transition group-open:rotate-180" aria-hidden="true">⌄</span>
    </summary>

    <div class="border-t border-emerald-100 p-4 sm:p-6">
        @forelse($paymentEvents ?? [] as $event)
            @php
                $eventTime = \App\Support\OrderDateTime::display($event->occurred_at);
                $delta = (int) $event->amount_delta_cents;
            @endphp
            <article class="mb-3 rounded-2xl border border-slate-100 bg-slate-50 p-4 last:mb-0">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="text-right">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-3 py-1 text-xs font-black {{ $event->event_type === 'payment_received' ? 'bg-emerald-100 text-emerald-800' : ($event->event_type === 'payment_reversed' ? 'bg-rose-100 text-rose-800' : 'bg-slate-200 text-slate-700') }}">
                                {{ $paymentEventLabels[$event->event_type] ?? $event->event_type }}
                            </span>
                            @if($delta !== 0)
                                <span class="font-black {{ $delta > 0 ? 'text-emerald-700' : 'text-rose-700' }}" dir="ltr">
                                    {{ $delta > 0 ? '+' : '−' }} {{ format_money(abs($delta) / 100) }}
                                </span>
                            @endif
                        </div>
                        <p class="mt-2 text-sm font-bold text-slate-700">
                            {{ \App\Support\OrderPaymentStatus::label($event->previous_status) }}
                            <span class="px-1 text-slate-400">←</span>
                            {{ \App\Support\OrderPaymentStatus::label($event->new_status) }}
                        </p>
                        <p class="mt-1 text-xs font-bold text-slate-500">
                            المدفوع: {{ format_money($event->previous_paid_amount_cents / 100) }}
                            <span class="px-1">←</span>
                            {{ format_money($event->new_paid_amount_cents / 100) }}
                            @if($event->payment_method) · {{ $event->payment_method }} @endif
                        </p>
                    </div>
                    <div class="shrink-0 text-right text-xs font-bold text-slate-500 sm:text-left">
                        <p>{{ $eventTime?->translatedFormat('d/m/Y — h:i A') }}</p>
                        <p class="mt-1">{{ $event->actor?->name ?? 'النظام' }}</p>
                        <p class="mt-1 text-[11px] text-slate-400">{{ $paymentSourceLabels[$event->source] ?? $event->source }}</p>
                    </div>
                </div>
                @if($event->event_type === 'legacy_baseline')
                    <p class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">هذه لقطة للحالة السابقة عند تفعيل السجل، ولا تُحتسب كدفعة جديدة في تاريخ الترحيل.</p>
                @elseif($event->event_type === 'merge_reconciliation')
                    <p class="mt-3 rounded-xl bg-sky-50 px-3 py-2 text-xs font-bold text-sky-800">تسوية إدارية فقط؛ المبلغ المدفوع نُقل من الطلب المدموج ولا يُحتسب كتحصيل جديد.</p>
                @elseif($event->event_type === 'payment_balance_adjusted')
                    <p class="mt-3 rounded-xl bg-violet-50 px-3 py-2 text-xs font-bold text-violet-800">تغير المبلغ تلقائياً بسبب تعديل قيمة الطلب مع بقاء حالة الدفع كما هي؛ يُحفظ للتدقيق ولا يُحتسب كدفعة نقدية جديدة.</p>
                @endif
            </article>
        @empty
            <div class="rounded-2xl bg-slate-50 px-5 py-8 text-center text-sm font-bold text-slate-500">لا توجد حركات دفع مسجلة بعد.</div>
        @endforelse
    </div>
</details>
