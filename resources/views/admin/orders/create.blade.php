<x-admin-layout>
    <x-slot name="header">
        <div class="text-right">
            <p class="text-xs font-black text-indigo-500">الطلبات</p>
            <h2 class="mt-1 text-xl font-black text-gray-900">إضافة طلب</h2>
            <p class="mt-1 text-xs font-bold text-gray-500">أنشئ عملية شراء كاملة لعميل تواصل معك خارج الموقع.</p>
        </div>
    </x-slot>

    @php
        $initialStories = old('stories', [[
            'story_id' => '', 'child_name' => '', 'child_age' => '', 'child_gender' => '',
            'interests' => '', 'gift_note' => '', 'parent_notes' => '',
        ]]);
    @endphp

    <div class="py-6" data-admin-order-form>
        <div class="mx-auto max-w-7xl space-y-5 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between gap-3">
                <a href="{{ route('admin.orders.index') }}" class="rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-black text-gray-600 hover:bg-gray-50">العودة إلى الطلبات</a>
                <span class="rounded-full bg-amber-50 px-3 py-2 text-xs font-black text-amber-700">إدخال يدوي بواسطة الإدارة</span>
            </div>

            @if($errors->any())
                <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-bold text-red-800" role="alert">
                    <p class="mb-2 font-black">راجع البيانات التالية:</p>
                    @foreach($errors->all() as $message)<p>• {{ $message }}</p>@endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.orders.store') }}" enctype="multipart/form-data" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]" data-order-form>
                @csrf

                <div class="space-y-5">
                    <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-6">
                        <div class="mb-5 text-right">
                            <h3 class="text-lg font-black text-gray-950">بيانات العميل ومصدر الطلب</h3>
                            <p class="mt-1 text-xs font-bold text-gray-500">بيانات موحدة لكل القصص والمنتجات داخل هذه العملية.</p>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label for="parent-name" class="mb-1.5 block text-xs font-black text-gray-700">اسم ولي الأمر *</label>
                                <input id="parent-name" name="parent_name" value="{{ old('parent_name') }}" required autocomplete="name" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            </div>
                            <div>
                                <label for="phone" class="mb-1.5 block text-xs font-black text-gray-700">رقم الهاتف / واتساب *</label>
                                <input id="phone" name="phone" value="{{ old('phone') }}" required inputmode="tel" autocomplete="tel" dir="ltr" class="w-full rounded-xl border-gray-200 text-left text-sm">
                            </div>
                            <div>
                                <label for="order-source" class="mb-1.5 block text-xs font-black text-gray-700">مصدر الطلب *</label>
                                <select id="order-source" name="order_source" required class="w-full rounded-xl border-gray-200 bg-white text-right text-sm">
                                    <option value="">اختر المصدر</option>
                                    @foreach($sourceOptions as $value => $label)
                                        <option value="{{ $value }}" @selected(old('order_source') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="source-notes" class="mb-1.5 block text-xs font-black text-gray-700">تفاصيل المصدر <span class="font-normal text-gray-400">اختياري</span></label>
                                <input id="source-notes" name="source_notes" value="{{ old('source_notes') }}" placeholder="مثال: رسالة على إنستجرام أو زيارة المعرض" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            </div>
                        </div>
                    </section>

                    <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-6">
                        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                            <button type="button" class="rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-black text-white hover:bg-violet-700" data-add-story>+ إضافة قصة أخرى</button>
                            <div class="text-right">
                                <h3 class="text-lg font-black text-gray-950">القصص وبيانات الأطفال</h3>
                                <p class="mt-1 text-xs font-bold text-gray-500">يمكن إضافة عدة قصص لنفس الطفل أو لأطفال مختلفين.</p>
                            </div>
                        </div>

                        <div class="space-y-4" data-story-rows>
                            @foreach($initialStories as $index => $row)
                                @include('admin.orders._manual-story-row', ['index' => $index, 'row' => $row])
                            @endforeach
                        </div>

                        <template data-story-template>
                            @include('admin.orders._manual-story-row', ['index' => '__INDEX__', 'row' => []])
                        </template>
                    </section>

                    @if($products->isNotEmpty())
                        <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-6">
                            <div class="mb-5 text-right">
                                <h3 class="text-lg font-black text-gray-950">منتجات وإضافات اختيارية</h3>
                                <p class="mt-1 text-xs font-bold text-gray-500">ضع الكمية المطلوبة فقط. المنتجات المخصصة يجب ربطها بالقصة/الطفل الصحيح.</p>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2">
                                @foreach($products as $product)
                                    @php
                                        $quantity = (int) old("products.$product->id.quantity", 0);
                                        $basePrice = $product->effectivePriceCents();
                                    @endphp
                                    <article class="rounded-2xl border border-gray-100 bg-slate-50 p-4" data-product-row data-base-price-cents="{{ $basePrice }}">
                                        <div class="flex items-start justify-between gap-3">
                                            <span class="font-black text-indigo-700">{{ format_money($basePrice / 100) }}</span>
                                            <div class="text-right">
                                                <h4 class="font-black text-gray-900">{{ $product->name_ar }}</h4>
                                                @if($product->isPersonalizedAddon())<p class="mt-1 text-[10px] font-black text-violet-600">مرتبط بقصة وطفل</p>@endif
                                            </div>
                                        </div>
                                        <div class="mt-4 grid gap-3 {{ $product->isPersonalizedAddon() ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
                                            <div>
                                                <label class="mb-1 block text-[10px] font-black text-gray-500">الكمية</label>
                                                <input name="products[{{ $product->id }}][quantity]" type="number" min="0" max="99" value="{{ $quantity }}" class="w-full rounded-xl border-gray-200 text-center text-sm" data-product-quantity>
                                            </div>
                                            @if($product->activeVariants->isNotEmpty())
                                                <div>
                                                    <label class="mb-1 block text-[10px] font-black text-gray-500">الخيار</label>
                                                    <select name="products[{{ $product->id }}][variant_id]" class="w-full rounded-xl border-gray-200 bg-white text-right text-xs" data-product-variant>
                                                        <option value="" data-price-cents="{{ $basePrice }}">اختر</option>
                                                        @foreach($product->activeVariants as $variant)
                                                            <option value="{{ $variant->id }}" data-price-cents="{{ $product->effectivePriceCents($variant) }}" @selected((string) old("products.$product->id.variant_id") === (string) $variant->id)>{{ $variant->name_ar }} — {{ format_money($product->effectivePriceCents($variant) / 100) }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            @else
                                                <div class="hidden"><input type="hidden" name="products[{{ $product->id }}][variant_id]" value=""></div>
                                            @endif
                                            @if($product->isPersonalizedAddon())
                                                <div>
                                                    <label class="mb-1 block text-[10px] font-black text-gray-500">القصة / الطفل</label>
                                                    <select name="products[{{ $product->id }}][linked_story_index]" class="w-full rounded-xl border-gray-200 bg-white text-right text-xs" data-story-link data-selected-value="{{ old("products.$product->id.linked_story_index") }}">
                                                        <option value="">اختر القصة</option>
                                                    </select>
                                                </div>
                                            @endif
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-6">
                        <div class="mb-5 text-right">
                            <h3 class="text-lg font-black text-gray-950">بيانات التوصيل</h3>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label for="country" class="mb-1.5 block text-xs font-black text-gray-700">الدولة *</label>
                                <select id="country" name="delivery_country_id" required class="w-full rounded-xl border-gray-200 bg-white text-right text-sm" data-country-select>
                                    <option value="">اختر الدولة</option>
                                    @foreach($countries as $country)
                                        <option value="{{ $country->id }}" @selected((string) old('delivery_country_id') === (string) $country->id)>{{ $country->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="governorate" class="mb-1.5 block text-xs font-black text-gray-700">المحافظة *</label>
                                <select id="governorate" name="delivery_governorate_id" required class="w-full rounded-xl border-gray-200 bg-white text-right text-sm" data-governorate-select>
                                    <option value="">اختر المحافظة</option>
                                    @foreach($countries as $country)
                                        @foreach($country->activeGovernorates as $governorate)
                                            <option value="{{ $governorate->id }}" data-country-id="{{ $country->id }}" data-fee-cents="{{ (int) round($governorate->effectiveDeliveryFee() * 100) }}" @selected((string) old('delivery_governorate_id') === (string) $governorate->id)>{{ $governorate->name }} — {{ format_money($governorate->effectiveDeliveryFee()) }}</option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="city" class="mb-1.5 block text-xs font-black text-gray-700">المدينة / المنطقة *</label>
                                <input id="city" name="city" value="{{ old('city') }}" required autocomplete="address-level2" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            </div>
                            <div>
                                <label for="street" class="mb-1.5 block text-xs font-black text-gray-700">الشارع *</label>
                                <input id="street" name="street" value="{{ old('street') }}" required autocomplete="street-address" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            </div>
                            <div class="md:col-span-2">
                                <label for="address-details" class="mb-1.5 block text-xs font-black text-gray-700">تفاصيل العنوان *</label>
                                <textarea id="address-details" name="address_details" rows="3" required class="w-full rounded-xl border-gray-200 text-right text-sm">{{ old('address_details') }}</textarea>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-6">
                        <div class="mb-5 text-right">
                            <h3 class="text-lg font-black text-gray-950">الخصم والدفع والملاحظات</h3>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label for="discount" class="mb-1.5 block text-xs font-black text-gray-700">قيمة الخصم بالجنيه</label>
                                <input id="discount" name="discount_amount" type="number" min="0" step="0.01" value="{{ old('discount_amount', 0) }}" class="w-full rounded-xl border-gray-200 text-left text-sm" dir="ltr" data-discount-input>
                            </div>
                            <div>
                                <label for="discount-reason" class="mb-1.5 block text-xs font-black text-gray-700">سبب الخصم</label>
                                <input id="discount-reason" name="discount_reason" value="{{ old('discount_reason') }}" placeholder="مطلوب عند إضافة خصم" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            </div>
                            <div>
                                <label for="payment-status" class="mb-1.5 block text-xs font-black text-gray-700">حالة الدفع *</label>
                                <select id="payment-status" name="payment_status" required class="w-full rounded-xl border-gray-200 bg-white text-right text-sm" data-payment-status>
                                    @foreach($paymentStatuses as $value => $label)
                                        <option value="{{ $value }}" @selected(old('payment_status', 'unpaid') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div data-partial-payment-field @if(old('payment_status', 'unpaid') !== 'partially_paid') hidden @endif>
                                <label for="paid-amount" class="mb-1.5 block text-xs font-black text-gray-700">المبلغ المدفوع بالجنيه *</label>
                                <input id="paid-amount" name="paid_amount" type="number" min="0.01" step="0.01" value="{{ old('paid_amount') }}" class="w-full rounded-xl border-gray-200 text-left text-sm" dir="ltr" data-paid-amount @required(old('payment_status') === 'partially_paid') @disabled(old('payment_status', 'unpaid') !== 'partially_paid')>
                            </div>
                            <div data-payment-method-field @if(old('payment_status', 'unpaid') === 'unpaid') hidden @endif>
                                <label for="payment-method" class="mb-1.5 block text-xs font-black text-gray-700">طريقة الدفع *</label>
                                <select id="payment-method" name="payment_method" class="w-full rounded-xl border-gray-200 bg-white text-right text-sm" data-payment-method @required(old('payment_status', 'unpaid') !== 'unpaid') @disabled(old('payment_status', 'unpaid') === 'unpaid')>
                                    <option value="">اختر الطريقة</option>
                                    @foreach($paymentMethods as $method)<option value="{{ $method }}" @selected(old('payment_method') === $method)>{{ $method }}</option>@endforeach
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label for="admin-notes" class="mb-1.5 block text-xs font-black text-gray-700">ملاحظات إدارية داخلية <span class="font-normal text-gray-400">اختياري</span></label>
                                <textarea id="admin-notes" name="admin_notes" rows="3" class="w-full rounded-xl border-gray-200 text-right text-sm">{{ old('admin_notes') }}</textarea>
                            </div>
                        </div>
                    </section>
                </div>

                <aside class="xl:sticky xl:top-24 xl:self-start">
                    <div class="rounded-3xl border border-indigo-100 bg-indigo-950 p-5 text-white shadow-xl sm:p-6">
                        <h3 class="text-lg font-black">ملخص الطلب</h3>
                        <div class="mt-5 space-y-3 text-sm font-bold">
                            <div class="flex justify-between gap-3 text-indigo-100"><span>القصص</span><span data-stories-total>٠ ج.م</span></div>
                            <div class="flex justify-between gap-3 text-indigo-100"><span>المنتجات</span><span data-products-total>٠ ج.م</span></div>
                            <div class="flex justify-between gap-3 text-indigo-100"><span>التوصيل</span><span data-delivery-total>٠ ج.م</span></div>
                            <div class="flex justify-between gap-3 text-rose-200"><span>الخصم</span><span data-discount-total>٠ ج.م</span></div>
                            <div class="flex justify-between gap-3 border-t border-indigo-700 pt-4 text-xl font-black"><span>الإجمالي</span><span data-grand-total>٠ ج.م</span></div>
                            <div class="flex justify-between gap-3 border-t border-indigo-800 pt-3 text-emerald-200"><span>المدفوع</span><span data-paid-total>٠ ج.م</span></div>
                            <div class="flex justify-between gap-3 text-rose-200"><span>المتبقي عند الاستلام</span><span data-remaining-total>٠ ج.م</span></div>
                        </div>
                        <p class="mt-4 rounded-xl bg-white/10 px-3 py-2 text-xs font-bold leading-6 text-indigo-100">السعر النهائي يُعاد حسابه والتحقق منه في الخادم عند الحفظ.</p>
                        <button class="mt-5 w-full rounded-xl bg-white px-5 py-3.5 text-sm font-black text-indigo-800 transition hover:bg-indigo-50">حفظ وإنشاء الطلب</button>
                    </div>
                </aside>
            </form>
        </div>
    </div>

    @push('scripts')
        <script>
            (() => {
                const root = document.querySelector('[data-admin-order-form]');
                if (!root) return;

                const rows = root.querySelector('[data-story-rows]');
                const template = root.querySelector('[data-story-template]');
                const addButton = root.querySelector('[data-add-story]');
                const country = root.querySelector('[data-country-select]');
                const governorate = root.querySelector('[data-governorate-select]');
                let nextIndex = Math.max(0, ...Array.from(rows.querySelectorAll('[data-story-row]')).map(row => Number(row.dataset.storyIndex) || 0)) + 1;

                const money = cents => new Intl.NumberFormat('ar-EG', { maximumFractionDigits: 2 }).format(Math.max(0, cents) / 100) + ' ج.م';

                const refreshStoryLinks = () => {
                    const storyRows = Array.from(rows.querySelectorAll('[data-story-row]'));
                    storyRows.forEach((row, position) => {
                        row.querySelector('[data-story-number]').textContent = position + 1;
                    });

                    root.querySelectorAll('[data-story-link]').forEach(select => {
                        const selected = select.value || select.dataset.selectedValue || '';
                        select.innerHTML = '<option value="">اختر القصة</option>';
                        storyRows.forEach((row, position) => {
                            const child = row.querySelector('[data-child-name]')?.value.trim();
                            const option = document.createElement('option');
                            option.value = row.dataset.storyIndex;
                            option.textContent = `القصة ${position + 1}${child ? ' — ' + child : ''}`;
                            option.selected = String(selected) === String(option.value);
                            select.appendChild(option);
                        });
                        select.dataset.selectedValue = '';
                    });
                };

                const calculate = () => {
                    const storiesCents = Array.from(rows.querySelectorAll('[data-story-select]')).reduce((sum, select) => {
                        return sum + Number(select.selectedOptions[0]?.dataset.priceCents || 0);
                    }, 0);
                    const productsCents = Array.from(root.querySelectorAll('[data-product-row]')).reduce((sum, row) => {
                        const quantity = Math.max(0, Number(row.querySelector('[data-product-quantity]')?.value || 0));
                        const variant = row.querySelector('[data-product-variant]');
                        const unit = Number(variant?.selectedOptions[0]?.dataset.priceCents || row.dataset.basePriceCents || 0);
                        return sum + quantity * unit;
                    }, 0);
                    const deliveryCents = Number(governorate?.selectedOptions[0]?.dataset.feeCents || 0);
                    const discountCents = Math.round(Math.max(0, Number(root.querySelector('[data-discount-input]')?.value || 0)) * 100);
                    const totalCents = Math.max(0, storiesCents + productsCents + deliveryCents - discountCents);

                    root.querySelector('[data-stories-total]').textContent = money(storiesCents);
                    root.querySelector('[data-products-total]').textContent = money(productsCents);
                    root.querySelector('[data-delivery-total]').textContent = money(deliveryCents);
                    root.querySelector('[data-discount-total]').textContent = money(discountCents);
                    root.querySelector('[data-grand-total]').textContent = money(totalCents);
                    const paymentStatus = root.querySelector('[data-payment-status]')?.value || 'unpaid';
                    const paidInput = root.querySelector('[data-paid-amount]');
                    const methodInput = root.querySelector('[data-payment-method]');
                    const partialField = root.querySelector('[data-partial-payment-field]');
                    const methodField = root.querySelector('[data-payment-method-field]');
                    let paidCents = 0;
                    if (paymentStatus === 'partially_paid') paidCents = Math.round(Math.max(0, Number(paidInput?.value || 0)) * 100);
                    if (paymentStatus === 'paid_without_shipping') paidCents = Math.max(0, totalCents - deliveryCents);
                    if (paymentStatus === 'paid_in_full') paidCents = totalCents;
                    partialField.hidden = paymentStatus !== 'partially_paid';
                    methodField.hidden = paymentStatus === 'unpaid';
                    paidInput.required = paymentStatus === 'partially_paid';
                    methodInput.required = paymentStatus !== 'unpaid';
                    paidInput.disabled = paymentStatus !== 'partially_paid';
                    methodInput.disabled = paymentStatus === 'unpaid';
                    root.querySelector('[data-paid-total]').textContent = money(paidCents);
                    root.querySelector('[data-remaining-total]').textContent = money(totalCents - paidCents);
                };

                const bindRow = row => {
                    row.querySelector('[data-remove-story]')?.addEventListener('click', () => {
                        if (rows.querySelectorAll('[data-story-row]').length === 1) {
                            alert('يجب أن يحتوي الطلب على قصة واحدة على الأقل.');
                            return;
                        }
                        row.remove();
                        refreshStoryLinks();
                        calculate();
                    });
                    row.querySelector('[data-child-name]')?.addEventListener('input', refreshStoryLinks);
                    row.querySelector('[data-story-select]')?.addEventListener('change', calculate);
                    row.querySelector('[data-photo-input]')?.addEventListener('change', event => {
                        const files = Array.from(event.target.files || []);
                        const label = row.querySelector('[data-photo-names]');
                        if (files.length > 3) {
                            event.target.value = '';
                            label.textContent = 'الحد الأقصى 3 صور.';
                            label.classList.add('text-red-600');
                            return;
                        }
                        label.classList.remove('text-red-600');
                        label.textContent = files.length ? files.map(file => file.name).join('، ') : 'لم يتم اختيار صور.';
                    });
                };

                rows.querySelectorAll('[data-story-row]').forEach(bindRow);
                addButton.addEventListener('click', () => {
                    const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
                    rows.insertAdjacentHTML('beforeend', html);
                    bindRow(rows.lastElementChild);
                    refreshStoryLinks();
                    calculate();
                    rows.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });

                country?.addEventListener('change', () => {
                    const countryId = country.value;
                    Array.from(governorate.options).forEach(option => {
                        if (!option.value) return;
                        const visible = option.dataset.countryId === countryId;
                        option.hidden = !visible;
                        option.disabled = !visible;
                    });
                    if (governorate.selectedOptions[0]?.disabled) governorate.value = '';
                    calculate();
                });
                governorate?.addEventListener('change', calculate);
                root.querySelectorAll('[data-product-quantity], [data-product-variant], [data-discount-input], [data-paid-amount]').forEach(input => input.addEventListener('input', calculate));
                root.querySelectorAll('[data-product-variant]').forEach(input => input.addEventListener('change', calculate));
                root.querySelector('[data-payment-status]')?.addEventListener('change', calculate);

                country?.dispatchEvent(new Event('change'));
                refreshStoryLinks();
                calculate();
            })();
        </script>
    @endpush
</x-admin-layout>
