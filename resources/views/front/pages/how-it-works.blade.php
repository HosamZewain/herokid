<x-front-layout>
    @php
        $steps = collect(range(1, 5))->map(fn ($number) => [
            'number' => $number,
            'title' => \App\Support\PublicExperienceCopy::value("hiw_step{$number}_title"),
            'desc' => \App\Support\PublicExperienceCopy::value("hiw_step{$number}_desc"),
            'bullets' => array_filter(array_map(fn ($bullet) => \App\Support\PublicExperienceCopy::value("hiw_step{$number}_bullet{$bullet}"), range(1, 3))),
            'image' => \App\Support\Seo::imageUrl(setting("img_hiw_step{$number}", \App\Support\SiteImages::path("img_hiw_step{$number}"))),
        ]);
        app(\App\Services\Images\PublicImageVariants::class)->prime($steps->pluck('image')->all());
        $schema = [
            '@context' => 'https://schema.org', '@type' => 'HowTo',
            'name' => 'كيف تختار وتطلب من HeroKid',
            'description' => \App\Support\PublicExperienceCopy::value('seo_how_it_works_description'),
            'step' => $steps->map(fn ($step) => [
                '@type' => 'HowToStep', 'position' => $step['number'],
                'name' => $step['title'], 'text' => $step['desc'],
                'url' => \App\Support\Seo::url('/how-it-works#step-'.$step['number']),
            ])->all(),
        ];
    @endphp
    <x-slot name="pageTitle">{{ setting('seo_how_it_works_title', 'كيف يعمل HeroKid؟') }}</x-slot>
    <x-slot name="pageDescription">{{ \App\Support\PublicExperienceCopy::value('seo_how_it_works_description') }}</x-slot>
    @push('schema')<script type="application/ld+json">@json($schema, \App\Support\Seo::jsonFlags())</script>@endpush

    <header data-front-page-hero class="bg-gradient-to-br from-indigo-600 to-indigo-800 px-4 py-14 text-center sm:py-20">
        <p class="mb-3 text-sm font-black text-cyan-200">دليل HeroKid</p>
        <h1 class="text-3xl font-black leading-tight text-white sm:text-5xl">من عالم HeroKid… ليوم طفلك</h1>
        <p class="mx-auto mt-5 max-w-2xl text-lg leading-8 text-indigo-100">قصص، منتجات مخصصة، وأنشطة جاهزة. اختار اللي يحبه طفلك، واعرف خطوات طلبك من البداية للاستلام.</p>
        <a href="{{ route('shop.index') }}" class="mt-6 inline-flex min-h-12 items-center rounded-full bg-white px-6 font-black text-indigo-700">اكتشف القصص والمنتجات <x-front-icon name="arrow-left" class="ms-2" /></a>
    </header>
    <main class="bg-white">
        <section class="mx-auto max-w-5xl space-y-12 px-4 py-12 sm:px-6 sm:py-20" aria-label="خطوات الطلب">
            @foreach($steps as $step)
                <article id="step-{{ $step['number'] }}" class="grid items-center gap-6 rounded-3xl border border-indigo-100 bg-slate-50/60 p-5 sm:p-8 lg:grid-cols-2 lg:gap-10">
                    <div>
                        <span class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-600 text-xl font-black text-white">{{ arabic_number($step['number']) }}</span>
                        <h2 class="text-2xl font-black text-slate-950 sm:text-3xl">{{ $step['title'] }}</h2>
                        <p class="mt-4 leading-8 text-slate-600">{{ $step['desc'] }}</p>
                        <ul class="mt-4 space-y-3 text-sm font-bold text-slate-600">
                            @foreach($step['bullets'] as $bullet)<li class="flex gap-2"><span aria-hidden="true" class="text-emerald-600">✓</span>{{ $bullet }}</li>@endforeach
                        </ul>
                    </div>
                    <x-public-image :src="$step['image']" :alt="$step['title']" sizes="(min-width: 1024px) 450px, 100vw" width="640" height="400" loading="lazy" class="aspect-[8/5] w-full rounded-2xl object-cover" />
                </article>
            @endforeach
        </section>
        <section class="bg-cyan-50 px-4 py-10 text-center" aria-labelledby="order-types-title">
            <h2 id="order-types-title" class="text-2xl font-black text-slate-950">كل اختيار له رحلته</h2>
            <div class="mx-auto mt-6 grid max-w-5xl gap-4 text-right md:grid-cols-3">
                @foreach([
                    ['المنتجات المخصصة', 'نجهّزها ببيانات طفلك، وتراجع المعاينة قبل الطباعة عندما تكون مطلوبة.'],
                    ['المنتجات الجاهزة', 'تتضاف للسلة وتُجهّز مباشرة؛ لا تحتاج صورًا أو معاينة.'],
                    ['الطلبات المختلطة', 'اجمع القصص والمنتجات في سلة واحدة؛ كل منتج يتبع خطوات التجهيز الخاصة به.'],
                ] as [$title, $description])
                    <div class="rounded-2xl border border-cyan-100 bg-white p-5"><h3 class="font-black text-indigo-800">{{ $title }}</h3><p class="mt-2 text-sm leading-7 text-slate-600">{{ $description }}</p></div>
                @endforeach
            </div>
            <p class="mx-auto mt-6 max-w-2xl text-sm leading-7 text-slate-600">راجع مدة التجهيز وتفاصيل المنتج قبل الطلب. لو محتاج مساعدة، فريقنا معاك.</p>
            <div class="mt-6 flex flex-wrap justify-center gap-3">
                <a href="{{ route('shop.index') }}" class="inline-flex min-h-12 items-center rounded-full bg-indigo-600 px-6 font-black text-white">تصفّح المتجر</a>
                <a href="{{ route('contact') }}" class="inline-flex min-h-12 items-center rounded-full border border-indigo-200 bg-white px-6 font-black text-indigo-700">تواصل معنا</a>
                <a href="{{ route('track.index') }}" class="inline-flex min-h-12 items-center rounded-full border border-indigo-200 bg-white px-6 font-black text-indigo-700">تتبع طلبك</a>
            </div>
        </section>
    </main>
</x-front-layout>
