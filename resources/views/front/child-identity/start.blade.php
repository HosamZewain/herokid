<x-front-layout>
    <x-slot name="pageTitle">اصنع هوية طفلك</x-slot>
    <x-slot name="pageDescription">أدخل البيانات وارفع صور طفلك في خطوة واحدة، وسنبدأ إنشاء الهوية تلقائيًا.</x-slot>
    <x-slot name="robots">noindex, nofollow</x-slot>

    <main class="min-h-screen bg-gradient-to-b from-indigo-50 via-white to-amber-50 py-8 sm:py-14" dir="rtl">
        <div class="mx-auto max-w-4xl px-4">
            <header class="mb-7 text-center">
                <span class="inline-flex rounded-full bg-indigo-100 px-4 py-2 text-sm font-black text-indigo-700">مجانية • خطوة واحدة</span>
                <h1 class="mt-4 text-3xl font-black text-slate-950 sm:text-5xl">اصنع هوية طفلك</h1>
                <p class="mx-auto mt-3 max-w-2xl text-sm leading-7 text-slate-600 sm:text-lg">
                    أدخل البيانات الأساسية واختر صورتين أو ٣ صور. يبدأ إنشاء الهوية تلقائيًا بعد الإرسال.
                </p>
            </header>

            @if(!$enabled)
                <div class="rounded-3xl border border-amber-200 bg-amber-50 p-8 text-center font-bold text-amber-900">
                    الخدمة متوقفة مؤقتًا. يرجى العودة لاحقًا.
                </div>
            @else
                <form method="POST" action="{{ route('child-identity.store') }}"
                      class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl shadow-indigo-100/60"
                      data-identity-intake>
                    @csrf
                    <input type="hidden" name="upload_session_token" value="{{ $photoUploadConfig['sessionToken'] }}">
                    <script type="application/json" data-identity-upload-config>@json(array_merge($photoUploadConfig, [
                        'serverRejectedUploads' => $errors->has('photo_upload_ids') || $errors->has('photo_upload_ids.*'),
                    ]))</script>

                    <div class="border-b border-slate-100 bg-slate-50/70 px-5 py-5 sm:px-8">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <h2 class="text-xl font-black text-slate-900">بيانات الطفل والصور</h2>
                                <p class="mt-1 text-sm text-slate-500">كل المطلوب في هذه الصفحة فقط.</p>
                            </div>
                            <span class="rounded-full bg-white px-4 py-2 text-xs font-black text-indigo-700 ring-1 ring-indigo-100">الخطوة ١ من ٣</span>
                        </div>
                    </div>

                    <div class="space-y-7 p-5 sm:p-8">
                        @if($errors->any())
                            <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700" data-scroll-on-load tabindex="-1">
                                {{ $errors->first() }}
                            </div>
                        @endif

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block text-sm font-black text-slate-700">
                                اسم ولي الأمر
                                <input name="parent_name" value="{{ old('parent_name') }}" autocomplete="name" required
                                       class="mt-2 w-full rounded-xl border-slate-300 px-4 py-3 focus:border-indigo-500 focus:ring-indigo-500">
                            </label>
                            <label class="block text-sm font-black text-slate-700">
                                رقم واتساب
                                <input name="parent_phone" value="{{ old('parent_phone') }}" inputmode="tel" autocomplete="tel" required
                                       class="mt-2 w-full rounded-xl border-slate-300 px-4 py-3 focus:border-indigo-500 focus:ring-indigo-500">
                            </label>
                            <label class="block text-sm font-black text-slate-700">
                                اسم الطفل
                                <input name="child_name" value="{{ old('child_name') }}" autocomplete="off" required
                                       class="mt-2 w-full rounded-xl border-slate-300 px-4 py-3 focus:border-indigo-500 focus:ring-indigo-500">
                            </label>
                            <label class="block text-sm font-black text-slate-700">
                                الفئة العمرية
                                <select name="age_range" required
                                        class="mt-2 w-full rounded-xl border-slate-300 px-4 py-3 focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">اختر الفئة العمرية</option>
                                    @foreach($ageRanges as $range)
                                        <option value="{{ $range }}" @selected(old('age_range') === $range)>{{ $range }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <section class="rounded-3xl border-2 border-dashed border-indigo-200 bg-indigo-50/60 p-4 sm:p-6">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-lg font-black text-indigo-950">صور الطفل</h3>
                                    <p class="mt-1 text-sm leading-6 text-indigo-700">اختر صورتين أو ٣ صور معًا. JPG وPNG وWebP وHEIC/HEIF مدعومة.</p>
                                </div>
                                <span class="rounded-full bg-white px-3 py-1.5 text-xs font-black text-indigo-700" data-identity-photo-count>تم رفع ٠ من ٢ المطلوبة</span>
                            </div>

                            <input type="file" id="identity-photos" multiple
                                   accept="image/*,.jpg,.jpeg,.png,.webp,.heic,.heif"
                                   class="sr-only" data-identity-photo-input>
                            <div data-identity-photo-ids></div>

                            <label for="identity-photos"
                                   data-identity-photo-picker
                                   class="mt-5 flex min-h-28 cursor-pointer flex-col items-center justify-center rounded-2xl border border-indigo-200 bg-white px-5 py-6 text-center transition hover:border-indigo-400 hover:bg-indigo-50">
                                <span class="text-base font-black text-indigo-700" data-identity-photo-picker-title>اختيار صور الطفل</span>
                                <span class="mt-1 text-xs font-bold text-slate-500" data-identity-photo-picker-help>اختر صورتين أو ٣ صور مرة واحدة وسيبدأ رفعها تلقائيًا</span>
                            </label>

                            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3" data-identity-photo-queue aria-live="polite"></div>
                            <div id="identity-photo-guidance"
                                 class="mt-4 rounded-2xl border border-indigo-200 bg-white px-4 py-3"
                                 role="status" aria-live="polite" data-identity-photo-requirement>
                                <p class="text-sm font-black text-indigo-950" data-identity-photo-requirement-title>اختر صورتين للمتابعة</p>
                                <p class="mt-1 text-xs font-bold leading-6 text-indigo-700" data-identity-photo-requirement-description>
                                    نحتاج صورتين واضحتين على الأقل، ويمكنك إضافة صورة ثالثة اختيارية.
                                </p>
                            </div>
                            <div class="mt-4 hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-black text-red-700" data-identity-photo-error></div>
                            <x-input-error :messages="$errors->get('photo_upload_ids')" class="mt-3" />
                            <x-input-error :messages="$errors->get('photo_upload_ids.*')" class="mt-3" />
                        </section>

                        @foreach(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $utm)
                            <input type="hidden" name="{{ $utm }}" value="{{ request($utm) }}">
                        @endforeach

                        <label class="flex items-start gap-3 rounded-2xl bg-slate-50 p-4 text-xs font-bold leading-6 text-slate-600">
                            <input type="checkbox" name="processing_consent" value="1" required
                                   class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span>
                                أوافق على معالجة الصور وحفظها بأمان لتنفيذ الهوية والقصة وفق
                                <a href="{{ route('privacy') }}" class="text-indigo-700 underline">سياسة الخصوصية</a>.
                            </span>
                        </label>

                        <button type="submit" disabled data-identity-submit aria-describedby="identity-photo-guidance"
                                class="w-full rounded-2xl bg-gradient-to-l from-indigo-600 to-violet-600 px-6 py-4 text-base font-black text-white shadow-lg shadow-indigo-200 transition hover:-translate-y-0.5 focus:ring-4 focus:ring-indigo-200">
                            <span data-submit-label>اختر صورتين للمتابعة</span>
                        </button>
                        <p class="text-center text-xs font-bold text-slate-400">لن تنتقل إلى نماذج أخرى؛ ستظهر حالة الإنشاء والنتيجة مباشرة.</p>
                    </div>
                </form>
            @endif
        </div>
    </main>
</x-front-layout>
