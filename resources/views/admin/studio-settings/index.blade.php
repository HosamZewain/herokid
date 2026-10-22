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
            <section class="rounded-2xl border bg-white p-6 space-y-4" aria-labelledby="studio-active-sessions">
                <h2 id="studio-active-sessions" class="text-lg font-bold">الجلسات النشطة</h2>
                <p class="text-sm text-slate-600">جلسات دخول الموظفين إلى Studio، بأحدثها أولًا (حتى ١٠٠ جلسة). إلغاء الجلسة يطلب من الموظف تسجيل الدخول مجددًا، ولا يغيّر صلاحياته أو إعدادات الفريق.</p>
                @forelse($sessions as $session)
                    <form method="POST" action="{{ route('admin.studio-settings.sessions.destroy', $session) }}" class="flex flex-wrap items-center gap-3 border-b p-3">@csrf @method('DELETE')
                        <div class="min-w-0 flex-1">
                            <p class="font-bold">{{ $employees->firstWhere('id', $session->user_id)?->name ?? ('الموظف #'.$session->user_id) }}</p>
                            <p class="text-sm text-slate-600">جلسة #{{ $session->id }} · بدأت {{ $session->created_at?->timezone('Africa/Cairo')->format('Y-m-d H:i') }} · تنتهي {{ $session->expires_at->timezone('Africa/Cairo')->format('Y-m-d H:i') }} (توقيت القاهرة)</p>
                        </div>
                        <button class="rounded-lg border border-red-200 px-4 py-2 text-red-700" aria-label="إلغاء الجلسة رقم {{ $session->id }}">إلغاء الجلسة</button>
                    </form>
                @empty<p class="text-slate-500">لا توجد جلسات نشطة.</p>@endforelse
            </section>
        @endcan
    </div>
</x-admin-layout>
