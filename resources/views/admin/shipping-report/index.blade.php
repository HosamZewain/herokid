<x-admin-layout>
    <x-slot name="header">
        <div class="text-right">
            <h2 class="text-xl font-black text-gray-900">تقرير الشحن وأداء الموظفين</h2>
            <p class="mt-1 text-xs font-bold text-gray-500">الأوردرات المشحونة والمسلّمة لكل موظف خلال الفترة — بتوقيت القاهرة.</p>
        </div>
    </x-slot>
    @php
        $period = ['from' => $report['from'], 'to' => $report['to']];
        $selectedEmployee = $report['employee'] === null ? null : $report['teams']->firstWhere('id', $report['employee']);
        $scopeName = $report['employee'] === null ? 'كل الموظفين' : ($selectedEmployee['name'] ?? 'الموظف المحدد');
        $money = fn ($cents) => format_report_money($cents / 100);
    @endphp
    <div class="space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm">
            <form method="GET" class="grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div><label for="shipping-from" class="mb-2 block text-sm font-bold">من تاريخ الشحن</label><input id="shipping-from" type="date" name="from" value="{{ $report['from'] }}" class="w-full rounded-xl border-gray-200" required></div>
                <div><label for="shipping-to" class="mb-2 block text-sm font-bold">إلى تاريخ الشحن</label><input id="shipping-to" type="date" name="to" value="{{ $report['to'] }}" class="w-full rounded-xl border-gray-200" required></div>
                <div><label for="shipping-employee" class="mb-2 block text-sm font-bold">الموظف المسؤول حاليًا</label>
                    <select id="shipping-employee" name="employee" class="w-full rounded-xl border-gray-200">
                        <option value="">كل الموظفين</option>
                        @foreach($report['teams'] as $team)<option value="{{ $team['id'] }}" @selected($report['employee'] === $team['id'])>{{ $team['name'] }} ({{ $team['shipments'] }})</option>@endforeach
                    </select>
                </div>
                <div><label for="shipping-day" class="mb-2 block text-sm font-bold">يوم محدد — اختياري</label><input id="shipping-day" type="date" name="day" value="{{ $report['day'] }}" min="{{ $report['from'] }}" max="{{ $report['to'] }}" class="w-full rounded-xl border-gray-200"></div>
                <button class="rounded-xl bg-indigo-600 px-5 py-3 font-black text-white hover:bg-indigo-700">عرض التقرير</button>
            </form>
            <p class="mt-4 text-sm font-bold leading-7 text-gray-700">يُحتسب الأوردر مرة واحدة عندما تكون حالة الشحن الحالية «تم الشحن» أو «تم التسليم». انتقاله من الشحن للتسليم لا يكرر احتسابه.</p>
            <p class="mt-2 text-xs leading-6 text-gray-500">الفترة حسب أول إرسال موثّق في بوسطة، أو أول انتقال إلى «تم الشحن» في سجل الطلب؛ وليست حسب تاريخ إنشاء الأوردر أو إنشاء البوليصة. عملية الشراء التي تضم أكثر من طفل تُحتسب مرة واحدة بعد شحن كل أجزائها. المرتجع والحالات غير المشحونة غير محتسبة. المسؤول والكميات والقيم تعكس البيانات الحالية، وتغيير الموظف ينقل الأوردر لملخص مسؤوله الجديد.</p>
            @if($report['undated'])
                <p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm font-bold text-amber-800">{{ $report['undated'] }} عملية شراء مشحونة أو مسلّمة بدون تاريخ إرسال موثّق؛ غير داخلة في حساب الفترة. لم يتم تخمين تاريخ لها.</p>
            @endif
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="ملخص الفترة للموظف المختار">
            <div class="rounded-3xl border border-indigo-100 bg-white p-5 shadow-sm"><p class="text-sm font-bold text-indigo-600">الأوردرات خلال الفترة — {{ $scopeName }}</p><p class="mt-3 text-3xl font-black text-gray-900">{{ $report['summary']['shipments'] }}</p><p class="mt-2 text-xs text-gray-500">تم الشحن أو تم التسليم</p></div>
            @if($report['canViewValues'])
                <div class="rounded-3xl border border-indigo-100 bg-white p-5 shadow-sm"><p class="text-sm font-bold text-indigo-600">قيمة الأوردرات خلال الفترة</p><p class="mt-3 text-2xl font-black text-gray-900">{{ $money($report['summary']['items_net_cents']) }}</p><p class="mt-2 text-xs text-gray-500">بدون الشحن وبعد الخصومات الفعلية</p></div>
            @endif
            <div class="rounded-3xl border border-indigo-100 bg-white p-5 shadow-sm"><p class="text-sm font-bold text-indigo-600">قطع المنتجات</p><p class="mt-3 text-3xl font-black text-gray-900">{{ $report['summary']['products'] }}</p></div>
            <div class="rounded-3xl border border-indigo-100 bg-white p-5 shadow-sm"><p class="text-sm font-bold text-indigo-600">القصص والإضافات</p><p class="mt-3 text-2xl font-black text-gray-900">{{ $report['summary']['stories'] }} قصة · {{ $report['summary']['add_ons'] }} إضافة</p></div>
        </section>

        <section id="shipping-daily" class="scroll-mt-24 overflow-hidden rounded-3xl border border-indigo-100 bg-white shadow-sm">
            <div class="p-5"><h3 class="text-lg font-black">الشحن يوم بيوم — {{ $scopeName }}</h3>
                <p class="mt-1 text-sm text-gray-500">كل أيام الفترة {{ $report['from'] }} إلى {{ $report['to'] }}. اضغط على اليوم لعرض تجميع منتجاته وأوردراته. اختيار يوم يفلتر التجميع والتفاصيل فقط؛ الملخص والجدول يشملان الفترة كاملة.</p>
                @if($report['canViewValues'])<p class="mt-2 text-xs leading-6 text-gray-500">كل القيم بدون الشحن وبعد الخصومات الفعلية. خصم الأوردر موزّع على القصص والمنتجات والإضافات بنسبة قيمة كل قسم، مع تسوية فروق القرش؛ مجموع الأقسام يساوي صافي الأوردرات.</p>@endif
            </div>
            <div class="max-h-96 overflow-auto"><table class="w-full text-right text-sm">
                <thead class="sticky top-0 bg-indigo-50 text-indigo-900"><tr><th class="p-3">اليوم</th><th class="p-3">الشحنات</th><th class="p-3">القصص — العدد @if($report['canViewValues']) / الصافي @endif</th><th class="p-3">المنتجات — العدد @if($report['canViewValues']) / الصافي @endif</th><th class="p-3">الإضافات — العدد @if($report['canViewValues']) / الصافي @endif</th>@if($report['canViewValues'] && $report['summary']['unclassified_net_cents'])<th class="p-3">قيمة غير مصنفة</th>@endif @if($report['canViewValues'])<th class="p-3">الإجمالي الصافي</th>@endif</tr></thead>
                <tbody>@foreach($report['daily'] as $row)<tr class="border-t border-gray-100 {{ $row['day'] === $report['day'] ? 'bg-indigo-50' : '' }}">
                    <td class="whitespace-nowrap p-3"><a class="font-bold text-indigo-600 underline" href="{{ route('admin.shipping-report.index', $period + ['day' => $row['day'], 'employee' => $report['employee']]) }}#sku-breakdown">{{ $row['day'] }}</a></td>
                    <td class="p-3 font-bold">{{ $row['shipments'] }}</td>
                    @foreach(['stories', 'products', 'add_ons'] as $key)<td class="whitespace-nowrap p-3"><span class="font-bold">{{ $row[$key] }}</span>@if($report['canViewValues'])<span class="mt-1 block text-sm text-gray-600">{{ $money($row[$key.'_net_cents']) }}</span>@endif</td>@endforeach
                    @if($report['canViewValues'] && $report['summary']['unclassified_net_cents'])<td class="whitespace-nowrap p-3">{{ $money($row['unclassified_net_cents']) }}</td>@endif
                    @if($report['canViewValues'])<td class="whitespace-nowrap p-3 font-black text-indigo-800">{{ $money($row['items_net_cents']) }}</td>@endif
                </tr>@endforeach</tbody>
                <tfoot class="sticky bottom-0 border-t border-indigo-100 bg-indigo-50 font-black"><tr><th class="p-3">إجمالي الفترة</th><td class="p-3">{{ $report['summary']['shipments'] }}</td>
                    @foreach(['stories', 'products', 'add_ons'] as $key)<td class="whitespace-nowrap p-3">{{ $report['summary'][$key] }} @if($report['canViewValues'])<span class="mt-1 block text-xs">{{ $money($report['summary'][$key.'_net_cents']) }}</span>@endif</td>@endforeach
                    @if($report['canViewValues'] && $report['summary']['unclassified_net_cents'])<td class="whitespace-nowrap p-3">{{ $money($report['summary']['unclassified_net_cents']) }}</td>@endif
                    @if($report['canViewValues'])<td class="whitespace-nowrap p-3">{{ $money($report['summary']['items_net_cents']) }}</td>@endif
                </tr></tfoot>
            </table></div>
        </section>

        <section class="overflow-hidden rounded-3xl border border-indigo-100 bg-white shadow-sm" aria-label="ملخص الموظفين خلال الفترة">
            <div class="p-5"><h3 class="text-lg font-black">مقارنة كل الموظفين خلال الفترة</h3><p class="mt-1 text-sm text-gray-500">{{ $report['from'] }} إلى {{ $report['to'] }} — اضغط على اسم الموظف لعرض كل أوردراته المشحونة والمسلّمة في الفترة.</p></div>
            <div class="overflow-x-auto"><table class="w-full text-right text-sm">
                <thead class="bg-indigo-50 text-indigo-900"><tr><th class="p-4">الموظف المسؤول حاليًا</th><th class="p-4">الأوردرات</th><th class="p-4">القطع / النسخ</th>@if($report['canViewValues'])<th class="p-4">صافي الأوردرات بدون الشحن وبعد الخصم</th>@endif</tr></thead>
                <tbody>@forelse($report['teams'] as $team)
                    <tr class="border-t border-gray-100 {{ $report['employee'] === $team['id'] ? 'bg-indigo-50' : '' }}">
                        <th scope="row" class="p-4"><a class="font-black text-indigo-700 underline underline-offset-4 hover:text-indigo-900" href="{{ route('admin.shipping-report.index', $period + ['employee' => $team['id']]) }}#sku-breakdown">{{ $team['name'] }}</a></th>
                        <td class="p-4 text-lg font-black">{{ $team['shipments'] }}</td><td class="p-4">{{ $team['items'] }}</td>
                        @if($report['canViewValues'])<td class="whitespace-nowrap p-4 font-black">{{ $money($team['items_net_cents']) }}</td>@endif
                    </tr>
                @empty<tr><td colspan="{{ $report['canViewValues'] ? 4 : 3 }}" class="p-8 text-center text-gray-500">لا توجد أوردرات مستوفية للشروط خلال الفترة.</td></tr>@endforelse</tbody>
            </table></div>
        </section>

        <section id="sku-breakdown" class="scroll-mt-24 overflow-hidden rounded-3xl border border-indigo-100 bg-white shadow-sm">
            <div class="p-5"><h3 class="text-lg font-black">تجميع المنتجات والقصص — {{ $scopeName }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ $report['day'] ?: $report['from'].' إلى '.$report['to'] }} — كل الكميات المشحونة في الاختيار، مجمّعة حسب المنتج أو القصة والاختيار / SKU.</p>
                @if($report['canViewValues'])<p class="mt-2 text-xs text-gray-500">القيمة بدون الشحن وبعد الخصومات الفعلية، موزّعة نسبيًا مع تسوية فروق القرش. المقاسات والاختيارات المختلفة تظهر في صفوف منفصلة.</p>@endif
            </div>
            <div class="overflow-x-auto"><table class="w-full text-right text-sm">
                <thead class="bg-indigo-50 text-indigo-900"><tr><th class="p-4">المنتج / القصة (SKU)</th><th class="p-4">العدد</th>@if($report['canViewValues'])<th class="p-4">القيمة</th>@endif</tr></thead>
                <tbody>@forelse($report['sku_breakdown'] as $item)
                    <tr class="border-t border-gray-100"><th scope="row" class="p-4 font-bold">{{ $item['title'] }}@if($item['sku'])<span dir="ltr" class="mt-1 block text-xs font-normal text-gray-500">{{ $item['sku'] }}</span>@endif</th><td class="p-4 font-bold">{{ $item['quantity'] }}</td>@if($report['canViewValues'])<td class="whitespace-nowrap p-4 font-bold">{{ $money($item['net_cents']) }}</td>@endif</tr>
                @empty<tr><td colspan="{{ $report['canViewValues'] ? 3 : 2 }}" class="p-8 text-center text-gray-500">لا توجد منتجات أو قصص مشحونة في الاختيار.</td></tr>@endforelse</tbody>
                <tfoot class="bg-indigo-50 font-black"><tr><th class="p-4">الإجمالي</th><td class="p-4">{{ $report['selected_summary']['items'] }}</td>@if($report['canViewValues'])<td class="whitespace-nowrap p-4">{{ $money($report['selected_summary']['items_net_cents']) }}</td>@endif</tr></tfoot>
            </table></div>
        </section>

        <details id="shipment-details" @if($report['rows']->currentPage() > 1) open @endif class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
            <summary class="cursor-pointer p-5 text-lg font-black">عرض تفاصيل الأوردرات</summary>
            <div class="flex flex-wrap items-start justify-between gap-3 p-5">
                <div><h3 class="text-lg font-black">تفاصيل الأوردرات — {{ $report['employee'] === null ? 'كل الموظفين' : ($selectedEmployee['name'] ?? 'الموظف المحدد') }}</h3><p class="mt-1 text-sm text-gray-500">{{ $report['day'] ?: $report['from'].' إلى '.$report['to'] }}</p></div>
                <div class="text-sm font-bold text-gray-600"><p>{{ $report['selected_summary']['shipments'] }} شحنة · {{ $report['selected_summary']['items'] }} قطعة / نسخة</p>@if($report['canViewValues'])<p class="mt-1 text-indigo-700">الصافي بدون الشحن وبعد الخصم: {{ $money($report['selected_summary']['items_net_cents']) }}</p><p class="mt-1 text-xs">المدفوع حاليًا: {{ $money($report['selected_summary']['paid_cents']) }} · المتبقي: {{ $money($report['selected_summary']['remaining_cents']) }}</p>@endif</div>
                @if($report['employee'] !== null || $report['day'] !== null)<a class="text-sm font-bold text-indigo-700 underline" href="{{ route('admin.shipping-report.index', $period) }}#sku-breakdown">عرض كل أوردرات الفترة</a>@endif
            </div>
            @if($report['canViewValues'])<p class="px-5 pb-4 text-xs text-gray-500">قيمة الأوردر المعروضة بدون الشحن وبعد خصم الخصومات المسجلة مرة واحدة لكل عملية شراء. المدفوع والمتبقي أرصدة الفاتورة الحالية وتشمل الشحن؛ وليست تحصيلًا خلال الفترة.</p>@endif
            <div class="overflow-x-auto"><table class="w-full text-right text-sm">
                <thead class="bg-gray-50 text-gray-500"><tr><th class="p-4">عملية الشراء</th><th class="p-4">وقت الشحن والحالة</th><th class="p-4">التتبع</th><th class="p-4">مسؤول الطلب</th><th class="p-4">المحتويات والكميات</th>@if($report['canViewValues'])<th class="p-4">صافي الأوردر بدون الشحن</th><th class="p-4">المدفوع والمتبقي</th>@endif</tr></thead>
                <tbody>@forelse($report['rows'] as $row)<tr class="border-t border-gray-100 align-top">
                    <td class="p-4 font-black text-indigo-600">@can('orders.view')<a class="underline" href="{{ route('admin.orders.groups.show', $row['representative_id']) }}">{{ $row['short_reference'] ?: $row['key'] }}</a>@else{{ $row['short_reference'] ?: $row['key'] }}@endcan</td>
                    <td class="whitespace-nowrap p-4">{{ \App\Support\AppDateTime::format($row['shipped_at'], 'Y-m-d H:i') }}<span class="mt-1 block text-xs text-gray-400">{{ $row['date_source'] === 'bosta' ? 'حدث بوسطة' : 'سجل حالة الشحن' }}</span><span class="mt-2 inline-block rounded-lg bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-800">{{ $row['current_shipping_status'] === 'mixed' ? 'مشحون / مسلّم' : \App\Support\OrderStatusRegistry::label('shipping', $row['current_shipping_status']) }}</span></td>
                    <td class="p-4"><span dir="ltr">{{ $row['tracking_number'] ?: '—' }}</span></td>
                    <td class="p-4 font-bold"><a class="text-indigo-700 underline" href="{{ route('admin.shipping-report.index', $period + ['employee' => (string) ($row['assignee_id'] ?? 'unassigned')]) }}#sku-breakdown">{{ $row['assignee_name'] ?: 'غير مسند' }}</a></td>
                    <td class="min-w-64 p-4"><ul class="space-y-2">@foreach($row['items'] as $item)<li class="flex justify-between gap-4"><span>{{ $item->title }}</span><span class="whitespace-nowrap font-black">× {{ $item->quantity }}</span></li>@endforeach</ul></td>
                    @if($report['canViewValues'])
                        <td class="whitespace-nowrap p-4"><p class="font-black text-indigo-800">{{ $money($row['items_net_cents']) }}</p><div class="mt-2 space-y-1 text-xs text-gray-500"><p>العناصر: {{ $money($row['items_cents']) }}</p><p>الخصم: {{ $money($row['discount_cents']) }}</p></div></td>
                        <td class="whitespace-nowrap p-4 text-xs"><p class="font-bold text-emerald-700">مدفوع: {{ $money($row['paid_cents']) }}</p><p class="mt-2 font-bold text-amber-700">متبقي: {{ $money($row['remaining_cents']) }}</p></td>
                    @endif
                </tr>@empty<tr><td colspan="{{ $report['canViewValues'] ? 7 : 5 }}" class="p-8 text-center text-gray-500">لا توجد أوردرات مطابقة للموظف والفترة المختارين.</td></tr>@endforelse</tbody>
            </table></div>
            <div class="border-t border-gray-100 p-5">{{ $report['rows']->links() }}</div>
        </details>


    </div>
</x-admin-layout>
