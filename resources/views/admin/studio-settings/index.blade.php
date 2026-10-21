<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-black">إعدادات HeroKid Studio المركزية</h1></x-slot>
    <div class="mx-auto max-w-6xl space-y-6 p-6" dir="rtl">
        @if($errors->any())<div class="rounded-xl bg-red-50 p-4 text-red-800" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <section class="rounded-2xl border bg-white p-6 space-y-3">
            <h2 class="text-lg font-bold">النسخة المنشورة: {{ $revision?->id ?? 'لم تُنشر بعد' }}</h2>
            <p>اضبط الإعدادات هنا مرة واحدة للفريق. بعد دخول الموظف، يحمّل Studio نسخة كاملة مع صورها؛ وبعد تغييرها هنا يضغط «تحديث الإعدادات» داخل التطبيق.</p>
            <p class="text-sm text-slate-600">هذه إعدادات مشتركة فقط. مسار الحفظ وملفات المشاريع وتعديلات كل أوردر تظل محلية ولا يغيّرها النشر. ربط التطبيق يتولاه تحديث Studio المنفصل.</p>
            @can('settings.ai_providers.view')<a class="text-indigo-700 underline" href="{{ route('admin.settings.ai-providers.index') }}">إدارة مفتاح OpenAI المشفّر والموديلات المتاحة</a>@endcan
            <p class="text-sm text-amber-800">المفتاح محفوظ على الخادم ولا يصل للتطبيق. تفعيل الأغلفة يتطلب موديلًا معتمدًا ومفتاحًا وصلاحية توليد للموظف؛ لا يحدث توليد مدفوع بمجرد نشر الإعدادات.</p>
        </section>
        <form method="POST" action="{{ route('admin.studio-settings.publish') }}" class="space-y-6" data-studio-settings-editor data-upload-url="{{ route('admin.studio-settings.assets.store') }}">@csrf
            <input type="hidden" name="base_revision" value="{{ $revision?->id ?? 0 }}">
            <input type="hidden" name="settings_json" value="{{ old('settings_json', json_encode($configuration, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}">
            <script type="application/json" data-studio-editor-options>@json($editorOptions)</script>
            <div data-studio-fields class="space-y-6"><p role="status">جارٍ تحميل نموذج الإعدادات…</p></div>
            <noscript><p>فعّل JavaScript لتعديل الإعدادات بأمان.</p></noscript>
            <section class="rounded-2xl border bg-white p-6 space-y-4">
                <p class="text-sm text-slate-600">رفع صورة وحده لا ينشرها. راجع الإعدادات ثم انشر نسخة كاملة. لا ترفع هنا صور الأطفال أو ملفات الأوردرات.</p>
                <label class="block">سبب التغيير<input class="mt-1 w-full rounded-lg border-slate-300" name="reason" maxlength="500" value="{{ old('reason') }}" required></label>
                <label class="block"><input type="checkbox" name="confirmed" value="1" required> أؤكد نشر هذه النسخة على موظفي Studio بعد مراجعتها.</label>
                <p role="status" data-studio-editor-status></p>
                <button disabled data-studio-publish class="rounded-xl bg-indigo-600 px-6 py-3 font-bold text-white disabled:opacity-50">نشر الإعدادات للفريق</button>
            </section>
        </form>
        <section class="rounded-2xl border bg-white p-6 space-y-4"><h2 class="text-lg font-bold">سجل النسخ والرجوع لنسخة سابقة</h2>
            @foreach($history as $entry)
                <details class="rounded-xl border p-4"><summary class="cursor-pointer">نسخة #{{ $entry->id }} · {{ $entry->created_at }} · {{ $employees->firstWhere('id', $entry->published_by)?->name ?? ('الموظف #'.$entry->published_by) }} · {{ $entry->reason }}</summary>
                    <form method="POST" action="{{ route('admin.studio-settings.rollback', $entry) }}" class="mt-4 flex flex-wrap items-center gap-3">@csrf
                        <input type="hidden" name="base_revision" value="{{ $revision?->id ?? 0 }}">
                        <input class="rounded-lg border-slate-300" name="reason" placeholder="سبب الرجوع" required maxlength="400" aria-label="سبب الرجوع">
                        <label><input type="checkbox" name="confirmed" value="1" required> تأكيد الرجوع</label><button class="rounded-lg border px-4 py-2">نشر نسخة جديدة من هذه النسخة</button>
                    </form>
                </details>
            @endforeach
        </section>
        @can('studio.sessions.manage')
            <details class="rounded-2xl border bg-white p-6 space-y-4"><summary class="cursor-pointer text-lg font-bold">إدارة وصول الموظفين والجلسات — إعداد تقني مرة واحدة</summary>
                <p class="text-sm text-slate-600">امنح الموظف صلاحيات دخول Studio وقراءة إعداداته من إدارة الموظفين. للإنتاج اربط سياسة Agent قائمة تخص نفس الموظف. الرقم ليس سرًا ولا يمنح صلاحيات إضافية. تغييره يلغي جلسات الموظف السابقة.</p>
                @foreach($employees as $employee)
                    <form method="POST" action="{{ route('admin.studio-settings.employees.update', $employee) }}" class="flex flex-wrap items-center gap-3 rounded border p-3">@csrf @method('PUT')
                        <span class="min-w-40">{{ $employee->name }}</span><input class="rounded-lg border-slate-300" name="agent_token_id" type="number" min="1" placeholder="رقم سياسة Agent — وليس السر" value="{{ $grants[$employee->id]->agent_token_id ?? '' }}" aria-label="رقم توكن سياسة {{ $employee->name }}">
                        <label><input type="checkbox" name="confirmed" value="1" required> تأكيد</label><button class="rounded-lg border px-4 py-2">تحديث الربط</button>
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
