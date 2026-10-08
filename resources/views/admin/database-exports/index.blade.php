<x-admin-layout>
    <x-slot name="title">تصدير قاعدة البيانات</x-slot>
    <x-slot name="header">تصدير قاعدة البيانات</x-slot>
    <div class="max-w-6xl mx-auto space-y-6">
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 space-y-2 text-amber-900">
            <h2 class="text-lg font-bold">نسخة كاملة — بيانات حساسة</h2>
            <p>الملف يشمل كل جداول قاعدة البيانات، بيانات العملاء والطلبات والمصروفات والإعدادات الداخلية. لا تشاركه برابط عام.</p>
            <p>لا يشمل الصور والمرفقات أو ملف البيئة <span dir="ltr">.env</span>. احتفظ بمفتاح التطبيق الأصلي بشكل آمن لاستعادة القيم المشفرة.</p>
            <p>التجهيز في الخلفية بدون إيقاف الموقع. النسخة متاحة لمدة 24 ساعة ثم تُحذف نسخة التصدير فقط، وليس بيانات الموقع.</p>
        </div>
        @if(session('success'))
            <div role="status" class="bg-green-50 text-green-800 rounded-xl p-4">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div role="alert" class="bg-red-50 text-red-800 rounded-xl p-4">{{ $errors->first() }}</div>
        @endif
        <div class="bg-white border rounded-2xl p-6 space-y-4">
            <h2 class="text-xl font-bold">إنشاء نسخة جديدة</h2>
            <p class="text-gray-600">أكد كلمة مرور حسابك. يبدأ التجهيز بواسطة مجدول الموقع؛ يتم تجهيز نسخة واحدة في كل مرة.</p>
            @unless($available)
                <div class="bg-red-50 text-red-800 rounded-xl p-4">التصدير غير متاح على هذا السيرفر. يلزم MySQL وأداة mysqldump أو mariadb-dump مع السماح بتشغيلها. لا يتم إنشاء نسخة ناقصة.</div>
            @endunless
            <form method="POST" action="{{ route('admin.database-exports.store') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="flex-1 min-w-48">
                    <label for="export-password" class="block font-bold mb-2">كلمة المرور الحالية</label>
                    <input id="export-password" name="current_password" type="password" autocomplete="current-password" required class="w-full rounded-xl border-gray-300">
                </div>
                <button class="bg-indigo-600 text-white font-bold rounded-xl px-6 py-3 disabled:opacity-50" @disabled(!$available || $exports->contains(fn ($export) => in_array($export->status, ['queued', 'processing'])))>تجهيز نسخة SQL مضغوطة</button>
            </form>
        </div>
        <div class="bg-white border rounded-2xl overflow-hidden">
            <h2 class="text-xl font-bold p-6">نسخ التصدير الخاصة بك</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-right text-sm">
                    <thead class="bg-gray-50 text-gray-600"><tr><th class="p-4">تاريخ الطلب</th><th class="p-4">الحالة</th><th class="p-4">الحجم</th><th class="p-4">متاحة حتى</th><th class="p-4">تحميل آمن</th></tr></thead>
                    <tbody class="divide-y">
                    @forelse($exports as $export)
                        <tr>
                            <td class="p-4 whitespace-nowrap" dir="ltr">{{ $export->created_at->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</td>
                            <td class="p-4">
                                <span class="font-bold">{{ ['queued' => 'في انتظار التجهيز', 'processing' => 'جاري التجهيز', 'ready' => 'جاهزة', 'failed' => 'تعذر التصدير', 'expired' => 'انتهت الصلاحية'][$export->status] ?? $export->status }}</span>
                                @if($export->status === 'failed')
                                    <p class="text-red-700 mt-2 max-w-xs">{{ ['DUMP_UNAVAILABLE' => 'أداة التصدير غير متاحة.', 'NON_TRANSACTIONAL_TABLES' => 'توجد جداول غير InnoDB؛ يلزم تجهيز نسخة آمنة بمعرفة مسؤول السيرفر.', 'STORAGE_FAILED' => 'تعذر حفظ الملف في التخزين الخاص.', 'WORKER_INTERRUPTED' => 'توقف تجهيز النسخة؛ يمكنك طلب نسخة جديدة.'][$export->error_code] ?? 'تعذر إنشاء نسخة كاملة. راجع توفر أداة التصدير وصلاحيات قاعدة البيانات ومساحة التخزين.' }}</p>
                                @endif
                            </td>
                            <td class="p-4 whitespace-nowrap">{{ $export->size !== null ? number_format($export->size / 1048576, 2).' MB' : '—' }}</td>
                            <td class="p-4 whitespace-nowrap" dir="ltr">{{ $export->status === 'ready' ? $export->expires_at?->timezone('Africa/Cairo')->format('Y-m-d H:i') : '—' }}</td>
                            <td class="p-4">
                                @if($export->status === 'ready' && $export->expires_at?->isFuture())
                                    <form method="POST" action="{{ route('admin.database-exports.download', $export) }}" class="flex flex-wrap gap-2">
                                        @csrf
                                        <input name="current_password" type="password" autocomplete="current-password" required aria-label="كلمة المرور لتحميل النسخة" placeholder="كلمة المرور الحالية" class="rounded-lg border-gray-300 w-44">
                                        <button class="bg-indigo-600 text-white rounded-lg px-4 py-2 font-bold">تحميل SQL.gz</button>
                                    </form>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-8 text-center text-gray-500">لم تطلب أي نسخة بعد.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @if($exports->contains(fn ($export) => in_array($export->status, ['queued', 'processing'])))
        <script>
            (() => {
                const states = @json($exports->pluck('status', 'uuid'));
                const timer = setInterval(async () => {
                    if (document.hidden || document.activeElement?.type === 'password') return;
                    try {
                        const response = await fetch(@json(route('admin.database-exports.index')), {headers: {'Accept': 'application/json'}, cache: 'no-store'});
                        if (!response.ok) { clearInterval(timer); return; }
                        const data = await response.json();
                        if (data.exports.some(item => states[item.uuid] !== item.status)) window.location.reload();
                    } catch (_) { /* Manual refresh remains available. */ }
                }, 10000);
            })();
        </script>
    @endif
</x-admin-layout>
