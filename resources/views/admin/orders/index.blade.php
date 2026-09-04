<x-admin-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-right">
                <h2 class="text-xl font-black text-gray-900">إدارة الطلبات</h2>
                <p class="mt-1 text-xs font-bold text-gray-500">كل صف يمثل عملية شراء واحدة حتى لو احتوت على أكثر من قصة.</p>
            </div>
            @can('orders.statistics.view')
                <div class="flex flex-wrap gap-2 text-xs font-black">
                    <span class="rounded-full bg-indigo-50 px-3 py-2 text-indigo-700">{{ number_format($stats['checkouts']) }} عملية شراء</span>
                    <span class="rounded-full bg-violet-50 px-3 py-2 text-violet-700">{{ number_format($stats['stories']) }} قصة</span>
                    <span class="rounded-full bg-emerald-50 px-3 py-2 text-emerald-700">{{ number_format($stats['products']) }} منتج وإضافة</span>
                </div>
            @endcan
        </div>
    </x-slot>

    @can('orders.create')
        <x-slot name="headerActions">
            <a href="{{ route('admin.orders.create') }}" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white transition hover:bg-indigo-700">+ إضافة طلب</a>
        </x-slot>
    @endcan

    @php
        $statusLabels = ['' => 'كل الحالات', 'mixed' => 'حالات متعددة'] + \App\Services\Orders\OrderStatusService::labels(false);
        $statusColors = ['mixed' => 'bg-slate-100 text-slate-700'] + \App\Services\Orders\OrderStatusService::colors();
        $paymentStatusLabels = ['' => 'كل حالات الدفع'] + \App\Support\OrderPaymentStatus::labels(false);
        $paymentStatusColors = \App\Support\OrderPaymentStatus::colors();
        $printingStatusLabels = ['' => 'كل حالات الطباعة'] + \App\Support\OrderWorkflowStatus::printingLabels(false);
        $shippingStatusLabels = ['' => 'كل حالات الشحن'] + \App\Support\OrderWorkflowStatus::shippingLabels(false);
        $printingStatusColors = \App\Support\OrderWorkflowStatus::printingColors();
        $shippingStatusColors = \App\Support\OrderWorkflowStatus::shippingColors();
        $eventOrderStatuses = \App\Services\Orders\OrderStatusService::labels(false);
        $eventPaymentStatuses = \App\Support\OrderPaymentStatus::labels(false);
        $eventPrintingStatuses = \App\Support\OrderWorkflowStatus::printingLabels(false);
        $eventShippingStatuses = \App\Support\OrderWorkflowStatus::shippingLabels(false);
        $nextUpdatedDirection = request('sort') === 'updated_at' && request('direction', 'desc') === 'desc' ? 'asc' : 'desc';
        $tabQuery = request()->except(['view', 'page', 'catalog_type', 'lifecycle']);
        $emptyState = match($lifecycle) {
            'finished' => 'لا توجد طلبات منتهية تطابق الفلاتر.',
            'cancelled' => 'لا توجد طلبات ملغاة أو محذوفة تطابق الفلاتر.',
            default => 'لا توجد طلبات نشطة تطابق الفلاتر.',
        };
    @endphp

    <div class="py-8">
        <div class="w-full max-w-none space-y-5 px-4 sm:px-6 lg:px-8">
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
                <div class="mb-4 flex flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('admin.orders.index', array_merge($tabQuery, ['catalog_type' => 'stories', 'lifecycle' => $lifecycle])) }}"
                           class="rounded-xl px-4 py-2.5 text-sm font-black {{ $catalogType === 'stories' ? 'bg-violet-600 text-white' : 'bg-violet-50 text-violet-700 hover:bg-violet-100' }}">
                            طلبات القصص
                        </a>
                        <a href="{{ route('admin.orders.index', array_merge($tabQuery, ['catalog_type' => 'products', 'lifecycle' => $lifecycle])) }}"
                           class="rounded-xl px-4 py-2.5 text-sm font-black {{ $catalogType === 'products' ? 'bg-emerald-600 text-white' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' }}">
                            طلبات المنتجات
                        </a>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                        <a href="{{ route('admin.orders.index', array_merge($tabQuery, ['catalog_type' => $catalogType === 'all' ? 'stories' : $catalogType, 'lifecycle' => 'active'])) }}"
                           class="rounded-xl px-4 py-2 text-sm font-black {{ $lifecycle === 'active' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                            الطلبات النشطة
                        </a>
                        <a href="{{ route('admin.orders.index', array_merge($tabQuery, ['catalog_type' => $catalogType === 'all' ? 'stories' : $catalogType, 'lifecycle' => 'finished'])) }}"
                           class="rounded-xl px-4 py-2 text-sm font-black {{ $lifecycle === 'finished' ? 'bg-slate-700 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                            الطلبات المنتهية
                        </a>
                        <a href="{{ route('admin.orders.index', array_merge($tabQuery, ['catalog_type' => $catalogType === 'all' ? 'stories' : $catalogType, 'lifecycle' => 'cancelled'])) }}"
                           class="rounded-xl px-4 py-2 text-sm font-black {{ $lifecycle === 'cancelled' ? 'bg-red-600 text-white' : 'bg-red-50 text-red-700 hover:bg-red-100' }}">
                            ملغاة / محذوفة
                        </a>
                        <a href="{{ route('admin.orders.export', request()->except('page')) }}"
                           class="me-auto rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-black text-emerald-700 transition hover:bg-emerald-100">
                            تصدير Excel (CSV)
                        </a>
                    </div>
                </div>

                <form method="GET" action="{{ route('admin.orders.index') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-12">
                    <input type="hidden" name="catalog_type" value="{{ $catalogType }}">
                    <input type="hidden" name="lifecycle" value="{{ $lifecycle }}">
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
                    <div class="xl:col-span-2">
                        <label class="mb-1.5 block text-xs font-black text-gray-600">المنتج الموجود بالطلب</label>
                        <select name="product_id" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            <option value="">كل المنتجات</option>
                            @foreach($filterProducts as $filterProduct)
                                <option value="{{ $filterProduct->id }}" @selected((string) request('product_id') === (string) $filterProduct->id)>{{ $filterProduct->name_ar }}</option>
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
                    <div class="xl:col-span-2">
                        <label class="mb-1.5 block text-xs font-black text-gray-600">حدث وقع على الطلب</label>
                        <select name="event" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            <option value="">كل الأحداث</option>
                            <optgroup label="تغيير حالة الطلب">
                                @foreach($eventOrderStatuses as $value => $label)<option value="order:{{ $value }}" @selected(request('event') === 'order:'.$value)>أصبح: {{ $label }}</option>@endforeach
                            </optgroup>
                            <optgroup label="تغيير حالة الدفع">
                                @foreach($eventPaymentStatuses as $value => $label)<option value="payment:{{ $value }}" @selected(request('event') === 'payment:'.$value)>أصبح: {{ $label }}</option>@endforeach
                                <option value="payment_event:received" @selected(request('event') === 'payment_event:received')>تم تسجيل دفعة فعلية</option>
                                <option value="payment_event:reversed" @selected(request('event') === 'payment_event:reversed')>تم عكس / تخفيض دفعة</option>
                            </optgroup>
                            <optgroup label="تغيير حالة الطباعة">
                                @foreach($eventPrintingStatuses as $value => $label)<option value="printing:{{ $value }}" @selected(request('event') === 'printing:'.$value)>أصبحت: {{ $label }}</option>@endforeach
                            </optgroup>
                            <optgroup label="تغيير حالة الشحن">
                                @foreach($eventShippingStatuses as $value => $label)<option value="shipping:{{ $value }}" @selected(request('event') === 'shipping:'.$value)>أصبحت: {{ $label }}</option>@endforeach
                            </optgroup>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-black text-gray-600">الحدث من يوم</label>
                        <input name="event_from" type="date" value="{{ request('event_from') }}" class="w-full rounded-xl border-gray-200 text-sm">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-black text-gray-600">الحدث إلى يوم</label>
                        <input name="event_to" type="date" value="{{ request('event_to') }}" class="w-full rounded-xl border-gray-200 text-sm">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-black text-gray-600">مسؤول الطلب</label>
                        <select name="assignment" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            <option value="">كل الطلبات</option>
                            <option value="mine" @selected(request('assignment') === 'mine')>طلباتي</option>
                            <option value="unassigned" @selected(request('assignment') === 'unassigned')>غير مستلمة</option>
                            <option value="assigned" @selected(request('assignment') === 'assigned')>مستلمة</option>
                            @if($assignmentUsers->isNotEmpty())
                                <optgroup label="حسب المستخدم">
                                    @foreach($assignmentUsers as $assignmentUser)
                                        <option value="user:{{ $assignmentUser->id }}" @selected(request('assignment') === 'user:'.$assignmentUser->id)>
                                            {{ $assignmentUser->name }}{{ $assignmentUser->is_active ? '' : ' (غير نشط)' }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-black text-gray-600">عدد الطلبات</label>
                        <select name="per_page" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            @foreach([25, 50, 100] as $size)
                                <option value="{{ $size }}" @selected($groups->perPage() === $size)>{{ $size }} طلب</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-black text-gray-600">الترتيب</label>
                        <select name="sort" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            <option value="created_at" @selected(request('sort', 'created_at') === 'created_at')>تاريخ الإنشاء</option>
                            <option value="updated_at" @selected(request('sort') === 'updated_at')>آخر تحديث</option>
                        </select>
                        <select name="direction" aria-label="اتجاه الترتيب" class="mt-2 w-full rounded-xl border-gray-200 text-right text-sm">
                            <option value="desc" @selected(request('direction', 'desc') === 'desc')>الأحدث أولاً</option>
                            <option value="asc" @selected(request('direction') === 'asc')>الأقدم أولاً</option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button class="flex-1 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white hover:bg-indigo-700">تطبيق</button>
                        <a href="{{ route('admin.orders.index', ['catalog_type' => $catalogType === 'all' ? 'stories' : $catalogType, 'lifecycle' => $lifecycle]) }}" class="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-black text-gray-500 hover:bg-gray-50">مسح</a>
                    </div>
                </form>
            </div>

            @can('orders.statistics.view')
            <section aria-label="إحصائيات الطلبات المطابقة للفلاتر" class="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-5">
                <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-4">
                    <p class="text-xs font-black text-indigo-700">إجمالي الطلبات</p>
                    <p class="mt-2 text-2xl font-black text-indigo-950">{{ number_format($stats['checkouts']) }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-black text-slate-600">إجمالي قيمة الطلبات</p>
                    <p class="mt-2 text-lg font-black text-slate-950">{{ format_money($stats['total_value_cents'] / 100) }}</p>
                </div>
                <div class="rounded-2xl border border-amber-100 bg-amber-50 p-4">
                    <p class="text-xs font-black text-amber-700">متوسط الطلب</p>
                    <p class="mt-2 text-lg font-black text-amber-950">{{ format_money($stats['average_order_cents'] / 100) }}</p>
                </div>
                <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4">
                    <p class="text-xs font-black text-emerald-700">إجمالي المدفوع</p>
                    <p class="mt-2 text-lg font-black text-emerald-950">{{ format_money($stats['collected_cents'] / 100) }}</p>
                    <p class="mt-1 text-[10px] font-bold text-emerald-700">من {{ number_format($stats['payment_checkouts']) }} عملية شراء</p>
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
                <div class="rounded-2xl border border-cyan-100 bg-cyan-50 p-4">
                    <p class="text-xs font-black text-cyan-700">الطلبات المشحونة</p>
                    <p class="mt-2 text-2xl font-black text-cyan-950">{{ number_format($stats['shipped_checkouts']) }}</p>
                </div>
            </section>
            @endcan

            <div class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
                <div class="divide-y divide-gray-100 md:hidden">
                    @forelse($groups as $group)
                        @php
                            $detailsUrl = route('admin.orders.groups.show', $group['representative_id']);
                        @endphp
                        <article class="space-y-4 p-5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 text-right">
                                    <a href="{{ $detailsUrl }}" class="block truncate font-mono text-base font-black text-indigo-700" dir="ltr">{{ $group['short_reference'] ?: $group['key'] }}</a>
                                    <p class="mt-1 truncate text-[9px] text-gray-400" dir="ltr" title="{{ $group['key'] }}">{{ $group['key'] }}</p>
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
                                @php
                                    $mobileUpdatedAt = \App\Support\OrderDateTime::display($group['updated_at']);
                                    $mobileCreatedAt = \App\Support\OrderDateTime::display($group['created_at']);
                                @endphp
                                <p class="mt-3 text-[10px] font-bold text-gray-500" dir="ltr">آخر تحديث: {{ $mobileUpdatedAt?->format('d/m/Y h:i A') }}</p>
                                <p class="mt-1 text-[10px] text-gray-400" dir="ltr">الإنشاء: {{ $mobileCreatedAt?->format('d/m/Y h:i A') }}</p>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <a href="{{ $detailsUrl }}" class="rounded-xl bg-indigo-600 px-3 py-2.5 text-center text-xs font-black text-white">عرض وإدارة</a>
                                @if(!empty($group['whatsapp_messages']) && !$group['trashed'])
                                    <div class="col-span-2 rounded-2xl border border-green-100 bg-green-50 p-2">
                                        @include('admin.orders._whatsapp-message-actions', ['whatsappMessages' => $group['whatsapp_messages']])
                                    </div>
                                @elseif($group['trashed'] && auth()->user()->hasPermission('orders.delete'))
                                    <form method="POST" action="{{ route('admin.orders.groups.restore', $group['representative_id']) }}" onsubmit="return confirm('استعادة عملية الشراء وكل قصصها؟')">
                                        @csrf
                                        <button class="w-full rounded-xl bg-green-600 px-3 py-2.5 text-xs font-black text-white">استعادة الكل</button>
                                    </form>
                                @else
                                    @php
                                        $displayOrderDate = \App\Support\OrderDateTime::display($group['latest_at']);
                                    @endphp
                                    <span class="rounded-xl bg-gray-50 px-3 py-2.5 text-center text-xs font-bold text-gray-400" dir="ltr">{{ $displayOrderDate?->format('d/m/Y h:i A') }}</span>
                                @endif
                            </div>
                            @include('admin.orders._assignment-controls', ['group' => $group, 'compact' => true])
                            @can('orders.update')
                                @if(!$group['trashed'])
                                    <details>
                                        <summary class="cursor-pointer rounded-xl border border-indigo-100 bg-indigo-50 px-3 py-2.5 text-center text-xs font-black text-indigo-700">تغيير الحالات الأربع</summary>
                                        <div class="mt-3">@include('admin.orders._workflow-status-panel', ['group' => $group])</div>
                                    </details>
                                @endif
                            @endcan
                        </article>
                    @empty
                        <div class="px-6 py-16 text-center text-sm font-bold text-gray-400">{{ $emptyState }}</div>
                    @endforelse
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-100 text-right text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">عملية الشراء</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">المصدر</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">العميل</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">المسؤول</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">المحتويات</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">الحالة</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">القيمة</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">الدفع</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">
                                    <a href="{{ route('admin.orders.index', array_merge(request()->except('page'), ['sort' => 'updated_at', 'direction' => $nextUpdatedDirection])) }}" class="inline-flex items-center gap-1 hover:text-indigo-700">
                                        آخر تحديث
                                        <span aria-hidden="true">{{ request('sort') === 'updated_at' ? (request('direction', 'desc') === 'desc' ? '↓' : '↑') : '↕' }}</span>
                                    </a>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($groups as $group)
                                @php
                                    $detailsUrl = route('admin.orders.groups.show', $group['representative_id']);
                                @endphp
                                <tr class="align-top transition hover:bg-slate-50">
                                    <td class="w-44 max-w-44 px-4 py-4" data-order-primary-cell>
                                        <a href="{{ $detailsUrl }}" class="block w-40 truncate font-mono text-sm font-black text-indigo-700 hover:text-indigo-900 hover:underline" dir="ltr" title="{{ $group['short_reference'] ?: $group['key'] }}">{{ $group['short_reference'] ?: $group['key'] }}</a>
                                        <p class="mt-1 text-xs text-gray-400">{{ count($group['order_numbers']) }} سجل طلب</p>
                                        <p class="mt-1 max-w-40 truncate text-[9px] text-gray-400" dir="ltr" title="{{ $group['key'] }}">{{ $group['key'] }}</p>
                                        <p class="mt-1 max-w-48 truncate text-[10px] text-gray-400" dir="ltr">{{ implode('، ', $group['order_numbers']) }}</p>
                                        <div class="mt-2 flex max-w-40 flex-wrap gap-1" data-order-row-actions>
                                            <a href="{{ $detailsUrl }}" title="عرض التفاصيل" aria-label="عرض التفاصيل" class="grid h-8 w-8 place-items-center rounded-md bg-indigo-50 text-indigo-700 hover:bg-indigo-100">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </a>
                                            @can('orders.update')
                                                @if(!$group['trashed'])
                                                    <a href="{{ route('admin.orders.groups.edit', $group['representative_id']) }}" title="تعديل الطلب" aria-label="تعديل الطلب" class="grid h-8 w-8 place-items-center rounded-md bg-violet-600 text-white hover:bg-violet-700"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-7.414a2 2 0 112.828 2.828L11.828 17H9v-2.828l8.586-8.586z"/></svg></a>
                                                    <button type="button" data-workflow-toggle="{{ $group['representative_id'] }}" title="تغيير الحالات" aria-label="تغيير الحالات" class="grid h-8 w-8 place-items-center rounded-md bg-amber-50 text-amber-700 hover:bg-amber-100"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg></button>
                                                @endif
                                            @endcan
                                            @if(!empty($group['whatsapp_messages']) && !$group['trashed'])
                                                @include('admin.orders._whatsapp-message-actions', ['whatsappMessages' => $group['whatsapp_messages'], 'compact' => true])
                                            @endif
                                            @can('orders.delete')
                                                @if($group['trashed'])
                                                    <form method="POST" action="{{ route('admin.orders.groups.restore', $group['representative_id']) }}" onsubmit="return confirm('استعادة عملية الشراء وكل قصصها؟')">
                                                        @csrf
                                                        <button class="rounded-md bg-green-600 px-2 py-1.5 text-[10px] font-black text-white hover:bg-green-700">استعادة الكل</button>
                                                    </form>
                                                @endif
                                            @endcan
                                        </div>
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
                                    <td class="min-w-36 px-4 py-4">
                                        @include('admin.orders._assignment-controls', ['group' => $group, 'compact' => true])
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
                                                    <p>{{ \App\Support\OrderStatusRegistry::label(\App\Support\OrderStatusRegistry::TYPE_ORDER, $status) }}: {{ $same->count() }}</p>
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
                                        @php
                                            $displayUpdatedAt = \App\Support\OrderDateTime::display($group['updated_at']);
                                            $displayCreatedAt = \App\Support\OrderDateTime::display($group['created_at']);
                                        @endphp
                                        <p class="font-bold text-gray-700">{{ $displayUpdatedAt?->format('d/m/Y') }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ $displayUpdatedAt?->format('h:i A') }}</p>
                                        <p class="mt-2 text-[9px] text-gray-400">إنشاء {{ $displayCreatedAt?->format('d/m/Y h:i A') }}</p>
                                    </td>
                                </tr>
                                @can('orders.update')
                                    @if(!$group['trashed'])
                                        <tr class="hidden bg-indigo-50/40" data-workflow-panel-row="{{ $group['representative_id'] }}">
                                            <td colspan="9" class="p-4">@include('admin.orders._workflow-status-panel', ['group' => $group])</td>
                                        </tr>
                                    @endif
                                @endcan
                            @empty
                                <tr><td colspan="9" class="px-6 py-16 text-center text-sm font-bold text-gray-400">{{ $emptyState }}</td></tr>
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
