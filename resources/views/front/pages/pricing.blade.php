<x-front-layout>
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

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-16">

                <!-- Standard Package -->
                <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-10 flex flex-col">
                    <div class="mb-8">
                        <span class="inline-block px-3 py-1 bg-slate-100 text-slate-600 text-xs font-bold rounded-lg mb-4">الباقة الأساسية</span>
                        <h3 class="text-2xl font-extrabold text-slate-900 mb-2">كتاب القصة المطبوع</h3>
                        <p class="text-slate-500 leading-relaxed">قصة مخصصة كاملة مع رسومات تحمل وجه طفلك، مطبوعة بجودة عالية.</p>
                    </div>
                    <div class="flex items-end gap-1 mb-8">
                        <span class="text-5xl font-extrabold text-indigo-600">٩٩</span>
                        <span class="text-2xl font-bold text-slate-500 mb-1">ج.م</span>
                    </div>
                    <ul class="space-y-3 mb-10 flex-grow">
                        <li class="flex items-center gap-3 text-slate-700"><span class="w-5 h-5 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span> 24 صفحة مصورة بالكامل</li>
                        <li class="flex items-center gap-3 text-slate-700"><span class="w-5 h-5 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span> غلاف ناعم (Soft Cover)</li>
                        <li class="flex items-center gap-3 text-slate-700"><span class="w-5 h-5 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span> اسم الطفل في كل صفحة</li>
                        <li class="flex items-center gap-3 text-slate-700"><span class="w-5 h-5 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span> وجه الطفل في الرسومات</li>
                        <li class="flex items-center gap-3 text-slate-700"><span class="w-5 h-5 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span> إهداء مخصص في الصفحة الأولى</li>
                        <li class="flex items-center gap-3 text-slate-700"><span class="w-5 h-5 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span> توصيل لجميع مناطق المملكة</li>
                    </ul>
                    <a href="{{ route('stories.index') }}" class="block text-center bg-slate-100 hover:bg-indigo-600 text-slate-800 hover:text-white font-bold py-4 rounded-2xl transition duration-300">
                        اختر قصتك الآن
                    </a>
                </div>

                <!-- Premium Package -->
                <div class="bg-indigo-600 rounded-3xl shadow-2xl p-10 flex flex-col relative overflow-hidden">
                    <div class="absolute top-6 left-6">
                        <span class="bg-yellow-400 text-yellow-900 text-xs font-extrabold px-3 py-1 rounded-full">الأكثر طلباً ⭐</span>
                    </div>
                    <div class="mb-8">
                        <span class="inline-block px-3 py-1 bg-indigo-500 text-indigo-100 text-xs font-bold rounded-lg mb-4">الباقة المميزة</span>
                        <h3 class="text-2xl font-extrabold text-white mb-2">كتاب هدية فاخر</h3>
                        <p class="text-indigo-100 leading-relaxed">قصة مخصصة مع غلاف صلب فاخر، مثالية كهدية لا تُنسى لأعياد الميلاد والمناسبات.</p>
                    </div>
                    <div class="flex items-end gap-1 mb-8">
                        <span class="text-5xl font-extrabold text-white">١٤٩</span>
                        <span class="text-2xl font-bold text-indigo-200 mb-1">ج.م</span>
                    </div>
                    <ul class="space-y-3 mb-10 flex-grow">
                        <li class="flex items-center gap-3 text-indigo-100"><span class="w-5 h-5 bg-white/20 text-white rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span> كل مزايا الباقة الأساسية</li>
                        <li class="flex items-center gap-3 text-indigo-100"><span class="w-5 h-5 bg-white/20 text-white rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span> غلاف صلب فاخر (Hard Cover)</li>
                        <li class="flex items-center gap-3 text-indigo-100"><span class="w-5 h-5 bg-white/20 text-white rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span> 32 صفحة مصورة</li>
                        <li class="flex items-center gap-3 text-indigo-100"><span class="w-5 h-5 bg-white/20 text-white rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span> تغليف هدايا فاخر</li>
                        <li class="flex items-center gap-3 text-indigo-100"><span class="w-5 h-5 bg-white/20 text-white rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span> شحن مجاني مضمون</li>
                        <li class="flex items-center gap-3 text-indigo-100"><span class="w-5 h-5 bg-white/20 text-white rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span> أولوية في المعالجة (3 أيام)</li>
                    </ul>
                    <a href="{{ route('stories.index') }}" class="block text-center bg-white text-indigo-600 font-bold py-4 rounded-2xl hover:bg-indigo-50 transition duration-300">
                        ابدأ الآن
                    </a>
                </div>
            </div>

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
