<x-front-layout>
<x-slot name="pageTitle">تطبيق HeroKid — عالم طفلك، أقرب لك</x-slot>
<x-slot name="pageDescription">حمّل تطبيق HeroKid للـ iPhone وAndroid. اكتشف القصص والهدايا المخصصة، خصّص طلبك ببيانات طفلك وتابع طلباتك من موبايلك.</x-slot>
@push('styles')
    @vite('resources/css/app-download.css')
@endpush
@push('scripts')
    @vite('resources/js/app-download.js')
@endpush
<div class="hk-app" data-app-download dir="rtl">
    <section class="hk-app-hero" aria-labelledby="app-title">
        <div class="hk-app-wrap hk-app-hero-grid">
            <div class="hk-app-intro">
                <p class="hk-app-eyebrow"><span></span> HeroKid معاك، في كل حتة</p>
                <h1 id="app-title">عالم طفلك.<br><span>أقرب لك.</span></h1>
                <p class="hk-app-lead">حكاية يبقى بطلها، هدية باسمه، ونشاط يفتح خياله.<br>اختار وخصّص وتابع طلبك… كله من موبايلك.</p>
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
                <span class="hk-app-float hk-app-float-first">حاجات تشبه طفلك <span aria-hidden="true">♡</span></span>
                <span class="hk-app-float hk-app-float-second">من اختيارك… لحد بابك <span aria-hidden="true">↗</span></span>
            </div>
        </div>
    </section>

    <section class="hk-app-wrap hk-app-features" aria-labelledby="app-features-title">
        <div class="hk-app-section-head"><p class="hk-app-kicker">تفاصيل صغيرة، فرق كبير</p><h2 id="app-features-title">كل اللي بتحبه في HeroKid.<br>في مكان واحد.</h2><p>تجربة بسيطة ليك، واختيارات على مقاس عالمه.</p></div>
        <div class="hk-app-feature-grid">
            @foreach([
                ['book-open', 'اختيارات تشبهه', 'قصص مطبوعة، منتجات مخصصة وأنشطة. تصفّح وابحث واختار اللي يناسب عمره واهتماماته.', 'blue'],
                ['pencil', 'لمسته في كل تفصيلة', 'اختار المنتج وضيف بيانات الطفل والصور المطلوبة حسب اختياراته، وراجع طلبك قبل الإرسال.', 'mint'],
                ['shopping-cart', 'طلبك معاك خطوة بخطوة', 'راجع طلباتك وتابع حالتها، وخلّي حسابك وعناوين التوصيل قريبين منك دايمًا.', 'coral'],
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
        <div class="hk-app-section-head"><p class="hk-app-kicker">من الفكرة للفرحة</p><h2 id="app-steps-title">اختار. خصّص. وسيب الباقي علينا.</h2></div>
        <ol class="hk-app-step-grid"><li><span>١</span><h3>حمّل وخد لفة</h3><p>تصفّح بدون حساب. ولما تحب تطلب، سجّل بالموبايل أو الإيميل.</p></li><li><span>٢</span><h3>خلّيه مخصوص له</h3><p>اختار القصة أو المنتج، وأكمل بيانات التخصيص والعنوان.</p></li><li><span>٣</span><h3>تابع الفرحة وهي بتتجهّز</h3><p>تابع حالة طلبك من التطبيق. وفريقنا يتواصل معاك على واتساب للمعاينة وترتيب الدفع.</p></li></ol>
    </section>

    <section class="hk-app-wrap hk-app-bottom" aria-labelledby="app-download-title"><div><p class="hk-app-kicker">جاهزين لحكاية جديدة؟</p><h2 id="app-download-title">خلي عالمه أقرب.<br>حمّل HeroKid.</h2><p data-device-message>اختار متجر موبايلك وابدأ رحلتك مع HeroKid.</p></div><div>@include('front.partials.app-store-buttons')<p class="hk-app-note">بتفضّل المتصفح؟ <a href="{{ route('shop.index') }}">كمّل من الموقع</a></p></div></section>
</div>
</x-front-layout>
