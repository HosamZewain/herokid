<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- ── Dynamic title: pages pass $pageTitle via x-slot; falls back to default ── --}}
    @php
        $seoTitle = isset($pageTitle) ? (string) $pageTitle : setting('seo_home_title', $settings['seo_home_title'] ?? '');
        $seoDescription = isset($pageDescription) ? (string) $pageDescription : setting('seo_home_description', $settings['seo_home_description'] ?? '');
        $seoImage = \App\Support\Seo::imageUrl(isset($pageImage) ? (string) $pageImage : '/images/og-cover.jpg');
        $seoImageWidth = isset($ogImageWidth) ? (int) trim((string) $ogImageWidth) : 1200;
        $seoImageHeight = isset($ogImageHeight) ? (int) trim((string) $ogImageHeight) : 630;
        $seoImageAlt = isset($pageImageAlt) ? (string) $pageImageAlt : 'HeroKid — قصص أطفال مخصصة';
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
                    'description' => setting('seo_home_description', $settings['seo_home_description'] ?? ''),
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
                            'urlTemplate' => \App\Support\Seo::url('/shop?q={search_term_string}'),
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
    <meta property="og:image:alt" content="{{ $seoImageAlt }}">
    <meta property="og:image:width" content="{{ $seoImageWidth }}">
    <meta property="og:image:height" content="{{ $seoImageHeight }}">
    <meta property="og:locale" content="ar_EG">

    <!-- ══ Twitter Card ══ -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@HeroKidEG">
    <meta name="twitter:title" content="{{ $fullTitle }}">
    <meta name="twitter:description" content="{{ $seoDescription }}">
    <meta name="twitter:image" content="{{ $seoImage }}">
    <meta name="twitter:image:alt" content="{{ $seoImageAlt }}">

    <!-- ══ Favicon & Icons ══ -->
    <link rel="icon" type="image/png" href="/images/logo-96.png">
    <link rel="apple-touch-icon" href="/images/logo-192.png">
    <meta name="theme-color" content="#074ad6">
    <meta name="msapplication-TileColor" content="#074ad6">

    <!-- ══ JSON-LD: Organization + WebSite (every page) ══ -->
    <script type="application/ld+json">
    @json($siteSchema, \App\Support\Seo::jsonFlags())
    </script>

    {{-- Extra schema injected per-page via @push('schema') --}}
    @stack('schema')

    @php
        $metaPixelId = trim((string) config('services.meta_pixel.id', ''));
        $googleAnalyticsId = trim((string) config('services.google_analytics.id', ''));
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

    @if($googleAnalyticsId !== '')
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ urlencode($googleAnalyticsId) }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', @json($googleAnalyticsId));
        </script>

        @if(!empty($googleAdsPurchaseEvent))
            <!-- Google Ads purchase conversion event -->
            <script>
                gtag('event', 'conversion_event_purchase', @json($googleAdsPurchaseEvent, \App\Support\Seo::jsonFlags()));
            </script>
        @endif
    @endif

    <!-- ══ Fonts ══ -->
    <link rel="preload" href="{{ asset('fonts/cairo-regular.ttf') }}" as="font" type="font/ttf" crossorigin>

    <!-- ══ Scripts ══ -->
    @vite(['resources/css/app.css', 'resources/css/front-theme.css', 'resources/js/app.js'])
    @if(request()->routeIs('home'))
        @vite('resources/css/homepage.css')
        @php
            $heroPreload = app(\App\Services\Images\PublicImageVariants::class)->presentation(asset('images/homepage/hero-banner.webp'), 1440);
        @endphp
        <link rel="preload" href="{{ $heroPreload['src'] }}" as="image" media="(min-width: 761px)" @if($heroPreload['srcset']) imagesrcset="{{ $heroPreload['srcset'] }}" imagesizes="100vw" @endif>
    @endif
    <style>
        body {
            font-family: 'Cairo', sans-serif;
        }
    </style>
    @stack('styles')
</head>

<body class="herokid-front font-sans antialiased text-gray-900 bg-white">
    <a href="#main-content" class="hk-skip-link">انتقل للمحتوى</a>
    @if($metaPixelId !== '')
        <noscript><img height="1" width="1" style="display:none"
            src="https://www.facebook.com/tr?id={{ $metaPixelId }}&ev=PageView&noscript=1"
            alt=""></noscript>
    @endif
    @php
        $cartItemCount = count(session('cart.items', []));
        $guideMenuActive = request()->routeIs('about', 'how-it-works', 'faq', 'track.*');
    @endphp
    <div class="min-h-screen flex flex-col">

        @include('front.partials.header')

        @if(is_array(session('cart_added_notice')))
            @php($cartAddedNotice = session('cart_added_notice'))
            <div data-cart-added-toast role="status" aria-live="polite" aria-atomic="true"
                class="fixed inset-x-3 bottom-4 z-[70] mx-auto max-w-xl rounded-3xl border border-emerald-200 bg-white p-4 text-right shadow-2xl shadow-slate-900/20 sm:inset-x-auto sm:bottom-6 sm:start-6 sm:mx-0 sm:w-[30rem]">
                <div class="flex items-start gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-emerald-100 text-xl text-emerald-700"
                        aria-hidden="true">✓</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-black text-slate-950">
                            تمت إضافة «{{ $cartAddedNotice['story_title'] ?? 'القصة' }}» إلى السلة
                        </p>
                        <p class="mt-1 text-sm leading-6 text-slate-600">يمكنك إكمال الطلب الآن أو اختيار قصة أو منتج آخر.</p>
                    </div>
                    <button type="button" data-cart-toast-dismiss
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                        aria-label="إغلاق الإشعار">×</button>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <a href="{{ route('cart.index') }}"
                        class="inline-flex min-h-11 items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        الذهاب إلى السلة
                    </a>
                    <button type="button" data-cart-toast-dismiss
                        class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-black text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                        اختيار منتج آخر
                    </button>
                </div>
            </div>
        @endif

        <!-- Page Content -->
        <main id="main-content" class="flex-grow" tabindex="-1">
            {{ $slot }}
        </main>

        <x-whatsapp-floating-button />

        @include('front.partials.footer')
    </div>
    @stack('scripts')
</body>

</html>
