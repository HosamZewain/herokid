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
    $cartCollection = collect($cartItems);
    $storyLineItems = $cartCollection->filter(fn ($item) => ($item['item_type'] ?? 'story') === 'story');
    $standaloneProductItems = $cartCollection->filter(fn ($item) => ($item['item_type'] ?? 'story') === 'product');
    $addOnItems = $cartCollection->filter(fn ($item) => ($item['item_type'] ?? 'story') === 'product_add_on');
    $footballLandingCart = $storyLineItems->isNotEmpty()
        && $storyLineItems->every(fn ($item) => ($item['source_context'] ?? null) === 'football_landing');
@endphp

<div class="min-h-[70vh] bg-slate-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
        <div class="mb-6 hidden justify-start sm:flex">
            <a href="{{ $footballLandingCart ? route('football-stories.index') : route('stories.index') }}"
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
            <p data-cart-mobile-delivery-notice class="mb-4 text-center text-sm font-black text-slate-800 sm:hidden">
                الرجاء إدخال بيانات التوصيل لإتمام الطلب
            </p>
            @unless($footballLandingCart)
                <x-purchase-progress :current="3" class="mb-6 hidden sm:block" />
            @endunless
            @include('front.cart._summary', ['attributes' => new \Illuminate\View\ComponentAttributeBag(['class' => 'mb-6 lg:hidden'])])

            <div class="grid grid-cols-1 lg:grid-cols-[minmax(560px,1.18fr)_minmax(0,0.82fr)] gap-6 lg:gap-8 items-start">
                <aside class="order-1 lg:order-1 space-y-6 lg:sticky lg:top-28">
                    <section class="rounded-3xl border border-slate-100 bg-white shadow-sm p-5 sm:p-6">
                        <div class="mb-5 text-right">
                            <p class="text-sm font-bold text-indigo-600">الخطوة ٣ من {{ $footballLandingCart ? '٣' : '٤' }}</p>
                            <h2 class="text-xl font-black text-slate-950 mt-1">بيانات ولي الأمر والتوصيل</h2>
                        </div>

                        @if($errors->any())
                            <div data-scroll-on-load data-first-error-field="{{ $errors->keys()[0] ?? '' }}" tabindex="-1"
                                class="mb-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-right text-sm text-red-700">
                                <p class="font-black">راجع البيانات المطلوبة:</p>
                                <ul class="mt-2 list-inside list-disc space-y-1">
                                    @foreach($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @guest
                            <div class="bg-blue-50 border border-blue-100 rounded-2xl p-4 text-sm text-blue-800 mb-4 text-right leading-6">
                                أنت تطلب كزائر. يمكنك <a href="{{ route('login') }}" class="font-black underline">تسجيل الدخول</a> لمتابعة طلبك لاحقاً.
                            </div>
                        @endguest

                        <form action="{{ route('checkout.store') }}" method="POST" class="space-y-4" data-checkout-form>
                            @csrf
                            <div>
                                <label for="checkout-parent-name" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">اسم ولي الأمر <span class="text-red-500">*</span></label>
                                <input id="checkout-parent-name" type="text" name="parent_name" value="{{ old('parent_name', auth()->user()->name ?? '') }}" required autocomplete="name"
                                    @if($errors->has('parent_name')) aria-invalid="true" @endif
                                    class="block w-full rounded-2xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3">
                                <x-input-error :messages="$errors->get('parent_name')" class="mt-1" />
                            </div>
                            <div>
                                <label for="checkout-phone" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">رقم الموبايل / واتساب <span class="text-red-500">*</span></label>
                                <input id="checkout-phone" type="tel" inputmode="tel" autocomplete="tel" name="phone" value="{{ old('phone', auth()->user()->phone ?? data_get($savedDeliveryDetails, 'phone')) }}" required dir="ltr"
                                    @if($errors->has('phone')) aria-invalid="true" @endif
                                    class="block w-full rounded-2xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3">
                                <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-3">
                                <div>
                                    <label for="delivery_country_id" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">الدولة <span class="text-red-500">*</span></label>
                                    <select name="delivery_country_id" id="delivery_country_id" required autocomplete="country-name"
                                        @if($errors->has('delivery_country_id')) aria-invalid="true" @endif
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
                                    <label for="delivery_governorate_id" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">المحافظة <span class="text-red-500">*</span></label>
                                    <select name="delivery_governorate_id" id="delivery_governorate_id" required
                                        autocomplete="address-level1"
                                        @if($errors->has('delivery_governorate_id')) aria-invalid="true" @endif
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
                                    <label for="checkout-city" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">المدينة <span class="text-red-500">*</span></label>
                                    <input id="checkout-city" type="text" name="city" value="{{ old('city', data_get($savedDeliveryDetails, 'city')) }}" required autocomplete="address-level2"
                                        @if($errors->has('city')) aria-invalid="true" @endif
                                        class="block w-full rounded-2xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3">
                                    <x-input-error :messages="$errors->get('city')" class="mt-1" />
                                </div>
                                <div>
                                    <label for="checkout-street" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">الشارع <span class="text-red-500">*</span></label>
                                    <input id="checkout-street" type="text" name="street" value="{{ old('street', data_get($savedDeliveryDetails, 'street')) }}" required autocomplete="address-line1"
                                        @if($errors->has('street')) aria-invalid="true" @endif
                                        class="block w-full rounded-2xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3">
                                    <x-input-error :messages="$errors->get('street')" class="mt-1" />
                                </div>
                            </div>
                            <div>
                                <label for="checkout-address-details" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">تفاصيل العنوان <span class="text-red-500">*</span></label>
                                <textarea id="checkout-address-details" name="address_details" rows="3" required autocomplete="address-line2"
                                    @if($errors->has('address_details')) aria-invalid="true" @endif
                                    class="block w-full rounded-2xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3">{{ old('address_details', data_get($savedDeliveryDetails, 'address_details')) }}</textarea>
                                <x-input-error :messages="$errors->get('address_details')" class="mt-1" />
                            </div>
                            <button type="submit"
                                class="w-full rounded-2xl bg-indigo-600 py-4 text-base font-black text-white shadow-lg shadow-indigo-100 transition hover:-translate-y-0.5 hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-200">
                                إتمام الطلب
                            </button>
                            <p class="text-center text-xs font-bold leading-5 text-slate-950">
                                سيتواصل فريقنا معك على الواتساب للمعاينة قبل الطباعة وتأكيد الطلب
                            </p>
                        </form>
                    </section>
                </aside>

                <div class="order-2 lg:order-2 space-y-6">
                    @include('front.cart._summary', ['attributes' => new \Illuminate\View\ComponentAttributeBag(['class' => 'hidden lg:block'])])

                    <section class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm sm:rounded-3xl">
                        <div class="flex items-center justify-between border-b border-slate-100 p-3 md:hidden">
                            <p class="text-xs font-bold text-slate-500" data-cart-count-label data-suffix=" عنصر">{{ $cartCount }} عنصر</p>
                            <h2 class="text-base font-black text-slate-950">عناصر السلة</h2>
                        </div>
                        <div class="hidden border-b border-slate-100 p-5 sm:p-6 md:block">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <p class="text-sm font-bold text-slate-500" data-cart-count-label data-suffix=" عنصر في السلة">{{ $cartCount }} عنصر في السلة</p>
                                <h2 class="text-xl font-black text-slate-950 text-right">عناصر السلة</h2>
                            </div>
                        </div>

                        <div class="divide-y divide-slate-100 md:hidden" data-cart-mobile-list>
                            @foreach($cartItems as $key => $item)
                                @include('front.cart._mobile_item', compact('key', 'item', 'addOnItems'))
                            @endforeach
                        </div>

                        <div class="hidden divide-y divide-slate-100 md:block">
                            @foreach($cartItems as $key => $item)
                                @continue(($item['item_type'] ?? 'story') === 'product_add_on')
                                @php
                                    $itemType = $item['item_type'] ?? 'story';
                                    $linkedAddOns = $itemType === 'story'
                                        ? $addOnItems->filter(fn ($addOn) => ($addOn['linked_story_key'] ?? null) === $key)
                                        : collect();
                                @endphp
                                <article class="p-5 sm:p-6">
                                    @php
                                        $itemPrice = $itemType === 'story'
                                            ? (float) ($item['story_price'] ?? 0)
                                            : ((int) ($item['line_total_cents'] ?? 0) / 100);
                                    @endphp
                                    <div class="space-y-4">
                                        <div class="text-right">
                                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                                                <div>
                                                    @if($itemType === 'story')
                                                        <p class="inline-flex rounded-full bg-indigo-50 px-3 py-1 text-xs font-extrabold text-indigo-600 mb-2">قصة مخصصة</p>
                                                        <h3 class="text-lg sm:text-xl font-black text-slate-950">{{ $item['story_title'] ?? 'قصة' }}</h3>
                                                    @elseif($itemType === 'package')
                                                        <p class="inline-flex rounded-full bg-fuchsia-50 px-3 py-1 text-xs font-extrabold text-fuchsia-700 mb-2">باقة موفرة</p>
                                                        <h3 class="text-lg sm:text-xl font-black text-slate-950">{{ $item['package_name'] ?? 'باقة HeroKid' }}</h3>
                                                    @else
                                                        <p class="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-extrabold text-emerald-700 mb-2">منتج من المتجر</p>
                                                        <h3 class="text-lg sm:text-xl font-black text-slate-950">{{ $item['product_title'] ?? 'منتج' }}</h3>
                                                        @if(!empty($item['variant_name']))
                                                            <p class="mt-1 text-xs font-bold text-slate-400">النوع: {{ $item['variant_name'] }}</p>
                                                        @endif
                                                    @endif
                                                </div>
                                                <div class="text-right">
                                                    <p class="inline-flex w-fit rounded-2xl bg-indigo-50 px-4 py-2 text-base font-black text-indigo-700">
                                                        {{ format_money($itemPrice) }}
                                                    </p>
                                                    @if($itemType === 'story' && !empty($item['story_offer_applied']))
                                                        <p class="mt-1 text-xs font-bold text-pink-600">
                                                            {{ $item['story_offer_label'] ?? 'عرض خاص' }}
                                                            <span class="mr-1 text-slate-400 line-through">{{ format_money($item['story_regular_price'] ?? $itemPrice) }}</span>
                                                        </p>
                                                    @endif
                                                </div>
                                            </div>

                                            @if($itemType === 'story')
                                                <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-2">
                                                    <div class="rounded-2xl bg-slate-50 px-3 py-3">
                                                        <p class="text-xs font-bold text-slate-400 mb-1">اسم الطفل</p>
                                                        <p class="font-black text-slate-900">{{ $item['child_name'] ?? '-' }}</p>
                                                    </div>
                                                    <div class="rounded-2xl bg-slate-50 px-3 py-3">
                                                        <p class="text-xs font-bold text-slate-400 mb-1">العمر والجنس</p>
                                                        <p class="font-black text-slate-900">
                                                            {{ $item['child_age_range'] ?? (($item['child_age'] ?? '-') . ' سنة') }} · {{ ($item['child_gender'] ?? null) === 'boy' ? 'ولد' : (($item['child_gender'] ?? null) === 'girl' ? 'بنت' : 'غير محدد') }}
                                                        </p>
                                                    </div>
                                                    <div class="rounded-2xl bg-slate-50 px-3 py-3">
                                                        <p class="text-xs font-bold text-slate-400 mb-1">الصور المرفقة</p>
                                                        <p class="font-black text-slate-900">{{ count($item['uploaded_photos'] ?? []) }} صورة</p>
                                                    </div>
                                                </div>

                                                @if($linkedAddOns->isNotEmpty())
                                                    <div class="mt-4 rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4">
                                                        <div class="mb-3 flex items-center justify-between gap-3">
                                                            <p class="text-xs font-bold text-indigo-500">{{ $linkedAddOns->count() }} إضافة</p>
                                                            <p class="text-sm font-black text-indigo-900">إضافات مرتبطة بهذه القصة</p>
                                                        </div>
                                                        <div class="space-y-3">
                                                            @foreach($linkedAddOns as $addOnKey => $addOn)
                                                                <div class="rounded-2xl bg-white p-3 text-sm shadow-sm">
                                                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                                        <div class="text-right">
                                                                            <p class="font-black leading-6 text-slate-900">{{ $addOn['product_title'] ?? 'إضافة' }}</p>
                                                                            <p class="mt-1 text-xs text-slate-400">
                                                                                {{ !empty($addOn['variant_name']) ? 'النوع: '.$addOn['variant_name'].' · ' : '' }}
                                                                                الكمية: {{ $addOn['quantity'] ?? 1 }}
                                                                            </p>
                                                                            <p class="mt-1 text-xs font-black text-indigo-700">{{ format_money(((int) ($addOn['line_total_cents'] ?? 0)) / 100) }}</p>
                                                                        </div>
                                                                        <form action="{{ route('cart.destroy', $addOnKey) }}" method="POST" class="shrink-0">
                                                                            @csrf
                                                                            @method('DELETE')
                                                                            <button class="min-h-11 w-full rounded-xl bg-red-50 px-4 py-2 text-xs font-black text-red-500 hover:bg-red-100 sm:w-auto">حذف</button>
                                                                        </form>
                                                                    </div>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif
                                            @elseif($itemType === 'package')
                                                <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3">
                                                    <div class="rounded-2xl bg-slate-50 px-3 py-3"><p class="text-xs font-bold text-slate-400 mb-1">القصص</p><p class="font-black text-slate-900">{{ $item['story_count'] ?? 0 }}</p></div>
                                                    <div class="rounded-2xl bg-slate-50 px-3 py-3"><p class="text-xs font-bold text-slate-400 mb-1">المنتجات</p><p class="font-black text-slate-900">{{ $item['product_count'] ?? 0 }}</p></div>
                                                    @if(($item['regular_total_cents'] ?? 0) > ($item['line_total_cents'] ?? 0))
                                                        <div class="rounded-2xl bg-emerald-50 px-3 py-3"><p class="text-xs font-bold text-emerald-600 mb-1">إجمالي منفصل</p><p class="font-black text-emerald-800 line-through">{{ format_money($item['regular_total_cents'] / 100) }}</p></div>
                                                    @endif
                                                </div>
                                            @elseif(($item['personalization_mode'] ?? null) === 'collect_child_details')
                                                @php
                                                    $personalizationValues = \App\Support\ProductPersonalizationSchema::displayValues($item['personalization_snapshot'] ?? []);
                                                @endphp
                                                <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
                                                    @forelse($personalizationValues as $value)
                                                        <div class="rounded-2xl bg-indigo-50 px-3 py-3">
                                                            <p class="mb-1 text-xs font-bold text-indigo-500">{{ $value['label'] }}</p>
                                                            <p class="break-words font-black text-slate-900">{{ $value['value'] }}</p>
                                                        </div>
                                                    @empty
                                                        <div class="rounded-2xl bg-indigo-50 px-3 py-3 text-sm font-bold text-indigo-700 sm:col-span-3">بيانات التخصيص محفوظة مع المنتج.</div>
                                                    @endforelse
                                                </div>
                                            @else
                                                <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-2">
                                                    <div class="rounded-2xl bg-slate-50 px-3 py-3">
                                                        <p class="text-xs font-bold text-slate-400 mb-1">الكمية</p>
                                                        <p class="font-black text-slate-900">{{ $item['quantity'] ?? 1 }}</p>
                                                    </div>
                                                    <div class="rounded-2xl bg-slate-50 px-3 py-3">
                                                        <p class="text-xs font-bold text-slate-400 mb-1">سعر الوحدة</p>
                                                        <p class="font-black text-slate-900">{{ format_money((float) ($item['unit_price'] ?? 0)) }}</p>
                                                    </div>
                                                    <div class="rounded-2xl bg-slate-50 px-3 py-3">
                                                        <p class="text-xs font-bold text-slate-400 mb-1">التخصيص</p>
                                                        <p class="font-black text-slate-900">بدون بيانات طفل</p>
                                                    </div>
                                                </div>
                                            @endif

                                            @if($itemType === 'story' && (!empty($item['interests']) || !empty($item['gift_note']) || !empty($item['parent_notes'])))
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

                                        <div class="flex flex-col gap-3 sm:flex-row">
                                            @if($itemType === 'story' && !empty($item['story_slug']))
                                                <a href="{{ route('stories.show', $item['story_slug']) }}"
                                                    class="inline-flex flex-1 items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs font-black text-slate-700 hover:bg-slate-50 transition">
                                                    عرض القصة
                                                </a>
                                            @elseif($itemType !== 'story' && !empty($item['product_slug']))
                                                <a href="{{ route('shop.product.show', $item['product_slug']) }}"
                                                    class="inline-flex flex-1 items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs font-black text-slate-700 hover:bg-slate-50 transition">
                                                    عرض المنتج
                                                </a>
                                            @endif
                                            <form action="{{ route('cart.destroy', $key) }}" method="POST" class="flex-1">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" @if($linkedAddOns->isNotEmpty()) onclick="return confirm('سيتم حذف الإضافات المرتبطة بهذه القصة أيضاً. هل تريد المتابعة؟')" @endif
                                                    class="min-h-11 w-full rounded-2xl border border-red-100 bg-red-50 px-4 py-3 text-xs font-black text-red-600 hover:bg-red-100 transition">
                                                    حذف
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </section>

                    @if(isset($recommendedProducts) && $recommendedProducts->isNotEmpty())
                        @php $targetStory = $storyLineItems->first(); @endphp
                        <section data-cart-upsells class="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-3 shadow-sm sm:rounded-3xl sm:p-6">
                            <div class="mb-3 text-right sm:mb-5">
                                <p class="text-xs font-black text-indigo-600 sm:text-sm">{{ $storyLineItems->isNotEmpty() ? 'أضف نشاطًا مع القصة' : 'منتجات مختارة تناسب طلبك' }}</p>
                                <h2 class="mt-1 text-lg font-black text-slate-950 sm:text-xl">قد يعجب طفلك أيضًا</h2>
                            </div>
                            <div class="-mx-1 flex snap-x snap-mandatory gap-2 overflow-x-auto px-1 pb-2 lg:mx-0 lg:grid lg:grid-cols-2 lg:gap-4 lg:overflow-visible lg:px-0 xl:grid-cols-3">
                                @foreach($recommendedProducts as $product)
                                    @php
                                        $isPersonalizedAddon = $product->isPersonalizedAddon();
                                        $requiresProductPage = $product->personalization_mode === 'collect_child_details'
                                            || $product->activeVariants->isNotEmpty()
                                            || ($isPersonalizedAddon && $storyLineItems->isEmpty());
                                    @endphp
                                    <article data-upsell-card class="w-[calc(50%_-_0.25rem)] min-w-[calc(50%_-_0.25rem)] snap-start overflow-hidden rounded-2xl border border-white bg-white text-right shadow-sm lg:w-auto lg:min-w-0">
                                        <div class="relative aspect-[4/3] overflow-hidden bg-slate-50">
                                            <div class="absolute inset-0">
                                                <x-product-image-placeholder />
                                            </div>
                                            @if($product->featured_image_url)
                                                <img src="{{ $product->featured_image_url }}" alt="{{ $product->name_ar }}"
                                                    class="relative z-10 h-full w-full object-cover" loading="lazy"
                                                    onerror="this.remove()">
                                            @endif
                                        </div>
                                        <div class="p-2.5">
                                            <h3 class="line-clamp-2 min-h-10 text-xs font-black leading-5 text-slate-950 sm:text-sm">{{ $product->name_ar }}</h3>
                                            <p class="mt-1 text-sm font-black text-indigo-700">{{ format_money($product->effectivePrice()) }}</p>
                                        </div>
                                        @if($requiresProductPage)
                                            <div class="border-t border-slate-100 p-2.5">
                                                <a href="{{ route('shop.product.show', $product) }}" class="flex min-h-11 w-full items-center justify-center rounded-xl bg-indigo-600 px-3 py-2 text-xs font-black text-white hover:bg-indigo-700">عرض المنتج واختيار التفاصيل</a>
                                            </div>
                                        @else
                                        <form action="{{ route('cart.products.store', $product) }}" method="POST"
                                            data-cart-upsell-form data-product-name="{{ $product->name_ar }}"
                                            class="space-y-2 border-t border-slate-100 p-2.5">
                                            @csrf
                                            @if($isPersonalizedAddon)
                                                @if($storyLineItems->count() > 1)
                                                    <div>
                                                        <label class="sr-only">اختر الطفل</label>
                                                        <select name="linked_story_key" required class="w-full rounded-lg border-slate-200 py-1.5 text-xs text-right">
                                                            @foreach($storyLineItems as $storyKey => $storyItem)
                                                                <option value="{{ $storyKey }}" @selected($storyKey === $upsellStoryKey)>
                                                                    لطفل: {{ $storyItem['child_name'] ?? 'طفل' }}
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                @else
                                                    <input type="hidden" name="linked_story_key" value="{{ $targetStory['key'] ?? $storyLineItems->keys()->first() }}">
                                                @endif
                                            @endif
                                            <button type="submit" data-upsell-submit class="min-h-11 w-full rounded-xl bg-indigo-600 px-3 py-2 text-xs font-black text-white hover:bg-indigo-700 disabled:cursor-wait disabled:opacity-60">
                                                إضافة
                                            </button>
                                            <p data-upsell-status class="hidden text-center text-[10px] font-bold leading-4" role="status" aria-live="polite"></p>
                                        </form>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endif

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
            let subtotal = Number(@json((float) $subtotal));
            const formatMoney = (value) => Math.max(0, Number(value || 0)).toLocaleString('ar-EG', { maximumFractionDigits: 0 });

            function selectedCountryFee() {
                return Number(countrySelect?.selectedOptions?.[0]?.dataset?.fee || 0);
            }

            function updateTotals(fee) {
                document.querySelectorAll('[data-cart-subtotal]').forEach((node) => {
                    node.textContent = `${formatMoney(subtotal)} {{ setting('currency_label', $settings['currency_label'] ?? '') }}`;
                });
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

            document.querySelectorAll('[data-cart-upsell-form]').forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();

                    const button = form.querySelector('[data-upsell-submit]');
                    const status = form.querySelector('[data-upsell-status]');
                    const originalLabel = button?.textContent?.trim() || 'إضافة';

                    if (!button || button.disabled) {
                        return;
                    }

                    button.disabled = true;
                    button.textContent = 'جاري الإضافة...';
                    status.textContent = '';
                    status.className = 'hidden text-center text-[10px] font-bold leading-4';

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            body: new FormData(form),
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        const payload = await response.json();

                        if (!response.ok) {
                            throw new Error(payload.message || 'تعذر إضافة المنتج.');
                        }

                        subtotal += Number(payload.added_line_total || 0);
                        const selectedFee = governorateSelect?.value
                            ? Number(governorateSelect.selectedOptions[0]?.dataset?.fee ?? selectedCountryFee())
                            : selectedCountryFee();

                        updateTotals(selectedFee);
                        document.querySelectorAll('[data-cart-count]').forEach((node) => {
                            node.textContent = payload.cart_count;
                        });
                        document.querySelectorAll('[data-cart-count-label]').forEach((node) => {
                            node.textContent = `${payload.cart_count}${node.dataset.suffix || ''}`;
                        });
                        if (payload.mobile_item_html) {
                            document.querySelector('[data-cart-mobile-list]')
                                ?.insertAdjacentHTML('beforeend', payload.mobile_item_html);
                        }

                        button.textContent = 'تمت الإضافة ✓';
                        button.classList.remove('bg-indigo-600', 'hover:bg-indigo-700');
                        button.classList.add('bg-emerald-600');

                        window.setTimeout(() => {
                            form.closest('[data-upsell-card]')?.remove();

                            if (!document.querySelector('[data-upsell-card]')) {
                                document.querySelector('[data-cart-upsells]')?.remove();
                            }
                        }, 650);
                    } catch (error) {
                        button.disabled = false;
                        button.textContent = originalLabel;
                        status.textContent = error.message || 'تعذر إضافة المنتج.';
                        status.className = 'block text-center text-[10px] font-bold leading-4 text-red-600';
                    }
                });
            });

            document.addEventListener('submit', async (event) => {
                const form = event.target.closest('[data-cart-remove-form]');

                if (!form) {
                    return;
                }

                event.preventDefault();

                if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
                    return;
                }

                const button = form.querySelector('button[type="submit"]');

                if (!button || button.disabled) {
                    return;
                }

                button.disabled = true;

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    const payload = await response.json();

                    if (!response.ok) {
                        throw new Error(payload.message || 'تعذر حذف العنصر.');
                    }

                    subtotal = Number(payload.subtotal || 0);
                    const selectedFee = governorateSelect?.value
                        ? Number(governorateSelect.selectedOptions[0]?.dataset?.fee ?? selectedCountryFee())
                        : selectedCountryFee();

                    updateTotals(selectedFee);
                    document.querySelectorAll('[data-cart-count]').forEach((node) => {
                        node.textContent = payload.cart_count;
                    });
                    document.querySelectorAll('[data-cart-count-label]').forEach((node) => {
                        node.textContent = `${payload.cart_count}${node.dataset.suffix || ''}`;
                    });

                    (payload.removed_keys || []).forEach((removedKey) => {
                        document.querySelectorAll('[data-cart-item-key]').forEach((item) => {
                            if (item.dataset.cartItemKey !== removedKey) {
                                return;
                            }

                            item.classList.add('pointer-events-none', 'opacity-0', '-translate-x-2');
                            window.setTimeout(() => item.remove(), 180);
                        });
                    });

                    if (payload.cart_empty) {
                        window.setTimeout(() => {
                            const list = document.querySelector('[data-cart-mobile-list]');

                            if (list) {
                                list.innerHTML = '<p class="px-4 py-6 text-center text-sm font-bold text-slate-500">السلة فارغة. اختر قصة أو منتجًا للمتابعة.</p>';
                            }

                            document.querySelector('[data-cart-upsells]')?.remove();
                            document.querySelector('form[action="{{ route('checkout.store') }}"] button[type="submit"]')
                                ?.setAttribute('disabled', 'disabled');
                        }, 200);
                    }
                } catch (error) {
                    button.disabled = false;
                    window.alert(error.message || 'تعذر حذف العنصر.');
                }
            });

            filterGovernorates();

            document.querySelector('[data-checkout-form]')?.addEventListener('submit', () => {
                const checkoutEventKey = 'herokid:analytics:InitiateCheckout:{{ hash('sha256', implode('|', array_keys($cartItems))) }}';

                if (sessionStorage.getItem(checkoutEventKey) === '1') {
                    return;
                }

                window.HeroKidAnalytics?.track('InitiateCheckout', {
                    content_type: 'product',
                    num_items: Number(@json($cartCount)),
                    value: subtotal,
                    currency: 'EGP',
                }, true);
                sessionStorage.setItem(checkoutEventKey, '1');
            });

            @if(is_array(session('football_add_to_cart_event')))
                window.HeroKidAnalytics?.track('AddToCart', @json(session('football_add_to_cart_event'), \App\Support\Seo::jsonFlags()), true);
                sessionStorage.removeItem('herokid:football-landing:draft');
                sessionStorage.removeItem('herokid:football-landing:photo-ids');
            @endif

            if (governorateSelect?.value) {
                const fee = governorateSelect.selectedOptions[0]?.dataset?.fee ?? selectedCountryFee();
                updateTotals(fee);
            }
        });
    </script>
@endif
</x-front-layout>
