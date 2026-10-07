@php($mobileGuide = $mobileGuide ?? false)
<details {{ $mobileGuide ? 'data-front-guide-menu-mobile' : 'data-front-guide-menu' }} class="hk-guide {{ $mobileGuide ? 'hk-guide--mobile' : '' }}">
    <summary @class(['hk-nav-link', 'is-active' => $guideMenuActive])>
        <span>دليل HeroKid</span>
        <x-front-icon name="chevron-down" />
    </summary>
    <div class="hk-guide-panel">
        @foreach(['about' => 'عن HeroKid', 'how-it-works' => 'كيف يعمل؟', 'faq' => 'الأسئلة الشائعة', 'track.index' => 'تتبع الطلب'] as $guideRoute => $guideLabel)
            <a href="{{ route($guideRoute) }}" @if(request()->routeIs($guideRoute === 'track.index' ? 'track.*' : $guideRoute)) aria-current="page" @endif>{{ $guideLabel }}</a>
        @endforeach
    </div>
</details>
