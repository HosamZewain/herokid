<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} — لوحة الإدارة</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800&display=swap" rel="stylesheet" />
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/images/logo-96.png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body {
            font-family: 'Cairo', sans-serif;
        }
    </style>
</head>

<body class="font-sans antialiased bg-gray-50">

    <div class="flex min-h-screen" dir="rtl">

        {{-- ===== SIDEBAR ===== --}}
        <aside
            class="w-64 bg-indigo-800 text-white flex-shrink-0 flex flex-col fixed top-0 right-0 h-full z-30 overflow-y-auto">

            {{-- Logo --}}
            <div class="px-6 py-5 border-b border-indigo-700">
                <a href="{{ route('admin.home') }}" class="flex items-center gap-3">
                    <img src="/images/logo-192.png" alt="HeroKid"
                        class="h-8 md:h-16 w-8 md:w-16 rounded-lg object-contain bg-white p-0.5">
                    <span class="font-extrabold text-lg text-white">HeroKid</span>
                    <span class="text-indigo-300 text-xs font-bold">Admin</span>
                </a>
            </div>

            {{-- Navigation --}}
            @php
                $navLink = 'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold transition';
                $idleLink = 'text-indigo-200 hover:bg-indigo-700 hover:text-white';
                $activeLink = 'bg-indigo-600 text-white';
                $canOperations = auth()->user()->hasAnyPermission([
                    'orders.view', 'visitor_carts.view', 'stories.view', 'story_categories.view', 'store.products.view',
                    'store.categories.view', 'store.homepage_sections.view', 'store.upsell_rules.view', 'customers.view',
                    'production_studio.view',
                ]);
                $canDashboard = auth()->user()->hasAnyPermission(['dashboard.view', 'analytics.view', 'visitor_carts.view']);
                $canContent = auth()->user()->hasAnyPermission([
                    'content.testimonials.view', 'content.faqs.view', 'content.messages.view',
                ]);
                $canSettings = auth()->user()->hasAnyPermission([
                    'settings.site.view', 'settings.production_prompt.view', 'settings.delivery_zones.view',
                    'settings.pricing.view', 'settings.ai_providers.view', 'admin_users.view', 'admin_users.create',
                    'admin_users.permissions.manage', 'activity_logs.view',
                ]);
            @endphp
            <nav class="flex-1 px-4 py-5 space-y-1">
                @if($canDashboard)
                    <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">الرئيسية</p>
                    @can('dashboard.view')
                        <a href="{{ route('admin.dashboard.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.dashboard.*') ? $activeLink : $idleLink }}">لوحة القيادة</a>
                    @endcan
                    @can('analytics.view')
                        <a href="{{ route('admin.analytics.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.analytics.*') ? $activeLink : $idleLink }}">تحليلات الموقع</a>
                    @endcan
                    @can('visitor_carts.view')
                        <a href="{{ route('admin.visitor-carts.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.visitor-carts.*') ? $activeLink : $idleLink }}">سلات الزوار</a>
                    @endcan
                @endif

                @if($canOperations)
                    <div class="pt-4">
                        <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">العمليات</p>
                        @can('orders.view')
                            <a href="{{ route('admin.orders.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.orders.*') ? $activeLink : $idleLink }}">الطلبات</a>
                        @endcan
                        @if(config('production_studio.enabled'))
                            @can('production_studio.view')
                                <a href="{{ route('admin.production-studio.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.production-studio.*') ? $activeLink : $idleLink }}">استوديو الإنتاج</a>
                            @endcan
                        @endif
                        @can('stories.view')
                            <a href="{{ route('admin.stories.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.stories.*') ? $activeLink : $idleLink }}">القصص</a>
                        @endcan
                        @if(auth()->user()->hasAnyPermission(['store.products.view', 'store.categories.view', 'store.homepage_sections.view', 'store.upsell_rules.view']))
                            <a href="{{ auth()->user()->hasPermission('store.products.view') ? route('admin.products.index') : route('admin.product-categories.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.products.*') || request()->routeIs('admin.product-categories.*') || request()->routeIs('admin.homepage-store-sections.*') || request()->routeIs('admin.upsell-rules.*') ? $activeLink : $idleLink }}">المتجر</a>
                        @endif
                        @can('customers.view')
                            <a href="{{ route('admin.customers.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.customers.*') ? $activeLink : $idleLink }}">Customers</a>
                        @endcan
                        @can('story_categories.view')
                            <a href="{{ route('admin.categories.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.categories.*') ? $activeLink : $idleLink }}">التصنيفات</a>
                        @endcan
                    </div>
                @endif

                @if($canContent)
                    <div class="pt-4">
                        <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">إدارة المحتوى</p>
                        @can('content.testimonials.view')
                            <a href="{{ route('admin.testimonials.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.testimonials.*') ? $activeLink : $idleLink }}">آراء العملاء</a>
                        @endcan
                        @can('content.faqs.view')
                            <a href="{{ route('admin.faqs.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.faqs.*') ? $activeLink : $idleLink }}">الأسئلة الشائعة</a>
                        @endcan
                        @can('content.messages.view')
                            <a href="{{ route('admin.messages.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.messages.*') ? $activeLink : $idleLink }}">الرسائل الواردة</a>
                        @endcan
                    </div>
                @endif

                @if($canSettings)
                    <div class="pt-4">
                        <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">الإعدادات</p>
                        @can('settings.site.view')
                            <a href="{{ route('admin.settings.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.settings.index') || request()->routeIs('admin.settings.update') ? $activeLink : $idleLink }}">إعدادات الموقع</a>
                        @endcan
                        @can('settings.production_prompt.view')
                            <a href="{{ route('admin.settings.story-production-prompt.edit') }}" class="{{ $navLink }} {{ request()->routeIs('admin.settings.story-production-prompt.*') ? $activeLink : $idleLink }}">قالب برومبت الإنتاج</a>
                        @endcan
                        @can('settings.ai_providers.view')
                            <a href="{{ route('admin.settings.ai-providers.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.settings.ai-providers.*') ? $activeLink : $idleLink }}">مزودو الذكاء الاصطناعي</a>
                        @endcan
                        @can('settings.delivery_zones.view')
                            <a href="{{ route('admin.delivery-zones.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.delivery-zones.*') ? $activeLink : $idleLink }}">مناطق التوصيل</a>
                        @endcan
                        @if(auth()->user()->hasAnyPermission(['admin_users.view', 'admin_users.create', 'admin_users.permissions.manage']))
                            <a href="{{ route('admin.users.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.users.*') ? $activeLink : $idleLink }}">إدارة المشرفين</a>
                        @endif
                        @can('settings.pricing.view')
                            <a href="{{ route('admin.pricing.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.pricing.*') ? $activeLink : $idleLink }}">باقات الأسعار</a>
                        @endcan
                        @can('activity_logs.view')
                            <a href="{{ route('admin.activity-logs.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.activity-logs.*') ? $activeLink : $idleLink }}">سجل النشاط</a>
                        @endcan
                    </div>
                @endif
            </nav>

            {{-- User + logout --}}
            <div class="px-4 py-4 border-t border-indigo-700">
                <div class="flex items-center justify-between">
                    <a href="{{ route('admin.users.edit', auth()->user()) }}"
                       class="flex items-center gap-2 min-w-0 hover:opacity-80 transition" title="تعديل حسابي">
                        <div class="w-8 h-8 bg-indigo-500 rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0 ring-2 ring-indigo-400/50">
                            {{ mb_substr(auth()->user()->name ?? 'A', 0, 1) }}
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-white truncate">{{ auth()->user()->name }}</p>
                            <p class="text-xs text-indigo-400 truncate">حسابي ← تعديل</p>
                        </div>
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" title="تسجيل خروج"
                            class="text-indigo-400 hover:text-white transition p-1">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        {{-- ===== MAIN CONTENT AREA (offset by sidebar width) ===== --}}
        <div class="flex-1 flex flex-col min-w-0 mr-64">

            {{-- Top bar --}}
            <header
                class="bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between sticky top-0 z-20">
                <div>
                    @isset($header)
                        {{ $header }}
                    @endisset
                </div>
                <div class="flex items-center gap-4">
                    <a href="{{ url('/') }}" target="_blank"
                        class="text-xs text-gray-500 hover:text-indigo-600 font-semibold flex items-center gap-1 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                        عرض الموقع
                    </a>
                </div>
            </header>

            {{-- Page content --}}
            <main class="flex-1 p-6 lg:p-8">
                @if(session('success'))
                    <div
                        class="bg-green-50 border border-green-300 text-green-800 px-5 py-3 rounded-xl flex items-center gap-2 mb-6">
                        ✅ <span>{{ session('success') }}</span>
                    </div>
                @endif
                @if(session('error'))
                    <div
                        class="bg-red-50 border border-red-300 text-red-800 px-5 py-3 rounded-xl flex items-center gap-2 mb-6">
                        ❌ <span>{{ session('error') }}</span>
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>

@stack('scripts')
</body>

</html>
