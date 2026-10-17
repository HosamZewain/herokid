<x-front-layout>
<x-slot name="pageTitle">تطبيق HeroKid — قصص طفلك، تقرؤوها سوا</x-slot>
<x-slot name="pageDescription">حمّل تطبيق HeroKid للـ iPhone وAndroid واقرأ مع طفلك النسخة الرقمية PDF من قصصه بعد الشراء، واكتشف مكتبة قصص كاملة جاهزة للقراءة.</x-slot>
@push('styles')
    @vite('resources/css/app-download.css')
@endpush
@push('scripts')
    @vite('resources/js/app-download.js')
@endpush
<div class="hk-app" data-app-download data-app-visit-url="{{ route('app.metrics.visit') }}" dir="rtl">
    <section class="hk-app-hero" aria-labelledby="app-title">
        <div class="hk-app-wrap hk-app-hero-grid">
            <div class="hk-app-intro">
                <p class="hk-app-eyebrow"><span></span> وقت حكاية، تجمعكم سوا</p>
                <h1 id="app-title">قصصه.<br><span>في كل وقت.</span></h1>
                <p class="hk-app-lead">اقرأ مع طفلك النسخة الرقمية PDF من قصصه اللي اشتريتها.<br>واكتشفوا مكتبة قصص كاملة جاهزة للقراءة… حكايات ترجعوا لها مرة ورا مرة.</p>
                <p class="hk-app-device" data-device-message>اختار متجر موبايلك وابدأ رحلتك مع HeroKid.</p>
                @include('front.partials.app-store-buttons')
                <p class="hk-app-note">تحميل مجاني · عربي وإنجليزي · للآباء والأمهات</p>
                <a href="#app-screens" class="hk-app-explore">خد لفة جوّه التطبيق <span aria-hidden="true">↓</span></a>
            </div>
            <div class="hk-app-visual" aria-label="لقطات حقيقية من تطبيق HeroKid">
                <span class="hk-app-orbit hk-app-orbit-one" aria-hidden="true"></span><span class="hk-app-orbit hk-app-orbit-two" aria-hidden="true"></span>
                <span class="hk-app-star" aria-hidden="true">✦</span>
                <div class="hk-app-phone hk-app-phone-back"><img src="{{ asset('images/app/catalog.webp') }}" alt="متجر HeroKid: البحث وتصفح القصص والمنتجات" width="600" height="1304" decoding="async"></div>
                <div class="hk-app-phone hk-app-phone-front"><img src="{{ asset('images/app/home.webp') }}" alt="الشاشة الرئيسية لتطبيق HeroKid" width="600" height="1304" fetchpriority="high"></div>
                <span class="hk-app-float hk-app-float-first">حكاية تجمعكم سوا <span aria-hidden="true">♡</span></span>
                <span class="hk-app-float hk-app-float-second">قصصه، أقرب لكم <span aria-hidden="true">↗</span></span>
            </div>
        </div>
    </section>

    <section class="hk-app-wrap hk-app-features" aria-labelledby="app-features-title">
        <div class="hk-app-section-head"><p class="hk-app-kicker">أهم حاجة؟ وقت الحكاية معاه</p><h2 id="app-features-title">قصص طفلك بعد الشراء.<br>ومكتبة تفتح له حكايات جديدة.</h2><p>تجربة القراءة هي الأساس، والتسوّق والتخصيص كمان في نفس المكان.</p></div>
        <div class="hk-app-feature-grid">
            @foreach([
                ['book-open', 'اقرأوا قصصه اللي اشتريتوها', 'بعد شراء قصة لطفلك وتجهيز نسختها الرقمية، تقدر تقرأ معاه PDF من حسابك. قصته باسمه، ترجعوا لها سوا مرة ورا مرة.', 'blue'],
                ['book-open', 'مكتبة قصص جاهزة للقراءة', 'تصفّح مكتبة القصص العامة، اختار حكاية واقرأها كاملة من التطبيق. مكتبة منفصلة عن مشترياتك، مش مجرد صور أغلفة أو مقتطفات.', 'mint'],
                ['shopping-cart', 'واختار له الحكاية اللي بعدها', 'قصص مطبوعة، هدايا مخصصة وأنشطة. خصّص طلبك ببيانات طفلك، وراجع السلة وتابع حالة الطلب من موبايلك.', 'coral'],
            ] as [$icon, $title, $description, $tone])
            <article class="hk-app-feature hk-app-feature-{{ $tone }}"><span class="hk-app-feature-icon"><x-front-icon :name="$icon" /></span><h3>{{ $title }}</h3><p>{{ $description }}</p></article>
            @endforeach
        </div>
    </section>

    <section id="app-screens" class="hk-app-screens" aria-labelledby="app-screens-title">
        <div class="hk-app-wrap">
            <div class="hk-app-section-head"><p class="hk-app-kicker">شوفها بنفسك</p><h2 id="app-screens-title">لفة سريعة، قبل التحميل.</h2><p>شاشات حقيقية من التطبيق. على الموبايل اسحب علشان تشوف أكتر.</p></div>
            <div class="hk-app-screen-track" tabindex="0" role="region" aria-label="صور شاشات التطبيق، قابلة للتمرير أفقيًا">
                @foreach([
                    ['home', 'بداية مليانة احتمالات', 'اكتشف عالم القصص والهدايا والأنشطة.'],
                    ['catalog', 'اختار اللي يناسب طفلك', 'بحث وفلاتر وتفاصيل تساعدك تقرر.'],
                    ['account', 'حسابك، ببساطة', 'إعداداتك والمساعدة، في مكان واضح.'],
                ] as $index => [$screen, $title, $caption])
                <figure class="hk-app-screen"><figcaption><span>0{{ $index + 1 }}</span><h3>{{ $title }}</h3><p>{{ $caption }}</p></figcaption><div class="hk-app-phone"><img src="{{ asset('images/app/'.$screen.'.webp') }}" alt="{{ $title }} — شاشة من تطبيق HeroKid" width="600" height="1304" loading="lazy" decoding="async"></div></figure>
                @endforeach
            </div>
        </div>
    </section>

    <section class="hk-app-wrap hk-app-steps" aria-labelledby="app-steps-title">
        <div class="hk-app-section-head"><p class="hk-app-kicker">حكاياته مش بتخلص عند آخر صفحة</p><h2 id="app-steps-title">افتح التطبيق. اختار حكاية. واقرؤوها سوا.</h2></div>
        <ol class="hk-app-step-grid"><li><span>١</span><h3>حمّل وافتح حسابك</h3><p>سجّل بنفس حساب أو رقم موبايل الشراء علشان توصل لقصص طفلك المرتبطة بيك.</p></li><li><span>٢</span><h3>افتح قصص طفلك</h3><p>بعد تجهيز النسخة الرقمية وإتاحتها لحسابك، افتح القصة واقرأ PDF مع طفلك.</p></li><li><span>٣</span><h3>واكتشفوا حكاية جديدة</h3><p>من مكتبة القصص العامة تقدروا تختاروا قصصًا كاملة جاهزة للقراءة، منفصلة عن القصص اللي اشتريتوها.</p></li></ol>
        <p class="hk-app-note">النسخة الرقمية للقصة المشتراة لا تظهر بمجرد الدفع؛ يلزم تجهيزها وإتاحتها لحساب صاحب الطلب. ولا تتغيّر خطوات اعتماد التصميم على واتساب أو تجهيز وطباعة طلبك.</p>
    </section>

    <section class="hk-app-wrap hk-app-bottom" aria-labelledby="app-download-title"><div><p class="hk-app-kicker">جاهزين لوقت حكاية؟</p><h2 id="app-download-title">قصته قريبة.<br>وحكاية جديدة مستنياه.</h2><p data-device-message>اختار متجر موبايلك وابدأ رحلتك مع HeroKid.</p></div><div>@include('front.partials.app-store-buttons')<p class="hk-app-note">بتفضّل المتصفح؟ اكتشف <a href="{{ route('story-library.index') }}">مكتبة القصص على الموقع</a>.</p></div></section>
</div>
</x-front-layout>
