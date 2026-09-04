@component('admin.layouts.admin')
    @slot('header')
        <div class="flex flex-col gap-2 text-right">
            <h2 class="text-xl font-bold text-gray-800">سلات الزوار</h2>
            <p class="text-xs text-gray-500">رؤية محلية من قاعدة بيانات HeroKid فقط، بدون إرسال بيانات السلة لأي خدمة خارجية.</p>
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
    @endphp

    <div class="mx-auto max-w-7xl space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-2xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                <p class="text-sm font-bold text-gray-500">السلات النشطة</p>
                <p class="mt-2 text-3xl font-black text-emerald-700">{{ number_format($summary['active']) }}</p>
            </div>
            <div class="rounded-2xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                <p class="text-sm font-bold text-gray-500">السلات المتروكة</p>
                <p class="mt-2 text-3xl font-black text-amber-700">{{ number_format($summary['abandoned']) }}</p>
            </div>
            <div class="rounded-2xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                <p class="text-sm font-bold text-gray-500">تحولت لطلبات</p>
                <p class="mt-2 text-3xl font-black text-indigo-700">{{ number_format($summary['converted']) }}</p>
            </div>
            <div class="rounded-2xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                <p class="text-sm font-bold text-gray-500">قيمة السلات المتروكة</p>
                <p class="mt-2 text-3xl font-black text-gray-950">{{ $money($summary['abandoned_value']) }}</p>
            </div>
            <div class="rounded-2xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                <p class="text-sm font-bold text-gray-500">معدل التحويل المحلي</p>
                <p class="mt-2 text-3xl font-black text-gray-950">{{ $summary['conversion_rate'] === null ? '—' : number_format($summary['conversion_rate'], 2).'%' }}</p>
            </div>
        </div>

        <div class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm">
            <form method="GET" action="{{ route('admin.visitor-carts.index') }}" class="grid gap-3 text-right lg:grid-cols-7">
                <div>
                    <label class="mb-2 block text-xs font-bold text-gray-500">بحث</label>
                    <input name="q" value="{{ request('q') }}" class="w-full rounded-xl border-gray-200 text-right" placeholder="عميل، رقم طلب، أو كود السلة">
                </div>
                <div>
                    <label class="mb-2 block text-xs font-bold text-gray-500">الحالة</label>
                    <select name="status" class="w-full rounded-xl border-gray-200 text-right">
                        <option value="">الكل</option>
                        @foreach($statusLabels as $key => $meta)
                            <option value="{{ $key }}" @selected(request('status') === $key)>{{ $meta['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-xs font-bold text-gray-500">نوع العميل</label>
                    <select name="customer_type" class="w-full rounded-xl border-gray-200 text-right">
                        <option value="">الكل</option>
                        <option value="guest" @selected(request('customer_type') === 'guest')>زائر غير مسجل</option>
                        <option value="known" @selected(request('customer_type') === 'known')>عميل معروف</option>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-xs font-bold text-gray-500">القصة</label>
                    <select name="story_id" class="w-full rounded-xl border-gray-200 text-right">
                        <option value="">كل القصص</option>
                        @foreach($stories as $story)
                            <option value="{{ $story->id }}" @selected((string) request('story_id') === (string) $story->id)>{{ $story->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-xs font-bold text-gray-500">من</label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}" class="w-full rounded-xl border-gray-200">
                </div>
                <div>
                    <label class="mb-2 block text-xs font-bold text-gray-500">إلى</label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}" class="w-full rounded-xl border-gray-200">
                </div>
                <div class="flex items-end gap-2">
                    <button class="w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-black text-white hover:bg-indigo-700">تصفية</button>
                    <a href="{{ route('admin.visitor-carts.index') }}" class="rounded-xl bg-gray-100 px-4 py-3 text-sm font-black text-gray-700 hover:bg-gray-200">مسح</a>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-100 text-right">
                <thead class="bg-gray-50 text-xs font-black text-gray-500">
                    <tr>
                        <th class="px-4 py-3">العميل</th>
                        <th class="px-4 py-3">الحالة</th>
                        <th class="px-4 py-3">العناصر</th>
                        <th class="px-4 py-3">الإجمالي</th>
                        <th class="px-4 py-3">أول إضافة</th>
                        <th class="px-4 py-3">آخر نشاط</th>
                        <th class="px-4 py-3">المصدر</th>
                        <th class="px-4 py-3">الطلب</th>
                        <th class="px-4 py-3">إجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm">
                    @forelse($carts as $cart)
                        @php($status = $statusLabels[$cart->status] ?? ['label' => $cart->status, 'class' => 'bg-gray-100 text-gray-600'])
                        <tr class="align-top hover:bg-gray-50">
                            <td class="px-4 py-4">
                                <div class="font-black text-gray-900">{{ $cart->display_name }}</div>
                                @if($cart->user)
                                    <div class="mt-1 text-xs text-gray-400 dir-ltr">{{ $canViewCustomers ? ($cart->user->phone ?: $cart->user->email) : $mask($cart->user->phone ?: $cart->user->email) }}</div>
                                @else
                                    <div class="mt-1 text-xs font-bold text-gray-400">زائر غير مسجل</div>
                                @endif
                            </td>
                            <td class="px-4 py-4">
                                <span class="rounded-full px-3 py-1 text-xs font-black {{ $status['class'] }}">{{ $status['label'] }}</span>
                            </td>
                            <td class="px-4 py-4">
                                <div class="font-bold text-gray-900">{{ $cart->active_items_count }} عنصر</div>
                                <div class="mt-1 max-w-xs text-xs text-gray-500">
                                    {{ $cart->activeItems->pluck('title_snapshot')->take(3)->implode('، ') ?: '-' }}
                                </div>
                            </td>
                            <td class="px-4 py-4 font-black text-gray-900">{{ $money($cart->total) }}</td>
                            <td class="px-4 py-4 text-gray-500">{{ app_datetime($cart->first_added_at, 'Y-m-d H:i', '-') ?? '-' }}</td>
                            <td class="px-4 py-4 text-gray-500">{{ app_datetime_human($cart->last_activity_at, '-') ?? '-' }}</td>
                            <td class="px-4 py-4 text-xs text-gray-500">{{ trim(($cart->utm_source ?: '').' / '.($cart->utm_medium ?: ''), ' /') ?: 'Direct' }}</td>
                            <td class="px-4 py-4">
                                @if($cart->relatedOrder)
                                    <a href="{{ route('admin.orders.groups.show', $cart->relatedOrder) }}" class="font-black text-indigo-600 hover:underline">{{ $cart->relatedOrder->checkoutReference?->short_reference ?: $cart->relatedOrder->order_number }}</a>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-4">
                                <a href="{{ route('admin.visitor-carts.show', $cart) }}" class="rounded-xl bg-indigo-50 px-4 py-2 text-xs font-black text-indigo-700 hover:bg-indigo-100">تفاصيل</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center text-sm font-bold text-gray-400">لا توجد سلات مطابقة للفلاتر الحالية.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            @if($carts->hasPages())
                <div class="border-t border-gray-100 p-4">{{ $carts->links() }}</div>
            @endif
        </div>
    </div>
@endcomponent
