<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- ── Dynamic title: pages pass $pageTitle via x-slot; falls back to default ── --}}
    @php
        $seoTitle = isset($pageTitle) ? (string) $pageTitle : 'قصص أطفال مخصصة تجعل طفلك بطل القصة';
        $seoDescription = isset($pageDescription) ? (string) $pageDescription : 'هيرو كيد — أول منصة في مصر لتحويل طفلك إلى بطل قصة مطبوعة بوجهه الحقيقي. اختر القصة، أرسل صورة طفلك، واستلمها مطبوعة خلال أيام.';
        $seoImage = \App\Support\Seo::imageUrl(isset($pageImage) ? (string) $pageImage : '/images/og-cover.jpg');
        $canonicalUrl = isset($canonical) ? \App\Support\Seo::url((string) $canonical) : \App\Support\Seo::canonicalForRequest(request());
        $fullTitle = $seoTitle . ' | HeroKid';
        $siteUrl = \App\Support\Seo::url('/');
        $organizationId = \App\Support\Seo::url('/#organization');
        $websiteId = \App\Support\Seo::url('/#website');
        $siteSchema = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => $organizationId,
                    'name' => 'HeroKid',
                    'url' => $siteUrl,
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => \App\Support\Seo::imageUrl('/images/logo-192.png'),
                    ],
                    'description' => 'أول منصة في مصر لتحويل طفلك إلى بطل قصة مطبوعة بوجهه الحقيقي.',
                    'address' => [
                        '@type' => 'PostalAddress',
                        'addressLocality' => $settings['address_city'] ?? 'المنصورة',
                        'addressCountry' => 'EG',
                    ],
                    'contactPoint' => [
                        '@type' => 'ContactPoint',
                        'contactType' => 'customer service',
                        'availableLanguage' => 'Arabic',
                    ],
                    'sameAs' => array_values(array_filter([
                        $settings['facebook_url'] ?? null,
                        $settings['instagram_url'] ?? null,
                        $settings['youtube_url'] ?? null,
                    ])),
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $websiteId,
                    'url' => $siteUrl,
                    'name' => 'HeroKid',
                    'publisher' => ['@id' => $organizationId],
                    'inLanguage' => 'ar',
                    'potentialAction' => [
                        '@type' => 'SearchAction',
                        'target' => [
                            '@type' => 'EntryPoint',
                            'urlTemplate' => \App\Support\Seo::url('/stories?search={search_term_string}'),
                        ],
                        'query-input' => 'required name=search_term_string',
                    ],
                ],
            ],
        ];
    @endphp

    <title>{{ $fullTitle }}</title>

    <!-- ══ Core SEO ══ -->
    <meta name="description" content="{{ $seoDescription }}">
    <meta name="keywords"
        content="قصص أطفال مخصصة, هيرو كيد, HeroKid, كتب أطفال مصر, هدايا أطفال, بطل القصة, قصص شخصية مطبوعة, قصص باسم الطفل">
    <meta name="author" content="HeroKid">
    <meta name="robots" content="{{ isset($robots) ? (string) $robots : 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1' }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">

    <!-- ══ Open Graph ══ -->
    <meta property="og:type" content="{{ isset($ogType) ? (string) $ogType : 'website' }}">
    <meta property="og:site_name" content="HeroKid">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:title" content="{{ $fullTitle }}">
    <meta property="og:description" content="{{ $seoDescription }}">
    <meta property="og:image" content="{{ $seoImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:locale" content="ar_EG">

    <!-- ══ Twitter Card ══ -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@HeroKidEG">
    <meta name="twitter:title" content="{{ $fullTitle }}">
    <meta name="twitter:description" content="{{ $seoDescription }}">
    <meta name="twitter:image" content="{{ $seoImage }}">

    <!-- ══ Favicon & Icons ══ -->
    <link rel="icon" type="image/png" href="/images/logo-96.png">
    <link rel="apple-touch-icon" href="/images/logo-192.png">
    <meta name="theme-color" content="#f97316">
    <meta name="msapplication-TileColor" content="#f97316">

    <!-- ══ JSON-LD: Organization + WebSite (every page) ══ -->
    <script type="application/ld+json">
    @json($siteSchema, \App\Support\Seo::jsonFlags())
    </script>

    {{-- Extra schema injected per-page via @push('schema') --}}
    @stack('schema')

    @php
        $metaPixelId = trim((string) config('services.meta_pixel.id', ''));
    @endphp
    @if($metaPixelId !== '')
        <!-- Meta Pixel Code -->
        <script>
            !function(f,b,e,v,n,t,s)
            {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};
            if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
            n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];
            s.parentNode.insertBefore(t,s)}(window, document,'script',
            'https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', @json($metaPixelId));
            fbq('track', 'PageView');
        </script>
        <!-- End Meta Pixel Code -->
    @endif

    <!-- ══ Fonts ══ -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800,900&display=swap" rel="stylesheet">

    <!-- ══ Scripts ══ -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body {
            font-family: 'Cairo', sans-serif;
        }
    </style>
    @stack('styles')
</head>

<body class="font-sans antialiased text-gray-900 bg-white">
    @if($metaPixelId !== '')
        <noscript><img height="1" width="1" style="display:none"
            src="https://www.facebook.com/tr?id={{ $metaPixelId }}&ev=PageView&noscript=1"
            alt=""></noscript>
    @endif
    @php
        $cartItemCount = count(session('cart.items', []));
    @endphp
    <div class="min-h-screen flex flex-col">

        <!-- ===================== NAVBAR ===================== -->
        <nav class="bg-white border-b border-gray-100 shadow-sm sticky top-0 z-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-18 py-3">

                    <!-- Logo + Desktop Nav -->
                    <div class="flex items-center gap-8">
                        <a href="{{ route('home') }}" class="flex-shrink-0">
                            <img src="/images/logo-192.png"
                                srcset="/images/logo-96.png 96w, /images/logo-192.png 192w, /images/logo-320.png 320w"
                                sizes="(min-width: 768px) 104px, 56px"
                                width="192" height="164"
                                alt="HeroKid Logo" class="h-12 md:h-24 w-auto object-contain">
                        </a>
                        <div class="hidden lg:flex items-center gap-10">
                            <x-nav-link :href="route('home')" :active="request()->routeIs('home')">الرئيسية</x-nav-link>
                            <x-nav-link :href="route('stories.index')"
                                :active="request()->routeIs('stories.*')">القصص</x-nav-link>
                            <x-nav-link :href="route('shop.index')"
                                :active="request()->routeIs('shop.*')">المتجر</x-nav-link>
                            <x-nav-link :href="route('how-it-works')" :active="request()->routeIs('how-it-works')">كيف
                                يعمل؟</x-nav-link>
                            <x-nav-link :href="route('pricing')"
                                :active="request()->routeIs('pricing')">الأسعار</x-nav-link>
                            <x-nav-link :href="route('faq')" :active="request()->routeIs('faq')">الأسئلة
                                الشائعة</x-nav-link>
                            <x-nav-link :href="route('track.index')" :active="request()->routeIs('track.*')">تتبع
                                الطلب</x-nav-link>
                            <x-nav-link :href="route('cart.index')" :active="request()->routeIs('cart.*')">
                                <span class="inline-flex items-center gap-1.5">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437m0 0L6.75 14.25A2.25 2.25 0 009 16.5h7.5a2.25 2.25 0 002.2-1.77l1.05-4.8A1.5 1.5 0 0018.285 8H6.04m-.934-2.728L4.5 3m4.5 16.5a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm9 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" />
                                    </svg>
                                    <span>السلة</span>
                                </span>
                                @if($cartItemCount > 0)
                                    <span class="mr-1 inline-flex items-center justify-center min-w-5 h-5 rounded-full bg-indigo-600 text-white text-[11px] px-1">{{ $cartItemCount }}</span>
                                @endif
                            </x-nav-link>
                        </div>
                    </div>

                    <!-- Auth + Mobile Toggle -->
                    <div class="flex items-center gap-3">
                        <div class="hidden sm:flex items-center gap-3">
                            @auth
                                <a href="{{ route('dashboard') }}"
                                    class="text-gray-600 hover:text-indigo-600 font-bold text-sm px-3">
                                    👤 حسابي
                                </a>
                            @else
                                <a href="{{ route('login') }}"
                                    class="text-gray-600 hover:text-indigo-600 font-bold text-sm px-3">دخول</a>
                                <a href="{{ route('register') }}"
                                    class="text-gray-600 hover:text-indigo-600 font-bold text-sm px-3">إنشاء حساب</a>
                                <a href="{{ route('stories.index') }}"
                                    class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-5 py-2.5 rounded-xl font-bold shadow-sm shadow-indigo-200 transition hover:-translate-y-0.5">
                                    ابدأ الآن
                                </a>
                            @endauth
                        </div>
                        <a href="{{ route('cart.index') }}"
                            class="relative lg:hidden inline-flex h-10 w-10 items-center justify-center rounded-xl border border-indigo-100 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition"
                            aria-label="السلة">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437m0 0L6.75 14.25A2.25 2.25 0 009 16.5h7.5a2.25 2.25 0 002.2-1.77l1.05-4.8A1.5 1.5 0 0018.285 8H6.04m-.934-2.728L4.5 3m4.5 16.5a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm9 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" />
                            </svg>
                            @if($cartItemCount > 0)
                                <span class="absolute -top-1 -left-1 inline-flex min-w-5 h-5 items-center justify-center rounded-full bg-indigo-600 px-1 text-[11px] font-black text-white">{{ $cartItemCount }}</span>
                            @endif
                        </a>
                        <!-- Mobile Hamburger -->
                        <button type="button" data-front-menu-toggle aria-expanded="false" aria-controls="front-mobile-menu" aria-label="فتح القائمة"
                            class="lg:hidden p-2 rounded-lg text-gray-500 hover:bg-gray-100 transition">
                            <svg data-front-menu-open-icon class="w-6 h-6" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                            <svg data-front-menu-close-icon class="hidden w-6 h-6" fill="none"
                                stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mobile Menu -->
            <div id="front-mobile-menu" data-front-mobile-menu
                class="hidden lg:hidden border-t border-gray-100 bg-white px-4 py-4 space-y-2">
                <a href="{{ route('home') }}"
                    class="block px-4 py-2 rounded-xl text-gray-700 font-bold hover:bg-indigo-50 hover:text-indigo-600 transition">الرئيسية</a>
                <a href="{{ route('stories.index') }}"
                    class="block px-4 py-2 rounded-xl text-gray-700 font-bold hover:bg-indigo-50 hover:text-indigo-600 transition">القصص</a>
                <a href="{{ route('shop.index') }}"
                    class="block px-4 py-2 rounded-xl text-gray-700 font-bold hover:bg-indigo-50 hover:text-indigo-600 transition">المتجر</a>
                <a href="{{ route('how-it-works') }}"
                    class="block px-4 py-2 rounded-xl text-gray-700 font-bold hover:bg-indigo-50 hover:text-indigo-600 transition">كيف
                    يعمل؟</a>
                <a href="{{ route('pricing') }}"
                    class="block px-4 py-2 rounded-xl text-gray-700 font-bold hover:bg-indigo-50 hover:text-indigo-600 transition">الأسعار</a>
                <a href="{{ route('faq') }}"
                    class="block px-4 py-2 rounded-xl text-gray-700 font-bold hover:bg-indigo-50 hover:text-indigo-600 transition">الأسئلة
                    الشائعة</a>
                <a href="{{ route('track.index') }}"
                    class="block px-4 py-2 rounded-xl text-gray-700 font-bold hover:bg-indigo-50 hover:text-indigo-600 transition">تتبع
                    الطلب</a>
                <a href="{{ route('cart.index') }}"
                    class="flex items-center justify-between px-4 py-2 rounded-xl text-gray-700 font-bold hover:bg-indigo-50 hover:text-indigo-600 transition">
                    <span class="inline-flex items-center gap-2">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437m0 0L6.75 14.25A2.25 2.25 0 009 16.5h7.5a2.25 2.25 0 002.2-1.77l1.05-4.8A1.5 1.5 0 0018.285 8H6.04m-.934-2.728L4.5 3m4.5 16.5a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm9 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" />
                        </svg>
                        <span>السلة</span>
                    </span>
                    @if($cartItemCount > 0)
                        <span class="mr-1 inline-flex items-center justify-center min-w-5 h-5 rounded-full bg-indigo-600 text-white text-[11px] px-1">{{ $cartItemCount }}</span>
                    @endif
                </a>
                <div class="pt-2 border-t border-gray-100">
                    @auth
                        <a href="{{ route('dashboard') }}" class="block px-4 py-2 rounded-xl text-indigo-600 font-bold">👤
                            حسابي</a>
                    @else
                        <a href="{{ route('login') }}" class="block px-4 py-2 rounded-xl text-gray-700 font-bold">تسجيل
                            الدخول</a>
                        <a href="{{ route('register') }}"
                            class="block mt-2 text-center bg-indigo-600 text-white font-bold px-4 py-2.5 rounded-xl">إنشاء
                            حساب</a>
                    @endauth
                </div>
            </div>
        </nav>

        <!-- Page Content -->
        <main class="flex-grow">
            {{ $slot }}
        </main>

        <!-- ===================== FOOTER ===================== -->
        <footer class="bg-slate-900 text-white pt-16 pb-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-12">

                    <div class="md:col-span-2 text-right text-slate-300">
                        <img src="/images/logo-192.png"
                            srcset="/images/logo-192.png 192w, /images/logo-320.png 320w"
                            sizes="(min-width: 768px) 140px, 96px"
                            width="192" height="164"
                            alt="HeroKid Logo" class="h-20 md:h-32 w-auto mb-4 object-contain">
                        <p class="text-slate-300 mt-3 leading-relaxed max-w-xs mr-0">
                            قصص أطفال مخصصة تجعل طفلك بطل القصة بوجهه الحقيقي. نهدف لنشر الحب والقيم الجميلة عبر القصص
                            المطبوعة.
                        </p>
                        <div class="flex gap-4 mt-8 justify-start">
                            @if(!empty($settings['whatsapp_url']))
                            <a href="{{ $settings['whatsapp_url'] }}" target="_blank" rel="noopener"
                                class="w-10 h-10 bg-slate-800 hover:bg-green-600 text-white rounded-xl flex items-center justify-center transition-all duration-300 hover:-translate-y-1 shadow-lg shadow-slate-900/50"
                                title="WhatsApp">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                    <path
                                        d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z">
                                    </path>
                                </svg>
                            </a>
                            @endif
                            @if(!empty($settings['instagram_url']))
                            <a href="{{ $settings['instagram_url'] }}" target="_blank" rel="noopener"
                                class="w-10 h-10 bg-slate-800 hover:bg-pink-600 text-white rounded-xl flex items-center justify-center transition-all duration-300 hover:-translate-y-1 shadow-lg shadow-slate-900/50"
                                title="Instagram">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                    <rect width="20" height="20" x="2" y="2" rx="5" ry="5"></rect>
                                    <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
                                    <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line>
                                </svg>
                            </a>
                            @endif
                            @if(!empty($settings['facebook_url']))
                            <a href="{{ $settings['facebook_url'] }}" target="_blank" rel="noopener"
                                class="w-10 h-10 bg-slate-800 hover:bg-blue-600 text-white rounded-xl flex items-center justify-center transition-all duration-300 hover:-translate-y-1 shadow-lg shadow-slate-900/50"
                                title="Facebook">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                    <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path>
                                </svg>
                            </a>
                            @endif
                            @if(!empty($settings['youtube_url']))
                            <a href="{{ $settings['youtube_url'] }}" target="_blank" rel="noopener"
                                class="w-10 h-10 bg-slate-800 hover:bg-red-600 text-white rounded-xl flex items-center justify-center transition-all duration-300 hover:-translate-y-1 shadow-lg shadow-slate-900/50"
                                title="YouTube">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                    <path
                                        d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.42a2.78 2.78 0 0 0-1.94 2C1 8.14 1 12 1 12s0 3.86.46 5.58a2.78 2.78 0 0 0 1.94 2c1.72.42 8.6.42 8.6.42s6.88 0 8.6-.42a2.78 2.78 0 0 0 1.94-2C23 15.86 23 12 23 12s0-3.86-.46-5.58z">
                                    </path>
                                    <polygon points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02"></polygon>
                                </svg>
                            </a>
                            @endif
                        </div>
                    </div>

                    <!-- Quick Links -->
                    <div class="text-right">
                        <h4 class="font-bold mb-4 text-white">روابط سريعة</h4>
                        <ul class="space-y-2 text-slate-300 text-sm">
                            <li><a href="{{ route('home') }}" class="hover:text-white transition">الرئيسية</a></li>
                            <li><a href="{{ route('stories.index') }}" class="hover:text-white transition">القصص
                                    المتاحة</a></li>
                            <li><a href="{{ route('shop.index') }}" class="hover:text-white transition">المتجر</a></li>
                            <li><a href="{{ route('how-it-works') }}" class="hover:text-white transition">كيف يعمل؟</a>
                            </li>
                            <li><a href="{{ route('pricing') }}" class="hover:text-white transition">الأسعار</a></li>
                            <li><a href="{{ route('faq') }}" class="hover:text-white transition">الأسئلة الشائعة</a>
                            </li>
                            <li><a href="{{ route('track.index') }}" class="hover:text-white transition">تتبع الطلب</a>
                            </li>
                            <li><a href="{{ route('contact') }}" class="hover:text-white transition">تواصل معنا</a></li>
                        </ul>
                    </div>

                    <!-- Policies -->
                    <div class="text-right">
                        <h4 class="font-bold mb-4 text-white">قانوني</h4>
                        <ul class="space-y-2 text-slate-300 text-sm">
                            <li><a href="{{ route('privacy') }}" class="hover:text-white transition">سياسة الخصوصية</a>
                            </li>
                            <li><a href="{{ route('terms') }}" class="hover:text-white transition">الشروط والأحكام</a>
                            </li>
                        </ul>
                        @if(!empty($settings['whatsapp_number']))
                        <div class="mt-6">
                            <p class="text-slate-300 text-xs">للتواصل السريع:</p>
                            <a href="tel:{{ $settings['whatsapp_number'] }}"
                                class="text-indigo-400 text-sm font-bold hover:text-indigo-300 transition">{{ $settings['whatsapp_number'] }}</a>
                        </div>
                        @endif
                    </div>
                </div>

                <div class="border-t border-slate-800 pt-8 text-center text-slate-400 text-sm">
                    &copy; {{ date('Y') }} HeroKid. جميع الحقوق محفوظة.
                </div>
            </div>
        </footer>
    </div>
    @stack('scripts')
    @php
        $facebookAddToCartEvent = session()->pull('facebook_add_to_cart_event');
    @endphp
    @if(!empty($facebookAddToCartEvent['data']))
        <script>
            if (typeof fbq === 'function') {
                fbq('track', 'AddToCart', @json($facebookAddToCartEvent['data']), {
                    eventID: @json($facebookAddToCartEvent['event_id'])
                });
            }
        </script>
    @endif
</body>

</html>
