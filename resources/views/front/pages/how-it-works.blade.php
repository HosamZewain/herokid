<x-front-layout>

@php
    $hiwSteps = collect(range(1, 5))->map(fn ($step) => [
        'title' => setting("hiw_step{$step}_title", $settings["hiw_step{$step}_title"] ?? ''),
        'desc' => setting("hiw_step{$step}_desc", $settings["hiw_step{$step}_desc"] ?? ''),
        'bullets' => array_values(array_filter([
            setting("hiw_step{$step}_bullet1", $settings["hiw_step{$step}_bullet1"] ?? ''),
            setting("hiw_step{$step}_bullet2", $settings["hiw_step{$step}_bullet2"] ?? ''),
            setting("hiw_step{$step}_bullet3", $settings["hiw_step{$step}_bullet3"] ?? ''),
        ])),
    ]);
    $hiwStepCount = $hiwSteps->count();
@endphp

{{-- ══ SEO ══ --}}
<x-slot name="pageTitle">{{ setting('seo_how_it_works_title', $settings['seo_how_it_works_title'] ?? '') }}</x-slot>
<x-slot name="pageDescription">{{ setting('seo_how_it_works_description', $settings['seo_how_it_works_description'] ?? '') }}</x-slot>

@push('schema')
@php
    $howToSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'HowTo',
        'name' => 'كيف تطلب قصة مخصصة لطفلك من HeroKid',
        'description' => 'خطوات بسيطة لتحويل طفلك إلى بطل قصة مطبوعة بوجهه الحقيقي من HeroKid',
        'totalTime' => 'PT5M',
        'estimatedCost' => [
            '@type' => 'MonetaryAmount',
            'currency' => 'EGP',
            'value' => (string) setting('price_soft_cover', 0),
        ],
        'supply' => [
            ['@type' => 'HowToSupply', 'name' => 'صورة واضحة لوجه الطفل'],
        ],
        'step' => $hiwSteps->values()->map(fn ($step, $index) => [
            '@type' => 'HowToStep',
            'position' => $index + 1,
            'name' => $step['title'],
            'text' => $step['desc'],
            'url' => $index === 0 ? \App\Support\Seo::url('/stories') : \App\Support\Seo::url('/how-it-works'),
        ])->all(),
    ];
@endphp
<script type="application/ld+json">
@json($howToSchema, \App\Support\Seo::jsonFlags())
</script>
@endpush

    <!-- Page Header -->
    <div class="relative overflow-hidden bg-gradient-to-br from-indigo-600 to-indigo-800 py-20">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 left-0 w-64 h-64 bg-white rounded-full -translate-x-1/2 -translate-y-1/2"></div>
            <div class="absolute bottom-0 right-0 w-96 h-96 bg-white rounded-full translate-x-1/3 translate-y-1/3"></div>
        </div>
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10">
            <span class="inline-block px-4 py-1.5 mb-4 text-sm font-bold tracking-wider text-indigo-200 uppercase bg-indigo-700 rounded-full">كيف نعمل؟</span>
            <h1 class="text-4xl md:text-5xl font-extrabold text-white mb-6">رحلة قصتك من الفكرة إلى يدَي طفلك</h1>
            <p class="text-xl text-indigo-100 max-w-2xl mx-auto leading-relaxed">نؤمن أن كل طفل يستحق أن يكون بطلاً. إليك كيف نجعل ذلك يحدث خلال {{ delivery_range() }}.</p>
        </div>
    </div>

    <!-- Steps Section -->
    <section class="py-24 bg-white">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

            <!-- Step 1 -->
            <div class="flex flex-col lg:flex-row items-center gap-12 mb-24">
                <div class="lg:w-1/2 order-2 lg:order-1">
                    <div class="inline-flex items-center justify-center w-14 h-14 bg-indigo-600 text-white text-2xl font-extrabold rounded-2xl mb-6">١</div>
                    <h2 class="text-3xl font-extrabold text-slate-900 mb-4">{{ $hiwSteps[0]['title'] }}</h2>
                    <p class="text-lg text-slate-600 leading-relaxed mb-6">
                        {{ $hiwSteps[0]['desc'] }}
                    </p>
                    <ul class="space-y-2 text-slate-600">
                        @foreach($hiwSteps[0]['bullets'] as $bullet)
                            <li class="flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-green-100 text-green-600 flex items-center justify-center text-xs">✓</span> {{ $bullet }}</li>
                        @endforeach
                    </ul>
                </div>
                <div class="lg:w-1/2 order-1 lg:order-2">
                    <div class="relative rounded-3xl overflow-hidden h-64 shadow-xl">
                        <img src="{{ \App\Support\Seo::imageUrl($settings['img_hiw_step1'] ?? \App\Support\SiteImages::url('img_hiw_step1')) }}"
                             alt="مكتبة القصص" class="w-full h-full object-cover">
                        <div class="absolute inset-0 bg-gradient-to-t from-indigo-900/70 to-indigo-600/20"></div>
                        <div class="absolute bottom-4 right-4 text-right">
                            <p class="text-white font-extrabold text-lg">{{ arabic_number(\App\Models\Story::where('active', true)->count()) }} قصة رائعة</p>
                            <p class="text-indigo-200 text-sm">تنتظر بطلك</p>
                        </div>
                        <div class="absolute -top-4 -right-4 w-12 h-12 bg-yellow-400 rounded-2xl flex items-center justify-center text-white font-bold shadow-lg text-xl">✨</div>
                    </div>
                </div>
            </div>

            <!-- Connector -->
            <div class="flex justify-center mb-24">
                <div class="w-0.5 h-16 bg-gradient-to-b from-indigo-300 to-indigo-100"></div>
            </div>

            <!-- Step 2 -->
            <div class="flex flex-col lg:flex-row items-center gap-12 mb-24">
                <div class="lg:w-1/2">
                    <div class="relative rounded-3xl overflow-hidden h-64 shadow-xl">
                        <img src="{{ \App\Support\Seo::imageUrl($settings['img_hiw_step2'] ?? \App\Support\SiteImages::url('img_hiw_step2')) }}"
                             alt="صور الطفل" class="w-full h-full object-cover">
                        <div class="absolute inset-0 bg-gradient-to-t from-pink-900/70 to-pink-400/10"></div>
                        <div class="absolute bottom-4 right-4 text-right">
                            <p class="text-white font-extrabold text-lg">وجهه الحقيقي</p>
                            <p class="text-pink-200 text-sm">في كل رسمة</p>
                        </div>
                        <div class="absolute -top-4 -left-4 w-12 h-12 bg-indigo-500 rounded-2xl flex items-center justify-center text-white font-bold shadow-lg text-xl">🎨</div>
                    </div>
                </div>
                <div class="lg:w-1/2">
                    <div class="inline-flex items-center justify-center w-14 h-14 bg-pink-500 text-white text-2xl font-extrabold rounded-2xl mb-6">٢</div>
                    <h2 class="text-3xl font-extrabold text-slate-900 mb-4">{{ $hiwSteps[1]['title'] }}</h2>
                    <p class="text-lg text-slate-600 leading-relaxed mb-6">
                        {{ $hiwSteps[1]['desc'] }}
                    </p>
                    <ul class="space-y-2 text-slate-600">
                        @foreach($hiwSteps[1]['bullets'] as $bullet)
                            <li class="flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-green-100 text-green-600 flex items-center justify-center text-xs">✓</span> {{ $bullet }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <!-- Connector -->
            <div class="flex justify-center mb-24">
                <div class="w-0.5 h-16 bg-gradient-to-b from-indigo-300 to-indigo-100"></div>
            </div>

            <!-- Step 3 -->
            <div class="flex flex-col lg:flex-row items-center gap-12 mb-24">
                <div class="lg:w-1/2 order-2 lg:order-1">
                    <div class="inline-flex items-center justify-center w-14 h-14 bg-amber-500 text-white text-2xl font-extrabold rounded-2xl mb-6">٣</div>
                    <h2 class="text-3xl font-extrabold text-slate-900 mb-4">{{ $hiwSteps[2]['title'] }}</h2>
                    <p class="text-lg text-slate-600 leading-relaxed mb-6">
                        {{ $hiwSteps[2]['desc'] }}
                    </p>
                    <ul class="space-y-2 text-slate-600">
                        @foreach($hiwSteps[2]['bullets'] as $bullet)
                            <li class="flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-green-100 text-green-600 flex items-center justify-center text-xs">✓</span> {{ $bullet }}</li>
                        @endforeach
                    </ul>
                </div>
                <div class="lg:w-1/2 order-1 lg:order-2">
                    <div class="relative rounded-3xl overflow-hidden h-64 shadow-xl">
                        <img src="{{ \App\Support\Seo::imageUrl($settings['img_hiw_step3'] ?? \App\Support\SiteImages::url('img_hiw_step3')) }}"
                             alt="رسومات فنية" class="w-full h-full object-cover">
                        <div class="absolute inset-0 bg-gradient-to-t from-amber-900/70 to-amber-500/10"></div>
                        <div class="absolute bottom-4 right-4 text-right">
                            <p class="text-white font-extrabold text-lg">رسومات مخصصة</p>
                            <p class="text-amber-200 text-sm">بالذكاء الاصطناعي</p>
                        </div>
                        <div class="absolute -bottom-4 -right-4 w-12 h-12 bg-pink-500 rounded-2xl flex items-center justify-center text-white font-bold shadow-lg text-xl">🤖</div>
                    </div>
                </div>
            </div>

            <!-- Connector -->
            <div class="flex justify-center mb-24">
                <div class="w-0.5 h-16 bg-gradient-to-b from-indigo-300 to-indigo-100"></div>
            </div>

            <!-- Step 4 -->
            <div class="flex flex-col lg:flex-row items-center gap-12 mb-24">
                <div class="lg:w-1/2">
                    <div class="relative rounded-3xl overflow-hidden h-64 shadow-xl">
                        <img src="{{ \App\Support\Seo::imageUrl($settings['img_hiw_step4'] ?? \App\Support\SiteImages::url('img_hiw_step4')) }}"
                             alt="مراجعة التصميم" class="w-full h-full object-cover">
                        <div class="absolute inset-0 bg-gradient-to-t from-green-900/70 to-green-500/10"></div>
                        <div class="absolute bottom-4 right-4 text-right">
                            <p class="text-white font-extrabold text-lg">معاينة كاملة</p>
                            <p class="text-green-200 text-sm">قبل أي طباعة</p>
                        </div>
                    </div>
                </div>
                <div class="lg:w-1/2">
                    <div class="inline-flex items-center justify-center w-14 h-14 bg-green-600 text-white text-2xl font-extrabold rounded-2xl mb-6">٤</div>
                    <h2 class="text-3xl font-extrabold text-slate-900 mb-4">{{ $hiwSteps[3]['title'] }}</h2>
                    <p class="text-lg text-slate-600 leading-relaxed mb-6">
                        {{ $hiwSteps[3]['desc'] }}
                    </p>
                    <ul class="space-y-2 text-slate-600">
                        @foreach($hiwSteps[3]['bullets'] as $bullet)
                            <li class="flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-green-100 text-green-600 flex items-center justify-center text-xs">✓</span> {{ $bullet }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <!-- Connector -->
            <div class="flex justify-center mb-24">
                <div class="w-0.5 h-16 bg-gradient-to-b from-indigo-300 to-indigo-100"></div>
            </div>

            <!-- Step 5 -->
            <div class="flex flex-col lg:flex-row items-center gap-12">
                <div class="lg:w-1/2 order-2 lg:order-1">
                    <div class="inline-flex items-center justify-center w-14 h-14 bg-blue-600 text-white text-2xl font-extrabold rounded-2xl mb-6">٥</div>
                    <h2 class="text-3xl font-extrabold text-slate-900 mb-4">{{ $hiwSteps[4]['title'] }}</h2>
                    <p class="text-lg text-slate-600 leading-relaxed mb-6">
                        {{ $hiwSteps[4]['desc'] }}
                    </p>
                    <ul class="space-y-2 text-slate-600">
                        @foreach($hiwSteps[4]['bullets'] as $bullet)
                            <li class="flex items-center gap-2"><span class="w-5 h-5 rounded-full bg-green-100 text-green-600 flex items-center justify-center text-xs">✓</span> {{ $bullet }}</li>
                        @endforeach
                    </ul>
                </div>
                <div class="lg:w-1/2 order-1 lg:order-2">
                    <div class="relative rounded-3xl overflow-hidden h-64 shadow-xl">
                        <img src="{{ \App\Support\Seo::imageUrl($settings['img_hiw_step5'] ?? \App\Support\SiteImages::url('img_hiw_step5')) }}"
                             alt="توصيل الكتاب" class="w-full h-full object-cover">
                        <div class="absolute inset-0 bg-gradient-to-t from-blue-900/70 to-blue-500/10"></div>
                        <div class="absolute bottom-4 right-4 text-right">
                            <p class="text-white font-extrabold text-lg">توصيل لباب بيتك</p>
                            <p class="text-blue-200 text-sm">خلال {{ delivery_range(false) }}</p>
                        </div>
                        <div class="absolute -top-4 -left-4 w-12 h-12 bg-amber-400 rounded-2xl flex items-center justify-center text-white font-bold shadow-lg text-xl">🎁</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Timeline Summary -->
    <section class="py-20 bg-slate-50">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl font-extrabold text-slate-900 mb-4">الجدول الزمني المتوقع</h2>
            <p class="text-slate-500 mb-12">من تاريخ تأكيد طلبك وإتمام الدفع</p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-100">
                    <div class="text-3xl font-extrabold text-indigo-600 mb-2">اليوم ١</div>
                    <p class="text-slate-600 text-sm">استلام الطلب ومراجعة الصور</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-100">
                    <div class="text-3xl font-extrabold text-pink-500 mb-2">٢-٣ أيام</div>
                    <p class="text-slate-600 text-sm">توليد القصة والرسومات</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-100">
                    <div class="text-3xl font-extrabold text-amber-500 mb-2">اليوم ٤</div>
                    <p class="text-slate-600 text-sm">إرسال Preview للموافقة</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-100">
                    <div class="text-3xl font-extrabold text-green-600 mb-2">{{ delivery_range() }}</div>
                    <p class="text-slate-600 text-sm">طباعة وشحن واستلام</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="py-20 bg-indigo-600">
        <div class="max-w-3xl mx-auto px-4 text-center">
            <h2 class="text-3xl font-extrabold text-white mb-6">جاهز لتبدأ رحلة طفلك؟</h2>
            <p class="text-indigo-100 text-xl mb-10">اختر قصتك الآن واجعل طفلك بطل المغامرة.</p>
            <a href="{{ route('stories.index') }}" class="inline-block bg-white text-indigo-600 font-extrabold py-4 px-12 rounded-2xl text-xl shadow-xl hover:shadow-2xl transition hover:-translate-y-1">
                تصفح المكتبة الآن
            </a>
        </div>
    </section>
</x-front-layout>
