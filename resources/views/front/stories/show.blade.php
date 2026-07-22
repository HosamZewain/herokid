<x-front-layout>
    @php
        $fallbackStoryCover = \App\Support\Seo::imageUrl('/images/site/featured_generic.png');
        $storyCoverUrl = $story->cover_url ?: $fallbackStoryCover;
        $storyPricing = app(\App\Services\Pricing\StoryPricingService::class);
        $storyRegularPrice = $storyPricing->regularPrice($story);
        $storyPrice = $storyPricing->effectivePrice($story);
        $storyHasOffer = $storyPricing->hasActiveOffer($story);
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

    <div class="bg-slate-50 min-h-screen py-12">
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
                                    {{ $story->age_range }}</span>
                            @endif
                            <span
                                class="bg-slate-100 text-slate-700 text-sm font-bold px-3 py-1.5 rounded-full">{{ $story->language == 'ar' ? 'عربي' : 'English' }}</span>
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="text-slate-600 leading-relaxed mb-6 text-lg">
                        {{ $story->full_desc ?: $story->short_desc }}
                    </div>

                    <!-- Lesson -->
                    @if($story->lesson_value)
                        <div class="bg-amber-50 border border-amber-100 rounded-2xl p-5 mb-8">
                            <p class="text-amber-800 font-bold mb-1">💡 الدرس المستفاد من هذه القصة:</p>
                            <p class="text-amber-700">{{ $story->lesson_value }}</p>
                        </div>
                    @endif

                    <!-- What's included -->
                    <div class="bg-white border border-slate-100 rounded-2xl p-6 mb-8 shadow-sm">
                        <h3 class="font-bold text-slate-900 text-lg mb-4">ما يتضمنه الكتاب:</h3>
                        <ul class="space-y-3">
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
                <div class="lg:sticky lg:top-24">
                    <div class="bg-white rounded-3xl p-8 shadow-xl border border-slate-100">
                        <div class="flex items-center gap-3 mb-8 justify-end">
                            <div class="text-right">
                                <h2 class="text-2xl font-extrabold text-slate-900">خصّص قصتك واطلبها</h2>
                                <p class="text-slate-500 text-sm mt-1">يستغرق التعبئة أقل من ٣ دقائق</p>
                            </div>
                            <div
                                class="w-12 h-12 bg-indigo-100 rounded-2xl flex items-center justify-center text-2xl flex-shrink-0">
                                ✍️</div>
                        </div>

                        @if(session('success'))
                            <div
                                class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-6 text-center font-bold">
                                {{ session('success') }}
                            </div>
                        @endif

                        @if($errors->any())
                            <div id="story-order-errors" data-scroll-on-load
                                class="bg-red-50 border border-red-200 text-red-700 px-4 py-4 rounded-xl mb-6 text-right"
                                tabindex="-1">
                                <p class="font-extrabold mb-2">يرجى مراجعة البيانات التالية:</p>
                                <ul class="space-y-1 text-sm list-disc list-inside">
                                    @foreach($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form action="{{ route('cart.store', $story->slug) }}" method="POST" novalidate data-story-order-form>
                            @csrf
                            <input type="hidden" name="upload_session_token" value="{{ $photoUploadConfig['sessionToken'] ?? '' }}">

                            {{-- SECTION 1: Child Info --}}
                            <div class="mb-6">
                                <div class="flex items-center gap-2 mb-4 justify-end">
                                    <h3 class="text-base font-extrabold text-indigo-800">بيانات البطل (الطفل)</h3>
                                    <span
                                        class="bg-pink-500 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0">١</span>
                                </div>
                                <div class="space-y-4">
                                    <div>
                                        <label for="child_name" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">اسم
                                            الطفل <span class="text-red-500">*</span></label>
                                        <input id="child_name" type="text" name="child_name" value="{{ old('child_name') }}" required
                                            class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                            placeholder="الاسم الأول للطفل">
                                        <x-input-error :messages="$errors->get('child_name')" class="mt-1" />
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label for="child_age"
                                                class="block text-sm font-bold text-slate-700 mb-1.5 text-right">العمر
                                                <span class="text-red-500">*</span></label>
                                            <input id="child_age" type="number" name="child_age" value="{{ old('child_age') }}"
                                                required min="1" max="18"
                                                class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-center py-3">
                                            <x-input-error :messages="$errors->get('child_age')" class="mt-1" />
                                        </div>
                                        <div>
                                            <label for="child_gender"
                                                class="block text-sm font-bold text-slate-700 mb-1.5 text-right">الجنس
                                                <span class="text-red-500">*</span></label>
                                            <select id="child_gender" name="child_gender" required
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
                                    <div>
                                        <label for="interests" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">اهتمامات
                                            الطفل (اختياري)</label>
                                        <input id="interests" type="text" name="interests" value="{{ old('interests') }}"
                                            class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                            placeholder="مثال: رائد فضاء، ديناصورات، كرة قدم...">
                                        <p class="text-xs text-slate-600 mt-1 text-right">سنحاول دمجها في القصة إن أمكن
                                        </p>
                                        <x-input-error :messages="$errors->get('interests')" class="mt-1" />
                                    </div>
                                </div>
                            </div>

                            <hr class="border-slate-100 my-6">

                            {{-- SECTION 2: Photos --}}
                            <div class="mb-6">
                                <div class="flex items-center gap-2 mb-4 justify-end">
                                    <h3 class="text-base font-extrabold text-indigo-800">صور الطفل</h3>
                                    <span
                                        class="bg-amber-500 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0">٢</span>
                                </div>
                                <div
                                    class="bg-indigo-50 border-2 border-dashed border-indigo-200 rounded-2xl p-6 text-right">
                                    <p class="font-bold text-indigo-800 mb-2">📸 ارفع ١–٥ صور واضحة للوجه</p>
                                    <ul class="text-xs text-indigo-600 space-y-1 mb-4">
                                        <li>• صور واضحة لوجه الطفل (بدون نظارة شمسية)</li>
                                        <li>• تقبل صور JPG وPNG وWebP وHEIC/HEIF — حد أقصى {{ arabic_number(config('photo_uploads.max_size_mb', 15)) }} ميجا للصورة</li>
                                        <li>• كلما كانت الصور أوضح، كانت الرسومات أجمل</li>
                                    </ul>
                                    <input type="file" id="photos" multiple
                                        accept="image/*,.jpg,.jpeg,.png,.webp,.heic,.heif"
                                        class="sr-only"
                                        data-photo-input>
                                    <div data-photo-upload-ids></div>
                                    <div class="flex flex-col sm:flex-row-reverse sm:items-center gap-3">
                                        <label for="photos"
                                            class="inline-flex justify-center items-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-extrabold text-white hover:bg-indigo-700 cursor-pointer transition">
                                            اختيار الصور
                                        </label>
                                        <span class="text-sm font-semibold text-slate-500" data-photo-label>
                                            لم يتم اختيار صور
                                        </span>
                                    </div>
                                    <div class="mt-4 space-y-3" data-photo-queue aria-live="polite"></div>
                                    <div class="mt-3 hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700" data-photo-global-error></div>
                                    <p class="mt-3 text-xs text-slate-500">سيتم رفع كل صورة وحدها قبل إرسال الطلب. يمكنك إعادة محاولة أي صورة فشلت بدون فقدان الصور الناجحة.</p>
                                    <x-input-error :messages="$errors->get('photo_upload_ids')" class="mt-2" />
                                    <x-input-error :messages="$errors->get('photo_upload_ids.*')" class="mt-2" />
                                </div>
                            </div>

                            <hr class="border-slate-100 my-6">

                            {{-- SECTION 3: Personalization & Gift --}}
                            <div class="mb-6">
                                <div class="flex items-center gap-2 mb-4 justify-end">
                                    <h3 class="text-base font-extrabold text-indigo-800">إضافات خاصة (اختياري)</h3>
                                    <span
                                        class="bg-green-600 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0">٣</span>
                                </div>
                                <div class="space-y-4">
                                    <div>
                                        <label for="gift_note" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">إهداء
                                            يُطبع في الصفحة الأولى</label>
                                        <textarea id="gift_note" name="gift_note" rows="2"
                                            class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                            placeholder="إلى ابني الغالي... أنت بطلنا الحقيقي ❤️">{{ old('gift_note') }}</textarea>
                                        <x-input-error :messages="$errors->get('gift_note')" class="mt-1" />
                                    </div>
                                    <div>
                                        <label for="parent_notes" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">ملاحظات
                                            إضافية للفريق</label>
                                        <textarea id="parent_notes" name="parent_notes" rows="2"
                                            class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                            placeholder="أي تفاصيل إضافية تريد إضافتها...">{{ old('parent_notes') }}</textarea>
                                        <x-input-error :messages="$errors->get('parent_notes')" class="mt-1" />
                                    </div>
                                </div>
                            </div>

                            {{-- Privacy Consent --}}
                            <div class="bg-yellow-50 border border-yellow-200 rounded-2xl p-5 mb-8">
                                <div class="flex items-start gap-3">
                                    <input id="privacy_consent" name="privacy_consent" type="checkbox" required
                                        class="mt-1 h-5 w-5 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500 flex-shrink-0">
                                    <label for="privacy_consent"
                                        class="text-sm text-slate-700 text-right cursor-pointer">
                                        <span class="font-bold block mb-1">موافقة صريحة على استخدام الصور</span>
                                        أوافق على استخدام صور طفلي المرفوعة حصرياً لإنشاء رسومات القصة المطبوعة لهذا
                                        الطلب. أؤكد أنني أمتلك الحق القانوني لرفع هذه الصور، وأوافق على حذفها من الخوادم
                                        بعد إتمام الطلب وفق <a href="{{ route('privacy') }}"
                                            class="underline text-indigo-600 hover:text-indigo-800"
                                            target="_blank">سياسة الخصوصية</a>.
                                    </label>
                                </div>
                                <x-input-error :messages="$errors->get('privacy_consent')" class="mt-2" />
                            </div>

                            {{-- Submit --}}
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-8">
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
                            <p class="text-center text-xs text-slate-400 mt-3">
                                بيانات ولي الأمر وعنوان التوصيل يتم إدخالها مرة واحدة في السلة. السعر: {{ format_money($storyPrice) }}
                            </p>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

    @push('scripts')
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
                if (!form || !input || !queueEl || !hiddenEl) return;

                const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                const maxFiles = Number(config.maxFiles || 5);
                const maxSizeBytes = Number(config.maxSizeMb || 15) * 1024 * 1024;
                const concurrency = Math.max(1, Number(config.concurrency || 2));
                const maxLongEdge = Number(config.maxLongEdge || 2560);
                const jpegQuality = Math.min(1, Math.max(0.5, Number(config.jpegQuality || 90) / 100));
                const storageKey = `herokid:story:${@json($story->slug)}:photo-upload-ids`;
                const serverRejectedStoredUploads = @json($errors->has('photo_upload_ids') || $errors->has('photo_upload_ids.*'));
                const items = [];
                let activeUploads = 0;
                let submitLocked = false;

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
                    const ids = items.filter(item => item.status === 'uploaded' && item.uploadId).map(item => item.uploadId);
                    hiddenEl.innerHTML = ids.map(id => `<input type="hidden" name="photo_upload_ids[]" value="${id.replaceAll('"', '&quot;')}">`).join('');
                    localStorage.setItem(storageKey, JSON.stringify(ids));
                    labelEl.textContent = ids.length ? `تم رفع ${ids.length} صورة` : 'لم يتم اختيار صور';
                    updateSubmitState();
                }

                function updateSubmitState() {
                    const hasUploading = items.some(item => ['waiting', 'preparing', 'uploading'].includes(item.status));
                    const hasFailed = items.some(item => item.status === 'failed');
                    const uploadedCount = items.filter(item => item.status === 'uploaded').length;
                    const disabled = submitLocked || hasUploading || hasFailed || uploadedCount < 1;
                    submitButtons.forEach(button => {
                        button.disabled = disabled;
                        button.classList.toggle('opacity-60', disabled);
                        button.classList.toggle('cursor-not-allowed', disabled);
                    });
                }

                function rowTemplate(item) {
                    return `
                        <div class="rounded-2xl border border-indigo-100 bg-white p-3 shadow-sm" data-photo-row="${item.id}">
                            <div class="flex gap-3 sm:flex-row-reverse">
                                <div class="h-20 w-20 flex-shrink-0 overflow-hidden rounded-xl bg-slate-100">
                                    ${item.previewUrl ? `<img src="${item.previewUrl}" alt="" class="h-full w-full object-cover">` : '<div class="h-full w-full bg-slate-100"></div>'}
                                </div>
                                <div class="min-w-0 flex-1 text-right">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="truncate text-sm font-extrabold text-slate-800">${item.name}</p>
                                        <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700" data-photo-status>${arabicStatus[item.status] || item.status}</span>
                                    </div>
                                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-full rounded-full bg-indigo-600 transition-all" data-photo-progress style="width: ${item.progress}%"></div>
                                    </div>
                                    <p class="mt-2 text-xs font-semibold text-slate-500" data-photo-message>${item.message || ''}</p>
                                    <div class="mt-3 flex flex-wrap justify-end gap-2">
                                        <button type="button" data-photo-retry class="${item.status === 'failed' ? '' : 'hidden'} rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white">إعادة المحاولة</button>
                                        <button type="button" data-photo-remove class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600">حذف</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
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
                    const file = await optimizeImage(item.file);
                    patchItem(item.id, { status: 'uploading', progress: 8, message: 'جاري رفع الصورة...' });

                    const formData = new FormData();
                    formData.append('photo', file);
                    formData.append('upload_session_token', config.sessionToken);

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

                form.addEventListener('submit', event => {
                    if (submitLocked) {
                        event.preventDefault();
                        return;
                    }
                    const hasUploading = items.some(item => ['waiting', 'preparing', 'uploading'].includes(item.status));
                    const hasFailed = items.some(item => item.status === 'failed');
                    const uploadedCount = items.filter(item => item.status === 'uploaded').length;
                    if (hasUploading || hasFailed || uploadedCount < 1) {
                        event.preventDefault();
                        showGlobalError(hasUploading
                            ? 'انتظر حتى يكتمل رفع كل الصور قبل إرسال الطلب.'
                            : 'يرجى رفع صورة واحدة ناجحة على الأقل وحذف أو إعادة محاولة الصور الفاشلة.');
                        queueEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return;
                    }
                    submitLocked = true;
                    updateSubmitState();
                });

                try {
                    if (serverRejectedStoredUploads) {
                        localStorage.removeItem(storageKey);
                    }
                    const storedIds = JSON.parse(localStorage.getItem(storageKey) || '[]');
                    if (Array.isArray(storedIds) && storedIds.length) {
                        storedIds.slice(0, maxFiles).forEach(id => items.push({
                            id: uid(),
                            name: 'صورة مرفوعة سابقاً',
                            previewUrl: null,
                            status: 'uploaded',
                            progress: 100,
                            uploadId: id,
                            message: 'تم الاحتفاظ برقم الصورة فقط بعد تحديث الصفحة.',
                        }));
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
