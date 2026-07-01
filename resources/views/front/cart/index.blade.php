<x-front-layout>
<x-slot name="pageTitle">سلة الطلب</x-slot>
<x-slot name="pageDescription">راجع قصص HeroKid المخصصة في السلة وأدخل بيانات ولي الأمر والتوصيل مرة واحدة قبل إرسال الطلب.</x-slot>
<x-slot name="robots">noindex, nofollow</x-slot>

@php
    $cartCount = count($cartItems);
    $total = $subtotal + $deliveryFee;
    $defaultCountry = $deliveryCountries->firstWhere('code', 'EG') ?? $deliveryCountries->first();
    $savedDeliveryDetails = $savedDeliveryDetails ?? [];
    $selectedCountryId = (string) old('delivery_country_id', data_get($savedDeliveryDetails, 'delivery_country_id', $defaultCountry?->id));
    $selectedGovernorateId = (string) old('delivery_governorate_id', data_get($savedDeliveryDetails, 'delivery_governorate_id'));
@endphp

<div class="min-h-[70vh] bg-slate-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
        <div class="mb-6 flex justify-start">
            <a href="{{ route('stories.index') }}"
                class="inline-flex items-center justify-center gap-2 rounded-2xl border border-indigo-200 bg-white px-5 py-3 text-sm font-extrabold text-indigo-700 shadow-sm hover:bg-indigo-50 transition">
                <span>إضافة قصة أخرى</span>
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.4" d="M12 5v14m7-7H5" />
                </svg>
            </a>
        </div>

        @if(session('success'))
            <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-right font-bold text-green-700">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-right font-bold text-red-700">
                {{ session('error') }}
            </div>
        @endif

        @if(empty($cartItems))
            <div class="overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-sm">
                <div class="grid grid-cols-1 lg:grid-cols-[1fr_360px]">
                    <div class="p-8 sm:p-10 text-right">
                        <p class="inline-flex rounded-full bg-amber-50 px-4 py-2 text-xs font-extrabold text-amber-700 mb-5">
                            لا توجد قصص في السلة
                        </p>
                        <h2 class="text-2xl sm:text-3xl font-black text-slate-950">ابدأ باختيار قصة لطفلك</h2>
                        <p class="mt-3 text-slate-500 leading-8 max-w-2xl">
                            من صفحة القصة ستدخل اسم الطفل، العمر، الاهتمامات، وترفع الصور. بعد ذلك ستظهر القصة هنا لإكمال بيانات التوصيل.
                        </p>
                        <div class="mt-7 flex flex-col sm:flex-row gap-3 sm:justify-start">
                            <a href="{{ route('stories.index') }}"
                                class="inline-flex items-center justify-center rounded-2xl bg-indigo-600 px-7 py-4 text-sm font-black text-white shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition">
                                تصفح القصص
                            </a>
                            <a href="{{ route('how-it-works') }}"
                                class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-7 py-4 text-sm font-black text-slate-700 hover:bg-slate-50 transition">
                                كيف يعمل؟
                            </a>
                        </div>
                    </div>
                    <div class="bg-indigo-50 p-8 sm:p-10 flex items-center justify-center">
                        <img src="/images/logo-192.png"
                            srcset="/images/logo-96.png 96w, /images/logo-192.png 192w, /images/logo-320.png 320w"
                            sizes="160px"
                            width="192" height="164"
                            alt="HeroKid" class="max-h-40 w-auto object-contain">
                    </div>
                </div>
            </div>
        @else
            <div class="grid grid-cols-1 lg:grid-cols-[minmax(560px,1.18fr)_minmax(0,0.82fr)] gap-6 lg:gap-8 items-start">
                <aside class="order-1 lg:order-1 space-y-6 lg:sticky lg:top-28">
                    <section class="rounded-3xl border border-slate-100 bg-white shadow-sm p-5 sm:p-6">
                        <div class="mb-5 text-right">
                            <p class="text-sm font-bold text-indigo-600">الخطوة الأخيرة</p>
                            <h2 class="text-xl font-black text-slate-950 mt-1">بيانات ولي الأمر والتوصيل</h2>
                            <p class="text-sm text-slate-500 mt-2 leading-6">هذه البيانات تُستخدم لكل القصص الموجودة في السلة.</p>
                        </div>

                        @guest
                            <div class="bg-blue-50 border border-blue-100 rounded-2xl p-4 text-sm text-blue-800 mb-4 text-right leading-6">
                                أنت تطلب كزائر. يمكنك <a href="{{ route('login') }}" class="font-black underline">تسجيل الدخول</a> لمتابعة طلبك لاحقاً.
                            </div>
                        @endguest

                        <form action="{{ route('checkout.store') }}" method="POST" class="space-y-4">
                            @csrf
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">اسم ولي الأمر <span class="text-red-500">*</span></label>
                                <input type="text" name="parent_name" value="{{ old('parent_name', auth()->user()->name ?? '') }}" required
                                    class="block w-full rounded-2xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3">
                                <x-input-error :messages="$errors->get('parent_name')" class="mt-1" />
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">رقم الموبايل / واتساب <span class="text-red-500">*</span></label>
                                <input type="text" name="phone" value="{{ old('phone', auth()->user()->phone ?? data_get($savedDeliveryDetails, 'phone')) }}" required dir="ltr"
                                    class="block w-full rounded-2xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3">
                                <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-3">
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">الدولة <span class="text-red-500">*</span></label>
                                    <select name="delivery_country_id" id="delivery_country_id" required
                                        class="block w-full rounded-2xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3">
                                        <option value="">اختر الدولة...</option>
                                        @foreach($deliveryCountries as $country)
                                            <option value="{{ $country->id }}" data-fee="{{ (float) $country->delivery_fee }}" @selected($selectedCountryId === (string) $country->id)>
                                                {{ $country->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('delivery_country_id')" class="mt-1" />
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">المحافظة <span class="text-red-500">*</span></label>
                                    <select name="delivery_governorate_id" id="delivery_governorate_id" required
                                        class="block w-full rounded-2xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3">
                                        <option value="">اختر المحافظة...</option>
                                        @foreach($deliveryCountries as $country)
                                            @foreach($country->activeGovernorates as $governorate)
                                                @php
                                                    $effectiveFee = (float) ($governorate->delivery_fee ?? $country->delivery_fee);
                                                @endphp
                                                <option
                                                    value="{{ $governorate->id }}"
                                                    data-country-id="{{ $country->id }}"
                                                    data-fee="{{ $effectiveFee }}"
                                                    @selected($selectedGovernorateId === (string) $governorate->id)
                                                >
                                                    {{ $governorate->name }}
                                                </option>
                                            @endforeach
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('delivery_governorate_id')" class="mt-1" />
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">المدينة <span class="text-red-500">*</span></label>
                                    <input type="text" name="city" value="{{ old('city', data_get($savedDeliveryDetails, 'city')) }}" required
                                        class="block w-full rounded-2xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3">
                                    <x-input-error :messages="$errors->get('city')" class="mt-1" />
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">الشارع <span class="text-red-500">*</span></label>
                                    <input type="text" name="street" value="{{ old('street', data_get($savedDeliveryDetails, 'street')) }}" required
                                        class="block w-full rounded-2xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3">
                                    <x-input-error :messages="$errors->get('street')" class="mt-1" />
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">تفاصيل العنوان <span class="text-red-500">*</span></label>
                                <textarea name="address_details" rows="3" required
                                    class="block w-full rounded-2xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3">{{ old('address_details', data_get($savedDeliveryDetails, 'address_details')) }}</textarea>
                                <x-input-error :messages="$errors->get('address_details')" class="mt-1" />
                            </div>
                            <button type="submit"
                                class="w-full rounded-2xl bg-indigo-600 py-4 text-base font-black text-white shadow-lg shadow-indigo-100 transition hover:-translate-y-0.5 hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-200">
                                تأكيد الطلب وإرساله للمراجعة
                            </button>
                            <p class="text-center text-xs leading-6 text-slate-400">لن يتم الدفع الآن. سنتواصل معك لتأكيد الطلب قبل الإنتاج.</p>
                        </form>
                    </section>
                </aside>

                <div class="order-2 lg:order-2 space-y-6">
                    <section class="rounded-3xl border border-slate-100 bg-white shadow-sm overflow-hidden">
                        <div class="bg-slate-950 p-6 text-white text-right">
                            <p class="text-sm font-bold text-indigo-200">ملخص الطلب</p>
                            <p class="mt-2 text-3xl font-black"><span data-cart-total>{{ number_format($total, 0) }}</span> ج.م</p>
                            <p class="mt-1 text-sm text-slate-300">يشمل القصص ومصاريف التوصيل</p>
                        </div>
                        <div class="p-6 grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                            <div class="rounded-2xl bg-slate-50 p-4 text-right">
                                <span class="block text-slate-500 mb-1">إجمالي القصص</span>
                                <span class="font-black text-slate-950">{{ number_format($subtotal, 0) }} ج.م</span>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-4 text-right">
                                <span class="block text-slate-500 mb-1">مصاريف التوصيل</span>
                                <span class="font-black text-slate-950"><span data-delivery-fee>{{ number_format($deliveryFee, 0) }}</span> ج.م</span>
                            </div>
                            <div class="rounded-2xl bg-indigo-50 p-4 text-right">
                                <span class="block font-bold text-indigo-500 mb-1">الإجمالي</span>
                                <span class="text-xl font-black text-indigo-700"><span data-cart-total>{{ number_format($total, 0) }}</span> ج.م</span>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-3xl border border-slate-100 bg-white shadow-sm overflow-hidden">
                        <div class="border-b border-slate-100 p-5 sm:p-6">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <p class="text-sm font-bold text-slate-500">{{ $cartCount }} {{ $cartCount === 1 ? 'قصة مخصصة' : 'قصص مخصصة' }}</p>
                                <h2 class="text-xl font-black text-slate-950 text-right">القصص داخل السلة</h2>
                            </div>
                        </div>

                        <div class="divide-y divide-slate-100">
                            @foreach($cartItems as $key => $item)
                                <article class="p-5 sm:p-6">
                                    <div class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-5">
                                        <div class="text-right">
                                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                                                <div>
                                                    <p class="text-xs font-extrabold text-indigo-500 mb-1">قصة رقم {{ $loop->iteration }}</p>
                                                    <h3 class="text-lg sm:text-xl font-black text-slate-950">{{ $item['story_title'] ?? 'قصة' }}</h3>
                                                </div>
                                                <p class="inline-flex w-fit rounded-2xl bg-indigo-50 px-4 py-2 text-base font-black text-indigo-700">
                                                    {{ number_format((float) ($item['story_price'] ?? 0), 0) }} ج.م
                                                </p>
                                            </div>

                                            <div class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                <div class="rounded-2xl bg-slate-50 p-4">
                                                    <p class="text-xs font-bold text-slate-400 mb-1">اسم الطفل</p>
                                                    <p class="font-black text-slate-900">{{ $item['child_name'] ?? '-' }}</p>
                                                </div>
                                                <div class="rounded-2xl bg-slate-50 p-4">
                                                    <p class="text-xs font-bold text-slate-400 mb-1">العمر والجنس</p>
                                                    <p class="font-black text-slate-900">
                                                        {{ $item['child_age'] ?? '-' }} سنة · {{ ($item['child_gender'] ?? '') === 'boy' ? 'ولد' : 'بنت' }}
                                                    </p>
                                                </div>
                                                <div class="rounded-2xl bg-slate-50 p-4">
                                                    <p class="text-xs font-bold text-slate-400 mb-1">الصور المرفقة</p>
                                                    <p class="font-black text-slate-900">{{ count($item['uploaded_photos'] ?? []) }} صورة</p>
                                                </div>
                                            </div>

                                            @if(!empty($item['interests']) || !empty($item['gift_note']) || !empty($item['parent_notes']))
                                                <div class="mt-4 rounded-2xl border border-slate-100 bg-white p-4 text-sm leading-7 text-slate-600">
                                                    @if(!empty($item['interests']))
                                                        <p><span class="font-black text-slate-800">الاهتمامات:</span> {{ $item['interests'] }}</p>
                                                    @endif
                                                    @if(!empty($item['gift_note']))
                                                        <p><span class="font-black text-slate-800">الإهداء:</span> {{ $item['gift_note'] }}</p>
                                                    @endif
                                                    @if(!empty($item['parent_notes']))
                                                        <p><span class="font-black text-slate-800">ملاحظات:</span> {{ $item['parent_notes'] }}</p>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>

                                        <div class="flex md:flex-col gap-3 md:min-w-28">
                                            @if(!empty($item['story_slug']))
                                                <a href="{{ route('stories.show', $item['story_slug']) }}"
                                                    class="inline-flex flex-1 md:flex-none items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs font-black text-slate-700 hover:bg-slate-50 transition">
                                                    عرض القصة
                                                </a>
                                            @endif
                                            <form action="{{ route('cart.destroy', $key) }}" method="POST" class="flex-1 md:flex-none">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                    class="w-full rounded-2xl border border-red-100 bg-red-50 px-4 py-3 text-xs font-black text-red-600 hover:bg-red-100 transition">
                                                    حذف
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </section>

                    <section class="rounded-3xl border border-slate-100 bg-white shadow-sm p-5 sm:p-6">
                        <div class="flex items-start justify-between gap-4 text-right">
                            <div class="rounded-2xl bg-emerald-50 p-3 text-emerald-700">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-lg font-black text-slate-950">ماذا يحدث بعد تأكيد الطلب؟</h2>
                                <p class="mt-2 text-sm leading-7 text-slate-500">
                                    سيراجع فريق HeroKid بيانات الأطفال والصور، ثم يتواصل معك عبر واتساب لتأكيد التفاصيل والدفع قبل بدء التصميم والطباعة.
                                </p>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        @endif
    </div>
</div>
@if(!empty($cartItems))
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const countrySelect = document.getElementById('delivery_country_id');
            const governorateSelect = document.getElementById('delivery_governorate_id');
            const subtotal = Number(@json((float) $subtotal));
            const formatMoney = (value) => Math.max(0, Number(value || 0)).toLocaleString('en-US', { maximumFractionDigits: 0 });

            function selectedCountryFee() {
                return Number(countrySelect?.selectedOptions?.[0]?.dataset?.fee || 0);
            }

            function updateTotals(fee) {
                document.querySelectorAll('[data-delivery-fee]').forEach((node) => {
                    node.textContent = formatMoney(fee);
                });
                document.querySelectorAll('[data-cart-total]').forEach((node) => {
                    node.textContent = formatMoney(subtotal + Number(fee || 0));
                });
            }

            function filterGovernorates() {
                const countryId = countrySelect.value;
                let hasVisibleSelected = false;

                Array.from(governorateSelect.options).forEach((option) => {
                    if (!option.value) {
                        option.hidden = false;
                        return;
                    }

                    const isVisible = option.dataset.countryId === countryId;
                    option.hidden = !isVisible;
                    option.disabled = !isVisible;

                    if (isVisible && option.selected) {
                        hasVisibleSelected = true;
                    }
                });

                if (!hasVisibleSelected) {
                    governorateSelect.value = '';
                }

                updateTotals(selectedCountryFee());
            }

            countrySelect?.addEventListener('change', filterGovernorates);
            governorateSelect?.addEventListener('change', () => {
                const fee = governorateSelect.selectedOptions[0]?.dataset?.fee ?? selectedCountryFee();
                updateTotals(fee);
            });

            filterGovernorates();

            if (governorateSelect?.value) {
                const fee = governorateSelect.selectedOptions[0]?.dataset?.fee ?? selectedCountryFee();
                updateTotals(fee);
            }
        });
    </script>
@endif
</x-front-layout>
