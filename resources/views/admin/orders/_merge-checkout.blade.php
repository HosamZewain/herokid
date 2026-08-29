@can('orders.update')
    @if(!($mergeGroup['trashed'] ?? false))
        <details class="rounded-2xl border border-amber-200 bg-amber-50/70 shadow-sm" @if($errors->hasAny(['source_reference', 'merge_reason', 'confirm_primary_delivery'])) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 text-right">
                <div>
                    <h3 class="text-sm font-black text-amber-950">دمج طلب آخر مع هذه العملية</h3>
                    <p class="mt-1 text-xs font-bold text-amber-800">اجمع طلبات نفس العميل واحتسب مصاريف التوصيل مرة واحدة.</p>
                </div>
                <span class="rounded-full bg-white px-3 py-1 text-xs font-black text-amber-800">فتح</span>
            </summary>

            <form method="POST"
                  action="{{ route('admin.orders.groups.merge', $mergeGroup['representative_id']) }}"
                  class="border-t border-amber-200 px-5 py-5 text-right"
                  onsubmit="return confirm('سيتم نقل كل قصص ومنتجات الطلب الآخر إلى هذه العملية وحذف مصاريف شحنه من الإجمالي. هل تريد المتابعة؟')">
                @csrf

                <div class="rounded-xl border border-amber-200 bg-white/80 p-4 text-xs font-bold leading-6 text-amber-950">
                    <p>• الطلب المفتوح الآن سيبقى الطلب الأساسي، وسيُعتمد اسمه وعنوانه ومصاريف توصيله.</p>
                    <p>• القصص والمنتجات وبيانات الأطفال والصور والمرفقات في الطلبين ستظل محفوظة.</p>
                    <p>• لا يمكن دمج طلبين برقمَي هاتف مختلفين، أو طلب بدأ شحنه/تم تسليمه/إلغاؤه.</p>
                </div>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="merge-source-reference" class="mb-1.5 block text-sm font-black text-gray-800">رقم الطلب الآخر</label>
                        <input id="merge-source-reference"
                               name="source_reference"
                               value="{{ old('source_reference') }}"
                               required
                               maxlength="255"
                               dir="ltr"
                               placeholder="مثال: HK08-151"
                               class="block w-full rounded-xl border-gray-300 text-left shadow-sm focus:border-amber-500 focus:ring-amber-500">
                        <x-input-error :messages="$errors->get('source_reference')" class="mt-1" />
                    </div>
                    <div>
                        <label for="merge-reason" class="mb-1.5 block text-sm font-black text-gray-800">سبب الدمج</label>
                        <input id="merge-reason"
                               name="merge_reason"
                               value="{{ old('merge_reason') }}"
                               required
                               minlength="5"
                               maxlength="1000"
                               placeholder="مثال: العميل أنشأ طلباً ثانياً لطفل آخر"
                               class="block w-full rounded-xl border-gray-300 text-right shadow-sm focus:border-amber-500 focus:ring-amber-500">
                        <x-input-error :messages="$errors->get('merge_reason')" class="mt-1" />
                    </div>
                </div>

                <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-amber-200 bg-white p-4">
                    <input type="checkbox"
                           name="confirm_primary_delivery"
                           value="1"
                           required
                           @checked(old('confirm_primary_delivery'))
                           class="mt-1 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                    <span class="text-sm font-black text-gray-800">
                        أؤكد اعتماد بيانات توصيل الطلب الحالي
                        <span class="mt-1 block text-xs font-bold text-gray-500">{{ $mergeGroup['short_reference'] ?: $mergeGroup['key'] }} — {{ $mergeGroup['customer_name'] }} — {{ $mergeGroup['phone'] ?: 'بدون هاتف' }}</span>
                    </span>
                </label>
                <x-input-error :messages="$errors->get('confirm_primary_delivery')" class="mt-1" />

                <button type="submit" class="mt-4 w-full rounded-xl bg-amber-600 px-5 py-3 text-sm font-black text-white transition hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
                    دمج الطلبين واحتساب شحن واحد
                </button>
            </form>
        </details>
    @endif
@endcan
