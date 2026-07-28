<x-admin-layout>
    @php
        $editing = $transaction->exists;
        $type = $transaction->type;
        $isOpening = $kind === 'opening';
        $title = $editing ? 'تعديل العملية المالية' : ($isOpening ? 'إضافة رصيد افتتاحي' : ($type === 'income' ? 'إضافة وارد' : 'إضافة مصروف'));
    @endphp

    <x-slot name="header">
        <div>
            <h2 class="text-xl font-black text-gray-900">{{ $title }}</h2>
            <p class="mt-1 text-xs font-bold text-gray-500">كل القيم تُسجل يدويًا ولا ترتبط بأي نظام آخر.</p>
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl py-4 sm:py-7">
        <form method="POST" enctype="multipart/form-data" action="{{ $editing ? route('admin.expenses.update', $transaction) : route('admin.expenses.store') }}" class="space-y-6 rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-8">
            @csrf
            @if($editing) @method('PUT') @endif
            <input type="hidden" name="kind" value="{{ $kind }}">
            <input type="hidden" name="type" value="{{ $type }}">

            @if($errors->any())
                <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800">
                    <p class="font-black">راجع البيانات التالية:</p>
                    <ul class="mt-2 list-disc space-y-1 pr-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            @if($isOpening && $openingBalanceExists)
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-7 text-amber-900">
                    <p class="font-black">يوجد رصيد افتتاحي مسجل بالفعل. هل تريد إضافة وارد جديد؟</p>
                    <label class="mt-3 flex items-start gap-2 font-bold">
                        <input type="checkbox" name="confirm_existing_opening_balance" value="1" @checked(old('confirm_existing_opening_balance')) class="mt-1 rounded border-amber-300 text-amber-600">
                        نعم، أريد إضافة رصيد افتتاحي آخر بعد المراجعة.
                    </label>
                </div>
            @endif

            <div class="grid gap-5 sm:grid-cols-2">
                <label>
                    <span class="mb-2 block text-sm font-black text-gray-700">التاريخ *</span>
                    <input type="date" name="transaction_date" required value="{{ old('transaction_date', optional($transaction->transaction_date)->format('Y-m-d') ?: now()->format('Y-m-d')) }}" class="w-full rounded-xl border-gray-200">
                </label>
                <label>
                    <span class="mb-2 block text-sm font-black text-gray-700">المبلغ *</span>
                    <div class="relative">
                        <input type="number" name="amount" required min="0.01" max="9999999999.99" step="0.01" value="{{ old('amount', $transaction->amount) }}" class="w-full rounded-xl border-gray-200 pl-16">
                        <span class="absolute inset-y-0 left-4 flex items-center text-xs font-black text-gray-400">{{ config('expenses.currency_label') }}</span>
                    </div>
                    <p class="mt-1 text-xs text-amber-600" data-large-expense-warning hidden>تنبيه: هذا المبلغ يتجاوز حد المصروف الكبير.</p>
                </label>
                <label>
                    <span class="mb-2 block text-sm font-black text-gray-700">التصنيف *</span>
                    @if($isOpening)
                        <input type="text" value="رصيد افتتاحي" readonly class="w-full rounded-xl border-gray-200 bg-gray-50">
                    @else
                        <select name="category_id" required class="w-full rounded-xl border-gray-200">
                            <option value="">اختر التصنيف</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected((string)old('category_id', $transaction->category_id) === (string)$category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </label>
                <label>
                    <span class="mb-2 block text-sm font-black text-gray-700">طريقة الدفع</span>
                    <select name="payment_method" class="w-full rounded-xl border-gray-200">
                        <option value="">غير محدد</option>
                        @foreach($paymentMethods as $key => $label)
                            <option value="{{ $key }}" @selected(old('payment_method', $transaction->payment_method) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="mb-2 block text-sm font-black text-gray-700">المورد / الجهة</span>
                    <input name="vendor_name" maxlength="255" value="{{ old('vendor_name', $transaction->vendor_name) }}" class="w-full rounded-xl border-gray-200">
                </label>
                <label>
                    <span class="mb-2 block text-sm font-black text-gray-700">رقم المرجع</span>
                    <input name="reference_number" maxlength="255" value="{{ old('reference_number', $transaction->reference_number) }}" class="w-full rounded-xl border-gray-200">
                </label>
            </div>

            <label class="block">
                <span class="mb-2 block text-sm font-black text-gray-700">الوصف</span>
                <textarea name="description" rows="3" class="w-full rounded-xl border-gray-200">{{ old('description', $transaction->description) }}</textarea>
            </label>
            <label class="block">
                <span class="mb-2 block text-sm font-black text-gray-700">ملاحظات داخلية</span>
                <textarea name="notes" rows="4" class="w-full rounded-xl border-gray-200">{{ old('notes', $transaction->notes) }}</textarea>
            </label>

            <div class="rounded-2xl border border-dashed border-gray-300 p-5">
                <label class="block">
                    <span class="mb-2 block text-sm font-black text-gray-700">فاتورة أو إيصال (اختياري)</span>
                    <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" class="block w-full text-sm">
                    <span class="mt-2 block text-xs text-gray-500">PDF أو JPG أو PNG أو WEBP — بحد أقصى {{ config('expenses.attachment_max_mb') }} MB. يُحفظ الملف بشكل خاص.</span>
                </label>
                @if($editing && $transaction->attachment_path)
                    <div class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                        المرفق الحالي: <span class="font-black">{{ $transaction->attachment_original_name }}</span>
                        <label class="mt-2 flex items-center gap-2 font-bold">
                            <input type="checkbox" name="confirm_replace_attachment" value="1" @checked(old('confirm_replace_attachment')) class="rounded">
                            أؤكد استبدال المرفق الحالي إذا اخترت ملفًا جديدًا.
                        </label>
                    </div>
                @endif
            </div>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <a href="{{ $editing ? route('admin.expenses.show', $transaction) : route('admin.expenses.index') }}" class="rounded-xl border border-gray-200 px-6 py-3 text-center text-sm font-black text-gray-600">إلغاء</a>
                <button class="rounded-xl bg-indigo-600 px-7 py-3 text-sm font-black text-white hover:bg-indigo-700">{{ $editing ? 'حفظ التعديلات' : 'تسجيل العملية' }}</button>
            </div>
        </form>
    </div>

    @push('scripts')
        <script>
            (() => {
                const input = document.querySelector('input[name="amount"]');
                const warning = document.querySelector('[data-large-expense-warning]');
                if (!input || !warning || @json($type !== 'expense')) return;
                const update = () => warning.hidden = Number(input.value || 0) < @json((float) config('expenses.large_expense_warning_amount'));
                input.addEventListener('input', update);
                update();
            })();
        </script>
    @endpush
</x-admin-layout>
