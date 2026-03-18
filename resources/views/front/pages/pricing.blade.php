<x-front-layout>

{{-- ══ SEO ══ --}}
<x-slot name="pageTitle">أسعار قصص الأطفال المخصصة — باقات HeroKid بدون رسوم خفية</x-slot>
<x-slot name="pageDescription">اكتشف باقات HeroKid لقصص الأطفال المخصصة. بدون اشتراكات — تدفع مرة واحدة.</x-slot>

@push('schema')
<script type="application/ld+json">
{
  "@@context": "https://schema.org",
  "@@type": "WebPage",
  "name": "أسعار قصص HeroKid المخصصة",
  "url": "{{ route('pricing') }}",
  "description": "باقات HeroKid لقصص الأطفال المخصصة بأسعار واضحة بدون رسوم خفية",
  "mainEntity": {
    "@@type": "ItemList",
    "itemListElement": [
      @foreach($packages as $i => $pkg)
      {
        "@@type": "ListItem",
        "position": {{ $i + 1 }},
        "item": {
          "@@type": "Product",
          "name": "{{ addslashes($pkg->name) }}",
          "description": "{{ addslashes($pkg->description ?? '') }}",
          "brand": { "@@type": "Brand", "name": "HeroKid" },
          "offers": {
            "@@type": "Offer",
            "priceCurrency": "EGP",
            "price": "{{ $pkg->price }}",
            "availability": "https://schema.org/InStock",
            "url": "{{ route('stories.index') }}"
          }
        }
      }{{ !$loop->last ? ',' : '' }}
      @endforeach
    ]
  }
}
</script>
@endpush

    <!-- Header -->
    <div class="relative overflow-hidden bg-gradient-to-br from-slate-900 to-indigo-900 py-20">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 left-0 w-72 h-72 bg-indigo-400 rounded-full -translate-x-1/2 -translate-y-1/2"></div>
        </div>
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10">
            <span class="inline-block px-4 py-1.5 mb-4 text-sm font-bold tracking-wider text-indigo-300 uppercase bg-indigo-900/50 rounded-full">الأسعار</span>
            <h1 class="text-4xl md:text-5xl font-extrabold text-white mb-6">سعر بسيط، قيمة لا تُقدَّر</h1>
            <p class="text-xl text-slate-300 max-w-2xl mx-auto leading-relaxed">لا رسوم خفية، لا اشتراكات. تدفع مرة واحدة وتحصل على كتاب مخصص يبقى مع طفلك للأبد.</p>
        </div>
    </div>

    <!-- Pricing Cards -->
    <section class="py-24 bg-slate-50">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

            @if($packages->isEmpty())
                <div class="text-center py-20 text-slate-400">
                    <div class="text-5xl mb-4">💰</div>
                    <p>الباقات قيد التحضير، تواصل معنا للاستفسار.</p>
                </div>
            @else
                <div class="grid grid-cols-1 {{ $packages->count() == 2 ? 'md:grid-cols-2' : ($packages->count() >= 3 ? 'md:grid-cols-3' : '') }} gap-8 mb-16">
                    @foreach($packages as $pkg)
                        @if($pkg->is_featured)
                        {{-- Featured / highlighted card --}}
                        <div class="bg-indigo-600 rounded-3xl shadow-2xl p-10 flex flex-col relative overflow-hidden">
                            @if($pkg->badge)
                            <div class="absolute top-6 left-6">
                                <span class="bg-yellow-400 text-yellow-900 text-xs font-extrabold px-3 py-1 rounded-full">{{ $pkg->badge }}</span>
                            </div>
                            @endif
                            <div class="mb-8">
                                <span class="inline-block px-3 py-1 bg-indigo-500 text-indigo-100 text-xs font-bold rounded-lg mb-4">{{ $pkg->name }}</span>
                                @if($pkg->subtitle)
                                <h3 class="text-2xl font-extrabold text-white mb-2">{{ $pkg->subtitle }}</h3>
                                @endif
                                @if($pkg->description)
                                <p class="text-indigo-100 leading-relaxed">{{ $pkg->description }}</p>
                                @endif
                            </div>
                            <div class="flex items-end gap-1 mb-8">
                                <span class="text-5xl font-extrabold text-white">{{ number_format($pkg->price, 0) }}</span>
                                <span class="text-2xl font-bold text-indigo-200 mb-1">{{ $pkg->currency }}</span>
                            </div>
                            @if(!empty($pkg->features))
                            <ul class="space-y-3 mb-10 flex-grow">
                                @foreach($pkg->features as $feature)
                                <li class="flex items-center gap-3 text-indigo-100">
                                    <span class="w-5 h-5 bg-white/20 text-white rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span>
                                    {{ $feature }}
                                </li>
                                @endforeach
                            </ul>
                            @endif
                            <a href="{{ route('stories.index') }}" class="block text-center bg-white text-indigo-600 font-bold py-4 rounded-2xl hover:bg-indigo-50 transition duration-300 mt-auto">
                                {{ $pkg->button_text ?: 'اختر قصتك الآن' }}
                            </a>
                        </div>
                        @else
                        {{-- Standard card --}}
                        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-10 flex flex-col relative">
                            @if($pkg->badge)
                            <div class="absolute top-6 left-6">
                                <span class="bg-slate-100 text-slate-600 text-xs font-extrabold px-3 py-1 rounded-full">{{ $pkg->badge }}</span>
                            </div>
                            @endif
                            <div class="mb-8">
                                <span class="inline-block px-3 py-1 bg-slate-100 text-slate-600 text-xs font-bold rounded-lg mb-4">{{ $pkg->name }}</span>
                                @if($pkg->subtitle)
                                <h3 class="text-2xl font-extrabold text-slate-900 mb-2">{{ $pkg->subtitle }}</h3>
                                @endif
                                @if($pkg->description)
                                <p class="text-slate-500 leading-relaxed">{{ $pkg->description }}</p>
                                @endif
                            </div>
                            <div class="flex items-end gap-1 mb-8">
                                <span class="text-5xl font-extrabold text-indigo-600">{{ number_format($pkg->price, 0) }}</span>
                                <span class="text-2xl font-bold text-slate-500 mb-1">{{ $pkg->currency }}</span>
                            </div>
                            @if(!empty($pkg->features))
                            <ul class="space-y-3 mb-10 flex-grow">
                                @foreach($pkg->features as $feature)
                                <li class="flex items-center gap-3 text-slate-700">
                                    <span class="w-5 h-5 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span>
                                    {{ $feature }}
                                </li>
                                @endforeach
                            </ul>
                            @endif
                            <a href="{{ route('stories.index') }}" class="block text-center bg-slate-100 hover:bg-indigo-600 text-slate-800 hover:text-white font-bold py-4 rounded-2xl transition duration-300 mt-auto">
                                {{ $pkg->button_text ?: 'اختر قصتك الآن' }}
                            </a>
                        </div>
                        @endif
                    @endforeach
                </div>
            @endif

            <!-- FAQ about pricing -->
            <div class="bg-white rounded-3xl border border-slate-100 shadow-sm p-10">
                <h3 class="text-2xl font-extrabold text-slate-900 mb-8 text-center">أسئلة عن الأسعار</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div>
                        <h4 class="font-bold text-slate-900 mb-2">هل السعر شامل الشحن؟</h4>
                        <p class="text-slate-600 text-sm leading-relaxed">الشحن مضمّن في الباقة المميزة. للباقة الأساسية يُضاف رسم شحن بسيط يتراوح بين ١٥-٢٥ ج.م حسب المنطقة.</p>
                    </div>
                    <div>
                        <h4 class="font-bold text-slate-900 mb-2">متى يتم الدفع؟</h4>
                        <p class="text-slate-600 text-sm leading-relaxed">يتم الدفع بعد مراجعة الطلب وإرسالك رابط الدفع. لن يُطلب منك الدفع قبل رؤية القصة أولاً.</p>
                    </div>
                    <div>
                        <h4 class="font-bold text-slate-900 mb-2">ما هي طرق الدفع المتاحة؟</h4>
                        <p class="text-slate-600 text-sm leading-relaxed">نقبل التحويل البنكي، الدفع عبر رابط إلكتروني (مدى، فيزا، ماستركارد)، والدفع عند الاستلام للطلبات المحلية.</p>
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
