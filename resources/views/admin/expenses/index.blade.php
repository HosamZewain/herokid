<x-admin-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-black text-gray-900">المصروفات</h2>
                <p class="mt-1 text-xs font-bold text-gray-500">تسجيل الوارد والصادر ومتابعة الرصيد</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('expenses.create_income')
                    <a href="{{ route('admin.expenses.create', 'income') }}" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white hover:bg-emerald-700">+ إضافة وارد</a>
                    <a href="{{ route('admin.expenses.create', 'opening') }}" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-black text-emerald-700 hover:bg-emerald-100">رصيد افتتاحي</a>
                @endcan
                @can('expenses.create_expense')
                    <a href="{{ route('admin.expenses.create', 'expense') }}" class="rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-black text-white hover:bg-rose-700">− إضافة مصروف</a>
                @endcan
            </div>
        </div>
    </x-slot>

    @php
        $money = fn ($value) => \App\Services\Expenses\ExpenseLedgerService::formatMoney($value);
        $typeLabels = ['income' => 'وارد', 'expense' => 'صادر'];
        $statusLabels = ['posted' => 'مرحّل', 'voided' => 'ملغي'];
    @endphp

    <div class="mx-auto max-w-7xl space-y-6 py-4 sm:py-7">
        <div class="rounded-2xl border border-indigo-100 bg-indigo-50 px-5 py-4 text-sm leading-7 text-indigo-900">
            <span class="font-black">دفتر يدوي مستقل:</span>
            لا تُضاف المبيعات أو الشحن أو تكاليف الذكاء الاصطناعي تلقائيًا. الرصيد الحالي هو مجموع الوارد المرحّل ناقص مجموع الصادر المرحّل.
        </div>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach([
                ['إجمالي الوارد', $summary['total_income'], 'border-emerald-100', 'text-emerald-700'],
                ['إجمالي الصادر', $summary['total_expenses'], 'border-rose-100', 'text-rose-700'],
                ['الرصيد الحالي', $summary['current_balance'], $summary['current_balance'] >= 0 ? 'border-indigo-100' : 'border-rose-100', $summary['current_balance'] >= 0 ? 'text-indigo-700' : 'text-rose-700'],
                ['وارد هذا الشهر', $summary['month_income'], 'border-emerald-100', 'text-emerald-700'],
                ['مصروفات هذا الشهر', $summary['month_expenses'], 'border-rose-100', 'text-rose-700'],
                ['صافي حركة الفترة', $summary['period_net'], $summary['period_net'] >= 0 ? 'border-indigo-100' : 'border-rose-100', $summary['period_net'] >= 0 ? 'text-indigo-700' : 'text-rose-700'],
            ] as [$label, $value, $borderClass, $textClass])
                <article class="rounded-2xl border bg-white p-5 shadow-sm {{ $borderClass }}">
                    <p class="text-xs font-black text-gray-500">{{ $label }}</p>
                    <p class="mt-2 text-2xl font-black {{ $textClass }}">{{ $money($value) }}</p>
                </article>
            @endforeach
        </section>

        <section class="rounded-3xl border border-gray-100 bg-white p-4 shadow-sm sm:p-6">
            <form method="GET" action="{{ route('admin.expenses.index') }}" class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="block">
                        <span class="mb-2 block text-xs font-black text-gray-600">الفترة</span>
                        <select name="date_preset" class="w-full rounded-xl border-gray-200 text-sm">
                            @foreach(['all' => 'كل الفترات', 'today' => 'اليوم', 'last_7_days' => 'آخر 7 أيام', 'this_month' => 'هذا الشهر', 'last_month' => 'الشهر السابق', 'custom' => 'تاريخ مخصص'] as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['date_preset'] ?? 'this_month') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-xs font-black text-gray-600">من</span>
                        <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="w-full rounded-xl border-gray-200 text-sm">
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-xs font-black text-gray-600">إلى</span>
                        <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="w-full rounded-xl border-gray-200 text-sm">
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-xs font-black text-gray-600">النوع</span>
                        <select name="type" class="w-full rounded-xl border-gray-200 text-sm">
                            <option value="all">الكل</option>
                            <option value="income" @selected(($filters['type'] ?? '') === 'income')>وارد</option>
                            <option value="expense" @selected(($filters['type'] ?? '') === 'expense')>صادر</option>
                        </select>
                    </label>
                </div>

                <details class="rounded-2xl border border-gray-100 bg-slate-50 p-4" @if(request()->hasAny(['category_id','payment_method','vendor','amount_min','amount_max','created_by','status'])) open @endif>
                    <summary class="cursor-pointer text-sm font-black text-gray-800">فلاتر إضافية</summary>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <label>
                            <span class="mb-2 block text-xs font-black text-gray-600">التصنيف</span>
                            <select name="category_id" class="w-full rounded-xl border-gray-200 text-sm">
                                <option value="">كل التصنيفات</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}" @selected((string)($filters['category_id'] ?? '') === (string)$category->id)>{{ $category->type === 'income' ? 'وارد' : 'صادر' }} — {{ $category->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-2 block text-xs font-black text-gray-600">طريقة الدفع</span>
                            <select name="payment_method" class="w-full rounded-xl border-gray-200 text-sm">
                                <option value="">الكل</option>
                                @foreach($paymentMethods as $key => $label)
                                    <option value="{{ $key }}" @selected(($filters['payment_method'] ?? '') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-2 block text-xs font-black text-gray-600">المورد / الجهة</span>
                            <input name="vendor" value="{{ $filters['vendor'] ?? '' }}" class="w-full rounded-xl border-gray-200 text-sm" placeholder="ابحث بالاسم">
                        </label>
                        <label>
                            <span class="mb-2 block text-xs font-black text-gray-600">بواسطة</span>
                            <select name="created_by" class="w-full rounded-xl border-gray-200 text-sm">
                                <option value="">كل المستخدمين</option>
                                @foreach($admins as $admin)
                                    <option value="{{ $admin->id }}" @selected((string)($filters['created_by'] ?? '') === (string)$admin->id)>{{ $admin->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span class="mb-2 block text-xs font-black text-gray-600">أقل مبلغ</span>
                            <input type="number" min="0" step="0.01" name="amount_min" value="{{ $filters['amount_min'] ?? '' }}" class="w-full rounded-xl border-gray-200 text-sm">
                        </label>
                        <label>
                            <span class="mb-2 block text-xs font-black text-gray-600">أعلى مبلغ</span>
                            <input type="number" min="0" step="0.01" name="amount_max" value="{{ $filters['amount_max'] ?? '' }}" class="w-full rounded-xl border-gray-200 text-sm">
                        </label>
                        <label>
                            <span class="mb-2 block text-xs font-black text-gray-600">الحالة</span>
                            <select name="status" class="w-full rounded-xl border-gray-200 text-sm">
                                <option value="all">الكل</option>
                                <option value="posted" @selected(($filters['status'] ?? '') === 'posted')>مرحّل</option>
                                <option value="voided" @selected(($filters['status'] ?? '') === 'voided')>ملغي</option>
                            </select>
                        </label>
                    </div>
                </details>

                <div class="flex flex-wrap gap-2">
                    <button class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-black text-white hover:bg-indigo-700">تطبيق الفلاتر</button>
                    <a href="{{ route('admin.expenses.index', ['date_preset' => 'this_month']) }}" class="rounded-xl border border-gray-200 px-5 py-2.5 text-sm font-black text-gray-600 hover:bg-gray-50">إعادة ضبط</a>
                    @can('expenses.export')
                        <a href="{{ route('admin.expenses.export', request()->except('page')) }}" class="rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-2.5 text-sm font-black text-emerald-700">تصدير CSV</a>
                    @endcan
                    @can('expenses.manage_categories')
                        <a href="{{ route('admin.expenses.categories.index') }}" class="rounded-xl border border-indigo-200 bg-indigo-50 px-5 py-2.5 text-sm font-black text-indigo-700">إدارة التصنيفات</a>
                    @endcan
                </div>
            </form>
        </section>

        @can('expenses.view_reports')
            <section class="grid gap-4 lg:grid-cols-2">
                @foreach(['income' => ['الوارد حسب التصنيف', 'text-emerald-700'], 'expense' => ['الصادر حسب التصنيف', 'text-rose-700']] as $type => [$label, $tone])
                    <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                        <h3 class="font-black text-gray-900">{{ $label }}</h3>
                        <div class="mt-4 space-y-3">
                            @forelse($breakdown[$type] as $row)
                                <div class="flex items-center justify-between gap-3 border-b border-gray-100 pb-3 text-sm">
                                    <span class="font-bold text-gray-600">{{ $row->category?->name ?? 'غير محدد' }}</span>
                                    <span class="font-black {{ $tone }}">{{ $money($row->total) }}</span>
                                </div>
                            @empty
                                <p class="text-sm text-gray-400">لا توجد بيانات في الفترة المحددة.</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </section>
        @endcan

        <section class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
            @if($transactions->isEmpty())
                <div class="px-6 py-16 text-center">
                    <div class="text-4xl">🧾</div>
                    <h3 class="mt-4 text-lg font-black text-gray-900">لا توجد عمليات مالية بعد</h3>
                    <p class="mt-2 text-sm text-gray-500">ابدأ بإضافة رصيد افتتاحي أو تسجيل مصروف.</p>
                </div>
            @else
                <div class="hidden overflow-x-auto lg:block">
                    <table class="min-w-full divide-y divide-gray-100 text-right text-sm">
                        <thead class="bg-slate-50 text-xs font-black text-gray-500">
                            <tr>
                                @foreach(['التاريخ','النوع','التصنيف','الوصف','المورد / الجهة','طريقة الدفع','المبلغ','مرفق','بواسطة','الحالة','إجراءات'] as $heading)
                                    <th class="whitespace-nowrap px-4 py-3">{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($transactions as $transaction)
                                <tr class="{{ $transaction->status === 'voided' ? 'bg-gray-50 opacity-70' : '' }}">
                                    <td class="whitespace-nowrap px-4 py-4">{{ $transaction->transaction_date->format('Y-m-d') }}</td>
                                    <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-black {{ $transaction->type === 'income' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">{{ $typeLabels[$transaction->type] }}</span></td>
                                    <td class="px-4 py-4 font-bold">{{ $transaction->category?->name }}</td>
                                    <td class="max-w-xs truncate px-4 py-4 text-gray-600">{{ $transaction->description ?: '—' }}</td>
                                    <td class="px-4 py-4">{{ $transaction->vendor_name ?: '—' }}</td>
                                    <td class="px-4 py-4">{{ $paymentMethods[$transaction->payment_method] ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-4 font-black {{ $transaction->type === 'income' ? 'text-emerald-700' : 'text-rose-700' }}">{{ $transaction->type === 'income' ? '+' : '−' }} {{ $money($transaction->amount) }}</td>
                                    <td class="px-4 py-4">
                                        @if($transaction->attachment_path)
                                            @can('expenses.view_attachments')
                                                <a href="{{ route('admin.expenses.attachment', $transaction) }}" target="_blank" class="font-black text-indigo-600">عرض</a>
                                            @else
                                                <span class="text-gray-400">موجود</span>
                                            @endcan
                                        @else — @endif
                                    </td>
                                    <td class="px-4 py-4">{{ $transaction->createdBy?->name ?? '—' }}</td>
                                    <td class="px-4 py-4"><span class="font-black {{ $transaction->status === 'posted' ? 'text-emerald-700' : 'text-gray-500' }}">{{ $statusLabels[$transaction->status] }}</span></td>
                                    <td class="px-4 py-4">
                                        <div class="flex min-w-32 flex-wrap items-center gap-2">
                                            <a href="{{ route('admin.expenses.show', $transaction) }}" class="rounded-lg bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-700">عرض</a>
                                            @if($transaction->status === 'posted')
                                                @can('expenses.edit')
                                                    <a href="{{ route('admin.expenses.edit', $transaction) }}" class="rounded-lg bg-amber-50 px-3 py-2 text-xs font-black text-amber-700">تعديل</a>
                                                @endcan
                                                @can('expenses.void')
                                                    <details class="w-full rounded-xl border border-rose-100 bg-rose-50 p-2">
                                                        <summary class="cursor-pointer text-xs font-black text-rose-700">حذف عملية مسجلة بالخطأ</summary>
                                                        <form method="POST" action="{{ route('admin.expenses.void', $transaction) }}" class="mt-3 space-y-2">
                                                            @csrf
                                                            <label class="block">
                                                                <span class="mb-1 block text-xs font-bold text-rose-900">سبب الحذف *</span>
                                                                <textarea name="void_reason" required minlength="5" rows="2" class="w-full rounded-lg border-rose-200 text-xs" placeholder="مثال: تم تسجيل المصروف مرتين"></textarea>
                                                            </label>
                                                            <p class="text-[11px] leading-5 text-rose-700">سيُستبعد المبلغ من الرصيد مع الاحتفاظ بسجل التدقيق.</p>
                                                            <button onclick="return confirm('هل أنت متأكد من حذف أثر هذه العملية من الرصيد؟')" class="w-full rounded-lg bg-rose-600 px-3 py-2 text-xs font-black text-white">تأكيد الحذف</button>
                                                        </form>
                                                    </details>
                                                @endcan
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="divide-y divide-gray-100 lg:hidden">
                    @foreach($transactions as $transaction)
                        <article class="space-y-3 p-4 {{ $transaction->status === 'voided' ? 'bg-gray-50 opacity-70' : '' }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-black {{ $transaction->type === 'income' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">{{ $typeLabels[$transaction->type] }}</span>
                                    <h3 class="mt-2 font-black text-gray-900">{{ $transaction->category?->name }}</h3>
                                </div>
                                <p class="text-left font-black {{ $transaction->type === 'income' ? 'text-emerald-700' : 'text-rose-700' }}">{{ $transaction->type === 'income' ? '+' : '−' }} {{ $money($transaction->amount) }}</p>
                            </div>
                            <p class="text-sm text-gray-600">{{ $transaction->description ?: 'بدون وصف' }}</p>
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                                <span>{{ $transaction->transaction_date->format('Y-m-d') }} · {{ $transaction->vendor_name ?: 'بدون جهة' }}</span>
                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ route('admin.expenses.show', $transaction) }}" class="rounded-lg bg-indigo-50 px-3 py-2 font-black text-indigo-700">التفاصيل</a>
                                    @if($transaction->status === 'posted')
                                        @can('expenses.edit')
                                            <a href="{{ route('admin.expenses.edit', $transaction) }}" class="rounded-lg bg-amber-50 px-3 py-2 font-black text-amber-700">تعديل</a>
                                        @endcan
                                    @endif
                                </div>
                            </div>
                            @if($transaction->status === 'posted')
                                @can('expenses.void')
                                    <details class="rounded-xl border border-rose-100 bg-rose-50 p-3">
                                        <summary class="cursor-pointer text-xs font-black text-rose-700">حذف عملية مسجلة بالخطأ</summary>
                                        <form method="POST" action="{{ route('admin.expenses.void', $transaction) }}" class="mt-3 space-y-2">
                                            @csrf
                                            <label class="block">
                                                <span class="mb-1 block text-xs font-bold text-rose-900">سبب الحذف *</span>
                                                <textarea name="void_reason" required minlength="5" rows="2" class="w-full rounded-lg border-rose-200 text-sm" placeholder="مثال: تم تسجيل المصروف مرتين"></textarea>
                                            </label>
                                            <p class="text-xs leading-5 text-rose-700">سيُستبعد المبلغ من الرصيد مع الاحتفاظ بسجل التدقيق.</p>
                                            <button onclick="return confirm('هل أنت متأكد من حذف أثر هذه العملية من الرصيد؟')" class="w-full rounded-lg bg-rose-600 px-3 py-2 text-xs font-black text-white">تأكيد الحذف</button>
                                        </form>
                                    </details>
                                @endcan
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
            @if($transactions->hasPages())
                <div class="border-t border-gray-100 p-4">{{ $transactions->links() }}</div>
            @endif
        </section>
    </div>
</x-admin-layout>
