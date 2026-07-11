<x-admin-layout>
    <x-slot name="header">
        <h2 class="text-xl font-bold text-gray-800">إعدادات الموقع</h2>
    </x-slot>

    @php
        $s = fn($key, $default = '') => $settings[$key] ?? $default;
        $paymentMethods = json_decode((string) $s('payment_methods', '[]'), true);
        $paymentMethodsText = implode("\n", is_array($paymentMethods) ? $paymentMethods : []);
    @endphp

    {{-- Live preview helper --}}
    <style>
    .img-preview { width:80px;height:60px;object-fit:cover;border-radius:8px;border:2px solid #e2e8f0;transition:.3s; }
    .img-preview-lg { width:100%;height:140px;object-fit:cover;border-radius:10px;border:2px solid #e2e8f0; }
    </style>
    <script>
    function previewImg(inputEl, previewId) {
        const url = inputEl.value.trim();
        const img = document.getElementById(previewId);
        if (img) { img.src = url || ''; img.style.display = url ? 'block' : 'none'; }
    }
    </script>

    <form action="{{ route('admin.settings.update') }}" method="POST">
        @csrf @method('PUT')

        <div class="space-y-6">

            {{-- ===== Business Info ===== --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-bold text-gray-900 mb-5 pb-3 border-b flex items-center gap-2">
                    <span class="text-xl">🏢</span> معلومات الموقع
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">اسم الموقع</label>
                        <input type="text" name="settings[site_name]" value="{{ $s('site_name', 'HeroKid') }}"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">البريد الإلكتروني الرئيسي</label>
                        <input type="email" name="settings[site_email]" value="{{ $s('site_email') }}"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">بريد الدعم</label>
                        <input type="email" name="settings[support_email]" value="{{ $s('support_email') }}"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">بريد الخصوصية</label>
                        <input type="email" name="settings[privacy_email]" value="{{ $s('privacy_email') }}"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">المدينة / المحافظة</label>
                        <input type="text" name="settings[address_city]" value="{{ $s('address_city') }}"
                               placeholder="مثال: المنصورة"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">العنوان / الشارع</label>
                        <input type="text" name="settings[address_street]" value="{{ $s('address_street') }}"
                               placeholder="مثال: شارع سامية الجمل"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-bold text-gray-900 mb-5 pb-3 border-b">إعدادات إخراج كتب الاستوديو</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">الموقع على الغلاف الخلفي</label>
                        <input type="text" name="settings[production_layout_website]" value="{{ $s('production_layout_website') }}" class="w-full rounded-lg border-gray-300 text-sm" dir="ltr">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">قالب سطر اسم الطفل على الغلاف</label>
                        <input type="text" name="settings[production_cover_subtitle_template]" value="{{ $s('production_cover_subtitle_template') }}" class="w-full rounded-lg border-gray-300 text-sm">
                        <p class="mt-1 text-xs text-gray-400">استخدم &#123;&#123;child_name&#125;&#125; لمكان اسم الطفل.</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-gray-700 mb-1">النص الافتراضي للغلاف الخلفي</label>
                        <textarea name="settings[production_back_cover_text]" rows="3" class="w-full rounded-lg border-gray-300 text-sm">{{ $s('production_back_cover_text') }}</textarea>
                    </div>
                </div>
            </div>

            {{-- ===== Contact & WhatsApp ===== --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-bold text-gray-900 mb-5 pb-3 border-b flex items-center gap-2">
                    <span class="text-xl">📞</span> التواصل
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">رقم واتساب (بالكود الدولي، بدون +)</label>
                        <input type="text" name="settings[whatsapp_number]" value="{{ $s('whatsapp_number') }}"
                               placeholder="201000000000"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        <p class="text-xs text-gray-400 mt-1">مثال: 201001234567 (مصر)</p>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">رابط واتساب المباشر</label>
                        <input type="url" name="settings[whatsapp_url]" value="{{ $s('whatsapp_url') }}"
                               placeholder="https://wa.me/201..."
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                </div>
            </div>

            {{-- ===== Social Media ===== --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-bold text-gray-900 mb-5 pb-3 border-b flex items-center gap-2">
                    <span class="text-xl">📱</span> وسائل التواصل الاجتماعي
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-5">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Instagram</label>
                        <input type="url" name="settings[instagram_url]" value="{{ $s('instagram_url') }}"
                               placeholder="https://instagram.com/..."
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Facebook</label>
                        <input type="url" name="settings[facebook_url]" value="{{ $s('facebook_url') }}"
                               placeholder="https://facebook.com/..."
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">TikTok</label>
                        <input type="url" name="settings[tiktok_url]" value="{{ $s('tiktok_url') }}"
                               placeholder="https://tiktok.com/..."
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">YouTube</label>
                        <input type="url" name="settings[youtube_url]" value="{{ $s('youtube_url') }}"
                               placeholder="https://youtube.com/..."
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                </div>
            </div>

            {{-- ===== Pricing ===== --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-bold text-gray-900 mb-5 pb-3 border-b flex items-center gap-2">
                    <span class="text-xl">💰</span> الأسعار والتوصيل
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">سعر الغلاف الناعم</label>
                        <input type="number" name="settings[price_soft_cover]" value="{{ $s('price_soft_cover', 299) }}" min="1"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">سعر الغلاف الصلب</label>
                        <input type="number" name="settings[price_hard_cover]" value="{{ $s('price_hard_cover', 399) }}" min="1"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">رمز العملة</label>
                        <input type="text" name="settings[currency_label]" value="{{ $s('currency_label', $s('currency_symbol', 'ج.م')) }}"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">أدنى أيام توصيل</label>
                        <input type="number" name="settings[delivery_days_min]" value="{{ $s('delivery_days_min', 7) }}" min="1"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">أقصى أيام توصيل</label>
                        <input type="number" name="settings[delivery_days_max]" value="{{ $s('delivery_days_max', 10) }}" min="1"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-gray-700 mb-1">نطاق الشحن</label>
                        <input type="text" name="settings[shipping_coverage_text]" value="{{ $s('shipping_coverage_text', 'شحن لجميع محافظات مصر') }}"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-gray-700 mb-1">طرق الدفع المتاحة</label>
                        <textarea name="settings[payment_methods]" rows="4"
                                  class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                  placeholder="طريقة دفع في كل سطر">{{ $paymentMethodsText }}</textarea>
                    </div>
                    <label class="md:col-span-2 flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm font-bold text-gray-700">
                        <input type="checkbox" name="settings[shop_enabled]" value="1" @checked($s('shop_enabled', '1') === '1')
                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span>تفعيل رابط وصفحات المتجر عند وجود منتجات منشورة</span>
                    </label>
                    <div class="md:col-span-2 rounded-lg bg-indigo-50 border border-indigo-100 p-4 text-sm text-indigo-800">
                        يتم تحديد رسوم التوصيل حسب الدولة والمحافظة من صفحة
                        <a href="{{ route('admin.delivery-zones.index') }}" class="font-bold underline">مناطق التوصيل</a>.
                    </div>
                </div>
            </div>

            {{-- ===== Hero Content ===== --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-bold text-gray-900 mb-5 pb-3 border-b flex items-center gap-2">
                    <span class="text-xl">🎯</span> محتوى الصفحة الرئيسية
                </h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">عنوان Hero الرئيسي (السطر الأول)</label>
                        <input type="text" name="settings[hero_title_1]" value="{{ $s('hero_title_1', 'طفلك ليس قارئاً…') }}"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">عنوان Hero المميز (السطر الثاني)</label>
                        <input type="text" name="settings[hero_title_2]" value="{{ $s('hero_title_2', 'هو البطل الحقيقي!') }}"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">الوصف تحت العنوان</label>
                        <textarea name="settings[hero_subtitle]" rows="2"
                                  class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">{{ $s('hero_subtitle', 'نحوّل خيال طفلك إلى كتاب مطبوع يحمل اسمه ووجهه الحقيقي في كل صفحة.') }}</textarea>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">عداد الطلبات</label>
                            <input type="text" name="settings[stat_orders]" value="{{ $s('stat_orders', '+١٠٠٠') }}"
                                   class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">متوسط التقييم</label>
                            <input type="text" name="settings[stat_rating]" value="{{ $s('stat_rating', '٤.٩ ⭐') }}"
                                   class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">متوسط التوصيل</label>
                            <input type="text" name="settings[stat_delivery]" value="{{ $s('stat_delivery', '٧ أيام') }}"
                                   class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">شارة أعلى الصفحة الرئيسية</label>
                            <input type="text" name="settings[home_badge_text]" value="{{ $s('home_badge_text') }}"
                                   class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">وصف الفوتر</label>
                            <input type="text" name="settings[footer_brand_description]" value="{{ $s('footer_brand_description') }}"
                                   class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                        @foreach(['home_feature_face' => 'ميزة الوجه', 'home_feature_values' => 'ميزة القيم', 'home_feature_delivery' => 'ميزة التوصيل', 'home_feature_languages' => 'ميزة اللغات'] as $key => $label)
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1">{{ $label }}</label>
                                <input type="text" name="settings[{{ $key }}]" value="{{ $s($key) }}"
                                       class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- ===== SEO ===== --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-bold text-gray-900 mb-5 pb-3 border-b flex items-center gap-2">
                    <span class="text-xl">🔎</span> عناوين ووصف SEO
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    @foreach([
                        'home' => 'الرئيسية',
                        'stories' => 'القصص',
                        'pricing' => 'الأسعار',
                        'how_it_works' => 'كيف يعمل',
                        'shop' => 'المتجر',
                    ] as $page => $label)
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">عنوان {{ $label }}</label>
                            <input type="text" name="settings[seo_{{ $page }}_title]" value="{{ $s('seo_'.$page.'_title') }}"
                                   class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">وصف {{ $label }}</label>
                            <textarea name="settings[seo_{{ $page }}_description]" rows="2"
                                      class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">{{ $s('seo_'.$page.'_description') }}</textarea>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- ===== How It Works Copy ===== --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-bold text-gray-900 mb-5 pb-3 border-b flex items-center gap-2">
                    <span class="text-xl">🧭</span> خطوات كيف يعمل
                </h3>
                <div class="space-y-6">
                    @for($step = 1; $step <= 5; $step++)
                        <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                            <p class="mb-4 text-sm font-black text-indigo-700">الخطوة {{ $step }}</p>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-1">العنوان</label>
                                    <input type="text" name="settings[hiw_step{{ $step }}_title]" value="{{ $s('hiw_step'.$step.'_title') }}"
                                           class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-1">الوصف</label>
                                    <input type="text" name="settings[hiw_step{{ $step }}_desc]" value="{{ $s('hiw_step'.$step.'_desc') }}"
                                           class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                </div>
                                @for($bullet = 1; $bullet <= 3; $bullet++)
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 mb-1">نقطة {{ $bullet }}</label>
                                        <input type="text" name="settings[hiw_step{{ $step }}_bullet{{ $bullet }}]" value="{{ $s('hiw_step'.$step.'_bullet'.$bullet) }}"
                                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                    </div>
                                @endfor
                            </div>
                        </div>
                    @endfor
                </div>
            </div>

            {{-- ===== Site Images ===== --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-bold text-gray-900 mb-5 pb-3 border-b flex items-center gap-2">
                    <span class="text-xl">🖼️</span> صور الموقع
                    <span class="text-xs font-normal text-gray-400 mr-2">كل الصور الافتراضية محلية من سيرفر الموقع ويمكن تعديل رابطها عند الحاجة</span>
                </h3>

                @php
                $imgDefaults = \App\Support\SiteImages::settingsDefaults();
                $si = fn($key) => $settings[$key] ?? $imgDefaults[$key] ?? '';
                @endphp

                {{-- Hero Section Images --}}
                <div class="mb-6">
                    <p class="text-xs font-extrabold text-indigo-600 uppercase tracking-wider mb-3">📌 الصفحة الرئيسية — قسم Hero</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                        @php
                            $heroImgs = [
                                'img_hero_main'  => ['label'=>'الكتاب الرئيسي (البطاقة الكبيرة)', 'pid'=>'prev_hero_main'],
                                'img_hero_mini1' => ['label'=>'قصة صغيرة ١ (يمين)',               'pid'=>'prev_hero_mini1'],
                                'img_hero_mini2' => ['label'=>'قصة صغيرة ٢ (يسار)',               'pid'=>'prev_hero_mini2'],
                            ];
                        @endphp
                        @foreach($heroImgs as $key => $meta)
                        @php $val = $si($key); @endphp
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">{{ $meta['label'] }}</label>
                            <div class="flex gap-2 items-start">
                                <div class="flex-1">
                                    <input type="text" name="settings[{{ $key }}]"
                                           value="{{ $val }}"
                                           oninput="previewImg(this,'{{ $meta['pid'] }}')"
                                           placeholder="/images/site/settings/example.svg"
                                           class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-xs">
                                </div>
                                <img id="{{ $meta['pid'] }}" src="{{ $val }}" class="img-preview" alt="" {{ $val ? '' : 'style=display:none' }}>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- Homepage How It Works --}}
                <div class="mb-6">
                    <p class="text-xs font-extrabold text-indigo-600 uppercase tracking-wider mb-3">📌 الصفحة الرئيسية — خطوات كيف يعمل</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                        @php
                            $homeSteps = [
                                'img_home_step1' => ['label'=>'خطوة ١ — اختر القصة',    'pid'=>'prev_home_s1'],
                                'img_home_step2' => ['label'=>'خطوة ٢ — خصّص وأرسل',  'pid'=>'prev_home_s2'],
                                'img_home_step3' => ['label'=>'خطوة ٣ — استلم الكتاب', 'pid'=>'prev_home_s3'],
                            ];
                        @endphp
                        @foreach($homeSteps as $key => $meta)
                        @php $val = $si($key); @endphp
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">{{ $meta['label'] }}</label>
                            <div class="flex gap-2 items-start">
                                <div class="flex-1">
                                    <input type="text" name="settings[{{ $key }}]"
                                           value="{{ $val }}"
                                           oninput="previewImg(this,'{{ $meta['pid'] }}')"
                                           placeholder="/images/site/settings/example.svg"
                                           class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-xs">
                                </div>
                                <img id="{{ $meta['pid'] }}" src="{{ $val }}" class="img-preview" alt="" {{ $val ? '' : 'style=display:none' }}>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- Homepage Stats --}}
                <div class="mb-6">
                    <p class="text-xs font-extrabold text-indigo-600 uppercase tracking-wider mb-3">📌 الصفحة الرئيسية — إحصائيات (لماذا HeroKid)</p>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
                        @php
                            $statImgs = [
                                'img_stat_books'    => ['label'=>'📚 عدد القصص',        'pid'=>'prev_stat_books'],
                                'img_stat_rating'   => ['label'=>'⭐ التقييم',           'pid'=>'prev_stat_rating'],
                                'img_stat_family'   => ['label'=>'👨‍👩‍👧 العائلات السعيدة', 'pid'=>'prev_stat_family'],
                                'img_stat_delivery' => ['label'=>'🚀 التوصيل',           'pid'=>'prev_stat_delivery'],
                            ];
                        @endphp
                        @foreach($statImgs as $key => $meta)
                        @php $val = $si($key); @endphp
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">{{ $meta['label'] }}</label>
                            <img id="{{ $meta['pid'] }}" src="{{ $val }}" class="img-preview-lg mb-2" alt="" {{ $val ? '' : 'style=display:none' }}>
                            <input type="text" name="settings[{{ $key }}]"
                                   value="{{ $val }}"
                                   oninput="previewImg(this,'{{ $meta['pid'] }}')"
                                   placeholder="/images/site/settings/example.svg"
                                   class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-xs">
                        </div>
                        @endforeach
                    </div>
                </div>

                {{-- How-It-Works Page Steps --}}
                <div>
                    <p class="text-xs font-extrabold text-indigo-600 uppercase tracking-wider mb-3">📌 صفحة "كيف يعمل" — الخطوات الخمس</p>
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                        @php
                            $hiwSteps = [
                                'img_hiw_step1' => ['label'=>'خطوة ١', 'pid'=>'prev_hiw_s1'],
                                'img_hiw_step2' => ['label'=>'خطوة ٢', 'pid'=>'prev_hiw_s2'],
                                'img_hiw_step3' => ['label'=>'خطوة ٣', 'pid'=>'prev_hiw_s3'],
                                'img_hiw_step4' => ['label'=>'خطوة ٤', 'pid'=>'prev_hiw_s4'],
                                'img_hiw_step5' => ['label'=>'خطوة ٥', 'pid'=>'prev_hiw_s5'],
                            ];
                        @endphp
                        @foreach($hiwSteps as $key => $meta)
                        @php $val = $si($key); @endphp
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">{{ $meta['label'] }}</label>
                            <img id="{{ $meta['pid'] }}" src="{{ $val }}" class="img-preview-lg mb-2" alt="" {{ $val ? '' : 'style=display:none' }}>
                            <input type="text" name="settings[{{ $key }}]"
                                   value="{{ $val }}"
                                   oninput="previewImg(this,'{{ $meta['pid'] }}')"
                                   placeholder="/images/site/settings/example.svg"
                                   class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-xs">
                        </div>
                        @endforeach
                    </div>
                </div>

            </div>

            {{-- ===== Age Ranges ===== --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-bold text-gray-900 mb-1 pb-3 border-b flex items-center gap-2">
                    <span class="text-xl">🎂</span> الفئات العمرية
                </h3>
                <p class="text-xs text-gray-400 mb-5">هذه الخيارات ستظهر في سيليكت الفئة العمرية عند إضافة أو تعديل قصة.</p>

                @php
                    $savedRanges = json_decode($settings['age_ranges'] ?? '[]', true) ?: [
                        '٢ - ٤ سنوات','٢ - ٦ سنوات','٣ - ٥ سنوات','٣ - ٦ سنوات','٣ - ٧ سنوات',
                        '٤ - ٦ سنوات','٤ - ٨ سنوات','٥ - ٧ سنوات','٥ - ٨ سنوات','٥ - ١٠ سنوات',
                        '٦ - ٨ سنوات','٦ - ١٠ سنوات','٧ - ١٠ سنوات','٨ - ١٢ سنوات',
                    ];
                @endphp

                <div id="age-ranges-list" class="space-y-2 mb-4">
                    @foreach($savedRanges as $range)
                    <div class="flex items-center gap-2 age-range-row">
                        <input type="text" name="age_ranges[]" value="{{ $range }}"
                               class="flex-1 rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                               placeholder="مثال: ٣ - ٦ سنوات">
                        <button type="button" onclick="this.closest('.age-range-row').remove()"
                                class="text-red-400 hover:text-red-600 transition p-1.5 rounded-lg hover:bg-red-50" title="حذف">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    @endforeach
                </div>

                <button type="button" onclick="addAgeRange()"
                        class="inline-flex items-center gap-2 text-indigo-600 hover:text-indigo-800 text-sm font-bold border border-indigo-200 hover:border-indigo-400 px-4 py-2 rounded-lg transition hover:bg-indigo-50">
                    + إضافة فئة عمرية
                </button>
            </div>

            {{-- ===== Operations ===== --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-bold text-gray-900 mb-5 pb-3 border-b flex items-center gap-2">
                    <span class="text-xl">⚙️</span> إعدادات التشغيل
                </h3>
                <div class="grid grid-cols-2 gap-5">
                    <div class="flex items-center gap-3 p-4 border rounded-lg">
                        <input type="hidden" name="settings[orders_open]" value="0">
                        <input type="checkbox" name="settings[orders_open]" id="orders_open" value="1"
                               {{ $s('orders_open', '1') == '1' ? 'checked' : '' }}
                               class="h-5 w-5 text-indigo-600 rounded border-gray-300">
                        <div>
                            <label for="orders_open" class="text-sm font-bold text-gray-700">قبول الطلبات الجديدة</label>
                            <p class="text-xs text-gray-400">أوقف هذا إذا كنت في إجازة أو الطاقة ممتلئة</p>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">حذف الصور بعد (أيام)</label>
                        <input type="number" name="settings[photo_delete_days]" value="{{ $s('photo_delete_days', 90) }}" min="30" max="365"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        <p class="text-xs text-gray-400 mt-1">صور الأطفال تُحذف تلقائياً بعد هذا العدد من الأيام</p>
                    </div>
                </div>
            </div>

        </div>

        <div class="mt-6 flex gap-3">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold px-8 py-3 rounded-xl shadow-sm transition">
                💾 حفظ جميع الإعدادات
            </button>
        </div>
    </form>

@push('scripts')
<script>
function addAgeRange() {
    const list = document.getElementById('age-ranges-list');
    const row = document.createElement('div');
    row.className = 'flex items-center gap-2 age-range-row';
    row.innerHTML = `
        <input type="text" name="age_ranges[]" value=""
               class="flex-1 rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm"
               placeholder="مثال: ٣ - ٦ سنوات" autofocus>
        <button type="button" onclick="this.closest('.age-range-row').remove()"
                class="text-red-400 hover:text-red-600 transition p-1.5 rounded-lg hover:bg-red-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>`;
    list.appendChild(row);
    row.querySelector('input').focus();
}
</script>
@endpush
</x-admin-layout>
