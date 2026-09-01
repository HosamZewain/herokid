<x-admin-layout>
    <x-slot name="header">
        <div class="text-right">
            <h2 class="text-xl font-black text-gray-900">تقرير الطلبات</h2>
            <p class="mt-1 text-xs font-bold text-gray-500">تقرير تشغيلي ومالي شامل لكل عمليات الشراء حسب الفلاتر المختارة.</p>
        </div>
    </x-slot>

    @php
        $summary = $report['summary'];
        $options = $report['options'];
        $rows = $report['rows'];
        $statusOptions = ['' => 'كل حالات الطلب', 'mixed' => 'حالات متعددة'] + $options['statuses'];
        $money = fn (int $cents): string => format_money($cents / 100);
    @endphp

    <div class="py-8">
        <div class="w-full max-w-none space-y-6 px-4 sm:px-6 lg:px-8">
            <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm">
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div class="text-right">
                        <h3 class="text-base font-black text-gray-900">فلاتر التقرير</h3>
                        <p class="mt-1 text-xs font-bold text-gray-400">كل الإحصاءات والجداول والتصدير تتبع نفس الفلاتر.</p>
                    </div>
                    <a href="{{ route('admin.order-report.export', request()->except('page')) }}"
                       class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-black text-emerald-700 hover:bg-emerald-100">
                        تصدير Excel (CSV)
                    </a>
                </div>

                <form method="GET" action="{{ route('admin.order-report.index') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                    <div class="xl:col-span-2">
                        <label for="report-q" class="mb-1.5 block text-xs font-black text-gray-600">بحث شامل</label>
                        <input id="report-q" name="q" type="search" value="{{ request('q') }}"
                               placeholder="مرجع، طلب، عميل، هاتف، طفل، قصة، منتج أو SKU"
                               class="w-full rounded-xl border-gray-200 text-right text-sm">
                    </div>
                    <div>
                        <label for="report-from" class="mb-1.5 block text-xs font-black text-gray-600">من تاريخ</label>
                        <input id="report-from" name="from" type="date" value="{{ request('from') }}" class="w-full rounded-xl border-gray-200 text-sm">
                    </div>
                    <div>
                        <label for="report-to" class="mb-1.5 block text-xs font-black text-gray-600">إلى تاريخ</label>
                        <input id="report-to" name="to" type="date" value="{{ request('to') }}" class="w-full rounded-xl border-gray-200 text-sm">
                    </div>
                    <div>
                        <label for="report-catalog" class="mb-1.5 block text-xs font-black text-gray-600">نوع الطلب</label>
                        <select id="report-catalog" name="catalog_type" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            <option value="all" @selected(request('catalog_type', 'all') === 'all')>كل الأنواع</option>
                            <option value="stories" @selected(request('catalog_type') === 'stories')>طلبات تحتوي قصصًا</option>
                            <option value="products" @selected(request('catalog_type') === 'products')>طلبات منتجات فقط</option>
                        </select>
                    </div>
                    <div>
                        <label for="report-lifecycle" class="mb-1.5 block text-xs font-black text-gray-600">دورة الطلب</label>
                        <select id="report-lifecycle" name="lifecycle" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            <option value="all" @selected(request('lifecycle', 'all') === 'all')>الكل</option>
                            <option value="active" @selected(request('lifecycle') === 'active')>نشطة</option>
                            <option value="finished" @selected(request('lifecycle') === 'finished')>منتهية</option>
                            <option value="cancelled" @selected(request('lifecycle') === 'cancelled')>ملغاة / محذوفة</option>
                        </select>
                    </div>
                    <div>
                        <label for="report-status" class="mb-1.5 block text-xs font-black text-gray-600">حالة الطلب</label>
                        <select id="report-status" name="status" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            @foreach($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(request('status', '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="report-payment" class="mb-1.5 block text-xs font-black text-gray-600">حالة الدفع</label>
                        <select id="report-payment" name="payment_status" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            <option value="">كل حالات الدفع</option>
                            @foreach($options['payment_statuses'] as $value => $label)
                                <option value="{{ $value }}" @selected(request('payment_status') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="report-printing" class="mb-1.5 block text-xs font-black text-gray-600">حالة الطباعة</label>
                        <select id="report-printing" name="printing_status" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            <option value="">كل حالات الطباعة</option>
                            @foreach($options['printing_statuses'] as $value => $label)
                                <option value="{{ $value }}" @selected(request('printing_status') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="report-shipping" class="mb-1.5 block text-xs font-black text-gray-600">حالة الشحن</label>
                        <select id="report-shipping" name="shipping_status" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            <option value="">كل حالات الشحن</option>
                            @foreach($options['shipping_statuses'] as $value => $label)
                                <option value="{{ $value }}" @selected(request('shipping_status') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="report-source" class="mb-1.5 block text-xs font-black text-gray-600">مصدر الطلب</label>
                        <select id="report-source" name="order_source" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            <option value="">كل المصادر</option>
                            @foreach($options['sources'] as $value => $label)
                                <option value="{{ $value }}" @selected(request('order_source') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="report-method" class="mb-1.5 block text-xs font-black text-gray-600">طريقة الدفع</label>
                        <select id="report-method" name="payment_method" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            <option value="">كل طرق الدفع</option>
                            @foreach($options['payment_methods'] as $method)
                                <option value="{{ $method }}" @selected(request('payment_method') === $method)>{{ $method }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="report-assignment" class="mb-1.5 block text-xs font-black text-gray-600">مسؤول الطلب</label>
                        <select id="report-assignment" name="assignment" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            <option value="">الكل</option>
                            <option value="mine" @selected(request('assignment') === 'mine')>طلباتي</option>
                            <option value="assigned" @selected(request('assignment') === 'assigned')>مستلمة</option>
                            <option value="unassigned" @selected(request('assignment') === 'unassigned')>غير مستلمة</option>
                        </select>
                    </div>
                    <div>
                        <label for="report-per-page" class="mb-1.5 block text-xs font-black text-gray-600">عدد النتائج</label>
                        <select id="report-per-page" name="per_page" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            @foreach([25, 50, 100] as $size)
                                <option value="{{ $size }}" @selected($rows->perPage() === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end gap-2 xl:col-span-2">
                        <button class="flex-1 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white hover:bg-indigo-700">تطبيق الفلاتر</button>
                        <a href="{{ route('admin.order-report.index') }}" class="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-black text-gray-500 hover:bg-gray-50">مسح</a>
                    </div>
                </form>
            </section>

            <section aria-label="ملخص تقرير الطلبات" class="grid grid-cols-2 gap-3 lg:grid-cols-4 xl:grid-cols-6">
                @foreach([
                    ['إجمالي عمليات الشراء', number_format($summary['checkouts']), 'border-indigo-100 bg-indigo-50', 'text-indigo-700', 'text-indigo-950'],
                    ['إجمالي قيمة الطلبات', $money($summary['total_cents']), 'border-slate-200 bg-slate-50', 'text-slate-600', 'text-slate-950'],
                    ['المدفوع فعليًا', $money($summary['paid_amount_cents']), 'border-emerald-100 bg-emerald-50', 'text-emerald-700', 'text-emerald-950'],
                    ['المبلغ المتبقي', $money($summary['remaining_amount_cents']), 'border-amber-100 bg-amber-50', 'text-amber-700', 'text-amber-950'],
                    ['الطلبات الملغاة', number_format($summary['cancelled_checkouts']), 'border-rose-100 bg-rose-50', 'text-rose-700', 'text-rose-950'],
                    ['قيمة الطلبات الملغاة', $money($summary['cancelled_value_cents']), 'border-red-100 bg-red-50', 'text-red-700', 'text-red-950'],
                    ['مدفوع داخل الطلبات الملغاة', $money($summary['cancelled_paid_cents']), 'border-orange-100 bg-orange-50', 'text-orange-700', 'text-orange-950'],
                    ['الطلبات النشطة', number_format($summary['active_checkouts']), 'border-blue-100 bg-blue-50', 'text-blue-700', 'text-blue-950'],
                    ['الطلبات المنتهية', number_format($summary['finished_checkouts']), 'border-teal-100 bg-teal-50', 'text-teal-700', 'text-teal-950'],
                    ['مدفوعة كليًا', number_format($summary['fully_paid_checkouts']), 'border-green-100 bg-green-50', 'text-green-700', 'text-green-950'],
                    ['تم شحنها', number_format($summary['shipped_checkouts']), 'border-cyan-100 bg-cyan-50', 'text-cyan-700', 'text-cyan-950'],
                    ['تم توصيلها', number_format($summary['delivered_checkouts']), 'border-emerald-100 bg-white', 'text-emerald-700', 'text-emerald-950'],
                    ['قيمة العناصر', $money($summary['items_cents']), 'border-slate-200 bg-white', 'text-slate-600', 'text-slate-950'],
                    ['إجمالي التوصيل', $money($summary['delivery_cents']), 'border-cyan-100 bg-white', 'text-cyan-700', 'text-cyan-950'],
                    ['الخصومات', $money($summary['discount_cents']), 'border-violet-100 bg-violet-50', 'text-violet-700', 'text-violet-950'],
                    ['متوسط قيمة الطلب', $money($summary['average_order_cents']), 'border-gray-200 bg-gray-50', 'text-gray-600', 'text-gray-950'],
                ] as [$label, $value, $boxClass, $labelClass, $valueClass])
                    <div class="rounded-2xl border p-4 {{ $boxClass }}">
                        <p class="text-xs font-black {{ $labelClass }}">{{ $label }}</p>
                        <p class="mt-2 text-lg font-black {{ $valueClass }}">{{ $value }}</p>
                    </div>
                @endforeach
            </section>

            <section class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                @foreach([
                    'catalog' => 'حسب نوع الطلب',
                    'lifecycle' => 'حسب دورة الطلب',
                    'status' => 'حسب حالة الطلب',
                    'payment' => 'حسب حالة الدفع',
                    'printing' => 'حسب حالة الطباعة',
                    'shipping' => 'حسب حالة الشحن',
                    'source' => 'حسب المصدر',
                    'daily' => 'الحركة اليومية',
                ] as $key => $title)
                    <details class="rounded-2xl border border-gray-100 bg-white shadow-sm" @if(in_array($key, ['catalog', 'lifecycle', 'payment'])) open @endif>
                        <summary class="cursor-pointer px-5 py-4 text-sm font-black text-gray-900">{{ $title }}</summary>
                        <div class="overflow-x-auto border-t border-gray-100">
                            <table class="min-w-full text-right text-xs">
                                <thead class="bg-gray-50 text-gray-500"><tr><th class="px-4 py-2">التصنيف</th><th class="px-4 py-2">العدد</th><th class="px-4 py-2">القيمة</th><th class="px-4 py-2">المدفوع</th></tr></thead>
                                <tbody class="divide-y divide-gray-100">
                                    @forelse($report['breakdowns'][$key] as $item)
                                        <tr><td class="px-4 py-2 font-bold text-gray-700">{{ $item['label'] }}</td><td class="px-4 py-2">{{ number_format($item['count']) }}</td><td class="px-4 py-2">{{ $money($item['total_cents']) }}</td><td class="px-4 py-2 text-emerald-700">{{ $money($item['paid_cents']) }}</td></tr>
                                    @empty
                                        <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">لا توجد بيانات</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endforeach
            </section>

            <section class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h3 class="text-base font-black text-gray-900">تفاصيل الطلبات المطابقة</h3>
                    <p class="mt-1 text-xs font-bold text-gray-400">{{ number_format($rows->total()) }} عملية شراء</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-[1500px] w-full divide-y divide-gray-100 text-right text-xs">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-4 py-3">الطلب والتاريخ</th><th class="px-4 py-3">العميل</th><th class="px-4 py-3">النوع والمحتويات</th><th class="px-4 py-3">المصدر</th><th class="px-4 py-3">الحالات</th><th class="px-4 py-3">قيمة الطلب</th><th class="px-4 py-3">المدفوع والمتبقي</th><th class="px-4 py-3">المسؤول</th><th class="px-4 py-3">إجراء</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($rows as $row)
                                @php
                                    $detailsUrl = $row['direct_order_id'] ? route('admin.orders.show', $row['direct_order_id']) : route('admin.orders.groups.show', $row['representative_id']);
                                    $titles = array_merge($row['story_titles'], $row['product_titles'], $row['add_on_titles']);
                                @endphp
                                <tr class="align-top">
                                    <td class="px-4 py-4"><p class="font-mono font-black text-gray-900" dir="ltr">{{ $row['short_reference'] ?: $row['key'] }}</p><p class="mt-1 text-[10px] text-gray-400" dir="ltr">{{ app_datetime($row['created_at']) }}</p><span class="mt-2 inline-flex rounded-full bg-gray-100 px-2 py-1 font-black text-gray-600">{{ $row['lifecycle_label'] }}</span></td>
                                    <td class="px-4 py-4"><p class="font-black text-gray-900">{{ $row['customer_name'] }}</p><p class="mt-1 text-gray-400" dir="ltr">{{ $row['phone'] }}</p>@if($row['child_names'])<p class="mt-2 text-gray-500">الأطفال: {{ implode('، ', $row['child_names']) }}</p>@endif</td>
                                    <td class="max-w-xs px-4 py-4"><span class="rounded-full bg-indigo-50 px-2 py-1 font-black text-indigo-700">{{ $row['catalog_type_label'] }}</span><p class="mt-2 line-clamp-3 leading-5 text-gray-600">{{ implode('، ', $titles) ?: 'لا توجد عناوين مسجلة' }}</p><p class="mt-1 text-[10px] text-gray-400">{{ $row['story_count'] }} قصة · {{ $row['product_quantity'] + $row['add_on_quantity'] }} منتج/إضافة</p></td>
                                    <td class="px-4 py-4"><p class="font-black text-gray-700">{{ $row['order_source_label'] }}</p>@if($row['payment_method'])<p class="mt-2 text-gray-400">{{ $row['payment_method'] }}</p>@endif</td>
                                    <td class="space-y-1 px-4 py-4"><p>الطلب: <strong>{{ $row['status_label'] }}</strong></p><p>الدفع: <strong>{{ $row['payment_status_label'] }}</strong></p><p>الطباعة: <strong>{{ $row['printing_status_label'] }}</strong></p><p>الشحن: <strong>{{ $row['shipping_status_label'] }}</strong></p></td>
                                    <td class="px-4 py-4"><p class="font-black text-gray-950">{{ $money($row['total_cents']) }}</p><p class="mt-1 text-[10px] text-gray-400">عناصر {{ $money($row['items_cents']) }} + توصيل {{ $money($row['delivery_cents']) }}</p>@if($row['discount_cents'])<p class="mt-1 text-[10px] text-violet-600">خصم {{ $money($row['discount_cents']) }}</p>@endif</td>
                                    <td class="px-4 py-4"><p class="font-black text-emerald-700">مدفوع {{ $money($row['paid_amount_cents']) }}</p><p class="mt-1 font-bold text-rose-600">متبقي {{ $money($row['remaining_amount_cents']) }}</p></td>
                                    <td class="px-4 py-4 font-bold text-gray-600">{{ $row['assigned_admin']?->name ?: 'غير مستلم' }}</td>
                                    <td class="px-4 py-4"><a href="{{ $detailsUrl }}" class="inline-flex rounded-lg bg-indigo-600 px-3 py-2 font-black text-white hover:bg-indigo-700">فتح الطلب</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-6 py-16 text-center font-bold text-gray-400">لا توجد طلبات تطابق الفلاتر المختارة.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($rows->hasPages())<div class="border-t border-gray-100 p-4">{{ $rows->links() }}</div>@endif
            </section>
        </div>
    </div>
</x-admin-layout>
