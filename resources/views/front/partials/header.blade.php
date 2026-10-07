@php
    $navItems = [
        ['label' => 'قصص', 'url' => route('stories.index'), 'active' => request()->routeIs('stories.*') || request('type') === 'stories'],
        ['label' => 'منتجات مخصصة', 'url' => route('shop.index', ['type' => 'products']), 'active' => request()->routeIs('shop.*') && !request()->routeIs('shop.package.*') && !in_array(request('type'), ['stories', 'activities'])],
        ['label' => 'أنشطة وتعلّم', 'url' => route('shop.index', ['type' => 'activities']), 'active' => request('type') === 'activities'],
        ['label' => 'باقات', 'url' => route('packages'), 'active' => request()->routeIs('packages', 'shop.package.*')],
    ];
@endphp
<header class="hk-site-header">
    <nav class="hk-header-inner" aria-label="القائمة الرئيسية">
        <a href="{{ route('home') }}" class="hk-brand" aria-label="HeroKid — الصفحة الرئيسية">
            <img src="{{ asset('images/logo-192.png') }}" srcset="{{ asset('images/logo-96.png') }} 96w, {{ asset('images/logo-192.png') }} 192w, {{ asset('images/logo-320.png') }} 320w" sizes="(min-width: 1024px) 120px, 82px" width="192" height="164" alt="HeroKid Logo">
        </a>
        <div class="hk-desktop-nav">
            @foreach($navItems as $navItem)
                <a href="{{ $navItem['url'] }}" @class(['hk-nav-link', 'is-active' => $navItem['active']]) @if($navItem['active']) aria-current="page" @endif>{{ $navItem['label'] }}</a>
            @endforeach
            @if(setting('child_identity_enabled', '1') === '1')
                <a href="{{ route('child-identity.index') }}" @class(['hk-nav-link hk-identity-link', 'is-active' => request()->routeIs('child-identity.*')])>هوية طفلك</a>
            @endif
            @include('front.partials.guide-menu')
        </div>
        <div class="hk-header-actions">
            <a href="{{ route('cart.index') }}" class="hk-icon-button hk-cart-link" aria-label="سلة المشتريات">
                <x-front-icon name="shopping-cart" />
                @if($cartItemCount > 0)
                    <span data-cart-count class="hk-cart-count">{{ $cartItemCount }}</span>
                @endif
            </a>
            <a href="{{ route('track.index') }}" class="hk-track-link">تتبع طلبك</a>
            @auth
                <a href="{{ route('dashboard') }}" class="hk-account-link" aria-label="حسابي"><x-front-icon name="user-circle" /><span>حسابي</span></a>
            @else
                <a href="{{ route('login') }}" class="hk-account-link" aria-label="تسجيل الدخول"><x-front-icon name="user-circle" /><span>دخول</span></a>
            @endauth
            <button type="button" data-front-menu-toggle aria-expanded="false" aria-controls="front-mobile-menu" aria-label="فتح القائمة" class="hk-icon-button hk-menu-toggle">
                <x-front-icon name="bars-3" data-front-menu-open-icon />
                <x-front-icon name="x-mark" data-front-menu-close-icon class="hidden" />
            </button>
        </div>
    </nav>
    <nav id="front-mobile-menu" data-front-mobile-menu class="hidden hk-mobile-menu" aria-label="القائمة الرئيسية للموبايل">
        <a href="{{ route('home') }}">الرئيسية</a>
        @foreach($navItems as $navItem)
            <a href="{{ $navItem['url'] }}" @if($navItem['active']) aria-current="page" @endif>{{ $navItem['label'] }}</a>
        @endforeach
        @if(setting('child_identity_enabled', '1') === '1')
            <a href="{{ route('child-identity.index') }}">اصنع هوية طفلك</a>
        @endif
        @include('front.partials.guide-menu', ['mobileGuide' => true])
        <a href="{{ route('shop.index') }}">متجر القصص والمنتجات</a>
        <a href="{{ route('cart.index') }}">السلة @if($cartItemCount > 0)<span data-cart-count>{{ $cartItemCount }}</span>@endif</a>
        @auth
            <a href="{{ route('dashboard') }}">حسابي</a>
        @else
            <a href="{{ route('login') }}">تسجيل الدخول</a>
            <a href="{{ route('register') }}">إنشاء حساب</a>
        @endauth
    </nav>
</header>
