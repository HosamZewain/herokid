<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">لوحة تحكم HeroKid</h2>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto w-full max-w-none space-y-8 px-4 sm:px-6 lg:px-8">

            <section class="overflow-hidden rounded-3xl bg-gradient-to-l from-indigo-950 via-indigo-800 to-violet-700 p-6 text-white shadow-xl sm:p-8">
                <div class="flex flex-col gap-6 xl:flex-row xl:items-center xl:justify-between">
                    <div class="text-right">
                        <p class="text-xs font-black text-indigo-200">ملخص التشغيل المباشر</p>
                        <h3 class="mt-2 text-2xl font-black sm:text-3xl">مرحبًا، {{ auth()->user()->name }}</h3>
                        <p class="mt-2 text-sm text-indigo-100">{{ \App\Support\OrderDateTime::display(now())->translatedFormat('l، j F Y') }}</p>
                    </div>
                    @if($canViewStatistics)
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <a href="{{ route('admin.orders.index', ['catalog_type' => 'all', 'lifecycle' => 'active']) }}" class="rounded-2xl border border-white/15 bg-white/10 px-5 py-4 text-right backdrop-blur transition hover:bg-white/15">
                            <p class="text-xs font-bold text-indigo-200">طلبات نشطة</p>
                            <p class="mt-1 text-2xl font-black">{{ arabic_number($operationsStats['active_checkouts']) }}</p>
                        </a>
                        <a href="{{ route('admin.orders.index', ['catalog_type' => 'all', 'lifecycle' => 'active', 'assignment' => 'unassigned']) }}" class="rounded-2xl border border-white/15 bg-white/10 px-5 py-4 text-right backdrop-blur transition hover:bg-white/15">
                            <p class="text-xs font-bold text-indigo-200">طلبات نشطة غير مستلمة</p>
                            <p class="mt-1 text-2xl font-black">{{ arabic_number($operationsStats['unassigned_checkouts']) }}</p>
                        </a>
                        <div class="col-span-2 rounded-2xl border border-white/15 bg-white/10 px-5 py-4 text-right backdrop-blur sm:col-span-1">
                            <p class="text-xs font-bold text-indigo-200">المتبقي على الطلبات النشطة</p>
                            <p class="mt-1 text-xl font-black">{{ format_report_money($operationsStats['outstanding_cents'] / 100) }}</p>
                        </div>
                    </div>
                    @endif
                </div>

            </section>

            @if($canViewStatistics)
            <section aria-labelledby="month-dashboard-heading" class="rounded-3xl border border-indigo-100 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 id="month-dashboard-heading" class="text-xl font-black text-slate-950">إحصائيات الشهر الحالي — {{ $monthStats['label'] }}</h3>
                    <span class="text-xs font-bold text-slate-500" dir="ltr">{{ $monthStats['from'] }} → {{ $monthStats['to'] }}</span>
                </div>
                <p class="mt-2 text-xs leading-6 text-slate-500">بتوقيت القاهرة، حسب تاريخ إنشاء عملية الشراء الأصلية. تشمل كل الحالات بما فيها الملغاة والمحذوفة؛ تُستبعد نسخ الدمج. أعداد القطع حسب محتويات الطلب الحالية أو آخر محتويات محفوظة للمحذوف.</p>
                <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-2xl bg-indigo-50 p-5">
                        <p class="text-sm font-black text-indigo-700">أوردرات الشهر</p>
                        <p class="mt-3 text-3xl font-black text-indigo-950">{{ arabic_number($monthStats['checkouts']) }}</p>
                        <p class="mt-2 text-sm text-indigo-800">تشمل {{ arabic_number($monthStats['pieces']) }} قطعة</p>
                        <p class="mt-1 text-xs text-indigo-700">{{ arabic_number($monthStats['story_pieces']) }} قصة · {{ arabic_number($monthStats['product_pieces']) }} منتج</p>
                    </div>
                    <div class="rounded-2xl bg-violet-50 p-5">
                        <p class="text-sm font-black text-violet-700">أوردرات القصص فقط</p>
                        <p class="mt-3 text-3xl font-black text-violet-950">{{ arabic_number($monthStats['story_checkouts']) }}</p>
                        <p class="mt-2 text-sm text-violet-800">تشمل {{ arabic_number($monthStats['story_only_pieces']) }} قصة</p>
                    </div>
                    <div class="rounded-2xl bg-emerald-50 p-5">
                        <p class="text-sm font-black text-emerald-700">أوردرات المنتجات فقط</p>
                        <p class="mt-3 text-3xl font-black text-emerald-950">{{ arabic_number($monthStats['product_checkouts']) }}</p>
                        <p class="mt-2 text-sm text-emerald-800">تشمل {{ arabic_number($monthStats['product_only_pieces']) }} منتج</p>
                    </div>
                    <div class="rounded-2xl bg-amber-50 p-5">
                        <p class="text-sm font-black text-amber-700">الأوردرات المختلطة</p>
                        <p class="mt-3 text-3xl font-black text-amber-950">{{ arabic_number($monthStats['mixed_checkouts']) }}</p>
                        <p class="mt-2 text-sm text-amber-800">تشمل {{ arabic_number($monthStats['mixed_pieces']) }} قطعة</p>
                        <p class="mt-1 text-xs text-amber-800">{{ arabic_number($monthStats['story_pieces'] - $monthStats['story_only_pieces']) }} قصة · {{ arabic_number($monthStats['product_pieces'] - $monthStats['product_only_pieces']) }} منتج</p>
                    </div>
                </div>
                <p class="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-sm leading-7 text-slate-700">
                    إجمالي الأوردرات: {{ arabic_number($monthStats['story_checkouts']) }} قصص فقط + {{ arabic_number($monthStats['product_checkouts']) }} منتجات فقط + {{ arabic_number($monthStats['mixed_checkouts']) }} مختلطة@if($monthStats['unclassified_checkouts'] > 0) + {{ arabic_number($monthStats['unclassified_checkouts']) }} غير مصنفة@endif = {{ arabic_number($monthStats['checkouts']) }} أوردر.
                    كل أوردر محسوب مرة واحدة. أعداد القصص والمنتجات تشمل كميات العناصر داخل الأوردر.
                </p>
                <div class="mt-4 flex flex-wrap gap-3 text-sm">
                    <span class="rounded-xl bg-amber-50 px-4 py-3 font-bold text-amber-800">متوسط عدد الأوردرات يوميًا: {{ arabic_number(number_format($monthStats['daily_average'], 2)) }} — {{ arabic_number($monthStats['checkouts']) }} ÷ {{ arabic_number($monthStats['elapsed_days']) }} يومًا منقضيًا، شامل اليوم</span>
                    <span class="rounded-xl bg-rose-50 px-4 py-3 font-bold text-rose-700">من إجمالي الشهر: {{ arabic_number($monthStats['cancelled_checkouts']) }} أوردر ملغى بالكامل</span>
                    @if($monthStats['unclassified_checkouts'] > 0)
                        <span class="rounded-xl bg-slate-100 px-4 py-3 text-slate-700">أوردرات بلا عناصر مصنفة: {{ arabic_number($monthStats['unclassified_checkouts']) }}</span>
                    @endif
                </div>
                <div class="mt-4 grid grid-cols-1 gap-4 border-t border-slate-100 pt-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div><p class="text-xs font-bold text-slate-500">قيمة أوردرات الشهر</p><p class="mt-2 text-xl font-black text-slate-900">{{ format_report_money($monthStats['value_cents'] / 100) }}</p><p class="mt-1 text-xs text-slate-500">بعد الخصم وشامل الشحن، وكل الحالات</p></div>
                    <div><p class="text-xs font-bold text-slate-500">الرصيد المدفوع لأوردرات الشهر</p><p class="mt-2 text-xl font-black text-slate-900">{{ format_report_money($monthStats['paid_balance_cents'] / 100) }}</p><p class="mt-1 text-xs text-slate-500">الرصيد الحالي لهذه الأوردرات</p></div>
                    <div><p class="text-xs font-bold text-slate-500">المتبقي على أوردرات الشهر</p><p class="mt-2 text-xl font-black text-slate-900">{{ format_report_money($monthStats['outstanding_cents'] / 100) }}</p><p class="mt-1 text-xs text-slate-500">يشمل الملغاة؛ ليس تقديرًا لقابلية التحصيل</p></div>
                    <div><p class="text-xs font-bold text-emerald-700">صافي تحصيل الشهر بتاريخ الدفع</p><p class="mt-2 text-xl font-black text-emerald-800">{{ format_report_money($monthStats['payments_cents'] / 100) }}</p><p class="mt-1 text-xs text-slate-500">يشمل تحصيلات أوردرات قديمة، ناقص العكس والتصحيحات</p></div>
                </div>
                @if($monthStats['excess_paid_cents'] > 0)
                    <p class="mt-3 text-sm text-slate-600">رصيد مدفوع زائد عن قيمة بعض أوردرات الشهر: {{ format_report_money($monthStats['excess_paid_cents'] / 100) }}. القيمة = المدفوع + المتبقي − الرصيد الزائد. الرصيد الزائد لا يُخصم من مستحقات أوردرات أخرى.</p>
                @endif
            </section>
            <section aria-labelledby="today-dashboard-heading">
                <div class="mb-4 flex items-end justify-between gap-4">
                    <div class="text-right">
                        <p class="text-xs font-black text-indigo-600">نبض اليوم</p>
                        <h3 id="today-dashboard-heading" class="mt-1 text-xl font-black text-slate-950">أهم أرقام اليوم</h3>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-500">بتوقيت القاهرة</span>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <a href="{{ route('admin.orders.index', ['catalog_type' => 'all', 'lifecycle' => 'all', 'from' => \App\Support\OrderDateTime::display(now())->toDateString(), 'to' => \App\Support\OrderDateTime::display(now())->toDateString()]) }}" class="group rounded-3xl border border-indigo-100 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                        <div class="flex items-start justify-between gap-4"><span class="rounded-2xl bg-indigo-50 p-3 text-2xl">🛍️</span><span class="text-xs font-black text-indigo-600">طلبات جديدة اليوم</span></div>
                        <p class="mt-5 text-4xl font-black text-slate-950">{{ arabic_number($todayStats['new_checkouts']) }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                            <span class="text-slate-500">أمس: {{ arabic_number($todayStats['yesterday_checkouts']) }}</span>
                            @if($todayStats['new_checkouts_difference'] > 0)
                                <span class="rounded-full bg-emerald-50 px-2 py-1 font-black text-emerald-700">↑ {{ arabic_number($todayStats['new_checkouts_difference']) }} عن أمس</span>
                            @elseif($todayStats['new_checkouts_difference'] < 0)
                                <span class="rounded-full bg-rose-50 px-2 py-1 font-black text-rose-700">↓ {{ arabic_number(abs($todayStats['new_checkouts_difference'])) }} عن أمس</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-1 font-black text-slate-600">مثل أمس</span>
                            @endif
                        </div>
                    </a>
                    <div class="rounded-3xl border border-violet-100 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-4"><span class="rounded-2xl bg-violet-50 p-3 text-2xl">📈</span><span class="text-xs font-black text-violet-600">قيمة طلبات اليوم</span></div>
                        <p class="mt-5 text-3xl font-black text-slate-950">{{ format_report_money($todayStats['order_value_cents'] / 100) }}</p>
                        <p class="mt-1 text-xs text-slate-500">القيمة الحالية لمشتريات اليوم بكل الحالات، بعد الخصم وشامل الشحن</p>
                    </div>
                    <div class="rounded-3xl border border-emerald-100 bg-emerald-50/50 p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-4"><span class="rounded-2xl bg-emerald-100 p-3 text-2xl">💳</span><span class="text-xs font-black text-emerald-700">مدفوعات اليوم (الصافي)</span></div>
                        <p class="mt-5 text-3xl font-black text-emerald-900">{{ format_report_money($todayStats['payments_cents'] / 100) }}</p>
                        <p class="mt-1 text-xs text-emerald-700">الدفعات المضافة ناقص العكس والتصحيحات المسجلة اليوم</p>
                    </div>
                    <div class="rounded-3xl border border-cyan-100 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-4"><span class="rounded-2xl bg-cyan-50 p-3 text-2xl">🧾</span><span class="text-xs font-black text-cyan-700">عمليات دفع اليوم</span></div>
                        <p class="mt-5 text-4xl font-black text-slate-950">{{ arabic_number($todayStats['payment_checkouts']) }}</p>
                        <p class="mt-1 text-xs text-slate-500">عدد حركات التحصيل الموجبة؛ قد يتكرر الدفع لنفس الأوردر</p>
                    </div>
                    <div class="rounded-3xl border border-amber-100 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-4"><span class="rounded-2xl bg-amber-50 p-3 text-2xl">🧮</span><span class="text-xs font-black text-amber-700">متوسط الطلب بدون الشحن</span></div>
                        <p class="mt-5 text-3xl font-black text-slate-950">{{ format_report_money($todayStats['average_order_cents'] / 100) }}</p>
                        <p class="mt-1 text-xs text-slate-500">قيمة المنتجات بعد الخصم، دون تكلفة الشحن</p>
                    </div>
                </div>

                <div class="mt-4 overflow-hidden rounded-3xl border border-emerald-100 bg-white shadow-sm">
                    <div class="flex flex-col gap-2 border-b border-emerald-100 bg-emerald-50/60 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs font-bold text-emerald-800">حركات الدفع المسجلة بتاريخها. السالب عكس/تصحيح، وليس إثباتًا لاسترداد نقدي.</p>
                        <h4 class="text-sm font-black text-slate-950">تفاصيل حركات الدفع اليوم</h4>
                    </div>
                    @if(count($todayStats['payment_events'] ?? []) > 0)
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[760px] text-right text-xs">
                                <thead class="bg-slate-50 font-black text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3">الطلب</th>
                                        <th class="px-4 py-3">الحركة</th>
                                        <th class="px-4 py-3">الحالة بعد التغيير</th>
                                        <th class="px-4 py-3">المدفوع بعد التغيير</th>
                                        <th class="px-4 py-3">الطريقة</th>
                                        <th class="px-4 py-3">بواسطة</th>
                                        <th class="px-4 py-3">الوقت</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($todayStats['payment_events'] as $paymentEvent)
                                        <tr class="hover:bg-emerald-50/30">
                                            <td class="px-4 py-3 font-mono font-black text-indigo-700" dir="ltr">
                                                @if($paymentEvent['order_id'])
                                                    <a href="{{ route('admin.orders.groups.show', $paymentEvent['order_id']) }}" class="hover:underline">{{ $paymentEvent['reference'] }}</a>
                                                @else
                                                    {{ $paymentEvent['reference'] }}
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 font-black {{ $paymentEvent['amount_delta_cents'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}" dir="ltr">
                                                {{ $paymentEvent['amount_delta_cents'] >= 0 ? '+' : '−' }} {{ format_report_money(abs($paymentEvent['amount_delta_cents']) / 100) }}
                                            </td>
                                            <td class="px-4 py-3 font-bold text-slate-700">{{ $paymentEvent['status_label'] }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 font-black text-slate-900">{{ format_report_money($paymentEvent['new_paid_amount_cents'] / 100) }}</td>
                                            <td class="px-4 py-3 text-slate-600">{{ $paymentEvent['payment_method'] ?: '—' }}</td>
                                            <td class="px-4 py-3 text-slate-600">{{ $paymentEvent['actor_name'] }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-slate-500" dir="ltr">{{ $paymentEvent['occurred_at_label'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="px-5 py-6 text-center text-sm font-bold text-slate-500">لا توجد أي دفعات فعلية مسجلة اليوم.</p>
                    @endif
                </div>
            </section>

            <section aria-labelledby="seven-day-dashboard-heading" class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                    <div class="text-right">
                        <p class="text-xs font-black text-indigo-600">اتجاه التشغيل اليومي</p>
                        <h3 id="seven-day-dashboard-heading" class="mt-1 text-xl font-black text-slate-950">آخر 7 أيام</h3>
                        <p class="mt-1 text-sm text-slate-500">كل الحالات بتاريخ الشراء الأصلي، وبنفس تصنيف الشهر. القيم بعد الخصم وتشمل الشحن مرة واحدة. صافي المدفوع بتاريخ حركة الدفع بتوقيت القاهرة.</p>
                        <p class="mt-1 text-xs text-slate-500">«بها إلغاء» يعد الأوردر مرة في يوم تسجيل إلغاء أحد عناصره، حتى لو أعيد فتحه لاحقًا. حالات التشغيل أدناه قد تتداخل داخل الأوردر الواحد.</p>
                    </div>
                    <span class="w-fit rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-black text-slate-600">7 أيام تقويمية</span>
                </div>

                <div class="hidden overflow-x-auto xl:block">
                    <table class="w-full min-w-[1360px] text-right text-sm">
                        <caption class="sr-only">إحصائيات الطلبات اليومية لآخر سبعة أيام</caption>
                        <thead class="bg-slate-50 text-xs font-black text-slate-600">
                            <tr>
                                <th scope="col" class="sticky right-0 z-10 border-l border-slate-200 bg-slate-50 px-5 py-4">اليوم</th>
                                <th scope="col" class="px-4 py-4">طلبات جديدة</th>
                                <th scope="col" class="px-4 py-4 text-violet-700">قصص فقط</th>
                                <th scope="col" class="px-4 py-4 text-emerald-700">منتجات فقط</th>
                                <th scope="col" class="px-4 py-4">مختلطة</th>
                                <th scope="col" class="px-4 py-4">قيمة طلبات القصص فقط</th>
                                <th scope="col" class="px-4 py-4">قيمة طلبات المنتجات فقط</th>
                                <th scope="col" class="px-4 py-4">قيمة المختلطة</th>
                                @if(collect($lastSevenDaysStats)->sum('unclassified_checkouts') > 0)
                                    <th scope="col" class="px-4 py-4">غير مصنفة / القيمة</th>
                                @endif
                                <th scope="col" class="px-4 py-4">إجمالي قيمة الطلبات</th>
                                <th scope="col" class="px-4 py-4 text-emerald-700">مدفوع اليوم (الصافي)</th>
                                <th scope="col" class="px-4 py-4 text-rose-700">أوردرات بها إلغاء اليوم</th>
                                <th scope="col" class="px-4 py-4">متوسط الطلب بدون الشحن</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($lastSevenDaysStats as $day)
                                <tr class="group transition hover:bg-indigo-50/30">
                                    <th scope="row" class="sticky right-0 z-10 border-l border-slate-100 bg-white px-5 py-4 group-hover:bg-indigo-50/30">
                                        <a href="{{ route('admin.orders.index', ['catalog_type' => 'all', 'lifecycle' => 'all', 'from' => $day['date'], 'to' => $day['date']]) }}" class="block hover:text-indigo-700">
                                            <span class="block font-black text-slate-900">{{ $day['day_label'] }}</span>
                                            <span class="mt-0.5 block text-xs font-bold text-slate-400" dir="ltr">{{ $day['date_label'] }}</span>
                                        </a>
                                    </th>
                                    <td class="px-4 py-4 font-black text-slate-900">{{ arabic_number($day['new_checkouts']) }}</td>
                                    <td class="px-4 py-4"><span class="rounded-full bg-violet-50 px-2.5 py-1 font-black text-violet-700">{{ arabic_number($day['story_checkouts']) }}</span></td>
                                    <td class="px-4 py-4"><span class="rounded-full bg-emerald-50 px-2.5 py-1 font-black text-emerald-700">{{ arabic_number($day['product_checkouts']) }}</span></td>
                                    <td class="px-4 py-4 font-black text-amber-800">{{ arabic_number($day['mixed_checkouts']) }}</td>
                                    <td class="whitespace-nowrap px-4 py-4 font-bold text-slate-700">{{ format_report_money($day['story_value_cents'] / 100) }}</td>
                                    <td class="whitespace-nowrap px-4 py-4 font-bold text-slate-700">{{ format_report_money($day['product_value_cents'] / 100) }}</td>
                                    <td class="whitespace-nowrap px-4 py-4 font-bold text-slate-700">{{ format_report_money($day['mixed_value_cents'] / 100) }}</td>
                                    @if(collect($lastSevenDaysStats)->sum('unclassified_checkouts') > 0)
                                        <td class="whitespace-nowrap px-4 py-4 text-slate-600">{{ arabic_number($day['unclassified_checkouts']) }} / {{ format_report_money($day['unclassified_value_cents'] / 100) }}</td>
                                    @endif
                                    <td class="whitespace-nowrap px-4 py-4 font-black text-slate-950">{{ format_report_money($day['total_value_cents'] / 100) }}</td>
                                    <td class="whitespace-nowrap px-4 py-4 font-black text-emerald-700">@if(auth()->user()->hasPermission('sales_reports.view'))<a class="underline" href="{{ route('admin.payment-report.index', ['range' => 'custom', 'start_date' => $day['date'], 'end_date' => $day['date'], 'day' => $day['date']]) }}">{{ format_report_money($day['payments_cents'] / 100) }}</a>@else{{ format_report_money($day['payments_cents'] / 100) }}@endif</td>
                                    <td class="px-4 py-4"><span class="rounded-full {{ $day['cancelled_checkouts'] > 0 ? 'bg-rose-50 text-rose-700' : 'bg-slate-50 text-slate-400' }} px-2.5 py-1 font-black">{{ arabic_number($day['cancelled_checkouts']) }}</span></td>
                                    <td class="whitespace-nowrap px-4 py-4 font-bold text-slate-700">{{ format_report_money($day['average_order_cents'] / 100) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="space-y-3 bg-slate-50/60 p-3 sm:p-4 xl:hidden">
                    @foreach(array_reverse($lastSevenDaysStats) as $day)
                        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
                                <a href="{{ route('admin.orders.index', ['catalog_type' => 'all', 'lifecycle' => 'all', 'from' => $day['date'], 'to' => $day['date']]) }}" class="font-black text-slate-950 hover:text-indigo-700">
                                    {{ $day['day_label'] }} <span class="mr-1 text-xs text-slate-400" dir="ltr">{{ $day['date_label'] }}</span>
                                </a>
                                <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">{{ arabic_number($day['new_checkouts']) }} طلب</span>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-3">
                                <div class="rounded-xl bg-violet-50 p-3"><span class="block font-bold text-violet-600">قصص فقط</span><strong class="mt-1 block text-base text-violet-900">{{ arabic_number($day['story_checkouts']) }}</strong></div>
                                <div class="rounded-xl bg-emerald-50 p-3"><span class="block font-bold text-emerald-600">منتجات فقط</span><strong class="mt-1 block text-base text-emerald-900">{{ arabic_number($day['product_checkouts']) }}</strong></div>
                                <div class="rounded-xl bg-amber-50 p-3"><span class="block font-bold text-amber-700">مختلطة</span><strong class="mt-1 block text-base text-amber-950">{{ arabic_number($day['mixed_checkouts']) }}</strong></div>
                                @if($day['unclassified_checkouts'] > 0)
                                    <div class="rounded-xl bg-slate-100 p-3">غير مصنفة: {{ arabic_number($day['unclassified_checkouts']) }} — {{ format_report_money($day['unclassified_value_cents'] / 100) }}</div>
                                @endif
                                <div class="col-span-2 rounded-xl bg-slate-100 p-3 sm:col-span-1"><span class="block font-bold text-slate-500">إجمالي القيمة</span><strong class="mt-1 block text-base text-slate-950">{{ format_report_money($day['total_value_cents'] / 100) }}</strong></div>
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                                <div><dt class="text-slate-500">قيمة طلبات القصص فقط</dt><dd class="mt-0.5 font-black text-slate-800">{{ format_report_money($day['story_value_cents'] / 100) }}</dd></div>
                                <div><dt class="text-slate-500">قيمة طلبات المنتجات فقط</dt><dd class="mt-0.5 font-black text-slate-800">{{ format_report_money($day['product_value_cents'] / 100) }}</dd></div>
                                <div><dt class="text-slate-500">قيمة المختلطة</dt><dd class="mt-0.5 font-black text-slate-800">{{ format_report_money($day['mixed_value_cents'] / 100) }}</dd></div>
                                <div><dt class="text-emerald-600">مدفوع اليوم (الصافي)</dt><dd class="mt-0.5 font-black text-emerald-800">{{ format_report_money($day['payments_cents'] / 100) }}</dd></div>
                                <div><dt class="text-rose-600">أوردرات بها إلغاء اليوم</dt><dd class="mt-0.5 font-black text-rose-800">{{ arabic_number($day['cancelled_checkouts']) }}</dd></div>
                                <div class="col-span-2 border-t border-slate-100 pt-2"><dt class="text-slate-500">متوسط الطلب بدون الشحن</dt><dd class="mt-0.5 font-black text-slate-900">{{ format_report_money($day['average_order_cents'] / 100) }}</dd></div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            </section>

            <!-- Stats Cards Row 1 -->
            <p class="text-sm text-slate-500">حالات التشغيل الحالية لكل الفترات. قد يظهر الأوردر في أكثر من حالة إذا اختلفت حالات عناصره. سجل الطلب ليس بالضرورة قطعة واحدة.</p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-3xl">📦</span>
                        <span class="text-xs font-bold text-orange-600 bg-orange-50 px-2 py-1 rounded-full">جديد</span>
                    </div>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $newOrders }}</p>
                    <p class="text-sm text-gray-500 mt-1">عمليات شراء جديدة تنتظر المراجعة</p>
                    <p class="mt-1 text-xs font-bold text-gray-400">تتضمن {{ $orderRecordCounts['new'] }} سجل طلب</p>
                    <a href="{{ route('admin.orders.index', ['catalog_type' => 'all', 'lifecycle' => 'all', 'status' => 'new']) }}" class="text-xs text-indigo-600 font-bold mt-2 block hover:underline">عرض الكل ←</a>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-3xl">👁️</span>
                        <span class="text-xs font-bold text-purple-600 bg-purple-50 px-2 py-1 rounded-full">للموافقة</span>
                    </div>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $pendingPreview }}</p>
                    <p class="text-sm text-gray-500 mt-1">عمليات شراء تنتظر موافقة العميل</p>
                    <p class="mt-1 text-xs font-bold text-gray-400">تتضمن {{ $orderRecordCounts['preview_uploaded'] }} سجل طلب</p>
                    <a href="{{ route('admin.orders.index', ['catalog_type' => 'all', 'lifecycle' => 'all', 'status' => 'preview_uploaded']) }}" class="text-xs text-indigo-600 font-bold mt-2 block hover:underline">عرض الكل ←</a>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-3xl">🚚</span>
                        <span class="text-xs font-bold text-blue-600 bg-blue-50 px-2 py-1 rounded-full">شحن</span>
                    </div>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $shippedOrders }}</p>
                    <p class="text-sm text-gray-500 mt-1">عمليات شراء بها عناصر في الشحن</p>
                    <p class="mt-1 text-xs font-bold text-gray-400">تتضمن {{ $orderRecordCounts['shipped'] }} سجل طلب</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-3xl">✅</span>
                        <span class="text-xs font-bold text-green-600 bg-green-50 px-2 py-1 rounded-full">مكتمل</span>
                    </div>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $deliveredOrders }}</p>
                    <p class="text-sm text-gray-500 mt-1">عمليات شراء بها عناصر تم تسليمها</p>
                    <p class="mt-1 text-xs font-bold text-gray-400">تتضمن {{ $orderRecordCounts['delivered'] }} سجل طلب</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-black text-slate-500">قيمة الطلبات النشطة</p>
                    <p class="mt-2 text-2xl font-black text-slate-950">{{ format_report_money($operationsStats['active_value_cents'] / 100) }}</p>
                </div>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                    <p class="text-xs font-black text-emerald-700">الرصيد المدفوع للطلبات النشطة</p>
                    <p class="mt-2 text-2xl font-black text-emerald-900">{{ format_report_money($operationsStats['collected_cents'] / 100) }}</p>
                    <p class="mt-1 text-xs text-emerald-700">رصيد حالي، وليس تحصيل فترة زمنية</p>
                </div>
                <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5 shadow-sm">
                    <p class="text-xs font-black text-rose-700">المتبقي من الطلبات النشطة</p>
                    <p class="mt-2 text-2xl font-black text-rose-900">{{ format_report_money($operationsStats['outstanding_cents'] / 100) }}</p>
                </div>
            </div>

            @if($operationsStats['excess_paid_cents'] > 0)
                <p class="text-sm text-slate-600">ضمن رصيد الطلبات النشطة مبلغ زائد عن قيمة بعض الطلبات: {{ format_report_money($operationsStats['excess_paid_cents'] / 100) }}. القيمة = المدفوع + المتبقي − الرصيد الزائد.</p>
            @endif

            <!-- Stats Cards Row 2 -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
                <div class="bg-indigo-600 rounded-2xl p-6 shadow-sm text-white">
                    <span class="text-3xl mb-3 block">📊</span>
                    <p class="text-3xl font-extrabold">{{ $totalOrders }}</p>
                    <p class="text-indigo-200 text-sm mt-1">إجمالي عمليات الشراء</p>
                    <p class="mt-1 text-xs font-bold text-indigo-200">منذ البداية، تشمل الملغاة والمحذوفة وتستبعد نسخ الدمج. {{ $orderRecordCounts['total'] }} سجل حالي وتاريخي</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <span class="text-3xl mb-3 block">📚</span>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $activeStories }}<span class="text-sm text-gray-400">/{{ $totalStories }}</span></p>
                    <p class="text-gray-500 text-sm mt-1">قصص نشطة</p>
                    <a href="{{ route('admin.stories.index') }}" class="text-xs text-indigo-600 font-bold mt-2 block hover:underline">إدارة القصص ←</a>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <span class="text-3xl mb-3 block">👥</span>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $totalUsers }}</p>
                    <p class="text-gray-500 text-sm mt-1">حسابات العملاء المسجلين</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 {{ $unreadMessages > 0 ? 'border-red-200' : '' }}">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-3xl">💬</span>
                        @if($unreadMessages > 0)
                        <span class="bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full">{{ $unreadMessages }} جديد</span>
                        @endif
                    </div>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $unreadMessages }}</p>
                    <p class="text-gray-500 text-sm mt-1">رسائل «اتصل بنا» غير المقروءة</p>
                    <a href="{{ route('admin.messages.index') }}" class="text-xs text-indigo-600 font-bold mt-2 block hover:underline">قراءة الرسائل ←</a>
                </div>
            </div>

            @endif

            @if($canViewStatistics)
            @can('analytics.view')
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="text-right">
                            <p class="text-xs font-black text-indigo-600">تحليلات الموقع</p>
                            <h3 class="mt-1 text-xl font-black text-gray-900">ملخص Google Analytics</h3>
                            <p class="mt-1 text-sm text-gray-500">بيانات مختصرة من GA4. التفاصيل الكاملة داخل صفحة التحليلات.</p>
                        </div>
                        <div class="grid grid-cols-3 gap-3 text-center" data-analytics-widget="{{ route('admin.analytics.widget') }}">
                            @if(($analyticsWidget['status'] ?? null) === 'ready')
                                <div class="rounded-2xl bg-indigo-50 px-4 py-3">
                                    <p class="text-xs font-bold text-indigo-500">نشطون آخر ٣٠ دقيقة</p>
                                    <p class="text-2xl font-black text-indigo-800">{{ $analyticsWidget['active_users_30m'] ?? '—' }}</p>
                                </div>
                                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                                    <p class="text-xs font-bold text-slate-500">مستخدمون اليوم</p>
                                    <p class="text-2xl font-black text-slate-900">{{ $analyticsWidget['users_today'] ?? '—' }}</p>
                                </div>
                                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                                    <p class="text-xs font-bold text-slate-500">جلسات اليوم</p>
                                    <p class="text-2xl font-black text-slate-900">{{ $analyticsWidget['sessions_today'] ?? '—' }}</p>
                                </div>
                            @else
                                <div class="col-span-3 rounded-2xl bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                                    {{ ($analyticsWidget['status'] ?? null) === 'setup_required' ? 'تحليلات GA4 تحتاج إعداد credentials.' : 'جارٍ تحميل ملخص التحليلات…' }}
                                </div>
                            @endif
                        </div>
                        <a href="{{ route('admin.analytics.index') }}" class="inline-flex justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700">فتح التحليلات</a>
                    </div>
                </div>
            @endcan
            @endif

            <!-- Quick Actions -->
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                <h3 class="font-bold text-gray-800 text-lg mb-5 text-right">إجراءات سريعة</h3>
                <div class="flex flex-wrap gap-3 justify-end">
                    <a href="{{ route('admin.orders.index') }}" class="flex items-center gap-2 bg-indigo-600 text-white font-bold px-5 py-2.5 rounded-xl hover:bg-indigo-700 transition text-sm">
                        <span>📦</span> إدارة الطلبات
                    </a>
                    <a href="{{ route('admin.stories.create') }}" class="flex items-center gap-2 bg-green-600 text-white font-bold px-5 py-2.5 rounded-xl hover:bg-green-700 transition text-sm">
                        <span>➕</span> إضافة قصة جديدة
                    </a>
                    <a href="{{ route('admin.faqs.index') }}" class="flex items-center gap-2 bg-amber-500 text-white font-bold px-5 py-2.5 rounded-xl hover:bg-amber-600 transition text-sm">
                        <span>❓</span> إدارة الأسئلة الشائعة
                    </a>
                    <a href="{{ route('admin.messages.index') }}" class="flex items-center gap-2 bg-slate-600 text-white font-bold px-5 py-2.5 rounded-xl hover:bg-slate-700 transition text-sm">
                        <span>💬</span> الرسائل
                    </a>
                </div>
            </div>

            <!-- Recent Orders -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center">
                    <a href="{{ route('admin.orders.index') }}" class="text-sm text-indigo-600 font-bold hover:underline">عرض الكل ←</a>
                    <h3 class="font-bold text-gray-800 text-lg">آخر عمليات الشراء</h3>
                </div>
                @if($recentOrders->count())
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">الإجراء</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">الحالة</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">المحتويات</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">العميل</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">تاريخ ووقت الشراء</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">عملية الشراء</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach($recentOrders as $group)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.orders.groups.show', $group['representative_id']) }}" class="text-indigo-600 font-bold text-xs hover:underline">تفاصيل</a>
                                </td>
                                <td class="px-4 py-3">
                                    @php($colorClass = $group['status'] === 'mixed' ? 'bg-gray-100 text-gray-700' : \App\Support\OrderStatusRegistry::color(\App\Support\OrderStatusRegistry::TYPE_ORDER, $group['status']))
                                    <span class="inline-block text-xs font-bold px-2 py-1 rounded-full {{ $colorClass }}">
                                        {{ $group['status_label'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700 text-right">
                                    <div class="flex flex-wrap justify-end gap-1">
                                        @if($group['story_quantity'])<span class="rounded-full bg-violet-50 px-2 py-1 text-xs font-bold text-violet-700">{{ $group['story_quantity'] }} قصة</span>@endif
                                        @if($group['add_on_quantity'])<span class="rounded-full bg-amber-50 px-2 py-1 text-xs font-bold text-amber-700">{{ $group['add_on_quantity'] }} إضافة</span>@endif
                                        @if($group['product_quantity'])<span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700">{{ $group['product_quantity'] }} منتج</span>@endif
                                    </div>
                                    <p class="mt-1 max-w-64 truncate text-xs text-gray-400">{{ implode('، ', array_merge($group['story_titles'], $group['add_on_titles'], $group['product_titles'])) }}</p>
                                </td>
                                <td class="px-4 py-3 text-sm font-bold text-gray-900 text-right">
                                    {{ $group['customer_name'] }}
                                    @if($group['child_names'])<p class="mt-1 text-xs font-normal text-gray-400">الأطفال: {{ implode('، ', $group['child_names']) }}</p>@endif
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-400 text-right whitespace-nowrap"><span dir="ltr">{{ app_datetime($group['created_at'], 'd/m/Y h:i A') }}</span></td>
                                <td class="px-4 py-3 text-right" dir="ltr">
                                    <p class="font-mono text-sm font-black text-indigo-700">{{ $group['short_reference'] ?: $group['key'] }}</p>
                                    @if($group['short_reference'])<p class="mt-1 max-w-40 truncate font-mono text-[9px] text-gray-400" title="{{ $group['key'] }}">{{ $group['key'] }}</p>@endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="text-center py-12 text-gray-400">
                    <p class="text-4xl mb-2">📭</p>
                    <p>لا توجد طلبات حتى الآن</p>
                </div>
                @endif
            </div>

            <section id="management-notes" class="rounded-3xl border border-amber-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="mb-5 text-right">
                    <p class="text-xs font-black text-amber-600">مساحة مشتركة لفريق الإدارة</p>
                    <h3 class="mt-1 text-xl font-black text-slate-950">ملاحظات الإدارة</h3>
                    <p class="mt-1 text-sm text-slate-500">أضف ملاحظة مستقلة ومعها حتى 5 مرفقات. تظهر الملاحظات لكل مستخدم لديه صلاحية مشاهدة الداشبورد.</p>
                </div>

                <form action="{{ route('admin.dashboard.management-notes.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4 rounded-2xl border border-amber-100 bg-amber-50/30 p-4">
                    @csrf
                    <label class="block text-right">
                        <span class="mb-2 block text-sm font-black text-slate-700">ملاحظة جديدة</span>
                        <textarea name="body" rows="4" maxlength="20000" class="block w-full rounded-2xl border-amber-200 bg-white text-right leading-7 focus:border-amber-500 focus:ring-amber-500" placeholder="اكتب الملاحظة هنا...">{{ old('body') }}</textarea>
                    </label>
                    <x-input-error :messages="$errors->get('body')" />

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <button type="submit" class="rounded-xl bg-amber-500 px-6 py-3 text-sm font-black text-white transition hover:bg-amber-600">إضافة الملاحظة</button>
                        <label class="block flex-1 text-right sm:max-w-xl">
                            <span class="mb-2 block text-sm font-black text-slate-700">المرفقات</span>
                            <input type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.csv,.doc,.docx,.xls,.xlsx" class="block w-full rounded-xl border border-slate-200 bg-white p-2 text-sm text-slate-600 file:ml-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:font-black file:text-indigo-700">
                            <span class="mt-1 block text-xs text-slate-400">حتى 5 ملفات، 20 ميجابايت للملف، و50 ميجابايت إجماليًا.</span>
                        </label>
                    </div>
                    <x-input-error :messages="$errors->get('attachments')" />
                    <x-input-error :messages="$errors->get('attachments.*')" />
                </form>

                <div class="mt-5 space-y-3">
                    @forelse($managementNotes as $note)
                        <article class="rounded-2xl border border-slate-200 bg-white p-4 text-right shadow-sm">
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 text-xs font-bold text-slate-400">
                                <time dir="ltr">{{ app_datetime($note->created_at, 'd/m/Y h:i A') }}</time>
                                <span>{{ $note->author?->name ?? 'مستخدم إدارة سابق' }}</span>
                            </div>
                            @if(filled($note->body))
                                <p class="mt-3 whitespace-pre-wrap break-words text-sm leading-7 text-slate-800">{{ $note->body }}</p>
                            @endif
                            @if($note->attachments->isNotEmpty())
                                <div class="mt-3 flex flex-wrap justify-end gap-2">
                                    @foreach($note->attachments as $attachment)
                                        <a href="{{ route('admin.dashboard.management-notes.attachments.download', $attachment) }}" class="inline-flex max-w-full items-center gap-2 rounded-xl border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-700 hover:border-indigo-300 hover:bg-indigo-100">
                                            <span class="truncate" dir="auto">{{ $attachment->original_name }}</span>
                                            <span class="shrink-0 text-[10px] text-slate-500">{{ number_format($attachment->size / 1024, 1) }} KB</span>
                                            <span aria-hidden="true">📎</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    @empty
                        <p class="rounded-2xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm font-bold text-slate-400">لا توجد ملاحظات إدارية حتى الآن.</p>
                    @endforelse
                </div>
            </section>

        </div>
    </div>
</x-admin-layout>
