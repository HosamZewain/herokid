<x-front-layout>

{{-- ══ SEO ══ --}}
<x-slot name="pageTitle">{{ setting('seo_pricing_title', 'باقات قصص الأطفال المخصصة | HeroKid') }}</x-slot>
<x-slot name="pageDescription">{{ setting('seo_pricing_description', $settings['seo_pricing_description'] ?? '') }}</x-slot>
<x-slot name="canonical">/packages</x-slot>
@if($packages->first()?->image_url)
<x-slot name="pageImage">{{ $packages->first()->image_url }}</x-slot>
<x-slot name="pageImageAlt">باقات قصص وأنشطة HeroKid المخصصة</x-slot>
<x-slot name="ogImageWidth">900</x-slot>
<x-slot name="ogImageHeight">900</x-slot>
@endif

@push('schema')
@php
    $pricingSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => 'أسعار قصص HeroKid المخصصة',
        'url' => \App\Support\Seo::url('/packages'),
        'description' => 'باقات HeroKid لقصص الأطفال المخصصة بأسعار واضحة بدون رسوم خفية',
        'mainEntity' => [
            '@type' => 'ItemList',
            'itemListElement' => $packages->values()->map(fn ($pkg, $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'item' => [
                    '@type' => 'Product',
                    'name' => $pkg->name,
                    'description' => $pkg->description ?? '',
                    'brand' => ['@type' => 'Brand', 'name' => 'HeroKid'],
                    'offers' => [
                        '@type' => 'Offer',
                        'priceCurrency' => 'EGP',
                        'price' => (string) $pkg->price,
                        'availability' => 'https://schema.org/InStock',
                        'url' => route('shop.package.show', $pkg),
                    ],
                ],
            ])->all(),
        ],
    ];
@endphp
<script type="application/ld+json">
@json($pricingSchema, \App\Support\Seo::jsonFlags())
</script>
@if($packageFaqs->isNotEmpty())
@php
    $packageFaqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $packageFaqs->map(fn ($faq) => [
            '@type' => 'Question',
            'name' => $faq->question,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq->answer],
        ])->values()->all(),
    ];
@endphp
<script type="application/ld+json">
@json($packageFaqSchema, \App\Support\Seo::jsonFlags())
</script>
@endif
@endpush

    <!-- Header -->
    <div class="relative overflow-hidden bg-gradient-to-br from-slate-950 via-indigo-950 to-fuchsia-900 py-16 sm:py-20">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 left-0 w-72 h-72 bg-indigo-400 rounded-full -translate-x-1/2 -translate-y-1/2"></div>
        </div>
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10">
            <span class="inline-block px-4 py-1.5 mb-4 text-sm font-black text-amber-950 bg-amber-300 rounded-full">وفر مع باقات HeroKid</span>
            <h1 class="text-4xl md:text-5xl font-extrabold text-white mb-6">قصص وأنشطة أكثر لطفلك بسعر أوفر</h1>
            <p class="text-lg text-slate-200 max-w-2xl mx-auto leading-8">اختر الباقة المناسبة، ثم اختر كل قصة وبيانات طفلها في طلب واحد. السعر الظاهر هو السعر النهائي للباقة قبل مصاريف التوصيل.</p>
        </div>
    </div>

    <!-- Pricing Cards -->
    <section class="py-14 sm:py-20 bg-gradient-to-b from-indigo-50 to-white">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

            @if($packages->isEmpty())
                <div class="text-center py-20 text-slate-400">
                    <div class="text-5xl mb-4">💰</div>
                    <p>الباقات قيد التحضير، تواصل معنا للاستفسار.</p>
                </div>
            @else
                <div class="mb-16">@include('front.packages._cards', ['packages' => $packages])</div>
            @endif

            @if($packageFaqs->isNotEmpty())
                <section class="rounded-3xl border border-slate-100 bg-white p-6 shadow-sm sm:p-10" aria-labelledby="package-faq-title">
                    <h2 id="package-faq-title" class="mb-8 text-center text-2xl font-extrabold text-slate-900">أسئلة عن الباقات والأسعار</h2>
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        @foreach($packageFaqs as $faq)
                            <article class="rounded-2xl border border-slate-100 bg-slate-50/70 p-5">
                                <h3 class="font-black text-slate-900">{{ $faq->question }}</h3>
                                <p class="mt-2 whitespace-pre-line text-sm leading-7 text-slate-600">{{ $faq->answer }}</p>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </section>
</x-front-layout>
