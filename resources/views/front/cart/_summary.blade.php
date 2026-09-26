<section {{ $attributes->class('overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm sm:rounded-3xl') }}>
    <div class="bg-slate-950 p-4 text-right text-white sm:p-6">
        <p class="text-xs font-bold text-indigo-200 sm:text-sm">ملخص الطلب قبل إدخال العنوان</p>
        <p class="mt-1 text-2xl font-black sm:mt-2 sm:text-3xl">
            <span data-cart-total>{{ arabic_number(number_format($total, 0)) }}</span>
            {{ setting('currency_label', $settings['currency_label'] ?? '') }}
        </p>
        <p class="mt-1 hidden text-sm text-slate-300 sm:block">السعر كامل ظاهر الآن، ولا يوجد دفع في هذه الخطوة.</p>
    </div>
    <div class="grid {{ $discount > 0 ? 'grid-cols-2 sm:grid-cols-4' : 'grid-cols-3' }} gap-2 p-3 text-sm sm:gap-3 sm:p-6">
        <div class="rounded-xl bg-slate-50 p-2 text-right sm:rounded-2xl sm:p-4">
            <span class="mb-1 block text-[11px] leading-4 text-slate-500 sm:text-sm">سعر المنتجات</span>
            <span class="text-xs font-black text-slate-950 sm:text-sm" data-cart-subtotal>{{ format_money($subtotal) }}</span>
        </div>
        @if($discount > 0)
            <div class="rounded-xl bg-emerald-50 p-2 text-right sm:rounded-2xl sm:p-4">
                <span class="mb-1 block text-[11px] leading-4 text-emerald-600 sm:text-sm">الخصم</span>
                <span class="text-xs font-black text-emerald-800 sm:text-sm">- <span data-cart-discount>{{ arabic_number(number_format($discount, 0)) }}</span> {{ setting('currency_label', $settings['currency_label'] ?? '') }}</span>
            </div>
        @endif
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
    <div class="border-t border-slate-100 p-3 sm:p-5">
        @if($promoCode)
            <div class="flex items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                <div class="text-right"><p class="text-xs font-bold text-emerald-600">كود الخصم مطبق</p><strong dir="ltr" class="text-emerald-900">{{ $promoCode->code }}</strong></div>
                <form method="POST" action="{{ route('cart.promo-code.destroy') }}">@csrf @method('DELETE')<button class="rounded-xl bg-white px-3 py-2 text-xs font-black text-rose-600 shadow-sm">إزالة</button></form>
            </div>
        @else
            <form method="POST" action="{{ route('cart.promo-code.store') }}" class="flex gap-2">
                @csrf
                <input name="promo_code" maxlength="40" dir="ltr" autocomplete="off" placeholder="لديك كود خصم؟"
                    class="min-w-0 flex-1 rounded-xl border-slate-200 py-2.5 text-sm font-bold uppercase focus:border-indigo-500 focus:ring-indigo-500">
                <button class="rounded-xl bg-indigo-50 px-4 py-2.5 text-xs font-black text-indigo-700">تطبيق</button>
            </form>
            @error('promo_code')<p class="mt-2 text-right text-xs font-bold text-rose-600">{{ $message }}</p>@enderror
        @endif
    </div>
</section>
