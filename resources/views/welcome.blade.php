<x-front-layout>

{{-- ══ Home page SEO ══ --}}
<x-slot name="pageTitle">{{ setting('seo_home_title', $settings['seo_home_title'] ?? '') }}</x-slot>
<x-slot name="pageDescription">{{ setting('seo_home_description', $settings['seo_home_description'] ?? '') }}</x-slot>

@php
    $homeStoryCount = \App\Models\Story::where('active', true)->count();
    $homeCategoryCount = \App\Models\StoryCategory::whereHas('stories', fn ($query) => $query->where('active', true))->count();
    $homeLanguages = \App\Models\Story::where('active', true)->whereNotNull('language')->distinct()->pluck('language');
    $homeLanguageCount = $homeLanguages->count();
    $homeLanguageLabel = match (true) {
        $homeLanguages->count() > 1 => 'متاحة بالعربية والإنجليزية',
        $homeLanguages->contains('ar') => 'متاحة بالعربية',
        $homeLanguages->contains('en') => 'متاحة بالإنجليزية',
        default => 'لغة القصة موضحة قبل الطلب',
    };
    $homeAgeRangeCount = count(setting_array('age_ranges')) ?: \App\Models\Story::where('active', true)->whereNotNull('age_range')->where('age_range', '!=', '')->distinct()->count('age_range');
    $storyPricing = app(\App\Services\Pricing\StoryPricingService::class);
    $homePricingStory = $featuredStories->first() ?? new \App\Models\Story(['price' => setting('price_soft_cover', 0)]);
    $homeStoryPrice = $storyPricing->effectivePrice($homePricingStory);

    // Cheapest entry point across the whole catalogue, not just the story library.
    $homeProductPrices = collect($featuredProducts ?? [])
        ->map(fn ($product) => $product->effectivePrice())
        ->filter(fn ($price) => $price > 0);
    $homeStartingPrice = $homeProductPrices->push($homeStoryPrice)->filter(fn ($price) => $price > 0)->min() ?: $homeStoryPrice;
    $homeProductCount = $homeProductCount ?? 0;
    $homeCatalogCount = $homeStoryCount + $homeProductCount;
@endphp

@if($faqs->count())
@push('schema')
@php
    $homeFaqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $faqs->map(fn ($faq) => [
            '@type' => 'Question',
            'name' => $faq->question,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $faq->answer,
            ],
        ])->values()->all(),
    ];
@endphp
<script type="application/ld+json">
@json($homeFaqSchema, \App\Support\Seo::jsonFlags())
</script>
@endpush
@endif

{{-- ══ Catalogue structured data: tells search engines the homepage lists
     personalized products, of which stories are one line ══ --}}
@php
    $homeCatalogEntries = collect();

    foreach ($featuredStories as $story) {
        $homeCatalogEntries->push([
            'name' => $story->title,
            'url' => route('stories.show', $story->slug),
            'image' => $story->cover_image ? \App\Support\Seo::imageUrl($story->cover_url) : null,
            'description' => $story->short_desc,
            'price' => $storyPricing->effectivePrice($story),
            'category' => 'قصص مخصصة',
        ]);
    }

    foreach ($featuredProducts as $product) {
        $homeCatalogEntries->push([
            'name' => $product->name_ar,
            'url' => route('shop.product.show', $product),
            'image' => $product->featured_image_url,
            'description' => $product->short_description_ar,
            'price' => $product->effectivePrice(),
            'category' => $product->category?->name_ar ?: 'منتجات مخصصة',
        ]);
    }

    $homeCatalogEntries = $homeCatalogEntries->take(12)->values();
@endphp

@if($homeCatalogEntries->isNotEmpty())
@push('schema')
@php
    $homeCatalogSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => 'منتجات HeroKid المخصصة',
        'numberOfItems' => $homeCatalogEntries->count(),
        'itemListElement' => $homeCatalogEntries->map(fn (array $entry, int $index) => array_filter([
            '@type' => 'ListItem',
            'position' => $index + 1,
            'item' => array_filter([
                '@type' => 'Product',
                'name' => $entry['name'],
                'url' => $entry['url'],
                'image' => $entry['image'],
                'description' => $entry['description'] ?: null,
                'category' => $entry['category'],
                'brand' => ['@type' => 'Brand', 'name' => 'HeroKid'],
                'offers' => $entry['price'] > 0 ? [
                    '@type' => 'Offer',
                    'price' => number_format((float) $entry['price'], 2, '.', ''),
                    'priceCurrency' => 'EGP',
                    'availability' => 'https://schema.org/InStock',
                    'url' => $entry['url'],
                ] : null,
            ]),
        ]))->values()->all(),
    ];
@endphp
<script type="application/ld+json">
@json($homeCatalogSchema, \App\Support\Seo::jsonFlags())
</script>
@endpush
@endif

    <style>
        /* Entry animations */
@verbatim
        @keyframes heroFadeUp {
            from { opacity: 0; transform: translateY(28px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes counterUp {
            from { opacity: 0; transform: scale(0.88); }
            to   { opacity: 1; transform: scale(1); }
        }
        .hero-text-anim { opacity:0; animation: heroFadeUp .9s ease forwards; }
        .hero-stat      { opacity:0; animation: counterUp .65s ease forwards; }
        @media (prefers-reduced-motion: reduce) {
            .hero-text-anim,
            .hero-stat,
            .h-float {
                opacity: 1 !important;
                animation: none !important;
                transform: none !important;
            }
        }

        /* Floating card */
        @keyframes h-float {
            0%,100% { transform: translate(-50%, 0)    rotate(0deg); }
            50%      { transform: translate(-50%, -14px) rotate(.8deg); }
        }
        .h-float { animation: h-float 5s ease-in-out infinite; }

        /* Confetti dots floating */
        @keyframes confetti-float {
            0%,100% { transform: translateY(0)    rotate(0deg);  }
            33%      { transform: translateY(-14px) rotate(6deg);  }
            66%      { transform: translateY(-7px)  rotate(-4deg); }
        }

        /* Pulsing dot badge */
        @keyframes dotBlink {
            0%,100% { opacity:1; transform:scale(1);   }
            50%      { opacity:.5; transform:scale(1.2); }
        }
        .dot-blink { animation: dotBlink 1.6s ease-in-out infinite; }

        /* Gentle spin for deco shapes */
        @keyframes slow-spin {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }

        /* Stat card hover lift */
        .stat-card { transition: transform .25s ease, box-shadow .25s ease; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 30px rgba(0,0,0,.1); }
@endverbatim
    </style>

    {{-- ═══════════════════════════════════════════
         HERO — Happy & Colorful
    ═══════════════════════════════════════════ --}}
    @if(homepage_section_enabled('hero'))
    <div data-home-section="hero" class="relative overflow-hidden" dir="rtl"
        style="background: linear-gradient(150deg, #fff7ed 0%, #fef3c7 38%, #f5f3ff 100%);">

        {{-- ── BACKGROUND LAYER ── --}}
        <div class="absolute inset-0 pointer-events-none overflow-hidden">

            {{-- Soft ambient circles --}}
            <div class="absolute -top-40 -right-40 w-[600px] h-[600px] rounded-full opacity-40"
                style="background: radial-gradient(circle, #fde68a, transparent 68%);"></div>
            <div class="absolute -bottom-32 -left-32 w-[500px] h-[500px] rounded-full opacity-30"
                style="background: radial-gradient(circle, #fbcfe8, transparent 68%);"></div>
            <div class="absolute top-1/3 left-1/3 w-[350px] h-[350px] rounded-full opacity-20"
                style="background: radial-gradient(circle, #bfdbfe, transparent 68%);"></div>

            {{-- Dot grid --}}
            <div class="absolute inset-0 opacity-20"
                style="background-image: radial-gradient(circle, #8b5cf6 1px, transparent 1px); background-size: 32px 32px;"></div>

            {{-- Large decorative spinning ring (top-left) --}}
            <div class="absolute -top-16 -left-16 w-64 h-64 rounded-full border-[16px] border-orange-200/40 opacity-60"
                style="animation: slow-spin 25s linear infinite;"></div>
            <div class="absolute bottom-20 right-10 w-40 h-40 rounded-full border-[10px] border-violet-200/40 opacity-50"
                style="animation: slow-spin 18s linear infinite reverse;"></div>

            {{-- Confetti: circles --}}
            <div class="absolute w-4 h-4 rounded-full bg-amber-400/70" style="top:11%;right:14%;animation:confetti-float 4s ease-in-out infinite;"></div>
            <div class="absolute w-2.5 h-2.5 rounded-full bg-violet-400/70" style="top:22%;right:6%;animation:confetti-float 5.2s ease-in-out infinite .5s;"></div>
            <div class="absolute w-3.5 h-3.5 rounded-full bg-orange-400/60" style="top:7%;left:18%;animation:confetti-float 6s ease-in-out infinite 1s;"></div>
            <div class="absolute w-2 h-2 rounded-full bg-amber-400/70" style="top:38%;right:4%;animation:confetti-float 4.5s ease-in-out infinite 1.5s;"></div>
            <div class="absolute w-3 h-3 rounded-full bg-violet-400/60" style="bottom:28%;left:8%;animation:confetti-float 5.5s ease-in-out infinite .8s;"></div>
            <div class="absolute w-2 h-2 rounded-full bg-orange-400/60" style="bottom:18%;right:18%;animation:confetti-float 4s ease-in-out infinite 2s;"></div>
            <div class="absolute w-5 h-5 rounded-full bg-amber-300/40" style="top:58%;left:22%;animation:confetti-float 7s ease-in-out infinite .3s;"></div>
            <div class="absolute w-2 h-2 rounded-full bg-violet-400/60" style="top:14%;left:38%;animation:confetti-float 5s ease-in-out infinite 1.2s;"></div>

            {{-- Confetti: diamonds --}}
            <div class="absolute w-3.5 h-3.5 bg-amber-300/50 rotate-45" style="top:43%;right:26%;animation:confetti-float 6s ease-in-out infinite 1.8s;"></div>
            <div class="absolute w-3 h-3 bg-violet-300/50 rotate-45" style="bottom:38%;left:28%;animation:confetti-float 5s ease-in-out infinite .4s;"></div>
            <div class="absolute w-2.5 h-2.5 bg-orange-300/50 rotate-12" style="top:68%;right:20%;animation:confetti-float 4.5s ease-in-out infinite 1.1s;"></div>
            <div class="absolute w-4 h-4 bg-amber-200/50 rotate-45" style="top:30%;left:12%;animation:confetti-float 6.5s ease-in-out infinite 2.3s;"></div>

            {{-- Confetti: stars --}}
            <svg class="absolute fill-current text-amber-400/60" style="top:17%;right:23%;animation:confetti-float 5s ease-in-out infinite .7s;" width="18" height="18" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>
            <svg class="absolute fill-current text-violet-400/50" style="bottom:33%;left:16%;animation:confetti-float 6s ease-in-out infinite 1.4s;" width="14" height="14" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>
            <svg class="absolute fill-current text-violet-400/40" style="top:48%;right:10%;animation:confetti-float 4s ease-in-out infinite .9s;" width="16" height="16" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>
            <svg class="absolute fill-current text-orange-400/50" style="top:28%;left:6%;animation:confetti-float 5s ease-in-out infinite 2.1s;" width="12" height="12" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>
        </div>

        {{-- SVG gradient defs --}}
        <svg width="0" height="0" class="absolute">
            <defs>
                <linearGradient id="heroGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%"   style="stop-color:#f97316;stop-opacity:1"/>
                    <stop offset="45%"  style="stop-color:#ec4899;stop-opacity:1"/>
                    <stop offset="100%" style="stop-color:#8b5cf6;stop-opacity:1"/>
                </linearGradient>
            </defs>
        </svg>

        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-14 pb-0">

            {{-- ════ TOP: Centered headline block ════ --}}
            <div class="text-center mb-10 hero-text-anim" style="animation-delay:.05s">

                {{-- Eyebrow badge --}}
                <div class="inline-flex items-center gap-2.5 px-5 py-2.5 mb-8 bg-white/80 backdrop-blur-sm border border-amber-200 rounded-full shadow-lg shadow-amber-100/40">
                    <span class="text-lg leading-none">✨</span>
                    <span class="text-sm font-black text-amber-800">{{ setting('home_badge_text', $settings['home_badge_text'] ?? '') }}</span>
                    <span class="text-lg leading-none">✨</span>
                </div>

                {{-- Mega headline --}}
                <h1 class="text-5xl sm:text-6xl lg:text-7xl font-extrabold leading-[1.1] mb-6 text-slate-900">
                    {{ setting('hero_title_1', $settings['hero_title_1'] ?? '') }}<br>
                    <span class="relative inline-block mt-1">
                        <span class="text-transparent bg-clip-text" style="background-image:linear-gradient(135deg,#f97316,#ec4899,#8b5cf6);">
                            {{ setting('hero_title_2', $settings['hero_title_2'] ?? '') }}
                        </span>
                        <svg class="absolute -bottom-3 right-0 w-full" height="10" viewBox="0 0 500 10" preserveAspectRatio="none">
                            <path d="M0,8 Q125,1 250,6 Q375,11 500,4" fill="none" stroke="url(#heroGrad)" stroke-width="4" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <span class="inline-block ml-2 animate-bounce" style="animation-duration:2s">🌟</span>
                </h1>

                {{-- Subtitle --}}
                <p class="text-lg md:text-xl text-slate-600 max-w-2xl mx-auto leading-relaxed mb-8">
                    {{ setting('hero_subtitle', $settings['hero_subtitle'] ?? '') }}
                    @if(delivery_range())
                        ويصلك خلال <strong class="text-orange-600">{{ delivery_range() }}</strong>.
                    @endif
                </p>

                {{-- Colorful feature pills --}}
                <div class="flex flex-wrap gap-3 justify-center mb-10">
                    <span class="inline-flex items-center gap-2 px-4 py-2 bg-orange-50 text-orange-700 text-sm font-bold rounded-full border border-orange-200 shadow-sm">🎨 {{ setting('home_feature_face', $settings['home_feature_face'] ?? '') }}</span>
                    <span class="inline-flex items-center gap-2 px-4 py-2 bg-pink-50 text-pink-700 text-sm font-bold rounded-full border border-pink-200 shadow-sm">📖 {{ setting('home_feature_values', $settings['home_feature_values'] ?? '') }}</span>
                    <span class="inline-flex items-center gap-2 px-4 py-2 bg-violet-50 text-violet-700 text-sm font-bold rounded-full border border-violet-200 shadow-sm">🚀 {{ setting('home_feature_delivery', $settings['home_feature_delivery'] ?? '') }}</span>
                    <span class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-50 text-emerald-700 text-sm font-bold rounded-full border border-emerald-200 shadow-sm">⏱ {{ delivery_range() }}</span>
                    <span class="inline-flex items-center gap-2 px-4 py-2 bg-sky-50 text-sky-700 text-sm font-bold rounded-full border border-sky-200 shadow-sm">🌐 {{ $homeLanguageLabel }}</span>
                </div>

                {{-- CTA buttons --}}
                <div class="flex flex-wrap gap-4 justify-center">
                    <a href="{{ route('shop.index') }}"
                        class="group inline-flex items-center gap-3 text-white font-black py-5 px-12 rounded-2xl shadow-2xl text-lg transition hover:-translate-y-1 active:scale-95"
                        style="background:linear-gradient(135deg,#f97316,#ec4899); box-shadow: 0 8px 30px rgba(249,115,22,.4);">
                        <span class="text-xl">🎁</span>
                        <span>تسوّق كل المنتجات</span>
                        <svg class="w-5 h-5 transform group-hover:-translate-x-1 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                    </a>
                    <a href="{{ route('shop.index', ['type' => 'stories']) }}"
                        class="inline-flex items-center gap-2 bg-white/90 backdrop-blur-sm text-slate-700 font-bold py-5 px-10 rounded-2xl border-2 border-slate-200 hover:border-orange-300 hover:shadow-xl transition hover:-translate-y-1 text-lg">
                        📚 القصص المخصصة
                    </a>
                    <a href="{{ route('how-it-works') }}"
                        class="inline-flex items-center gap-2 text-slate-500 font-bold py-5 px-6 rounded-2xl hover:text-slate-800 transition text-lg">
                        كيف يعمل؟ 🎬
                    </a>
                </div>
            </div>

            {{-- ════ BOTTOM: Visual + Stats ════ --}}
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-8 items-end">

                {{-- ── Stats & Trust (2 cols) ── --}}
                <div class="lg:col-span-2 space-y-4 pb-8 order-2 lg:order-1 hero-text-anim" style="animation-delay:.2s">

                    {{-- 3 stat cards --}}
                    <div class="grid grid-cols-3 gap-3">
                        <div class="stat-card bg-white/85 backdrop-blur-sm rounded-2xl p-4 text-center shadow-md border border-white">
                            <div class="text-3xl mb-1">📚</div>
                            <p class="text-2xl font-black text-slate-900 leading-none">{{ arabic_number($homeStoryCount) }}</p>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-wider mt-1">قصة</p>
                        </div>
                        @if($homeProductCount > 0)
                            <div class="stat-card bg-white/85 backdrop-blur-sm rounded-2xl p-4 text-center shadow-md border border-white">
                                <div class="text-3xl mb-1">🎁</div>
                                <p class="text-2xl font-black text-slate-900 leading-none">{{ arabic_number($homeProductCount) }}</p>
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-wider mt-1">منتج</p>
                            </div>
                        @else
                            <div class="stat-card bg-white/85 backdrop-blur-sm rounded-2xl p-4 text-center shadow-md border border-white">
                                <div class="text-3xl mb-1">⭐</div>
                                <p class="text-2xl font-black text-slate-900 leading-none">{{ setting('stat_rating', $settings['stat_rating'] ?? '') }}</p>
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-wider mt-1">تقييم</p>
                            </div>
                        @endif
                        <div class="stat-card bg-white/85 backdrop-blur-sm rounded-2xl p-4 text-center shadow-md border border-white">
                            <div class="text-3xl mb-1">👨‍👩‍👧</div>
                            <p class="text-2xl font-black text-slate-900 leading-none">{{ setting('stat_orders', $settings['stat_orders'] ?? '') }}</p>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-wider mt-1">عائلة</p>
                        </div>
                    </div>

                    {{-- Trust / mini testimonial --}}
                    <div class="bg-white/85 backdrop-blur-sm rounded-2xl p-5 shadow-md border border-white text-right">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex gap-0.5">
                                @for($i=0;$i<5;$i++)
                                    <svg class="w-4 h-4 text-amber-400 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                @endfor
                            </div>
                            <div class="flex -space-x-2 rtl:space-x-reverse">
                                @foreach(['47','12','32','15','22'] as $av)
                                    <img src="https://i.pravatar.cc/100?img={{ $av }}" class="w-8 h-8 rounded-full border-2 border-white shadow object-cover" alt="">
                                @endforeach
                            </div>
                        </div>
                        <p class="text-slate-600 font-semibold text-sm leading-relaxed">"طفلي بقى يقرأها كل يوم! هدية لا تُنسى 🥹"</p>
                        <p class="text-slate-400 text-xs font-bold mt-1.5">— {{ setting('stat_orders', $settings['stat_orders'] ?? '') }} عائلة سعيدة</p>
                    </div>

                    {{-- Price badge strip --}}
                    <div class="flex gap-3">
                        <div class="flex-1 rounded-2xl p-4 text-white text-center font-black shadow-lg" style="background:linear-gradient(135deg,#f97316,#ec4899);">
                            <p class="text-[10px] opacity-80 uppercase tracking-wider mb-0.5">ابتداءً من</p>
                            <p class="text-xl">{{ format_money($homeStartingPrice) }}</p>
                        </div>
                        <div class="flex-1 rounded-2xl p-4 text-white text-center font-black shadow-lg" style="background:linear-gradient(135deg,#8b5cf6,#3b82f6);">
                            <p class="text-[10px] opacity-80 uppercase tracking-wider mb-0.5">تصلك خلال</p>
                            <p class="text-xl">{{ delivery_range(false) }}</p>
                        </div>
                    </div>

                </div>

                {{-- ── Story Cards Visual (3 cols) ── --}}
                <div class="lg:col-span-3 relative h-[300px] sm:h-[380px] md:h-[500px] order-1 lg:order-2">

                    {{-- Glow behind main card --}}
                    <div class="absolute pointer-events-none" style="top:40px;left:50%;transform:translateX(-50%);width:280px;height:360px;border-radius:2.5rem;background:radial-gradient(ellipse,rgba(249,115,22,.18),transparent 70%);filter:blur(40px);z-index:1;"></div>

                    {{-- MAIN CARD --}}
                    @php
                        // The main card stays the flagship story; the two satellite cards show
                        // products first so the hero visual reads "personalized products", not "books".
                        $heroCard = $featuredStories->first();
                        $heroProducts = collect($featuredProducts ?? []);
                        $card2 = $heroProducts->first();
                        $card3 = $heroProducts->skip(1)->first();
                        $card2IsProduct = (bool) $card2;
                        $card3IsProduct = (bool) $card3;
                        $card2 = $card2 ?: $featuredStories->skip(1)->first();
                        $card3 = $card3 ?: $featuredStories->skip(2)->first();
                        $card2Title = $card2IsProduct ? $card2?->name_ar : $card2?->title;
                        $card3Title = $card3IsProduct ? $card3?->name_ar : $card3?->title;
                        $card2Image = $card2IsProduct ? $card2?->featured_image_url : ($card2?->cover_image ? $card2->cover_url : null);
                        $card3Image = $card3IsProduct ? $card3?->featured_image_url : ($card3?->cover_image ? $card3->cover_url : null);
                        $card2Meta = $card2IsProduct ? $card2?->ageLabel() : ($card2?->age_range ? $card2->age_range.' سنة' : null);
                        $card3Meta = $card3IsProduct ? $card3?->ageLabel() : ($card3?->age_range ? $card3->age_range.' سنة' : null);
                    @endphp
                    <div class="h-float absolute z-20" style="top:20px;left:50%;transform:translateX(-50%);">
                        <div class="w-[220px] rounded-[2rem] overflow-hidden bg-white" style="box-shadow:0 24px 64px rgba(249,115,22,.25),0 8px 24px rgba(0,0,0,.12);">
                            <div class="relative h-[280px] overflow-hidden">
                                @if($heroCard && $heroCard->cover_image)
                                    <x-story-cover-image :src="$heroCard->cover_url" :alt="$heroCard->title" class="w-full h-full object-cover" />
                                @else
                                    <img src="{{ \App\Support\Seo::imageUrl($settings['img_hero_main'] ?? \App\Support\SiteImages::url('img_hero_main')) }}" alt="HeroKid" class="w-full h-full object-cover">
                                @endif
                                <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(15,23,42,.85) 0%,transparent 52%);"></div>
                                <div class="absolute top-3 right-3">
                                    <span class="px-3 py-1 text-[10px] font-black rounded-full text-white shadow-lg" style="background:linear-gradient(135deg,#f97316,#ec4899);">الأكثر مبيعاً 🔥</span>
                                </div>
                                <div class="absolute bottom-0 right-0 left-0 p-4 text-right">
                                    @if($heroCard)
                                        <p class="text-orange-300 text-[9px] font-black uppercase tracking-widest mb-0.5">{{ $heroCard->categories->first()->name ?? 'مغامرة' }}</p>
                                        <p class="text-white font-black text-base leading-snug">{{ $heroCard->title }}</p>
                                    @else
                                        <p class="text-white font-black text-base">قصة طفلك المخصصة</p>
                                    @endif
                                </div>
                            </div>
                            @if($heroCard)
                            <div class="px-4 py-3 flex items-center justify-between bg-white">
                                <span class="font-black text-lg" style="color:#f97316;">{{ format_money($heroCard->price) }}</span>
                                <span class="text-[9px] font-black px-2.5 py-1.5 rounded-xl" style="background:#fff7ed;color:#ea580c;">{{ $heroCard->age_range }} سنة</span>
                            </div>
                            @endif
                        </div>
                    </div>

                    {{-- CARD 2 --}}
                    <div class="absolute top-10 right-4 lg:right-8 z-10 hero-stat" style="animation-delay:.6s">
                        <div class="w-[125px] rounded-[1.5rem] overflow-hidden bg-white transform rotate-[-8deg] hover:rotate-[-3deg] transition-transform duration-700 opacity-90" style="box-shadow:0 10px 35px rgba(236,72,153,.2);">
                            <div class="h-[80px] overflow-hidden bg-gradient-to-br from-pink-100 to-rose-100">
                                @if($card2Image)
                                    <img src="{{ $card2Image }}" alt="{{ $card2Title }}" class="w-full h-full object-cover" loading="lazy">
                                @else
                                    <img src="{{ \App\Support\Seo::imageUrl($settings['img_hero_mini1'] ?? \App\Support\SiteImages::url('img_hero_mini1')) }}" class="w-full h-full object-cover" alt="منتج مخصص" loading="lazy">
                                @endif
                            </div>
                            <div class="p-2.5 text-right">
                                <p class="text-[9px] font-black text-pink-500 truncate">{{ $card2Title ?: 'كتاب أنشطة مخصص' }}</p>
                                @if($card2Meta)<p class="text-[8px] text-slate-400 font-bold">{{ $card2Meta }}</p>@endif
                            </div>
                        </div>
                    </div>

                    {{-- CARD 3 --}}
                    <div class="absolute top-20 left-4 lg:left-8 z-10 hero-stat" style="animation-delay:.9s">
                        <div class="w-[115px] rounded-[1.5rem] overflow-hidden bg-white transform rotate-[7deg] hover:rotate-[2deg] transition-transform duration-700 opacity-80" style="box-shadow:0 10px 35px rgba(139,92,246,.2);">
                            <div class="h-[75px] overflow-hidden bg-gradient-to-br from-violet-100 to-indigo-100">
                                @if($card3Image)
                                    <img src="{{ $card3Image }}" alt="{{ $card3Title }}" class="w-full h-full object-cover" loading="lazy">
                                @else
                                    <img src="{{ \App\Support\Seo::imageUrl($settings['img_hero_mini2'] ?? \App\Support\SiteImages::url('img_hero_mini2')) }}" class="w-full h-full object-cover" alt="هدية مخصصة" loading="lazy">
                                @endif
                            </div>
                            <div class="p-2 text-right">
                                <p class="text-[8px] font-black text-violet-500 truncate">{{ $card3Title ?: 'هدية مخصصة' }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- FLOATING: Quality badge --}}
                    <div class="absolute top-2 right-2 z-30 hero-stat" style="animation-delay:.55s">
                        <div class="bg-white/95 backdrop-blur-md p-2.5 rounded-2xl shadow-xl border border-orange-50 flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl flex items-center justify-center text-base shrink-0 bg-orange-50">✅</div>
                            <div>
                                <p class="text-[8px] text-slate-400 font-bold uppercase tracking-wider leading-none">جودة فاخرة</p>
                                <p class="text-[11px] font-black text-slate-800 leading-tight">ورق مقوى</p>
                            </div>
                        </div>
                    </div>

                    {{-- FLOATING: Shipping badge --}}
                    <div class="absolute bottom-14 left-2 z-30 hero-stat" style="animation-delay:.8s">
                        <div class="p-2.5 rounded-2xl shadow-xl flex items-center gap-2 text-white" style="background:linear-gradient(135deg,#10b981,#059669);">
                            <span class="text-lg leading-none">🚀</span>
                            <div>
                                <p class="text-[8px] text-white/70 font-bold uppercase tracking-wider leading-none">شحن سريع</p>
                                <p class="text-[11px] font-black leading-tight">{{ delivery_range(false) }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- FLOATING: Gift/price badge --}}
                    <div class="absolute top-1/2 left-0 z-30 hero-stat" style="animation-delay:1s">
                        <div class="p-2.5 rounded-2xl shadow-xl flex items-center gap-2 text-white" style="background:linear-gradient(135deg,#8b5cf6,#ec4899);">
                            <span class="text-lg leading-none">🎁</span>
                            <div>
                                <p class="text-[8px] text-white/70 font-bold uppercase tracking-wider leading-none">ابتداءً من</p>
                                <p class="text-[11px] font-black leading-tight">{{ format_money($homeStartingPrice) }}</p>
                            </div>
                        </div>
                    </div>

                </div>

            </div>
        </div>

        {{-- Colorful wave divider --}}
        <div class="relative overflow-hidden leading-none mt-10" style="height:64px;">
            <svg viewBox="0 0 1440 64" fill="none" xmlns="http://www.w3.org/2000/svg"
                class="absolute bottom-0 w-full h-full" preserveAspectRatio="none">
                <path d="M0 64L60 56C120 48 240 32 360 26C480 20 600 22 720 28C840 34 960 44 1080 48C1200 52 1320 50 1380 48L1440 46V64H0Z" fill="white"/>
            </svg>
        </div>

    </div>
    @endif

    {{-- PRODUCT FAMILIES --}}
    @if(homepage_section_enabled('categories'))
        @include('front._home-categories')
    @endif

    @if(homepage_section_enabled('child_identity') && setting('child_identity_enabled', '1') === '1')
        @include('front.child-identity._home-section')
    @endif

    {{-- FEATURED CATALOGUE (stories + products, tabbed) --}}
    @if(homepage_section_enabled('catalog'))
        @include('front._home-catalog')
    @endif

    {{-- Admin-curated product rows, shown below the unified catalogue. --}}
    @if(homepage_section_enabled('store') && isset($storeSections) && $storeSections->isNotEmpty())
        <section data-home-section="store" class="py-24 relative overflow-hidden" dir="rtl"
            style="background: linear-gradient(155deg, #fff7ed 0%, #ffedd5 50%, #fef3c7 100%);">
            <div class="absolute inset-0 pointer-events-none opacity-20"
                style="background-image: radial-gradient(circle, #8b5cf6 1px, transparent 1px); background-size: 32px 32px;"></div>
            <div class="absolute -top-14 -right-14 w-56 h-56 rounded-full border-[14px] border-violet-200/50 pointer-events-none"></div>
            <div class="absolute -bottom-10 -left-10 w-44 h-44 rounded-full border-[10px] border-violet-200/50 pointer-events-none"></div>

            <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-14">
                <div class="text-center max-w-2xl mx-auto">
                    <span class="inline-flex items-center gap-2 bg-indigo-100 text-indigo-800 font-black text-xs px-4 py-2 rounded-full border border-indigo-300 mb-4">🛍️ المتجر</span>
                    <h2 class="text-3xl sm:text-4xl font-black text-slate-950">{{ setting('home_store_section_title', $settings['home_store_section_title'] ?? '') }}</h2>
                    <div class="w-20 h-1.5 mx-auto mt-3 mb-4 rounded-full" style="background: linear-gradient(90deg, #6366f1, #a855f7);"></div>
                    <p class="text-slate-500 leading-8">{{ setting('home_store_section_subtitle', $settings['home_store_section_subtitle'] ?? '') }}</p>
                </div>

                @foreach($storeSections as $section)
                    @php
                        $sectionProducts = $section->category->activeProducts->take($section->max_products);
                    @endphp
                    @if($sectionProducts->isNotEmpty())
                        <div>
                            <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                <div class="text-right">
                                    <h3 class="text-2xl font-black text-slate-950">{{ $section->title_ar }}</h3>
                                    @if($section->subtitle_ar)
                                        <p class="mt-2 text-slate-500">{{ $section->subtitle_ar }}</p>
                                    @endif
                                </div>
                                <a href="{{ $section->cta_url ?: route('shop.category', $section->category) }}" class="inline-flex items-center justify-center rounded-2xl border border-indigo-100 bg-white px-5 py-3 text-sm font-black text-indigo-700 shadow-sm hover:bg-indigo-50">
                                    {{ $section->cta_text_ar ?: 'عرض الكل' }}
                                </a>
                            </div>
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                                @foreach($sectionProducts as $product)
                                    @include('front.shop._product-card', ['product' => $product])
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </section>
    @endif

    {{-- HOW IT WORKS --}}
    @if(homepage_section_enabled('how_it_works'))
    <section id="how-it-works" data-home-section="how_it_works" class="py-24 relative overflow-hidden" dir="rtl"
        style="background: linear-gradient(150deg, #1a1030 0%, #2e1065 55%, #1a1030 100%);">
        {{-- Star dot grid --}}
        <div class="absolute inset-0 pointer-events-none"
            style="background-image: radial-gradient(circle, rgba(167,139,250,.15) 1px, transparent 1px); background-size: 24px 24px;"></div>
        {{-- Ambient glow --}}
        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-[600px] h-[280px] rounded-full opacity-20 blur-3xl pointer-events-none"
            style="background: radial-gradient(circle, #7c3aed, transparent 70%);"></div>
        <div class="absolute bottom-0 right-0 w-80 h-80 rounded-full opacity-10 blur-3xl pointer-events-none"
            style="background: radial-gradient(circle, #ec4899, transparent 70%);"></div>

        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-16">
                <span class="inline-flex items-center gap-2 bg-violet-500/20 text-violet-300 font-black text-xs px-4 py-2 rounded-full border border-violet-500/30 mb-4">⚡ العملية</span>
                <h2 class="text-4xl font-extrabold text-white mt-1 mb-2">٣ خطوات وطلبك في الطريق!</h2>
                <div class="w-20 h-1 mx-auto rounded-full mb-4" style="background: linear-gradient(90deg, #a78bfa, #ec4899);"></div>
                <p class="text-lg text-violet-300">بضع دقائق منك، ومنتج لا يُنسى لهم.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 relative">
                {{-- Connecting gradient line --}}
                <div class="hidden md:block absolute top-[6.5rem] right-[16.5%] left-[16.5%] h-0.5 opacity-30"
                    style="background: linear-gradient(90deg, #7c3aed, #ec4899, #7c3aed);"></div>

                {{-- Step 1 --}}
                <div class="group relative text-center">
                    <div class="relative rounded-3xl overflow-hidden h-52 mb-5 shadow-2xl shadow-violet-900/60 border border-violet-500/20">
                        <img src="{{ \App\Support\Seo::imageUrl($settings['img_home_step1'] ?? \App\Support\SiteImages::url('img_home_step1')) }}"
                            alt="اختر المنتج" class="w-full h-full object-cover transition duration-700 group-hover:scale-105 opacity-55" loading="lazy">
                        <div class="absolute inset-0" style="background: linear-gradient(to top, rgba(109,40,217,.92) 0%, rgba(109,40,217,.4) 60%, transparent 100%);"></div>
                        <div class="absolute top-3 right-3 w-11 h-11 rounded-2xl flex items-center justify-center text-white font-extrabold text-xl shadow-lg"
                            style="background: linear-gradient(135deg, #f97316, #fbbf24); box-shadow: 0 4px 15px rgba(249,115,22,.5);">١</div>
                        <div class="absolute bottom-4 inset-x-0 text-center">
                            <p class="text-white font-extrabold text-lg drop-shadow-lg">اختر المنتج</p>
                        </div>
                    </div>
                    <div class="bg-violet-500/10 backdrop-blur-sm border border-violet-500/20 rounded-2xl p-5 text-right">
                        <span class="inline-block bg-violet-500/20 text-violet-300 font-black text-xs px-3 py-1 rounded-full mb-2">خطوة ١</span>
                        <p class="text-violet-200 leading-relaxed text-sm">تصفح المتجر واختر ما يناسب طفلك: قصة مخصصة، كتاب أنشطة، أو هدية.</p>
                    </div>
                </div>

                {{-- Step 2 --}}
                <div class="group relative text-center">
                    <div class="relative rounded-3xl overflow-hidden h-52 mb-5 shadow-2xl shadow-pink-900/60 border border-pink-500/20">
                        <img src="{{ \App\Support\Seo::imageUrl($settings['img_home_step2'] ?? \App\Support\SiteImages::url('img_home_step2')) }}"
                            alt="خصّص وأرسل" class="w-full h-full object-cover transition duration-700 group-hover:scale-105 opacity-55" loading="lazy">
                        <div class="absolute inset-0" style="background: linear-gradient(to top, rgba(190,24,93,.92) 0%, rgba(190,24,93,.4) 60%, transparent 100%);"></div>
                        <div class="absolute top-3 right-3 w-11 h-11 rounded-2xl flex items-center justify-center text-white font-extrabold text-xl shadow-lg"
                            style="background: linear-gradient(135deg, #ec4899, #f43f5e); box-shadow: 0 4px 15px rgba(236,72,153,.5);">٢</div>
                        <div class="absolute bottom-4 inset-x-0 text-center">
                            <p class="text-white font-extrabold text-lg drop-shadow-lg">خصّص وأرسل</p>
                        </div>
                    </div>
                    <div class="bg-pink-500/10 backdrop-blur-sm border border-pink-500/20 rounded-2xl p-5 text-right">
                        <span class="inline-block bg-pink-500/20 text-pink-300 font-black text-xs px-3 py-1 rounded-full mb-2">خطوة ٢</span>
                        <p class="text-pink-200 leading-relaxed text-sm">أضف اسم طفلك وارفع صورته مرة واحدة، ونستخدمها في كل منتج تختاره.</p>
                    </div>
                </div>

                {{-- Step 3 --}}
                <div class="group relative text-center">
                    <div class="relative rounded-3xl overflow-hidden h-52 mb-5 shadow-2xl shadow-amber-900/60 border border-amber-500/20">
                        <img src="{{ \App\Support\Seo::imageUrl($settings['img_home_step3'] ?? \App\Support\SiteImages::url('img_home_step3')) }}"
                            alt="استلم طلبك" class="w-full h-full object-cover transition duration-700 group-hover:scale-105 opacity-55" loading="lazy">
                        <div class="absolute inset-0" style="background: linear-gradient(to top, rgba(161,90,0,.92) 0%, rgba(161,90,0,.4) 60%, transparent 100%);"></div>
                        <div class="absolute top-3 right-3 w-11 h-11 rounded-2xl flex items-center justify-center text-white font-extrabold text-xl shadow-lg"
                            style="background: linear-gradient(135deg, #fbbf24, #f97316); box-shadow: 0 4px 15px rgba(245,158,11,.5);">٣</div>
                        <div class="absolute bottom-4 inset-x-0 text-center">
                            <p class="text-white font-extrabold text-lg drop-shadow-lg">استلم طلبك</p>
                        </div>
                    </div>
                    <div class="bg-amber-500/10 backdrop-blur-sm border border-amber-500/20 rounded-2xl p-5 text-right">
                        <span class="inline-block bg-amber-500/20 text-amber-300 font-black text-xs px-3 py-1 rounded-full mb-2">خطوة ٣</span>
                        <p class="text-amber-200 leading-relaxed text-sm">نطبع ونشحن إلى بابك مباشرة خلال {{ delivery_range() }}.</p>
                    </div>
                </div>
            </div>

            <div class="text-center mt-10">
                <p class="inline-flex flex-wrap items-center justify-center gap-2 rounded-2xl border border-white/15 bg-white/10 px-5 py-3 text-sm font-bold text-violet-200 backdrop-blur-sm">
                    <span class="text-base">⚡</span>
                    منتجات جاهزة لا تحتاج تخصيص؟ اشترِها مباشرة بدون أي خطوات إضافية.
                </p>
            </div>

            <div class="text-center mt-8">
                <a href="{{ route('how-it-works') }}"
                    class="inline-flex items-center gap-2 bg-white/10 backdrop-blur-sm text-white font-bold border border-white/20 py-3 px-8 rounded-xl hover:bg-white/20 transition">
                    اعرف أكثر عن العملية
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                </a>
            </div>
        </div>
    </section>
    @endif

    {{-- WHY PARENTS LOVE IT --}}
    @if(homepage_section_enabled('benefits'))
    <section data-home-section="benefits" class="py-24 relative overflow-hidden" dir="rtl"
        style="background: #ffffff;">
        <div class="absolute inset-0 pointer-events-none opacity-20"
            style="background-image: radial-gradient(circle, #8b5cf6 1px, transparent 1px); background-size: 36px 36px;"></div>
        <div class="absolute -top-16 -left-16 w-56 h-56 rounded-full border-[14px] border-orange-200/50 pointer-events-none"></div>
        <div class="absolute -bottom-12 -right-12 w-44 h-44 rounded-full border-[10px] border-violet-200/50 pointer-events-none"></div>

        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
                <div class="text-right order-2 lg:order-1">
                    <span class="inline-flex items-center gap-2 bg-emerald-100 text-emerald-800 font-black text-xs px-4 py-2 rounded-full border border-emerald-300 mb-4">💚 لماذا HeroKid؟</span>
                    <h2 class="text-4xl font-extrabold text-slate-900 mt-1 mb-2 leading-snug">لأن طفلك يستحق <br>أكثر من مجرد كتاب.</h2>
                    <div class="w-24 h-1.5 mb-8 rounded-full" style="background: linear-gradient(90deg, #10b981, #06b6d4);"></div>
                    <div class="space-y-4">
                        <div class="flex items-start gap-4 bg-white/70 backdrop-blur-sm rounded-2xl p-5 border border-emerald-100 hover:shadow-lg hover:shadow-emerald-100/60 transition">
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl shadow-md"
                                style="background: linear-gradient(135deg, #f97316, #fbbf24);">🎨</div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-lg mb-1">وجه طفلك في كل منتج</h3>
                                <p class="text-slate-500 text-sm leading-relaxed">رسومات مخصصة بالذكاء الاصطناعي تضع وجه طفلك في القصص وكتب الأنشطة والهدايا.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4 bg-white/70 backdrop-blur-sm rounded-2xl p-5 border border-emerald-100 hover:shadow-lg hover:shadow-emerald-100/60 transition">
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl shadow-md"
                                style="background: linear-gradient(135deg, #fbbf24, #f97316);">📖</div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-lg mb-1">منتجات بعيدًا عن الشاشات</h3>
                                <p class="text-slate-500 text-sm leading-relaxed">قصص بقيم تربوية أصيلة، وكتب أنشطة تنمّي مهارات طفلك بعيدًا عن الشاشات.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4 bg-white/70 backdrop-blur-sm rounded-2xl p-5 border border-emerald-100 hover:shadow-lg hover:shadow-emerald-100/60 transition">
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl shadow-md"
                                style="background: linear-gradient(135deg, #8b5cf6, #a78bfa);">🛡️</div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-lg mb-1">خصوصية محمية</h3>
                                <p class="text-slate-500 text-sm leading-relaxed">صور طفلك تُحفظ في تخزين خاص وتُستخدم فقط لتنفيذ الخدمة والطلب، ويمكنك ممارسة حقوق الحذف وفق سياسة الخصوصية.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4 bg-white/70 backdrop-blur-sm rounded-2xl p-5 border border-emerald-100 hover:shadow-lg hover:shadow-emerald-100/60 transition">
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl shadow-md"
                                style="background: linear-gradient(135deg, #8b5cf6, #ec4899);">🎁</div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-lg mb-1">هدية مثالية للمناسبات</h3>
                                <p class="text-slate-500 text-sm leading-relaxed">عيد ميلاد، نهاية سنة دراسية — هدية شخصية تحمل اسمه ووجهه.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 order-1 lg:order-2">
                    <div class="relative rounded-3xl overflow-hidden aspect-square shadow-xl shadow-emerald-200/50 group">
                        <img src="{{ \App\Support\Seo::imageUrl($settings['img_stat_books'] ?? \App\Support\SiteImages::url('img_stat_books')) }}"
                            alt="قصص" class="w-full h-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(4,120,87,.92) 0%,rgba(4,120,87,.3) 60%,transparent 100%);"></div>
                        <div class="absolute inset-0 flex flex-col items-center justify-end pb-6 text-center">
                            <p class="font-extrabold text-3xl text-white">{{ arabic_number($homeProductCount > 0 ? $homeCatalogCount : $homeStoryCount) }}</p>
                            <p class="text-emerald-200 text-sm mt-0.5">{{ $homeProductCount > 0 ? 'منتجات وقصص متاحة' : 'قصص متاحة' }}</p>
                        </div>
                        <div class="absolute top-3 right-3 w-9 h-9 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center text-xl">📚</div>
                    </div>
                    <div class="relative rounded-3xl overflow-hidden aspect-square shadow-xl shadow-teal-200/50 group">
                        <img src="{{ \App\Support\Seo::imageUrl($settings['img_stat_rating'] ?? \App\Support\SiteImages::url('img_stat_rating')) }}"
                            alt="تقييم" class="w-full h-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(14,116,144,.92) 0%,rgba(14,116,144,.3) 60%,transparent 100%);"></div>
                        <div class="absolute inset-0 flex flex-col items-center justify-end pb-6 text-center">
                            <p class="font-extrabold text-3xl text-white">{{ setting('stat_rating', $settings['stat_rating'] ?? '') }}</p>
                            <p class="text-cyan-200 text-sm mt-0.5">متوسط التقييم</p>
                        </div>
                        <div class="absolute top-3 right-3 w-9 h-9 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center text-xl">⭐</div>
                    </div>
                    <div class="relative rounded-3xl overflow-hidden aspect-square shadow-xl shadow-emerald-200/50 group">
                        <img src="{{ \App\Support\Seo::imageUrl($settings['img_stat_family'] ?? \App\Support\SiteImages::url('img_stat_family')) }}"
                            alt="عائلات سعيدة" class="w-full h-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(5,150,105,.92) 0%,rgba(5,150,105,.3) 60%,transparent 100%);"></div>
                        <div class="absolute inset-0 flex flex-col items-center justify-end pb-6 text-center">
                            <p class="font-extrabold text-3xl text-white">{{ setting('stat_orders', $settings['stat_orders'] ?? '') }}</p>
                            <p class="text-emerald-200 text-sm mt-0.5">عائلة سعيدة</p>
                        </div>
                        <div class="absolute top-3 right-3 w-9 h-9 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center text-xl">👨‍👩‍👧</div>
                    </div>
                    <div class="relative rounded-3xl overflow-hidden aspect-square shadow-xl shadow-teal-200/50 group">
                        <img src="{{ \App\Support\Seo::imageUrl($settings['img_stat_delivery'] ?? \App\Support\SiteImages::url('img_stat_delivery')) }}"
                            alt="توصيل" class="w-full h-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(8,145,178,.92) 0%,rgba(8,145,178,.3) 60%,transparent 100%);"></div>
                        <div class="absolute inset-0 flex flex-col items-center justify-end pb-6 text-center">
                            <p class="font-extrabold text-3xl text-white">{{ setting('stat_delivery', $settings['stat_delivery'] ?? delivery_range(false)) }}</p>
                            <p class="text-cyan-200 text-sm mt-0.5">متوسط التوصيل</p>
                        </div>
                        <div class="absolute top-3 right-3 w-9 h-9 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center text-xl">🚀</div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    @endif

    {{-- TESTIMONIALS (dynamic from DB) --}}
    @if(homepage_section_enabled('testimonials') && $testimonials->count())
        <section data-home-section="testimonials" class="py-24 relative overflow-hidden" dir="rtl"
            style="background: linear-gradient(155deg, #faf5ff 0%, #f3e8ff 50%, #fdf2f8 100%);">
            <div class="absolute inset-0 pointer-events-none opacity-20"
                style="background-image: radial-gradient(circle, #8b5cf6 1px, transparent 1px); background-size: 30px 30px;"></div>
            {{-- Large decorative quote --}}
            <div class="absolute top-4 right-12 text-[160px] leading-none font-serif text-rose-100/70 select-none pointer-events-none" aria-hidden="true">❝</div>
            <div class="absolute bottom-4 left-12 text-[120px] leading-none font-serif text-pink-100/60 select-none pointer-events-none" aria-hidden="true">❞</div>

            <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-2xl mx-auto mb-16">
                    <span class="inline-flex items-center gap-2 bg-rose-100 text-rose-700 font-black text-xs px-4 py-2 rounded-full border border-rose-200 mb-4">💬 آراء العملاء</span>
                    <h2 class="text-4xl font-extrabold text-slate-900 mt-1 mb-2">ماذا يقول الآباء؟</h2>
                    <div class="w-20 h-1.5 mx-auto rounded-full mb-4" style="background: linear-gradient(90deg, #f43f5e, #ec4899, #a855f7);"></div>
                    <p class="text-lg text-slate-500">حكايات حقيقية من عائلات أصبح أطفالها أبطالاً.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 text-right">
                    @foreach($testimonials->take(3) as $testimonial)
                        <div class="bg-white rounded-2xl p-8 shadow-lg shadow-rose-100/60 border border-rose-100 hover:shadow-xl hover:shadow-rose-200/50 hover:-translate-y-1 transition-all duration-300 flex flex-col relative overflow-hidden">
                            <div class="absolute top-0 inset-x-0 h-1.5 rounded-t-2xl" style="background: linear-gradient(90deg, #f43f5e, #ec4899, #a855f7);"></div>
                            <div class="flex items-center gap-0.5 mb-5 justify-end text-yellow-400 text-lg">
                                @for($i = 0; $i < $testimonial->rating; $i++)★@endfor
                                @for($i = $testimonial->rating; $i < 5; $i++)<span class="text-slate-200">★</span>@endfor
                            </div>
                            <p class="text-slate-600 leading-relaxed mb-6 text-sm flex-grow">"{{ $testimonial->review_text ?? $testimonial->message }}"</p>
                            <div class="flex items-center gap-3 pt-4 border-t border-rose-50 justify-end">
                                <div class="text-right">
                                    <p class="font-bold text-slate-900 text-sm">
                                        {{ $testimonial->reviewer_name ?? $testimonial->name }}
                                        @if($testimonial->reviewer_location)
                                            <span class="text-slate-400 font-normal"> — {{ $testimonial->reviewer_location }}</span>
                                        @endif
                                    </p>
                                    <p class="text-[10px] text-rose-400 font-black uppercase tracking-wider">قائد المغامرة</p>
                                </div>
                                @if($testimonial->reviewer_avatar || $testimonial->image)
                                    <img src="{{ \App\Support\Seo::imageUrl($testimonial->reviewer_avatar ?: Storage::url($testimonial->image)) }}"
                                        alt="{{ $testimonial->reviewer_name ?? $testimonial->name }}"
                                        class="w-11 h-11 rounded-full object-cover border-2 border-rose-200 flex-shrink-0">
                                @else
                                    <div class="w-11 h-11 rounded-full flex items-center justify-center text-white font-extrabold text-sm flex-shrink-0"
                                        style="background: linear-gradient(135deg, #f43f5e, #ec4899);">
                                        {{ mb_substr($testimonial->reviewer_name ?? $testimonial->name, 0, 1) }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- PRICING TEASER --}}
    @if(homepage_section_enabled('pricing'))
    <section data-home-section="pricing" class="py-24 relative overflow-hidden" dir="rtl"
        style="background: linear-gradient(150deg, #1a1030 0%, #2e1065 55%, #1a1030 100%);">
        <div class="absolute inset-0 pointer-events-none opacity-10"
            style="background-image: radial-gradient(circle, #8b5cf6 1px, transparent 1px); background-size: 28px 28px;"></div>
        <div class="absolute -top-10 left-1/2 -translate-x-1/2 w-[500px] h-56 blur-3xl opacity-20 rounded-full pointer-events-none"
            style="background: radial-gradient(circle, #6366f1, transparent);"></div>
        <div class="absolute bottom-0 left-0 w-80 h-80 blur-3xl opacity-10 rounded-full pointer-events-none"
            style="background: radial-gradient(circle, #ec4899, transparent);"></div>

        <div class="relative z-10 mx-auto w-full max-w-[90rem] px-4 sm:px-6 lg:px-10">
            <div class="text-center mb-14">
                <span class="inline-flex items-center gap-2 bg-indigo-500/20 text-indigo-300 font-black text-xs px-4 py-2 rounded-full border border-indigo-500/30 mb-4">🎁 باقات HeroKid</span>
                <h2 class="text-4xl font-extrabold text-white mt-1 mb-2">قصص وأنشطة أكثر بسعر أوفر</h2>
                <div class="w-20 h-1.5 mx-auto rounded-full mb-4" style="background: linear-gradient(90deg, #6366f1, #a855f7, #ec4899);"></div>
                <p class="text-lg text-indigo-300">اختر عدد القصص والمنتجات داخل باقة واحدة بسعر ثابت.</p>
            </div>

            @if(isset($packages) && $packages->isNotEmpty())
                @include('front.packages._home-carousel', ['packages' => $packages])
            @endif

            <div class="text-center mt-8">
                <a href="{{ route('packages') }}" class="text-indigo-300 font-bold hover:text-indigo-100 transition text-sm">عرض كل الباقات والتفاصيل ←</a>
            </div>
        </div>
    </section>
    @endif

    {{-- FAQ SNIPPET --}}
    @if(homepage_section_enabled('faq') && $faqs->count())
        <section data-home-section="faq" class="py-24 relative overflow-hidden" dir="rtl"
            style="background: #ffffff;">
            <div class="absolute inset-0 pointer-events-none opacity-20"
                style="background-image: radial-gradient(circle, #8b5cf6 1px, transparent 1px); background-size: 28px 28px;"></div>
            <div class="absolute top-8 left-16 w-24 h-24 rounded-full border-4 border-orange-200/50 pointer-events-none"></div>
            <div class="absolute bottom-10 right-12 w-16 h-16 rounded-full border-4 border-violet-200/50 pointer-events-none"></div>
            <div class="absolute top-1/2 -translate-y-1/2 right-4 w-10 h-10 bg-amber-200/40 rotate-45 rounded-md pointer-events-none"></div>

            <div class="relative z-10 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-14">
                    <span class="inline-flex items-center gap-2 bg-amber-100 text-amber-800 font-black text-xs px-4 py-2 rounded-full border border-amber-300 mb-4">❓ الأسئلة الشائعة</span>
                    <h2 class="text-4xl font-extrabold text-slate-900 mt-1 mb-2">أسئلة يطرحها الآباء دائماً</h2>
                    <div class="w-20 h-1.5 mx-auto rounded-full" style="background: linear-gradient(90deg, #f59e0b, #f97316);"></div>
                </div>
                <div class="space-y-4">
                    @foreach($faqs as $faq)
                        @php
                            $faqAnswerId = 'home-faq-answer-' . $loop->iteration;
                        @endphp
                        <div class="bg-white border border-amber-100 rounded-2xl overflow-hidden shadow-sm shadow-amber-100/50 hover:shadow-md hover:shadow-amber-200/40 transition" data-faq-item>
                            <button type="button"
                                data-faq-toggle
                                aria-expanded="false"
                                aria-controls="{{ $faqAnswerId }}"
                                class="w-full text-right px-6 py-5 font-bold text-slate-900 flex justify-between items-center hover:bg-amber-50/50 transition">
                                <span>{{ $faq->question }}</span>
                                <div data-faq-indicator class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0 transition-all duration-200 bg-amber-100 text-amber-600">
                                    <svg data-faq-icon class="w-4 h-4 transform transition-transform duration-200"
                                        fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </div>
                            </button>
                            <div id="{{ $faqAnswerId }}"
                                data-faq-answer
                                class="px-6 pb-5 pt-2 text-slate-600 border-t border-amber-50 text-sm leading-relaxed text-right"
                                hidden>{{ $faq->answer }}</div>
                        </div>
                    @endforeach
                </div>
                <div class="text-center mt-10">
                    <a href="{{ route('faq') }}"
                        class="inline-flex items-center gap-2 bg-amber-500 hover:bg-amber-600 text-white font-bold py-3 px-8 rounded-xl hover:shadow-lg hover:shadow-amber-200 transition">
                        عرض جميع الأسئلة
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                    </a>
                </div>
            </div>
        </section>
    @endif

    {{-- CONTACT US SECTION --}}
    @if(homepage_section_enabled('contact'))
    <section id="contact-us" data-home-section="contact" class="py-16 md:py-24 relative overflow-hidden" dir="rtl"
        style="background: linear-gradient(155deg, #fffbeb 0%, #fef3c7 60%, #fff7ed 100%);">
        <div class="absolute inset-0 pointer-events-none opacity-20"
            style="background-image: radial-gradient(circle, #8b5cf6 1px, transparent 1px); background-size: 32px 32px;"></div>
        <div class="absolute -top-16 -right-16 w-64 h-64 rounded-full border-[14px] border-sky-200/50 pointer-events-none"></div>
        <div class="absolute -bottom-12 -left-12 w-48 h-48 rounded-full border-[10px] border-blue-200/50 pointer-events-none"></div>

        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">

                {{-- Left Side: Contact Info --}}
                <div class="text-right">
                    <span class="inline-flex items-center gap-2 bg-sky-100 text-sky-700 font-black text-xs px-4 py-2 rounded-full border border-sky-200 mb-4">📨 تواصل معنا</span>
                    <h2 class="text-4xl font-extrabold text-slate-900 mt-1 mb-2">نحن هنا للإجابة على استفساراتك</h2>
                    <div class="w-24 h-1.5 mb-6 rounded-full" style="background: linear-gradient(90deg, #0ea5e9, #6366f1);"></div>
                    <p class="text-lg text-slate-500 mb-10 leading-relaxed">
                        هل لديك سؤال حول قصة معينة؟ أو استفسار عن حالة طلبك؟ فريق HeroKid يسعده مساعدتك في أي وقت.
                    </p>

                    <div class="space-y-5">
                        <div class="flex items-center gap-5 p-6 bg-white/80 backdrop-blur-sm rounded-3xl border border-green-100 border-r-4 border-r-green-500 shadow-sm hover:shadow-md hover:shadow-green-100/50 transition group">
                            <div class="w-14 h-14 bg-green-500 text-white rounded-2xl flex items-center justify-center text-2xl shadow-lg shadow-green-200 group-hover:scale-110 transition flex-shrink-0">💬</div>
                            <div class="text-right">
                                <h3 class="font-bold text-slate-900">واتساب</h3>
                                <p class="text-slate-500 text-sm">رد سريع خلال ساعات العمل</p>
                                @if(!empty($settings['whatsapp_url']))
                                <a href="{{ $settings['whatsapp_url'] }}" target="_blank" rel="noopener"
                                    class="text-green-600 font-bold mt-1 block hover:underline">{{ $settings['whatsapp_number'] ?? '' }}</a>
                                @elseif(!empty($settings['whatsapp_number']))
                                <span class="text-green-600 font-bold mt-1 block">{{ $settings['whatsapp_number'] }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-5 p-6 bg-white/80 backdrop-blur-sm rounded-3xl border border-indigo-100 border-r-4 border-r-indigo-500 shadow-sm hover:shadow-md hover:shadow-indigo-100/50 transition group">
                            <div class="w-14 h-14 bg-indigo-500 text-white rounded-2xl flex items-center justify-center text-2xl shadow-lg shadow-indigo-200 group-hover:scale-110 transition flex-shrink-0">📧</div>
                            <div class="text-right">
                                <h3 class="font-bold text-slate-900">البريد الإلكتروني</h3>
                                <p class="text-slate-500 text-sm">للاستفسارات الرسمية والطلبات الخاصة</p>
                                <a href="mailto:{{ $settings['site_email'] ?? '' }}"
                                    class="text-indigo-600 font-bold mt-1 block hover:underline">{{ $settings['site_email'] ?? '' }}</a>
                            </div>
                        </div>

                        <div class="flex items-center gap-5 p-6 bg-white/80 backdrop-blur-sm rounded-3xl border border-sky-100 border-r-4 border-r-sky-500 shadow-sm hover:shadow-md hover:shadow-sky-100/50 transition group">
                            <div class="w-14 h-14 bg-sky-500 text-white rounded-2xl flex items-center justify-center text-2xl shadow-lg shadow-sky-200 group-hover:scale-110 transition flex-shrink-0">📍</div>
                            <div class="text-right">
                                <h3 class="font-bold text-slate-900">المقر الرئيسي</h3>
                                <p class="text-slate-500 text-sm">{{ $settings['address_city'] ?? '' }}{{ !empty($settings['address_street']) ? '، ' . $settings['address_street'] : '' }}</p>
                                <span class="text-slate-400 text-xs font-bold mt-1 block">نشحن لجميع المحافظات</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Right Side: Contact Form --}}
                <div class="relative mt-12 lg:mt-0">
                    <div class="absolute -inset-4 rounded-[3rem] blur-2xl -z-10 opacity-60"
                        style="background: linear-gradient(135deg, #bae6fd, #c7d2fe);"></div>
                    <div class="bg-white rounded-[2rem] p-6 md:p-10 shadow-2xl shadow-sky-200/40 border border-sky-50">
                        <h3 class="text-2xl font-black text-slate-900 mb-8 text-right">
                            أرسل لنا رسالة <span class="text-sky-500">✉️</span>
                        </h3>

                        <form action="{{ route('contact.submit') }}" method="POST" class="space-y-5">
                            @csrf
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 text-right">
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-2">الاسم <span class="text-red-500">*</span></label>
                                    <input type="text" name="name" placeholder="اسمك الكريم"
                                        class="w-full bg-sky-50 border-transparent rounded-2xl py-4 px-6 focus:bg-white focus:ring-2 focus:ring-sky-400 transition text-right" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-2">الموبايل <span class="text-red-500">*</span></label>
                                    <input type="text" name="phone" placeholder="+20 1XX XXXX XXX"
                                        class="w-full bg-sky-50 border-transparent rounded-2xl py-4 px-6 focus:bg-white focus:ring-2 focus:ring-sky-400 transition text-right" dir="ltr">
                                </div>
                            </div>
                            <div class="text-right">
                                <label class="block text-sm font-bold text-slate-700 mb-2">البريد الإلكتروني <span class="text-red-500">*</span></label>
                                <input type="email" name="email" placeholder="example@email.com"
                                    class="w-full bg-sky-50 border-transparent rounded-2xl py-4 px-6 focus:bg-white focus:ring-2 focus:ring-sky-400 transition text-right" dir="ltr" required>
                            </div>
                            <div class="text-right">
                                <label class="block text-sm font-bold text-slate-700 mb-2">الرسالة <span class="text-red-500">*</span></label>
                                <textarea name="message" rows="4" placeholder="كيف يمكننا مساعدتك اليوم؟"
                                    class="w-full bg-sky-50 border-transparent rounded-2xl py-4 px-6 focus:bg-white focus:ring-2 focus:ring-sky-400 transition text-right" required></textarea>
                            </div>
                            <button type="submit"
                                class="w-full text-white font-black py-5 rounded-2xl transition-all duration-300 hover:-translate-y-1 active:scale-95 flex items-center justify-center gap-3"
                                style="background: linear-gradient(135deg, #0ea5e9, #6366f1); box-shadow: 0 8px 30px rgba(14,165,233,.35);">
                                أرسل الرسالة الآن
                                <svg class="w-5 h-5 rtl:rotate-180" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </section>
    @endif

    {{-- FINAL CTA --}}
    @if(homepage_section_enabled('final_cta'))
    <div data-home-section="final_cta" class="py-24 relative overflow-hidden" dir="rtl"
        style="background: linear-gradient(135deg, #f97316 0%, #ec4899 50%, #8b5cf6 100%);">
        {{-- Dot pattern --}}
        <div class="absolute inset-0 opacity-15 pointer-events-none"
            style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 24px 24px;"></div>
        {{-- Ambient blobs --}}
        <div class="absolute -top-20 -right-20 w-96 h-96 bg-white/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-20 -left-20 w-80 h-80 bg-white/10 rounded-full blur-3xl pointer-events-none"></div>
        {{-- Confetti decorations --}}
        <div class="absolute top-10 left-1/4 w-4 h-4 rounded-full bg-yellow-300/60 pointer-events-none" style="animation: confetti-float 4s ease-in-out infinite;"></div>
        <div class="absolute top-16 right-1/3 w-3 h-3 rounded-full bg-white/40 pointer-events-none" style="animation: confetti-float 5s ease-in-out infinite .5s;"></div>
        <div class="absolute bottom-16 left-1/3 w-5 h-5 rounded-full bg-yellow-200/50 pointer-events-none" style="animation: confetti-float 6s ease-in-out infinite 1s;"></div>
        <div class="absolute bottom-10 right-1/4 w-3 h-3 bg-white/30 rotate-45 pointer-events-none" style="animation: confetti-float 4.5s ease-in-out infinite 1.5s;"></div>
        <div class="absolute top-1/2 left-12 w-3 h-3 rounded-full bg-white/30 pointer-events-none" style="animation: confetti-float 5.5s ease-in-out infinite .3s;"></div>
        <div class="absolute top-1/2 right-12 w-2 h-2 rounded-full bg-yellow-300/60 pointer-events-none" style="animation: confetti-float 4s ease-in-out infinite 2s;"></div>

        <div class="relative z-10 max-w-3xl mx-auto px-4 text-center">
            <div class="text-6xl mb-6 inline-block animate-bounce" style="animation-duration: 2.5s;">🚀</div>
            <h2 class="text-4xl md:text-5xl font-extrabold text-white mb-6 leading-tight drop-shadow-lg">
                ابدأ رحلة طفلك اليوم!
            </h2>
            <p class="text-white/85 text-xl mb-10 leading-relaxed">
                انضم لأكثر من <strong class="text-yellow-300">{{ setting('stat_orders', $settings['stat_orders'] ?? '') }} عائلة</strong> تصنع السحر مع HeroKid.<br>
                طلبك المخصص جاهز خلال <strong class="text-yellow-300">{{ delivery_range() }}</strong>.
            </p>
            <div class="flex flex-wrap justify-center gap-4">
                <a href="{{ route('shop.index') }}"
                    class="bg-white font-extrabold py-5 px-12 rounded-2xl text-xl shadow-2xl hover:shadow-white/30 transition hover:-translate-y-1"
                    style="color: #7c3aed;">تسوّق منتجات طفلك ✨</a>
                <a href="{{ route('how-it-works') }}"
                    class="border-2 border-white/50 text-white font-bold py-5 px-10 rounded-2xl text-xl hover:bg-white/15 transition hover:-translate-y-1 backdrop-blur-sm">
                    كيف يعمل؟ 🎬
                </a>
            </div>
            {{-- Trust micro-badges --}}
            <div class="flex flex-wrap justify-center gap-6 mt-12 text-white/75 text-sm font-bold">
                <span class="flex items-center gap-1.5">🔒 دفع آمن</span>
                <span class="flex items-center gap-1.5">🚀 شحن سريع</span>
                <span class="flex items-center gap-1.5">⭐ ضمان الجودة</span>
                <span class="flex items-center gap-1.5">💬 دعم على مدار الساعة</span>
            </div>
        </div>
    </div>
    @endif
</x-front-layout>
