<section {{ $attributes->class('overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm sm:rounded-3xl') }}>
    <div class="bg-slate-950 p-4 text-right text-white sm:p-6">
        <p class="text-xs font-bold text-indigo-200 sm:text-sm">ملخص الطلب قبل إدخال العنوان</p>
        <p class="mt-1 text-2xl font-black sm:mt-2 sm:text-3xl">
            <span data-cart-total>{{ arabic_number(number_format($total, 0)) }}</span>
            {{ setting('currency_label', $settings['currency_label'] ?? '') }}
        </p>
        <p class="mt-1 hidden text-sm text-slate-300 sm:block">السعر كامل ظاهر الآن، ولا يوجد دفع في هذه الخطوة.</p>
    </div>
    <div class="grid grid-cols-3 gap-2 p-3 text-sm sm:gap-3 sm:p-6">
        <div class="rounded-xl bg-slate-50 p-2 text-right sm:rounded-2xl sm:p-4">
            <span class="mb-1 block text-[11px] leading-4 text-slate-500 sm:text-sm">سعر المنتجات</span>
            <span class="text-xs font-black text-slate-950 sm:text-sm" data-cart-subtotal>{{ format_money($subtotal) }}</span>
        </div>
        <div class="rounded-xl bg-slate-50 p-2 text-right sm:rounded-2xl sm:p-4">
            <span class="mb-1 block text-[11px] leading-4 text-slate-500 sm:text-sm">مصاريف التوصيل</span>
            <span class="text-xs font-black text-slate-950 sm:text-sm">
                <span data-delivery-fee>{{ arabic_number(number_format($deliveryFee, 0)) }}</span>
                {{ setting('currency_label', $settings['currency_label'] ?? '') }}
            </span>
        </div>
        <div class="rounded-xl bg-indigo-50 p-2 text-right sm:rounded-2xl sm:p-4">
            <span class="mb-1 block text-[11px] font-bold leading-4 text-indigo-500 sm:text-sm">الإجمالي</span>
            <span class="text-sm font-black text-indigo-700 sm:text-xl">
                <span data-cart-total>{{ arabic_number(number_format($total, 0)) }}</span>
                {{ setting('currency_label', $settings['currency_label'] ?? '') }}
            </span>
        </div>
    </div>
</section>
