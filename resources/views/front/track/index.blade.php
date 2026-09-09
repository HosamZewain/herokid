<x-front-layout>
    <x-slot name="pageTitle">متابعة وتعديل طلبك</x-slot>
    <x-slot name="pageDescription">تابع حالة طلب HeroKid أو عدّل بياناته أو ألغِه بأمان باستخدام رقم الطلب ورقم الموبايل.</x-slot>
    <x-slot name="robots">noindex, nofollow</x-slot>

    <main class="min-h-[70vh] bg-slate-50 py-12 sm:py-20">
        <div class="mx-auto max-w-lg px-4 sm:px-6">
            <section class="overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-xl shadow-slate-200/50">
                <div class="bg-gradient-to-l from-indigo-700 to-violet-600 px-6 py-8 text-center text-white sm:px-9">
                    <span class="inline-flex rounded-full bg-white/15 px-3 py-1 text-xs font-black">خدمة آمنة للعملاء</span>
                    <h1 class="mt-4 text-3xl font-black">متابعة وتعديل طلبك</h1>
                    <p class="mt-3 text-sm font-bold leading-6 text-indigo-100">أدخل رقم الطلب ورقم الموبايل المسجل عليه لعرض الحالة، أو تعديل الطلب وإلغائه إذا لم يبدأ التنفيذ.</p>
                </div>

                <div class="p-6 sm:p-9">
                    @if(session('error'))
                        <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700" role="alert">{{ session('error') }}</div>
                    @endif

                    <form action="{{ route('track.search') }}" method="POST" class="space-y-5">
                        @csrf
                        <div>
                            <label for="order_number" class="mb-2 block text-sm font-black text-slate-800">رقم الطلب</label>
                            <input type="text" name="order_number" id="order_number" value="{{ old('order_number') }}" placeholder="مثال: HK09-236" required autocomplete="off" dir="ltr" class="block min-h-12 w-full rounded-2xl border-slate-200 text-center font-mono text-lg font-black uppercase shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <x-input-error :messages="$errors->get('order_number')" class="mt-2" />
                        </div>
                        <div>
                            <label for="phone" class="mb-2 block text-sm font-black text-slate-800">رقم الموبايل / واتساب</label>
                            <input type="tel" inputmode="tel" name="phone" id="phone" value="{{ old('phone') }}" required autocomplete="tel" dir="ltr" class="block min-h-12 w-full rounded-2xl border-slate-200 text-left shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                        </div>
                        <button type="submit" class="flex min-h-12 w-full items-center justify-center rounded-2xl bg-indigo-600 px-5 py-3.5 text-base font-black text-white shadow-lg shadow-indigo-100 transition hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-200">فتح الطلب</button>
                    </form>
                    <p class="mt-5 text-center text-xs font-bold leading-5 text-slate-500">لن نعرض أي بيانات إلا عند تطابق رقم الطلب ورقم الهاتف.</p>
                </div>
            </section>
        </div>
    </main>
</x-front-layout>
