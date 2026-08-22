<x-admin-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-right">
                <h2 class="text-xl font-black text-gray-900">إدارة الطلبات</h2>
                <p class="mt-1 text-xs font-bold text-gray-500">كل صف يمثل عملية شراء واحدة حتى لو احتوت على أكثر من قصة.</p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs font-black">
                <span class="rounded-full bg-indigo-50 px-3 py-2 text-indigo-700">{{ number_format($stats['checkouts']) }} عملية شراء</span>
                <span class="rounded-full bg-violet-50 px-3 py-2 text-violet-700">{{ number_format($stats['stories']) }} قصة</span>
                <span class="rounded-full bg-emerald-50 px-3 py-2 text-emerald-700">{{ number_format($stats['products']) }} منتج وإضافة</span>
            </div>
        </div>
    </x-slot>

    @can('orders.create')
        <x-slot name="headerActions">
            <a href="{{ route('admin.orders.create') }}" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white transition hover:bg-indigo-700">+ إضافة طلب</a>
        </x-slot>
    @endcan

    @php
        $statusLabels = [
            '' => 'كل الحالات',
            'mixed' => 'حالات متعددة',
            'new' => 'طلب جديد',
            'under_review' => 'قيد المراجعة',
            'generating' => 'جاري التوليد',
            'preview_uploaded' => 'انتظار الموافقة',
            'revision_requested' => 'طلب تعديلات',
            'approved_for_print' => 'موافق للطباعة',
            'printing' => 'جاري الطباعة',
            'shipped' => 'تم الشحن',
            'delivered' => 'تم التوصيل',
            'cancelled' => 'ملغي',
        ];
        $statusColors = [
            'mixed' => 'bg-slate-100 text-slate-700',
            'new' => 'bg-blue-100 text-blue-700',
            'under_review' => 'bg-amber-100 text-amber-700',
            'generating' => 'bg-purple-100 text-purple-700',
            'preview_uploaded' => 'bg-orange-100 text-orange-700',
            'revision_requested' => 'bg-rose-100 text-rose-700',
            'approved_for_print' => 'bg-teal-100 text-teal-700',
            'printing' => 'bg-indigo-100 text-indigo-700',
            'shipped' => 'bg-cyan-100 text-cyan-700',
            'delivered' => 'bg-green-100 text-green-700',
            'cancelled' => 'bg-red-100 text-red-700',
        ];
        $paymentStatusLabels = ['' => 'كل حالات الدفع'] + \App\Support\OrderPaymentStatus::labels();
        $paymentStatusColors = \App\Support\OrderPaymentStatus::colors();
        $printingStatusLabels = ['' => 'كل حالات الطباعة'] + \App\Support\OrderWorkflowStatus::printingLabels();
        $shippingStatusLabels = ['' => 'كل حالات الشحن'] + \App\Support\OrderWorkflowStatus::shippingLabels();
        $printingStatusColors = \App\Support\OrderWorkflowStatus::printingColors();
        $shippingStatusColors = \App\Support\OrderWorkflowStatus::shippingColors();
    @endphp

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-5 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-bold text-green-800">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-bold text-red-800">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-bold text-red-800">
                    @foreach($errors->all() as $message)<p>{{ $message }}</p>@endforeach
                </div>
            @endif

            <div class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm">
                <div class="mb-4 flex flex-wrap gap-2">
                    <a href="{{ route('admin.orders.index', request()->except(['view', 'page'])) }}"
                       class="rounded-xl px-4 py-2 text-sm font-black {{ !$trash ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        الطلبات الحالية
                    </a>
                    @can('orders.delete')
                        <a href="{{ route('admin.orders.index', array_merge(request()->except('page'), ['view' => 'trash'])) }}"
                           class="rounded-xl px-4 py-2 text-sm font-black {{ $trash ? 'bg-red-600 text-white' : 'bg-red-50 text-red-700 hover:bg-red-100' }}">
                            سلة المحذوفات
                        </a>
                    @endcan
                </div>

                <form method="GET" action="{{ route('admin.orders.index') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-9">
                    @if($trash)<input type="hidden" name="view" value="trash">@endif
                    <div class="xl:col-span-2">
                        <label class="mb-1.5 block text-xs font-black text-gray-600">بحث شامل</label>
                        <input name="q" type="search" value="{{ request('q') }}" placeholder="مرجع، طلب، عميل، هاتف، طفل، قصة أو منتج"
                               class="w-full rounded-xl border-gray-200 text-right text-sm">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-black text-gray-600">الحالة</label>
                        <select name="status" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            @foreach($statusLabels as $value => $label)
                                <option value="{{ $value }}" @selected(request('status', '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-black text-gray-600">حالة الدفع</label>
                        <select name="payment_status" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            @foreach($paymentStatusLabels as $value => $label)
                                <option value="{{ $value }}" @selected(request('payment_status', '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-black text-gray-600">حالة الطباعة</label>
                        <select name="printing_status" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            @foreach($printingStatusLabels as $value => $label)<option value="{{ $value }}" @selected(request('printing_status', '') === $value)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-black text-gray-600">حالة الشحن</label>
                        <select name="shipping_status" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            @foreach($shippingStatusLabels as $value => $label)<option value="{{ $value }}" @selected(request('shipping_status', '') === $value)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-black text-gray-600">من تاريخ</label>
                        <input name="from" type="date" value="{{ request('from') }}" class="w-full rounded-xl border-gray-200 text-sm">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-black text-gray-600">إلى تاريخ</label>
                        <input name="to" type="date" value="{{ request('to') }}" class="w-full rounded-xl border-gray-200 text-sm">
                    </div>
                    <div class="flex items-end gap-2">
                        <button class="flex-1 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white hover:bg-indigo-700">تطبيق</button>
                        <a href="{{ route('admin.orders.index', $trash ? ['view' => 'trash'] : []) }}" class="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-black text-gray-500 hover:bg-gray-50">مسح</a>
                    </div>
                </form>
            </div>

            <section aria-label="إحصائيات الطلبات المطابقة للفلاتر" class="grid grid-cols-2 gap-3 lg:grid-cols-4 xl:grid-cols-7">
                <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-4">
                    <p class="text-xs font-black text-indigo-700">إجمالي الطلبات</p>
                    <p class="mt-2 text-2xl font-black text-indigo-950">{{ number_format($stats['checkouts']) }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-black text-slate-600">إجمالي قيمة الطلبات</p>
                    <p class="mt-2 text-lg font-black text-slate-950">{{ format_money($stats['total_value_cents'] / 100) }}</p>
                </div>
                <div class="rounded-2xl border border-rose-100 bg-rose-50 p-4">
                    <p class="text-xs font-black text-rose-700">الطلبات الملغاة</p>
                    <p class="mt-2 text-2xl font-black text-rose-950">{{ number_format($stats['cancelled_checkouts']) }}</p>
                </div>
                <div class="rounded-2xl border border-rose-100 bg-white p-4">
                    <p class="text-xs font-black text-rose-600">قيمة الطلبات الملغاة</p>
                    <p class="mt-2 text-lg font-black text-rose-950">{{ format_money($stats['cancelled_value_cents'] / 100) }}</p>
                </div>
                <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4">
                    <p class="text-xs font-black text-emerald-700">الطلبات المدفوعة كليًا</p>
                    <p class="mt-2 text-2xl font-black text-emerald-950">{{ number_format($stats['paid_checkouts']) }}</p>
                </div>
                <div class="rounded-2xl border border-emerald-100 bg-white p-4">
                    <p class="text-xs font-black text-emerald-600">قيمة الطلبات المدفوعة كليًا</p>
                    <p class="mt-2 text-lg font-black text-emerald-950">{{ format_money($stats['paid_value_cents'] / 100) }}</p>
                </div>
                <div class="col-span-2 rounded-2xl border border-cyan-100 bg-cyan-50 p-4 lg:col-span-1">
                    <p class="text-xs font-black text-cyan-700">الطلبات المشحونة</p>
                    <p class="mt-2 text-2xl font-black text-cyan-950">{{ number_format($stats['shipped_checkouts']) }}</p>
                </div>
            </section>

            <div class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
                <div class="divide-y divide-gray-100 md:hidden">
                    @forelse($groups as $group)
                        @php
                            $whatsappNumber = \App\Support\Phone::forWhatsApp($group['phone']);
                            $detailsUrl = $group['direct_order_id']
                                ? route('admin.orders.show', $group['direct_order_id'])
                                : route('admin.orders.groups.show', $group['representative_id']);
                        @endphp
                        <article class="space-y-4 p-5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 text-right">
                                    <a href="{{ $detailsUrl }}" class="block truncate font-mono text-sm font-black text-gray-950" dir="ltr">{{ $group['key'] }}</a>
                                    <p class="mt-1 text-[10px] text-gray-400" dir="ltr">{{ implode(' · ', $group['order_numbers']) }}</p>
                                    <p class="mt-2 text-[10px] font-black text-amber-700">المصدر: {{ \App\Support\OrderSource::label($group['order_source']) }}</p>
                                </div>
                                <div class="flex max-w-44 flex-wrap justify-end gap-1" data-workflow-badge-group="{{ $group['representative_id'] }}">
                                    <span data-workflow-badge="status" class="shrink-0 rounded-full px-2 py-1 text-[10px] font-black {{ $statusColors[$group['status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $group['status_label'] }}</span>
                                    <span data-workflow-badge="printing_status" class="shrink-0 rounded-full px-2 py-1 text-[10px] font-black {{ $printingStatusColors[$group['printing_status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $group['printing_status_label'] }}</span>
                                    <span data-workflow-badge="shipping_status" class="shrink-0 rounded-full px-2 py-1 text-[10px] font-black {{ $shippingStatusColors[$group['shipping_status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $group['shipping_status_label'] }}</span>
                                </div>
                            </div>

                            <div class="rounded-2xl bg-slate-50 p-4 text-right">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-black text-gray-900">{{ $group['customer_name'] }}</p>
                                        @if($group['phone'])<p class="mt-1 text-xs text-gray-400" dir="ltr">{{ $group['phone'] }}</p>@endif
                                    </div>
                                    <div class="text-left">
                                        <p class="font-black text-gray-950">{{ format_money($group['total_cents'] / 100) }}</p>
                                        <p class="mt-1 text-[10px] text-gray-400">شامل التوصيل</p>
                                        <span data-workflow-badge="payment_status" class="mt-2 inline-flex rounded-full px-2 py-1 text-[10px] font-black {{ $paymentStatusColors[$group['payment_status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $group['payment_status_label'] }}</span>
                                        @if($group['remaining_amount_cents'] > 0)<p class="mt-1 text-[10px] font-bold text-rose-600">متبقي {{ format_money($group['remaining_amount_cents'] / 100) }}</p>@endif
                                    </div>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-1.5">
                                    @if($group['story_count'])<span class="rounded-full bg-violet-100 px-2.5 py-1 text-xs font-black text-violet-700">{{ $group['story_count'] }} قصة</span>@endif
                                    @if($group['add_on_quantity'])<span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-black text-amber-700">{{ $group['add_on_quantity'] }} إضافة</span>@endif
                                    @if($group['product_quantity'])<span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-black text-emerald-700">{{ $group['product_quantity'] }} منتج</span>@endif
                                </div>
                                @if($group['child_names'])<p class="mt-3 text-xs font-bold text-gray-600">الأطفال: {{ implode('، ', $group['child_names']) }}</p>@endif
                                <p class="mt-1 line-clamp-2 text-xs leading-5 text-gray-500">{{ implode('، ', array_merge($group['story_titles'], $group['add_on_titles'], $group['product_titles'])) }}</p>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <a href="{{ $detailsUrl }}" class="rounded-xl bg-indigo-600 px-3 py-2.5 text-center text-xs font-black text-white">عرض وإدارة</a>
                                @if($whatsappNumber && !$trash)
                                    <a href="https://wa.me/{{ $whatsappNumber }}?text={{ urlencode('مرحباً، بخصوص طلبك '.$group['key']) }}" target="_blank" rel="noopener" class="rounded-xl bg-green-50 px-3 py-2.5 text-center text-xs font-black text-green-700">واتساب</a>
                                @elseif($trash && auth()->user()->hasPermission('orders.delete'))
                                    <form method="POST" action="{{ route('admin.orders.groups.restore', $group['representative_id']) }}" onsubmit="return confirm('استعادة عملية الشراء وكل قصصها؟')">
                                        @csrf
                                        <button class="w-full rounded-xl bg-green-600 px-3 py-2.5 text-xs font-black text-white">استعادة الكل</button>
                                    </form>
                                @else
                                    <span class="rounded-xl bg-gray-50 px-3 py-2.5 text-center text-xs font-bold text-gray-400">{{ optional($group['latest_at'])->format('d/m/Y') }}</span>
                                @endif
                            </div>
                            @can('orders.update')
                                @if(!$trash)
                                    <details>
                                        <summary class="cursor-pointer rounded-xl border border-indigo-100 bg-indigo-50 px-3 py-2.5 text-center text-xs font-black text-indigo-700">تغيير الحالات الأربع</summary>
                                        <div class="mt-3">@include('admin.orders._workflow-status-panel', ['group' => $group])</div>
                                    </details>
                                @endif
                            @endcan
                        </article>
                    @empty
                        <div class="px-6 py-16 text-center text-sm font-bold text-gray-400">{{ $trash ? 'سلة المحذوفات فارغة.' : 'لا توجد عمليات شراء تطابق الفلاتر.' }}</div>
                    @endforelse
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-100 text-right text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">عملية الشراء</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">المصدر</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">العميل</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">المحتويات</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">الحالة</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">القيمة</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">الدفع</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">التاريخ</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($groups as $group)
                                @php
                                    $whatsappNumber = \App\Support\Phone::forWhatsApp($group['phone']);
                                    $detailsUrl = $group['direct_order_id']
                                        ? route('admin.orders.show', $group['direct_order_id'])
                                        : route('admin.orders.groups.show', $group['representative_id']);
                                @endphp
                                <tr class="align-top transition hover:bg-slate-50">
                                    <td class="w-44 max-w-44 px-4 py-4">
                                        <a href="{{ $detailsUrl }}" class="block w-40 truncate font-mono text-xs font-black text-gray-900 hover:text-indigo-700 hover:underline" dir="ltr" title="{{ $group['key'] }}">{{ $group['key'] }}</a>
                                        <p class="mt-1 text-xs text-gray-400">{{ count($group['order_numbers']) }} سجل طلب</p>
                                        <p class="mt-1 max-w-48 truncate text-[10px] text-gray-400" dir="ltr">{{ implode('، ', $group['order_numbers']) }}</p>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-black text-amber-700">{{ \App\Support\OrderSource::label($group['order_source']) }}</span>
                                        @if($group['source_notes'])<p class="mt-2 line-clamp-2 max-w-32 text-[10px] leading-4 text-gray-400">{{ $group['source_notes'] }}</p>@endif
                                    </td>
                                    <td class="px-4 py-4">
                                        @can('customers.view')
                                            <a href="{{ route('admin.customers.show', $group['customer_key']) }}" class="font-bold text-gray-900 hover:text-indigo-700 hover:underline">{{ $group['customer_name'] }}</a>
                                        @else
                                            <p class="font-bold text-gray-900">{{ $group['customer_name'] }}</p>
                                        @endcan
                                        @if($group['phone'])<p class="mt-1 text-xs text-gray-400" dir="ltr">{{ $group['phone'] }}</p>@endif
                                    </td>
                                    <td class="min-w-64 px-4 py-4">
                                        <div class="flex flex-wrap gap-1.5">
                                            @if($group['story_count'])<span class="rounded-full bg-violet-50 px-2.5 py-1 text-xs font-black text-violet-700">{{ $group['story_count'] }} قصة</span>@endif
                                            @if($group['add_on_quantity'])<span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-black text-amber-700">{{ $group['add_on_quantity'] }} إضافة</span>@endif
                                            @if($group['product_quantity'])<span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-black text-emerald-700">{{ $group['product_quantity'] }} منتج مباشر</span>@endif
                                        </div>
                                        @if($group['child_names'])<p class="mt-2 text-xs font-bold text-gray-700">الأطفال: {{ implode('، ', $group['child_names']) }}</p>@endif
                                        <p class="mt-1 line-clamp-2 text-xs text-gray-500">{{ implode('، ', array_merge($group['story_titles'], $group['add_on_titles'], $group['product_titles'])) }}</p>
                                    </td>
                                    <td class="px-4 py-4" data-workflow-badge-group="{{ $group['representative_id'] }}">
                                        <div class="flex min-w-36 flex-col items-start gap-1">
                                            <span data-workflow-badge="status" class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-black {{ $statusColors[$group['status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $group['status_label'] }}</span>
                                            <span data-workflow-badge="printing_status" class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-black {{ $printingStatusColors[$group['printing_status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $group['printing_status_label'] }}</span>
                                            <span data-workflow-badge="shipping_status" class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-black {{ $shippingStatusColors[$group['shipping_status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $group['shipping_status_label'] }}</span>
                                        </div>
                                        @if($group['status'] === 'mixed')
                                            <div class="mt-2 space-y-1 text-[10px] font-bold text-gray-400">
                                                @foreach(collect($group['active_orders'])->groupBy('status') as $status => $same)
                                                    <p>{{ __('order_status.'.$status) }}: {{ $same->count() }}</p>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <p class="font-black text-gray-900">{{ format_money($group['total_cents'] / 100) }}</p>
                                        <p class="mt-1 text-[10px] text-gray-400">التوصيل {{ format_money($group['delivery_cents'] / 100) }}</p>
                                        @if($group['discount_cents'] > 0)<p class="mt-1 text-[10px] font-bold text-rose-600">خصم - {{ format_money($group['discount_cents'] / 100) }}</p>@endif
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <span data-workflow-badge="payment_status" class="inline-flex rounded-full px-2.5 py-1 text-xs font-black {{ $paymentStatusColors[$group['payment_status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $group['payment_status_label'] }}</span>
                                        @if($group['paid_amount_cents'] > 0)<p class="mt-2 text-[10px] font-bold text-emerald-700">مدفوع <span data-workflow-paid>{{ format_money($group['paid_amount_cents'] / 100) }}</span></p>@endif
                                        @if($group['remaining_amount_cents'] > 0)<p class="mt-1 text-[10px] font-bold text-rose-600">متبقي <span data-workflow-remaining>{{ format_money($group['remaining_amount_cents'] / 100) }}</span></p>@endif
                                        @if($group['payment_method'])<p class="mt-1 text-[10px] text-gray-400">{{ $group['payment_method'] }}</p>@endif
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-gray-500" dir="ltr">
                                        <p>{{ optional($group['latest_at'])->format('d/m/Y') }}</p>
                                        <p class="mt-1 text-xs text-gray-400">{{ optional($group['latest_at'])->format('h:i A') }}</p>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex min-w-36 flex-wrap gap-1.5">
                                            <a href="{{ $detailsUrl }}" title="عرض التفاصيل" aria-label="عرض التفاصيل" class="grid h-9 w-9 place-items-center rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </a>
                                            @can('orders.update')
                                                @if(!$trash)
                                                    <a href="{{ route('admin.orders.groups.edit', $group['representative_id']) }}" title="تعديل الطلب" aria-label="تعديل الطلب" class="grid h-9 w-9 place-items-center rounded-lg bg-violet-600 text-white hover:bg-violet-700"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-7.414a2 2 0 112.828 2.828L11.828 17H9v-2.828l8.586-8.586z"/></svg></a>
                                                    <button type="button" data-workflow-toggle="{{ $group['representative_id'] }}" title="تغيير الحالات" aria-label="تغيير الحالات" class="grid h-9 w-9 place-items-center rounded-lg bg-amber-50 text-amber-700 hover:bg-amber-100"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg></button>
                                                @endif
                                            @endcan
                                            @if($whatsappNumber && !$trash)
                                                <a href="https://wa.me/{{ $whatsappNumber }}?text={{ urlencode('مرحباً، بخصوص طلبك '.$group['key']) }}" target="_blank" rel="noopener" title="واتساب" aria-label="واتساب" class="grid h-9 w-9 place-items-center rounded-lg bg-green-50 text-green-700 hover:bg-green-100"><svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M20.52 3.48A11.91 11.91 0 0012.06 0C5.5 0 .16 5.34.16 11.9c0 2.1.55 4.15 1.59 5.95L.06 24l6.29-1.65a11.9 11.9 0 005.7 1.45h.01c6.56 0 11.9-5.34 11.9-11.9 0-3.18-1.22-6.16-3.44-8.42zm-8.46 18.31h-.01a9.88 9.88 0 01-5.04-1.38l-.36-.21-3.73.98 1-3.64-.24-.37a9.86 9.86 0 01-1.51-5.27c0-5.45 4.44-9.89 9.9-9.89a9.82 9.82 0 017 2.9 9.82 9.82 0 012.9 7c-.01 5.45-4.45 9.88-9.9 9.88zm5.42-7.41c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47a8.94 8.94 0 01-1.65-2.05c-.17-.3-.02-.46.13-.6.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.08-.8.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.88 1.22 3.08.15.2 2.1 3.2 5.08 4.49.71.3 1.27.49 1.7.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.3.18-1.42-.08-.12-.27-.2-.57-.35z"/></svg></a>
                                            @endif
                                            @can('orders.delete')
                                                @if($trash)
                                                    <form method="POST" action="{{ route('admin.orders.groups.restore', $group['representative_id']) }}" onsubmit="return confirm('استعادة عملية الشراء وكل قصصها؟')">
                                                        @csrf
                                                        <button class="w-full rounded-lg bg-green-600 px-3 py-2 text-xs font-black text-white hover:bg-green-700">استعادة الكل</button>
                                                    </form>
                                                @endif
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                                @can('orders.update')
                                    @if(!$trash)
                                        <tr class="hidden bg-indigo-50/40" data-workflow-panel-row="{{ $group['representative_id'] }}">
                                            <td colspan="9" class="p-4">@include('admin.orders._workflow-status-panel', ['group' => $group])</td>
                                        </tr>
                                    @endif
                                @endcan
                            @empty
                                <tr><td colspan="9" class="px-6 py-16 text-center text-sm font-bold text-gray-400">{{ $trash ? 'سلة المحذوفات فارغة.' : 'لا توجد عمليات شراء تطابق الفلاتر.' }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($groups->hasPages())
                    <div class="border-t border-gray-100 px-5 py-4">{{ $groups->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-admin-layout>
