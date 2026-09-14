@php
    $invoiceReference = $invoiceGroup['short_reference'] ?: $invoiceGroup['key'];
    $delivery = $invoiceGroup['delivery'] ?? [];
    $customerLocation = collect([
        data_get($delivery, 'country'),
        data_get($delivery, 'governorate'),
        data_get($delivery, 'city'),
        data_get($delivery, 'street'),
    ])->filter()->implode('، ');
    $customerAddress = data_get($delivery, 'address_details', data_get($delivery, 'address'));
    $brandAddress = collect([setting('address_city'), setting('address_street')])->filter()->implode('، ');
    $brandHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'hero-kid.com';
    $invoiceData = [
        'brand' => setting('site_name', 'HeroKid'),
        'brand_host' => $brandHost,
        'brand_email' => setting('site_email'),
        'brand_phone' => setting('whatsapp_number'),
        'brand_address' => $brandAddress,
        'reference' => $invoiceReference,
        'checkout_reference' => $invoiceGroup['key'],
        'order_numbers' => implode(' • ', $invoiceGroup['order_numbers']),
        'date' => app_datetime($invoiceGroup['created_at'], 'd/m/Y h:i A'),
        'customer' => $invoiceGroup['customer_name'],
        'phone' => $invoiceGroup['phone'] ?: '—',
        'location' => $customerLocation ?: '—',
        'address' => $customerAddress ?: '—',
        'items' => $invoiceItems->map(fn (array $item): array => [
            'title' => $item['title'],
            'type' => $item['type'],
            'quantity' => $item['quantity'],
            'unit_price' => format_money($item['unit_price_cents'] / 100),
            'line_total' => format_money($item['line_total_cents'] / 100),
        ])->all(),
        'items_total' => format_money($invoiceGroup['items_cents'] / 100),
        'delivery_total' => format_money($invoiceGroup['delivery_cents'] / 100),
        'discount_total' => format_money($invoiceGroup['discount_cents'] / 100),
        'discount_cents' => (int) $invoiceGroup['discount_cents'],
        'grand_total' => format_money($invoiceGroup['total_cents'] / 100),
        'paid_total' => format_money($invoiceGroup['paid_amount_cents'] / 100),
        'due_total' => format_money($invoiceGroup['remaining_amount_cents'] / 100),
        'due_cents' => (int) $invoiceGroup['remaining_amount_cents'],
        'payment_status' => $invoiceGroup['payment_status_label'],
        'payment_method' => $invoiceGroup['payment_method'] ?: '—',
        'file_name' => 'HeroKid-invoice-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $invoiceReference).'.png',
    ];
@endphp

<div data-order-invoice>
    <button type="button" class="rounded-lg border border-indigo-200 bg-white px-2.5 py-1.5 text-[10px] font-black text-indigo-700 transition hover:bg-indigo-100 disabled:cursor-wait disabled:opacity-60" data-order-invoice-open>
        فاتورة
    </button>

    <div class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/70 p-3 backdrop-blur-sm sm:p-6" data-order-invoice-modal role="dialog" aria-modal="true" aria-labelledby="order-invoice-title">
        <button type="button" class="absolute inset-0 h-full w-full cursor-default" data-order-invoice-close aria-label="إغلاق معاينة الفاتورة"></button>
        <section class="relative z-10 flex max-h-[96vh] w-full max-w-3xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
            <header class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 sm:px-5">
                <div class="text-right">
                    <h4 id="order-invoice-title" class="font-black text-gray-950">فاتورة الطلب {{ $invoiceReference }}</h4>
                    <p class="mt-0.5 text-[10px] font-bold text-gray-400">A6 عمودي — صورة PNG</p>
                </div>
                <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-gray-100 text-lg font-black text-gray-600 hover:bg-red-50 hover:text-red-600" data-order-invoice-close aria-label="إغلاق">×</button>
            </header>
            <div class="min-h-0 flex-1 overflow-auto bg-slate-100 p-3 text-center sm:p-5">
                <p class="py-16 text-sm font-black text-indigo-700" data-order-invoice-status>جاري تجهيز الفاتورة…</p>
                <img class="mx-auto hidden h-auto max-h-[70vh] max-w-full rounded-lg bg-white shadow-lg" data-order-invoice-preview alt="معاينة فاتورة الطلب {{ $invoiceReference }}">
            </div>
            <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 px-4 py-3 sm:px-5">
                <button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-xs font-black text-gray-600 hover:bg-gray-50" data-order-invoice-close>إغلاق</button>
                <a class="hidden rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-xs font-black text-indigo-700 hover:bg-indigo-100" target="_blank" rel="noopener" data-order-invoice-new-tab>فتح الصورة</a>
                <a class="hidden rounded-xl bg-indigo-600 px-4 py-2 text-xs font-black text-white hover:bg-indigo-700" data-order-invoice-download>تحميل PNG</a>
            </footer>
        </section>
    </div>

    <script type="application/json" data-order-invoice-data>{!! json_encode($invoiceData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
</div>
