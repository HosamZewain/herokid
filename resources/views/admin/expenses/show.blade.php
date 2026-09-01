<x-admin-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-black text-gray-900">تفاصيل العملية #{{ $transaction->id }}</h2>
                <p class="mt-1 text-xs font-bold text-gray-500">سجل يدوي محفوظ مع تاريخ النشاط.</p>
            </div>
            <a href="{{ route('admin.expenses.index') }}" class="text-sm font-black text-indigo-600">العودة إلى المصروفات</a>
        </div>
    </x-slot>

    @php
        $money = \App\Services\Expenses\ExpenseLedgerService::formatMoney($transaction->amount);
        $paymentMethods = config('expenses.payment_methods', []);
    @endphp

    <div class="mx-auto max-w-5xl space-y-6 py-4 sm:py-7">
        <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <span class="rounded-full px-3 py-1.5 text-xs font-black {{ $transaction->type === 'income' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">{{ $transaction->type === 'income' ? 'وارد' : 'صادر' }}</span>
                    <h3 class="mt-3 text-3xl font-black {{ $transaction->type === 'income' ? 'text-emerald-700' : 'text-rose-700' }}">{{ $money }}</h3>
                    <p class="mt-2 font-black text-gray-900">{{ $transaction->category?->name }}</p>
                </div>
                <span class="rounded-full px-3 py-1.5 text-xs font-black {{ $transaction->status === 'posted' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">{{ $transaction->status === 'posted' ? 'مرحّل ويؤثر في الرصيد' : 'ملغي ولا يؤثر في الرصيد' }}</span>
            </div>

            @if($transaction->status === 'voided')
                <div class="mt-6 rounded-2xl border border-gray-200 bg-gray-50 p-4 text-sm leading-7 text-gray-700">
                    <p><span class="font-black">سبب الإلغاء:</span> {{ $transaction->void_reason }}</p>
                    <p><span class="font-black">ألغاه:</span> {{ $transaction->voidedBy?->name ?? 'مستخدم محذوف' }} — {{ app_datetime($transaction->voided_at, 'Y-m-d H:i') }}</p>
                </div>
            @endif

            <dl class="mt-7 grid gap-5 border-t border-gray-100 pt-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach([
                    ['التاريخ', $transaction->transaction_date->format('Y-m-d')],
                    ['الوصف', $transaction->description ?: '—'],
                    ['المورد / الجهة', $transaction->vendor_name ?: '—'],
                    ['طريقة الدفع', $paymentMethods[$transaction->payment_method] ?? '—'],
                    ['رقم المرجع', $transaction->reference_number ?: '—'],
                    ['سجلها', $transaction->createdBy?->name ?? 'مستخدم محذوف'],
                ] as [$label, $value])
                    <div><dt class="text-xs font-black text-gray-400">{{ $label }}</dt><dd class="mt-1 break-words text-sm font-bold text-gray-800">{{ $value }}</dd></div>
                @endforeach
            </dl>

            @if($transaction->notes)
                <div class="mt-6 rounded-2xl bg-slate-50 p-4"><p class="text-xs font-black text-gray-400">الملاحظات</p><p class="mt-2 whitespace-pre-wrap text-sm leading-7 text-gray-700">{{ $transaction->notes }}</p></div>
            @endif

            @if($transaction->attachment_path)
                <div class="mt-6 rounded-2xl border border-indigo-100 bg-indigo-50 p-4">
                    <p class="text-sm font-black text-indigo-900">المرفق الخاص: {{ $transaction->attachment_original_name }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @can('expenses.view_attachments')
                            <a target="_blank" href="{{ route('admin.expenses.attachment', $transaction) }}" class="rounded-lg bg-white px-4 py-2 text-xs font-black text-indigo-700">عرض المرفق</a>
                        @endcan
                        @can('expenses.download_attachments')
                            <a href="{{ route('admin.expenses.attachment.download', $transaction) }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-black text-white">تنزيل المرفق</a>
                        @endcan
                    </div>
                </div>
            @endif

            @if($transaction->status === 'posted')
                <div class="mt-7 flex flex-wrap gap-3 border-t border-gray-100 pt-6">
                    @can('expenses.edit')
                        <a href="{{ route('admin.expenses.edit', $transaction) }}" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-black text-white">تعديل العملية</a>
                    @endcan
                    @can('expenses.void')
                        <button type="button" data-void-toggle class="rounded-xl border border-rose-200 bg-rose-50 px-5 py-2.5 text-sm font-black text-rose-700">حذف عملية مسجلة بالخطأ</button>
                    @endcan
                </div>
                @can('expenses.void')
                    <form method="POST" action="{{ route('admin.expenses.void', $transaction) }}" data-void-form hidden class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 p-5">
                        @csrf
                        <p class="mb-3 text-sm leading-7 text-rose-800">الحذف آمن: ستبقى العملية في سجل التدقيق، لكن لن تدخل في إجمالي المصروفات أو الرصيد.</p>
                        <label class="block"><span class="mb-2 block text-sm font-black text-rose-900">سبب الحذف *</span><textarea name="void_reason" required minlength="5" rows="3" class="w-full rounded-xl border-rose-200" placeholder="مثال: تم تسجيل المصروف مرتين"></textarea></label>
                        <button onclick="return confirm('هل أنت متأكد من حذف أثر هذه العملية من الرصيد؟')" class="mt-3 rounded-xl bg-rose-600 px-5 py-2.5 text-sm font-black text-white">تأكيد الحذف</button>
                    </form>
                @endcan
            @endif
        </section>

        <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-8">
            <h3 class="text-lg font-black text-gray-900">سجل النشاط</h3>
            <div class="mt-5 space-y-4">
                @foreach($transaction->activityLogs as $log)
                    <div class="border-r-2 border-indigo-200 pr-4">
                        <p class="text-sm font-black text-gray-800">{{ $log->description }}</p>
                        <p class="mt-1 text-xs text-gray-400">{{ $log->actor?->name ?? 'مستخدم محذوف' }} · {{ app_datetime($log->created_at, 'Y-m-d H:i') }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    @push('scripts')
        <script>
            document.querySelector('[data-void-toggle]')?.addEventListener('click', () => {
                const form = document.querySelector('[data-void-form]');
                form.hidden = !form.hidden;
                if (!form.hidden) form.querySelector('textarea')?.focus();
            });
        </script>
    @endpush
</x-admin-layout>
