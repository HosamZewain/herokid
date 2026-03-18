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
    <link rel="icon" type="image/jpeg" href="/images/logo.png">

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
                <a href="{{ route('admin.dashboard.index') }}" class="flex items-center gap-3">
                    <img src="/images/logo.png" alt="HeroKid"
                        class="h-8 md:h-16 w-8 md:w-16 rounded-lg object-contain bg-white p-0.5">
                    <span class="font-extrabold text-lg text-white">HeroKid</span>
                    <span class="text-indigo-300 text-xs font-bold">Admin</span>
                </a>
            </div>

            {{-- Navigation --}}
            <nav class="flex-1 px-4 py-5 space-y-1">

                {{-- Main --}}
                <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">الرئيسية</p>

                <a href="{{ route('admin.dashboard.index') }}"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold transition
                      {{ request()->routeIs('admin.dashboard.*') ? 'bg-indigo-600 text-white' : 'text-indigo-200 hover:bg-indigo-700 hover:text-white' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    لوحة القيادة
                </a>

                {{-- Operations --}}
                <div class="pt-4">
                    <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">العمليات</p>

                    <a href="{{ route('admin.orders.index') }}"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold transition
                          {{ request()->routeIs('admin.orders.*') ? 'bg-indigo-600 text-white' : 'text-indigo-200 hover:bg-indigo-700 hover:text-white' }}">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        الطلبات
                        {{-- badge for orders needing action --}}
                    </a>

                    <a href="{{ route('admin.stories.index') }}"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold transition
                          {{ request()->routeIs('admin.stories.*') ? 'bg-indigo-600 text-white' : 'text-indigo-200 hover:bg-indigo-700 hover:text-white' }}">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                        القصص
                    </a>

                    <a href="{{ route('admin.categories.index') }}"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold transition
                          {{ request()->routeIs('admin.categories.*') ? 'bg-indigo-600 text-white' : 'text-indigo-200 hover:bg-indigo-700 hover:text-white' }}">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                        </svg>
                        التصنيفات
                    </a>
                </div>

                {{-- Content --}}
                <div class="pt-4">
                    <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">إدارة المحتوى</p>

                    <a href="{{ route('admin.testimonials.index') }}"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold transition
                          {{ request()->routeIs('admin.testimonials.*') ? 'bg-indigo-600 text-white' : 'text-indigo-200 hover:bg-indigo-700 hover:text-white' }}">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                        </svg>
                        آراء العملاء
                    </a>

                    <a href="{{ route('admin.faqs.index') }}"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold transition
                          {{ request()->routeIs('admin.faqs.*') ? 'bg-indigo-600 text-white' : 'text-indigo-200 hover:bg-indigo-700 hover:text-white' }}">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        الأسئلة الشائعة
                    </a>

                    <a href="{{ route('admin.messages.index') }}"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold transition
                          {{ request()->routeIs('admin.messages.*') ? 'bg-indigo-600 text-white' : 'text-indigo-200 hover:bg-indigo-700 hover:text-white' }}">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        الرسائل الواردة
                    </a>
                </div>

                {{-- Settings --}}
                <div class="pt-4">
                    <p class="px-3 text-xs font-bold text-indigo-400 uppercase tracking-wider mb-2">الإعدادات</p>

                    <a href="{{ route('admin.settings.index') }}"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold transition
                          {{ request()->routeIs('admin.settings.*') ? 'bg-indigo-600 text-white' : 'text-indigo-200 hover:bg-indigo-700 hover:text-white' }}">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        إعدادات الموقع
                    </a>

                    <a href="{{ route('admin.users.index') }}"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold transition
                          {{ request()->routeIs('admin.users.*') ? 'bg-indigo-600 text-white' : 'text-indigo-200 hover:bg-indigo-700 hover:text-white' }}">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        إدارة المشرفين
                    </a>

                    <a href="{{ route('admin.pricing.index') }}"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-semibold transition
                          {{ request()->routeIs('admin.pricing.*') ? 'bg-indigo-600 text-white' : 'text-indigo-200 hover:bg-indigo-700 hover:text-white' }}">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        باقات الأسعار
                    </a>
                </div>
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