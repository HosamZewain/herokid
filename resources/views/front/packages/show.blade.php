<x-front-layout>
    @php
        $packageUrl = route('shop.package.show', $pricingPackage);
        $packageDescription = trim((string) ($pricingPackage->description ?: 'اختر قصص ومنتجات باقة '.$pricingPackage->name.' بسعر موفر من HeroKid.'));
        $packagePriceCents = (int) round((float) $pricingPackage->price * 100);
        $configuredRegularCents = (int) round((float) $pricingPackage->regular_price * 100);
        $displayRegularCents = max($regularTotal, $configuredRegularCents);
        $savingCents = max(0, $displayRegularCents - $packagePriceCents);
        $packageImage = $pricingPackage->image_url ?: \App\Support\StoryCover::fallbackUrl();
        $packageFeatures = collect($pricingPackage->features ?? [])->filter()->take(4);
    @endphp

    <x-slot name="pageTitle">{{ $pricingPackage->name }} — باقة قصص أطفال مخصصة</x-slot>
    <x-slot name="pageDescription">{{ \Illuminate\Support\Str::limit($packageDescription, 155, '') }}</x-slot>
    <x-slot name="canonical">{{ $packageUrl }}</x-slot>
    <x-slot name="ogType">product</x-slot>
    @if($pricingPackage->image_url)
        <x-slot name="pageImage">{{ $pricingPackage->image_url }}</x-slot>
        <x-slot name="pageImageAlt">{{ $pricingPackage->name }}</x-slot>
        <x-slot name="ogImageWidth">900</x-slot>
        <x-slot name="ogImageHeight">900</x-slot>
    @endif

    @push('schema')
        @php
            $packageSchema = [
                '@context' => 'https://schema.org',
                '@graph' => [
                    [
                        '@type' => 'BreadcrumbList',
                        'itemListElement' => [
                            ['@type' => 'ListItem', 'position' => 1, 'name' => 'الرئيسية', 'item' => \App\Support\Seo::url('/')],
                            ['@type' => 'ListItem', 'position' => 2, 'name' => 'الباقات', 'item' => \App\Support\Seo::url('/packages')],
                            ['@type' => 'ListItem', 'position' => 3, 'name' => $pricingPackage->name, 'item' => $packageUrl],
                        ],
                    ],
                    [
                        '@type' => 'Product',
                        '@id' => $packageUrl.'#product',
                        'name' => $pricingPackage->name,
                        'description' => $packageDescription,
                        'image' => [$packageImage],
                        'sku' => 'PACKAGE-'.$pricingPackage->id,
                        'brand' => ['@type' => 'Brand', 'name' => 'HeroKid'],
                        'category' => 'باقات قصص أطفال مخصصة',
                        'offers' => [
                            '@type' => 'Offer',
                            'url' => $packageUrl,
                            'priceCurrency' => 'EGP',
                            'price' => number_format((float) $pricingPackage->price, 2, '.', ''),
                            'availability' => 'https://schema.org/InStock',
                            'itemCondition' => 'https://schema.org/NewCondition',
                        ],
                    ],
                ],
            ];
        @endphp
        <script type="application/ld+json">@json($packageSchema, \App\Support\Seo::jsonFlags())</script>
    @endpush

    @push('styles')
        <style>
            @media (max-width: 767px) {
                [data-floating-whatsapp] {
                    bottom: calc(5.75rem + env(safe-area-inset-bottom));
                }
            }
        </style>
    @endpush

    <div class="min-h-screen bg-gradient-to-b from-indigo-50 via-white to-white py-4 pb-28 sm:py-8 md:pb-12" dir="rtl">
        <div class="mx-auto max-w-6xl px-3 sm:px-6">
            <nav aria-label="مسار التنقل" class="mb-3 overflow-hidden text-xs font-bold text-slate-500 sm:mb-5 sm:text-sm">
                <ol class="flex items-center gap-2 whitespace-nowrap">
                    <li><a href="{{ route('home') }}" class="hover:text-indigo-700">الرئيسية</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('packages') }}" class="hover:text-indigo-700">الباقات</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="truncate text-slate-800" aria-current="page">{{ $pricingPackage->name }}</li>
                </ol>
            </nav>

            <header class="overflow-hidden rounded-[1.75rem] bg-gradient-to-bl from-indigo-950 via-violet-900 to-fuchsia-800 text-right text-white shadow-xl shadow-indigo-200/70">
                <div class="grid items-stretch md:grid-cols-[minmax(0,1fr)_360px]">
                    <div class="flex flex-col justify-center p-5 sm:p-8 lg:p-10">
                        <div class="flex flex-wrap items-center gap-2">
                            @if($pricingPackage->badge)<span class="rounded-full bg-amber-300 px-3 py-1 text-[11px] font-black text-amber-950 sm:text-xs">{{ $pricingPackage->badge }}</span>@endif
                            @if($discount = $pricingPackage->discountPercentage())<span class="rounded-full bg-emerald-400/20 px-3 py-1 text-[11px] font-black text-emerald-100 ring-1 ring-emerald-300/40">وفّر {{ arabic_number($discount) }}٪</span>@endif
                        </div>
                        <h1 class="mt-3 text-2xl font-black leading-tight sm:text-4xl">{{ $pricingPackage->name }}</h1>
                        <p class="mt-2 text-sm font-bold text-indigo-100 sm:text-base">{{ $pricingPackage->componentSummary() }}</p>
                        <p class="mt-3 max-w-2xl text-sm leading-7 text-indigo-100/90 sm:text-base sm:leading-8">{{ $packageDescription }}</p>

                        @if($packageFeatures->isNotEmpty())
                            <ul class="mt-4 grid gap-2 text-xs font-bold text-white sm:grid-cols-2 sm:text-sm">
                                @foreach($packageFeatures as $feature)<li class="flex items-start gap-2"><span class="mt-0.5 text-emerald-300" aria-hidden="true">✓</span><span>{{ $feature }}</span></li>@endforeach
                            </ul>
                        @endif

                        <div class="mt-5 flex flex-wrap items-end justify-between gap-4 rounded-2xl bg-white/10 p-4 backdrop-blur">
                            <div>
                                <p class="text-xs text-indigo-200">سعر الباقة النهائي</p>
                                <div class="mt-1 flex flex-wrap items-baseline gap-2">
                                    <strong class="text-3xl font-black">{{ format_money((float) $pricingPackage->price) }}</strong>
                                    @if($displayRegularCents > $packagePriceCents)<span class="text-sm font-bold text-indigo-200 line-through">{{ format_money($displayRegularCents / 100) }}</span>@endif
                                </div>
                                @if($savingCents > 0)<p class="mt-1 text-xs font-black text-emerald-300">توفير {{ format_money($savingCents / 100) }}</p>@endif
                            </div>
                            <a href="#package-customization" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-white px-5 py-3 text-sm font-black text-indigo-800 shadow-lg transition hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300">ابدأ اختيار الباقة</a>
                        </div>
                    </div>
                    <div class="order-first bg-indigo-900/40 p-3 md:order-last md:p-5">
                        <img src="{{ $packageImage }}" alt="{{ $pricingPackage->name }}" width="720" height="720" fetchpriority="high" class="mx-auto aspect-[4/3] h-full max-h-72 w-full rounded-2xl object-cover shadow-lg md:max-h-none md:aspect-auto">
                    </div>
                </div>
            </header>

            <section aria-label="خطوات طلب الباقة" class="mt-4 grid grid-cols-3 gap-2 rounded-2xl border border-indigo-100 bg-white p-3 shadow-sm sm:mt-6 sm:gap-4 sm:p-4">
                <div class="flex min-w-0 items-center gap-2"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-black text-white">١</span><span class="text-[11px] font-black text-slate-800 sm:text-sm">اختر القصص</span></div>
                <div class="flex min-w-0 items-center gap-2"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-fuchsia-500 text-xs font-black text-white">٢</span><span class="text-[11px] font-black text-slate-800 sm:text-sm">أضف بيانات الأطفال</span></div>
                <div class="flex min-w-0 items-center gap-2"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-xs font-black text-white">٣</span><span class="text-[11px] font-black text-slate-800 sm:text-sm">أدخل التوصيل</span></div>
            </section>

            @if($errors->any())
                <div class="mt-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700" role="alert">
                    <p class="mb-2 font-black">أكمل البيانات التالية:</p>
                    <ul class="list-inside list-disc space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <form id="package-customization" action="{{ route('cart.packages.store', $pricingPackage) }}" method="POST" enctype="multipart/form-data" class="mt-5 scroll-mt-24 space-y-4 sm:mt-6 sm:space-y-5" data-package-order-form>
                @csrf
                <input type="hidden" name="upload_session_token" value="{{ $photoUploadConfig['sessionToken'] ?? '' }}">

                @if($pricingPackage->story_count > 0)
                    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-100 p-4 sm:flex sm:items-center sm:justify-between sm:p-6">
                            <div><h2 class="text-xl font-black text-slate-950">اختر {{ $pricingPackage->story_count === 1 ? 'القصة وبيانات الطفل' : 'القصص وبيانات الأطفال' }}</h2><p class="mt-1 text-xs leading-6 text-slate-500 sm:text-sm">كل قصة لها بيانات طفلها وصورتان أو ٣ صور واضحة.</p></div>
                            <div class="mt-3 sm:mt-0 sm:w-52" aria-live="polite"><div class="flex items-center justify-between text-xs font-black text-indigo-700"><span>التقدم</span><span dir="ltr" data-package-progress-text>٠ / {{ arabic_number($pricingPackage->story_count) }}</span></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-indigo-100"><span class="block h-full w-0 rounded-full bg-gradient-to-l from-indigo-600 to-fuchsia-500 transition-all duration-300" data-package-progress-bar></span></div></div>
                        </div>
                        <div class="space-y-3 bg-slate-50/70 p-3 sm:space-y-4 sm:p-6">
                            @for($slot = 0; $slot < $pricingPackage->story_count; $slot++)
                                <fieldset class="rounded-2xl border border-indigo-100 bg-white p-3 shadow-sm transition sm:p-5" data-package-story-card>
                                    <legend class="sr-only">القصة {{ $slot + 1 }}</legend>
                                    <div class="mb-3 flex items-center justify-between gap-3"><h3 class="font-black text-indigo-950">القصة {{ arabic_number($slot + 1) }}</h3><span class="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-black text-amber-700" data-slot-status>تحتاج استكمال</span></div>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        @php $selectedStory = $stories->firstWhere('id', (int) old("stories.$slot.story_id")); @endphp
                                        <div class="sm:col-span-2" data-package-story-slot data-slot="{{ $slot }}">
                                            <span class="mb-1 block text-xs font-black text-slate-700 sm:text-sm">اختر القصة</span>
                                            <select name="stories[{{ $slot }}][story_id]" required class="w-full rounded-xl border-slate-300" data-package-story-select>
                                                <option value="">اختر قصة</option>
                                                @foreach($stories as $story)<option value="{{ $story->id }}" data-title="{{ $story->title }}" data-image="{{ $story->cover_url }}" @selected((string) old("stories.$slot.story_id") === (string) $story->id)>{{ $story->title }}</option>@endforeach
                                            </select>
                                            <button type="button" class="mt-1 hidden min-h-16 w-full items-center gap-3 rounded-2xl border-2 border-indigo-100 bg-indigo-50/40 p-2 text-right transition hover:border-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" data-open-story-picker>
                                                <span class="h-14 w-12 shrink-0 overflow-hidden rounded-lg bg-white"><x-story-cover-image :src="$selectedStory?->cover_url" alt="" class="{{ $selectedStory ? '' : 'hidden' }} h-full w-full object-cover" data-selected-story-image /></span>
                                                <span class="min-w-0 flex-1"><strong class="block truncate text-sm text-slate-950 sm:text-base" data-selected-story-title>{{ $selectedStory?->title ?: 'اضغط لاختيار القصة' }}</strong><span class="mt-0.5 block text-[11px] font-bold text-indigo-600">عرض الصور والاختيار</span></span>
                                                <span class="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-black text-white">اختيار</span>
                                            </button>
                                            <p class="mt-1 hidden text-xs font-bold text-red-600" data-story-picker-error>اختر قصة لهذه الخانة.</p>
                                        </div>
                                        <label for="package-child-name-{{ $slot }}"><span class="mb-1 block text-xs font-black text-slate-700 sm:text-sm">اسم الطفل</span><input id="package-child-name-{{ $slot }}" name="stories[{{ $slot }}][child_name]" value="{{ old("stories.$slot.child_name") }}" required autocomplete="given-name" class="min-h-11 w-full rounded-xl border-slate-300" data-package-required></label>
                                        <div class="grid grid-cols-2 gap-3"><label for="package-child-age-{{ $slot }}"><span class="mb-1 block text-xs font-black text-slate-700 sm:text-sm">العمر</span><select id="package-child-age-{{ $slot }}" name="stories[{{ $slot }}][child_age]" required class="min-h-11 w-full rounded-xl border-slate-300" data-package-required><option value="">اختر</option>@foreach(\App\Support\StoryAgeOptions::forPersonalization() as $age)<option value="{{ $age }}" @selected((string) old("stories.$slot.child_age") === (string) $age)>{{ $age }} سنوات</option>@endforeach</select></label><label for="package-child-gender-{{ $slot }}"><span class="mb-1 block text-xs font-black text-slate-700 sm:text-sm">الجنس</span><select id="package-child-gender-{{ $slot }}" name="stories[{{ $slot }}][child_gender]" required class="min-h-11 w-full rounded-xl border-slate-300" data-package-required><option value="">اختر</option><option value="boy" @selected(old("stories.$slot.child_gender") === 'boy')>ولد</option><option value="girl" @selected(old("stories.$slot.child_gender") === 'girl')>بنت</option></select></label></div>
                                        <div class="sm:col-span-2" data-package-uploader data-slot="{{ $slot }}"><label for="package-photos-{{ $slot }}" class="block rounded-2xl border border-dashed border-indigo-300 bg-indigo-50/50 p-3"><span class="flex items-center justify-between gap-3"><span><strong class="block text-xs font-black text-slate-800 sm:text-sm">صور الطفل</strong><span class="mt-0.5 block text-[11px] text-slate-500">اختر صورتين أو ٣ صور واضحة للوجه</span></span><span class="shrink-0 rounded-xl bg-white px-3 py-2 text-xs font-black text-indigo-700 shadow-sm">اختيار الصور</span></span><input id="package-photos-{{ $slot }}" type="file" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" class="sr-only" data-package-photo-input><span class="mt-1 hidden text-xs font-bold text-red-600" data-package-photo-error></span></label><div class="mt-2 flex flex-wrap gap-2" data-package-photo-previews></div><div data-package-photo-hidden></div></div>
                                    </div>
                                </fieldset>
                            @endfor
                        </div>
                    </section>
                @endif

                @if($pricingPackage->items->isNotEmpty())
                    <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                        <h2 class="text-xl font-black text-slate-950">المنتجات الموجودة في الباقة</h2>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            @foreach($pricingPackage->items as $item)
                                <div class="flex items-center gap-3 rounded-2xl bg-slate-50 p-3">
                                    <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white">@if($item->product?->featured_image_url)<img src="{{ $item->product->featured_image_url }}" alt="" class="h-full w-full object-cover">@else<span>🎁</span>@endif</div>
                                    <div><p class="font-black text-slate-900">{{ $item->product?->name_ar }}</p><p class="mt-1 text-xs text-slate-500">الكمية: {{ $item->quantity }} @if($item->variant) · {{ $item->variant->name_ar }} @endif</p></div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                <div class="fixed inset-x-2 bottom-2 z-40 flex items-center gap-3 rounded-2xl border border-indigo-200 bg-white/95 p-2.5 shadow-2xl backdrop-blur md:static md:inset-auto md:justify-between md:p-4">
                    <div class="min-w-24 text-right"><p class="text-[10px] text-slate-500 md:text-xs">سعر الباقة النهائي</p><p class="text-lg font-black text-indigo-700 md:text-2xl">{{ format_money((float) $pricingPackage->price) }}</p></div>
                    <button type="submit" class="min-h-12 flex-1 rounded-xl bg-gradient-to-l from-indigo-600 to-fuchsia-500 px-4 py-3 text-sm font-black text-white transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 md:flex-none md:px-8 md:text-base">إضافة الباقة إلى السلة</button>
                </div>
            </form>
        </div>
    </div>

    <dialog data-package-story-dialog aria-labelledby="package-story-dialog-title" class="m-auto max-h-[92dvh] w-[calc(100%-1rem)] max-w-4xl rounded-3xl p-0 shadow-2xl backdrop:bg-slate-950/70">
        <div class="sticky top-0 z-10 border-b border-slate-200 bg-white px-3 py-3 sm:px-6">
            <div class="flex items-center justify-between gap-4">
                <div><h2 id="package-story-dialog-title" class="text-lg font-black text-slate-950 sm:text-xl">اختر القصة</h2><p class="text-[11px] text-slate-500 sm:text-xs">مرتبة حسب الأكثر طلبًا. اضغط على القصة لإضافتها.</p></div>
                <button type="button" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xl font-black text-slate-700" data-close-story-picker aria-label="إغلاق">×</button>
            </div>
            <label class="relative mt-3 block">
                <span class="sr-only">ابحث باسم القصة</span>
                <input type="search" inputmode="search" autocomplete="off" placeholder="ابحث باسم القصة..." class="w-full rounded-2xl border-slate-200 bg-slate-50 py-3 pe-11 text-sm font-bold text-slate-800 focus:border-indigo-500 focus:ring-indigo-500" data-package-story-search>
                <span class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-slate-400" aria-hidden="true">⌕</span>
            </label>
        </div>
        <div class="grid gap-2 bg-slate-50 p-3 sm:grid-cols-2 sm:gap-3 sm:p-6 lg:grid-cols-3">
            @foreach($stories as $story)
                <button type="button" class="group flex min-h-20 items-center gap-3 rounded-2xl border-2 border-transparent bg-white p-2 text-right shadow-sm transition hover:border-indigo-500 focus:outline-none focus-visible:border-indigo-500 focus-visible:ring-2 focus-visible:ring-indigo-500" data-choose-story data-id="{{ $story->id }}" data-title="{{ $story->title }}" data-image="{{ $story->cover_url }}">
                    <span class="h-16 w-14 shrink-0 overflow-hidden rounded-xl bg-slate-100 sm:h-20 sm:w-16"><x-story-cover-image :src="$story->cover_url" :alt="$story->title" loading="lazy" width="160" height="200" class="h-full w-full object-cover" /></span>
                    <span class="min-w-0"><strong class="line-clamp-2 text-xs leading-6 text-slate-950 sm:text-sm">{{ $story->title }}</strong><span class="mt-1 block text-[11px] font-bold text-indigo-600 sm:text-xs">اختر هذه القصة</span></span>
                </button>
            @endforeach
            <p class="col-span-full hidden rounded-2xl bg-white px-5 py-10 text-center font-bold text-slate-500" data-package-story-empty>لا توجد قصة بهذا الاسم.</p>
        </div>
    </dialog>

    @push('scripts')
    <script>
    (() => {
        const config = @json($photoUploadConfig ?? []);
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const form = document.querySelector('[data-package-order-form]');
        const states = new Map();
        const escape = value => { const span = document.createElement('span'); span.textContent = value; return span.innerHTML; };
        const dialog = document.querySelector('[data-package-story-dialog]');
        const storySearch = dialog?.querySelector('[data-package-story-search]');
        const storyButtons = [...(dialog?.querySelectorAll('[data-choose-story]') || [])];
        const storyEmpty = dialog?.querySelector('[data-package-story-empty]');
        const progressText = form?.querySelector('[data-package-progress-text]');
        const progressBar = form?.querySelector('[data-package-progress-bar]');
        let activeStorySlot = null;

        const updateProgress = () => {
            const cards = [...document.querySelectorAll('[data-package-story-card]')];
            let complete = 0;
            cards.forEach(card => {
                const slot = card.querySelector('[data-package-story-slot]');
                const uploader = card.querySelector('[data-package-uploader]');
                const uploaded = states.get(Number(uploader?.dataset.slot))?.filter(item => item.status === 'done').length || 0;
                const fieldsReady = [...card.querySelectorAll('[data-package-required]')].every(field => Boolean(field.value));
                const ready = Boolean(slot?.querySelector('[data-package-story-select]')?.value) && fieldsReady && uploaded >= 2;
                if (ready) complete += 1;
                const status = card.querySelector('[data-slot-status]');
                if (status) {
                    status.textContent = ready ? 'مكتملة' : 'تحتاج استكمال';
                    status.className = ready
                        ? 'rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-black text-emerald-700'
                        : 'rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-black text-amber-700';
                }
            });
            if (progressText) progressText.textContent = `${complete.toLocaleString('ar-EG')} / ${cards.length.toLocaleString('ar-EG')}`;
            if (progressBar) progressBar.style.width = cards.length ? `${(complete / cards.length) * 100}%` : '100%';
        };

        document.querySelectorAll('[data-package-story-slot]').forEach(slot => {
            const select = slot.querySelector('[data-package-story-select]');
            const trigger = slot.querySelector('[data-open-story-picker]');
            const title = slot.querySelector('[data-selected-story-title]');
            const image = slot.querySelector('[data-selected-story-image]');
            const error = slot.querySelector('[data-story-picker-error]');
            select.required = false;
            select.classList.add('hidden');
            trigger.classList.remove('hidden');
            trigger.classList.add('flex');
            trigger.addEventListener('click', () => {
                activeStorySlot = slot;
                if (storySearch) {
                    storySearch.value = '';
                    storySearch.dispatchEvent(new Event('input'));
                }
                dialog?.showModal();
                window.setTimeout(() => storySearch?.focus(), 50);
            });
            select.addEventListener('change', () => {
                const option = select.options[select.selectedIndex];
                title.textContent = option?.dataset.title || 'اضغط لاختيار القصة';
                if (option?.dataset.image) {
                    image.dataset.originalSrc = option.dataset.image;
                    image.dataset.coverRetryState = 'original';
                    image.src = option.dataset.image;
                    image.classList.remove('hidden');
                } else {
                    image.removeAttribute('data-original-src');
                    image.classList.add('hidden');
                }
                error.classList.toggle('hidden', Boolean(select.value));
                updateProgress();
            });
        });
        form?.querySelectorAll('[data-package-required]').forEach(field => {
            field.addEventListener('input', updateProgress);
            field.addEventListener('change', updateProgress);
        });
        dialog?.querySelector('[data-close-story-picker]')?.addEventListener('click', () => dialog.close());
        dialog?.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
        storySearch?.addEventListener('input', () => {
            const term = storySearch.value.trim().toLocaleLowerCase('ar');
            let visible = 0;
            storyButtons.forEach(button => {
                const matches = !term || (button.dataset.title || '').toLocaleLowerCase('ar').includes(term);
                button.classList.toggle('hidden', !matches);
                if (matches) visible += 1;
            });
            storyEmpty?.classList.toggle('hidden', visible !== 0);
        });
        storyButtons.forEach(button => button.addEventListener('click', () => {
            const select = activeStorySlot?.querySelector('[data-package-story-select]');
            if (!select) return;
            select.value = button.dataset.id;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            dialog.close();
        }));

        document.querySelectorAll('[data-package-uploader]').forEach(uploader => {
            const slot = Number(uploader.dataset.slot);
            const input = uploader.querySelector('[data-package-photo-input]');
            const previews = uploader.querySelector('[data-package-photo-previews]');
            const hidden = uploader.querySelector('[data-package-photo-hidden]');
            const error = uploader.querySelector('[data-package-photo-error]');
            const items = [];
            states.set(slot, items);

            const render = () => {
                previews.innerHTML = items.map((item, index) => `<div class="relative w-20 rounded-xl border border-indigo-100 bg-white p-1 shadow-sm"><div class="aspect-square overflow-hidden rounded-lg bg-slate-100">${item.preview ? `<img src="${item.preview}" class="h-full w-full object-cover" alt="معاينة الصورة">` : ''}</div><button type="button" data-remove="${index}" class="absolute -start-1 -top-1 flex h-8 w-8 items-center justify-center rounded-full bg-red-600 text-sm font-black text-white shadow" aria-label="حذف الصورة">×</button><p class="mt-1 truncate text-center text-[9px] font-bold text-slate-600">${escape(item.name)}</p><p class="text-center text-[9px] font-black ${item.status === 'done' ? 'text-emerald-600' : item.status === 'failed' ? 'text-red-600' : 'text-indigo-600'}">${item.status === 'done' ? 'تم الرفع' : item.status === 'failed' ? 'فشل' : 'يرفع...'}</p></div>`).join('');
                hidden.innerHTML = items.filter(item => item.status === 'done').map(item => `<input type="hidden" name="stories[${slot}][photo_upload_ids][]" value="${item.id}">`).join('');
                previews.querySelectorAll('[data-remove]').forEach(button => button.addEventListener('click', () => {
                    const [removed] = items.splice(Number(button.dataset.remove), 1);
                    if (removed?.id) fetch(config.deleteUrlTemplate.replace('__ID__', removed.id), { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } }).catch(() => {});
                    render();
                }));
                updateProgress();
            };

            const upload = async file => {
                const item = { name: file.name, preview: URL.createObjectURL(file), status: 'uploading', id: null };
                items.push(item); render();
                try {
                    const prepared = window.HeroKidImageUpload?.prepare ? await window.HeroKidImageUpload.prepare(file, { maxLongEdge: config.maxLongEdge, jpegQuality: Number(config.jpegQuality || 90) / 100 }) : file;
                    const body = new FormData(); body.append('photo', prepared); body.append('upload_session_token', config.sessionToken); body.append('upload_batch_token', config.batchTokens[slot]);
                    const response = await fetch(config.uploadUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body });
                    const result = await response.json();
                    if (!response.ok || !result.id) throw new Error(result.message || 'فشل رفع الصورة.');
                    item.id = result.id; item.preview = result.preview_url || item.preview; item.status = 'done'; error.classList.add('hidden');
                } catch (exception) { item.status = 'failed'; error.textContent = exception.message || 'فشل رفع الصورة.'; error.classList.remove('hidden'); }
                render();
            };

            input.addEventListener('change', () => {
                const available = Math.max(0, 3 - items.length);
                const selected = Array.from(input.files);
                selected.slice(0, available).forEach(upload);
                input.value = '';
                if (selected.length > available) { error.textContent = 'الحد الأقصى ٣ صور.'; error.classList.remove('hidden'); }
            });
        });

        updateProgress();

        form?.addEventListener('submit', event => {
            const missingStory = [...document.querySelectorAll('[data-package-story-slot]')].find(slot => !slot.querySelector('[data-package-story-select]')?.value);
            if (missingStory) {
                event.preventDefault();
                missingStory.querySelector('[data-story-picker-error]')?.classList.remove('hidden');
                missingStory.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            const incomplete = [...states.values()].some(items => items.filter(item => item.status === 'done').length < 2 || items.some(item => item.status === 'uploading'));
            if (!incomplete) return;
            event.preventDefault();
            const first = [...document.querySelectorAll('[data-package-uploader]')].find(element => states.get(Number(element.dataset.slot)).filter(item => item.status === 'done').length < 2 || states.get(Number(element.dataset.slot)).some(item => item.status === 'uploading'));
            const error = first?.querySelector('[data-package-photo-error]'); if (error) { error.textContent = 'انتظر اكتمال الرفع وتأكد من وجود صورتين على الأقل.'; error.classList.remove('hidden'); }
            first?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    })();
    </script>
    @endpush
</x-front-layout>
