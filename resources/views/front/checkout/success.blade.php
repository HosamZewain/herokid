<x-front-layout>

{{-- ══ SEO ══ --}}
<x-slot name="pageTitle">تم استلام طلبك بنجاح</x-slot>
<x-slot name="robots">noindex, nofollow</x-slot>

    <div class="bg-gray-50 py-20 min-h-[70vh] flex flex-col justify-center">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 text-center bg-white p-10 rounded-3xl shadow-xl border border-gray-100">
            
            <div class="w-24 h-24 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg class="w-12 h-12 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            </div>

            <h1 class="text-4xl font-extrabold text-gray-900 mb-4">تم استلام طلبك بنجاح!</h1>
            <p class="text-xl text-gray-600 mb-8">شكراً لك! بدأ فريق HeroKid وذكاءنا الاصطناعي في العمل على تحويل طفلك <span class="font-bold text-indigo-600">{{ $order->child_name }}</span> إلى بطل القصة.</p>
            
            <div class="bg-indigo-50 rounded-xl p-6 mb-8 text-right">
                <h2 class="font-bold text-indigo-900 mb-4 text-lg border-b border-indigo-100 pb-2">تفاصيل الطلب:</h2>
                <ul class="space-y-3 text-indigo-800">
                    <li><span class="font-bold">رقم الطلب:</span> <span class="bg-white px-2 py-1 rounded border dir-ltr font-mono">{{ $order->order_number }}</span></li>
                    <li><span class="font-bold">القصة:</span> {{ $order->story->title }}</li>
                    <li><span class="font-bold">الإجمالي:</span> {{ $order->story->price }} ج.م</li>
                </ul>
            </div>

            <div class="text-gray-500 mb-10 text-sm bg-yellow-50 p-4 rounded-lg">
                <p>سنقوم بمراجعة الصور والبيانات وتجهيز القصة.</p>
                <p class="mt-2">سيتم التواصل معك عبر الواتساب على الرقم <strong>{{ $order->delivery_details['phone'] }}</strong> بخصوص الدفع وتأكيد التصميم النهائي.</p>
            </div>

            <div class="flex flex-col sm:flex-row justify-center gap-4">
                <a href="{{ route('stories.index') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-8 rounded-full shadow transition">
                    استكشاف المزيد من القصص
                </a>
                <a href="{{ url('/') }}" class="bg-white border-2 border-indigo-600 text-indigo-600 hover:bg-indigo-50 font-bold py-3 px-8 rounded-full transition">
                    العودة للرئيسية
                </a>
            </div>

        </div>
    </div>
</x-front-layout>
