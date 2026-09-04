<x-front-layout>

{{-- ══ SEO ══ --}}
<x-slot name="pageTitle">تتبع طلبك</x-slot>
<x-slot name="pageDescription">تتبع حالة طلب قصة طفلك المخصصة من HeroKid. أدخل رقم الطلب للاطلاع على آخر تحديثات الطباعة والشحن.</x-slot>
<x-slot name="robots">noindex, nofollow</x-slot>

    <div class="bg-gray-50 py-20 min-h-[70vh]">
        <div class="max-w-md mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white p-8 rounded-3xl shadow-xl border border-gray-100">
                
                <h1 class="text-3xl font-extrabold text-gray-900 mb-6 text-center">تتبع طلبك</h1>
                <p class="text-gray-600 mb-8 text-center text-sm">أدخل رقم الطلب ورقم الموبايل المستخدم أثناء الطلب لمعرفة حالة قصتك.</p>

                @if(session('error'))
                    <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg mb-6 text-sm">
                        {{ session('error') }}
                    </div>
                @endif

                <form action="{{ route('track.search') }}" method="POST">
                    @csrf
                    
                    <div class="space-y-5">
                        <div>
                            <label for="order_number" class="block text-sm font-medium text-gray-700 mb-1">رقم الطلب</label>
                            <input type="text" name="order_number" id="order_number" value="{{ old('order_number') }}" placeholder="HK09-236" required class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dir-ltr text-center font-mono">
                            <x-input-error :messages="$errors->get('order_number')" class="mt-2" />
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">رقم الموبايل / واتساب</label>
                            <input type="text" name="phone" id="phone" value="{{ old('phone') }}" required dir="ltr" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-left">
                            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                        </div>
                    </div>

                    <button type="submit" class="mt-8 w-full flex justify-center py-3 px-4 border border-transparent rounded-full shadow-sm text-base font-bold text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition">
                        ابحث عن الطلب
                    </button>
                    
                </form>

            </div>
        </div>
    </div>
</x-front-layout>
