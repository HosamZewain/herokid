<section {{ $attributes->class('overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-sm') }}>
    <div class="bg-slate-950 p-5 text-right text-white sm:p-6">
        <p class="text-sm font-bold text-indigo-200">ملخص الطلب قبل إدخال العنوان</p>
        <p class="mt-2 text-3xl font-black">
            <span data-cart-total>{{ arabic_number(number_format($total, 0)) }}</span>
            {{ setting('currency_label', $settings['currency_label'] ?? '') }}
        </p>
        <p class="mt-1 text-sm text-slate-300">السعر كامل ظاهر الآن، ولا يوجد دفع في هذه الخطوة.</p>
    </div>
    <div class="grid grid-cols-1 gap-3 p-4 text-sm sm:grid-cols-3 sm:p-6">
        <div class="rounded-2xl bg-slate-50 p-4 text-right">
            <span class="mb-1 block text-slate-500">سعر المنتجات</span>
            <span class="font-black text-slate-950">{{ format_money($subtotal) }}</span>
        </div>
        <div class="rounded-2xl bg-slate-50 p-4 text-right">
            <span class="mb-1 block text-slate-500">مصاريف التوصيل</span>
            <span class="font-black text-slate-950">
                <span data-delivery-fee>{{ arabic_number(number_format($deliveryFee, 0)) }}</span>
                {{ setting('currency_label', $settings['currency_label'] ?? '') }}
            </span>
        </div>
        <div class="rounded-2xl bg-indigo-50 p-4 text-right">
            <span class="mb-1 block font-bold text-indigo-500">الإجمالي</span>
            <span class="text-xl font-black text-indigo-700">
                <span data-cart-total>{{ arabic_number(number_format($total, 0)) }}</span>
                {{ setting('currency_label', $settings['currency_label'] ?? '') }}
            </span>
        </div>
    </div>
</section>
