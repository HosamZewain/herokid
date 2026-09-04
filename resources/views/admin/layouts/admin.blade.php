<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) && trim((string) $title) !== '' ? trim((string) $title).' — '.config('app.name') : config('app.name').' — لوحة الإدارة' }}</title>
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

<body class="font-sans antialiased bg-gray-50 overflow-x-hidden">

    <div class="flex min-h-screen" dir="rtl" data-admin-shell>

        {{-- Mobile sidebar backdrop --}}
        <button type="button"
            class="fixed inset-0 z-30 hidden bg-slate-950/50 backdrop-blur-[1px] lg:hidden"
            aria-label="إغلاق قائمة الإدارة"
            data-admin-sidebar-overlay></button>

        {{-- ===== SIDEBAR ===== --}}
        <aside
            id="admin-sidebar"
            class="fixed inset-y-0 right-0 z-40 flex h-full w-72 max-w-[calc(100vw-3rem)] translate-x-full flex-col overflow-y-auto bg-indigo-800 text-white shadow-2xl transition-transform duration-200 ease-out lg:w-64 lg:max-w-none lg:translate-x-0 lg:shadow-none"
            aria-label="قائمة الإدارة"
            data-admin-sidebar>

            {{-- Logo --}}
            <div class="flex items-center justify-between gap-3 border-b border-indigo-700 px-4 py-4 sm:px-6 sm:py-5">
                <a href="{{ route('admin.home') }}" class="flex min-w-0 items-center gap-3">
                    <img src="/images/logo-192.png" alt="HeroKid"
                        class="h-8 md:h-16 w-8 md:w-16 rounded-lg object-contain bg-white p-0.5">
                    <span class="truncate font-extrabold text-lg text-white">HeroKid</span>
                    <span class="text-indigo-300 text-xs font-bold">Admin</span>
                </a>
                <button type="button"
                    class="inline-flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg text-indigo-100 transition hover:bg-indigo-700 hover:text-white focus:outline-none focus:ring-2 focus:ring-white/70 lg:hidden"
                    aria-label="إغلاق قائمة الإدارة"
                    data-admin-sidebar-close>
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Navigation --}}
            @php
                $navLink = 'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold transition';
                $idleLink = 'text-indigo-200 hover:bg-indigo-700 hover:text-white';
                $activeLink = 'bg-indigo-600 text-white';
                $canHome = auth()->user()->hasAnyPermission(['dashboard.view']);
                $canReports = auth()->user()->hasAnyPermission([
                    'analytics.view', 'sales_reports.view', 'order_reports.view', 'visitor_carts.view', 'child_identities.view_share_report',
                ]);
                $canFinance = auth()->user()->hasAnyPermission(['expenses.view']);
                $canFulfillment = auth()->user()->hasAnyPermission([
                    'orders.view', 'orders.create', 'booklet_previews.view', 'production_studio.view', 'child_identities.view', 'bosta.view',
                ]);
                $canCatalog = auth()->user()->hasAnyPermission([
                    'stories.view', 'story_categories.view', 'store.products.view', 'store.categories.view',
                    'store.homepage_sections.view', 'store.upsell_rules.view', 'customers.view', 'settings.pricing.view',
                ]);
                $canContent = auth()->user()->hasAnyPermission([
                    'content.testimonials.view', 'content.faqs.view', 'content.messages.view',
                ]);
                $canSettings = auth()->user()->hasAnyPermission([
                    'settings.site.view', 'settings.production_prompt.view', 'settings.delivery_zones.view',
                    'settings.ai_providers.view', 'settings.notifications.view',
                    'settings.mobile.view',
                    'settings.order_statuses.manage',
                    'child_identities.settings',
                ]);
                $canIntegrations = auth()->user()->hasAnyPermission(['robodesk.view', 'robodesk.manage', 'agent_api.tokens.manage']);
                $canAdministration = auth()->user()->hasAnyPermission([
                    'admin_users.view', 'admin_users.create', 'admin_users.permissions.manage', 'activity_logs.view',
                ]);
            @endphp
            <nav class="flex-1 px-4 py-5 space-y-1">
                @if($canHome)
                    <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">الرئيسية</p>
                    @can('dashboard.view')
                        <a href="{{ route('admin.dashboard.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.dashboard.*') ? $activeLink : $idleLink }}">لوحة القيادة</a>
                    @endcan
                @endif

                @if($canFinance)
                    <div class="pt-4">
                        <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">المالية</p>
                        @can('expenses.view')
                            <a href="{{ route('admin.expenses.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.expenses.*') ? $activeLink : $idleLink }}">المصروفات</a>
                        @endcan
                    </div>
                @endif

                @if($canFulfillment)
                    <div class="pt-4">
                        <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">الطلبات والإنتاج</p>
                        @can('orders.view')
                            <a href="{{ route('admin.orders.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.orders.*') && ! request()->routeIs('admin.orders.create', 'admin.orders.store') ? $activeLink : $idleLink }}">الطلبات</a>
                        @endcan
                        @can('orders.create')
                            <a href="{{ route('admin.orders.create') }}" class="{{ $navLink }} {{ request()->routeIs('admin.orders.create') ? $activeLink : $idleLink }}">إضافة طلب</a>
                        @endcan
                        @can('bosta.view')
                            <a href="{{ route('admin.bosta.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.bosta.*') ? $activeLink : $idleLink }}">Bosta للشحن</a>
                        @endcan
                        @can('booklet_previews.view')
                            <a href="{{ route('admin.booklet-previews.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.booklet-previews.*') ? $activeLink : $idleLink }}">معاينات الكتب</a>
                        @endcan
                        @if(config('production_studio.enabled'))
                            @can('production_studio.view')
                                <a href="{{ route('admin.production-studio.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.production-studio.*') ? $activeLink : $idleLink }}">استوديو الإنتاج</a>
                            @endcan
                        @endif
                        @can('child_identities.view')
                            <a href="{{ route('admin.child-identities.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.child-identities.*') && !request()->routeIs('admin.child-identities.settings.*') && !request()->routeIs('admin.child-identities.share-report') ? $activeLink : $idleLink }}">هويات الأطفال</a>
                        @endcan
                    </div>
                @endif

                @if($canCatalog)
                    <div class="pt-4">
                        <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">الكتالوج والعملاء</p>
                        @can('stories.view')
                            <a href="{{ route('admin.stories.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.stories.*') ? $activeLink : $idleLink }}">القصص</a>
                        @endcan
                        @if(auth()->user()->hasAnyPermission(['store.products.view', 'store.categories.view', 'store.homepage_sections.view', 'store.upsell_rules.view']))
                            <a href="{{ auth()->user()->hasPermission('store.products.view') ? route('admin.products.index') : route('admin.product-categories.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.products.*') || request()->routeIs('admin.product-categories.*') || request()->routeIs('admin.homepage-store-sections.*') || request()->routeIs('admin.upsell-rules.*') ? $activeLink : $idleLink }}">المتجر</a>
                        @endif
                        @can('settings.pricing.view')
                            <a href="{{ route('admin.pricing.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.pricing.*') ? $activeLink : $idleLink }}">الباقات</a>
                        @endcan
                        @can('customers.view')
                            <a href="{{ route('admin.customers.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.customers.*') ? $activeLink : $idleLink }}">العملاء</a>
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

                @if($canReports)
                    <div class="pt-4">
                        <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">التقارير</p>
                        @can('analytics.view')
                            <a href="{{ route('admin.analytics.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.analytics.*') ? $activeLink : $idleLink }}">تحليلات الموقع</a>
                        @endcan
                        @can('sales_reports.view')
                            <a href="{{ route('admin.sales-report.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.sales-report.*') ? $activeLink : $idleLink }}">تقرير المبيعات</a>
                        @endcan
                        @can('order_reports.view')
                            <a href="{{ route('admin.order-report.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.order-report.*') ? $activeLink : $idleLink }}">تقرير الطلبات</a>
                        @endcan
                        @can('child_identities.view_share_report')
                            <a href="{{ route('admin.child-identities.share-report') }}" class="{{ $navLink }} {{ request()->routeIs('admin.child-identities.share-report') ? $activeLink : $idleLink }}">تقرير مشاركة الهويات</a>
                        @endcan
                        @can('visitor_carts.view')
                            <a href="{{ route('admin.visitor-carts.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.visitor-carts.*') ? $activeLink : $idleLink }}">سلات الزوار</a>
                        @endcan
                    </div>
                @endif

                @if($canSettings)
                    <div class="pt-4">
                        <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">الإعدادات</p>
                        @can('settings.site.view')
                            <a href="{{ route('admin.settings.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.settings.index') || request()->routeIs('admin.settings.update') ? $activeLink : $idleLink }}">إعدادات الموقع</a>
                        @endcan
                        @can('settings.order_statuses.manage')
                            <a href="{{ route('admin.settings.order-statuses.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.settings.order-statuses.*') ? $activeLink : $idleLink }}">حالات الطلبات</a>
                        @endcan
                        @can('settings.site.view')
                            <a href="{{ route('admin.settings.order-whatsapp-messages.edit') }}" class="{{ $navLink }} {{ request()->routeIs('admin.settings.order-whatsapp-messages.*') ? $activeLink : $idleLink }}">رسائل واتساب للطلبات</a>
                        @endcan
                        @can('settings.delivery_zones.view')
                            <a href="{{ route('admin.delivery-zones.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.delivery-zones.*') ? $activeLink : $idleLink }}">مناطق التوصيل</a>
                        @endcan
                        @can('settings.production_prompt.view')
                            <a href="{{ route('admin.settings.story-production-prompt.edit') }}" class="{{ $navLink }} {{ request()->routeIs('admin.settings.story-production-prompt.*') ? $activeLink : $idleLink }}">قالب برومبت الإنتاج</a>
                        @endcan
                        @can('settings.ai_providers.view')
                            <a href="{{ route('admin.settings.ai-providers.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.settings.ai-providers.*') ? $activeLink : $idleLink }}">مزودو الذكاء الاصطناعي</a>
                        @endcan
                        @can('child_identities.settings')
                            <a href="{{ route('admin.child-identities.settings.edit') }}" class="{{ $navLink }} {{ request()->routeIs('admin.child-identities.settings.*') ? $activeLink : $idleLink }}">إعدادات هويات الأطفال</a>
                        @endcan
                        @can('settings.notifications.view')
                            <a href="{{ route('admin.settings.notifications.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.settings.notifications.*') ? $activeLink : $idleLink }}">مركز التنبيهات</a>
                        @endcan
                        @can('settings.mobile.view')
                            <a href="{{ route('admin.mobile-operations.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.mobile-operations.*') ? $activeLink : $idleLink }}">تطبيق الهاتف</a>
                        @endcan
                    </div>
                @endif

                @if($canIntegrations)
                    <div class="pt-4">
                        <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">التكاملات</p>
                        @can('robodesk.view')
                            <a href="{{ route('admin.robodesk.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.robodesk.*') ? $activeLink : $idleLink }}">RoboDesk وواتساب</a>
                        @endcan
                        @can('agent_api.tokens.manage')
                            <a href="{{ route('admin.agent-api-tokens.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.agent-api-tokens.*') ? $activeLink : $idleLink }}">Agent API Tokens</a>
                        @endcan
                    </div>
                @endif

                @if($canAdministration)
                    <div class="pt-4">
                        <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">الإدارة والأمان</p>
                        @if(auth()->user()->hasAnyPermission(['admin_users.view', 'admin_users.create', 'admin_users.permissions.manage']))
                            <a href="{{ route('admin.users.index') }}" class="{{ $navLink }} {{ request()->routeIs('admin.users.*') ? $activeLink : $idleLink }}">إدارة المشرفين</a>
                        @endif
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
        <div class="flex min-w-0 flex-1 flex-col lg:mr-64">

            {{-- Top bar --}}
            <header
                class="sticky top-0 z-20 flex min-h-16 flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-white px-4 py-3 sm:px-6 sm:py-4 lg:flex-nowrap">
                <div class="flex min-w-0 items-center gap-3">
                    <button type="button"
                        class="inline-flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 lg:hidden"
                        aria-label="فتح قائمة الإدارة"
                        aria-controls="admin-sidebar"
                        aria-expanded="false"
                        data-admin-sidebar-toggle>
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <div class="min-w-0 [&_h1]:break-words [&_h2]:break-words">
                    @isset($header)
                        {{ $header }}
                    @endisset
                    </div>
                </div>
                @can('orders.view')
                    <form method="GET" action="{{ route('admin.orders.index') }}" class="order-3 w-full sm:order-none sm:mx-auto sm:w-72 lg:w-80" role="search" data-admin-order-quick-search>
                        <input type="hidden" name="catalog_type" value="all">
                        <input type="hidden" name="lifecycle" value="all">
                        <label for="admin-order-quick-search" class="sr-only">بحث سريع عن طلب</label>
                        <div class="relative">
                            <input id="admin-order-quick-search" name="q" type="search"
                                value="{{ request()->routeIs('admin.orders.index') ? request('q') : '' }}"
                                placeholder="رقم الطلب أو موبايل العميل"
                                class="w-full rounded-xl border-gray-200 bg-gray-50 py-2.5 pe-3 ps-11 text-right text-sm focus:border-indigo-500 focus:bg-white focus:ring-indigo-500">
                            <button type="submit" title="بحث في كل الطلبات" aria-label="بحث في كل الطلبات"
                                class="absolute inset-y-1 start-1 grid w-9 place-items-center rounded-lg bg-indigo-600 text-white transition hover:bg-indigo-700">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" />
                                </svg>
                            </button>
                        </div>
                    </form>
                @endcan
                <div class="flex flex-shrink-0 items-center gap-2 sm:gap-4">
                    @isset($headerActions)
                        {{ $headerActions }}
                    @endisset
                    <a href="{{ url('/') }}" target="_blank"
                        class="flex items-center gap-1 text-xs font-semibold text-gray-500 transition hover:text-indigo-600">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                        <span class="hidden sm:inline">عرض الموقع</span>
                    </a>
                </div>
            </header>

            {{-- Page content --}}
            <main class="admin-content min-w-0 flex-1 overflow-x-hidden p-3 sm:p-6 lg:p-8">
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

    @isset($leftDrawer)
        {{ $leftDrawer }}
    @endisset

@stack('scripts')
</body>

</html>
