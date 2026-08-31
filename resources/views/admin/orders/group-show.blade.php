<x-admin-layout>
    <x-slot name="title">{{ $group['short_reference'] ?: $group['key'] }}</x-slot>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-right">
                <p class="text-xs font-black text-indigo-500">تفاصيل عملية الشراء</p>
                <h2 class="mt-1 text-2xl font-black text-indigo-700" dir="ltr">{{ $group['short_reference'] ?: $group['key'] }}</h2>
                @if($group['short_reference'])<p class="mt-1 text-[10px] font-mono text-gray-400" dir="ltr">{{ $group['key'] }}</p>@endif
            </div>
            <div class="flex flex-wrap gap-1.5" data-workflow-badge-group="{{ $group['representative_id'] }}">
                <span data-workflow-badge="status" class="inline-flex rounded-full px-3 py-1.5 text-xs font-black {{ $group['status'] === 'mixed' ? 'bg-slate-100 text-slate-700' : \App\Support\OrderStatusRegistry::color(\App\Support\OrderStatusRegistry::TYPE_ORDER, $group['status']) }}">{{ $group['status_label'] }}</span>
                <span data-workflow-badge="payment_status" class="inline-flex rounded-full px-3 py-1.5 text-xs font-black {{ \App\Support\OrderStatusRegistry::color(\App\Support\OrderStatusRegistry::TYPE_PAYMENT, $group['payment_status']) }}">{{ $group['payment_status_label'] }}</span>
                <span data-workflow-badge="printing_status" class="inline-flex rounded-full px-3 py-1.5 text-xs font-black {{ \App\Support\OrderStatusRegistry::color(\App\Support\OrderStatusRegistry::TYPE_PRINTING, $group['printing_status']) }}">{{ $group['printing_status_label'] }}</span>
                <span data-workflow-badge="shipping_status" class="inline-flex rounded-full px-3 py-1.5 text-xs font-black {{ \App\Support\OrderStatusRegistry::color(\App\Support\OrderStatusRegistry::TYPE_SHIPPING, $group['shipping_status']) }}">{{ $group['shipping_status_label'] }}</span>
            </div>
        </div>
    </x-slot>
    <x-slot name="headerActions">
        @include('admin.orders._activity-log-button')
    </x-slot>

    @php
        $sourceLabel = \App\Support\OrderSource::label($group['order_source']);
        $statusLabels = \App\Services\Orders\OrderStatusService::labels(false);
        $statusColors = \App\Services\Orders\OrderStatusService::colors();
        $visibleStoryOrders = $group['story_orders'];
        $deletedStoryOrders = $group['deleted_orders']->filter(fn ($order) => $order->story_id || $order->items->contains('item_type', 'story'));
        $paymentStatusColors = \App\Support\OrderPaymentStatus::colors();
        $adminNotesCount = collect($orderAdminNotes ?? [])->count();
        $attachmentsCount = collect($attachmentOrders ?? [])->sum(fn ($order) => $order->attachments->count());
    @endphp

    @include('admin.orders._activity-drawer', ['activityTargetOrder' => $attachmentTarget])

    <div class="py-8">
        <div class="mx-auto w-full max-w-none space-y-6 px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-bold text-green-800">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-bold text-red-800">
                    @foreach($errors->all() as $message)<p>{{ $message }}</p>@endforeach
                </div>
            @endif

            @include('admin.orders._merge-checkout', ['mergeGroup' => $group])

            <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" aria-label="إجراءات الطلب الأساسية">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('admin.orders.index', $group['trashed'] ? ['view' => 'trash'] : []) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-black text-gray-600 hover:bg-gray-50">العودة إلى الطلبات</a>
                    @can('orders.update')
                        @if(!$group['trashed'])
                                <a href="{{ route('admin.orders.groups.edit', $group['representative_id']) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-black text-white hover:bg-violet-700">تعديل الطلب بالكامل</a>
                        @endif
                    @endcan
                    @if(!empty($whatsappMessages) && !$group['trashed'])
                            @include('admin.orders._whatsapp-message-actions', ['whatsappMessages' => $whatsappMessages, 'compact' => true, 'labelledCompact' => true])
                    @endif
                    @can('orders.delete')
                        @if($group['trashed'])
                            <form method="POST" action="{{ route('admin.orders.groups.restore', $group['representative_id']) }}" onsubmit="return confirm('سيتم استعادة كل الطلبات والمخزون المرتبط. هل تريد المتابعة؟')">
                                @csrf
                                <button class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white hover:bg-emerald-700">استعادة العملية كاملة</button>
                            </form>
                        @endif
                    @endcan
                    </div>
                    <div class="flex flex-col gap-2 rounded-xl bg-sky-50/70 px-4 py-3 sm:flex-row sm:items-center sm:justify-between xl:min-w-[22rem]">
                        <div>
                            <p class="text-xs font-black text-gray-900">مسؤول تنفيذ الطلب</p>
                            <p class="mt-0.5 text-[11px] font-bold text-gray-500">حدد بوضوح من يعمل على الطلب الآن.</p>
                        </div>
                        @include('admin.orders._assignment-controls', ['group' => $group])
                    </div>
                </div>
            </section>

            @if($group['trashed'])
                <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-bold text-red-800">
                    هذه العملية موجودة في سلة المحذوفات. الملفات وسجل الإنتاج محفوظان ولم يتم حذفهما نهائياً.
                </div>
            @endif

            <section id="order-overview" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]" data-order-page-section="overview">
                <div class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-6">
                    <h3 class="mb-4 text-lg font-black text-gray-900">العميل والتوصيل</h3>
                    <div class="grid gap-4 text-sm md:grid-cols-2">
                        <div><p class="text-xs font-bold text-gray-400">اسم ولي الأمر</p><p class="mt-1 font-black text-gray-900">{{ $group['customer_name'] }}</p></div>
                        <div><p class="text-xs font-bold text-gray-400">الهاتف</p><p class="mt-1 font-black text-gray-900" dir="ltr">{{ $group['phone'] ?: '—' }}</p></div>
                        <div><p class="text-xs font-bold text-gray-400">الدولة / المحافظة</p><p class="mt-1 font-bold text-gray-800">{{ data_get($group['delivery'], 'country', '—') }} / {{ data_get($group['delivery'], 'governorate', '—') }}</p></div>
                        <div><p class="text-xs font-bold text-gray-400">المدينة / الشارع</p><p class="mt-1 font-bold text-gray-800">{{ data_get($group['delivery'], 'city', '—') }} / {{ data_get($group['delivery'], 'street', '—') }}</p></div>
                        <div class="md:col-span-2"><p class="text-xs font-bold text-gray-400">تفاصيل العنوان</p><p class="mt-1 font-bold text-gray-800">{{ data_get($group['delivery'], 'address_details', data_get($group['delivery'], 'address', '—')) }}</p></div>
                        <div><p class="text-xs font-bold text-gray-400">مصدر الطلب</p><p class="mt-1 font-black text-gray-900">{{ $sourceLabel }}</p></div>
                        <div><p class="text-xs font-bold text-gray-400">أُنشئ بواسطة</p><p class="mt-1 font-bold text-gray-800">{{ $group['created_by_admin']?->name ?? 'العميل عبر الموقع' }}</p></div>
                        @if($group['source_notes'])<div class="md:col-span-2"><p class="text-xs font-bold text-gray-400">تفاصيل المصدر</p><p class="mt-1 font-bold text-gray-800">{{ $group['source_notes'] }}</p></div>@endif
                    </div>
                </div>
                <aside class="rounded-3xl border border-indigo-100 bg-indigo-50 p-5 xl:sticky xl:top-5 xl:self-start">
                    <h3 class="text-base font-black text-indigo-900">ملخص القيمة</h3>
                    <div class="mt-5 space-y-3 text-sm font-bold text-indigo-900">
                        <div class="flex justify-between gap-3"><span>العناصر</span><span>{{ format_money($group['items_cents'] / 100) }}</span></div>
                        <div class="flex justify-between gap-3"><span>التوصيل</span><span>{{ format_money($group['delivery_cents'] / 100) }}</span></div>
                        @if($group['discount_cents'] > 0)
                            <div class="flex justify-between gap-3 text-rose-700"><span>الخصم</span><span>- {{ format_money($group['discount_cents'] / 100) }}</span></div>
                            @if($group['discount_reason'])<p class="rounded-xl bg-white/70 px-3 py-2 text-xs text-rose-700">{{ $group['discount_reason'] }}</p>@endif
                        @endif
                        <div class="flex justify-between gap-3 border-t border-indigo-200 pt-3 text-lg font-black"><span>الإجمالي</span><span>{{ format_money($group['total_cents'] / 100) }}</span></div>
                    </div>
                    <div class="mt-5 border-t border-indigo-200 pt-4">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs font-black text-indigo-800">حالة الدفع</span>
                            <span class="rounded-full px-3 py-1 text-xs font-black {{ $paymentStatusColors[$group['payment_status']] ?? 'bg-gray-100 text-gray-700' }}">{{ $group['payment_status_label'] }}</span>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs font-bold">
                            <div class="rounded-xl bg-white/70 p-3"><p class="text-gray-400">المدفوع</p><p class="mt-1 text-emerald-700">{{ format_money($group['paid_amount_cents'] / 100) }}</p></div>
                            <div class="rounded-xl bg-white/70 p-3"><p class="text-gray-400">المتبقي عند الاستلام</p><p class="mt-1 text-rose-700">{{ format_money($group['remaining_amount_cents'] / 100) }}</p></div>
                        </div>
                        @if($group['payment_method'])<p class="mt-3 text-xs font-bold text-indigo-800">طريقة الدفع: {{ $group['payment_method'] }}</p>@endif
                    </div>
                </aside>
            </section>

            @if(!$group['trashed'] && $group['active_orders']->isNotEmpty())
                @can('orders.update')
                    @include('admin.orders._workflow-status-panel', ['group' => $group])
                @endcan
            @endif

            @include('admin.orders._payment-history', ['paymentEvents' => $paymentEvents ?? collect()])

            @if($visibleStoryOrders->isNotEmpty())
            <section id="order-stories" class="space-y-4" data-order-page-section="items">
                <div class="flex items-center justify-between gap-3">
                    <span class="rounded-full bg-violet-50 px-3 py-1.5 text-xs font-black text-violet-700">{{ $visibleStoryOrders->count() }} قصة نشطة</span>
                    <h3 class="text-xl font-black text-gray-900">القصص والأطفال</h3>
                </div>

                @foreach($visibleStoryOrders as $order)
                    @php
                        $storyItem = $order->items->firstWhere('item_type', 'story');
                        $linkedAddOns = $order->items->where('item_type', 'product_add_on');
                    @endphp
                    <article class="rounded-3xl border border-gray-100 bg-white p-6 shadow-sm">
                        <div class="flex flex-col gap-4 border-b border-gray-100 pb-5 lg:flex-row lg:items-start lg:justify-between">
                            <div class="text-right">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-3 py-1 text-xs font-black {{ $statusColors[$order->status] ?? 'bg-gray-100 text-gray-700' }}">{{ $statusLabels[$order->status] ?? $order->status }}</span>
                                    <a href="{{ route('admin.orders.show', $order) }}" class="font-mono text-xs font-black text-indigo-600 hover:underline" dir="ltr">#{{ $order->order_number }}</a>
                                </div>
                                <h4 class="mt-3 text-lg font-black text-gray-950">{{ $storyItem?->title ?: $order->story?->title ?: 'قصة مخصصة' }}</h4>
                                @if(data_get($storyItem?->item_snapshot, 'package.name'))
                                    <p class="mt-2 inline-flex rounded-full bg-fuchsia-50 px-3 py-1 text-xs font-black text-fuchsia-700">ضمن باقة: {{ data_get($storyItem->item_snapshot, 'package.name') }}</p>
                                @endif
                                <p class="mt-1 text-sm font-bold text-gray-600">الطفل: {{ $order->child_name ?: '—' }} · {{ $order->child_age ? $order->child_age.' سنوات' : 'العمر غير محدد' }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span class="rounded-xl bg-slate-50 px-3 py-2 text-xs font-bold text-slate-600">{{ count($order->uploaded_photos ?? []) }} صور</span>
                                <span class="rounded-xl bg-slate-50 px-3 py-2 text-xs font-bold text-slate-600">{{ $order->previews->count() }} معاينات</span>
                                <a href="{{ route('admin.orders.show', $order) }}" class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-black text-white hover:bg-indigo-700">فتح تفاصيل الإنتاج</a>
                                @if($order->productionProject && auth()->user()->hasPermission('production_studio.view'))
                                    <a href="{{ route('admin.production-studio.show', $order->productionProject) }}" class="rounded-xl bg-violet-50 px-4 py-2 text-xs font-black text-violet-700 hover:bg-violet-100">استوديو الإنتاج</a>
                                @endif
                            </div>
                        </div>

                        @if((auth()->user()->hasPermission('orders.photos.view') && count($order->uploaded_photos ?? [])) || $order->previews->isNotEmpty())
                            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                @can('orders.photos.view')
                                    @if(count($order->uploaded_photos ?? []))
                                        <div class="rounded-2xl border border-sky-100 bg-sky-50 p-4">
                                            <p class="mb-3 text-xs font-black text-sky-800">صور الطفل</p>
                                            <div class="flex flex-wrap gap-2">
                                                @foreach(array_slice($order->uploaded_photos ?? [], 0, 5) as $photo)
                                                    <a href="{{ route('admin.orders.photo', [$order, $loop->index]) }}" target="_blank" class="block h-16 w-16 overflow-hidden rounded-xl border-2 border-white bg-white shadow-sm">
                                                        <img src="{{ route('admin.orders.photo', [$order, $loop->index]) }}" alt="صورة {{ $loop->iteration }} للطفل {{ $order->child_name }}" class="h-full w-full object-cover" loading="lazy">
                                                    </a>
                                                @endforeach
                                                @if(count($order->uploaded_photos ?? []) > 5)
                                                    <a href="{{ route('admin.orders.show', $order) }}" class="flex h-16 w-16 items-center justify-center rounded-xl bg-white text-xs font-black text-sky-700">+{{ count($order->uploaded_photos) - 5 }}</a>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                @endcan

                                @if($order->previews->isNotEmpty())
                                    <div class="rounded-2xl border border-fuchsia-100 bg-fuchsia-50 p-4">
                                        <p class="mb-3 text-xs font-black text-fuchsia-800">معاينات التصميم</p>
                                        <div class="space-y-2">
                                            @foreach($order->previews->take(3) as $preview)
                                                <a href="{{ route('admin.orders.show', $order) }}" class="flex items-start justify-between gap-3 rounded-xl bg-white px-3 py-2 text-xs hover:bg-fuchsia-100">
                                                    <span class="font-black text-gray-800">تصميم #{{ $loop->iteration }}@if($preview->note) — {{ $preview->note }}@endif</span>
                                                    <span class="shrink-0 text-gray-400" dir="ltr">{{ $preview->created_at->format('d/m/Y') }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if($linkedAddOns->isNotEmpty())
                            <div class="mt-4">
                                <p class="mb-2 text-xs font-black text-amber-700">إضافات مرتبطة بهذه القصة</p>
                                <div class="grid gap-2 md:grid-cols-2">
                                    @foreach($linkedAddOns as $addOn)
                                        <div class="flex items-center justify-between rounded-xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm">
                                            <div><p class="font-black text-gray-900">{{ $addOn->title }}</p>@if($addOn->sku)<p class="text-[10px] text-gray-400" dir="ltr">{{ $addOn->sku }}</p>@endif</div>
                                            <p class="font-bold text-gray-700">{{ $addOn->quantity }} × {{ format_money($addOn->unit_price_cents / 100) }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="mt-5 grid gap-4 border-t border-gray-100 pt-5 lg:grid-cols-2">
                            @can('orders.update')
                                <form method="POST" action="{{ route('admin.orders.update', $order) }}" class="flex flex-col gap-2 sm:flex-row">
                                    @csrf
                                    @method('PATCH')
                                    <select name="status" class="flex-1 rounded-xl border-gray-200 text-right text-sm">
                                        @foreach($statuses as $status)<option value="{{ $status }}" @selected($order->status === $status)>{{ $statusLabels[$status] }}</option>@endforeach
                                    </select>
                                    <input name="admin_notes" class="flex-1 rounded-xl border-gray-200 text-right text-sm" placeholder="ملاحظة اختيارية">
                                    <button class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-black text-indigo-700 hover:bg-indigo-100">تحديث القصة</button>
                                </form>
                            @endcan
                            @can('orders.delete')
                                <details class="rounded-xl border border-red-100 bg-red-50 p-3">
                                    <summary class="cursor-pointer text-sm font-black text-red-700">حذف هذه القصة فقط</summary>
                                    <form method="POST" action="{{ route('admin.orders.destroy', $order) }}" class="mt-3 grid gap-2 sm:grid-cols-3">
                                        @csrf
                                        @method('DELETE')
                                        <input name="deletion_reason" required minlength="5" class="rounded-lg border-red-200 text-sm" placeholder="سبب الحذف">
                                        <input name="confirmation" required class="rounded-lg border-red-200 text-sm" placeholder="اكتب {{ $order->order_number }}" dir="ltr">
                                        <button class="rounded-lg bg-red-600 px-4 py-2 text-sm font-black text-white">نقل للمحذوفات</button>
                                    </form>
                                </details>
                            @endcan
                        </div>
                    </article>
                @endforeach
            </section>
            @endif

            @if($group['direct_products']->isNotEmpty())
                <section id="order-products" class="rounded-3xl border border-emerald-100 bg-white p-5 shadow-sm sm:p-6" data-order-page-section="items">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-black text-emerald-700">{{ $group['direct_products']->sum('quantity') }} منتج</span>
                        <h3 class="text-lg font-black text-gray-900">المنتجات المباشرة</h3>
                    </div>
                    <div @class([
                        'mt-4 grid gap-4',
                        'md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4' => $group['direct_products']->count() > 1,
                    ])>
                        @foreach($group['direct_products'] as $product)
                            @php
                                $productOrder = $group['active_orders']->firstWhere('id', (int) $product->order_id);
                                $productPhotos = $productOrder?->uploaded_photos ?? [];
                            @endphp
                            <div class="flex h-full flex-col rounded-2xl border border-emerald-100 bg-emerald-50 p-3">
                                @if(data_get($product->item_snapshot, 'package.name'))<p class="mb-1.5 text-[10px] font-black text-fuchsia-700">ضمن باقة: {{ data_get($product->item_snapshot, 'package.name') }}</p>@endif
                                <p class="text-sm font-black leading-6 text-gray-900">{{ $product->title }}</p>
                                @if($product->sku)<p class="mt-1 text-xs text-gray-400" dir="ltr">SKU: {{ $product->sku }}</p>@endif
                                <p class="mt-2 text-xs font-bold text-emerald-800">{{ $product->quantity }} × {{ format_money($product->unit_price_cents / 100) }}</p>
                                @if($product->personalization_mode === 'collect_child_details')
                                    <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-2 border-t border-emerald-100 pt-2">
                                        @foreach($product->personalizationDisplayValues() as $value)
                                            <div class="min-w-0">
                                                <dt class="text-[10px] font-bold text-emerald-600">{{ $value['label'] }}</dt>
                                                <dd class="break-words text-xs font-black leading-5 text-gray-900">{{ $value['value'] }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                    @can('orders.photos.view')
                                        @if($productOrder && count($productPhotos))
                                            <div class="mt-2 border-t border-emerald-100 pt-2">
                                                <p class="mb-1.5 text-[10px] font-black text-emerald-700">صور الطفل المرفقة</p>
                                                <div class="flex flex-wrap gap-1.5">
                                                    @foreach($productPhotos as $photo)
                                                        <a href="{{ route('admin.orders.photo', [$productOrder, $loop->index]) }}" target="_blank" rel="noopener" class="block h-14 w-14 overflow-hidden rounded-lg border-2 border-white bg-white shadow-sm" title="فتح الصورة بالحجم الكامل">
                                                            <img src="{{ route('admin.orders.photo', [$productOrder, $loop->index]) }}" alt="صورة {{ $loop->iteration }} للمنتج {{ $product->title }}" class="h-full w-full object-cover" loading="lazy">
                                                        </a>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    @endcan
                                @endif
                                @can('orders.production_prompt.manage')
                                    @if($productOrder && \App\Support\ProductProductionPrompt::templateForItem($product) !== null)
                                        <a
                                            href="{{ route('admin.orders.products.production', [$productOrder, $product]) }}"
                                            class="mt-auto inline-flex min-h-10 w-full items-center justify-center gap-1.5 rounded-xl bg-fuchsia-600 px-3 py-2 text-xs font-black text-white transition hover:bg-fuchsia-700 focus:outline-none focus:ring-2 focus:ring-fuchsia-500 focus:ring-offset-2"
                                        >
                                            <span aria-hidden="true">✨</span>
                                            فتح صفحة إنتاج الاستيكر
                                        </a>
                                    @endif
                                @endcan
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @can('orders.production_prompt.manage')
                @include('admin.orders._product-production-prompts', ['collapsed' => true])
            @endcan

            <section id="order-follow-up" class="space-y-4" data-order-page-section="follow-up">
                <div class="flex flex-wrap items-center justify-between gap-3 px-1">
                    <div class="flex flex-wrap gap-2 text-xs font-black">
                        <span class="rounded-full bg-amber-50 px-3 py-1.5 text-amber-800">{{ $adminNotesCount }} ملاحظة</span>
                        <span class="rounded-full bg-sky-50 px-3 py-1.5 text-sky-700">{{ $attachmentsCount }} مرفق</span>
                    </div>
                    <div class="text-right">
                        <h3 class="text-xl font-black text-gray-900">المتابعة والملفات</h3>
                        <p class="mt-1 text-xs font-bold text-gray-500">الملاحظات الداخلية والمرفقات محفوظة مع الطلب دون تعطيل شاشة التنفيذ.</p>
                    </div>
                </div>

                @include('admin.orders._admin-notes', [
                    'noteTargetOrder' => $attachmentTarget,
                    'orderAdminNotes' => $orderAdminNotes ?? collect(),
                    'collapsible' => true,
                    'openWhenPresent' => true,
                ])

                @include('admin.orders._attachments', [
                    'attachmentTarget' => $attachmentTarget,
                    'attachmentOrders' => $attachmentOrders,
                    'collapsible' => true,
                    'openByDefault' => true,
                ])
            </section>

            @if($deletedStoryOrders->isNotEmpty())
                <section class="rounded-3xl border border-red-100 bg-red-50 p-6">
                    <h3 class="text-lg font-black text-red-900">قصص محذوفة من هذه العملية</h3>
                    <div class="mt-4 space-y-3">
                        @foreach($deletedStoryOrders as $order)
                            <div class="flex flex-col gap-3 rounded-2xl bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
                                <div><p class="font-black text-gray-900">{{ $order->story?->title ?: $order->order_number }}</p><p class="mt-1 text-xs text-gray-500">{{ $order->child_name }} · {{ $order->deletion_reason }}</p></div>
                                @can('orders.delete')
                                    <form method="POST" action="{{ route('admin.orders.restore', $order->id) }}" onsubmit="return confirm('استعادة هذه القصة وحجز مخزون إضافاتها مرة أخرى؟')">
                                        @csrf
                                        <button class="rounded-xl bg-green-600 px-4 py-2 text-sm font-black text-white hover:bg-green-700">استعادة القصة</button>
                                    </form>
                                @endcan
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @can('orders.delete')
                @if(!$group['trashed'])
                    <details class="rounded-3xl border border-red-200 bg-red-50 p-6">
                        <summary class="cursor-pointer text-lg font-black text-red-800">حذف عملية الشراء كاملة</summary>
                        <p class="mt-2 text-sm leading-6 text-red-700">سيتم نقل كل القصص والإضافات والمنتجات إلى سلة المحذوفات، وإرجاع المخزون، وإلغاء أي إنتاج نشط مع الاحتفاظ بالملفات.</p>
                        <form method="POST" action="{{ route('admin.orders.groups.destroy', $group['representative_id']) }}" class="mt-4 grid gap-3 md:grid-cols-3">
                            @csrf
                            @method('DELETE')
                            <textarea name="deletion_reason" required minlength="5" rows="2" class="rounded-xl border-red-200 text-sm" placeholder="سبب الحذف"></textarea>
                            <input name="confirmation" required class="rounded-xl border-red-200 text-sm" placeholder="اكتب {{ $group['short_reference'] ?: $group['key'] }}" dir="ltr">
                            <button class="rounded-xl bg-red-600 px-5 py-3 text-sm font-black text-white hover:bg-red-700">نقل العملية للمحذوفات</button>
                        </form>
                    </details>
                @endif
            @endcan
        </div>
    </div>

</x-admin-layout>
