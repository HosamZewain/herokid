<x-front-layout>
    @php
        $fallbackStoryCover = \App\Support\Seo::imageUrl('/images/site/featured_generic.png');
        $storyCoverUrl = $story->cover_url ?: $fallbackStoryCover;
        $storyPricing = app(\App\Services\Pricing\StoryPricingService::class);
        $storyRegularPrice = $storyPricing->regularPrice($story);
        $storyPrice = $storyPricing->effectivePrice($story);
        $storyHasOffer = $storyPricing->hasActiveOffer($story);
        $cartItemCount = count(session('cart.items', []));
        $storyDescription = trim((string) ($story->full_desc ?: $story->short_desc));
    @endphp

    {{-- ══ Per-page SEO slots ══ --}}
    <x-slot name="pageTitle">{{ $story->title }} — قصة أطفال مخصصة بوجه طفلك</x-slot>
    <x-slot name="pageDescription">{{ $story->seo_description }}</x-slot>
    <x-slot name="pageImage">{{ $storyCoverUrl }}</x-slot>
    <x-slot name="ogType">product</x-slot>
    <x-slot name="canonical">/stories/{{ $story->slug }}</x-slot>

    @push('schema')
        @php
            $storyUrl = \App\Support\Seo::url('/stories/' . $story->slug);
            $storySchemaProduct = [
                '@type' => 'Product',
                'name' => $story->title,
                'description' => $story->seo_description,
                'image' => $storyCoverUrl,
                'brand' => ['@type' => 'Brand', 'name' => 'HeroKid'],
                'offers' => [
                    '@type' => 'Offer',
                    'priceCurrency' => 'EGP',
                    'price' => (string) $storyPrice,
                    'availability' => 'https://schema.org/InStock',
                    'url' => $storyUrl,
                ],
            ];

            if ($story->lesson_value) {
                $storySchemaProduct['additionalProperty'] = [
                    '@type' => 'PropertyValue',
                    'name' => 'القيمة التربوية',
                    'value' => $story->lesson_value,
                ];
            }

            $storySchema = [
                '@context' => 'https://schema.org',
                '@graph' => [
                    [
                        '@type' => 'BreadcrumbList',
                        'itemListElement' => [
                            ['@type' => 'ListItem', 'position' => 1, 'name' => 'الرئيسية', 'item' => \App\Support\Seo::url('/')],
                            ['@type' => 'ListItem', 'position' => 2, 'name' => 'متجر القصص والمنتجات', 'item' => \App\Support\Seo::url('/shop')],
                            ['@type' => 'ListItem', 'position' => 3, 'name' => $story->title, 'item' => $storyUrl],
                        ],
                    ],
                    $storySchemaProduct,
                ],
            ];
        @endphp
        <script type="application/ld+json">
        @json($storySchema, \App\Support\Seo::jsonFlags())
        </script>
    @endpush

    <div class="min-h-screen bg-slate-50 py-8 pb-12 md:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <!-- Breadcrumb -->
            <nav aria-label="مسار التنقل" class="mb-4 text-sm font-bold text-slate-500">
                <ol class="flex flex-wrap items-center gap-2">
                    <li><a href="{{ route('home') }}" class="hover:text-indigo-700">الرئيسية</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('shop.index') }}" class="hover:text-indigo-700">متجر القصص والمنتجات</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="text-slate-800" aria-current="page">{{ $story->title }}</li>
                </ol>
            </nav>
            <div class="mb-8">
                <a href="{{ route('shop.index', ['type' => 'stories']) }}"
                    class="text-indigo-600 hover:text-indigo-800 flex items-center gap-2 font-medium text-sm w-fit">
                    <svg class="w-4 h-4 rtl:rotate-180 flex-shrink-0" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    العودة إلى متجر القصص والمنتجات
                </a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-start">

                {{-- ===== LEFT: Story Details ===== --}}
                <div class="text-right">

                    <!-- Cover Image -->
                    <div
                        class="aspect-[4/4] bg-gradient-to-br from-indigo-50 to-slate-100 rounded-3xl overflow-hidden shadow-lg mb-8 relative">
                        <img src="{{ $storyCoverUrl }}" alt="{{ $story->title }}"
                            width="640" height="640"
                            fetchpriority="high"
                            onerror="this.onerror=null;this.src='{{ $fallbackStoryCover }}';"
                            class="w-full h-full object-cover">
                    </div>

                    <!-- Title & Price -->
                    <h1 class="text-4xl font-extrabold text-slate-900 mb-4">{{ $story->title }}</h1>
                    <div class="flex items-center gap-4 mb-6 justify-end">
                        <div class="text-right">
                            @if($storyHasOffer)
                                <div class="mb-1 flex items-center justify-end gap-2">
                                    <span class="rounded-full bg-pink-100 px-2.5 py-1 text-xs font-black text-pink-700">{{ $storyPricing->offerLabel() }}</span>
                                    <span class="text-lg font-bold text-slate-400 line-through">{{ format_money($storyRegularPrice) }}</span>
                                </div>
                            @endif
                            <span class="text-4xl font-extrabold text-indigo-600">{{ format_money($storyPrice) }}</span>
                        </div>
                        <div class="flex gap-2 flex-wrap justify-end">
                            @if($story->age_range)
                                <span class="bg-indigo-50 text-indigo-700 text-sm font-bold px-3 py-1.5 rounded-full">👶
                                    {{ format_age_range($story->age_range) }}</span>
                            @endif
                            <span
                                class="bg-slate-100 text-slate-700 text-sm font-bold px-3 py-1.5 rounded-full">{{ $story->language == 'ar' ? 'عربي' : 'إنجليزي' }}</span>
                        </div>
                    </div>

                    @if($story->publicBookletPreview?->isPubliclyAvailable() && $story->publicBookletPreview->publicUrl())
                        <a href="{{ $story->publicBookletPreview->publicUrl() }}"
                            target="_blank" rel="noopener noreferrer nofollow"
                            class="mb-6 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl border-2 border-indigo-200 bg-indigo-50 px-5 py-3 text-base font-black text-indigo-800 transition hover:border-indigo-300 hover:bg-indigo-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 sm:w-auto">
                            <span aria-hidden="true">📖</span>
                            معاينة القصة
                        </a>
                    @endif

                    <!-- Description -->
                    @if($storyDescription !== '')
                        <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-4 text-right" data-story-about>
                            <h2 class="font-black text-slate-900">عن القصة</h2>
                            <div class="relative mt-2">
                                <p id="story-about-text"
                                    class="overflow-hidden text-sm leading-7 text-slate-600 transition-[max-height] duration-500 ease-in-out md:max-h-none md:overflow-visible md:text-lg md:leading-8 md:transition-none"
                                    style="max-height: 5.25rem"
                                    data-story-about-text>
                                    {{ $storyDescription }}
                                </p>
                                <div class="pointer-events-none absolute inset-x-0 bottom-0 h-14 bg-gradient-to-b from-transparent to-white opacity-100 transition-opacity duration-500 md:hidden"
                                    data-story-about-fade></div>
                            </div>
                            <button type="button"
                                class="mt-1 inline-flex min-h-11 items-center gap-1 rounded-xl px-2 text-sm font-black text-indigo-700 hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 md:hidden"
                                aria-expanded="false"
                                aria-controls="story-about-text"
                                data-story-about-toggle>
                                <span data-story-about-label>عرض المزيد</span>
                                <span class="transition-transform duration-300" aria-hidden="true" data-story-about-icon>⌄</span>
                            </button>
                        </section>
                    @endif

                    <!-- Lesson -->
                    @if($story->lesson_value)
                        <div class="bg-amber-50 border border-amber-100 rounded-2xl p-5 mb-8">
                            <p class="text-amber-800 font-bold mb-1">💡 الدرس المستفاد من هذه القصة:</p>
                            <p class="text-amber-700">{{ $story->lesson_value }}</p>
                        </div>
                    @endif

                    <!-- What's included -->
                    <div class="mb-8 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm" data-story-includes>
                        <h3 class="font-bold text-slate-900 text-lg mb-4">ما يتضمنه الكتاب:</h3>
                        <div class="relative">
                            <ul id="story-includes-list"
                                class="space-y-3 overflow-hidden transition-[max-height] duration-500 ease-in-out md:max-h-none md:overflow-visible md:transition-none"
                                style="max-height: 7.5rem"
                                data-story-includes-list>
                                <li class="flex items-center gap-3 text-slate-700 justify-end"><span>اسم طفلك في كل صفحة من
                                        القصة</span><span
                                        class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span>
                                </li>
                                <li class="flex items-center gap-3 text-slate-700 justify-end"><span>وجه طفلك الحقيقي في
                                        رسومات الشخصية الرئيسية</span><span
                                        class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span>
                                </li>
                                <li class="flex items-center gap-3 text-slate-700 justify-end"><span>إهداء شخصي مطبوع في
                                        الصفحة الأولى</span><span
                                        class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span>
                                </li>
                                <li class="flex items-center gap-3 text-slate-700 justify-end"><span>طباعة احترافية عالية
                                        الجودة</span><span
                                        class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span>
                                </li>
                                <li class="flex items-center gap-3 text-slate-700 justify-end"><span>مراجعة تصميم قبل
                                        الطباعة (Preview)</span><span
                                        class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span>
                                </li>
                                <li class="flex items-center gap-3 text-slate-700 justify-end"><span>شحن لجميع محافظات
                                        مصر</span><span
                                        class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span>
                                </li>
                            </ul>
                            <div class="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-b from-transparent to-white opacity-100 transition-opacity duration-500 md:hidden"
                                data-story-includes-fade></div>
                        </div>
                        <button type="button"
                            class="mt-1 inline-flex min-h-11 items-center gap-1 rounded-xl px-2 text-sm font-black text-indigo-700 hover:text-indigo-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 md:hidden"
                            aria-expanded="false"
                            aria-controls="story-includes-list"
                            data-story-includes-toggle>
                            <span data-story-includes-label>عرض المزيد</span>
                            <span class="transition-transform duration-300" aria-hidden="true" data-story-includes-icon>⌄</span>
                        </button>
                    </div>

                    <!-- Delivery Time -->
                    <div class="bg-indigo-50 rounded-2xl p-5 flex items-center gap-4 justify-end">
                        <div class="text-right">
                            <p class="font-bold text-indigo-800 text-lg">متوسط وقت التوصيل: {{ delivery_range() }}</p>
                            <p class="text-indigo-600 text-sm">من تاريخ تأكيد الدفع وموافقتك على التصميم</p>
                        </div>
                        <span class="text-4xl flex-shrink-0">🚀</span>
                    </div>
                </div>

                {{-- ===== RIGHT: Order Form ===== --}}
                <div id="story-customization" class="scroll-mt-24 lg:sticky lg:top-24">
                    <div class="overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-xl">
                        <div class="border-b border-slate-100 bg-white px-4 py-4 text-right sm:px-6">
                            <h2 class="text-xl font-extrabold text-slate-900 sm:text-2xl">املا بيانات طفلك لطلب القصة</h2>
                        </div>
                        <div class="p-3 sm:p-5">

                        @if(session('success'))
                            <div
                                class="mb-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-center font-bold text-green-700">
                                {{ session('success') }}
                            </div>
                        @endif

                        @if($errors->any())
                            <div id="story-order-errors" data-scroll-on-load data-first-error-field="{{ $errors->keys()[0] ?? '' }}"
                                class="mb-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-right text-red-700"
                                tabindex="-1">
                                <p class="font-extrabold mb-2">يرجى مراجعة البيانات التالية:</p>
                                <ul class="space-y-1 text-sm list-disc list-inside">
                                    @foreach($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form id="story-order-form" action="{{ route('cart.store', $story->slug) }}" method="POST" novalidate data-story-order-form data-story-draft-key="herokid:story:{{ $story->slug }}:draft">
                            @csrf
                            <input type="hidden" name="upload_session_token" value="{{ $photoUploadConfig['sessionToken'] ?? '' }}">

                            <div class="mb-3 grid grid-cols-2 gap-2" aria-label="خطوات تخصيص القصة">
                                <div class="rounded-xl bg-indigo-600 px-3 py-2.5 text-center text-sm font-black text-white">
                                    <span class="block text-xs text-indigo-100">الخطوة ١</span>
                                    بيانات الطفل والصور
                                </div>
                                <div class="rounded-xl bg-indigo-50 px-3 py-2.5 text-center text-sm font-black text-indigo-800">
                                    <span class="block text-xs text-indigo-500">الخطوة ٢</span>
                                    إضافات اختيارية
                                </div>
                            </div>

                            {{-- SECTION 1: Child Info --}}
                            <details class="group mb-3 rounded-2xl border border-indigo-100 bg-white" open data-story-stage="1">
                                <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 rounded-2xl bg-indigo-50 px-3 py-2.5">
                                <div class="flex items-center gap-2 justify-end">
                                    <h3 class="text-base font-extrabold text-indigo-800">بيانات الطفل والصور</h3>
                                    <span class="rounded-full bg-pink-500 px-2.5 py-1 text-xs font-black text-white">مطلوب</span>
                                </div>
                                <span class="text-xs font-black text-indigo-600 group-open:rotate-180">⌄</span>
                                </summary>
                                <div class="space-y-3 p-3">
                                    <div>
                                        <label for="child_name" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">اسم
                                            الطفل <span class="text-red-500">*</span></label>
                                        <input id="child_name" type="text" name="child_name" value="{{ old('child_name') }}" required autocomplete="off"
                                            @if($errors->has('child_name')) aria-invalid="true" @endif
                                            class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                            placeholder="الاسم الأول للطفل">
                                        <x-input-error :messages="$errors->get('child_name')" class="mt-1" />
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label for="child_age"
                                                class="block text-sm font-bold text-slate-700 mb-1.5 text-right">العمر
                                                <span class="text-red-500">*</span></label>
                                            <select id="child_age" name="child_age" required
                                                @if($errors->has('child_age')) aria-invalid="true" @endif
                                                class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-center py-3">
                                                <option value="">اختر العمر</option>
                                                @foreach($ageOptions as $age)
                                                    <option value="{{ $age }}" @selected((string) old('child_age') === (string) $age)>{{ arabic_number($age) }} سنوات</option>
                                                @endforeach
                                            </select>
                                            <x-input-error :messages="$errors->get('child_age')" class="mt-1" />
                                        </div>
                                        <div>
                                            <label for="child_gender"
                                                class="block text-sm font-bold text-slate-700 mb-1.5 text-right">الجنس
                                                <span class="text-red-500">*</span></label>
                                            <select id="child_gender" name="child_gender" required
                                                @if($errors->has('child_gender')) aria-invalid="true" @endif
                                                class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3">
                                                <option value="">اختر...</option>
                                                <option value="boy" @selected(old('child_gender') == 'boy')>ولد 👦
                                                </option>
                                                <option value="girl" @selected(old('child_gender') == 'girl')>بنت 👧
                                                </option>
                                            </select>
                                            <x-input-error :messages="$errors->get('child_gender')" class="mt-1" />
                                        </div>
                                    </div>
                                    <div class="rounded-2xl border-2 border-dashed border-indigo-200 bg-indigo-50 p-3 text-right">
                                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                            <span class="rounded-full bg-pink-500 px-2.5 py-1 text-xs font-black text-white">مطلوب</span>
                                            <p class="font-bold text-indigo-800">📸 ارفع صورتين أو ٣ صور واضحة للوجه</p>
                                        </div>
                                        <input type="file" id="photos" multiple
                                            accept="image/*,.jpg,.jpeg,.png,.webp,.heic,.heif"
                                            class="sr-only"
                                            data-photo-input>
                                        <div data-photo-upload-ids></div>
                                        <div class="flex flex-col gap-2 sm:flex-row-reverse sm:items-center">
                                            <label for="photos"
                                                class="inline-flex min-h-11 cursor-pointer items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-extrabold text-white transition hover:bg-indigo-700">
                                                اختيار ٢ أو ٣ صور
                                            </label>
                                            <span class="text-sm font-semibold text-slate-500" data-photo-label>
                                                لم يتم اختيار صور
                                            </span>
                                        </div>
                                        <div class="mt-3 grid grid-cols-3 gap-2" data-photo-queue aria-live="polite"></div>
                                        <div class="mt-3 hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700" data-photo-global-error></div>
                                        <x-input-error :messages="$errors->get('photo_upload_ids')" class="mt-2" />
                                        <x-input-error :messages="$errors->get('photo_upload_ids.*')" class="mt-2" />
                                    </div>
                                    <button type="button" class="min-h-11 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-black text-white" data-story-next="2">التالي: الإضافات الاختيارية</button>
                                </div>
                            </details>

                            {{-- SECTION 2: Optional personalization and gift details --}}
                            <details class="group mb-3 rounded-2xl border border-slate-200 bg-slate-50" @if($errors->hasAny(['interests', 'gift_note', 'parent_notes'])) open @endif data-story-stage="2">
                                <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 rounded-2xl bg-slate-50 px-3 py-2.5">
                                <div class="flex items-center gap-2 justify-end">
                                    <div class="text-right">
                                        <h3 class="text-base font-extrabold text-indigo-800">إهداء واهتمامات وملاحظات</h3>
                                        <p class="text-xs font-bold text-emerald-700">اختياري — يمكنك الإضافة للسلة بدون فتح هذا القسم</p>
                                    </div>
                                </div>
                                <span class="text-xs font-black text-slate-500 group-open:rotate-180">⌄</span>
                                </summary>
                                <div class="space-y-3 p-3">
                                    <div>
                                        <label for="interests" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">اهتمامات
                                            الطفل (اختياري)</label>
                                        <input id="interests" type="text" name="interests" value="{{ old('interests') }}"
                                            class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                            placeholder="مثال: الفضاء، الديناصورات، كرة القدم">
                                        <x-input-error :messages="$errors->get('interests')" class="mt-1" />
                                    </div>
                                    <div>
                                        <label for="gift_note" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">إهداء
                                            يُطبع في الصفحة الأولى (اختياري)</label>
                                        <textarea id="gift_note" name="gift_note" rows="2"
                                            class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                            placeholder="إلى ابني الغالي... أنت بطلنا الحقيقي ❤️">{{ old('gift_note') }}</textarea>
                                        <x-input-error :messages="$errors->get('gift_note')" class="mt-1" />
                                    </div>
                                    <div>
                                        <label for="parent_notes" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">ملاحظات
                                            إضافية للفريق (اختياري)</label>
                                        <textarea id="parent_notes" name="parent_notes" rows="2"
                                            class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                            placeholder="أي تفاصيل إضافية تريد إضافتها...">{{ old('parent_notes') }}</textarea>
                                        <x-input-error :messages="$errors->get('parent_notes')" class="mt-1" />
                                    </div>
                                </div>
                            </details>

                            {{-- Submit --}}
                            <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2" data-story-cart-actions>
                                <button type="submit" name="next" value="cart" data-story-submit
                                    class="w-full flex justify-center items-center gap-3 py-4 px-6 rounded-2xl text-base font-extrabold text-white bg-indigo-600 hover:bg-indigo-700 shadow-xl shadow-indigo-200 transition hover:-translate-y-0.5 focus:ring-4 focus:ring-indigo-300">
                                    <span>إضافة للسلة وإتمام الطلب</span>
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                            d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                    </svg>
                                </button>
                                <button type="submit" name="next" value="stories" data-story-submit
                                    class="w-full flex justify-center items-center gap-3 py-4 px-6 rounded-2xl text-base font-extrabold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 border border-indigo-100 transition hover:-translate-y-0.5 focus:ring-4 focus:ring-indigo-200">
                                    <span>إضافة واختيار قصة أخرى</span>
                                </button>
                            </div>
                            <div class="mb-3 mt-3 hidden rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-right text-red-800" role="alert" aria-live="assertive" tabindex="-1" data-story-requirements>
                                <p class="font-black" data-story-requirements-title>أكمل البيانات المطلوبة لإضافة القصة</p>
                                <ul class="mt-2 list-inside list-disc text-sm leading-6" data-story-requirements-list></ul>
                            </div>
                            <p class="mt-2 text-center text-xs text-slate-400">
                                بيانات ولي الأمر وعنوان التوصيل يتم إدخالها مرة واحدة في السلة. السعر: {{ format_money($storyPrice) }}
                            </p>
                        </form>
                    </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            (() => {
                const about = document.querySelector('[data-story-about]');
                const text = about?.querySelector('[data-story-about-text]');
                const fade = about?.querySelector('[data-story-about-fade]');
                const toggle = about?.querySelector('[data-story-about-toggle]');
                const label = about?.querySelector('[data-story-about-label]');
                const icon = about?.querySelector('[data-story-about-icon]');
                if (!about || !text || !fade || !toggle || !label || !icon) return;

                let expanded = false;
                let collapsedHeight = 56;

                const update = () => {
                    const desktop = window.matchMedia('(min-width: 768px)').matches;
                    collapsedHeight = (Number.parseFloat(getComputedStyle(text).lineHeight) || 28) * 3;
                    const fullHeight = text.scrollHeight;
                    const needsExpansion = fullHeight > collapsedHeight + 1;

                    toggle.classList.toggle('hidden', desktop || !needsExpansion);
                    fade.classList.toggle('hidden', desktop || !needsExpansion);
                    text.style.maxHeight = `${desktop || expanded ? fullHeight : collapsedHeight}px`;
                    fade.classList.toggle('opacity-0', desktop || expanded);
                    fade.classList.toggle('opacity-100', !desktop && !expanded);
                    label.textContent = expanded ? 'عرض أقل' : 'عرض المزيد';
                    icon.classList.toggle('rotate-180', expanded);
                    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                };

                toggle.addEventListener('click', () => {
                    expanded = !expanded;
                    update();
                });

                window.addEventListener('resize', update, { passive: true });
                requestAnimationFrame(update);
            })();
        </script>
        <script>
            (() => {
                const includes = document.querySelector('[data-story-includes]');
                const list = includes?.querySelector('[data-story-includes-list]');
                const fade = includes?.querySelector('[data-story-includes-fade]');
                const toggle = includes?.querySelector('[data-story-includes-toggle]');
                const label = includes?.querySelector('[data-story-includes-label]');
                const icon = includes?.querySelector('[data-story-includes-icon]');
                if (!includes || !list || !fade || !toggle || !label || !icon) return;

                let expanded = false;
                let collapsedHeight = 80;

                const update = () => {
                    const items = Array.from(list.children);
                    const desktop = window.matchMedia('(min-width: 768px)').matches;
                    const lastVisibleItem = items[Math.min(2, items.length - 1)];
                    collapsedHeight = lastVisibleItem
                        ? lastVisibleItem.offsetTop + lastVisibleItem.offsetHeight
                        : list.scrollHeight;
                    const fullHeight = list.scrollHeight;
                    const needsExpansion = items.length > 3 && fullHeight > collapsedHeight + 1;

                    toggle.classList.toggle('hidden', desktop || !needsExpansion);
                    fade.classList.toggle('hidden', desktop || !needsExpansion);
                    list.style.maxHeight = `${desktop || expanded ? fullHeight : collapsedHeight}px`;
                    fade.classList.toggle('opacity-0', desktop || expanded);
                    fade.classList.toggle('opacity-100', !desktop && !expanded);
                    label.textContent = expanded ? 'عرض أقل' : 'عرض المزيد';
                    icon.classList.toggle('rotate-180', expanded);
                    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                };

                toggle.addEventListener('click', () => {
                    expanded = !expanded;
                    update();
                });

                window.addEventListener('resize', update, { passive: true });
                requestAnimationFrame(update);
            })();
        </script>
        <script>
            (() => {
                const config = @json($photoUploadConfig ?? []);
                const form = document.querySelector('[data-story-order-form]');
                const input = document.querySelector('[data-photo-input]');
                const queueEl = document.querySelector('[data-photo-queue]');
                const labelEl = document.querySelector('[data-photo-label]');
                const hiddenEl = document.querySelector('[data-photo-upload-ids]');
                const errorEl = document.querySelector('[data-photo-global-error]');
                const submitButtons = Array.from(document.querySelectorAll('[data-story-submit]'));
                const requirementsEl = document.querySelector('[data-story-requirements]');
                const requirementsTitle = document.querySelector('[data-story-requirements-title]');
                const requirementsList = document.querySelector('[data-story-requirements-list]');
                if (!form || !input || !queueEl || !hiddenEl) return;

                const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const minFiles = Number(config.minFiles || 2);
                const maxFiles = Number(config.maxFiles || 3);
                const maxSizeBytes = Number(config.maxSizeMb || 15) * 1024 * 1024;
                const concurrency = Math.max(1, Number(config.concurrency || 2));
                const maxLongEdge = Number(config.maxLongEdge || 2560);
                const jpegQuality = Math.min(1, Math.max(0.5, Number(config.jpegQuality || 90) / 100));
                const storageKey = `herokid:story:${@json($story->slug)}:photo-upload-ids`;
                const draftKey = form.dataset.storyDraftKey;
                const serverRejectedStoredUploads = @json($errors->has('photo_upload_ids') || $errors->has('photo_upload_ids.*'));
                const items = [];
                let activeUploads = 0;
                let submitLocked = false;
                let submitAttempted = false;

                const arabicStatus = {
                    waiting: 'في الانتظار',
                    preparing: 'تجهيز الصورة',
                    uploading: 'جاري الرفع',
                    uploaded: 'تم الرفع',
                    failed: 'فشل الرفع',
                    cancelled: 'ملغاة',
                };

                function showGlobalError(message) {
                    if (!message) {
                        errorEl?.classList.add('hidden');
                        if (errorEl) errorEl.textContent = '';
                        return;
                    }
                    if (errorEl) {
                        errorEl.textContent = message;
                        errorEl.classList.remove('hidden');
                    }
                }

                function uid() {
                    return crypto?.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
                }

                function updateHiddenInputs() {
                    const uploadedItems = items.filter(item => item.status === 'uploaded' && item.uploadId);
                    const ids = uploadedItems.map(item => item.uploadId);
                    hiddenEl.innerHTML = ids.map(id => `<input type="hidden" name="photo_upload_ids[]" value="${id.replaceAll('"', '&quot;')}">`).join('');
                    localStorage.setItem(storageKey, JSON.stringify(uploadedItems.map(item => ({
                        id: item.uploadId,
                        name: item.name,
                        previewUrl: item.serverPreviewUrl || item.previewUrl || null,
                    }))));
                    labelEl.textContent = ids.length === 0
                        ? `ارفع ${minFiles} صور على الأقل`
                        : (ids.length < minFiles
                            ? `تم رفع ${ids.length} صورة — أضف ${minFiles - ids.length} أخرى للمتابعة`
                            : `تم رفع ${ids.length} صورة — يمكنك المتابعة`);
                    updateSubmitState();
                }

                function missingRequirements() {
                    const hasUploading = items.some(item => ['waiting', 'preparing', 'uploading'].includes(item.status));
                    const hasFailed = items.some(item => item.status === 'failed');
                    const uploadedCount = items.filter(item => item.status === 'uploaded').length;
                    const missing = [];
                    if (!form.elements.child_name?.value.trim()) missing.push('اسم الطفل');
                    if (!form.elements.child_age?.value) missing.push('عمر الطفل');
                    if (!form.elements.child_gender?.value) missing.push('جنس الطفل');
                    if (uploadedCount < minFiles) missing.push(`${minFiles - uploadedCount} صورة إضافية للطفل`);
                    if (hasUploading) missing.push('انتظار اكتمال رفع الصور');
                    if (hasFailed) missing.push('إعادة محاولة الصورة الفاشلة أو حذفها');

                    return missing;
                }

                function updateSubmitState() {
                    const missing = missingRequirements();
                    submitButtons.forEach(button => {
                        button.disabled = submitLocked;
                        button.classList.toggle('opacity-60', submitLocked);
                        button.classList.toggle('cursor-not-allowed', submitLocked);
                    });

                    if (requirementsList) {
                        requirementsList.innerHTML = missing.length
                            ? missing.map(item => `<li>${item}</li>`).join('')
                            : '<li class="text-emerald-700">كل البيانات المطلوبة مكتملة — يمكنك الإضافة للسلة الآن.</li>';
                    }
                    if (requirementsTitle) {
                        requirementsTitle.textContent = missing.length
                            ? 'لا يمكن إضافة القصة بعد — أكمل التالي:'
                            : 'القصة جاهزة للإضافة للسلة';
                    }
                    requirementsEl?.classList.toggle('border-emerald-200', missing.length === 0);
                    requirementsEl?.classList.toggle('bg-emerald-50', missing.length === 0);
                    requirementsEl?.classList.toggle('text-emerald-800', missing.length === 0);
                    requirementsEl?.classList.toggle('border-red-200', missing.length > 0);
                    requirementsEl?.classList.toggle('bg-red-50', missing.length > 0);
                    requirementsEl?.classList.toggle('text-red-800', missing.length > 0);
                    requirementsEl?.classList.toggle('hidden', missing.length > 0 && !submitAttempted);
                }

                function rowTemplate(item) {
                    const escapedName = escapeHtml(item.name || 'صورة الطفل');
                    const escapedMessage = escapeHtml(item.message || '');
                    const isUploaded = item.status === 'uploaded';

                    return `
                        <div class="min-w-0 rounded-xl border border-indigo-100 bg-white p-2 shadow-sm" data-photo-row="${item.id}">
                            <div class="relative aspect-square overflow-hidden rounded-lg bg-slate-100">
                                ${item.previewUrl ? `<img src="${item.previewUrl}" alt="معاينة ${escapedName}" class="h-full w-full object-cover">` : '<div class="h-full w-full bg-slate-100"></div>'}
                                <span class="absolute start-1 top-1 max-w-[calc(100%_-_0.5rem)] truncate rounded-full bg-white/95 px-2 py-1 text-[10px] font-black text-indigo-700 shadow-sm" data-photo-status>${arabicStatus[item.status] || item.status}</span>
                                <div class="${isUploaded ? 'hidden' : ''} absolute inset-x-1 bottom-1 h-1.5 overflow-hidden rounded-full bg-white/80">
                                    <div class="h-full rounded-full bg-indigo-600 transition-all" data-photo-progress style="width: ${item.progress}%"></div>
                                </div>
                            </div>
                            <p class="mt-2 truncate text-center text-[11px] font-extrabold text-slate-700" title="${escapedName}">${escapedName}</p>
                            <p class="${isUploaded ? 'hidden' : ''} mt-1 line-clamp-2 text-center text-[10px] font-semibold leading-4 text-slate-500" data-photo-message>${escapedMessage}</p>
                            <div class="mt-1.5 grid gap-1">
                                <button type="button" data-photo-retry class="${item.status === 'failed' ? '' : 'hidden'} min-h-11 rounded-lg bg-indigo-600 px-2 py-2 text-[11px] font-bold text-white">إعادة المحاولة</button>
                                <button type="button" data-photo-remove class="min-h-11 rounded-lg bg-red-50 px-2 py-2 text-[11px] font-bold text-red-600 hover:bg-red-100">حذف</button>
                            </div>
                        </div>
                    `;
                }

                function escapeHtml(value) {
                    const element = document.createElement('span');
                    element.textContent = String(value);

                    return element.innerHTML;
                }

                function render() {
                    queueEl.innerHTML = items.map(rowTemplate).join('');
                    queueEl.querySelectorAll('[data-photo-row]').forEach(row => {
                        const item = items.find(candidate => candidate.id === row.dataset.photoRow);
                        row.querySelector('[data-photo-retry]')?.addEventListener('click', () => retryItem(item.id));
                        row.querySelector('[data-photo-remove]')?.addEventListener('click', () => removeItem(item.id));
                    });
                    updateHiddenInputs();
                }

                function patchItem(id, changes) {
                    const item = items.find(candidate => candidate.id === id);
                    if (!item) return;
                    Object.assign(item, changes);
                    render();
                }

                async function optimizeImage(file) {
                    if (window.HeroKidImageUpload?.prepare) {
                        return window.HeroKidImageUpload.prepare(file, { maxLongEdge, jpegQuality });
                    }

                    const type = file.type.toLowerCase();
                    if (!['image/jpeg', 'image/png', 'image/webp'].includes(type)) {
                        return file;
                    }
                    if (!window.createImageBitmap || !document.createElement('canvas').getContext) {
                        return file;
                    }

                    try {
                        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
                        const longEdge = Math.max(bitmap.width, bitmap.height);
                        if (longEdge <= maxLongEdge) {
                            bitmap.close?.();
                            return file;
                        }

                        const scale = maxLongEdge / longEdge;
                        const width = Math.round(bitmap.width * scale);
                        const height = Math.round(bitmap.height * scale);
                        const canvas = document.createElement('canvas');
                        canvas.width = width;
                        canvas.height = height;
                        canvas.getContext('2d').drawImage(bitmap, 0, 0, width, height);
                        bitmap.close?.();

                        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', jpegQuality));
                        if (!blob || blob.size > file.size) return file;
                        return new File([blob], file.name.replace(/\.[^.]+$/, '.jpg'), { type: 'image/jpeg' });
                    } catch {
                        return file;
                    }
                }

                function enqueue(files) {
                    showGlobalError('');
                    const remaining = maxFiles - items.filter(item => item.status !== 'cancelled').length;
                    if (remaining <= 0) {
                        showGlobalError(`يمكنك رفع ${maxFiles} صور كحد أقصى.`);
                        return;
                    }

                    Array.from(files).slice(0, remaining).forEach(file => {
                        if (file.size > maxSizeBytes) {
                            items.push({
                                id: uid(),
                                file,
                                name: file.name,
                                previewUrl: URL.createObjectURL(file),
                                status: 'failed',
                                progress: 0,
                                message: `حجم الصورة أكبر من ${config.maxSizeMb || 15} ميجا.`,
                            });
                            return;
                        }
                        items.push({
                            id: uid(),
                            file,
                            name: file.name,
                            previewUrl: URL.createObjectURL(file),
                            status: 'waiting',
                            progress: 0,
                            message: 'سيتم رفع الصورة تلقائياً.',
                            uploadId: null,
                            xhr: null,
                        });
                    });

                    render();
                    pumpQueue();
                }

                function pumpQueue() {
                    while (activeUploads < concurrency) {
                        const next = items.find(item => item.status === 'waiting');
                        if (!next) break;
                        uploadItem(next);
                    }
                    updateSubmitState();
                }

                async function uploadItem(item) {
                    activeUploads++;
                    patchItem(item.id, { status: 'preparing', progress: 3, message: 'جاري تجهيز الصورة قبل الرفع...' });
                    let preparedFile;
                    try {
                        preparedFile = await optimizeImage(item.file);
                    } catch (conversionError) {
                        activeUploads = Math.max(0, activeUploads - 1);
                        patchItem(item.id, {
                            status: 'failed',
                            progress: 0,
                            message: conversionError.message || 'تعذر تجهيز الصورة قبل الرفع.',
                        });
                        pumpQueue();
                        return;
                    }
                    patchItem(item.id, { status: 'uploading', progress: 8, message: 'جاري رفع الصورة...' });

                    const formData = new FormData();
                    formData.append('photo', preparedFile);
                    formData.append('upload_session_token', config.sessionToken);
                    formData.append('upload_batch_token', config.batchToken || '');

                    const xhr = new XMLHttpRequest();
                    item.xhr = xhr;
                    xhr.open('POST', config.uploadUrl);
                    xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.upload.addEventListener('progress', event => {
                        if (!event.lengthComputable) return;
                        const percent = Math.min(95, Math.max(10, Math.round((event.loaded / event.total) * 90)));
                        patchItem(item.id, { progress: percent });
                    });
                    xhr.onreadystatechange = () => {
                        if (xhr.readyState !== XMLHttpRequest.DONE) return;
                        activeUploads = Math.max(0, activeUploads - 1);
                        let body = {};
                        try { body = JSON.parse(xhr.responseText || '{}'); } catch {}

                        if (xhr.status >= 200 && xhr.status < 300 && body.id) {
                            patchItem(item.id, {
                                status: 'uploaded',
                                progress: 100,
                                uploadId: body.id,
                                serverPreviewUrl: body.preview_url || null,
                                previewUrl: body.preview_url || item.previewUrl,
                                message: 'تم رفع الصورة بنجاح.',
                            });
                        } else {
                            patchItem(item.id, {
                                status: 'failed',
                                progress: 0,
                                message: body.message || 'تعذر رفع الصورة. حاول مرة أخرى.',
                                retryable: body.retryable !== false && xhr.status >= 500,
                            });
                        }
                        pumpQueue();
                    };
                    xhr.onerror = () => {
                        activeUploads = Math.max(0, activeUploads - 1);
                        patchItem(item.id, {
                            status: 'failed',
                            progress: 0,
                            message: 'انقطع الاتصال أثناء رفع الصورة. حاول مرة أخرى.',
                            retryable: true,
                        });
                        pumpQueue();
                    };
                    xhr.send(formData);
                }

                function retryItem(id) {
                    patchItem(id, { status: 'waiting', progress: 0, message: 'سيتم إعادة رفع هذه الصورة فقط.', uploadId: null });
                    pumpQueue();
                }

                function removeItem(id) {
                    const index = items.findIndex(item => item.id === id);
                    if (index === -1) return;
                    const [item] = items.splice(index, 1);
                    item.xhr?.abort?.();
                    if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);
                    if (item.uploadId && config.deleteUrlTemplate) {
                        fetch(config.deleteUrlTemplate.replace('__ID__', item.uploadId), {
                            method: 'DELETE',
                            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                        }).catch(() => {});
                    }
                    render();
                    pumpQueue();
                }

                input.addEventListener('change', event => {
                    enqueue(event.target.files || []);
                    input.value = '';
                });

                form.querySelectorAll('[data-story-next]').forEach(button => {
                    button.addEventListener('click', () => {
                        const target = form.querySelector(`[data-story-stage="${button.dataset.storyNext}"]`);
                        form.querySelectorAll('[data-story-stage]').forEach(stage => {
                            stage.open = stage === target;
                        });
                        target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        target?.querySelector('input, select, textarea, button')?.focus({ preventScroll: true });
                    });
                });

                const draftFields = ['child_name', 'child_age', 'child_gender', 'interests', 'gift_note', 'parent_notes'];
                if (draftKey) {
                    try {
                        const draft = JSON.parse(sessionStorage.getItem(draftKey) || '{}');
                        draftFields.forEach(name => {
                            const field = form.elements[name];
                            if (field && !field.value && typeof draft[name] === 'string') field.value = draft[name];
                        });
                    } catch {}

                    const saveDraft = () => {
                        const draft = Object.fromEntries(draftFields.map(name => [name, form.elements[name]?.value || '']));
                        sessionStorage.setItem(draftKey, JSON.stringify(draft));
                        updateSubmitState();
                    };
                    form.addEventListener('input', saveDraft);
                    form.addEventListener('change', saveDraft);
                } else {
                    form.addEventListener('input', updateSubmitState);
                    form.addEventListener('change', updateSubmitState);
                }

                form.addEventListener('submit', event => {
                    if (submitLocked) {
                        event.preventDefault();
                        return;
                    }
                    const missing = missingRequirements();
                    if (missing.length > 0) {
                        event.preventDefault();
                        submitAttempted = true;
                        updateSubmitState();
                        const targetStage = form.querySelector('[data-story-stage="1"]');
                        if (targetStage) targetStage.open = true;
                        requirementsEl?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        requirementsEl?.focus({ preventScroll: true });
                        return;
                    }
                    if (!form.checkValidity()) {
                        event.preventDefault();
                        form.reportValidity();
                        return;
                    }
                    submitLocked = true;
                    if (draftKey) sessionStorage.removeItem(draftKey);
                    updateSubmitState();
                });

                try {
                    if (serverRejectedStoredUploads) {
                        localStorage.removeItem(storageKey);
                    }
                    const storedUploads = JSON.parse(localStorage.getItem(storageKey) || '[]');
                    if (Array.isArray(storedUploads) && storedUploads.length) {
                        storedUploads.slice(0, maxFiles).forEach(stored => {
                            const uploadId = typeof stored === 'string' ? stored : stored?.id;
                            if (!uploadId) return;
                            const previewUrl = typeof stored === 'object' && stored?.previewUrl
                                ? stored.previewUrl
                                : config.previewUrlTemplate?.replace('__ID__', uploadId);
                            items.push({
                                id: uid(),
                                name: typeof stored === 'object' && stored?.name
                                    ? stored.name
                                    : 'صورة مرفوعة سابقاً',
                                previewUrl: previewUrl || null,
                                serverPreviewUrl: previewUrl || null,
                                status: 'uploaded',
                                progress: 100,
                                uploadId,
                                message: 'تم استعادة الصورة المرفوعة بعد تحديث الصفحة.',
                            });
                        });
                        render();
                    } else {
                        updateSubmitState();
                    }
                } catch {
                    updateSubmitState();
                }
            })();
        </script>
    @endpush
</x-front-layout>
