@canany(['orders.update', 'orders.delete'])
    @if(!$group['trashed'])
        <div data-order-quick-edit
            data-options-url="{{ route('admin.orders.groups.quick-edit-options', $group['representative_id']) }}"
            data-removal-options-url="{{ route('admin.orders.groups.removal-options', $group['representative_id']) }}"
            data-remove-url="{{ route('admin.orders.groups.items.destroy', [$group['representative_id'], '__ITEM__']) }}"
            data-contact-url="{{ route('admin.orders.groups.contact', $group['representative_id']) }}"
            data-add-url="{{ route('admin.orders.groups.products.store', $group['representative_id']) }}"
            data-add-story-url="{{ route('admin.orders.groups.stories.store', $group['representative_id']) }}"
            data-item-url="{{ route('admin.orders.groups.products.update', [$group['representative_id'], '__ITEM__']) }}"
            data-story-url="{{ route('admin.orders.quick-story-details', '__ORDER__') }}"
            data-contact="{{ json_encode(['parent_name' => $group['customer_name'], 'phone' => $group['phone'], 'alternate_phone' => data_get($group['delivery'], 'alternate_phone')], JSON_UNESCAPED_UNICODE) }}">
            <dialog data-quick-dialog class="m-auto w-[min(94vw,42rem)] max-h-[90dvh] rounded-2xl border border-indigo-100 bg-white p-0 text-right shadow-2xl backdrop:bg-slate-900/40" dir="rtl">
                <form data-quick-form class="flex max-h-[90dvh] flex-col" enctype="multipart/form-data">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 p-4">
                        <h3 data-quick-title class="text-base font-black text-gray-900"></h3>
                        <button type="button" data-quick-close class="rounded-lg px-3 py-2 text-sm font-bold text-gray-500" aria-label="إغلاق">✕</button>
                    </div>
                    <div class="overflow-y-auto p-4">
                        <p class="mb-4 rounded-xl bg-indigo-50 p-3 text-xs font-bold leading-6 text-indigo-700">يحافظ على المرفقات والمدفوع، ويسجّل الموظف والوقت وسبب التعديل.</p>
                        <div data-quick-errors role="alert" hidden class="mb-3 whitespace-pre-line rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-700"></div>
                        <div data-quick-fields class="space-y-3"></div>
                    </div>
                    <div class="border-t border-gray-100 bg-gray-50 p-4">
                        <label data-quick-reason-label class="block text-xs font-black text-gray-700" for="quick-change-reason">سبب التعديل *</label>
                        <input id="quick-change-reason" name="change_reason" required minlength="5" maxlength="500" placeholder="مثال: طلب العميل إضافة قصة أو منتج أو تصحيح بيانات الطفل" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                        <div class="mt-3 flex items-center gap-3">
                            <button data-quick-save type="submit" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-black text-white disabled:opacity-50">حفظ التعديل</button>
                            <button data-quick-cancel type="button" class="rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-bold text-gray-600">إلغاء</button>
                            <span data-quick-status role="status" class="text-xs font-bold text-gray-500"></span>
                        </div>
                    </div>
                </form>
            </dialog>
        </div>
    @endif
@endcanany
