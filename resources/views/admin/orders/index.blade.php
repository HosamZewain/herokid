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
            'approved_for_print' => 'bg-teal-100 text-teal-700',
            'printing' => 'bg-indigo-100 text-indigo-700',
            'shipped' => 'bg-cyan-100 text-cyan-700',
            'delivered' => 'bg-green-100 text-green-700',
            'cancelled' => 'bg-red-100 text-red-700',
        ];
        $paymentStatusLabels = ['' => 'كل حالات الدفع'] + \App\Support\OrderPaymentStatus::labels();
        $paymentStatusColors = \App\Support\OrderPaymentStatus::colors();
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

                <form method="GET" action="{{ route('admin.orders.index') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-7">
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

            <div class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
                <div class="divide-y divide-gray-100 md:hidden">
                    @forelse($groups as $group)
                        <article class="space-y-4 p-5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 text-right">
                                    <a href="{{ route('admin.orders.groups.show', $group['representative_id']) }}" class="block truncate font-mono text-sm font-black text-gray-950" dir="ltr">{{ $group['key'] }}</a>
                                    <p class="mt-1 text-[10px] text-gray-400" dir="ltr">{{ implode(' · ', $group['order_numbers']) }}</p>
                                    @if($group['order_source'] !== 'website')
                                        <span class="mt-2 inline-flex rounded-full bg-amber-50 px-2 py-1 text-[10px] font-black text-amber-700">{{ \App\Support\OrderSource::label($group['order_source']) }}</span>
                                    @endif
                                </div>
                                <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-black {{ $statusColors[$group['status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $group['status_label'] }}</span>
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
                                        <span class="mt-2 inline-flex rounded-full px-2 py-1 text-[10px] font-black {{ $paymentStatusColors[$group['payment_status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $group['payment_status_label'] }}</span>
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
                                <a href="{{ route('admin.orders.groups.show', $group['representative_id']) }}" class="rounded-xl bg-indigo-600 px-3 py-2.5 text-center text-xs font-black text-white">عرض وإدارة</a>
                                @if($group['phone'] && !$trash)
                                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $group['phone']) }}?text={{ urlencode('مرحباً، بخصوص طلبك '.$group['key']) }}" target="_blank" class="rounded-xl bg-green-50 px-3 py-2.5 text-center text-xs font-black text-green-700">واتساب</a>
                                @elseif($trash && auth()->user()->hasPermission('orders.delete'))
                                    <form method="POST" action="{{ route('admin.orders.groups.restore', $group['representative_id']) }}" onsubmit="return confirm('استعادة عملية الشراء وكل قصصها؟')">
                                        @csrf
                                        <button class="w-full rounded-xl bg-green-600 px-3 py-2.5 text-xs font-black text-white">استعادة الكل</button>
                                    </form>
                                @else
                                    <span class="rounded-xl bg-gray-50 px-3 py-2.5 text-center text-xs font-bold text-gray-400">{{ optional($group['latest_at'])->format('d/m/Y') }}</span>
                                @endif
                            </div>
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
                                <tr class="align-top transition hover:bg-slate-50">
                                    <td class="px-4 py-4">
                                        <a href="{{ route('admin.orders.groups.show', $group['representative_id']) }}" class="font-black text-gray-900 hover:text-indigo-700 hover:underline" dir="ltr">{{ $group['key'] }}</a>
                                        <p class="mt-1 text-xs text-gray-400">{{ count($group['order_numbers']) }} سجل طلب</p>
                                        <p class="mt-1 max-w-48 truncate text-[10px] text-gray-400" dir="ltr">{{ implode('، ', $group['order_numbers']) }}</p>
                                        @if($group['order_source'] !== 'website')
                                            <span class="mt-2 inline-flex rounded-full bg-amber-50 px-2 py-1 text-[10px] font-black text-amber-700">{{ \App\Support\OrderSource::label($group['order_source']) }}</span>
                                        @endif
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
                                    <td class="px-4 py-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-black {{ $statusColors[$group['status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $group['status_label'] }}</span>
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
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-black {{ $paymentStatusColors[$group['payment_status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $group['payment_status_label'] }}</span>
                                        @if($group['paid_amount_cents'] > 0)<p class="mt-2 text-[10px] font-bold text-emerald-700">مدفوع {{ format_money($group['paid_amount_cents'] / 100) }}</p>@endif
                                        @if($group['remaining_amount_cents'] > 0)<p class="mt-1 text-[10px] font-bold text-rose-600">متبقي {{ format_money($group['remaining_amount_cents'] / 100) }}</p>@endif
                                        @if($group['payment_method'])<p class="mt-1 text-[10px] text-gray-400">{{ $group['payment_method'] }}</p>@endif
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap text-gray-500" dir="ltr">{{ optional($group['latest_at'])->format('d/m/Y') }}</td>
                                    <td class="px-4 py-4">
                                        <div class="flex min-w-32 flex-col gap-2">
                                            <a href="{{ route('admin.orders.groups.show', $group['representative_id']) }}" class="rounded-lg bg-indigo-50 px-3 py-2 text-center text-xs font-black text-indigo-700 hover:bg-indigo-100">عرض التفاصيل</a>
                                            @can('orders.update')
                                                @if(!$trash)<a href="{{ route('admin.orders.groups.edit', $group['representative_id']) }}" class="rounded-lg bg-violet-600 px-3 py-2 text-center text-xs font-black text-white hover:bg-violet-700">تعديل الطلب</a>@endif
                                            @endcan
                                            @if($group['phone'] && !$trash)
                                                <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $group['phone']) }}?text={{ urlencode('مرحباً، بخصوص طلبك '.$group['key']) }}" target="_blank" class="rounded-lg bg-green-50 px-3 py-2 text-center text-xs font-black text-green-700 hover:bg-green-100">واتساب</a>
                                            @endif
                                            @can('orders.delete')
                                                @if($trash)
                                                    <form method="POST" action="{{ route('admin.orders.groups.restore', $group['representative_id']) }}" onsubmit="return confirm('استعادة عملية الشراء وكل قصصها؟')">
                                                        @csrf
                                                        <button class="w-full rounded-lg bg-green-600 px-3 py-2 text-xs font-black text-white hover:bg-green-700">استعادة الكل</button>
                                                    </form>
                                                @else
                                                    <details class="rounded-lg border border-red-100 bg-red-50 p-2">
                                                        <summary class="cursor-pointer text-center text-xs font-black text-red-700">حذف العملية</summary>
                                                        <form method="POST" action="{{ route('admin.orders.groups.destroy', $group['representative_id']) }}" class="mt-2 space-y-2">
                                                            @csrf
                                                            @method('DELETE')
                                                            <textarea name="deletion_reason" required minlength="5" rows="2" placeholder="سبب الحذف" class="w-full rounded-lg border-red-200 text-xs"></textarea>
                                                            <input name="confirmation" required placeholder="اكتب {{ $group['key'] }}" class="w-full rounded-lg border-red-200 text-xs" dir="ltr">
                                                            <button class="w-full rounded-lg bg-red-600 px-2 py-2 text-xs font-black text-white">نقل للمحذوفات</button>
                                                        </form>
                                                    </details>
                                                @endif
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-6 py-16 text-center text-sm font-bold text-gray-400">{{ $trash ? 'سلة المحذوفات فارغة.' : 'لا توجد عمليات شراء تطابق الفلاتر.' }}</td></tr>
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
