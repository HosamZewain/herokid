<x-front-layout>
    <x-slot name="pageTitle">عن HeroKid — قصص ومنتجات تجعل طفلك بطل الحكاية</x-slot>
    <x-slot name="pageDescription">تعرف على HeroKid، وكيف نصنع قصص أطفال مخصصة باسم وصورة الطفل، وما تحتاجه للطلب، وخطوات المراجعة والتوصيل والخصوصية.</x-slot>
    <x-slot name="canonical">/about</x-slot>
    <x-slot name="pageImage">/images/logo.jpg</x-slot>
    <x-slot name="pageImageAlt">HeroKid — قصص مخصصة تجعل طفلك بطل الحكاية</x-slot>
    <x-slot name="ogImageWidth">1024</x-slot>
    <x-slot name="ogImageHeight">1024</x-slot>

    @push('schema')
        @php
            $aboutSchema = [
                '@context' => 'https://schema.org',
                '@graph' => [
                    [
                        '@type' => 'AboutPage',
                        '@id' => \App\Support\Seo::url('/about#webpage'),
                        'name' => 'عن HeroKid',
                        'url' => \App\Support\Seo::url('/about'),
                        'description' => 'HeroKid يصنع قصص أطفال مخصصة تجعل الطفل بطل الحكاية باسمه وصورته، إلى جانب كتب الأنشطة والهدايا والمنتجات التعليمية.',
                        'inLanguage' => 'ar',
                        'isPartOf' => ['@id' => \App\Support\Seo::url('/#website')],
                        'about' => ['@id' => \App\Support\Seo::url('/#organization')],
                        'breadcrumb' => ['@id' => \App\Support\Seo::url('/about#breadcrumb')],
                        'primaryImageOfPage' => [
                            '@type' => 'ImageObject',
                            'url' => \App\Support\Seo::imageUrl('/images/logo.jpg'),
                            'width' => 1024,
                            'height' => 1024,
                        ],
                    ],
                    [
                        '@type' => 'BreadcrumbList',
                        '@id' => \App\Support\Seo::url('/about#breadcrumb'),
                        'itemListElement' => [
                            [
                                '@type' => 'ListItem',
                                'position' => 1,
                                'name' => 'الرئيسية',
                                'item' => \App\Support\Seo::url('/'),
                            ],
                            [
                                '@type' => 'ListItem',
                                'position' => 2,
                                'name' => 'عن HeroKid',
                                'item' => \App\Support\Seo::url('/about'),
                            ],
                        ],
                    ],
                ],
            ];
        @endphp
        <script type="application/ld+json">
        @json($aboutSchema, \App\Support\Seo::jsonFlags())
        </script>
    @endpush

    <nav aria-label="مسار التنقل" class="border-b border-slate-100 bg-white">
        <ol class="mx-auto flex max-w-7xl items-center gap-2 px-4 py-3 text-sm text-slate-500 sm:px-6 lg:px-8">
            <li><a href="{{ route('home') }}" class="transition hover:text-indigo-700">الرئيسية</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-bold text-slate-800">عن HeroKid</li>
        </ol>
    </nav>

    <section class="relative overflow-hidden bg-gradient-to-br from-indigo-950 via-indigo-800 to-violet-700 py-16 sm:py-24">
        <div class="absolute inset-0 opacity-20" aria-hidden="true">
            <div class="absolute -right-16 -top-20 h-72 w-72 rounded-full bg-pink-400 blur-3xl"></div>
            <div class="absolute -bottom-28 -left-12 h-80 w-80 rounded-full bg-cyan-300 blur-3xl"></div>
        </div>
        <div class="relative mx-auto grid max-w-7xl items-center gap-10 px-4 sm:px-6 lg:grid-cols-[1.2fr_.8fr] lg:px-8">
            <div class="text-center lg:text-right">
                <span class="inline-flex rounded-full border border-white/20 bg-white/10 px-4 py-2 text-sm font-black text-indigo-100">
                    حكاية يكون طفلك بطلها
                </span>
                <h1 class="mt-5 text-4xl font-black leading-tight text-white sm:text-5xl lg:text-6xl">
                    عن HeroKid
                </h1>
                <p class="mx-auto mt-5 max-w-3xl text-lg leading-9 text-indigo-100 lg:mx-0">
                    نصنع قصصًا مخصصة تجعل الطفل بطل الحكاية باسمه وصورته، ونقدّم كتب أنشطة ومنتجات تعليمية وهدايا اختيرت لتمنح الأسرة تجربة ممتعة وذات معنى.
                </p>
                <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row lg:justify-start">
                    <a href="{{ route('shop.index', ['type' => 'stories']) }}"
                        class="inline-flex min-h-12 items-center justify-center rounded-2xl bg-white px-7 py-3 font-black text-indigo-700 shadow-lg transition hover:-translate-y-0.5 hover:bg-indigo-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-indigo-800">
                        اختر قصة لطفلك
                    </a>
                    <a href="{{ route('how-it-works') }}"
                        class="inline-flex min-h-12 items-center justify-center rounded-2xl border border-white/30 bg-white/10 px-7 py-3 font-black text-white transition hover:bg-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                        شاهد كيف يعمل
                    </a>
                </div>
            </div>
            <div class="mx-auto w-full max-w-md rounded-[2rem] border border-white/15 bg-white/10 p-5 shadow-2xl backdrop-blur">
                <div class="rounded-[1.5rem] bg-white p-6 text-center">
                    <img src="/images/logo-320.png" width="320" height="274" alt="HeroKid"
                        class="mx-auto h-32 w-auto object-contain sm:h-40">
                    <p class="mt-3 text-xl font-black text-slate-900">طفلك داخل عالم من الخيال والتعلّم</p>
                    <p class="mt-2 text-sm leading-7 text-slate-600">قصة مخصصة، مراجعة بشرية، وتجربة واضحة من الاختيار حتى الاستلام.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="bg-white py-16 sm:py-20">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl text-center">
                <span class="text-sm font-black text-indigo-600">ماذا نقدّم؟</span>
                <h2 class="mt-2 text-3xl font-black text-slate-950 sm:text-4xl">تجربة واحدة تناسب ما تبحث عنه لطفلك</h2>
            </div>
            <div class="mt-10 grid gap-5 md:grid-cols-3">
                <article class="rounded-3xl border border-pink-100 bg-pink-50/70 p-7">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-pink-500 text-2xl text-white" aria-hidden="true">📖</span>
                    <h3 class="mt-5 text-xl font-black text-slate-950">قصص مخصصة</h3>
                    <p class="mt-3 leading-8 text-slate-600">قصص باسم الطفل وصوره وبياناته المناسبة للقصة، لتصبح القراءة تجربة قريبة منه ويتذكرها.</p>
                </article>
                <article class="rounded-3xl border border-indigo-100 bg-indigo-50/70 p-7">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-600 text-2xl text-white" aria-hidden="true">🎨</span>
                    <h3 class="mt-5 text-xl font-black text-slate-950">كتب وأنشطة</h3>
                    <p class="mt-3 leading-8 text-slate-600">كتب تلوين وأنشطة ومتاهات ومنتجات تعليمية؛ بعضها جاهز للشراء مباشرة وبعضها يدعم التخصيص.</p>
                </article>
                <article class="rounded-3xl border border-amber-100 bg-amber-50/70 p-7">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-500 text-2xl text-white" aria-hidden="true">🎁</span>
                    <h3 class="mt-5 text-xl font-black text-slate-950">هدايا بلمسة شخصية</h3>
                    <p class="mt-3 leading-8 text-slate-600">منتجات تصلح لهدية مميزة في المناسبات، مع توضيح متطلبات كل منتج قبل إضافته إلى السلة.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="bg-slate-50 py-16 sm:py-20">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid gap-10 lg:grid-cols-[.8fr_1.2fr] lg:items-start">
                <div>
                    <span class="text-sm font-black text-indigo-600">رحلة الطلب</span>
                    <h2 class="mt-2 text-3xl font-black leading-tight text-slate-950">من اختيار القصة إلى وصولها لطفلك</h2>
                    <p class="mt-4 leading-8 text-slate-600">نعرض الخطوات قبل جمع بياناتك حتى تعرف ما الذي سيحدث في كل مرحلة.</p>
                    <a href="{{ route('how-it-works') }}" class="mt-6 inline-flex font-black text-indigo-700 underline decoration-2 underline-offset-8 hover:text-indigo-900">
                        التفاصيل الكاملة لطريقة العمل
                    </a>
                </div>
                <ol class="grid gap-4 sm:grid-cols-2">
                    @foreach([
                        ['١', 'اختر القصة', 'تصفح القصص وحدد الحكاية المناسبة لطفلك.'],
                        ['٢', 'أضف بيانات الطفل والصور', 'أدخل البيانات المطلوبة وارفع الصور الواضحة حسب تعليمات القصة.'],
                        ['٣', 'أدخل بيانات التوصيل', 'راجع السلة ثم أضف العنوان وبيانات التواصل.'],
                        ['٤', 'نراجع الطلب ونتواصل معك', 'نتواصل عبر واتساب لتأكيد المعاينة وإكمال الخطوات التالية.'],
                    ] as [$number, $title, $description])
                        <li class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-600 font-black text-white">{{ $number }}</span>
                            <h3 class="mt-4 text-lg font-black text-slate-950">{{ $title }}</h3>
                            <p class="mt-2 text-sm leading-7 text-slate-600">{{ $description }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </section>

    <section class="bg-white py-16 sm:py-20">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid gap-6 lg:grid-cols-2">
                <article class="rounded-3xl border border-slate-200 p-7 sm:p-9">
                    <h2 class="text-2xl font-black text-slate-950">ما الذي تحتاجه لطلب قصة مخصصة؟</h2>
                    <ul class="mt-6 space-y-4 text-slate-600">
                        <li class="flex gap-3"><span class="text-green-600" aria-hidden="true">✓</span><span>اسم الطفل وعمره وجنسه والبيانات المطلوبة للقصة.</span></li>
                        <li class="flex gap-3"><span class="text-green-600" aria-hidden="true">✓</span><span>صورتان أو ٣ صور واضحة للوجه كما توضّح صفحة القصة.</span></li>
                        <li class="flex gap-3"><span class="text-green-600" aria-hidden="true">✓</span><span>بيانات تواصل وعنوان صحيحان لمراجعة الطلب والتوصيل.</span></li>
                    </ul>
                </article>
                <article class="rounded-3xl border border-slate-200 p-7 sm:p-9">
                    <h2 class="text-2xl font-black text-slate-950">السعر والتوصيل قبل الطلب</h2>
                    <p class="mt-5 leading-8 text-slate-600">
                        يظهر سعر كل قصة أو منتج بوضوح في صفحة الاختيار والسلة. تكلفة التوصيل تُعرض قبل إرسال الطلب، ومدة الوصول المتوقعة هي
                        <strong class="text-slate-900">{{ delivery_range() }}</strong>.
                    </p>
                    <a href="{{ route('pricing') }}" class="mt-5 inline-flex font-black text-indigo-700 underline decoration-2 underline-offset-8 hover:text-indigo-900">راجع الأسعار والباقات</a>
                </article>
                <article class="rounded-3xl border border-slate-200 p-7 sm:p-9">
                    <h2 class="text-2xl font-black text-slate-950">المراجعة وجودة النتيجة</h2>
                    <p class="mt-5 leading-8 text-slate-600">
                        نراجع البيانات والصور قبل التنفيذ، ونتواصل معك عند الحاجة إلى توضيح أو صور أفضل. طلب القصة يمر بمرحلة مراجعة ومعاينة قبل إتمامه.
                    </p>
                </article>
                <article class="rounded-3xl border border-slate-200 p-7 sm:p-9">
                    <h2 class="text-2xl font-black text-slate-950">الخصوصية وحماية الصور</h2>
                    <p class="mt-5 leading-8 text-slate-600">
                        نستخدم بيانات الطفل وصوره لتنفيذ الخدمة المطلوبة، ونتعامل معها وفق سياسة الخصوصية. يمكنك الاطلاع على تفاصيل الحفظ والاستخدام وطلب الحذف من الصفحة المخصصة.
                    </p>
                    <a href="{{ route('privacy') }}" class="mt-5 inline-flex font-black text-indigo-700 underline decoration-2 underline-offset-8 hover:text-indigo-900">اقرأ سياسة الخصوصية</a>
                </article>
            </div>
        </div>
    </section>

    <section class="bg-indigo-50 py-16">
        <div class="mx-auto max-w-4xl px-4 text-center sm:px-6">
            <h2 class="text-3xl font-black text-slate-950">هل لديك سؤال قبل الطلب؟</h2>
            <p class="mx-auto mt-4 max-w-2xl leading-8 text-slate-600">راجع الإجابات الشائعة أو تواصل معنا، وإذا أرسلت طلبًا بالفعل يمكنك متابعة حالته برقم الطلب.</p>
            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ route('faq') }}" class="inline-flex min-h-12 items-center justify-center rounded-2xl bg-white px-6 py-3 font-black text-indigo-700 shadow-sm ring-1 ring-indigo-100 hover:bg-indigo-100">الأسئلة الشائعة</a>
                <a href="{{ route('contact') }}" class="inline-flex min-h-12 items-center justify-center rounded-2xl bg-indigo-600 px-6 py-3 font-black text-white shadow-sm hover:bg-indigo-700">تواصل معنا</a>
                <a href="{{ route('track.index') }}" class="inline-flex min-h-12 items-center justify-center rounded-2xl bg-slate-900 px-6 py-3 font-black text-white shadow-sm hover:bg-slate-800">تتبع طلبك</a>
            </div>
        </div>
    </section>
</x-front-layout>
