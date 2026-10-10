<x-admin-layout>
    <x-slot name="header"><div><h2 class="text-xl font-black text-gray-900">تقرير الدفعات</h2><p class="mt-1 text-xs font-bold text-gray-500">حركة التحصيل حسب تاريخ تسجيل الدفع — بتوقيت القاهرة.</p></div></x-slot>
    <x-slot name="title">تقرير الدفعات</x-slot>
    @php $money = fn ($cents) => format_report_money($cents / 100); $query = array_merge(request()->except('day', 'page'), ['range' => 'custom', 'start_date' => $filters->startDate, 'end_date' => $filters->endDate]); @endphp
    <div class="space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm">
            <form method="GET" action="{{ route('admin.payment-report.index') }}" class="grid items-end gap-4 md:grid-cols-4">
                <div><label for="payment-range" class="mb-2 block text-sm font-bold">الفترة</label><select id="payment-range" name="range" class="w-full rounded-xl border-gray-200">
                    @foreach(['today' => 'اليوم', 'yesterday' => 'أمس', 'last_7_days' => 'آخر 7 أيام', 'last_30_days' => 'آخر 30 يومًا', 'this_month' => 'هذا الشهر', 'last_month' => 'الشهر الماضي', 'this_year' => 'هذا العام', 'custom' => 'فترة مخصصة'] as $key => $label)<option value="{{ $key }}" @selected($filters->range === $key)>{{ $label }}</option>@endforeach
                </select></div>
                <div><label for="payment-from" class="mb-2 block text-sm font-bold">من</label><input id="payment-from" name="start_date" type="date" value="{{ $filters->startDate }}" onchange="document.getElementById('payment-range').value='custom'" class="w-full rounded-xl border-gray-200"></div>
                <div><label for="payment-to" class="mb-2 block text-sm font-bold">إلى</label><input id="payment-to" name="end_date" type="date" value="{{ $filters->endDate }}" onchange="document.getElementById('payment-range').value='custom'" class="w-full rounded-xl border-gray-200"></div>
                <button class="rounded-xl bg-indigo-600 px-5 py-3 font-black text-white">عرض الدفعات</button>
            </form>
            <p class="mt-4 text-sm leading-7 text-gray-600">يشمل حركات الدفع الجديدة والدفعات القديمة المستعادة بتاريخ موثّق، حتى للطلبات الملغاة والمحذوفة. صافي الفترة = الدفعات المضافة ناقص العكس/التصحيحات. الحركة السالبة ليست إثباتًا منفصلًا لرد الأموال فعليًا. الرصيد القديم بلا تاريخ موثّق محسوب في كشف إجمالي السجل أدناه، وليس في يوم افتراضي.</p>
            <p class="mt-2 text-xs leading-6 text-gray-500">القصص والمنتجات والباقات/المختلط تصنيفات منفصلة؛ تُحتسب الحركة مرة واحدة وتشمل الشحن. التصنيف من مكونات الطلب المتاحة حاليًا أو المحفوظة للطلب المحذوف، وليس لقطة تاريخية مضمونة. تفاصيل المبلغ والتاريخ من سجل الدفعات نفسه.</p>
            @if(count(request()->except('range', 'start_date', 'end_date', 'day', 'page')))
                <p class="mt-2 text-sm font-bold text-indigo-700">توجد فلاتر إضافية على التحصيل. <a class="underline" href="{{ route('admin.payment-report.index', ['range' => 'custom', 'start_date' => $filters->startDate, 'end_date' => $filters->endDate]) }}">عرض كل دفعات الفترة</a></p>
            @endif
        </section>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach([['صافي المدفوع المسجل خلال الفترة', $money($summary['net_cents'])], ['دفعات مضافة خلال الفترة', $money($summary['received_cents'])], ['عكس / تصحيحات خلال الفترة', $money($summary['reversed_cents'])], ['عمليات شراء بها حركة', $summary['checkouts']]] as [$label, $value])
                <div class="rounded-3xl border border-indigo-100 bg-white p-5 shadow-sm"><p class="text-sm font-bold text-gray-500">{{ $label }}</p><p class="mt-3 text-2xl font-black text-indigo-900">{{ $value }}</p></div>
            @endforeach
        </div>
        @include('admin.payment-report._reconciliation')
        <section class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b p-5"><div><h3 class="font-black">التحصيل اليومي — اضغط على اليوم لعرض التفاصيل</h3><p class="mt-1 text-xs text-gray-500">يمكن سحب الجدول أفقيًا لعرض باقي الأعمدة على الشاشة الصغيرة.</p></div><a href="{{ route('admin.payment-report.export', $query) }}" class="font-bold text-emerald-700">تصدير حركات الفترة CSV</a></div>
            <div class="overflow-x-auto" role="region" aria-label="جدول التحصيل اليومي" tabindex="0"><table class="w-full whitespace-nowrap text-right text-sm"><thead class="bg-gray-50"><tr>
                <th class="p-3">اليوم</th>@foreach($categories as $label)<th class="whitespace-normal p-3">{{ $label }}</th>@endforeach<th class="whitespace-normal p-3">دفعات مضافة</th><th class="whitespace-normal p-3">عكس / تصحيحات</th><th class="p-3">الصافي</th><th class="whitespace-normal p-3">حركات الدفع</th>
            </tr></thead><tbody>
                @foreach($daily as $row)<tr class="border-t {{ $row['date'] === $day ? 'bg-indigo-50' : '' }}">
                    <th class="p-3"><a class="inline-block font-black text-indigo-700 underline" dir="ltr" href="{{ route('admin.payment-report.index', $query + ['day' => $row['date']]) }}#day-details">{{ $row['date'] }}</a></th>
                    @foreach($categories as $key => $label)<td class="p-3">{{ $money($row[$key.'_cents']) }}</td>@endforeach
                    <td class="p-3 text-emerald-700">{{ $money($row['received_cents']) }}</td><td class="p-3 text-rose-700">{{ $money($row['reversed_cents']) }}</td><td class="p-3 font-black">{{ $money($row['net_cents']) }}</td><td class="p-3">{{ $row['received_count'] + $row['reversed_count'] }}</td>
                </tr>@endforeach
            </tbody><tfoot class="border-t bg-indigo-50 font-black"><tr><th class="p-3">إجمالي الفترة</th>@foreach($categories as $key => $label)<td class="p-3">{{ $money($daily->sum($key.'_cents')) }}</td>@endforeach<td class="p-3">{{ $money($summary['received_cents']) }}</td><td class="p-3">{{ $money($summary['reversed_cents']) }}</td><td class="p-3">{{ $money($summary['net_cents']) }}</td><td class="p-3">{{ $summary['received_count'] + $summary['reversed_count'] }}</td></tr></tfoot></table></div>
        </section>
        @if($day)
        <section id="day-details" class="scroll-mt-52 overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm sm:scroll-mt-24">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b p-5"><div><h3 class="font-black">تفاصيل التحصيل — <span class="inline-block" dir="ltr">{{ $day }}</span></h3><p class="mt-2 text-sm text-gray-500">صافي اليوم: {{ $money($selectedSummary['net_cents']) }} · {{ $rows->total() }} حركة</p></div><a class="font-bold text-emerald-700" href="{{ route('admin.payment-report.export', $query + ['day' => $day]) }}">تصدير اليوم CSV</a></div>
            <div class="overflow-x-auto" role="region" aria-label="جدول تفاصيل التحصيل" tabindex="0"><table class="w-full text-right text-sm"><thead class="bg-gray-50"><tr><th class="p-4">وقت الدفع / الحركة</th><th class="p-4">الطلب / تاريخ الشراء</th><th class="p-4">العميل</th><th class="p-4">التصنيف / المحتويات</th><th class="p-4">حركة المبلغ</th><th class="p-4">الطريقة</th><th class="p-4">الموظف</th></tr></thead><tbody>
                @forelse($rows as $row)<tr class="border-t align-top"><td class="p-4 whitespace-nowrap"><span dir="ltr" class="inline-block">{{ app_datetime($row['occurred_at'], 'h:i:s A') }}</span><p class="mt-1 text-xs text-gray-400">{{ $row['historical'] ? 'سجل قديم' : 'حركة' }} #{{ abs($row['id']) }} · {{ $row['amount_delta_cents'] >= 0 ? 'دفعة مضافة' : 'عكس / تصحيح' }}</p></td>
                    <td class="p-4">@if($row['first_order_id'] && auth()->user()->hasPermission('orders.view'))<a class="font-black text-indigo-700" href="{{ route('admin.orders.groups.show', $row['first_order_id']) }}">{{ $row['reference'] }}</a>@else{{ $row['reference'] }}@endif<p class="mt-1 whitespace-nowrap text-xs text-gray-400" dir="ltr">{{ app_datetime($row['original_created_at'], 'd/m/Y h:i A') }}</p></td>
                    <td class="p-4">{{ $row['customer_name'] ?: 'غير متاح' }}</td><td class="p-4"><p class="font-bold">{{ $row['category_label'] }}</p>@foreach($row['items'] as $item)<p class="mt-1 text-xs text-gray-500">{{ $item['title'] }} × {{ $item['quantity'] }}</p>@endforeach</td>
                    <td class="p-4 whitespace-nowrap font-black {{ $row['amount_delta_cents'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}" dir="ltr">{{ $row['amount_delta_cents'] >= 0 ? '+' : '−' }} {{ $money(abs($row['amount_delta_cents'])) }}</td><td class="p-4">{{ $row['payment_method'] ?: 'غير مسجلة' }}</td><td class="p-4">{{ $row['actor_name'] }}</td></tr>
                @empty<tr><td colspan="7" class="p-8 text-center text-gray-500">لا توجد حركات دفع مسجلة في هذا اليوم.</td></tr>@endforelse
            </tbody></table></div><div class="p-5">{{ $rows->links() }}</div>
        </section>
        @endif
    </div>
</x-admin-layout>
