@php
    $shippingStart = $shippingOffer['starts_at'] ? \Carbon\CarbonImmutable::parse($shippingOffer['starts_at'])->setTimezone('Africa/Cairo')->format('Y-m-d\TH:i') : now('Africa/Cairo')->format('Y-m-d\TH:i');
    $shippingEnd = $shippingOffer['ends_at'] ? \Carbon\CarbonImmutable::parse($shippingOffer['ends_at'])->setTimezone('Africa/Cairo')->format('Y-m-d\TH:i') : now('Africa/Cairo')->addWeek()->format('Y-m-d\TH:i');
    $shippingStatus = ! $shippingOffer['enabled'] ? 'متوقف' : (now()->lt($shippingOffer['starts_at']) ? 'مجدول' : (now()->gte($shippingOffer['ends_at']) ? 'منتهي' : 'نشط'));
@endphp
<section class="rounded-3xl border border-emerald-200 bg-white p-6 shadow-sm lg:p-8">
    <h3 class="text-2xl font-black text-slate-950">عرض الشحن المجاني <span class="text-sm text-emerald-700">— {{ $shippingStatus }}</span></h3>
    <p class="my-3 text-sm leading-7 text-slate-600">عرض تلقائي للموقع بدون كود، يشمل القصص والمنتجات والباكدجات وكل مناطق التوصيل المتاحة. الحد الأدنى يُحسب بعد خصم الأكواد وبدون الشحن، ويشمل المبلغ المساوي للحد. المواعيد بتوقيت القاهرة.</p>
    @if($errors->shippingOffer->any())
        <ul class="mb-4 text-sm text-red-700">@foreach($errors->shippingOffer->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif
    <form method="POST" action="{{ route('admin.discount-codes.free-shipping') }}" class="space-y-4">
        @csrf @method('PUT')
        <div class="grid gap-4 md:grid-cols-3">
            <label class="block text-sm font-bold">الحد الأدنى بعد الخصومات (جنيه)
                <input name="minimum_subtotal" type="number" min="0.01" max="10000000" step="0.01" required value="{{ $errors->shippingOffer->any() ? old('minimum_subtotal') : $shippingOffer['minimum_cents'] / 100 }}" class="mt-2 w-full rounded-xl border-slate-200">
            </label>
            <label class="block text-sm font-bold">بداية العرض — القاهرة
                <input name="starts_at" type="datetime-local" required value="{{ $errors->shippingOffer->any() ? old('starts_at') : $shippingStart }}" class="mt-2 w-full rounded-xl border-slate-200">
            </label>
            <label class="block text-sm font-bold">نهاية العرض — القاهرة
                <input name="ends_at" type="datetime-local" required value="{{ $errors->shippingOffer->any() ? old('ends_at') : $shippingEnd }}" class="mt-2 w-full rounded-xl border-slate-200">
            </label>
        </div>
        <input type="hidden" name="enabled" value="0">
        <label class="flex items-center gap-2 font-bold"><input type="checkbox" name="enabled" value="1" @checked($errors->shippingOffer->any() ? old('enabled') : $shippingOffer['enabled'])>تفعيل العرض خلال المدة المحددة</label>
        <button class="rounded-2xl bg-indigo-600 px-6 py-3 font-black text-white">حفظ عرض الشحن المجاني</button>
    </form>
</section>
