<x-admin-layout>
    @php
        $isEditing = isset($editingGroup);
        $orderForm = $orderForm ?? [];
        $formValue = fn (string $key, mixed $default = null): mixed => old($key, data_get($orderForm, $key, $default));
        $initialProducts = old('products', $initialProducts ?? []);
        $initialStories = old('stories', $initialStories ?? [[
            'story_id' => '', 'child_name' => '', 'child_age' => '', 'child_gender' => '',
            'interests' => '', 'gift_note' => '', 'parent_notes' => '',
        ]]);
    @endphp

    <x-slot name="header">
        <div class="text-right">
            <p class="text-xs font-black text-indigo-500">الطلبات</p>
            <h2 class="mt-1 text-xl font-black text-gray-900">{{ $isEditing ? 'تعديل الطلب بالكامل' : 'إضافة طلب' }}</h2>
            <p class="mt-1 text-xs font-bold text-gray-500">
                {{ $isEditing ? 'عدّل العميل والقصص والأطفال والصور والمنتجات والتوصيل والخصم والدفع من نموذج واحد.' : 'أنشئ عملية شراء كاملة لعميل تواصل معك خارج الموقع.' }}
            </p>
        </div>
    </x-slot>

    <div class="py-6" data-admin-order-form data-edit-mode="{{ $isEditing ? '1' : '0' }}" data-restored-package="{{ old('pricing_package_id') ? '1' : '0' }}">
        <div class="mx-auto max-w-7xl space-y-5 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between gap-3">
                <a href="{{ route('admin.orders.index') }}" class="rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-black text-gray-600 hover:bg-gray-50">العودة إلى الطلبات</a>
                <span class="rounded-full bg-amber-50 px-3 py-2 text-xs font-black text-amber-700">
                    {{ $isEditing ? 'تعديل آمن ومسجل — '.$editingGroup['key'] : 'إدخال يدوي بواسطة الإدارة' }}
                </span>
            </div>

            @if($errors->any())
                <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-bold text-red-800" role="alert">
                    <p class="mb-2 font-black">راجع البيانات التالية:</p>
                    @foreach($errors->all() as $message)<p>• {{ $message }}</p>@endforeach
                </div>
            @endif

            <form method="POST" action="{{ $isEditing ? route('admin.orders.groups.update', $representative->id) : route('admin.orders.store') }}" enctype="multipart/form-data" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]" data-order-form>
                @csrf
                @if($isEditing) @method('PUT') @endif

                <div class="space-y-5">
                    @if(! $isEditing && $pricingPackages->isNotEmpty())
                        <section class="rounded-3xl border border-amber-200 bg-gradient-to-l from-amber-50 to-white p-5 shadow-sm sm:p-6">
                            <div class="grid items-end gap-4 md:grid-cols-2">
                                <div class="text-right">
                                    <h3 class="text-lg font-black text-gray-950">إضافة باقة <span class="text-xs text-gray-400">(اختياري)</span></h3>
                                    <p class="mt-1 text-xs font-bold leading-6 text-gray-600">اختر الباقة أولًا؛ سنحدد عدد القصص ونضيف منتجاتها ونطبق سعرها الثابت تلقائيًا. المنتجات التي تزيدها لاحقًا تُحسب كإضافات خارج الباقة.</p>
                                    <div class="mt-3 hidden rounded-2xl bg-white px-4 py-3 text-xs font-bold text-amber-900 ring-1 ring-amber-200" data-package-description></div>
                                </div>
                                <div>
                                    <label for="pricing-package" class="mb-1.5 block text-xs font-black text-gray-700">الباقة</label>
                                    <select id="pricing-package" name="pricing_package_id" class="w-full rounded-xl border-amber-200 bg-white text-right text-sm" data-package-select>
                                        <option value="">بدون باقة — طلب عادي</option>
                                        @foreach($pricingPackages as $package)
                                            @php
                                                $packageItems = $package->items->map(fn ($item): array => [
                                                    'product_id' => (int) $item->product_id,
                                                    'variant_id' => $item->product_variant_id ? (int) $item->product_variant_id : null,
                                                    'quantity' => (int) $item->quantity,
                                                ])->values();
                                            @endphp
                                            <option
                                                value="{{ $package->id }}"
                                                data-story-count="{{ (int) $package->story_count }}"
                                                data-price-cents="{{ (int) round(((float) $package->price) * 100) }}"
                                                data-all-stories="{{ $package->applies_to_all_stories ? '1' : '0' }}"
                                                data-story-ids="{{ $package->eligibleStories->pluck('id')->implode(',') }}"
                                                data-items='@json($packageItems)'
                                                data-summary="{{ $package->componentSummary() }}"
                                                @selected((string) old('pricing_package_id') === (string) $package->id)
                                            >{{ $package->name }} — {{ format_money((float) $package->price) }}</option>
                                        @endforeach
                                    </select>
                                    @error('pricing_package_id')<p class="mt-1 text-xs font-bold text-red-600">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        </section>
                    @endif

                    <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-6">
                        <div class="mb-5 text-right">
                            <h3 class="text-lg font-black text-gray-950">بيانات العميل ومصدر الطلب</h3>
                            <p class="mt-1 text-xs font-bold text-gray-500">بيانات موحدة لكل القصص والمنتجات داخل هذه العملية.</p>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label for="parent-name" class="mb-1.5 block text-xs font-black text-gray-700">اسم ولي الأمر *</label>
                                <input id="parent-name" name="parent_name" value="{{ $formValue('parent_name') }}" required autocomplete="name" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            </div>
                            <div>
                                <label for="phone" class="mb-1.5 block text-xs font-black text-gray-700">رقم الهاتف / واتساب *</label>
                                <input id="phone" name="phone" value="{{ $formValue('phone') }}" required inputmode="tel" autocomplete="tel" dir="ltr" class="w-full rounded-xl border-gray-200 text-left text-sm">
                            </div>
                            <div>
                                <label for="order-source" class="mb-1.5 block text-xs font-black text-gray-700">مصدر الطلب *</label>
                                <select id="order-source" name="order_source" required class="w-full rounded-xl border-gray-200 bg-white text-right text-sm">
                                    <option value="">اختر المصدر</option>
                                    @foreach($sourceOptions as $value => $label)
                                        <option value="{{ $value }}" @selected($formValue('order_source') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="source-notes" class="mb-1.5 block text-xs font-black text-gray-700">تفاصيل المصدر <span class="font-normal text-gray-400">اختياري</span></label>
                                <input id="source-notes" name="source_notes" value="{{ $formValue('source_notes') }}" placeholder="مثال: رسالة على إنستجرام أو زيارة المعرض" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            </div>
                        </div>
                    </section>

                    <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-6">
                        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                            <button type="button" class="rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-black text-white hover:bg-violet-700" data-add-story>+ إضافة قصة أخرى</button>
                            <div class="text-right">
                                <h3 class="text-lg font-black text-gray-950">القصص وبيانات الأطفال <span class="text-xs text-gray-400">(اختياري)</span></h3>
                                <p class="mt-1 text-xs font-bold text-gray-500">يمكن إضافة عدة قصص لنفس الطفل أو لأطفال مختلفين، أو حذف كل القصص وإنشاء طلب منتجات فقط.</p>
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
                                <h3 class="text-lg font-black text-gray-950">منتجات وإضافات</h3>
                                <p class="mt-1 text-xs font-bold text-gray-500">يمكن إنشاء طلب منتجات فقط. عند اختيار منتج مخصص ستظهر حقوله وصوره المطلوبة تلقائيًا.</p>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2">
                                @foreach($products as $product)
                                    @php
                                        $productForm = $initialProducts[$product->id] ?? [];
                                        $quantity = (int) ($productForm['quantity'] ?? 0);
                                        $basePrice = isset($productForm['unit_price_cents'])
                                            ? (int) $productForm['unit_price_cents']
                                            : $product->effectivePriceCents();
                                    @endphp
                                    <article class="rounded-2xl border border-gray-100 bg-slate-50 p-4" data-product-row data-product-id="{{ $product->id }}" data-base-price-cents="{{ $basePrice }}">
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
                                                <input name="products[{{ $product->id }}][quantity]" type="number" min="0" max="{{ $product->personalization_mode === 'collect_child_details' ? 10 : 99 }}" value="{{ $quantity }}" class="w-full rounded-xl border-gray-200 text-center text-sm" data-product-quantity>
                                            </div>
                                            @if($product->activeVariants->isNotEmpty())
                                                <div>
                                                    <label class="mb-1 block text-[10px] font-black text-gray-500">الخيار</label>
                                                    <select name="products[{{ $product->id }}][variant_id]" class="w-full rounded-xl border-gray-200 bg-white text-right text-xs" data-product-variant>
                                                        <option value="" data-price-cents="{{ $basePrice }}">اختر</option>
                                                        @foreach($product->activeVariants as $variant)
                                                            @php
                                                                $variantPrice = (string) ($productForm['variant_id'] ?? '') === (string) $variant->id && isset($productForm['unit_price_cents'])
                                                                    ? (int) $productForm['unit_price_cents']
                                                                    : $product->effectivePriceCents($variant);
                                                            @endphp
                                                            <option value="{{ $variant->id }}" data-price-cents="{{ $variantPrice }}" @selected((string) ($productForm['variant_id'] ?? '') === (string) $variant->id)>{{ $variant->name_ar }} — {{ format_money($variantPrice / 100) }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            @else
                                                <div class="hidden"><input type="hidden" name="products[{{ $product->id }}][variant_id]" value=""></div>
                                            @endif
                                            @if($product->isPersonalizedAddon())
                                                <div>
                                                    <label class="mb-1 block text-[10px] font-black text-gray-500">القصة / الطفل</label>
                                                    <select name="products[{{ $product->id }}][linked_story_index]" class="w-full rounded-xl border-gray-200 bg-white text-right text-xs" data-story-link data-selected-value="{{ $productForm['linked_story_index'] ?? '' }}">
                                                        <option value="">اختر القصة</option>
                                                    </select>
                                                </div>
                                            @endif
                                        </div>
                                        @if($product->personalization_mode === 'collect_child_details')
                                            @include('admin.orders._manual-product-personalization', [
                                                'product' => $product,
                                                'productForm' => $productForm,
                                            ])
                                        @endif
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
                                        <option value="{{ $country->id }}" @selected((string) $formValue('delivery_country_id') === (string) $country->id)>{{ $country->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="governorate" class="mb-1.5 block text-xs font-black text-gray-700">المحافظة *</label>
                                <select id="governorate" name="delivery_governorate_id" required class="w-full rounded-xl border-gray-200 bg-white text-right text-sm" data-governorate-select>
                                    <option value="">اختر المحافظة</option>
                                    @foreach($countries as $country)
                                        @foreach($country->activeGovernorates as $governorate)
                                            @php
                                                $savedDeliveryCents = $isEditing && (string) $formValue('delivery_governorate_id') === (string) $governorate->id
                                                    ? (int) $editingGroup['delivery_cents']
                                                    : (int) round($governorate->effectiveDeliveryFee() * 100);
                                            @endphp
                                            <option value="{{ $governorate->id }}" data-country-id="{{ $country->id }}" data-fee-cents="{{ $savedDeliveryCents }}" @selected((string) $formValue('delivery_governorate_id') === (string) $governorate->id)>{{ $governorate->name }} — {{ format_money($savedDeliveryCents / 100) }}</option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="city" class="mb-1.5 block text-xs font-black text-gray-700">المدينة / المنطقة *</label>
                                <input id="city" name="city" value="{{ $formValue('city') }}" required autocomplete="address-level2" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            </div>
                            <div>
                                <label for="street" class="mb-1.5 block text-xs font-black text-gray-700">الشارع *</label>
                                <input id="street" name="street" value="{{ $formValue('street') }}" required autocomplete="street-address" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            </div>
                            <div class="md:col-span-2">
                                <label for="address-details" class="mb-1.5 block text-xs font-black text-gray-700">تفاصيل العنوان *</label>
                                <textarea id="address-details" name="address_details" rows="3" required class="w-full rounded-xl border-gray-200 text-right text-sm">{{ $formValue('address_details') }}</textarea>
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
                                <input id="discount" name="discount_amount" type="number" min="0" step="0.01" value="{{ $formValue('discount_amount', 0) }}" class="w-full rounded-xl border-gray-200 text-left text-sm" dir="ltr" data-discount-input>
                            </div>
                            <div>
                                <label for="discount-reason" class="mb-1.5 block text-xs font-black text-gray-700">سبب الخصم</label>
                                <input id="discount-reason" name="discount_reason" value="{{ $formValue('discount_reason') }}" placeholder="مطلوب عند إضافة خصم" class="w-full rounded-xl border-gray-200 text-right text-sm">
                            </div>
                            <div>
                                <label for="payment-status" class="mb-1.5 block text-xs font-black text-gray-700">حالة الدفع *</label>
                                <select id="payment-status" name="payment_status" required class="w-full rounded-xl border-gray-200 bg-white text-right text-sm" data-payment-status>
                                    @foreach($paymentStatuses as $value => $label)
                                        <option value="{{ $value }}" data-behavior="{{ \App\Support\OrderPaymentStatus::behavior($value) }}" @selected($formValue('payment_status', 'unpaid') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @php($selectedPaymentBehavior = \App\Support\OrderPaymentStatus::behavior($formValue('payment_status', 'unpaid')))
                            <div data-partial-payment-field @if($selectedPaymentBehavior !== 'partially_paid') hidden @endif>
                                <label for="paid-amount" class="mb-1.5 block text-xs font-black text-gray-700">المبلغ المدفوع بالجنيه *</label>
                                <input id="paid-amount" name="paid_amount" type="number" min="0.01" step="0.01" value="{{ $formValue('paid_amount') }}" class="w-full rounded-xl border-gray-200 text-left text-sm" dir="ltr" data-paid-amount @required($selectedPaymentBehavior === 'partially_paid') @disabled($selectedPaymentBehavior !== 'partially_paid')>
                            </div>
                            <div data-payment-method-field @if($selectedPaymentBehavior === 'unpaid') hidden @endif>
                                <label for="payment-method" class="mb-1.5 block text-xs font-black text-gray-700">طريقة الدفع *</label>
                                <select id="payment-method" name="payment_method" class="w-full rounded-xl border-gray-200 bg-white text-right text-sm" data-payment-method @required($selectedPaymentBehavior !== 'unpaid') @disabled($selectedPaymentBehavior === 'unpaid')>
                                    <option value="">اختر الطريقة</option>
                                    @foreach($paymentMethods as $method)<option value="{{ $method }}" @selected($formValue('payment_method') === $method)>{{ $method }}</option>@endforeach
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label for="admin-notes" class="mb-1.5 block text-xs font-black text-gray-700">ملاحظات إدارية داخلية <span class="font-normal text-gray-400">اختياري</span></label>
                                <textarea id="admin-notes" name="admin_notes" rows="3" class="w-full rounded-xl border-gray-200 text-right text-sm">{{ $formValue('admin_notes') }}</textarea>
                            </div>
                            @if($isEditing)
                                <div class="md:col-span-2 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                    <label for="change-reason" class="mb-1.5 block text-xs font-black text-amber-900">سبب تعديل الطلب *</label>
                                    <textarea id="change-reason" name="change_reason" rows="2" required minlength="5" maxlength="500" placeholder="مثال: طلب العميل تغيير القصة وإضافة كتاب متاهات" class="w-full rounded-xl border-amber-200 bg-white text-right text-sm">{{ old('change_reason') }}</textarea>
                                    <p class="mt-2 text-[11px] font-bold text-amber-700">سيُحفظ السبب مع تفاصيل ما أُضيف أو حُذف أو تغيّر في سجل النشاط.</p>
                                </div>
                            @endif
                        </div>
                    </section>
                </div>

                <aside class="xl:sticky xl:top-24 xl:self-start">
                    <div class="rounded-3xl border border-indigo-100 bg-indigo-950 p-5 text-white shadow-xl sm:p-6">
                        <h3 class="text-lg font-black">ملخص الطلب</h3>
                        <div class="mt-5 space-y-3 text-sm font-bold">
                            <div class="flex justify-between gap-3 text-indigo-100"><span>القصص</span><span data-stories-total>٠ ج.م</span></div>
                            <div class="flex justify-between gap-3 text-indigo-100"><span>المنتجات</span><span data-products-total>٠ ج.م</span></div>
                            <div class="hidden flex justify-between gap-3 text-amber-200" data-package-saving-row><span>توفير الباقة</span><span data-package-saving>٠ ج.م</span></div>
                            <div class="flex justify-between gap-3 text-indigo-100"><span>التوصيل</span><span data-delivery-total>٠ ج.م</span></div>
                            <div class="flex justify-between gap-3 text-rose-200"><span>الخصم</span><span data-discount-total>٠ ج.م</span></div>
                            <div class="flex justify-between gap-3 border-t border-indigo-700 pt-4 text-xl font-black"><span>الإجمالي</span><span data-grand-total>٠ ج.م</span></div>
                            <div class="flex justify-between gap-3 border-t border-indigo-800 pt-3 text-emerald-200"><span>المدفوع</span><span data-paid-total>٠ ج.م</span></div>
                            <div class="flex justify-between gap-3 text-rose-200"><span>المتبقي عند الاستلام</span><span data-remaining-total>٠ ج.م</span></div>
                        </div>
                        <p class="mt-4 rounded-xl bg-white/10 px-3 py-2 text-xs font-bold leading-6 text-indigo-100">السعر النهائي يُعاد حسابه والتحقق منه في الخادم عند الحفظ.</p>
                        <button class="mt-5 w-full rounded-xl bg-white px-5 py-3.5 text-sm font-black text-indigo-800 transition hover:bg-indigo-50">
                            {{ $isEditing ? 'حفظ كل تعديلات الطلب' : 'حفظ وإنشاء الطلب' }}
                        </button>
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
                const packageSelect = root.querySelector('[data-package-select]');
                const packageDescription = root.querySelector('[data-package-description]');
                const country = root.querySelector('[data-country-select]');
                const governorate = root.querySelector('[data-governorate-select]');
                let restoringPackage = root.dataset.restoredPackage === '1';
                let nextIndex = Math.max(0, ...Array.from(rows.querySelectorAll('[data-story-row]')).map(row => Number(row.dataset.storyIndex) || 0)) + 1;

                const money = cents => new Intl.NumberFormat('ar-EG', { maximumFractionDigits: 2 }).format(Math.max(0, cents) / 100) + ' ج.م';

                const selectedPackage = () => {
                    const option = packageSelect?.selectedOptions[0];
                    if (!option?.value) return null;

                    return {
                        id: Number(option.value),
                        name: option.textContent.split('—')[0].trim(),
                        storyCount: Number(option.dataset.storyCount || 0),
                        priceCents: Number(option.dataset.priceCents || 0),
                        allStories: option.dataset.allStories === '1',
                        storyIds: (option.dataset.storyIds || '').split(',').filter(Boolean),
                        items: JSON.parse(option.dataset.items || '[]'),
                        summary: option.dataset.summary || '',
                    };
                };

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
                    const manualDiscountCents = Math.round(Math.max(0, Number(root.querySelector('[data-discount-input]')?.value || 0)) * 100);
                    const activePackage = selectedPackage();
                    let packageRegularCents = 0;
                    if (activePackage) {
                        packageRegularCents += Array.from(rows.querySelectorAll('[data-story-select]'))
                            .slice(0, activePackage.storyCount)
                            .reduce((sum, select) => sum + Number(select.selectedOptions[0]?.dataset.priceCents || 0), 0);
                        activePackage.items.forEach(item => {
                            const productRow = root.querySelector(`[data-product-row][data-product-id="${item.product_id}"]`);
                            const variant = productRow?.querySelector('[data-product-variant]');
                            const unit = Number(variant?.selectedOptions[0]?.dataset.priceCents || productRow?.dataset.basePriceCents || 0);
                            packageRegularCents += unit * Number(item.quantity || 0);
                        });
                    }
                    const packageDiscountCents = activePackage ? Math.max(0, packageRegularCents - activePackage.priceCents) : 0;
                    const discountCents = manualDiscountCents + packageDiscountCents;
                    const totalCents = Math.max(0, storiesCents + productsCents + deliveryCents - discountCents);

                    root.querySelector('[data-stories-total]').textContent = money(storiesCents);
                    root.querySelector('[data-products-total]').textContent = money(productsCents);
                    root.querySelector('[data-delivery-total]').textContent = money(deliveryCents);
                    root.querySelector('[data-discount-total]').textContent = money(discountCents);
                    const savingRow = root.querySelector('[data-package-saving-row]');
                    savingRow?.classList.toggle('hidden', packageDiscountCents < 1);
                    root.querySelector('[data-package-saving]').textContent = money(packageDiscountCents);
                    root.querySelector('[data-grand-total]').textContent = money(totalCents);
                    const paymentSelect = root.querySelector('[data-payment-status]');
                    const paymentStatus = paymentSelect?.value || 'unpaid';
                    const paymentBehavior = paymentSelect?.selectedOptions[0]?.dataset.behavior || paymentStatus;
                    const paidInput = root.querySelector('[data-paid-amount]');
                    const methodInput = root.querySelector('[data-payment-method]');
                    const partialField = root.querySelector('[data-partial-payment-field]');
                    const methodField = root.querySelector('[data-payment-method-field]');
                    let paidCents = 0;
                    if (paymentBehavior === 'partially_paid') paidCents = Math.round(Math.max(0, Number(paidInput?.value || 0)) * 100);
                    if (paymentBehavior === 'paid_without_shipping') paidCents = Math.max(0, totalCents - deliveryCents);
                    if (paymentBehavior === 'paid_in_full') paidCents = totalCents;
                    partialField.hidden = paymentBehavior !== 'partially_paid';
                    methodField.hidden = paymentBehavior === 'unpaid';
                    paidInput.required = paymentBehavior === 'partially_paid';
                    methodInput.required = paymentBehavior !== 'unpaid';
                    paidInput.disabled = paymentBehavior !== 'partially_paid';
                    methodInput.disabled = paymentBehavior === 'unpaid';
                    root.querySelector('[data-paid-total]').textContent = money(paidCents);
                    root.querySelector('[data-remaining-total]').textContent = money(totalCents - paidCents);
                };

                const bindRow = row => {
                    row.querySelector('[data-remove-story]')?.addEventListener('click', () => {
                        row.remove();
                        const activePackage = selectedPackage();
                        if (activePackage) {
                            addButton.disabled = rows.querySelectorAll('[data-story-row]').length >= activePackage.storyCount;
                            addButton.classList.toggle('opacity-50', addButton.disabled);
                            addButton.classList.toggle('cursor-not-allowed', addButton.disabled);
                        }
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

                const bindProductRow = row => {
                    const quantity = row.querySelector('[data-product-quantity]');
                    const personalization = row.querySelector('[data-product-personalization]');

                    const refreshPersonalization = () => {
                        if (!personalization) return;
                        const count = Math.max(0, Math.min(10, Number(quantity?.value || 0)));
                        const selected = count > 0;
                        personalization.hidden = !selected;
                        personalization.querySelectorAll('[data-product-personalization-unit]').forEach((unit, index) => {
                            const active = index < count;
                            const reuse = unit.querySelector('[data-admin-reuse-first]')?.checked;
                            unit.hidden = !active;
                            unit.querySelectorAll('[data-admin-unit-field]').forEach(input => {
                                input.disabled = !active || reuse;
                                input.required = active && !reuse && input.dataset.required === '1';
                            });
                            const reuseInput = unit.querySelector('[data-admin-reuse-first]');
                            if (reuseInput) reuseInput.disabled = !active;
                        });
                    };

                    quantity?.addEventListener('input', refreshPersonalization);
                    row.querySelectorAll('[data-admin-reuse-first]').forEach(input => input.addEventListener('change', refreshPersonalization));
                    row.querySelectorAll('[data-product-photo-input]').forEach(input => input.addEventListener('change', event => {
                        const files = Array.from(event.target.files || []);
                        const maximum = Number(event.target.dataset.maxFiles || 3);
                        const label = event.target.closest('[data-product-personalization-unit]')?.querySelector('[data-product-photo-names]');

                        if (files.length > maximum) {
                            event.target.value = '';
                            label.textContent = `الحد الأقصى ${maximum} صور.`;
                            label.classList.add('text-red-600');
                            return;
                        }

                        label.classList.remove('text-red-600');
                        if (files.length) label.textContent = files.map(file => file.name).join('، ');
                    }));
                    refreshPersonalization();
                };

                const appendStoryRow = ({ scroll = false } = {}) => {
                    const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
                    rows.insertAdjacentHTML('beforeend', html);
                    bindRow(rows.lastElementChild);
                    refreshStoryLinks();
                    calculate();
                    if (scroll) rows.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'center' });
                };

                const applyPackage = () => {
                    root.querySelectorAll('[data-product-row]').forEach(row => {
                        const quantity = row.querySelector('[data-product-quantity]');
                        const previousMinimum = Number(row.dataset.packageMinimum || 0);
                        if (quantity && previousMinimum > 0) {
                            quantity.value = Math.max(0, Number(quantity.value || 0) - previousMinimum);
                            quantity.dispatchEvent(new Event('input'));
                        }
                        row.dataset.packageMinimum = '0';
                    });

                    const activePackage = selectedPackage();
                    const currentRows = () => Array.from(rows.querySelectorAll('[data-story-row]'));
                    if (!activePackage) {
                        currentRows().forEach(row => row.querySelectorAll('[data-story-select] option').forEach(option => {
                            option.hidden = false;
                            option.disabled = false;
                        }));
                        addButton.disabled = false;
                        addButton.classList.remove('opacity-50', 'cursor-not-allowed');
                        packageDescription?.classList.add('hidden');
                        calculate();
                        return;
                    }

                    while (currentRows().length < activePackage.storyCount) appendStoryRow();
                    if (activePackage.storyCount === 0 && currentRows().length === 1 && !currentRows()[0].querySelector('[data-story-select]').value) {
                        currentRows()[0].remove();
                    }

                    currentRows().forEach(row => {
                        const select = row.querySelector('[data-story-select]');
                        select.querySelectorAll('option').forEach(option => {
                            if (!option.value) return;
                            const allowed = activePackage.allStories || activePackage.storyIds.includes(String(option.value));
                            option.hidden = !allowed;
                            option.disabled = !allowed;
                        });
                        if (select.selectedOptions[0]?.disabled) select.value = '';
                    });

                    activePackage.items.forEach(item => {
                        const row = root.querySelector(`[data-product-row][data-product-id="${item.product_id}"]`);
                        const quantity = row?.querySelector('[data-product-quantity]');
                        if (!row || !quantity) return;
                        const minimum = Math.max(1, Number(item.quantity || 1));
                        if (! restoringPackage) quantity.value = Math.max(0, Number(quantity.value || 0)) + minimum;
                        row.dataset.packageMinimum = String(minimum);
                        const variant = row.querySelector('[data-product-variant]');
                        if (variant) variant.value = item.variant_id ? String(item.variant_id) : '';
                        quantity.dispatchEvent(new Event('input'));
                    });

                    addButton.disabled = currentRows().length >= activePackage.storyCount;
                    addButton.classList.toggle('opacity-50', addButton.disabled);
                    addButton.classList.toggle('cursor-not-allowed', addButton.disabled);
                    if (packageDescription) {
                        packageDescription.textContent = `${activePackage.summary} — سعر الباقة ${money(activePackage.priceCents)}. أدخل بيانات ${activePackage.storyCount} قصة بالضبط.`;
                        packageDescription.classList.remove('hidden');
                    }
                    refreshStoryLinks();
                    calculate();
                    restoringPackage = false;
                };

                rows.querySelectorAll('[data-story-row]').forEach(bindRow);
                root.querySelectorAll('[data-product-row]').forEach(bindProductRow);
                addButton.addEventListener('click', () => appendStoryRow({ scroll: true }));
                packageSelect?.addEventListener('change', applyPackage);

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
                if (packageSelect?.value) applyPackage();
                else calculate();
            })();
        </script>
    @endpush
</x-admin-layout>
