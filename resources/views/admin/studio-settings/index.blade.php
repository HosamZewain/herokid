<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-black">إعدادات HeroKid Studio المركزية</h1></x-slot>
    <div class="mx-auto max-w-6xl space-y-6 p-6" dir="rtl">
        @if($errors->any())<div class="rounded-xl bg-red-50 p-4 text-red-800" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <section class="rounded-2xl border bg-white p-6 space-y-3">
            <h2 class="text-lg font-bold">النسخة المنشورة: {{ $revision?->id ?? 'لم تُنشر بعد' }}</h2>
            <p class="text-sm text-slate-600">تُنشر الإعدادات والأصول معًا. جلسة الموظف ساعتان بحد أقصى؛ لا تنقل توكن Agent أو مفتاح OpenAI إلى التطبيق. النشر لا يحذف الإعدادات المحلية ولا يغيّر أي طلب.</p>
            @can('settings.ai_providers.view')
                <a class="text-indigo-700 underline" href="{{ route('admin.settings.ai-providers.index') }}">إعداد مفتاح OpenAI المشفّر والموديلات من مزودي الذكاء الاصطناعي</a>
            @endcan
            <p class="text-sm text-amber-800">التوليد المدفوع معطّل افتراضيًا. اختر موديلًا مفعّلًا يدعم cover_generation، وامنح الموظف studio.covers.generate صراحةً. لا تضع مفاتيح أو مسارات جهاز داخل الإعدادات.</p>
        </section>
        <section class="rounded-2xl border bg-white p-6 space-y-4">
            <h2 class="text-lg font-bold">رفع اللوجو أو الغلاف الخلفي أو قالب منتج</h2>
            <p class="text-sm text-slate-600">PNG / JPEG / WebP حتى 15 ميجا، بحد أقصى 6000 بكسل لكل ضلع و25 مليون بكسل. التخزين خاص؛ الرفع وحده لا ينشر الأصل.</p>
            <form method="POST" action="{{ route('admin.studio-settings.assets.store') }}" enctype="multipart/form-data" class="flex flex-wrap gap-3">@csrf
                <input type="file" name="file" accept="image/png,image/jpeg,image/webp" required aria-label="ملف الأصل">
                <button class="rounded-xl bg-indigo-600 px-5 py-2 text-white">رفع أصل محمي</button>
            </form>
            <details><summary class="cursor-pointer font-bold">الأصول المرفوعة ومعرّفاتها ({{ $assets->count() }})</summary>
                <div class="max-h-72 overflow-auto space-y-3 pt-3">@foreach($assets as $asset)
                    <div class="flex items-center gap-3 rounded border p-2">
                        <img src="{{ route('admin.studio-settings.assets.show', $asset->id) }}" alt="أصل Studio" loading="lazy" class="h-16 w-16 object-contain">
                        <div class="break-all"><code dir="ltr">{{ $asset->id }}</code><p class="text-xs text-slate-500">{{ $asset->width }} × {{ $asset->height }} · {{ number_format($asset->bytes / 1024) }} KB</p></div>
                    </div>
                @endforeach</div>
            </details>
        </section>
        <form method="POST" action="{{ route('admin.studio-settings.publish') }}" class="rounded-2xl border bg-white p-6 space-y-6">@csrf
            <input type="hidden" name="base_revision" value="{{ $revision?->id ?? 0 }}">
            <h2 class="text-lg font-bold">إعدادات النسخة الجديدة</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <label>معرّف الخط<input class="mt-1 w-full rounded-lg border-slate-300" name="font_id" value="{{ old('font_id', $configuration['production_typography']['default_font_id']) }}" required></label>
                <label>وزن الخط<select class="mt-1 w-full rounded-lg border-slate-300" name="font_weight">@foreach([400,500,600,700,800] as $weight)<option @selected(old('font_weight', $configuration['production_typography']['default_font_weight']) == $weight)>{{ $weight }}</option>@endforeach</select></label>
                <label>جانب النص<select class="mt-1 w-full rounded-lg border-slate-300" name="page_side">@foreach(['right'=>'يمين','left'=>'يسار'] as $value=>$label)<option value="{{ $value }}" @selected(old('page_side', $configuration['production_typography']['default_text_box_page_side']) === $value)>{{ $label }}</option>@endforeach</select></label>
                <label>ترتيب التصدير<select class="mt-1 w-full rounded-lg border-slate-300" name="export_mode">@foreach(['reading-order'=>'ترتيب القراءة','booklet'=>'ملزمة'] as $value=>$label)<option value="{{ $value }}" @selected(old('export_mode', $configuration['export_preferences']['default_mode']) === $value)>{{ $label }}</option>@endforeach</select></label>
                @foreach(['default_back_cover'=>'الغلاف الخلفي', 'brand_logo'=>'اللوجو الرسمي'] as $key=>$label)
                    <label>{{ $label }}<select class="mt-1 w-full rounded-lg border-slate-300" name="{{ $key }}_asset_id"><option value="">بدون أصل</option>@foreach($assets as $asset)<option value="{{ $asset->id }}" @selected(old($key.'_asset_id', $configuration[$key]['asset_id'] ?? '') === $asset->id)>{{ $asset->id }} ({{ $asset->width }}×{{ $asset->height }})</option>@endforeach</select></label>
                @endforeach
            </div>
            <div class="flex flex-wrap gap-5">
                <input type="hidden" name="allow_warnings" value="0">
                <label><input type="checkbox" name="allow_warnings" value="1" @checked(old('allow_warnings', $configuration['export_preferences']['allow_warnings']))> السماح بتحذيرات التصدير</label>
                @foreach(['show_safe_area'=>'المنطقة الآمنة','show_bleed'=>'النزيف','show_gutter'=>'هامش التجليد'] as $key=>$label)<input type="hidden" name="{{ $key }}" value="0"><label><input type="checkbox" name="{{ $key }}" value="1" @checked(old($key, $configuration['ui_preferences'][$key]))> {{ $label }}</label>@endforeach
            </div>
            <fieldset class="space-y-4 rounded-xl border p-4"><legend class="font-bold">توليد الأغلفة عبر الخادم</legend>
                <input type="hidden" name="cover_enabled" value="0"><input type="hidden" name="use_identity_image" value="0">
                <label><input type="checkbox" name="cover_enabled" value="1" @checked(old('cover_enabled', $configuration['openai_cover']['enabled']))> تفعيل التوليد المصرّح به</label>
                <label class="mr-4"><input type="checkbox" name="use_identity_image" value="1" @checked(old('use_identity_image', $configuration['openai_cover']['use_identity_image']))> استخدام صورة الهوية المرجعية</label>
                <div class="grid gap-4 md:grid-cols-3">@foreach(['cover_model'=>['model','الموديل'], 'cover_quality'=>['quality','الجودة'], 'cover_size'=>['size','الأبعاد']] as $name=>[$key,$label])<label>{{ $label }}<input class="mt-1 w-full rounded-lg border-slate-300" name="{{ $name }}" value="{{ old($name, $configuration['openai_cover'][$key]) }}" required dir="ltr"></label>@endforeach</div>
                @foreach(['cover_prompt'=>['prompt_template','تعليمات غلاف القصة'], 'coloring_prompt'=>['coloring_book_prompt_template','تعليمات غلاف التلوين'], 'cover_notes'=>['cover_style_notes','ملاحظات الأسلوب']] as $name=>[$key,$label])<label class="block">{{ $label }}<textarea class="mt-1 w-full rounded-lg border-slate-300" rows="4" name="{{ $name }}" dir="auto">{{ old($name, $configuration['openai_cover'][$key]) }}</textarea></label>@endforeach
            </fieldset>
            <details><summary class="cursor-pointer font-bold">الإعدادات المتقدمة: قص الغلاف، موضع اللوجو، أنماط النص وقوالب المنتجات</summary>
                <p class="my-3 text-sm text-slate-600">صيغة JSON كاملة. حافظ على معرّفات القوالب الحالية؛ استبدل مسار الصورة بـ {"asset_id":"معرّف الأصل"}. لا تُنسخ بيانات مشاريع الأطفال إلى هنا. الحقول المبسطة أعلاه لها الأولوية.</p>
                <textarea class="w-full rounded-lg border-slate-300 font-mono text-sm" rows="24" name="settings_json" dir="ltr">{{ old('settings_json', json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}</textarea>
            </details>
            <label class="block">سبب التغيير<input class="mt-1 w-full rounded-lg border-slate-300" name="reason" maxlength="500" value="{{ old('reason') }}" required></label>
            <label class="block"><input type="checkbox" name="confirmed" value="1" required> أؤكد نشر هذه النسخة على موظفي Studio بعد مراجعتها.</label>
            <button class="rounded-xl bg-indigo-600 px-6 py-3 font-bold text-white">نشر نسخة كاملة</button>
        </form>
        <section class="rounded-2xl border bg-white p-6 space-y-4"><h2 class="text-lg font-bold">سجل النسخ والرجوع لنسخة سابقة</h2>
            @foreach($history as $entry)
                <details class="rounded-xl border p-4"><summary class="cursor-pointer">نسخة #{{ $entry->id }} · {{ $entry->created_at }} · {{ $employees->firstWhere('id', $entry->published_by)?->name ?? ('الموظف #'.$entry->published_by) }} · {{ $entry->reason }}</summary>
                    <form method="POST" action="{{ route('admin.studio-settings.rollback', $entry) }}" class="mt-4 flex flex-wrap items-center gap-3">@csrf
                        <input type="hidden" name="base_revision" value="{{ $revision?->id ?? 0 }}">
                        <input class="rounded-lg border-slate-300" name="reason" placeholder="سبب الرجوع" required maxlength="400" aria-label="سبب الرجوع">
                        <label><input type="checkbox" name="confirmed" value="1" required> تأكيد الرجوع</label>
                        <button class="rounded-lg border px-4 py-2">نشر نسخة جديدة من هذه النسخة</button>
                    </form>
                </details>
            @endforeach
        </section>
        @can('studio.sessions.manage')
            <section class="rounded-2xl border bg-white p-6 space-y-4"><h2 class="text-lg font-bold">نطاق إنتاج الموظف</h2>
                <p class="text-sm text-slate-600">من إدارة المشرفين امنح studio.access وstudio.settings.read. للإنتاج اربط رقم توكن Agent قائم يخص الموظف نفسه؛ لا يُرسل سره للتطبيق، ولا يُنشئ الربط أي صلاحية جديدة. اترك الرقم فارغًا لدخول الإعدادات فقط. أي تغيير يلغي الجلسات السابقة.</p>
                @foreach($employees as $employee)
                    <form method="POST" action="{{ route('admin.studio-settings.employees.update', $employee) }}" class="flex flex-wrap items-center gap-3 rounded border p-3">@csrf @method('PUT')
                        <span class="min-w-40">{{ $employee->name }}</span><input class="rounded-lg border-slate-300" name="agent_token_id" type="number" min="1" placeholder="رقم توكن السياسة — وليس السر" value="{{ $grants[$employee->id]->agent_token_id ?? '' }}" aria-label="رقم توكن سياسة {{ $employee->name }}">
                        <label><input type="checkbox" name="confirmed" value="1" required> تأكيد</label><button class="rounded-lg border px-4 py-2">تحديث الربط</button>
                    </form>
                @endforeach
                <h3 class="font-bold">الجلسات النشطة</h3>
                @forelse($sessions as $session)
                    <form method="POST" action="{{ route('admin.studio-settings.sessions.destroy', $session) }}" class="flex flex-wrap items-center gap-3 border-b p-3">@csrf @method('DELETE')
                        <span>جلسة #{{ $session->id }} · {{ $employees->firstWhere('id', $session->user_id)?->name ?? ('الموظف #'.$session->user_id) }} · تنتهي {{ $session->expires_at }}</span><button class="rounded-lg border border-red-200 px-4 py-2 text-red-700">إلغاء الجلسة</button>
                    </form>
                @empty<p class="text-slate-500">لا توجد جلسات نشطة.</p>@endforelse
            </section>
        @endcan
    </div>
</x-admin-layout>
