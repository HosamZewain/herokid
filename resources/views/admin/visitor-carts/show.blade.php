@component('admin.layouts.admin')
    @slot('header')
        <div class="flex items-center justify-between gap-4">
            <a href="{{ route('admin.visitor-carts.index') }}" class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-black text-gray-600 hover:bg-gray-50">رجوع</a>
            <div class="text-right">
                <h2 class="text-xl font-bold text-gray-800">تفاصيل سلة زائر</h2>
                <p class="text-xs text-gray-500 dir-ltr">{{ $cart->cart_identifier }}</p>
            </div>
        </div>
    @endslot

    @php
        $money = fn ($value): string => number_format((float) $value, 0).' ج.م';
        $statusLabels = [
            'active' => ['label' => 'نشطة', 'class' => 'bg-emerald-50 text-emerald-700'],
            'abandoned' => ['label' => 'متروكة', 'class' => 'bg-amber-50 text-amber-700'],
            'converted' => ['label' => 'تحولت لطلب', 'class' => 'bg-indigo-50 text-indigo-700'],
            'expired' => ['label' => 'منتهية', 'class' => 'bg-gray-100 text-gray-600'],
        ];
        $activityLabels = [
            'item_added' => 'إضافة عنصر',
            'quantity_updated' => 'تحديث كمية',
            'item_removed' => 'حذف عنصر',
            'checkout_started' => 'بدء checkout',
            'order_completed' => 'طلب مكتمل',
        ];
        $mask = function (?string $value): string {
            if (blank($value)) {
                return '-';
            }
            $value = (string) $value;
            if (str_contains($value, '@')) {
                [$name, $domain] = array_pad(explode('@', $value, 2), 2, '');
                return mb_substr($name, 0, 2).'***@'.$domain;
            }
            return mb_substr($value, 0, 3).'***'.mb_substr($value, -2);
        };
        $status = $statusLabels[$cart->status] ?? ['label' => $cart->status, 'class' => 'bg-gray-100 text-gray-600'];
    @endphp

    <div class="mx-auto max-w-6xl space-y-6">
        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-2xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                <p class="text-xs font-bold text-gray-400">الحالة</p>
                <span class="mt-2 inline-flex rounded-full px-3 py-1 text-xs font-black {{ $status['class'] }}">{{ $status['label'] }}</span>
            </div>
            <div class="rounded-2xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                <p class="text-xs font-bold text-gray-400">العميل</p>
                <p class="mt-2 text-lg font-black text-gray-900">{{ $cart->display_name }}</p>
                @if($cart->user)
                    <p class="mt-1 text-xs text-gray-500 dir-ltr">{{ $canViewCustomers ? ($cart->user->phone ?: $cart->user->email) : $mask($cart->user->phone ?: $cart->user->email) }}</p>
                @else
                    <p class="mt-1 text-xs font-bold text-gray-400">زائر غير مسجل</p>
                @endif
            </div>
            <div class="rounded-2xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                <p class="text-xs font-bold text-gray-400">إجمالي السلة</p>
                <p class="mt-2 text-2xl font-black text-gray-950">{{ $money($cart->total) }}</p>
            </div>
            <div class="rounded-2xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                <p class="text-xs font-bold text-gray-400">الطلب المرتبط</p>
                @if($cart->relatedOrder)
                    <a href="{{ route('admin.orders.show', $cart->relatedOrder) }}" class="mt-2 block text-lg font-black text-indigo-600 hover:underline">{{ $cart->relatedOrder->order_number }}</a>
                @else
                    <p class="mt-2 text-lg font-black text-gray-400">-</p>
                @endif
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-3xl border border-gray-100 bg-white p-6 text-right shadow-sm lg:col-span-2">
                <h3 class="text-lg font-black text-gray-900">عناصر السلة</h3>
                <div class="mt-4 space-y-3">
                    @forelse($cart->items as $item)
                        <div class="rounded-2xl border border-gray-100 bg-gray-50 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-black text-gray-900">{{ $item->title_snapshot }}</p>
                                    <p class="mt-1 text-xs text-gray-500">
                                        {{ $item->item_type }} · الكمية {{ $item->quantity }}
                                        @if($item->removed_at)
                                            · <span class="font-bold text-red-600">محذوف</span>
                                        @endif
                                    </p>
                                </div>
                                <p class="font-black text-indigo-700">{{ $money($item->total) }}</p>
                            </div>
                            <div class="mt-3 grid gap-2 text-xs text-gray-500 md:grid-cols-3">
                                <div>أول إضافة: {{ app_datetime($item->first_added_at, 'Y-m-d H:i', '-') ?? '-' }}</div>
                                <div>آخر نشاط: {{ app_datetime($item->last_activity_at, 'Y-m-d H:i', '-') ?? '-' }}</div>
                                <div>مرتبط بسلة: {{ $item->linked_cart_item_key ?: '-' }}</div>
                            </div>
                        </div>
                    @empty
                        <p class="rounded-2xl bg-gray-50 p-8 text-center text-sm font-bold text-gray-400">لا توجد عناصر محفوظة لهذه السلة.</p>
                    @endforelse
                </div>
            </section>

            <aside class="space-y-6">
                <section class="rounded-3xl border border-gray-100 bg-white p-6 text-right shadow-sm">
                    <h3 class="text-lg font-black text-gray-900">مصدر الزيارة</h3>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-gray-400">Source</dt><dd class="font-bold text-gray-900">{{ $cart->utm_source ?: 'Direct' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-400">Medium</dt><dd class="font-bold text-gray-900">{{ $cart->utm_medium ?: '-' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-400">Campaign</dt><dd class="font-bold text-gray-900">{{ $cart->utm_campaign ?: '-' }}</dd></div>
                    </dl>
                </section>

                <section class="rounded-3xl border border-gray-100 bg-white p-6 text-right shadow-sm">
                    <h3 class="text-lg font-black text-gray-900">التوقيت</h3>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-gray-400">أول إضافة</dt><dd class="font-bold text-gray-900">{{ app_datetime($cart->first_added_at, 'Y-m-d H:i', '-') ?? '-' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-400">آخر نشاط</dt><dd class="font-bold text-gray-900">{{ app_datetime($cart->last_activity_at, 'Y-m-d H:i', '-') ?? '-' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-400">بدأ checkout</dt><dd class="font-bold text-gray-900">{{ app_datetime($cart->checkout_started_at, 'Y-m-d H:i', '-') ?? '-' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-400">تحول لطلب</dt><dd class="font-bold text-gray-900">{{ app_datetime($cart->converted_at, 'Y-m-d H:i', '-') ?? '-' }}</dd></div>
                    </dl>
                </section>
            </aside>
        </div>

        <section class="rounded-3xl border border-gray-100 bg-white p-6 text-right shadow-sm">
            <h3 class="text-lg font-black text-gray-900">سجل نشاط السلة</h3>
            <div class="mt-5 space-y-4">
                @forelse($cart->activities as $activity)
                    <div class="flex gap-4 rounded-2xl bg-gray-50 p-4">
                        <div class="mt-1 h-3 w-3 shrink-0 rounded-full bg-indigo-500"></div>
                        <div class="flex-1">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <p class="font-black text-gray-900">{{ $activityLabels[$activity->type] ?? $activity->type }}</p>
                                <p class="text-xs font-bold text-gray-400">{{ app_datetime($activity->created_at, 'Y-m-d H:i') }}</p>
                            </div>
                            <p class="mt-1 text-sm text-gray-600">{{ $activity->description }}</p>
                            @if($activity->item)
                                <p class="mt-1 text-xs text-gray-400">العنصر: {{ $activity->item->title_snapshot }}</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="rounded-2xl bg-gray-50 p-8 text-center text-sm font-bold text-gray-400">لا يوجد نشاط مسجل بعد.</p>
                @endforelse
            </div>
        </section>
    </div>
@endcomponent
