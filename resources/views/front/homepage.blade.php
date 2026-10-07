@php
    $storiesUrl = route('stories.index');
    $productsUrl = route('shop.index', ['type' => 'products']);
    $activitiesUrl = route('shop.index', ['type' => 'activities']);
    $homeImages = asset('images/homepage');
    $homeCategories = [
        ['title' => 'يعيش الحكاية', 'copy' => 'قصص مخصصة باسمه وصورته، بالعربي أو الإنجليزي.', 'cta' => 'شوف القصص', 'url' => $storiesUrl, 'image' => 'category-story', 'enabled' => true],
        ['title' => 'يحط بصمته', 'copy' => 'منتجات مدرسية وهدايا مخصصة تحمل اسمه وتعبّر عنه.', 'cta' => 'شوف المنتجات', 'url' => $productsUrl, 'image' => 'category-products', 'enabled' => $shopEnabled],
        ['title' => 'يجرب ويكتشف', 'copy' => 'أنشطة مطبوعة تفتح خياله وتخليه يستمتع بعيدًا عن الشاشات.', 'cta' => 'شوف الأنشطة', 'url' => $activitiesUrl, 'image' => 'category-activities', 'enabled' => $shopEnabled],
    ];
@endphp
<div class="hk-home" data-homepage-design="workshop">
    @if(homepage_section_enabled('hero'))
        <section data-home-section="hero" class="hero" aria-labelledby="hero-title">
            <div class="hero-copy">
                <h1 id="hero-title">{{ setting('home_workshop_title_1', 'كل طفل عنده عالم.') }}<br><span>{{ setting('home_workshop_title_2', 'خلّيه يسيب بصمته.') }}</span></h1>
                <p class="hk-hero-description">{{ setting('home_workshop_subtitle', "حكايات يعيشها.\nحاجات تحمل اسمه.\nوأنشطة يجرب فيها ويكتشف.") }}</p>
                <a href="{{ homepage_section_enabled('categories') ? '#world' : route('shop.index') }}" class="button button--yellow">اكتشف عالم HeroKid <x-front-icon name="arrow-left" /></a>
                <a href="{{ route('shop.index') }}" class="hero-secondary">تصفّح كل المنتجات <x-front-icon name="arrow-left" /></a>
            </div>
            <picture class="hero-art">
                <source media="(min-width: 761px)" srcset="{{ $homeImages }}/hero-banner.webp">
                <img src="{{ $homeImages }}/hero-products.webp" width="1536" height="1024" alt="عالم HeroKid: قصة مخصصة ودفتر وملصقات وكتاب متاهات" fetchpriority="high" decoding="async">
            </picture>
        </section>
    @endif

    @if(homepage_section_enabled('categories'))
        <section data-home-section="categories" class="world section" id="world" aria-labelledby="world-title">
            <div class="content-width">
                <h2 id="world-title">{{ $shopEnabled ? '٣ طرق نخلّي يومه أحلى' : 'حكايات نخلّي بيها يومه أحلى' }}</h2>
                <div class="category-grid">
                    @foreach($homeCategories as $category)
                        @if($category['enabled'])
                            <article class="category">
                                <a href="{{ $category['url'] }}" class="category-image-link" aria-label="{{ $category['cta'] }}"><img src="{{ $homeImages }}/{{ $category['image'] }}.webp" alt="{{ $category['title'] }}" width="1586" height="992" loading="lazy" decoding="async"></a>
                                <h3>{{ $category['title'] }}</h3><p>{{ $category['copy'] }}</p>
                                <a href="{{ $category['url'] }}" class="button button--outline">{{ $category['cta'] }} <x-front-icon name="arrow-left" /></a>
                            </article>
                        @endif
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if($homeCatalogItems->isNotEmpty())
        <section class="product-shelf section" aria-labelledby="products-title" data-home-catalog>
            <div class="content-width">
                <h2 id="products-title">من عالم <bdi>HeroKid</bdi>… لعالمه</h2>
                <p class="section-intro">منتجات مختارة بعناية لكل لحظة من يومه</p>
                <div class="product-grid">
                    @foreach($homeCatalogItems as $item)
                        <article class="product" data-catalog-type="{{ $item->type }}" data-home-item="{{ $item->id }}">
                            <a href="{{ $item->detailUrl }}" class="product-image-link" aria-label="{{ $item->title }}">
                                @if($item->type === 'story')
                                    <x-story-cover-image :src="$item->imageUrl" :alt="$item->title" loading="lazy" />
                                @elseif($item->imageUrl)
                                    <img src="{{ $item->imageUrl }}" alt="{{ $item->title }}" loading="lazy" decoding="async">
                                @else
                                    <img src="{{ $homeImages }}/category-products.webp" alt="صورة توضيحية لمنتجات HeroKid" loading="lazy" decoding="async">
                                @endif
                            </a>
                            <p class="product-label">{{ $item->badgeLabel }}</p>
                            <h3><a href="{{ $item->detailUrl }}">{{ $item->title }}</a></h3>
                            <p class="hk-home-price">@if($item->originalPriceLabel)<del>{{ $item->originalPriceLabel }}</del>@endif <strong>{{ $item->priceLabel }}</strong></p>
                            <a href="{{ $item->detailUrl }}" class="button button--outline">شوف التفاصيل <x-front-icon name="arrow-left" /></a>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if(homepage_section_enabled('stories'))
        <section data-home-section="stories" class="feature feature--story" aria-labelledby="story-title">
            <div class="feature-inner content-width">
                <div class="feature-copy"><h2 id="story-title">مش بطل أي قصة.<br>بطل قصته هو.</h2><p>باسمه وصورته، بالعربي أو الإنجليزي.</p><a href="{{ $storiesUrl }}" class="button button--blue">اكتشف القصص <x-front-icon name="arrow-left" /></a></div>
                <img src="{{ $homeImages }}/story-feature.webp" alt="كتاب مفتوح ببطولة الطفل وصورته" class="feature-image" width="1774" height="887" loading="lazy" decoding="async">
            </div>
        </section>
    @endif
    @if($shopEnabled && homepage_section_enabled('store'))
        <section data-home-section="store" class="feature feature--activities" aria-labelledby="activities-title">
            <div class="feature-inner content-width">
                <img src="{{ $homeImages }}/activity-feature.webp" alt="كتاب متاهات مع ورقة تلوين وأقلام ملونة" class="feature-image" width="1774" height="887" loading="lazy" decoding="async">
                <div class="feature-copy"><h2 id="activities-title">إيدين مشغولة.<br>خيال مفتوح.</h2><p>أنشطة مطبوعة تخليه يستمتع ويكتشف<br>من متاهات وتلوين وأنشطة ممتعة.</p><a href="{{ $activitiesUrl }}" class="button button--blue">اكتشف الأنشطة <x-front-icon name="arrow-left" /></a></div>
            </div>
        </section>
    @endif
    @if(homepage_section_enabled('pricing'))
        <section data-home-section="pricing" class="feature feature--bundle" aria-labelledby="bundle-title">
            <div class="feature-inner content-width">
                <div class="feature-copy"><h2 id="bundle-title">اسمه على تفاصيل يومه.</h2><p>اختار الباقة المناسبة لطفلك<br>وشوف محتوياتها وتفاصيلها قبل الطلب.</p><a href="{{ route('packages') }}" class="button button--blue">تصفّح الباقات <x-front-icon name="arrow-left" /></a></div>
                <img src="{{ $homeImages }}/bundle-feature.webp" alt="تشكيلة توضيحية من حقيبة ودفتر وملصقات مخصصة" class="feature-image" width="2172" height="724" loading="lazy" decoding="async">
            </div>
            @if($packages->isNotEmpty())
                <div class="content-width hk-extra-catalog">@include('front.packages._home-carousel', ['packages' => $packages])</div>
            @endif
        </section>
    @endif

    @if($storeSections->isNotEmpty())
        <section class="hk-extra-catalog content-width" aria-label="اختيارات المتجر" data-home-store-sections>
            @foreach($storeSections as $storeSection)
                <div class="hk-extra-section">
                    <h2>{{ $storeSection->title_ar }}</h2>
                    @if($storeSection->subtitle_ar)<p>{{ $storeSection->subtitle_ar }}</p>@endif
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach($storeSection->category->activeProducts->take($storeSection->max_products ?: 4) as $product)
                            @include('front.shop._product-card', ['product' => $product])
                        @endforeach
                    </div>
                    <a class="button button--outline" href="{{ $storeSection->cta_url ?: route('shop.category', $storeSection->category) }}">{{ $storeSection->cta_text_ar ?: 'عرض كل المنتجات' }} <x-front-icon name="arrow-left" /></a>
                </div>
            @endforeach
        </section>
    @endif

    @if(homepage_section_enabled('child_identity') && setting('child_identity_enabled', '1') === '1')
        <section data-home-section="child_identity" class="hk-identity-strip content-width">
            <img src="{{ \App\Support\SiteImages::path('img_home_child_identity') }}" alt="هوية الطفل" loading="lazy" decoding="async">
            <div><h2>{{ setting('home_child_identity_title', 'اصنع هوية طفلك قبل اختيار القصة') }}</h2><p>{{ setting('home_child_identity_subtitle', 'ارفع صور طفلك مرة واحدة، واحصل على هوية بصرية جاهزة لتختار بعدها القصة المناسبة له.') }}</p><details class="hk-identity-explanation"><summary>من صورتين إلى هوية واحدة</summary><p>صورتان حقيقيتان لنفس الطفل تتحولان إلى هوية متناسقة من زوايا وتعبيرات متعددة</p></details></div>
            <a href="{{ route('child-identity.index') }}" class="button button--blue">{{ setting('home_child_identity_cta', 'ابدأ مجانًا') }} <x-front-icon name="arrow-left" /></a>
        </section>
    @endif

    @if(homepage_section_enabled('how_it_works'))
        <section data-home-section="how_it_works" class="how-it-works section" aria-labelledby="steps-title">
            <div class="content-width">
                <h2 id="steps-title">الطلب بسيط</h2>
                <div class="steps-layout">
                    <ol class="steps">
                        @foreach([['اختار', 'منتجك المفضل', 'magnifying-glass'], ['خصّص', 'لو المنتج مخصص', 'pencil'], ['راجع المعاينة', 'واتأكد من التفاصيل', 'eye'], ['استلم', 'ونجهّز كل حاجة بعناية', 'cube']] as $step)
                            <li><div class="step-symbol"><span class="step-number">{{ ['١','٢','٣','٤'][$loop->index] }}</span><x-front-icon :name="$step[2]" /></div><h3>{{ $step[0] }}</h3><p>{{ $step[1] }}</p></li>
                        @endforeach
                    </ol>
                    <a href="{{ $activitiesUrl }}" class="ready-made"><x-front-icon name="book-open" /><div><h3>المنتجات الجاهزة</h3><p>تُشترى مباشرة بدون معاينة.</p></div></a>
                </div>
            </div>
        </section>
    @endif
    @if(homepage_section_enabled('benefits'))
        <section data-home-section="benefits" class="hk-home-benefits content-width" aria-label="تفاصيل تجربة HeroKid">
            <p>باسمه وصورته</p><p>تفاصيل الطلب واضحة قبل الشراء</p><a href="{{ route('how-it-works') }}">اعرف رحلة الطلب <x-front-icon name="arrow-left" /></a>
        </section>
    @endif
    @if(homepage_section_enabled('testimonials') && $testimonials->isNotEmpty())
        <section data-home-section="testimonials" class="hk-extra-catalog content-width"><h2>ماذا يقول الآباء؟</h2><div class="hk-review-grid">@foreach($testimonials as $testimonial)<blockquote><p>{{ $testimonial->review_text }}</p><cite>{{ $testimonial->reviewer_name }}</cite></blockquote>@endforeach</div></section>
    @endif

    @if(homepage_section_enabled('faq') || homepage_section_enabled('contact'))
        @php
            $helpNumber = \App\Support\Phone::forWhatsApp($settings['whatsapp_number'] ?? null);
            $helpUrl = trim((string) ($settings['whatsapp_url'] ?? '')) ?: ($helpNumber ? 'https://wa.me/'.$helpNumber : route('contact'));
        @endphp
        <section class="faq section" aria-labelledby="faq-title">
            <div class="content-width"><h2 id="faq-title">لسه عندك سؤال؟</h2><div class="faq-layout">
                @if(homepage_section_enabled('contact'))
                    <a data-home-section="contact" href="{{ $helpUrl }}" class="help-panel" @if($helpUrl !== route('contact')) target="_blank" rel="noopener noreferrer" @endif><div><h3>محتاج مساعدة؟</h3><p>فريقنا موجود علشان يساعدك<br>نرد على استفساراتك.</p><span>تواصل معانا <x-front-icon name="arrow-left" /></span></div><img src="{{ asset('images/icons/whatsapp-white.svg') }}" alt="" class="help-icon" width="72" height="72" loading="lazy"></a>
                @endif
                @if(homepage_section_enabled('faq'))
                    <div class="faq-list" data-home-section="faq">
                        @foreach($faqs as $faq)
                            <div data-faq-item class="hk-home-faq-item">
                                <button type="button" data-faq-toggle aria-expanded="false" aria-controls="home-faq-{{ $faq->id }}"><span>{{ $faq->question }}</span><x-front-icon name="chevron-down" data-faq-icon /></button>
                                <div id="home-faq-{{ $faq->id }}" data-faq-answer class="faq-answer" hidden>{{ $faq->answer }}</div>
                            </div>
                        @endforeach
                        <a href="{{ route('faq') }}" class="hk-all-faq">كل الأسئلة الشائعة <x-front-icon name="arrow-left" /></a>
                    </div>
                @endif
            </div></div>
        </section>
    @endif
    @if(homepage_section_enabled('final_cta'))
        <section data-home-section="final_cta" class="closing" aria-labelledby="closing-title">
            <img src="{{ $homeImages }}/closing-art.webp" alt="" class="closing-art" width="1983" height="793" loading="lazy" decoding="async">
            <div class="closing-copy"><h2 id="closing-title">نبدأ بحاجة يحبّها؟</h2><a href="{{ route('shop.index') }}" class="button button--yellow">تصفّح المتجر <x-front-icon name="arrow-left" /></a></div>
        </section>
    @endif
</div>
