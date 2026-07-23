<x-admin-layout>
    <x-slot name="header"><h2 class="text-xl font-black text-slate-900">إعدادات هويات الأطفال</h2></x-slot>

    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-4xl px-4 sm:px-6">
            @if(session('success'))<div class="mb-5 rounded-xl bg-emerald-50 p-4 font-bold text-emerald-800">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="mb-5 rounded-xl bg-red-50 p-4 font-bold text-red-700">{{ $errors->first() }}</div>@endif

            <form method="POST" action="{{ route('admin.child-identities.settings.update') }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                @csrf @method('PUT')
                <label class="flex items-center gap-3 rounded-xl bg-indigo-50 p-4 font-black text-indigo-900">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $values['enabled'])) class="rounded border-indigo-300 text-indigo-600">
                    تفعيل خدمة هويات الأطفال العامة
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-bold text-slate-700">حجم الصورة
                        <select name="image_size" class="mt-2 w-full rounded-xl border-slate-300">
                            @foreach(['1536x1024', '1024x1536', '1024x1024'] as $size)
                                <option value="{{ $size }}" @selected(old('image_size', $values['size']) === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-bold text-slate-700">الجودة
                        <select name="image_quality" class="mt-2 w-full rounded-xl border-slate-300">
                            @foreach(['low', 'medium', 'high'] as $quality)
                                <option value="{{ $quality }}" @selected(old('image_quality', $values['quality']) === $quality)>{{ $quality }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="rounded-2xl border border-indigo-100 bg-indigo-50/40 p-5 sm:col-span-2">
                        <div class="mb-4">
                            <h3 class="font-black text-indigo-950">نصوص شاشة انتظار إنشاء الهوية</h3>
                            <p class="mt-1 text-xs leading-6 text-indigo-700">
                                يمكنك تغيير جميع العناوين الموضحة للعميل أثناء المعالجة. استخدم
                                <code dir="ltr">:child</code> لاسم الطفل و<code dir="ltr">:count</code> لعدد الصور.
                            </p>
                        </div>
                        @php
                            $processingFields = [
                                'heading' => 'العنوان الرئيسي',
                                'description' => 'الوصف الرئيسي',
                                'received_title' => 'عنوان استلام البيانات والصور',
                                'received_description' => 'وصف استلام الصور',
                                'queued_title' => 'عنوان تجهيز الطلب',
                                'queued_waiting_description' => 'وصف الطلب في قائمة الانتظار',
                                'queued_completed_description' => 'وصف اكتمال تجهيز الطلب',
                                'generating_title' => 'عنوان إنشاء الهوية',
                                'generating_active_description' => 'وصف إنشاء الهوية الجاري',
                                'generating_waiting_description' => 'وصف انتظار بدء الإنشاء',
                                'result_title' => 'عنوان عرض النتيجة',
                                'result_description' => 'وصف ظهور النتيجة',
                            ];
                        @endphp
                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach($processingFields as $key => $label)
                                <label class="text-sm font-bold text-slate-700 {{ in_array($key, ['description'], true) ? 'sm:col-span-2' : '' }}">
                                    {{ $label }}
                                    <input name="processing_copy[{{ $key }}]"
                                           value="{{ old("processing_copy.{$key}", $values['processing_copy'][$key]) }}"
                                           maxlength="500"
                                           class="mt-2 w-full rounded-xl border-slate-300">
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <label class="text-sm font-bold text-slate-700 sm:col-span-2">نسخة البرومبت
                        <input name="prompt_version" value="{{ old('prompt_version', $values['version']) }}" class="mt-2 w-full rounded-xl border-slate-300" dir="ltr">
                    </label>
                    <label class="text-sm font-bold text-slate-700 sm:col-span-2">قالب character sheet
                        <textarea name="prompt_template" rows="12" class="mt-2 w-full rounded-xl border-slate-300 font-mono text-sm" dir="ltr">{{ old('prompt_template', $values['prompt']) }}</textarea>
                    </label>
                </div>
                <section class="rounded-2xl border border-violet-200 bg-violet-50/40 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-black text-violet-950">المشاركة والإحالات</h3>
                            <p class="mt-1 text-xs leading-6 text-violet-700">تؤثر التغييرات على المشاركات الجديدة أو التي يعاد تجهيزها فقط؛ اللقطات التاريخية لا تتغير.</p>
                        </div>
                        <label class="flex items-center gap-2 rounded-xl bg-white px-4 py-2 text-sm font-black text-violet-900">
                            <input type="checkbox" name="sharing_enabled" value="1" @checked(old('sharing_enabled', $values['sharing']['enabled'])) class="rounded border-violet-300 text-violet-600">
                            تفعيل المشاركة
                        </label>
                    </div>

                    @php
                        $channelLabels = [
                            'native' => 'المشاركة الأصلية',
                            'whatsapp' => 'واتساب',
                            'facebook' => 'فيسبوك',
                            'instagram' => 'إنستجرام',
                            'copy_link' => 'نسخ الرابط',
                            'copy_caption' => 'نسخ النص',
                            'download' => 'حفظ الصورة',
                        ];
                    @endphp
                    <div class="mt-5 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach($channelLabels as $channel => $label)
                            <label class="flex items-center gap-2 rounded-xl border border-violet-100 bg-white p-3 text-sm font-bold">
                                <input type="checkbox" name="share_channels[]" value="{{ $channel }}"
                                       @checked(in_array($channel, old('share_channels', collect($values['sharing']['channels'])->filter()->keys()->all()), true))
                                       class="rounded border-violet-300 text-violet-600">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <label class="text-sm font-bold text-slate-700 sm:col-span-2">قالب النص العربي
                            <textarea name="share_caption_ar" rows="7" class="mt-2 w-full rounded-xl border-slate-300 text-sm" dir="rtl">{{ old('share_caption_ar', $values['sharing']['caption_ar']) }}</textarea>
                            <span class="mt-1 block text-xs text-slate-500" dir="ltr">{share_url} · {child_first_name} · {brand_name}</span>
                        </label>
                        <label class="text-sm font-bold text-slate-700 sm:col-span-2">قالب النص الإنجليزي (اختياري)
                            <textarea name="share_caption_en" rows="4" class="mt-2 w-full rounded-xl border-slate-300 text-sm" dir="ltr">{{ old('share_caption_en', $values['sharing']['caption_en']) }}</textarea>
                        </label>
                        <label class="text-sm font-bold text-slate-700 sm:col-span-2">الهاشتاجات
                            <textarea name="share_hashtags" rows="4" class="mt-2 w-full rounded-xl border-slate-300 text-sm">{{ old('share_hashtags', $values['sharing']['hashtags']) }}</textarea>
                        </label>
                        <label class="text-sm font-bold text-slate-700">عنوان بطاقة المشاركة
                            <input name="share_card_headline" value="{{ old('share_card_headline', $values['sharing']['card_headline']) }}" class="mt-2 w-full rounded-xl border-slate-300">
                        </label>
                        <label class="text-sm font-bold text-slate-700">دعوة بطاقة المشاركة
                            <input name="share_card_cta" value="{{ old('share_card_cta', $values['sharing']['card_cta']) }}" class="mt-2 w-full rounded-xl border-slate-300">
                        </label>
                        <label class="text-sm font-bold text-slate-700">عنوان الصفحة العامة
                            <input name="share_landing_title" value="{{ old('share_landing_title', $values['sharing']['landing_title']) }}" class="mt-2 w-full rounded-xl border-slate-300">
                        </label>
                        <label class="text-sm font-bold text-slate-700">زر الصفحة العامة
                            <input name="share_landing_cta" value="{{ old('share_landing_cta', $values['sharing']['landing_cta']) }}" class="mt-2 w-full rounded-xl border-slate-300">
                        </label>
                        <label class="text-sm font-bold text-slate-700 sm:col-span-2">وصف الصفحة العامة
                            <textarea name="share_landing_description" rows="3" class="mt-2 w-full rounded-xl border-slate-300">{{ old('share_landing_description', $values['sharing']['landing_description']) }}</textarea>
                        </label>
                        <label class="text-sm font-bold text-slate-700">مدة الإحالة بالأيام
                            <input type="number" min="1" max="365" name="share_attribution_days" value="{{ old('share_attribution_days', $values['sharing']['attribution_days']) }}" class="mt-2 w-full rounded-xl border-slate-300">
                        </label>
                        <label class="text-sm font-bold text-slate-700">نسخة القالب
                            <input name="share_template_version" value="{{ old('share_template_version', $values['sharing']['template_version']) }}" class="mt-2 w-full rounded-xl border-slate-300" dir="ltr">
                        </label>
                        <label class="text-sm font-bold text-slate-700">جودة Feed JPEG
                            <input type="number" min="70" max="96" name="share_feed_quality" value="{{ old('share_feed_quality', $values['sharing']['feed_quality']) }}" class="mt-2 w-full rounded-xl border-slate-300">
                        </label>
                        <label class="text-sm font-bold text-slate-700">جودة Story JPEG
                            <input type="number" min="70" max="96" name="share_story_quality" value="{{ old('share_story_quality', $values['sharing']['story_quality']) }}" class="mt-2 w-full rounded-xl border-slate-300">
                        </label>
                        <label class="flex items-center gap-3 rounded-xl bg-white p-4 text-sm font-bold">
                            <input type="checkbox" name="share_allow_first_name" value="1" @checked(old('share_allow_first_name', $values['sharing']['allow_first_name'])) class="rounded border-violet-300 text-violet-600">
                            السماح بعرض الاسم الأول
                        </label>
                    </div>
                </section>
                <div class="rounded-xl bg-slate-50 p-4 text-sm leading-7 text-slate-600">
                    النموذج يُحل من إعداد OpenAI الافتراضي لقدرة <code>character_sheet</code> مع fallback إلى <code>gpt-image-2</code>.
                    حد العميل ثابت: {{ arabic_number($values['limit']) }} نتائج ناجحة. التكلفة الداخلية بالدولار فقط والخدمة مجانية للعميل.
                </div>
                <button class="rounded-xl bg-indigo-600 px-6 py-3 font-black text-white">حفظ الإعدادات</button>
            </form>
        </div>
    </div>
</x-admin-layout>
