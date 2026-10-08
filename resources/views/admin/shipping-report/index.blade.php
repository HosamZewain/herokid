<x-admin-layout>
    <x-slot name="header">
        <div class="text-right">
            <h2 class="text-xl font-black text-gray-900">تقرير الشحن</h2>
            <p class="mt-1 text-xs font-bold text-gray-500">الكميات المشحونة يوميًا، محتويات الشحنات، ومسؤولو الطلبات — بتوقيت القاهرة.</p>
        </div>
    </x-slot>
    <div class="space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm">
            <form method="GET" class="grid items-end gap-4 sm:grid-cols-4">
                <div><label for="shipping-from" class="mb-2 block text-sm font-bold">من تاريخ</label><input id="shipping-from" type="date" name="from" value="{{ $report['from'] }}" class="w-full rounded-xl border-gray-200" required></div>
                <div><label for="shipping-to" class="mb-2 block text-sm font-bold">إلى تاريخ</label><input id="shipping-to" type="date" name="to" value="{{ $report['to'] }}" class="w-full rounded-xl border-gray-200" required></div>
                <div><label for="shipping-day" class="mb-2 block text-sm font-bold">تفاصيل يوم</label><input id="shipping-day" type="date" name="day" value="{{ $report['day'] }}" class="w-full rounded-xl border-gray-200" required></div>
                <button class="rounded-xl bg-indigo-600 px-5 py-3 font-black text-white hover:bg-indigo-700">عرض التقرير</button>
            </form>
            <p class="mt-4 text-xs leading-6 text-gray-500">تاريخ الشحن هو أول حدث إرسال مسجّل في بوسطة، أو أول انتقال لحالة «تم الشحن» في سجل الطلب. إنشاء بوليصة لا يُحتسب شحنًا. الكميات ومحتويات الشحنة ومسؤول الطلب تعكس بيانات الطلب الحالية؛ ليست لقطة تاريخية وقت الشحن. إعادة الشحن لنفس عملية الشراء لا تُحتسب كعملية شراء جديدة.</p>
            @if($report['undated'])
                <p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm font-bold text-amber-800">{{ $report['undated'] }} عملية شراء حالتها مشحونة / مسلّمة / مرتجعة ولكن بدون تاريخ إرسال موثّق؛ غير محتسبة في الأيام. لم يتم تخمين تاريخ لها.</p>
            @endif
        </section>
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach(['shipments' => 'الشحنات خلال الفترة', 'products' => 'قطع المنتجات المشحونة', 'stories' => 'نسخ القصص المشحونة', 'add_ons' => 'قطع الإضافات المشحونة'] as $key => $label)
                <div class="rounded-3xl border border-indigo-100 bg-white p-5 shadow-sm"><p class="text-sm font-bold text-indigo-600">{{ $label }}</p><p class="mt-3 text-3xl font-black text-gray-900">{{ $report['summary'][$key] }}</p></div>
            @endforeach
        </section>
        <div class="grid items-start gap-6 xl:grid-cols-2">
            <section class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
                <h3 class="p-5 text-lg font-black">الشحن يوم بيوم</h3>
                <div class="max-h-96 overflow-auto"><table class="w-full text-right text-sm">
                    <thead class="sticky top-0 bg-gray-50 text-gray-500"><tr><th class="p-3">اليوم</th><th class="p-3">الشحنات</th><th class="p-3">المنتجات</th><th class="p-3">القصص</th><th class="p-3">الإضافات</th></tr></thead>
                    <tbody>@foreach($report['daily'] as $row)
                        <tr class="border-t border-gray-100 {{ $row['day'] === $report['day'] ? 'bg-indigo-50' : '' }}">
                            <td class="p-3"><a class="font-bold text-indigo-600 underline" href="{{ route('admin.shipping-report.index', ['from' => $report['from'], 'to' => $report['to'], 'day' => $row['day']]) }}">{{ $row['day'] }}</a></td>
                            @foreach(['shipments', 'products', 'stories', 'add_ons'] as $key)<td class="p-3 font-bold">{{ $row[$key] }}</td>@endforeach
                        </tr>
                    @endforeach</tbody>
                </table></div>
            </section>
            <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm">
                <h3 class="text-lg font-black">مسؤولية التيم — {{ $report['day'] }}</h3>
                <p class="mt-1 text-xs text-gray-500">المسؤول الحالي عن الطلب، وليس منشئ الشحنة.</p>
                <div class="mt-4 space-y-3">@forelse($report['teams'] as $team)
                    <div class="rounded-2xl border border-gray-100 p-4">
                        <div class="flex flex-wrap justify-between gap-2"><h4 class="font-black text-indigo-700">{{ $team['name'] }}</h4><span class="text-xs font-bold text-gray-500">{{ $team['shipments'] }} شحنة · {{ $team['items'] }} قطعة / نسخة</span></div>
                        <ul class="mt-3 space-y-2 text-sm">@foreach($team['contents'] as $item)<li class="flex justify-between gap-4"><span>{{ $item['title'] }} <span class="text-xs text-gray-400">{{ $item['type'] === 'story' ? '(قصة)' : ($item['type'] === 'product' ? '(منتج)' : '(إضافة)') }}</span></span><span class="font-black">× {{ $item['quantity'] }}</span></li>@endforeach</ul>
                    </div>
                @empty<p class="rounded-xl bg-gray-50 p-5 text-sm text-gray-500">لا توجد شحنات بتاريخ إرسال موثّق لهذا اليوم.</p>@endforelse</div>
            </section>
        </div>
        <section class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
            <div class="flex flex-wrap justify-between gap-3 p-5"><h3 class="text-lg font-black">تفاصيل الشحنات — {{ $report['day'] }}</h3><span class="text-sm font-bold text-gray-500">{{ $report['selected_summary']['shipments'] }} شحنة · {{ $report['selected_summary']['items'] }} قطعة / نسخة</span></div>
            <div class="overflow-x-auto"><table class="w-full text-right text-sm">
                <thead class="bg-gray-50 text-gray-500"><tr><th class="p-4">عملية الشراء</th><th class="p-4">وقت الشحن</th><th class="p-4">التتبع</th><th class="p-4">مسؤول الطلب</th><th class="p-4">المحتويات والكميات</th></tr></thead>
                <tbody>@forelse($report['rows'] as $row)<tr class="border-t border-gray-100 align-top">
                    <td class="p-4 font-black text-indigo-600">@can('orders.view')<a href="{{ route('admin.orders.groups.show', $row['representative_id']) }}">{{ $row['short_reference'] ?: $row['key'] }}</a>@else{{ $row['short_reference'] ?: $row['key'] }}@endcan</td>
                    <td class="whitespace-nowrap p-4">{{ \App\Support\AppDateTime::format($row['shipped_at'], 'Y-m-d H:i') }}<span class="mt-1 block text-xs text-gray-400">{{ $row['date_source'] === 'bosta' ? 'حدث بوسطة' : 'سجل حالة الشحن' }}</span></td>
                    <td class="p-4"><span dir="ltr">{{ $row['tracking_number'] ?: '—' }}</span></td>
                    <td class="p-4 font-bold">{{ $row['assignee_name'] ?: 'غير مسند' }}</td>
                    <td class="min-w-64 p-4"><ul class="space-y-2">@foreach($row['items'] as $item)<li class="flex justify-between gap-4"><span>{{ $item->title }}</span><span class="whitespace-nowrap font-black">× {{ $item->quantity }}</span></li>@endforeach</ul></td>
                </tr>@empty<tr><td colspan="5" class="p-8 text-center text-gray-500">لا توجد شحنات في اليوم المختار.</td></tr>@endforelse</tbody>
            </table></div>
            <div class="border-t border-gray-100 p-5">{{ $report['rows']->links() }}</div>
        </section>
    </div>
</x-admin-layout>
