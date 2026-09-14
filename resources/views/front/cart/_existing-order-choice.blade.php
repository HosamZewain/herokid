<input type="hidden" name="previous_order_action" value="{{ old('previous_order_action') }}" data-previous-order-action>
<input type="hidden" name="previous_order_reference" value="{{ old('previous_order_reference') }}" data-previous-order-reference>
<x-input-error :messages="$errors->get('previous_order_action')" class="mt-1" />

<div class="fixed inset-0 z-50 hidden items-end justify-center bg-black/60 p-0 backdrop-blur-sm sm:items-center sm:p-4" data-existing-order-dialog role="dialog" aria-modal="true" aria-labelledby="existing-order-title">
    <section class="max-h-screen w-full max-w-xl overflow-y-auto rounded-t-3xl bg-white p-5 shadow-2xl sm:rounded-3xl sm:p-7">
        <div class="flex items-start justify-between gap-4">
            <button type="button" class="rounded-full bg-slate-100 px-3 py-1.5 text-sm font-black text-slate-600" data-existing-order-close aria-label="إغلاق">×</button>
            <div class="text-right">
                <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-black text-amber-800">طلب نشط موجود</span>
                <h2 id="existing-order-title" class="mt-3 text-xl font-black text-slate-950">لديك طلب سابق مسجل بنفس الرقم</h2>
                <p class="mt-2 text-sm font-bold leading-6 text-slate-600">الطلب <strong dir="ltr" data-existing-order-number></strong> حالته «<strong data-existing-order-status></strong>». اختر ما تريد فعله قبل إتمام الطلب الجديد.</p>
            </div>
        </div>

        <div class="mt-6 space-y-3">
            <button type="button" data-order-choice="separate" class="w-full rounded-2xl border-2 border-indigo-100 bg-indigo-50 p-4 text-right transition hover:border-indigo-300">
                <span class="block font-black text-indigo-950">المتابعة بالطلبين منفصلين</span>
                <span class="mt-1 block text-xs font-bold text-indigo-700">سيبقى لكل طلب سعر الشحن والمتابعة الخاصة به.</span>
            </button>
            <button type="button" data-order-choice="cancel_previous" class="w-full rounded-2xl border-2 border-red-100 bg-red-50 p-4 text-right transition hover:border-red-300 disabled:cursor-not-allowed disabled:opacity-50">
                <span class="block font-black text-red-950">الاحتفاظ بالطلب الجديد فقط وإلغاء القديم</span>
                <span class="mt-1 block text-xs font-bold text-red-700" data-cancel-choice-note>متاح فقط قبل بدء تنفيذ الطلب السابق وقبل تسجيل أي دفعة.</span>
            </button>
            <button type="button" data-order-choice="merge" class="w-full rounded-2xl border-2 border-emerald-100 bg-emerald-50 p-4 text-right transition hover:border-emerald-300 disabled:cursor-not-allowed disabled:opacity-50">
                <span class="block font-black text-emerald-950">دمج الطلبين واحتساب شحن واحد</span>
                <span class="mt-1 block text-xs font-bold text-emerald-700" data-merge-choice-note>تُجمع المنتجات في طلب واحد وتُحذف مصاريف الشحن المكررة.</span>
            </button>
        </div>
    </section>
</div>

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-checkout-form]');
            const phone = document.getElementById('checkout-phone');
            const dialog = document.querySelector('[data-existing-order-dialog]');
            const action = form?.querySelector('[data-previous-order-action]');
            const reference = form?.querySelector('[data-previous-order-reference]');
            let checking = false;
            let selectedOrder = null;

            if (!form || !phone || !dialog || !action || !reference) return;

            const close = () => {
                dialog.classList.add('hidden');
                dialog.classList.remove('flex');
            };

            phone.addEventListener('input', () => {
                action.value = '';
                reference.value = '';
            });
            dialog.querySelector('[data-existing-order-close]')?.addEventListener('click', close);

            dialog.querySelectorAll('[data-order-choice]').forEach((button) => {
                button.addEventListener('click', () => {
                    action.value = button.dataset.orderChoice;
                    reference.value = selectedOrder?.reference || '';
                    close();
                    form.requestSubmit();
                });
            });

            form.addEventListener('submit', async (event) => {
                if (checking || action.value) return;

                event.preventDefault();
                checking = true;

                try {
                    const response = await fetch(@json(route('checkout.active-orders')), {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ phone: phone.value }),
                    });
                    const payload = await response.json();
                    selectedOrder = Array.isArray(payload.orders) ? payload.orders[0] : null;

                    if (!response.ok || !selectedOrder) {
                        action.value = 'separate';
                        form.requestSubmit();
                        return;
                    }

                    dialog.querySelector('[data-existing-order-number]').textContent = selectedOrder.reference;
                    dialog.querySelector('[data-existing-order-status]').textContent = selectedOrder.status_label;
                    const cancelButton = dialog.querySelector('[data-order-choice="cancel_previous"]');
                    const mergeButton = dialog.querySelector('[data-order-choice="merge"]');
                    cancelButton.disabled = !selectedOrder.can_cancel;
                    mergeButton.disabled = !selectedOrder.can_merge;
                    dialog.classList.remove('hidden');
                    dialog.classList.add('flex');
                } catch (error) {
                    action.value = 'separate';
                    form.requestSubmit();
                } finally {
                    checking = false;
                }
            }, { capture: true });
        });
    </script>
@endpush
