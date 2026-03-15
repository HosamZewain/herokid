<x-front-layout>
<div class="bg-slate-50 min-h-screen py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Breadcrumb -->
        <div class="mb-8">
            <a href="{{ route('stories.index') }}" class="text-indigo-600 hover:text-indigo-800 flex items-center gap-2 font-medium text-sm w-fit">
                <svg class="w-4 h-4 rtl:rotate-180 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                العودة لمكتبة القصص
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-start">

            {{-- ===== LEFT: Story Details ===== --}}
            <div class="text-right">

                <!-- Cover Image -->
                <div class="aspect-[4/3] bg-gradient-to-br from-indigo-50 to-slate-100 rounded-3xl overflow-hidden shadow-lg mb-8 relative">
                    @if($story->cover_image)
                        <img src="{{ $story->cover_url }}" alt="{{ $story->title }}" class="w-full h-full object-cover">
                    @else
                        <img src="https://images.unsplash.com/photo-1446776811953-b23d57bd21aa?w=800&auto=format&fit=crop&q=80"
                             alt="{{ $story->title }}" class="w-full h-full object-cover">
                    @endif
                </div>

                <!-- Title & Price -->
                <h1 class="text-4xl font-extrabold text-slate-900 mb-4">{{ $story->title }}</h1>
                <div class="flex items-center gap-4 mb-6 justify-end">
                    <span class="text-4xl font-extrabold text-indigo-600">{{ number_format($story->price, 0) }} ج.م</span>
                    <div class="flex gap-2 flex-wrap justify-end">
                        @if($story->age_range)
                            <span class="bg-indigo-50 text-indigo-700 text-sm font-bold px-3 py-1.5 rounded-full">👶 {{ $story->age_range }}</span>
                        @endif
                        <span class="bg-slate-100 text-slate-700 text-sm font-bold px-3 py-1.5 rounded-full">{{ $story->language == 'ar' ? '🇸🇦 عربي' : '🇬🇧 English' }}</span>
                    </div>
                </div>

                <!-- Description -->
                <div class="text-slate-600 leading-relaxed mb-6 text-lg">
                    {{ $story->full_desc ?: $story->short_desc }}
                </div>

                <!-- Lesson -->
                @if($story->lesson_value)
                <div class="bg-amber-50 border border-amber-100 rounded-2xl p-5 mb-8">
                    <p class="text-amber-800 font-bold mb-1">💡 الدرس المستفاد من هذه القصة:</p>
                    <p class="text-amber-700">{{ $story->lesson_value }}</p>
                </div>
                @endif

                <!-- What's included -->
                <div class="bg-white border border-slate-100 rounded-2xl p-6 mb-8 shadow-sm">
                    <h3 class="font-bold text-slate-900 text-lg mb-4">ما يتضمنه الكتاب:</h3>
                    <ul class="space-y-3">
                        <li class="flex items-center gap-3 text-slate-700 justify-end"><span>اسم طفلك في كل صفحة من القصة</span><span class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span></li>
                        <li class="flex items-center gap-3 text-slate-700 justify-end"><span>وجه طفلك الحقيقي في رسومات الشخصية الرئيسية</span><span class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span></li>
                        <li class="flex items-center gap-3 text-slate-700 justify-end"><span>إهداء شخصي مطبوع في الصفحة الأولى</span><span class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span></li>
                        <li class="flex items-center gap-3 text-slate-700 justify-end"><span>طباعة احترافية عالية الجودة</span><span class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span></li>
                        <li class="flex items-center gap-3 text-slate-700 justify-end"><span>مراجعة تصميم قبل الطباعة (Preview)</span><span class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span></li>
                        <li class="flex items-center gap-3 text-slate-700 justify-end"><span>شحن لجميع محافظات مصر</span><span class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs flex-shrink-0">✓</span></li>
                    </ul>
                </div>

                <!-- Delivery Time -->
                <div class="bg-indigo-50 rounded-2xl p-5 flex items-center gap-4 justify-end">
                    <div class="text-right">
                        <p class="font-bold text-indigo-800 text-lg">متوسط وقت التوصيل: ٧–١٠ أيام عمل</p>
                        <p class="text-indigo-600 text-sm">من تاريخ تأكيد الدفع وموافقتك على التصميم</p>
                    </div>
                    <span class="text-4xl flex-shrink-0">🚀</span>
                </div>
            </div>

            {{-- ===== RIGHT: Order Form ===== --}}
            <div class="lg:sticky lg:top-24">
                <div class="bg-white rounded-3xl p-8 shadow-xl border border-slate-100">
                    <div class="flex items-center gap-3 mb-8 justify-end">
                        <div class="text-right">
                            <h2 class="text-2xl font-extrabold text-slate-900">خصّص قصتك واطلبها</h2>
                            <p class="text-slate-500 text-sm mt-1">يستغرق التعبئة أقل من ٣ دقائق</p>
                        </div>
                        <div class="w-12 h-12 bg-indigo-100 rounded-2xl flex items-center justify-center text-2xl flex-shrink-0">✍️</div>
                    </div>

                    @if(session('success'))
                    <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-6 text-center font-bold">
                        {{ session('success') }}
                    </div>
                    @endif

                    <form action="{{ route('checkout.store', $story) }}" method="POST" enctype="multipart/form-data" class="space-y-0">
                        @csrf

                        {{-- SECTION 1: Parent Info --}}
                        <div class="mb-6">
                            <div class="flex items-center gap-2 mb-4 justify-end">
                                <h3 class="text-base font-extrabold text-indigo-800">بيانات ولي الأمر</h3>
                                <span class="bg-indigo-600 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0">١</span>
                            </div>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">الاسم الكامل <span class="text-red-500">*</span></label>
                                    <input type="text" name="parent_name" value="{{ old('parent_name', auth()->user()->name ?? '') }}" required
                                        class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                        placeholder="اسمك الكامل">
                                    <x-input-error :messages="$errors->get('parent_name')" class="mt-1"/>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">رقم الهاتف / واتساب <span class="text-red-500">*</span></label>
                                        <input type="text" name="phone" value="{{ old('phone') }}" required dir="ltr"
                                            class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3"
                                            placeholder="+20 1XX XXXX XXX">
                                        <x-input-error :messages="$errors->get('phone')" class="mt-1"/>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">البريد الإلكتروني <span class="text-red-500">*</span></label>
                                        <input type="email" name="email" value="{{ old('email', auth()->user()->email ?? '') }}" required dir="ltr"
                                            class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3"
                                            placeholder="email@example.com">
                                        <p class="text-xs text-slate-400 mt-1 text-right">سنرسل رابط التصميم هنا</p>
                                        <x-input-error :messages="$errors->get('email')" class="mt-1"/>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr class="border-slate-100 my-6">

                        {{-- SECTION 2: Child Info --}}
                        <div class="mb-6">
                            <div class="flex items-center gap-2 mb-4 justify-end">
                                <h3 class="text-base font-extrabold text-indigo-800">بيانات البطل (الطفل)</h3>
                                <span class="bg-pink-500 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0">٢</span>
                            </div>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">اسم الطفل <span class="text-red-500">*</span></label>
                                    <input type="text" name="child_name" value="{{ old('child_name') }}" required
                                        class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                        placeholder="الاسم الأول للطفل">
                                    <x-input-error :messages="$errors->get('child_name')" class="mt-1"/>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">العمر <span class="text-red-500">*</span></label>
                                        <input type="number" name="child_age" value="{{ old('child_age') }}" required min="1" max="18"
                                            class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-center py-3">
                                        <x-input-error :messages="$errors->get('child_age')" class="mt-1"/>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">الجنس <span class="text-red-500">*</span></label>
                                        <select name="child_gender" required class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3">
                                            <option value="">اختر...</option>
                                            <option value="boy" @selected(old('child_gender') == 'boy')>ولد 👦</option>
                                            <option value="girl" @selected(old('child_gender') == 'girl')>بنت 👧</option>
                                        </select>
                                        <x-input-error :messages="$errors->get('child_gender')" class="mt-1"/>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">اهتمامات الطفل (اختياري)</label>
                                    <input type="text" name="interests" value="{{ old('interests') }}"
                                        class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                        placeholder="مثال: رائد فضاء، ديناصورات، كرة قدم...">
                                    <p class="text-xs text-slate-400 mt-1 text-right">سنحاول دمجها في القصة إن أمكن</p>
                                    <x-input-error :messages="$errors->get('interests')" class="mt-1"/>
                                </div>
                            </div>
                        </div>

                        <hr class="border-slate-100 my-6">

                        {{-- SECTION 3: Photos --}}
                        <div class="mb-6">
                            <div class="flex items-center gap-2 mb-4 justify-end">
                                <h3 class="text-base font-extrabold text-indigo-800">صور الطفل</h3>
                                <span class="bg-amber-500 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0">٣</span>
                            </div>
                            <div class="bg-indigo-50 border-2 border-dashed border-indigo-200 rounded-2xl p-6 text-right">
                                <p class="font-bold text-indigo-800 mb-2">📸 ارفع ١–٥ صور واضحة للوجه</p>
                                <ul class="text-xs text-indigo-600 space-y-1 mb-4">
                                    <li>• صور واضحة لوجه الطفل (بدون نظارة شمسية)</li>
                                    <li>• تنسيق JPG أو PNG — حد أقصى ٥ ميجا للصورة</li>
                                    <li>• كلما كانت الصور أوضح، كانت الرسومات أجمل</li>
                                </ul>
                                <input type="file" name="photos[]" id="photos" multiple accept="image/jpeg,image/png,image/jpg" required
                                    class="block w-full text-sm text-slate-500 file:ml-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-bold file:bg-indigo-600 file:text-white hover:file:bg-indigo-700 file:cursor-pointer">
                                <x-input-error :messages="$errors->get('photos')" class="mt-2"/>
                                <x-input-error :messages="$errors->get('photos.*')" class="mt-2"/>
                            </div>
                        </div>

                        <hr class="border-slate-100 my-6">

                        {{-- SECTION 4: Personalization & Gift --}}
                        <div class="mb-6">
                            <div class="flex items-center gap-2 mb-4 justify-end">
                                <h3 class="text-base font-extrabold text-indigo-800">إضافات خاصة (اختياري)</h3>
                                <span class="bg-green-600 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0">٤</span>
                            </div>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">إهداء يُطبع في الصفحة الأولى</label>
                                    <textarea name="gift_note" rows="2"
                                        class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                        placeholder="إلى ابني الغالي... أنت بطلنا الحقيقي ❤️">{{ old('gift_note') }}</textarea>
                                    <x-input-error :messages="$errors->get('gift_note')" class="mt-1"/>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">ملاحظات إضافية للفريق</label>
                                    <textarea name="parent_notes" rows="2"
                                        class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                        placeholder="أي تفاصيل إضافية تريد إضافتها...">{{ old('parent_notes') }}</textarea>
                                    <x-input-error :messages="$errors->get('parent_notes')" class="mt-1"/>
                                </div>
                            </div>
                        </div>

                        <hr class="border-slate-100 my-6">

                        {{-- SECTION 5: Delivery --}}
                        <div class="mb-6">
                            <div class="flex items-center gap-2 mb-4 justify-end">
                                <h3 class="text-base font-extrabold text-indigo-800">عنوان التوصيل</h3>
                                <span class="bg-slate-700 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0">٥</span>
                            </div>

                            @guest
                            <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 flex items-start gap-3 text-sm text-blue-800 mb-4">
                                <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <p>أنت تطلب كزائر. ننصح بـ <a href="{{ route('login') }}" class="font-bold underline">تسجيل الدخول</a> لتتمكن من متابعة طلبك لاحقاً بسهولة.</p>
                            </div>
                            @endguest

                            <div class="space-y-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">المحافظة <span class="text-red-500">*</span></label>
                                        <input type="text" name="governorate" value="{{ old('governorate') }}" required
                                            class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                            placeholder="القاهرة">
                                        <x-input-error :messages="$errors->get('governorate')" class="mt-1"/>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">المدينة / المنطقة <span class="text-red-500">*</span></label>
                                        <input type="text" name="city" value="{{ old('city') }}" required
                                            class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                            placeholder="مدينة نصر">
                                        <x-input-error :messages="$errors->get('city')" class="mt-1"/>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">العنوان بالتفصيل <span class="text-red-500">*</span></label>
                                    <textarea name="address" rows="2" required
                                        class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                        placeholder="شارع، رقم المبنى، الدور، الشقة...">{{ old('address') }}</textarea>
                                    <x-input-error :messages="$errors->get('address')" class="mt-1"/>
                                </div>
                            </div>
                        </div>

                        {{-- Privacy Consent --}}
                        <div class="bg-yellow-50 border border-yellow-200 rounded-2xl p-5 mb-6">
                            <div class="flex items-start gap-3">
                                <input id="privacy_consent" name="privacy_consent" type="checkbox" required
                                    class="mt-1 h-5 w-5 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500 flex-shrink-0">
                                <label for="privacy_consent" class="text-sm text-slate-700 text-right cursor-pointer">
                                    <span class="font-bold block mb-1">موافقة صريحة على استخدام الصور</span>
                                    أوافق على استخدام صور طفلي المرفوعة حصرياً لإنشاء رسومات القصة المطبوعة لهذا الطلب. أؤكد أنني أمتلك الحق القانوني لرفع هذه الصور، وأوافق على حذفها من الخوادم بعد إتمام الطلب وفق <a href="{{ route('privacy') }}" class="underline text-indigo-600 hover:text-indigo-800" target="_blank">سياسة الخصوصية</a>.
                                </label>
                            </div>
                            <x-input-error :messages="$errors->get('privacy_consent')" class="mt-2"/>
                        </div>

                        {{-- Submit --}}
                        <button type="submit"
                            class="w-full flex justify-center items-center gap-3 py-4 px-6 rounded-2xl text-lg font-extrabold text-white bg-indigo-600 hover:bg-indigo-700 shadow-xl shadow-indigo-200 transition hover:-translate-y-0.5 focus:ring-4 focus:ring-indigo-300">
                            <span>تأكيد الطلب — {{ number_format($story->price, 0) }} ج.م</span>
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                        </button>
                        <p class="text-center text-xs text-slate-400 mt-3">
                            الدفع يتم بعد مراجعة الطلب وإرسال تأكيد الطلب عليك
                        </p>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>
</x-front-layout>
