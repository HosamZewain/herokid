@php
    $reconcileMoney = fn (int $cents): string => format_report_money($cents / 100);
    $history = $reconciliation['history'];
@endphp
<section class="rounded-3xl border border-indigo-100 bg-white p-5 shadow-sm" aria-label="مطابقة المدفوع المسجل">
    <h3 class="text-lg font-black text-indigo-950">إجمالي المدفوع المسجل — شامل الأرصدة القديمة</h3>
    <p class="mt-2 text-sm leading-7 text-gray-600">هذا الكشف لكل السجل منذ البداية، مستقل عن فلاتر كل تقرير. الرصيد التاريخي + صافي حركات الدفع الجديدة. ليس رصيد الخزنة أو البنك بعد المصروفات، وليس إثباتًا لتحويل أو استرداد فعلي.</p>
    @if($reconciliation['differences']->isNotEmpty())
        <p class="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold leading-7 text-rose-900">المطابقة غير مكتملة: {{ $reconciliation['differences']->count() }} مجموعة بها اختلاف بين الرصيد وسجل حركاته. صافي الفرق {{ $reconcileMoney($reconciliation['unreconciled_cents']) }}؛ التفاصيل أدناه. لا تعتبر هذا الكشف رصيد خزنة نهائيًا قبل مراجعة الفروق.</p>
    @else
        <p class="mt-3 text-sm font-bold text-emerald-800">رصيد الطلبات مطابق للسجل والتسويات غير النقدية. المطابقة لا تؤكد رصيد الخزنة أو البنك.</p>
    @endif
    <div class="mt-4 grid gap-4 sm:grid-cols-3">
        <div class="rounded-2xl bg-indigo-50 p-4"><p class="text-sm font-bold text-indigo-700">المدفوع المسجل شامل القديم بعد التصحيحات</p><p class="mt-2 text-2xl font-black text-indigo-950">{{ $reconcileMoney($reconciliation['opening_plus_net_cents']) }}</p><p class="mt-2 text-xs text-indigo-700">لا تُضاف التسويات أو أرصدة الدمج إلى التحصيل.</p></div>
        <div class="rounded-2xl bg-gray-50 p-4"><p class="text-sm font-bold text-gray-600">الأرصدة القديمة عند بدء سجل الدفعات</p><p class="mt-2 text-2xl font-black">{{ $reconcileMoney($reconciliation['opening_cents']) }}</p><p class="mt-2 text-xs text-gray-500">جزء من الإجمالي، وليس إضافة أخرى عليه.</p></div>
        <div class="rounded-2xl bg-amber-50 p-4"><p class="text-sm font-bold text-amber-800">رصيد قديم بلا تاريخ دفع موثّق</p><p class="mt-2 text-2xl font-black text-amber-950">{{ $reconcileMoney($history['undated_cents']) }}</p><p class="mt-2 text-xs text-amber-800">محسوب في إجمالي السجل؛ لا يُنسب ليوم افتراضي.</p></div>
    </div>
    @if($reconciliation['baseline_corrections']->isNotEmpty())
        <p class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm leading-7 text-sky-900">تصحيح قراءة الأرصدة القديمة: {{ $reconcileMoney($reconciliation['historical_baseline_correction_cents']) }} في {{ $reconciliation['baseline_corrections']->count() }} مجموعة. الترحيل أخذ رصيد عنصر محذوف بدل العناصر النشطة؛ التصحيح مثبت بسجل الدفع القديم، ومحسوب مرة واحدة ضمن الإجمالي وليس دفعة جديدة. لقطة الترحيل الأصلية محفوظة دون تغيير.</p>
    @endif
    @if($history['undated_cents'] !== 0 || $history['issues']->isNotEmpty())
        <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-bold leading-7 text-amber-900">بيانات التحصيل التاريخية غير مكتملة بالتاريخ. الأيام قبل بدء سجل الدفعات ليست دليلًا على عدم وجود تحصيل. المستعاد بتاريخ موثّق من السجل القديم: {{ $reconcileMoney($history['recovered_net_cents']) }}؛ والباقي ظاهر منفصلًا أعلاه. لا تعتمد على صافي الفترة وحده كإجمالي منذ البداية.</p>
    @endif
    <details class="mt-4 rounded-xl border border-gray-200 p-4">
        <summary class="cursor-pointer font-black text-indigo-800">تفاصيل مطابقة الرصيد والتحصيل · @if($reconciliation['differences']->isNotEmpty()){{ $reconciliation['differences']->count() }} مجموعة تحتاج مراجعة@elseالأرصدة متطابقة — لا توجد فروق معلّقة@endif</summary>
        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            @foreach([
                'الأرصدة القديمة' => $reconciliation['opening_cents'],
                'صافي حركات الدفع الجديدة' => $reconciliation['recorded_net_cents'],
                'صافي تغيّر الرصيد غير المصنّف كدفعات' => $reconciliation['non_cash_adjustments_cents'],
                'الرصيد المتوقع من السجل والتسويات' => $reconciliation['expected_balance_cents'],
                'الرصيد الحالي على الطلبات دون تكرار الدمج' => $reconciliation['current_balance_cents'],
                ($reconciliation['differences']->isNotEmpty() ? 'فرق غير مفسّر يحتاج مراجعة — ليس إثبات عجز نقدي' : 'صافي فرق المطابقة') => $reconciliation['unreconciled_cents'],
            ] as $label => $amount)<div class="flex flex-wrap justify-between gap-2 rounded-xl bg-gray-50 p-3"><dt>{{ $label }}</dt><dd class="font-black">{{ $reconcileMoney($amount) }}</dd></div>@endforeach
        </dl>
        <p class="mt-3 text-xs leading-6 text-gray-500">نُقلت أرصدة {{ $reconcileMoney($reconciliation['merge_transfers_cents']) }} بين طلبات عند الدمج؛ ليست دفعات جديدة. نسخ أرصدة المصادر المدمجة المستبعدة من تكرار الرصيد الحالي: {{ $reconcileMoney($reconciliation['merged_source_copies_cents']) }}.</p>
        @if($reconciliation['differences']->isNotEmpty())
            <div class="mt-4 overflow-x-auto"><table class="w-full whitespace-nowrap text-right text-sm"><thead><tr><th class="p-3">الطلب</th><th class="p-3">الرصيد الحالي</th><th class="p-3">المتوقع من السجل</th><th class="p-3">الفرق</th></tr></thead><tbody>
                @foreach($reconciliation['differences']->take(20) as $difference)<tr class="border-t"><td class="p-3">@if($difference['order_id'] && auth()->user()->hasPermission('orders.view'))<a class="font-bold text-indigo-700 underline" href="{{ route('admin.orders.groups.show', $difference['order_id']) }}">{{ $difference['reference'] }}</a>@else{{ $difference['reference'] }}@endif @if($difference['missing_order'])<span class="text-xs text-amber-800">طلب غير متاح</span>@endif</td><td class="p-3">{{ $reconcileMoney($difference['current_cents']) }}</td><td class="p-3">{{ $reconcileMoney($difference['expected_cents']) }}</td><td class="p-3 font-black">{{ $reconcileMoney($difference['difference_cents']) }}</td></tr>@endforeach
            </tbody></table></div>
            <p class="mt-2 text-xs text-gray-500">أكبر ٢٠ فرقًا. لا تُعدّل الأرصدة تلقائيًا ولا تُضاف حركات دفع لسد الفرق.</p>
        @endif
    </details>
    @if($reconciliation['baseline_corrections']->isNotEmpty())
        <details class="mt-4 rounded-xl border border-sky-200 p-4">
            <summary class="cursor-pointer font-black text-sky-900">سجل تصحيحات القراءة القديمة — محسوبة بالفعل</summary>
            <p class="mt-3 text-sm leading-7 text-gray-600">هذه تصحيحات موثّقة ومحسوبة في الإجمالي، وليست فروقًا معلّقة ولا مبالغ متبقية على العملاء. لم تتغير دفعات الطلبات؛ بقيت لقطة الترحيل القديمة محفوظة للمراجعة التاريخية فقط.</p>
            <div class="mt-4 overflow-x-auto"><table class="w-full whitespace-nowrap text-right text-sm"><thead><tr><th class="p-3">تصحيح الترحيل الموثّق</th><th class="p-3">اللقطة القديمة قبل التصحيح</th><th class="p-3">المدفوع الموثّق وقت الترحيل</th><th class="p-3">تصحيح القراءة المحتسب</th></tr></thead><tbody>
                @foreach($reconciliation['baseline_corrections'] as $correction)<tr class="border-t"><td class="p-3">@if(auth()->user()->hasPermission('orders.view'))<a class="font-bold text-indigo-700 underline" href="{{ route('admin.orders.groups.show', $correction['order_id']) }}">{{ $correction['reference'] }}</a>@else{{ $correction['reference'] }}@endif <span class="text-xs text-gray-500">· لقطة #{{ $correction['baseline_event_id'] }} · سجل نشاط #{{ $correction['last_payment_log_id'] }}</span><span class="block text-xs font-bold text-sky-900">تصحيح موثّق — محسوب</span></td><td class="p-3">{{ $reconcileMoney($correction['snapshot_cents']) }}</td><td class="p-3">{{ $reconcileMoney($correction['corrected_cents']) }}</td><td class="p-3 font-bold">{{ $reconcileMoney($correction['delta_cents']) }}</td></tr>@endforeach
            </tbody></table></div>
        </details>
    @endif
</section>
