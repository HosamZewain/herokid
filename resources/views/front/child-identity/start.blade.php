<x-front-layout>
    <x-slot name="pageTitle">اصنع هوية طفلك</x-slot>
    <x-slot name="pageDescription">أنشئ هوية بصرية متناسقة لطفلك مجانًا ثم اختر قصته المخصصة من HeroKid.</x-slot>
    <x-slot name="robots">noindex, nofollow</x-slot>

    <main class="min-h-screen bg-gradient-to-b from-indigo-50 via-white to-amber-50 py-10 sm:py-16" dir="rtl">
        <div class="mx-auto max-w-3xl px-4">
            <div class="mb-8 text-center">
                <span class="inline-flex rounded-full bg-indigo-100 px-4 py-2 text-sm font-black text-indigo-700">خدمة مجانية من HeroKid</span>
                <h1 class="mt-5 text-3xl font-black text-slate-950 sm:text-5xl">اصنع هوية طفلك</h1>
                <p class="mx-auto mt-4 max-w-2xl text-base leading-8 text-slate-600 sm:text-lg">
                    ارفع صورًا واضحة لنصنع مرجعًا بصريًا متناسقًا لطفلك، ثم اختر القصة المناسبة وأكمل الطلب العادي.
                </p>
            </div>

            @if(!$enabled)
                <div class="rounded-3xl border border-amber-200 bg-amber-50 p-8 text-center font-bold text-amber-900">
                    الخدمة متوقفة مؤقتًا. يرجى العودة لاحقًا.
                </div>
            @else
                <form method="POST" action="{{ route('child-identity.store') }}" class="space-y-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-xl shadow-indigo-100/50 sm:p-8">
                    @csrf
                    <div>
                        <h2 class="text-xl font-black text-slate-900">بيانات ولي الأمر والطفل</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-500">نحفظ الطلب بعد هذه الخطوة حتى يمكنك الرجوع إليه بأمان.</p>
                    </div>

                    @if($errors->any())
                        <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm font-bold text-slate-700">اسم ولي الأمر
                            <input name="parent_name" value="{{ old('parent_name') }}" required class="mt-2 w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </label>
                        <label class="block text-sm font-bold text-slate-700">رقم واتساب
                            <input name="parent_phone" value="{{ old('parent_phone') }}" inputmode="tel" required class="mt-2 w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </label>
                        <label class="block text-sm font-bold text-slate-700">البريد الإلكتروني <span class="font-normal text-slate-400">(اختياري)</span>
                            <input type="email" name="parent_email" value="{{ old('parent_email') }}" class="mt-2 w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </label>
                        <label class="block text-sm font-bold text-slate-700">اسم الطفل
                            <input name="child_name" value="{{ old('child_name') }}" required class="mt-2 w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </label>
                        <label class="block text-sm font-bold text-slate-700">عمر الطفل بالسنوات
                            <input type="number" min="1" max="18" name="child_age" value="{{ old('child_age') }}" required class="mt-2 w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                        </label>
                        <label class="block text-sm font-bold text-slate-700">النوع
                            <select name="gender" class="mt-2 w-full rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">غير محدد</option>
                                <option value="boy" @selected(old('gender') === 'boy')>ولد</option>
                                <option value="girl" @selected(old('gender') === 'girl')>بنت</option>
                            </select>
                        </label>
                    </div>

                    @foreach(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $utm)
                        <input type="hidden" name="{{ $utm }}" value="{{ request($utm) }}">
                    @endforeach

                    <div class="space-y-3 rounded-2xl bg-slate-50 p-4 text-sm leading-7 text-slate-600">
                        <label class="flex items-start gap-3">
                            <input type="checkbox" name="processing_consent" value="1" required class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span>أوافق على معالجة صور طفلي لإنشاء الهوية والقصة. تُحفظ الصور الأصلية والمخرجات والمحاولات بشكل آمن لدعم الطلب وسجل الخدمة، ولا تُحذف تلقائيًا. يمكن طلب الحذف وفق <a href="{{ route('privacy') }}" class="font-bold text-indigo-700 underline">سياسة الخصوصية</a>.</span>
                        </label>
                        <label class="flex items-start gap-3">
                            <input type="checkbox" name="marketing_consent" value="1" class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span>أوافق على استقبال عروض HeroKid. <strong>اختياري ومنفصل</strong> عن موافقة تنفيذ الخدمة.</span>
                        </label>
                    </div>

                    <button class="w-full rounded-2xl bg-gradient-to-l from-indigo-600 to-violet-600 px-6 py-4 text-base font-black text-white shadow-lg shadow-indigo-200 transition hover:-translate-y-0.5">
                        حفظ الطلب والانتقال للصور
                    </button>
                </form>
            @endif
        </div>
    </main>
</x-front-layout>
