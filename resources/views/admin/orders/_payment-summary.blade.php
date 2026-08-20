@php
    $summaryStoryItems = $checkoutGroup['story_orders']->map(function ($storyOrder) {
        $item = $storyOrder->items->firstWhere('item_type', 'story');
        $lineTotalCents = (int) ($item?->total_price_cents
            ?? round((float) data_get($storyOrder->delivery_details, 'item_price', $storyOrder->story?->price ?? 0) * 100));

        return [
            'type' => 'قصة مخصصة',
            'title' => $item?->title ?: $storyOrder->story?->title ?: 'قصة مخصصة',
            'details' => $storyOrder->child_name ? 'للطفل '.$storyOrder->child_name : null,
            'quantity' => max(1, (int) ($item?->quantity ?? 1)),
            'total_cents' => $lineTotalCents,
        ];
    });
    $summaryProductItems = $checkoutGroup['direct_products']->map(fn ($item) => [
        'type' => 'منتج',
        'title' => $item->title,
        'details' => data_get($item->variant_snapshot, 'name_ar'),
        'quantity' => max(1, (int) $item->quantity),
        'total_cents' => (int) $item->total_price_cents,
    ]);
    $summaryAddOnItems = $checkoutGroup['add_ons']->map(fn ($item) => [
        'type' => 'إضافة',
        'title' => $item->title,
        'details' => null,
        'quantity' => max(1, (int) $item->quantity),
        'total_cents' => (int) $item->total_price_cents,
    ]);
    $paymentSummaryItems = $summaryStoryItems
        ->concat($summaryProductItems)
        ->concat($summaryAddOnItems)
        ->values();
    $delivery = $checkoutGroup['delivery'] ?? [];
    $location = collect([
        data_get($delivery, 'country'),
        data_get($delivery, 'governorate'),
        data_get($delivery, 'city'),
        data_get($delivery, 'street'),
    ])->filter()->implode('، ');
    $addressDetails = data_get($delivery, 'address_details', data_get($delivery, 'address'));
    $amountDueCents = (int) $checkoutGroup['remaining_amount_cents'];
    $summaryImageData = [
        'reference' => $checkoutGroup['key'],
        'order_numbers' => implode(' • ', $checkoutGroup['order_numbers']),
        'date' => optional($checkoutGroup['created_at'])->format('d/m/Y h:i A'),
        'customer' => $checkoutGroup['customer_name'],
        'phone' => $checkoutGroup['phone'] ?: '—',
        'location' => $location ?: '—',
        'address' => $addressDetails ?: '—',
        'items' => $paymentSummaryItems->map(fn (array $item) => [
            ...$item,
            'total' => format_money($item['total_cents'] / 100),
        ])->all(),
        'items_total' => format_money($checkoutGroup['items_cents'] / 100),
        'delivery' => format_money($checkoutGroup['delivery_cents'] / 100),
        'discount' => format_money($checkoutGroup['discount_cents'] / 100),
        'discount_cents' => (int) $checkoutGroup['discount_cents'],
        'total' => format_money($checkoutGroup['total_cents'] / 100),
        'paid' => format_money($checkoutGroup['paid_amount_cents'] / 100),
        'due' => format_money($amountDueCents / 100),
        'due_cents' => $amountDueCents,
        'payment_status' => $checkoutGroup['payment_status_label'],
        'file_name' => 'HeroKid-order-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $checkoutGroup['key']).'.png',
    ];
@endphp

<section class="overflow-hidden rounded-3xl border border-indigo-100 bg-white shadow-sm" data-order-payment-summary>
    <div class="flex flex-col gap-4 border-b border-indigo-100 bg-gradient-to-l from-indigo-50 via-white to-violet-50 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div class="text-right">
            <p class="text-xs font-black text-indigo-600">جاهز للإرسال بعد المعاينة</p>
            <h3 class="mt-1 text-xl font-black text-slate-950">ملخص الطلب والدفع</h3>
            <p class="mt-1 text-sm font-bold text-slate-500">كل عناصر عملية الشراء والمبلغ المطلوب من العميل في بطاقة واحدة.</p>
        </div>
        <div class="flex flex-col items-stretch gap-2 sm:items-end">
            <button type="button"
                class="min-h-11 rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200 disabled:cursor-wait disabled:opacity-60"
                data-order-payment-summary-download>
                تنزيل الملخص كصورة
            </button>
            <p class="min-h-5 text-center text-xs font-bold text-slate-500 sm:text-left" aria-live="polite" data-order-payment-summary-status></p>
        </div>
    </div>

    <div class="grid gap-0 lg:grid-cols-4" data-order-payment-summary-card>
        <div class="space-y-5 p-5 sm:p-6 lg:col-span-3">
            <div class="grid gap-4 border-b border-slate-100 pb-5 text-right sm:grid-cols-2">
                <div>
                    <p class="text-xs font-bold text-slate-400">العميل</p>
                    <p class="mt-1 font-black text-slate-950">{{ $checkoutGroup['customer_name'] }}</p>
                </div>
                <div>
                    <p class="text-xs font-bold text-slate-400">الهاتف / واتساب</p>
                    <p class="mt-1 font-black text-slate-950" dir="ltr">{{ $checkoutGroup['phone'] ?: '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-bold text-slate-400">العنوان</p>
                    <p class="mt-1 text-sm font-bold leading-6 text-slate-800">{{ $location ?: '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-bold text-slate-400">تفاصيل التوصيل</p>
                    <p class="mt-1 text-sm font-bold leading-6 text-slate-800">{{ $addressDetails ?: '—' }}</p>
                </div>
            </div>

            <div>
                <div class="mb-3 flex items-center justify-between gap-3">
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-600">{{ arabic_number($paymentSummaryItems->count()) }} عنصر</span>
                    <h4 class="font-black text-slate-950">محتويات الطلب</h4>
                </div>
                <div class="divide-y divide-slate-100 rounded-2xl border border-slate-100 bg-slate-50/70">
                    @forelse($paymentSummaryItems as $summaryItem)
                        <div class="flex items-start justify-between gap-4 px-4 py-3 text-right">
                            <div class="min-w-0">
                                <p class="text-[11px] font-black text-indigo-600">{{ $summaryItem['type'] }}</p>
                                <p class="mt-0.5 font-black text-slate-900">{{ $summaryItem['title'] }}</p>
                                @if($summaryItem['details'])
                                    <p class="mt-0.5 text-xs font-bold text-slate-500">{{ $summaryItem['details'] }}</p>
                                @endif
                            </div>
                            <div class="shrink-0 text-left">
                                <p class="text-xs font-bold text-slate-400">{{ arabic_number($summaryItem['quantity']) }} ×</p>
                                <p class="mt-1 font-black text-slate-900">{{ format_money($summaryItem['total_cents'] / 100) }}</p>
                            </div>
                        </div>
                    @empty
                        <p class="px-4 py-6 text-center text-sm font-bold text-slate-400">لا توجد عناصر نشطة في هذه العملية.</p>
                    @endforelse
                </div>
            </div>

            <div class="flex flex-col gap-1 border-t border-slate-100 pt-4 text-xs font-bold text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                <span dir="ltr">{{ $checkoutGroup['key'] }}</span>
                <span>{{ optional($checkoutGroup['created_at'])->format('d/m/Y — h:i A') }}</span>
            </div>
        </div>

        <aside class="border-t border-indigo-100 bg-indigo-50 p-5 text-right sm:p-6 lg:border-r lg:border-t-0">
            <h4 class="font-black text-indigo-950">ملخص القيمة</h4>
            <div class="mt-5 space-y-3 text-sm font-bold text-indigo-950">
                <div class="flex items-center justify-between gap-3"><span>العناصر</span><span>{{ format_money($checkoutGroup['items_cents'] / 100) }}</span></div>
                <div class="flex items-center justify-between gap-3"><span>التوصيل</span><span>{{ format_money($checkoutGroup['delivery_cents'] / 100) }}</span></div>
                @if($checkoutGroup['discount_cents'] > 0)
                    <div class="flex items-center justify-between gap-3 text-rose-700"><span>الخصم</span><span>- {{ format_money($checkoutGroup['discount_cents'] / 100) }}</span></div>
                @endif
                <div class="flex items-center justify-between gap-3 border-t border-indigo-200 pt-3 text-lg font-black"><span>الإجمالي</span><span>{{ format_money($checkoutGroup['total_cents'] / 100) }}</span></div>
                @if($checkoutGroup['paid_amount_cents'] > 0)
                    <div class="flex items-center justify-between gap-3 text-emerald-700"><span>تم دفع</span><span>{{ format_money($checkoutGroup['paid_amount_cents'] / 100) }}</span></div>
                @endif
            </div>

            <div class="mt-6 rounded-2xl bg-white p-4 shadow-sm">
                <p class="text-xs font-black text-slate-500">{{ $amountDueCents > 0 ? 'المبلغ المطلوب للدفع' : 'حالة السداد' }}</p>
                <p class="mt-2 text-3xl font-black {{ $amountDueCents > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                    {{ $amountDueCents > 0 ? format_money($amountDueCents / 100) : 'مدفوع بالكامل' }}
                </p>
                <p class="mt-3 inline-flex rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">{{ $checkoutGroup['payment_status_label'] }}</p>
            </div>
        </aside>
    </div>

    <script type="application/json" data-order-payment-summary-data>{!! json_encode($summaryImageData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
</section>
