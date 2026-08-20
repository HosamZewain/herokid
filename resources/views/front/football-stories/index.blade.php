<x-front-layout>
    @php
        $minimumPrice = $stories->min(fn ($story) => $story['catalog']->price);
        $maximumPrice = $stories->max(fn ($story) => $story['catalog']->price);
        $selectedIds = collect(old('story_ids', []))->map(fn ($id) => (int) $id)->all();
        $seoImage = data_get($stories->first(), 'catalog.imageUrl') ?: asset('images/logo-320.png');
        $priceCopy = $stories->isEmpty()
            ? null
            : ($minimumPrice === $maximumPrice
                ? format_money($minimumPrice)
                : format_money($minimumPrice).' – '.format_money($maximumPrice));
        $collectionSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => 'قصص كرة القدم المخصصة للأطفال من HeroKid',
            'url' => route('football-stories.index'),
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => $stories->count(),
                'itemListElement' => $stories->values()->map(fn ($entry, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => [
                        '@type' => 'Product',
                        'name' => $entry['model']->title,
                        'description' => $entry['model']->short_desc,
                        'image' => $entry['catalog']->imageUrl,
                        'url' => route('stories.show', $entry['model']->slug),
                        'offers' => [
                            '@type' => 'Offer',
                            'priceCurrency' => 'EGP',
                            'price' => number_format($entry['catalog']->price, 2, '.', ''),
                            'availability' => 'https://schema.org/InStock',
                        ],
                    ],
                ])->all(),
            ],
        ];
        $footballFormState = [
            'selectedStoryIds' => $selectedIds,
            'oldInput' => [
                'childName' => old('child_name'),
                'childAge' => old('child_age'),
                'childGender' => old('child_gender'),
                'giftNote' => old('gift_note'),
                'interests' => old('interests'),
                'parentNotes' => old('parent_notes'),
            ],
        ];
    @endphp

    <x-slot name="pageTitle">قصص كرة القدم المخصصة باسم وصورة طفلك</x-slot>
    <x-slot name="pageDescription">اختار قصة كرة قدم أو أكثر لطفلك، وأضف اسمه وصوره مرة واحدة. راجع التصميم قبل الطباعة واطلب قصص HeroKid المخصصة بسهولة.</x-slot>
    <x-slot name="canonicalUrl">{{ route('football-stories.index') }}</x-slot>
    <x-slot name="pageImage">{{ $seoImage }}</x-slot>
    <x-slot name="pageImageAlt">قصص كرة القدم المخصصة للأطفال من HeroKid</x-slot>

    @push('schema')
        <script type="application/ld+json">@json($collectionSchema, \App\Support\Seo::jsonFlags())</script>
    @endpush

    <div data-football-landing class="min-h-screen overflow-x-clip bg-slate-50 pb-28 md:pb-12">
        <section class="relative overflow-hidden bg-gradient-to-br from-indigo-950 via-indigo-900 to-violet-900 text-white">
            <div class="absolute inset-0 opacity-20" aria-hidden="true"
                style="background-image:radial-gradient(circle at 20% 20%,#fff 0 1px,transparent 1.5px);background-size:24px 24px"></div>
            <div class="relative mx-auto grid min-h-[calc(100svh-5rem)] max-w-7xl items-center gap-8 px-4 py-10 sm:px-6 lg:grid-cols-[1.1fr_.9fr] lg:px-8 lg:py-14">
                <div class="text-center lg:text-right">
                    <span class="inline-flex rounded-full border border-white/20 bg-white/10 px-4 py-2 text-sm font-black text-indigo-50">
                        كتاب مطبوع مخصص باسم وصورة طفلك
                    </span>
                    <h1 class="mt-5 text-4xl font-black leading-tight sm:text-5xl lg:text-6xl">
                        خلي طفلك بطل قصة كرة القدم باسمه وصورته
                    </h1>
                    <p class="mx-auto mt-5 max-w-2xl text-base font-semibold leading-8 text-indigo-100 sm:text-lg lg:mx-0">
                        اختار قصة أو أكثر، أضف بيانات طفلك مرة واحدة، وراجع التصميم قبل الطباعة.
                    </p>

                    <div class="mt-6 grid grid-cols-2 gap-3 text-right sm:grid-cols-4">
                        <div class="rounded-2xl border border-white/15 bg-white/10 p-3 backdrop-blur-sm">
                            <span class="block text-xs text-indigo-200">السعر</span>
                            <strong class="mt-1 block text-sm">{{ $priceCopy ?: 'حسب القصة' }}</strong>
                        </div>
                        <div class="rounded-2xl border border-white/15 bg-white/10 p-3 backdrop-blur-sm">
                            <span class="block text-xs text-indigo-200">التوصيل</span>
                            <strong class="mt-1 block text-sm">{{ delivery_range() }}</strong>
                        </div>
                        <div class="rounded-2xl border border-white/15 bg-white/10 p-3 backdrop-blur-sm">
                            <span class="block text-xs text-indigo-200">المعاينة</span>
                            <strong class="mt-1 block text-sm">قبل الطباعة</strong>
                        </div>
                        <div class="rounded-2xl border border-white/15 bg-white/10 p-3 backdrop-blur-sm">
                            <span class="block text-xs text-indigo-200">الشحن</span>
                            <strong class="mt-1 block text-sm">يُحسب حسب المحافظة</strong>
                        </div>
                    </div>

                    <div class="mt-7 flex flex-col justify-center gap-3 sm:flex-row lg:justify-start">
                        <a href="#football-story-selection"
                            class="inline-flex min-h-14 items-center justify-center rounded-2xl bg-gradient-to-l from-orange-500 to-pink-500 px-7 py-3 text-lg font-black text-white shadow-xl shadow-pink-950/20 transition hover:-translate-y-0.5 focus:outline-none focus-visible:ring-4 focus-visible:ring-orange-200">
                            اختار قصص طفلك
                        </a>
                        <a href="#football-how-it-works"
                            class="inline-flex min-h-14 items-center justify-center rounded-2xl border border-white/25 bg-white/10 px-7 py-3 text-base font-black text-white transition hover:bg-white/15 focus:outline-none focus-visible:ring-4 focus-visible:ring-white/30">
                            اعرف خطوات الطلب
                        </a>
                    </div>
                    <p class="mt-4 text-sm font-bold leading-7 text-indigo-100">
                        الدفع المتاح:
                        {{ $paymentMethods !== [] ? implode('، ', $paymentMethods) : 'تظهر الطرق المتاحة عند متابعة الطلب' }}
                    </p>
                </div>

                <div class="mx-auto w-full max-w-lg">
                    <div class="grid grid-cols-2 gap-3 rounded-[2rem] border border-white/15 bg-white/10 p-3 shadow-2xl shadow-slate-950/30 backdrop-blur-sm">
                        @forelse($stories->take(4) as $entry)
                            <div class="overflow-hidden rounded-2xl bg-white/10 {{ $loop->first ? 'col-span-2' : '' }}">
                                @if($entry['catalog']->imageUrl)
                                    <img src="{{ $entry['catalog']->imageUrl }}" alt="غلاف {{ $entry['model']->title }}"
                                        width="640" height="800" fetchpriority="{{ $loop->first ? 'high' : 'auto' }}"
                                        class="{{ $loop->first ? 'aspect-[16/8]' : 'aspect-[4/3]' }} h-full w-full object-cover">
                                @else
                                    <div class="{{ $loop->first ? 'aspect-[16/8]' : 'aspect-[4/3]' }} flex h-full w-full items-center justify-center bg-white/10 p-6">
                                        <img src="{{ asset('images/logo-320.png') }}" alt="HeroKid" width="160" height="160" class="h-24 w-24 object-contain opacity-90">
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="col-span-2 flex min-h-64 items-center justify-center rounded-2xl bg-white/10 p-8 text-center font-bold text-indigo-100">
                                ستظهر قصص كرة القدم المتاحة هنا قريبًا.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        <section id="football-how-it-works" class="border-b border-slate-200 bg-white">
            <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach([
                        ['١', 'اختار القصص', 'اختار قصة واحدة أو أكثر لنفس الطفل.'],
                        ['٢', 'أضف بيانات الطفل', 'اكتب البيانات مرة واحدة وارفع صورتين أو ٣ صور.'],
                        ['٣', 'أدخل التوصيل', 'راجع السعر والشحن ثم أرسل الطلب للمراجعة.'],
                    ] as [$number, $title, $copy])
                        <div class="flex gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-right">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-lg font-black text-white">{{ $number }}</span>
                            <div>
                                <h2 class="font-black text-slate-950">{{ $title }}</h2>
                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ $copy }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <form method="POST" action="{{ route('football-stories.store') }}" data-football-form novalidate>
            @csrf
            <input type="hidden" name="upload_session_token" value="{{ $photoUploadConfig['sessionToken'] }}">
            <script type="application/json" data-football-upload-config>@json($photoUploadConfig)</script>

            <section id="football-story-selection" class="scroll-mt-28 py-10">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="mb-6 text-center">
                        <span class="text-sm font-black text-indigo-600">الخطوة ١ من ٣</span>
                        <h2 class="mt-2 text-3xl font-black text-slate-950">اختار قصة أو أكثر</h2>
                        <p class="mt-2 text-sm font-semibold text-slate-600">كل القصص المختارة تستخدم بيانات وصور الطفل نفسها.</p>
                    </div>

                    @error('story_ids')
                        <div data-field-error="story_ids" role="alert" class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 font-bold text-red-700">{{ $message }}</div>
                    @enderror

                    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                        @forelse($stories as $entry)
                            @php($story = $entry['model'])
                            @php($catalog = $entry['catalog'])
                            <article data-football-story-card data-story-id="{{ $story->id }}"
                                data-story-title="{{ $story->title }}" data-price="{{ $catalog->price }}"
                                data-regular-price="{{ $catalog->originalPrice ?: $catalog->price }}"
                                data-ages="{{ implode(',', $entry['recommended_ages']) }}"
                                class="group relative flex flex-col overflow-hidden rounded-3xl border-2 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-xl focus-within:ring-4 focus-within:ring-indigo-200 {{ in_array($story->id, $selectedIds, true) ? 'border-indigo-600 ring-4 ring-indigo-100' : 'border-slate-200' }}">
                                <input id="football_story_{{ $story->id }}" type="checkbox" name="story_ids[]" value="{{ $story->id }}"
                                    @checked(in_array($story->id, $selectedIds, true)) class="peer sr-only" data-story-checkbox>
                                <div class="relative aspect-[4/3] overflow-hidden bg-slate-100">
                                    @if($catalog->imageUrl)
                                        <img src="{{ $catalog->imageUrl }}" alt="غلاف {{ $story->title }}" width="640" height="480"
                                            loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]">
                                    @else
                                        <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-indigo-50 to-violet-100 p-8">
                                            <img src="{{ asset('images/logo-320.png') }}" alt="HeroKid" width="160" height="160" loading="lazy" class="h-28 w-28 object-contain opacity-80">
                                        </div>
                                    @endif
                                    <span data-selected-badge class="absolute start-3 top-3 inline-flex min-h-11 items-center rounded-full bg-white/95 px-4 text-sm font-black text-indigo-700 shadow {{ in_array($story->id, $selectedIds, true) ? '' : 'hidden' }}">✓ تم الاختيار</span>
                                    @if($catalog->offerLabel)
                                        <span class="absolute end-3 top-3 rounded-full bg-pink-600 px-3 py-1.5 text-xs font-black text-white">{{ $catalog->offerLabel }}</span>
                                    @endif
                                </div>
                                <div class="flex flex-1 flex-col p-5 text-right">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">{{ format_age_range($story->age_range ?: 'كل الأعمار') }}</span>
                                        <div class="text-left">
                                            <strong class="text-xl font-black text-indigo-700">{{ $catalog->priceLabel }}</strong>
                                            @if($catalog->originalPriceLabel)
                                                <del class="ms-1 text-xs font-bold text-slate-400">{{ $catalog->originalPriceLabel }}</del>
                                            @endif
                                        </div>
                                    </div>
                                    <h3 class="mt-3 text-xl font-black leading-8 text-slate-950">{{ $story->title }}</h3>
                                    <p class="mt-2 line-clamp-2 text-sm leading-6 text-slate-600">{{ $story->short_desc }}</p>
                                    @if($story->lesson_value)
                                        <p class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs font-bold leading-5 text-amber-900">القيمة: {{ $story->lesson_value }}</p>
                                    @endif
                                    <div class="mt-auto flex items-center justify-between gap-3 pt-4">
                                        <label for="football_story_{{ $story->id }}" data-select-copy
                                            class="inline-flex min-h-11 flex-1 cursor-pointer items-center justify-center rounded-xl bg-indigo-600 px-4 text-sm font-black text-white transition hover:bg-indigo-700">{{ in_array($story->id, $selectedIds, true) ? 'إلغاء الاختيار' : 'اختار القصة' }}</label>
                                        @if($story->publicBookletPreview?->isPubliclyAvailable() && $story->publicBookletPreview->publicScenesUrl())
                                            <a href="{{ $story->publicBookletPreview->publicScenesUrl() }}" target="_blank" rel="noopener"
                                                data-story-detail-link data-story-title="{{ $story->title }}"
                                                class="inline-flex min-h-11 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-3 text-sm font-black text-emerald-700 transition hover:bg-emerald-100">معاينة</a>
                                        @endif
                                        <a href="{{ route('stories.show', $story->slug) }}" target="_blank" rel="noopener"
                                            data-story-detail-link data-story-title="{{ $story->title }}"
                                            class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-black text-slate-700 transition hover:bg-slate-50">التفاصيل</a>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div class="sm:col-span-2 xl:col-span-3 rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                                <h3 class="text-xl font-black text-slate-900">لا توجد قصص كرة قدم متاحة الآن</h3>
                                <p class="mt-2 text-slate-600">يمكنك تصفح القصص والمنتجات الأخرى في المتجر.</p>
                                <a href="{{ route('shop.index', ['type' => 'stories']) }}" class="mt-5 inline-flex min-h-12 items-center rounded-xl bg-indigo-600 px-6 font-black text-white">عرض المتجر</a>
                            </div>
                        @endforelse
                    </div>

                    <div data-age-warning role="status" aria-live="polite" class="mt-5 hidden rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-bold leading-6 text-amber-900"></div>

                    <div class="mt-7 flex justify-center">
                        <button type="button" data-start-customization
                            class="inline-flex min-h-14 w-full max-w-xl items-center justify-center rounded-2xl bg-indigo-600 px-7 py-3 text-lg font-black text-white shadow-lg shadow-indigo-200 transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 disabled:cursor-not-allowed disabled:opacity-50">
                            متابعة وإدخال بيانات الطفل
                        </button>
                    </div>
                </div>
            </section>

            <section id="football-customization" class="scroll-mt-24 border-y border-slate-200 bg-white py-10" data-customization-section>
                <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                    <div class="mb-6 text-center">
                        <span class="text-sm font-black text-indigo-600">الخطوة ٢ من ٣</span>
                        <h2 class="mt-2 text-3xl font-black text-slate-950">بيانات الطفل وصوره مرة واحدة</h2>
                        <p class="mt-2 text-sm font-semibold text-slate-600">سنستخدم هذه البيانات في كل قصص كرة القدم التي اخترتها.</p>
                    </div>

                    @if($errors->any())
                        <div data-scroll-on-load data-first-error-field="{{ $errors->keys()[0] }}" role="alert" aria-live="assertive"
                            class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold leading-6 text-red-700" tabindex="-1">
                            راجع الحقل الموضح أدناه وأكمل البيانات المطلوبة.
                        </div>
                    @endif

                    <div class="grid gap-5 rounded-3xl border border-slate-200 bg-slate-50 p-5 sm:grid-cols-2 sm:p-7">
                        <div>
                            <label for="football_child_name" class="mb-2 block text-sm font-black text-slate-800">اسم الطفل الأول <span class="text-red-500">*</span></label>
                            <input id="football_child_name" name="child_name" value="{{ old('child_name') }}" required autocomplete="off"
                                @if($errors->has('child_name')) aria-invalid="true" aria-describedby="football_child_name_error" @endif
                                class="block min-h-12 w-full rounded-xl border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <x-input-error id="football_child_name_error" :messages="$errors->get('child_name')" class="mt-2" />
                        </div>
                        <div>
                            <label for="football_child_age" class="mb-2 block text-sm font-black text-slate-800">عمر الطفل <span class="text-red-500">*</span></label>
                            <select id="football_child_age" name="child_age" required data-child-age
                                @if($errors->has('child_age')) aria-invalid="true" aria-describedby="football_child_age_error" @endif
                                class="block min-h-12 w-full rounded-xl border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">اختر العمر</option>
                                @foreach($ageOptions as $age)
                                    <option value="{{ $age }}" @selected((int) old('child_age') === $age)>{{ arabic_number($age) }} سنوات</option>
                                @endforeach
                            </select>
                            <x-input-error id="football_child_age_error" :messages="$errors->get('child_age')" class="mt-2" />
                        </div>
                        <div class="sm:col-span-2">
                            <fieldset>
                                <legend class="mb-2 text-sm font-black text-slate-800">جنس الطفل <span class="text-red-500">*</span></legend>
                                <div class="grid grid-cols-2 gap-3">
                                    <label class="cursor-pointer rounded-xl border border-slate-300 bg-white p-3 text-center font-black has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50 has-[:checked]:text-indigo-700">
                                        <input type="radio" name="child_gender" value="boy" class="sr-only" @checked(old('child_gender') === 'boy')>
                                        ولد 👦
                                    </label>
                                    <label class="cursor-pointer rounded-xl border border-slate-300 bg-white p-3 text-center font-black has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50 has-[:checked]:text-indigo-700">
                                        <input type="radio" name="child_gender" value="girl" class="sr-only" @checked(old('child_gender') === 'girl')>
                                        بنت 👧
                                    </label>
                                </div>
                                <x-input-error :messages="$errors->get('child_gender')" class="mt-2" />
                            </fieldset>
                        </div>

                        <div class="sm:col-span-2 rounded-2xl border-2 border-dashed border-indigo-200 bg-indigo-50 p-4" data-football-photo-uploader>
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-black text-indigo-950">ارفع صورتين أو ٣ صور واضحة للوجه <span class="text-red-500">*</span></h3>
                                    <p class="mt-1 text-xs font-bold leading-5 text-indigo-700">وجه واضح، إضاءة جيدة، وبدون فلاتر. تعدد الزوايا يساعدنا على الحفاظ على ملامح الطفل.</p>
                                </div>
                                <span data-football-photo-count class="rounded-full bg-white px-3 py-1 text-xs font-black text-indigo-700">٠ / ٣</span>
                            </div>
                            <input type="file" id="football_photos" multiple accept="image/*,.jpg,.jpeg,.png,.webp,.heic,.heif" class="sr-only" data-football-photo-input>
                            <div data-football-photo-ids></div>
                            <label for="football_photos" class="mt-4 inline-flex min-h-12 cursor-pointer items-center justify-center rounded-xl bg-indigo-600 px-5 font-black text-white transition hover:bg-indigo-700 focus-within:ring-4 focus-within:ring-indigo-200">
                                اختيار صورتين أو ٣ صور
                            </label>
                            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3" data-football-photo-queue aria-live="polite"></div>
                            <div data-football-photo-error role="alert" class="mt-3 hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700"></div>
                            <x-input-error :messages="$errors->get('photo_upload_ids')" class="mt-2" />
                            <x-input-error :messages="$errors->get('photo_upload_ids.*')" class="mt-2" />
                            <p class="mt-4 text-xs font-semibold leading-6 text-slate-600">
                                تُستخدم الصور لتخصيص قصص هذا الطلب وتُحفظ في تخزين خاص لدعم تنفيذ الطلب وسجل الخدمة. يمكنك معرفة التفاصيل وحقوق الحذف في
                                <a href="{{ route('privacy') }}" target="_blank" class="font-black text-indigo-700 underline">سياسة الخصوصية</a>.
                            </p>
                        </div>

                        <details class="sm:col-span-2 rounded-2xl border border-slate-200 bg-white p-4">
                            <summary class="cursor-pointer list-none font-black text-slate-800 [&::-webkit-details-marker]:hidden">إهداء واهتمامات وملاحظات (اختياري) +</summary>
                            <div class="mt-4 grid gap-4">
                                <div>
                                    <label for="football_gift_note" class="mb-1.5 block text-sm font-bold text-slate-700">الإهداء</label>
                                    <textarea id="football_gift_note" name="gift_note" rows="2" maxlength="500" class="block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('gift_note') }}</textarea>
                                    <x-input-error :messages="$errors->get('gift_note')" class="mt-1" />
                                </div>
                                <div>
                                    <label for="football_interests" class="mb-1.5 block text-sm font-bold text-slate-700">اهتمامات الطفل</label>
                                    <textarea id="football_interests" name="interests" rows="2" maxlength="500" class="block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('interests') }}</textarea>
                                    <x-input-error :messages="$errors->get('interests')" class="mt-1" />
                                </div>
                                <div>
                                    <label for="football_parent_notes" class="mb-1.5 block text-sm font-bold text-slate-700">ملاحظات للفريق</label>
                                    <textarea id="football_parent_notes" name="parent_notes" rows="2" maxlength="1000" class="block w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">{{ old('parent_notes') }}</textarea>
                                    <x-input-error :messages="$errors->get('parent_notes')" class="mt-1" />
                                </div>
                            </div>
                        </details>
                    </div>

                    <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-right">
                        <h3 class="font-black text-emerald-950">بعد إضافة القصص إلى السلة</h3>
                        <p class="mt-1 text-sm font-semibold leading-6 text-emerald-800">ستدخل اسم ولي الأمر ورقم الهاتف والعنوان مرة واحدة، ويظهر سعر الشحن والإجمالي قبل تأكيد الطلب.</p>
                    </div>

                    <button type="submit" data-football-submit
                        class="mt-6 inline-flex min-h-14 w-full items-center justify-center rounded-2xl bg-gradient-to-l from-orange-500 to-pink-500 px-7 py-3 text-lg font-black text-white shadow-lg shadow-pink-200 transition hover:-translate-y-0.5 focus:outline-none focus-visible:ring-4 focus-visible:ring-orange-200">
                        إضافة القصص إلى السلة وإدخال التوصيل
                    </button>
                </div>
            </section>
        </form>

        <section class="py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="rounded-3xl bg-indigo-950 p-6 text-white sm:p-8">
                    <div class="grid gap-5 md:grid-cols-3">
                        <div>
                            <h2 class="text-xl font-black">مراجعة قبل الطباعة</h2>
                            <p class="mt-2 text-sm leading-7 text-indigo-100">نرسل لك المعاينة لتراجع التصميم وتطلب التصحيح قبل الموافقة والطباعة.</p>
                        </div>
                        <div>
                            <h2 class="text-xl font-black">الدفع المتاح</h2>
                            @if($paymentMethods !== [])
                                <p class="mt-2 text-sm leading-7 text-indigo-100">{{ implode('، ', $paymentMethods) }}</p>
                            @else
                                <p class="mt-2 text-sm leading-7 text-indigo-100">تظهر طرق الدفع المتاحة عند متابعة الطلب.</p>
                            @endif
                        </div>
                        <div>
                            <h2 class="text-xl font-black">التصحيح والعيوب</h2>
                            <p class="mt-2 text-sm leading-7 text-indigo-100">يمكن طلب تعديلات معقولة قبل اعتماد المعاينة، ويُعاد طباعة الكتاب عند وجود عيب مطبعي موثق.</p>
                        </div>
                    </div>
                    <div class="mt-6 flex flex-wrap gap-3 text-sm font-bold">
                        <a href="{{ route('terms') }}" target="_blank" class="underline decoration-indigo-400 underline-offset-4">الشروط وسياسة التصحيح</a>
                        <a href="{{ route('privacy') }}" target="_blank" class="underline decoration-indigo-400 underline-offset-4">حماية صور الطفل والبيانات</a>
                    </div>
                </div>
            </div>
        </section>

        @if($relatedProducts->isNotEmpty())
            <section class="border-t border-slate-200 bg-white py-12" data-related-products>
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <span class="text-sm font-black text-indigo-600">بعد اختيار قصة طفلك</span>
                            <h2 class="mt-1 text-3xl font-black text-slate-950">ممكن يعجب طفلك كمان</h2>
                        </div>
                        <a href="{{ route('shop.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-indigo-200 px-4 text-sm font-black text-indigo-700 hover:bg-indigo-50">عرض المزيد من القصص والمنتجات</a>
                    </div>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @foreach($relatedProducts as $product)
                            <a href="{{ route('shop.product.show', $product) }}" class="overflow-hidden rounded-2xl border border-slate-200 bg-white transition hover:-translate-y-0.5 hover:shadow-lg">
                                <div class="aspect-square bg-slate-100">
                                    @if($product->featured_image_url)
                                        <img src="{{ $product->featured_image_url }}" alt="{{ $product->name_ar }}" width="360" height="360" loading="lazy" class="h-full w-full object-cover">
                                    @else
                                        <div class="flex h-full items-center justify-center text-4xl" aria-hidden="true">📚</div>
                                    @endif
                                </div>
                                <div class="p-3 text-right">
                                    <h3 class="line-clamp-2 text-sm font-black leading-6 text-slate-900">{{ $product->name_ar }}</h3>
                                    <p class="mt-1 font-black text-indigo-700">{{ format_money($product->effectivePrice()) }}</p>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        <div data-football-sticky-summary class="fixed inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-50 mx-auto max-w-2xl rounded-2xl border border-indigo-200 bg-white/95 p-3 shadow-2xl shadow-slate-950/20 backdrop-blur sm:bottom-5">
            <div class="flex items-center gap-3">
                <div class="min-w-0 flex-1 text-right">
                    <p class="text-xs font-bold text-slate-500"><span data-selected-count>٠</span> قصة مختارة</p>
                    <p data-selected-names class="truncate text-sm font-black text-slate-900">اختار قصة للبدء</p>
                </div>
                <div class="shrink-0 text-left">
                    <span class="block text-xs font-bold text-slate-500">الإجمالي</span>
                    <strong data-selected-total class="text-lg font-black text-indigo-700">٠ ج.م</strong>
                </div>
                <button type="button" data-sticky-continue class="inline-flex min-h-12 shrink-0 items-center justify-center rounded-xl bg-indigo-600 px-4 text-sm font-black text-white disabled:cursor-not-allowed disabled:opacity-50">
                    متابعة
                </button>
            </div>
        </div>
    </div>

    @push('scripts')
        <script type="application/json" data-football-form-state>{{ Illuminate\Support\Js::from($footballFormState) }}</script>
    @endpush

    @push('styles')
        <style>
            @media (max-width: 767px) {
                body:has([data-football-landing]) [data-floating-whatsapp] {
                    bottom: calc(6rem + env(safe-area-inset-bottom));
                }
            }
        </style>
    @endpush
</x-front-layout>
