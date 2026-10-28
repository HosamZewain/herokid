<section class="rounded-3xl border border-indigo-100 bg-white p-6 shadow-sm lg:p-8">
    <h3 class="text-2xl font-black text-slate-950">خصم عام على الشحن</h3>
    <p class="my-3 text-sm leading-7 text-slate-600">يُخصم تلقائيًا من سعر شحن كل محافظة في الموقع والتطبيق، بدون كود أو حد أدنى للطلب. لا يغيّر أسعار المحافظات الأصلية أو الطلبات السابقة. إذا استحق الطلب عرض الشحن المجاني على الموقع يصبح الشحن صفرًا، ولا يُخصم الفرق من سعر المنتجات.</p>
    @if($errors->shippingDiscount->any())
        <ul class="mb-4 text-sm text-red-700" role="alert">@foreach($errors->shippingDiscount->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif
    <form method="POST" action="{{ route('admin.discount-codes.shipping-discount') }}" class="space-y-4">
        @csrf @method('PUT')
        <div class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm font-bold">نوع الخصم
                <select name="shipping_type" class="mt-2 w-full rounded-xl border-slate-200">
                    <option value="fixed" @selected(($errors->shippingDiscount->any() ? old('shipping_type') : $shippingDiscount['type']) === 'fixed')>مبلغ ثابت بالجنيه</option>
                    <option value="percent" @selected(($errors->shippingDiscount->any() ? old('shipping_type') : $shippingDiscount['type']) === 'percent')>نسبة مئوية</option>
                </select>
            </label>
            <label class="block text-sm font-bold">قيمة الخصم (جنيه أو % حسب النوع)
                <input name="shipping_value" type="number" min="0" max="100000" step="0.01" required value="{{ $errors->shippingDiscount->any() ? old('shipping_value') : $shippingDiscount['value'] / 100 }}" class="mt-2 w-full rounded-xl border-slate-200">
            </label>
        </div>
        <p class="text-sm text-slate-500">مثال: شحن 100 و150 جنيه مع خصم 30 جنيه يصبح 70 و120. مع خصم 30% يصبح 70 و105. النسبة من 0 إلى 100%، وسعر الشحن لا يقل عن صفر.</p>
        <input type="hidden" name="shipping_enabled" value="0">
        <label class="flex items-center gap-2 font-bold"><input type="checkbox" name="shipping_enabled" value="1" @checked($errors->shippingDiscount->any() ? old('shipping_enabled') : $shippingDiscount['enabled'])>تفعيل خصم الشحن — يستمر حتى إيقافه</label>
        <button class="rounded-2xl bg-indigo-600 px-6 py-3 font-black text-white">حفظ خصم الشحن</button>
    </form>
</section>
