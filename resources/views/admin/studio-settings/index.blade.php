<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-black">إعدادات HeroKid Studio المركزية</h1></x-slot>
    <div class="mx-auto max-w-6xl space-y-6 p-6" dir="rtl">
        @if($errors->any())<div class="rounded-xl bg-red-50 p-4 text-red-800" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <section class="rounded-2xl border bg-white p-6 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-xl font-bold">إعداد واحد لكل أجهزة الفريق</h2><span class="rounded-full bg-indigo-50 px-4 py-2 text-sm text-indigo-700">النسخة المنشورة: {{ $revision?->id ?? 'لم تُنشر بعد' }}</span></div>
            <p class="text-slate-600">١. عدّل الإعدادات والصور هنا. ٢. انشر التغييرات. ٣. الموظف يسجّل الدخول في Studio لتحميلها، أو يضغط «تحديث الإعدادات» إذا كان التطبيق مفتوحًا.</p>
            <p class="text-sm text-slate-500">لا تتغير ملفات المشاريع أو إعدادات الأوردرات الحالية. مجلد الحفظ وبيئة الاتصال يظلان على جهاز الموظف.</p>
        </section>
        <form method="POST" action="{{ route('admin.studio-settings.publish') }}" class="space-y-6" data-studio-settings-editor data-upload-url="{{ route('admin.studio-settings.assets.store') }}">@csrf
            <input type="hidden" name="base_revision" value="{{ old('base_revision', $revision?->id ?? 0) }}">
            <input type="hidden" name="settings_json" value="{{ old('settings_json', json_encode($configuration, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}">
            <script type="application/json" data-studio-editor-options>@json($editorOptions)</script>
            <div data-studio-navigation class="flex flex-wrap gap-2" role="tablist" aria-label="أقسام إعدادات Studio"></div>
            <div data-studio-fields class="space-y-6"><p role="status">جارٍ تحميل نموذج الإعدادات…</p></div>
            <div data-studio-provider-help class="rounded-xl bg-indigo-50 p-4 text-sm text-indigo-900" hidden>
                <p>مفتاح OpenAI يُضبط على الخادم، ولا يحتاج الموظف لإدخاله في التطبيق. النشر لا يشغّل أي توليد مدفوع؛ التوليد يبدأ بطلب صريح من موظف مصرح له.</p>
                @can('settings.ai_providers.view')<a class="mt-2 inline-block underline" href="{{ route('admin.settings.ai-providers.index') }}">إدارة مزوّد التوليد والمفتاح والموديلات</a>@endcan
            </div>
            <noscript><p>فعّل JavaScript لتعديل الإعدادات بأمان.</p></noscript>
            <section class="rounded-2xl border bg-white p-6 space-y-4">
                <p class="text-sm text-slate-600">رفع صورة وحده لا ينشرها. راجع الإعدادات ثم انشر نسخة كاملة. لا ترفع هنا صور الأطفال أو ملفات الأوردرات.</p>
                <h2 class="text-lg font-bold">حفظ التغييرات ونشرها</h2>
                <label class="block">ملخص التغيير<input class="mt-1 w-full rounded-lg border-slate-300" name="reason" maxlength="500" value="{{ old('reason') }}" placeholder="مثال: تحديث اللوجو والغلاف الخلفي" required></label>
                <label class="block"><input type="checkbox" name="confirmed" value="1" required> أؤكد نشر هذه النسخة على موظفي Studio بعد مراجعتها.</label>
                <p role="status" data-studio-editor-status class="text-sm text-indigo-700">لم تُجرَ تغييرات بعد.</p>
                <button disabled data-studio-publish class="rounded-xl bg-indigo-600 px-6 py-3 font-bold text-white disabled:opacity-50">نشر الإعدادات للفريق</button>
            </section>
        </form>
        <details class="rounded-2xl border bg-white p-6 space-y-4"><summary class="cursor-pointer text-lg font-bold">سجل التغييرات والرجوع لنسخة سابقة</summary>
            @foreach($history as $entry)
                <details class="rounded-xl border p-4"><summary class="cursor-pointer">نسخة #{{ $entry->id }} · {{ $entry->created_at }} · {{ $employees->firstWhere('id', $entry->published_by)?->name ?? ('الموظف #'.$entry->published_by) }} · {{ $entry->reason }}</summary>
                    <form method="POST" action="{{ route('admin.studio-settings.rollback', $entry) }}" class="mt-4 flex flex-wrap items-center gap-3">@csrf
                        <input type="hidden" name="base_revision" value="{{ $revision?->id ?? 0 }}">
                        <input class="rounded-lg border-slate-300" name="reason" placeholder="سبب الرجوع" required maxlength="400" aria-label="سبب الرجوع">
                        <label><input type="checkbox" name="confirmed" value="1" required> تأكيد الرجوع</label><button class="rounded-lg border px-4 py-2">نشر نسخة جديدة من هذه النسخة</button>
                    </form>
                </details>
            @endforeach
            @if($history->isEmpty())<p class="text-sm text-slate-500">سيظهر هنا سجل التغييرات بعد أول نشر.</p>@endif
        </details>
        @can('studio.sessions.manage')
            <details class="rounded-2xl border bg-white p-6 space-y-4"><summary class="cursor-pointer text-lg font-bold">وصول الموظفين والأجهزة — للإدارة فقط</summary>
                <p class="text-sm text-slate-600">هذا القسم ليس إعدادًا للتصميم. يحدد صلاحيات الإنتاج التي يستخدمها كل موظف داخل Studio. اختر ربطًا محفوظًا باسم الموظف بدل كتابة أرقام. لن تُضاف صلاحيات جديدة ولن يُرسل توكن Agent إلى التطبيق.</p>
                <p class="text-sm text-slate-600">دخول Studio وقراءة الإعدادات يحتاجان صلاحياتهما من إدارة الموظفين. اختيار «بدون وصول للإنتاج» لا يلغي هاتين الصلاحيتين. تغيير الربط يلغي جلسات الموظف الحالية ويطلب منه تسجيل الدخول مجددًا.</p>
                @can('agent_api.tokens.manage')<a class="inline-block text-indigo-700 underline" href="{{ route('admin.agent-api-tokens.index') }}">إدارة صلاحيات وتوكنات Agent الحالية</a>@endcan
                @foreach($employees as $employee)
                    @php($employeePolicies = collect($policies[$employee->id] ?? []))
                    @php($linkedId = $grants[$employee->id]->agent_token_id ?? null)
                    <form method="POST" action="{{ route('admin.studio-settings.employees.update', $employee) }}" class="space-y-3 rounded-xl border p-4">@csrf @method('PUT')
                        <h3 class="font-bold">{{ $employee->name }}</h3>
                        @if($linkedId && !$employeePolicies->contains('id', $linkedId))<p class="text-sm text-amber-800">الربط السابق غير متاح أو انتهت صلاحيته. اختر ربطًا صالحًا أو أوقف وصول الإنتاج.</p>@endif
                        <label class="block text-sm">صلاحيات الإنتاج في Studio
                            <select class="mt-1 w-full rounded-lg border-slate-300" name="agent_token_id" aria-label="صلاحيات إنتاج {{ $employee->name }}">
                                <option value="">بدون وصول للإنتاج — الإعدادات فقط حسب صلاحيات الموظف</option>
                                @foreach($employeePolicies as $policy)<option value="{{ $policy['id'] }}" @selected((int) $linkedId === (int) $policy['id'])>{{ $policy['name'] }} · {{ $policy['scope'] }} · {{ $policy['operations'] }}{{ $policy['expires'] ? ' · ينتهي '.$policy['expires'] : '' }}</option>@endforeach
                            </select>
                        </label>
                        @if($employeePolicies->isEmpty())<p class="text-sm text-slate-500">لا توجد صلاحيات Agent صالحة ومفعّلة لهذا الموظف. مسؤول النظام يراجع إعداداتها؛ لا تستخدم توكن موظف آخر.</p>@endif
                        <div class="flex flex-wrap items-center gap-3"><label class="text-sm"><input type="checkbox" name="confirmed" value="1" required> أؤكد التغيير وإعادة تسجيل دخول الموظف</label><button class="rounded-lg border px-4 py-2 text-indigo-700">حفظ وصول الموظف</button></div>
                    </form>
                @endforeach
                <h3 class="font-bold">الجلسات النشطة</h3>
                @forelse($sessions as $session)
                    <form method="POST" action="{{ route('admin.studio-settings.sessions.destroy', $session) }}" class="flex flex-wrap items-center gap-3 border-b p-3">@csrf @method('DELETE')
                        <span>جلسة #{{ $session->id }} · {{ $employees->firstWhere('id', $session->user_id)?->name ?? ('الموظف #'.$session->user_id) }} · تنتهي {{ $session->expires_at }}</span><button class="rounded-lg border border-red-200 px-4 py-2 text-red-700">إلغاء الجلسة</button>
                    </form>
                @empty<p class="text-slate-500">لا توجد جلسات نشطة.</p>@endforelse
            </details>
        @endcan
    </div>
</x-admin-layout>
