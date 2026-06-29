<x-front-layout>
<x-slot name="pageTitle">سلة الطلب</x-slot>
<x-slot name="pageDescription">راجع قصص HeroKid المخصصة في السلة وأدخل بيانات ولي الأمر والتوصيل مرة واحدة قبل إرسال الطلب.</x-slot>
<x-slot name="robots">noindex, nofollow</x-slot>

<div class="bg-slate-50 py-12 min-h-[70vh]">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
            <div class="text-right">
                <h1 class="text-3xl font-black text-slate-900">سلة الطلب</h1>
                <p class="text-slate-500 mt-2">يمكنك طلب قصة واحدة أو أكثر. بيانات التوصيل تُكتب مرة واحدة لكل السلة.</p>
            </div>
            <a href="{{ route('stories.index') }}" class="inline-flex items-center justify-center rounded-xl border border-indigo-200 bg-white px-5 py-3 text-sm font-bold text-indigo-700 hover:bg-indigo-50 transition">
                إضافة قصة أخرى
            </a>
        </div>

        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-700 px-5 py-4 rounded-xl font-bold mb-6 text-right">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-700 px-5 py-4 rounded-xl font-bold mb-6 text-right">
                {{ session('error') }}
            </div>
        @endif

        @if(empty($cartItems))
            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-10 text-center">
                <div class="text-5xl mb-4">🛒</div>
                <h2 class="text-xl font-black text-slate-900 mb-2">السلة فارغة</h2>
                <p class="text-slate-500 mb-6">اختر قصة، اكتب بيانات الطفل، وارفع الصور لإضافتها هنا.</p>
                <a href="{{ route('stories.index') }}" class="inline-flex bg-indigo-600 hover:bg-indigo-700 text-white px-7 py-3 rounded-xl font-bold transition">
                    تصفح القصص
                </a>
            </div>
        @else
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-4">
                    @foreach($cartItems as $key => $item)
                        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                            <div class="flex items-start justify-between gap-4">
                                <form action="{{ route('cart.destroy', $key) }}" method="POST">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-bold bg-red-50 hover:bg-red-100 rounded-lg px-3 py-2 transition">
                                        حذف
                                    </button>
                                </form>
                                <div class="text-right flex-1">
                                    <h2 class="text-lg font-black text-slate-900">{{ $item['story_title'] ?? 'قصة' }}</h2>
                                    <p class="text-sm text-slate-500 mt-1">
                                        الطفل: <span class="font-bold text-slate-800">{{ $item['child_name'] ?? '-' }}</span>
                                        · {{ $item['child_age'] ?? '-' }} سنة
                                        · {{ ($item['child_gender'] ?? '') === 'boy' ? 'ولد' : 'بنت' }}
                                    </p>
                                    @if(!empty($item['interests']))
                                        <p class="text-sm text-slate-500 mt-2">الاهتمامات: {{ $item['interests'] }}</p>
                                    @endif
                                    <p class="text-xs text-slate-400 mt-2">{{ count($item['uploaded_photos'] ?? []) }} صورة مرفقة</p>
                                </div>
                                <div class="text-left min-w-24">
                                    <p class="text-lg font-black text-indigo-600">{{ number_format((float) ($item['story_price'] ?? 0), 0) }} ج.م</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="space-y-6">
                    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                        <h2 class="text-lg font-black text-slate-900 mb-4 text-right">ملخص السعر</h2>
                        <div class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <span class="font-bold text-slate-900">{{ number_format($subtotal, 0) }} ج.م</span>
                                <span class="text-slate-500">إجمالي القصص</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="font-bold text-slate-900">{{ number_format($deliveryFee, 0) }} ج.م</span>
                                <span class="text-slate-500">مصاريف التوصيل</span>
                            </div>
                            <div class="border-t pt-3 flex justify-between">
                                <span class="font-black text-indigo-700 text-lg">{{ number_format($subtotal + $deliveryFee, 0) }} ج.م</span>
                                <span class="font-bold text-slate-900">الإجمالي</span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                        <h2 class="text-lg font-black text-slate-900 mb-4 text-right">بيانات ولي الأمر والتوصيل</h2>

                        @guest
                            <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 text-sm text-blue-800 mb-4 text-right">
                                أنت تطلب كزائر. يمكنك <a href="{{ route('login') }}" class="font-bold underline">تسجيل الدخول</a> لمتابعة طلبك لاحقاً.
                            </div>
                        @endguest

                        <form action="{{ route('checkout.store') }}" method="POST" class="space-y-4">
                            @csrf
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">اسم ولي الأمر <span class="text-red-500">*</span></label>
                                <input type="text" name="parent_name" value="{{ old('parent_name', auth()->user()->name ?? '') }}" required
                                    class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3">
                                <x-input-error :messages="$errors->get('parent_name')" class="mt-1" />
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">البريد الإلكتروني <span class="text-red-500">*</span></label>
                                <input type="email" name="email" value="{{ old('email', auth()->user()->email ?? '') }}" required dir="ltr"
                                    class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3">
                                <x-input-error :messages="$errors->get('email')" class="mt-1" />
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">الهاتف / واتساب <span class="text-red-500">*</span></label>
                                <input type="text" name="phone" value="{{ old('phone') }}" required dir="ltr"
                                    class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3">
                                <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">المحافظة <span class="text-red-500">*</span></label>
                                    <input type="text" name="governorate" value="{{ old('governorate') }}" required
                                        class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3">
                                    <x-input-error :messages="$errors->get('governorate')" class="mt-1" />
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">المدينة / المنطقة <span class="text-red-500">*</span></label>
                                    <input type="text" name="city" value="{{ old('city') }}" required
                                        class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3">
                                    <x-input-error :messages="$errors->get('city')" class="mt-1" />
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">العنوان بالتفصيل <span class="text-red-500">*</span></label>
                                <textarea name="address" rows="3" required
                                    class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3">{{ old('address') }}</textarea>
                                <x-input-error :messages="$errors->get('address')" class="mt-1" />
                            </div>
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-black py-4 rounded-2xl shadow-lg shadow-indigo-100 transition">
                                تأكيد الطلب
                            </button>
                            <p class="text-center text-xs text-slate-400">الدفع يتم بعد مراجعة الطلب والتواصل معك للتأكيد.</p>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
</x-front-layout>
