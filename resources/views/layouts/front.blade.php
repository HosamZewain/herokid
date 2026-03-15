<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- ── Dynamic title: pages pass $pageTitle via x-slot; falls back to default ── --}}
    @php
        $seoTitle       = isset($pageTitle)       ? (string)$pageTitle       : 'قصص أطفال مخصصة تجعل طفلك بطل القصة';
        $seoDescription = isset($pageDescription) ? (string)$pageDescription : 'هيرو كيد — أول منصة في مصر لتحويل طفلك إلى بطل قصة مطبوعة بوجهه الحقيقي. اختر القصة، أرسل صورة طفلك، واستلمها مطبوعة خلال أيام.';
        $seoImage       = isset($pageImage)       ? (string)$pageImage       : asset('images/og-cover.jpg');
        $canonicalUrl   = rtrim(strtok(url()->full(), '?'), '/');
        $fullTitle      = $seoTitle . ' | HeroKid';
    @endphp

    <title>{{ $fullTitle }}</title>

    <!-- ══ Core SEO ══ -->
    <meta name="description" content="{{ $seoDescription }}">
    <meta name="keywords" content="قصص أطفال مخصصة, هيرو كيد, HeroKid, كتب أطفال مصر, هدايا أطفال, بطل القصة, قصص شخصية مطبوعة, قصص باسم الطفل">
    <meta name="author" content="HeroKid">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    <link rel="canonical" href="{{ $canonicalUrl }}">

    <!-- ══ Open Graph ══ -->
    <meta property="og:type"        content="{{ isset($ogType) ? (string)$ogType : 'website' }}">
    <meta property="og:site_name"   content="HeroKid">
    <meta property="og:url"         content="{{ $canonicalUrl }}">
    <meta property="og:title"       content="{{ $fullTitle }}">
    <meta property="og:description" content="{{ $seoDescription }}">
    <meta property="og:image"       content="{{ $seoImage }}">
    <meta property="og:image:width"  content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:locale"      content="ar_EG">

    <!-- ══ Twitter Card ══ -->
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:site"        content="@HeroKidEG">
    <meta name="twitter:title"       content="{{ $fullTitle }}">
    <meta name="twitter:description" content="{{ $seoDescription }}">
    <meta name="twitter:image"       content="{{ $seoImage }}">

    <!-- ══ Favicon & Icons ══ -->
    <link rel="icon"             type="image/png"  href="/images/logo.png">
    <link rel="apple-touch-icon"                   href="/images/logo.png">
    <meta name="theme-color" content="#f97316">
    <meta name="msapplication-TileColor" content="#f97316">

    <!-- ══ JSON-LD: Organization + WebSite (every page) ══ -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "Organization",
          "@id": "{{ config('app.url') }}/#organization",
          "name": "HeroKid",
          "url": "{{ config('app.url') }}",
          "logo": {
            "@type": "ImageObject",
            "url": "{{ asset('images/logo.png') }}"
          },
          "description": "أول منصة في مصر لتحويل طفلك إلى بطل قصة مطبوعة بوجهه الحقيقي.",
          "address": {
            "@type": "PostalAddress",
            "addressLocality": "المنصورة",
            "addressCountry": "EG"
          },
          "contactPoint": {
            "@type": "ContactPoint",
            "contactType": "customer service",
            "availableLanguage": "Arabic"
          },
          "sameAs": []
        },
        {
          "@type": "WebSite",
          "@id": "{{ config('app.url') }}/#website",
          "url": "{{ config('app.url') }}",
          "name": "HeroKid",
          "publisher": { "@id": "{{ config('app.url') }}/#organization" },
          "inLanguage": "ar",
          "potentialAction": {
            "@type": "SearchAction",
            "target": {
              "@type": "EntryPoint",
              "urlTemplate": "{{ route('stories.index') }}?search={search_term_string}"
            },
            "query-input": "required name=search_term_string"
          }
        }
      ]
    }
    </script>

    {{-- Extra schema injected per-page (e.g. FAQPage, Book, BreadcrumbList) --}}
    {{ $schema ?? '' }}

    <!-- ══ Fonts ══ -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800,900&display=swap" rel="stylesheet">

    <!-- ══ Scripts ══ -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>body { font-family: 'Cairo', sans-serif; }</style>
</head>

<body class="font-sans antialiased text-gray-900 bg-white">
    <div class="min-h-screen flex flex-col">

        <!-- ===================== NAVBAR ===================== -->
        <nav class="bg-white border-b border-gray-100 shadow-sm sticky top-0 z-50" x-data="{ mobileOpen: false }">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-18 py-3">

                    <!-- Logo + Desktop Nav -->
                    <div class="flex items-center gap-8">
                        <a href="{{ route('home') }}" class="flex-shrink-0">
                            <img src="/images/logo.png" alt="HeroKid Logo" class="h-12 md:h-24 w-auto object-contain">
                        </a>
                        <div class="hidden lg:flex items-center gap-10">
                            <x-nav-link :href="route('home')" :active="request()->routeIs('home')">الرئيسية</x-nav-link>
                            <x-nav-link :href="route('stories.index')"
                                :active="request()->routeIs('stories.*')">القصص</x-nav-link>
                            <x-nav-link :href="route('how-it-works')" :active="request()->routeIs('how-it-works')">كيف
                                يعمل؟</x-nav-link>
                            <x-nav-link :href="route('pricing')"
                                :active="request()->routeIs('pricing')">الأسعار</x-nav-link>
                            <x-nav-link :href="route('faq')" :active="request()->routeIs('faq')">الأسئلة
                                الشائعة</x-nav-link>
                            <x-nav-link :href="route('track.index')" :active="request()->routeIs('track.*')">تتبع
                                الطلب</x-nav-link>
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
                                <a href="{{ route('stories.index') }}"
                                    class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-5 py-2.5 rounded-xl font-bold shadow-sm shadow-indigo-200 transition hover:-translate-y-0.5">
                                    ابدأ الآن
                                </a>
                            @endauth
                        </div>
                        <!-- Mobile Hamburger -->
                        <button @click="mobileOpen = !mobileOpen"
                            class="lg:hidden p-2 rounded-lg text-gray-500 hover:bg-gray-100 transition">
                            <svg x-show="!mobileOpen" class="w-6 h-6" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                            <svg x-show="mobileOpen" class="w-6 h-6" style="display:none" fill="none"
                                stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mobile Menu -->
            <div x-show="mobileOpen" x-transition
                class="lg:hidden border-t border-gray-100 bg-white px-4 py-4 space-y-2" style="display:none">
                <a href="{{ route('home') }}"
                    class="block px-4 py-2 rounded-xl text-gray-700 font-bold hover:bg-indigo-50 hover:text-indigo-600 transition">الرئيسية</a>
                <a href="{{ route('stories.index') }}"
                    class="block px-4 py-2 rounded-xl text-gray-700 font-bold hover:bg-indigo-50 hover:text-indigo-600 transition">القصص</a>
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
        <footer class="bg-slate-900 text-white pt-16 pb-8 mt-16">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-12">

                    <div class="md:col-span-2 text-right text-slate-400">
                        <img src="/images/logo.png" alt="HeroKid Logo" class="h-20 md:h-32 w-auto mb-4 object-contain">
                        <p class="text-slate-400 mt-3 leading-relaxed max-w-xs mr-0">
                            قصص أطفال مخصصة تجعل طفلك بطل القصة بوجهه الحقيقي. نهدف لنشر الحب والقيم الجميلة عبر القصص
                            المطبوعة.
                        </p>
                        <div class="flex gap-4 mt-8 justify-start">
                            <a href="https://wa.me/201112333646" target="_blank"
                                class="w-10 h-10 bg-slate-800 hover:bg-green-600 text-white rounded-xl flex items-center justify-center transition-all duration-300 hover:-translate-y-1 shadow-lg shadow-slate-900/50"
                                title="WhatsApp">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                    <path
                                        d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z">
                                    </path>
                                </svg>
                            </a>
                            <a href="#"
                                class="w-10 h-10 bg-slate-800 hover:bg-pink-600 text-white rounded-xl flex items-center justify-center transition-all duration-300 hover:-translate-y-1 shadow-lg shadow-slate-900/50"
                                title="Instagram">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                    <rect width="20" height="20" x="2" y="2" rx="5" ry="5"></rect>
                                    <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
                                    <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line>
                                </svg>
                            </a>
                            <a href="{{ $settings['facebook'] ?? '#' }}"
                                class="w-10 h-10 bg-slate-800 hover:bg-blue-600 text-white rounded-xl flex items-center justify-center transition-all duration-300 hover:-translate-y-1 shadow-lg shadow-slate-900/50"
                                title="Facebook">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                    <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path>
                                </svg>
                            </a>
                            <a href="#"
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
                        </div>
                    </div>

                    <!-- Quick Links -->
                    <div class="text-right">
                        <h4 class="font-bold mb-4 text-white">روابط سريعة</h4>
                        <ul class="space-y-2 text-slate-400 text-sm">
                            <li><a href="{{ route('home') }}" class="hover:text-white transition">الرئيسية</a></li>
                            <li><a href="{{ route('stories.index') }}" class="hover:text-white transition">القصص
                                    المتاحة</a></li>
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
                        <ul class="space-y-2 text-slate-400 text-sm">
                            <li><a href="{{ route('privacy') }}" class="hover:text-white transition">سياسة الخصوصية</a>
                            </li>
                            <li><a href="{{ route('terms') }}" class="hover:text-white transition">الشروط والأحكام</a>
                            </li>
                        </ul>
                        <div class="mt-6">
                            <p class="text-slate-400 text-xs">للتواصل السريع:</p>
                            <a href="tel:201112333646"
                                class="text-indigo-400 text-sm font-bold hover:text-indigo-300 transition">01112333646</a>
                        </div>
                    </div>
                </div>

                <div class="border-t border-slate-800 pt-8 text-center text-slate-500 text-sm">
                    &copy; {{ date('Y') }} HeroKid. جميع الحقوق محفوظة.
                </div>
            </div>
        </footer>
    </div>
</body>

</html>