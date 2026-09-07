@can('orders.discount.manage')
    @if(! $group['trashed'])
        <details class="mt-5 border-t border-indigo-200 pt-4" data-order-discount-form>
            <summary class="cursor-pointer select-none text-sm font-black text-indigo-800">إضافة أو تعديل خصم</summary>
            <form method="POST" action="{{ route('admin.orders.groups.discount', $group['representative_id']) }}" class="mt-4 space-y-3 rounded-2xl bg-white/80 p-4 text-right ring-1 ring-indigo-100">
                @csrf
                @method('PATCH')
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-xs font-black text-slate-700">
                        نوع الخصم
                        <select name="discount_type" required class="mt-1.5 w-full rounded-xl border-gray-200 bg-white text-sm">
                            <option value="fixed" @selected(old('discount_type', 'fixed') === 'fixed')>مبلغ ثابت بالجنيه</option>
                            <option value="percentage" @selected(old('discount_type') === 'percentage')>نسبة مئوية</option>
                        </select>
                    </label>
                    <label class="text-xs font-black text-slate-700">
                        القيمة
                        <input name="discount_value" type="number" min="0.01" max="9999999.99" step="0.01" value="{{ old('discount_value') }}" required dir="ltr" class="mt-1.5 w-full rounded-xl border-gray-200 text-left text-sm" placeholder="مثال: 50 أو 10">
                    </label>
                </div>
                <label class="block text-xs font-black text-slate-700">
                    طريقة التطبيق
                    <select name="discount_mode" required class="mt-1.5 w-full rounded-xl border-gray-200 bg-white text-sm">
                        <option value="add" @selected(old('discount_mode', 'add') === 'add')>إضافة فوق الخصم الحالي</option>
                        <option value="replace" @selected(old('discount_mode') === 'replace')>استبدال الخصم الحالي بالكامل</option>
                    </select>
                </label>
                <label class="block text-xs font-black text-slate-700">
                    سبب الخصم *
                    <textarea name="discount_reason" rows="2" minlength="5" maxlength="500" required class="mt-1.5 w-full rounded-xl border-gray-200 text-sm" placeholder="اكتب سببًا واضحًا يظهر في سجل الطلب">{{ old('discount_reason') }}</textarea>
                </label>
                <p class="text-[11px] font-bold leading-5 text-slate-500">الخصم يُطبق على قيمة المنتجات فقط، والنسبة تُحسب منها. مصاريف التوصيل لا يشملها الخصم. الخصم الحالي: {{ format_money($group['discount_cents'] / 100) }}.</p>
                <button class="min-h-11 w-full rounded-xl bg-rose-600 px-4 py-3 text-sm font-black text-white transition hover:bg-rose-700" onclick="return confirm('هل تريد تطبيق هذا الخصم على عملية الشراء بالكامل؟')">تطبيق الخصم</button>
            </form>
        </details>
    @endif
@endcan
