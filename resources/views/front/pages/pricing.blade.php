<x-front-layout>

{{-- ══ SEO ══ --}}
<x-slot name="pageTitle">{{ setting('seo_pricing_title', $settings['seo_pricing_title'] ?? '') }}</x-slot>
<x-slot name="pageDescription">{{ setting('seo_pricing_description', $settings['seo_pricing_description'] ?? '') }}</x-slot>
@if($packages->first()?->image_url)
<x-slot name="pageImage">{{ $packages->first()->image_url }}</x-slot>
<x-slot name="pageImageAlt">باقات قصص وأنشطة HeroKid المخصصة</x-slot>
<x-slot name="ogImageWidth">900</x-slot>
<x-slot name="ogImageHeight">900</x-slot>
@endif

@php
    $paymentMethods = setting_array('payment_methods');
    $shippingFeeRange = shipping_fee_range();
@endphp

@push('schema')
@php
    $pricingSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => 'أسعار قصص HeroKid المخصصة',
        'url' => \App\Support\Seo::url('/pricing'),
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

            <!-- FAQ about pricing -->
            <div class="bg-white rounded-3xl border border-slate-100 shadow-sm p-10">
                <h3 class="text-2xl font-extrabold text-slate-900 mb-8 text-center">أسئلة عن الأسعار</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div>
                        <h4 class="font-bold text-slate-900 mb-2">هل السعر شامل الشحن؟</h4>
                        <p class="text-slate-600 text-sm leading-relaxed">
                            رسوم الشحن تُحسب في السلة حسب محافظتك، وتظهر لك بوضوح قبل تأكيد الطلب
                            @if($shippingFeeRange)
                                (النطاق الحالي من مناطق التوصيل: {{ $shippingFeeRange }}).
                            @else
                                .
                            @endif
                        </p>
                    </div>
                    <div>
                        <h4 class="font-bold text-slate-900 mb-2">متى يتم الدفع؟</h4>
                        <p class="text-slate-600 text-sm leading-relaxed">يتم الدفع بعد مراجعة الطلب وإرسالك رابط الدفع. لن يُطلب منك الدفع قبل رؤية القصة أولاً.</p>
                    </div>
                    <div>
                        <h4 class="font-bold text-slate-900 mb-2">ما هي طرق الدفع المتاحة؟</h4>
                        <p class="text-slate-600 text-sm leading-relaxed">
                            @if($paymentMethods)
                                نقبل {{ implode('، ', $paymentMethods) }}.
                            @else
                                يتم تأكيد طريقة الدفع المناسبة معك قبل بدء الإنتاج.
                            @endif
                        </p>
                    </div>
                    <div>
                        <h4 class="font-bold text-slate-900 mb-2">هل يوجد سياسة استرجاع؟</h4>
                        <p class="text-slate-600 text-sm leading-relaxed">نضمن رضاك التام. إذا لم تكن راضياً عن النتيجة النهائية بعد مرحلة المراجعة، نعيد لك المبلغ كاملاً.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-front-layout>
