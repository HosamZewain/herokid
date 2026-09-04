@php
    $customerPhone = \App\Support\Phone::normalize($order->delivery_details['phone'] ?? null);
    $customerKey = $order->user && $order->user->role !== 'admin'
        ? 'user-' . $order->user->id
        : 'guest-' . sha1($customerPhone ?: 'order-' . $order->id);
@endphp

@php
    if (! isset($checkoutGroup)) {
        $fallbackItemsCents = (int) $order->items->sum('total_price_cents');
        if ($fallbackItemsCents === 0) {
            $fallbackItemsCents = (int) round((float) data_get(
                $order->delivery_details,
                'item_price',
                $order->story?->price ?? 0,
            ) * 100);
        }

        $fallbackDeliveryCents = (int) round(max(0, (float) data_get($order->delivery_details, 'delivery_fee', 0)) * 100);
        $fallbackDiscountCents = max(0, (int) ($order->discount_cents ?? 0));
        $fallbackTotalCents = max(0, $fallbackItemsCents + $fallbackDeliveryCents - $fallbackDiscountCents);
        $fallbackPaidCents = min($fallbackTotalCents, max(0, (int) ($order->paid_amount_cents ?? 0)));
        $fallbackPhone = (string) data_get($order->delivery_details, 'phone', '');
        $fallbackPaymentStatus = in_array($order->payment_status, \App\Support\OrderStatusRegistry::keys(\App\Support\OrderStatusRegistry::TYPE_PAYMENT, false), true)
            ? $order->payment_status
            : \App\Support\OrderPaymentStatus::UNPAID;

        $checkoutGroup = [
            'representative_id' => $order->id,
            'story_orders' => collect([$order]),
            'direct_products' => collect(),
            'add_ons' => collect(),
            'key' => $order->checkoutGroupKey(),
            'short_reference' => $order->checkoutReference?->short_reference,
            'order_numbers' => [$order->order_number],
            'created_at' => $order->created_at,
            'customer_name' => $order->parent_name ?: $order->user?->name ?: 'زائر',
            'phone' => $fallbackPhone,
            'delivery' => $order->delivery_details ?? [],
            'items_cents' => $fallbackItemsCents,
            'delivery_cents' => $fallbackDeliveryCents,
            'discount_cents' => $fallbackDiscountCents,
            'total_cents' => $fallbackTotalCents,
            'paid_amount_cents' => $fallbackPaidCents,
            'remaining_amount_cents' => max(0, $fallbackTotalCents - $fallbackPaidCents),
            'status' => $order->status ?: 'new',
            'payment_status' => $fallbackPaymentStatus,
            'payment_status_label' => \App\Support\OrderPaymentStatus::label($fallbackPaymentStatus),
            'payment_method' => $order->payment_method,
            'printing_status' => in_array($order->printing_status, \App\Support\OrderStatusRegistry::keys(\App\Support\OrderStatusRegistry::TYPE_PRINTING, false), true)
                ? $order->printing_status
                : \App\Support\OrderWorkflowStatus::PRINTING_NOT_STARTED,
            'shipping_status' => in_array($order->shipping_status, \App\Support\OrderStatusRegistry::keys(\App\Support\OrderStatusRegistry::TYPE_SHIPPING, false), true)
                ? $order->shipping_status
                : \App\Support\OrderWorkflowStatus::SHIPPING_NOT_READY,
        ];
    }
    $sceneTextHandoff ??= null;
    $orderPageReference = $checkoutGroup['short_reference'] ?: $order->order_number;
@endphp

<x-admin-layout>
    <x-slot name="title">{{ $orderPageReference }}</x-slot>
    <x-slot name="header">
        <div class="text-right">
            <p class="text-xs font-black text-indigo-500">تفاصيل الطلب</p>
            <a href="{{ route('admin.orders.show', $order) }}"
               class="mt-1 inline-block font-mono text-2xl font-black text-indigo-700 transition hover:text-indigo-900 hover:underline"
               dir="ltr">
                {{ $orderPageReference }}
            </a>
            <p class="mt-1 text-xs font-bold text-gray-500">تاريخ إنشاء الطلب: <span dir="ltr">{{ app_datetime($checkoutGroup['created_at'], 'd/m/Y h:i A') }}</span></p>
        </div>
    </x-slot>
    <x-slot name="headerActions">
        @include('admin.orders._activity-log-button')
    </x-slot>

    @include('admin.orders._activity-drawer', ['activityTargetOrder' => $order])

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-700 px-5 py-4 rounded-xl font-bold flex items-center gap-2">
                ✅ {{ session('success') }}
            </div>
            @endif

            @include('admin.orders._merge-checkout', ['mergeGroup' => $checkoutGroup])

            @include('admin.orders._related-customer-checkouts')

            <div class="rounded-2xl border border-sky-100 bg-white px-5 py-4 shadow-sm">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-sm font-black text-gray-900">مسؤول تنفيذ عملية الشراء</h3>
                        <p class="mt-1 text-xs font-bold text-gray-500">التعيين يشمل جميع القصص والمنتجات الموجودة في نفس الطلب.</p>
                    </div>
                    @include('admin.orders._assignment-controls', ['group' => $checkoutGroup])
                </div>
            </div>

            <!-- Top Bar -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.orders.groups.show', $checkoutGroup['representative_id']) }}" class="text-indigo-600 border border-indigo-100 bg-indigo-50 px-4 py-2 rounded-lg hover:bg-indigo-100 transition text-sm font-bold">
                        العودة لعملية الشراء كاملة
                    </a>
                    @can('orders.update')
                        <a href="{{ route('admin.orders.groups.edit', $checkoutGroup['representative_id']) }}" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-black text-white transition hover:bg-violet-700">
                            تعديل الطلب بالكامل
                        </a>
                    @endcan
                </div>
                @php
                    $statusColor = \App\Support\OrderStatusRegistry::color(\App\Support\OrderStatusRegistry::TYPE_ORDER, $order->status);
                @endphp
                <span class="px-4 py-2 rounded-full text-sm font-extrabold border {{ $statusColor }}">
                    {{ \App\Support\OrderStatusRegistry::label(\App\Support\OrderStatusRegistry::TYPE_ORDER, $order->status) }}
                </span>
            </div>

            @if($checkoutGroup['story_orders']->count() > 1)
                <div class="rounded-2xl border border-violet-100 bg-violet-50 p-4 text-right">
                    <p class="mb-3 text-xs font-black text-violet-700">قصص أخرى في نفس عملية الشراء {{ $checkoutGroup['short_reference'] ?: $checkoutGroup['key'] }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($checkoutGroup['story_orders'] as $sibling)
                            <a href="{{ route('admin.orders.show', $sibling) }}"
                               class="rounded-xl px-3 py-2 text-xs font-black {{ $sibling->is($order) ? 'bg-violet-600 text-white' : 'bg-white text-violet-700 hover:bg-violet-100' }}">
                                {{ $sibling->child_name ?: 'طلب متجر' }} — {{ $sibling->story?->title ?: $sibling->order_number }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            @include('admin.orders._checkout-products-summary', ['group' => $checkoutGroup])

            @include('admin.orders._payment-summary')

            @include('admin.orders._payment-history', ['paymentEvents' => $paymentEvents ?? collect()])

            @include('admin.orders._admin-notes', [
                'noteTargetOrder' => $order,
                'orderAdminNotes' => $orderAdminNotes ?? collect(),
            ])

            @include('admin.orders._attachments', [
                'attachmentTarget' => $order,
                'attachmentOrders' => $checkoutGroup['active_orders'] ?? collect([$order]),
            ])

            @if($order->childIdentityRequest)
                <div class="rounded-2xl border border-fuchsia-200 bg-gradient-to-l from-fuchsia-50 to-indigo-50 p-5 text-right">
                    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                        <div>
                            <p class="text-xs font-black text-fuchsia-700">هوية طفل مرتبطة بهذا الطلب</p>
                            <h3 class="mt-1 text-lg font-black text-slate-900">{{ $order->childIdentityRequest->child_name }} • المحاولة المعتمدة {{ $order->childIdentityApprovedAttempt?->attempt_number ?: '—' }}</h3>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $order->childIdentityRequest->photos->count() }} صور أصلية • {{ $order->childIdentityRequest->attempts->count() }} محاولات
                                @can('child_identities.view_costs')
                                    • التكلفة الداخلية {{ $order->childIdentityRequest->total_cost_usd !== null ? '$'.number_format((float) $order->childIdentityRequest->total_cost_usd, 6) : 'USD غير معروفة' }}
                                @endcan
                            </p>
                        </div>
                        @can('child_identities.view')
                            <a href="{{ route('admin.child-identities.show', $order->childIdentityRequest->id) }}" class="rounded-xl bg-fuchsia-600 px-5 py-3 text-sm font-black text-white">فتح سجل الهوية الكامل</a>
                        @endcan
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Left Column: Details -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- Child & Story Info -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold mb-5 text-right border-b pb-3">بيانات الطفل والقصة</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-right">
                            <div class="space-y-3">
                                <div>
                                    <span class="font-bold text-gray-600">اسم ولي الأمر:</span>
                                    @can('customers.view')
                                        <a href="{{ route('admin.customers.show', $customerKey) }}"
                                           class="text-gray-900 hover:text-indigo-600 hover:underline transition">
                                            {{ $order->parent_name ?? ($order->user->name ?? 'زائر') }}
                                        </a>
                                    @else
                                        <span class="text-gray-900">{{ $order->parent_name ?? ($order->user->name ?? 'زائر') }}</span>
                                    @endcan
                                </div>
                                <div><span class="font-bold text-gray-600">الهاتف / واتساب:</span>
                                    <a href="https://wa.me/{{ \App\Support\Phone::forWhatsApp($order->delivery_details['phone'] ?? '') }}" target="_blank" rel="noopener" class="text-green-600 font-bold hover:underline dir-ltr">{{ $order->delivery_details['phone'] ?? '-' }}</a>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <div><span class="font-bold text-gray-600">اسم الطفل:</span> <span class="text-gray-900 font-bold">{{ $order->child_name ?? '-' }}</span></div>
                                <div><span class="font-bold text-gray-600">العمر / الجنس:</span> <span>{{ $order->child_age ? $order->child_age . ' سنة' : '-' }} / {{ $order->child_gender == 'boy' ? '👦 ولد' : ($order->child_gender == 'girl' ? '👧 بنت' : '-') }}</span></div>
                                <div>
                                    <span class="font-bold text-gray-600">القصة:</span>
                                    @if($order->story)
                                        @can('stories.update')
                                        <a href="{{ route('admin.stories.edit', $order->story) }}"
                                           class="text-indigo-600 font-bold hover:text-indigo-800 hover:underline transition">
                                            {{ $order->story->title }}
                                        </a>
                                        @else
                                            <span class="text-indigo-600 font-bold">{{ $order->story->title }}</span>
                                        @endcan
                                    @else
                                        <span class="text-indigo-600 font-bold">-</span>
                                    @endif
                                </div>
                                <div><span class="font-bold text-gray-600">اللغة:</span> <span>{{ $order->language ? ($order->language == 'ar' ? 'عربي' : 'English') : '-' }}</span></div>
                            </div>
                        </div>
                        @if($order->interests || $order->parent_notes || $order->gift_note || $order->lesson)
                        <div class="mt-5 pt-5 border-t space-y-2 text-sm text-right">
                            @if($order->lesson) <div><span class="font-bold text-gray-600">الدرس:</span> {{ $order->lesson }}</div> @endif
                            @if($order->interests) <div><span class="font-bold text-gray-600">الاهتمامات:</span> {{ $order->interests }}</div> @endif
                            @if($order->gift_note) <div><span class="font-bold text-gray-600">الإهداء:</span> <em class="text-gray-700">"{{ $order->gift_note }}"</em></div> @endif
                            @if($order->parent_notes) <div><span class="font-bold text-gray-600">ملاحظات ولي الأمر:</span> {{ $order->parent_notes }}</div> @endif
                        </div>
                        @endif

                        @can('orders.update')
                            <details
                                class="mt-5 overflow-hidden rounded-2xl border border-indigo-100 bg-indigo-50/50"
                                @if($errors->hasAny(['parent_name', 'phone', 'child_name', 'child_age', 'child_gender', 'language', 'lesson', 'interests', 'gift_note', 'parent_notes', 'change_reason'])) open @endif
                            >
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-right">
                                    <div>
                                        <p class="font-black text-indigo-900">تعديل بيانات الطلب</p>
                                        <p class="mt-1 text-xs font-bold text-indigo-600">يُحدّث نصوص المشاهد وبرومبت الإنتاج وبيانات Production Studio الآمنة.</p>
                                    </div>
                                    <span class="rounded-full bg-white px-3 py-1 text-xs font-black text-indigo-700">تعديل</span>
                                </summary>

                                <form action="{{ route('admin.orders.details.update', $order) }}" method="POST" class="space-y-5 border-t border-indigo-100 bg-white p-4 sm:p-5">
                                    @csrf
                                    @method('PATCH')

                                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-right text-sm leading-6 text-amber-900">
                                        <p class="font-black">ما الذي سيحدث بعد الحفظ؟</p>
                                        <p class="mt-1">سيتم تحديث الطلب وعناصره ولقطات نصوص المشاهد والبرومبت الحالي. السجلات والبرومبتات التاريخية والأصول المولدة ستظل محفوظة، وأي محتوى إنتاج عُدّل يدويًا سيُعلّم للمراجعة بدل الكتابة فوقه.</p>
                                    </div>

                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <label for="order-parent-name" class="mb-1.5 block text-sm font-black text-gray-700">اسم ولي الأمر</label>
                                            <input id="order-parent-name" name="parent_name" type="text" required maxlength="150"
                                                value="{{ old('parent_name', $order->parent_name ?? $order->user?->name) }}"
                                                class="block w-full rounded-xl border-gray-300 text-right shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                            <x-input-error :messages="$errors->get('parent_name')" class="mt-1" />
                                        </div>
                                        <div>
                                            <label for="order-phone" class="mb-1.5 block text-sm font-black text-gray-700">الهاتف / واتساب</label>
                                            <input id="order-phone" name="phone" type="text" required maxlength="30" dir="ltr"
                                                value="{{ old('phone', data_get($order->delivery_details, 'phone')) }}"
                                                class="block w-full rounded-xl border-gray-300 text-left shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                            <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                                        </div>

                                        @if($order->story)
                                            <div>
                                                <label for="order-child-name" class="mb-1.5 block text-sm font-black text-gray-700">اسم الطفل</label>
                                                <input id="order-child-name" name="child_name" type="text" required maxlength="100"
                                                    value="{{ old('child_name', $order->child_name) }}"
                                                    class="block w-full rounded-xl border-gray-300 text-right shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <x-input-error :messages="$errors->get('child_name')" class="mt-1" />
                                            </div>
                                            <div>
                                                <label for="order-child-age" class="mb-1.5 block text-sm font-black text-gray-700">عمر الطفل</label>
                                                <input id="order-child-age" name="child_age" type="number" required min="1" max="18"
                                                    value="{{ old('child_age', $order->child_age) }}"
                                                    class="block w-full rounded-xl border-gray-300 text-right shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <x-input-error :messages="$errors->get('child_age')" class="mt-1" />
                                            </div>
                                            <div>
                                                <label for="order-child-gender" class="mb-1.5 block text-sm font-black text-gray-700">جنس الطفل</label>
                                                <select id="order-child-gender" name="child_gender" required
                                                    class="block w-full rounded-xl border-gray-300 text-right shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    <option value="boy" @selected(old('child_gender', $order->child_gender) === 'boy')>👦 ولد</option>
                                                    <option value="girl" @selected(old('child_gender', $order->child_gender) === 'girl')>👧 بنت</option>
                                                </select>
                                                <x-input-error :messages="$errors->get('child_gender')" class="mt-1" />
                                            </div>
                                            <div>
                                                <label for="order-language" class="mb-1.5 block text-sm font-black text-gray-700">لغة الإنتاج</label>
                                                <select id="order-language" name="language"
                                                    class="block w-full rounded-xl border-gray-300 text-right shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    <option value="ar" @selected(old('language', $order->language) === 'ar')>العربية</option>
                                                    <option value="en" @selected(old('language', $order->language) === 'en')>English</option>
                                                </select>
                                                <x-input-error :messages="$errors->get('language')" class="mt-1" />
                                            </div>
                                        @endif
                                    </div>

                                    @if($order->story)
                                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <div>
                                                <label for="order-lesson" class="mb-1.5 block text-sm font-black text-gray-700">الدرس / القيمة</label>
                                                <textarea id="order-lesson" name="lesson" rows="3" maxlength="500"
                                                    class="block w-full rounded-xl border-gray-300 text-right shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('lesson', $order->lesson) }}</textarea>
                                                <x-input-error :messages="$errors->get('lesson')" class="mt-1" />
                                            </div>
                                            <div>
                                                <label for="order-interests" class="mb-1.5 block text-sm font-black text-gray-700">اهتمامات الطفل</label>
                                                <textarea id="order-interests" name="interests" rows="3" maxlength="1000"
                                                    class="block w-full rounded-xl border-gray-300 text-right shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('interests', $order->interests) }}</textarea>
                                                <x-input-error :messages="$errors->get('interests')" class="mt-1" />
                                            </div>
                                            <div>
                                                <label for="order-gift-note" class="mb-1.5 block text-sm font-black text-gray-700">الإهداء</label>
                                                <textarea id="order-gift-note" name="gift_note" rows="3" maxlength="1000"
                                                    class="block w-full rounded-xl border-gray-300 text-right shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('gift_note', $order->gift_note) }}</textarea>
                                                <x-input-error :messages="$errors->get('gift_note')" class="mt-1" />
                                            </div>
                                            <div>
                                                <label for="order-parent-notes" class="mb-1.5 block text-sm font-black text-gray-700">ملاحظات ولي الأمر</label>
                                                <textarea id="order-parent-notes" name="parent_notes" rows="3" maxlength="2000"
                                                    class="block w-full rounded-xl border-gray-300 text-right shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('parent_notes', $order->parent_notes) }}</textarea>
                                                <x-input-error :messages="$errors->get('parent_notes')" class="mt-1" />
                                            </div>
                                        </div>
                                    @endif

                                    <div>
                                        <label for="order-change-reason" class="mb-1.5 block text-sm font-black text-gray-700">سبب التعديل <span class="text-red-600">*</span></label>
                                        <textarea id="order-change-reason" name="change_reason" rows="2" required minlength="5" maxlength="500"
                                            placeholder="مثال: العميل أرسل العمر الصحيح عبر واتساب"
                                            class="block w-full rounded-xl border-gray-300 text-right shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('change_reason') }}</textarea>
                                        <x-input-error :messages="$errors->get('change_reason')" class="mt-1" />
                                    </div>

                                    <button type="submit" class="w-full rounded-xl bg-indigo-600 px-5 py-3 font-black text-white transition hover:bg-indigo-700 sm:w-auto">
                                        حفظ ومزامنة كل البيانات
                                    </button>
                                </form>
                            </details>
                        @endcan
                    </div>

                    <!-- Production Studio -->
                    @if(config('production_studio.enabled') && auth()->user()->hasAnyPermission(['production_studio.view', 'production_studio.create_from_order']))
                    <div class="bg-white rounded-2xl shadow-sm border border-indigo-100 p-6">
                        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 border-b border-indigo-50 pb-4">
                            <div class="text-right">
                                <p class="text-xs font-black text-indigo-500">Production Studio</p>
                                <h3 class="text-lg font-bold text-gray-900">استوديو الإنتاج</h3>
                                <p class="mt-2 text-sm leading-6 text-gray-500">
                                    هذا ينشئ مشروع إنتاج داخلي منفصل ولا يؤثر على سير الطلب الحالي أو حالة الطلب أو برومبت الإنتاج الحالي.
                                </p>
                            </div>
                            @if($order->productionProject)
                                @can('production_studio.view')
                                    <a href="{{ route('admin.production-studio.show', $order->productionProject) }}"
                                       class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700">
                                        فتح مشروع الاستوديو
                                    </a>
                                @endcan
                            @else
                                @can('production_studio.create_from_order')
                                    <form action="{{ route('admin.production-studio.from-order', $order) }}" method="POST">
                                        @csrf
                                        <button class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700">
                                            إرسال إلى استوديو الإنتاج
                                        </button>
                                    </form>
                                @endcan
                            @endif
                        </div>

                        @if($order->productionProject)
                            <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3 text-right text-sm">
                                <div class="rounded-xl bg-indigo-50 p-4">
                                    <p class="text-xs font-bold text-indigo-500">حالة المشروع</p>
                                    <p class="mt-1 font-black text-gray-900">{{ $order->productionProject->statusLabel() }}</p>
                                </div>
                                <div class="rounded-xl bg-slate-50 p-4">
                                    <p class="text-xs font-bold text-slate-400">المرحلة الحالية</p>
                                    <p class="mt-1 font-black text-gray-900">{{ $order->productionProject->stageLabel() }}</p>
                                </div>
                                <div class="rounded-xl bg-slate-50 p-4">
                                    <p class="text-xs font-bold text-slate-400">المسؤول</p>
                                    <p class="mt-1 font-black text-gray-900">{{ $order->productionProject->assignedTo?->name ?? 'غير معين' }}</p>
                                </div>
                            </div>

                            @if($order->productionProject->personalization_status === 'needs_review')
                                <div class="mt-4 rounded-xl border-2 border-amber-300 bg-amber-50 p-4 text-right text-amber-950" role="alert">
                                    <p class="font-black">مشروع الإنتاج يحتاج مراجعة بيانات الطفل</p>
                                    <p class="mt-1 text-sm font-bold leading-6">تم تعديل بيانات الطلب بعد إنشاء المشروع. راجع النصوص والتوجيهات والأصول المولدة قبل تشغيل أي توليد جديد.</p>
                                    @if($order->productionProject->personalization_warnings)
                                        <ul class="mt-2 list-inside list-disc space-y-1 text-xs font-bold">
                                            @foreach($order->productionProject->personalization_warnings as $warning)
                                                <li>{{ $warning }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endif
                        @endif
                    </div>
                    @endif

                    @if($sceneTextHandoff)
                        @include('admin.orders._scene-text-handoff')
                    @endif

                    <!-- Delivery Info -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold mb-4 text-right border-b pb-3">عنوان التوصيل</h3>
                        @php
                            $delivery = $order->delivery_details ?? [];
                        @endphp
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm text-right text-gray-700">
                            <div><span class="font-bold text-gray-600">الدولة:</span> {{ $delivery['country'] ?? 'مصر' }}</div>
                            <div><span class="font-bold text-gray-600">المحافظة:</span> {{ $delivery['governorate'] ?? '-' }}</div>
                            <div><span class="font-bold text-gray-600">المدينة:</span> {{ $delivery['city'] ?? '-' }}</div>
                            <div><span class="font-bold text-gray-600">الشارع:</span> {{ $delivery['street'] ?? '-' }}</div>
                            <div class="md:col-span-2">
                                <span class="font-bold text-gray-600">تفاصيل العنوان:</span>
                                {{ $delivery['address_details'] ?? ($delivery['address'] ?? '-') }}
                            </div>
                        </div>
                        @if(!empty($order->delivery_details['checkout_group']))
                            <div class="mt-4 pt-4 border-t text-sm text-right text-gray-700 space-y-2">
                                <div><span class="font-bold text-gray-600">مجموعة السلة:</span> <span class="font-mono dir-ltr">{{ $order->delivery_details['checkout_group'] }}</span></div>
                                <div><span class="font-bold text-gray-600">ترتيب القصة في السلة:</span> {{ $order->delivery_details['cart_item_index'] ?? '-' }} / {{ $order->delivery_details['cart_items_count'] ?? '-' }}</div>
                                <div><span class="font-bold text-gray-600">سعر القصة:</span> {{ number_format((float) ($order->delivery_details['item_price'] ?? ($order->story->price ?? 0)), 0) }} ج.م</div>
                                <div><span class="font-bold text-gray-600">إجمالي القصص:</span> {{ number_format((float) ($order->delivery_details['subtotal'] ?? 0), 0) }} ج.م</div>
                                <div><span class="font-bold text-gray-600">مصاريف التوصيل:</span> {{ number_format((float) ($order->delivery_details['delivery_fee'] ?? 0), 0) }} ج.م</div>
                                @if((int) $order->discount_cents > 0)
                                    <div class="text-red-700"><span class="font-bold">الخصم:</span> - {{ format_money($order->discount_cents / 100) }} — {{ $order->discount_reason }}</div>
                                @endif
                                <div><span class="font-bold text-gray-600">مصدر الطلب:</span> {{ \App\Support\OrderSource::label($order->order_source) }}</div>
                                <div><span class="font-bold text-gray-600">إجمالي السلة:</span> {{ number_format((float) ($order->delivery_details['total'] ?? 0), 0) }} ج.م</div>
                                <div><span class="font-bold text-gray-600">حالة الدفع:</span> {{ $checkoutGroup['payment_status_label'] }}</div>
                                <div><span class="font-bold text-gray-600">المدفوع:</span> {{ format_money($checkoutGroup['paid_amount_cents'] / 100) }}</div>
                                <div class="text-red-700"><span class="font-bold">المتبقي عند الاستلام:</span> {{ format_money($checkoutGroup['remaining_amount_cents'] / 100) }}</div>
                                @if($checkoutGroup['payment_method'])<div><span class="font-bold text-gray-600">طريقة الدفع:</span> {{ $checkoutGroup['payment_method'] }}</div>@endif
                            </div>
                        @endif
                    </div>

                    @if($order->items->count())
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <h3 class="text-lg font-bold mb-4 text-right border-b pb-3">عناصر الطلب والمنتجات</h3>
                            <div class="space-y-3">
                                @foreach($order->items->whereNull('linked_order_item_id') as $orderItem)
                                    <div class="rounded-xl border border-gray-100 bg-slate-50 p-4 text-right">
                                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                                            <div>
                                                <p class="text-xs font-bold text-indigo-600">{{ $orderItem->item_type === 'story' ? 'قصة مخصصة' : 'منتج' }}</p>
                                                @if(data_get($orderItem->item_snapshot, 'package.name'))
                                                    <p class="mb-1 inline-flex rounded-full bg-fuchsia-50 px-2 py-1 text-[10px] font-black text-fuchsia-700">ضمن باقة: {{ data_get($orderItem->item_snapshot, 'package.name') }}</p>
                                                @endif
                                                <p class="font-black text-gray-900">{{ $orderItem->title }}</p>
                                                @if($orderItem->variant_snapshot)
                                                    <p class="text-xs text-gray-500">النوع: {{ $orderItem->variant_snapshot['name_ar'] ?? '-' }}</p>
                                                @endif
                                                @if($orderItem->personalization_mode === 'collect_child_details')
                                                    <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                                                        @foreach($orderItem->personalizationDisplayValues() as $value)
                                                            <div class="rounded-lg bg-white px-3 py-2">
                                                                <dt class="text-[11px] font-bold text-indigo-500">{{ $value['label'] }}</dt>
                                                                <dd class="mt-1 break-words text-sm font-black text-gray-900">{{ $value['value'] }}</dd>
                                                            </div>
                                                        @endforeach
                                                    </dl>
                                                @endif
                                            </div>
                                            <div class="text-sm font-bold text-gray-700">
                                                {{ $orderItem->quantity }} × {{ number_format($orderItem->unit_price_cents / 100, 0) }} ج.م
                                            </div>
                                        </div>

                                        @if($orderItem->linkedAddOns->count())
                                            <div class="mt-4 border-t border-indigo-100 pt-4">
                                                <p class="mb-2 text-xs font-black text-indigo-700">إضافات مرتبطة بهذا الطفل/القصة</p>
                                                <div class="space-y-2">
                                                    @foreach($orderItem->linkedAddOns as $addOn)
                                                        <div class="flex items-center justify-between rounded-lg bg-white px-3 py-2 text-sm">
                                                            <span class="font-bold text-gray-900">{{ $addOn->title }}</span>
                                                            <span class="text-gray-500">{{ $addOn->quantity }} × {{ number_format($addOn->unit_price_cents / 100, 0) }} ج.م</span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <!-- Child Photos -->
                    @can('orders.photos.view')
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <div class="mb-4 flex flex-col gap-2 border-b pb-3 text-right md:flex-row md:items-center md:justify-between">
                            <div>
                                <h3 class="text-lg font-bold">📸 صور الطفل المرفقة</h3>
                                <p class="mt-1 text-xs text-gray-500">كل الصور هنا تظهر تلقائياً داخل برومبت الإنتاج.</p>
                            </div>
                            <span class="text-xs font-black text-indigo-600">
                                {{ count($order->uploaded_photos ?? []) }} / {{ config('photo_uploads.admin_max_files', 10) }} صور
                            </span>
                        </div>
                        @if($order->uploaded_photos && count($order->uploaded_photos) > 0)
                        <div class="grid grid-cols-3 md:grid-cols-5 gap-3">
                            @foreach($order->uploaded_photos as $photo)
                            <div class="relative group">
                                <a href="{{ route('admin.orders.photo', [$order, $loop->index]) }}" target="_blank" class="block">
                                    <div class="aspect-square bg-gray-100 rounded-xl overflow-hidden">
                                        <img
                                            src="{{ route('admin.orders.photo', [$order, $loop->index]) }}"
                                            alt="صورة الطفل {{ $loop->iteration }}"
                                            class="w-full h-full object-cover transition group-hover:scale-105"
                                            loading="lazy"
                                        >
                                    </div>
                                    <p class="text-xs text-center text-gray-500 mt-1">صورة {{ $loop->iteration }}</p>
                                </a>
                            </div>
                            @endforeach
                        </div>
                        <p class="text-xs text-gray-400 mt-3 text-right">{{ count($order->uploaded_photos) }} صورة مرفقة — اضغط للعرض الكامل</p>
                        @else
                        <p class="text-gray-400 text-sm text-right">لا توجد صور مرفقة.</p>
                        @endif

                        @if(auth()->user()->hasPermission('orders.update'))
                            @php
                                $photoCount = count($order->uploaded_photos ?? []);
                                $remainingPhotoSlots = max(0, (int) config('photo_uploads.admin_max_files', 10) - $photoCount);
                            @endphp
                            <div class="mt-5 border-t border-gray-100 pt-5 text-right">
                                <h4 class="text-sm font-black text-gray-900">إضافة صور أوضح من العميل</h4>
                                <p class="mt-1 text-xs leading-5 text-gray-500">
                                    سيتم الاحتفاظ بالصور الحالية وإضافة الصور الجديدة إليها، وستظهر جميعها تلقائياً داخل برومبت الإنتاج.
                                    @if(config('production_studio.enabled'))
                                        الصور الجديدة تظهر أيضاً في استوديو الإنتاج، وتحتاج اعتمادها هناك قبل استخدامها كصور مرجعية للذكاء الاصطناعي.
                                    @endif
                                </p>

                                @if($remainingPhotoSlots > 0)
                                    <form action="{{ route('admin.orders.photos.store', $order) }}" method="POST" enctype="multipart/form-data" class="mt-4 space-y-3">
                                        @csrf
                                        <input
                                            type="file"
                                            name="photos[]"
                                            accept="image/jpeg,image/png,image/webp,image/heic,image/heif"
                                            multiple
                                            required
                                            class="block w-full rounded-xl border border-gray-200 bg-slate-50 px-4 py-3 text-sm text-gray-700 file:ml-4 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-black file:text-indigo-700 hover:file:bg-indigo-100"
                                        >
                                        <p class="text-xs text-gray-400">
                                            يمكنك إضافة {{ $remainingPhotoSlots }} صورة أخرى — JPG أو PNG أو WebP أو HEIC/HEIF، وبحد أقصى {{ config('photo_uploads.max_size_mb', 15) }} ميجا للصورة.
                                        </p>
                                        @if($errors->has('photos') || $errors->has('photos.*'))
                                            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">
                                                @foreach(array_merge($errors->get('photos'), $errors->get('photos.*')) as $message)
                                                    <p>{{ $message }}</p>
                                                @endforeach
                                            </div>
                                        @endif
                                        <button class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700">
                                            رفع وإضافة الصور إلى الطلب
                                        </button>
                                    </form>
                                @else
                                    <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-700">
                                        وصل الطلب إلى الحد الأقصى للصور. الحد الحالي {{ config('photo_uploads.admin_max_files', 10) }} صور.
                                    </p>
                                @endif
                            </div>
                        @endif
                    </div>
                    @endcan

                    <!-- Product Production Prompts -->
                    @can('orders.production_prompt.manage')
                        @include('admin.orders._product-production-prompts')
                    @endcan

                    <!-- Child Identity Prompt -->
                    @if($order->story)
                    @can('orders.production_prompt.manage')
                    <div class="rounded-2xl border border-fuchsia-100 bg-white p-6 shadow-sm">
                        <div class="mb-4 flex flex-col gap-3 border-b pb-3 md:flex-row md:items-center md:justify-between">
                            <div class="text-right">
                                <h3 class="text-lg font-bold">Child Identity Prompt</h3>
                                <p class="mt-1 text-xs font-bold text-gray-500">ينشئ هوية الطفل فقط قبل توليد المشاهد، ويستخدم سياق القصة لتحديد شكل بطلها</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if($order->childIdentityApprovedAttempt)
                                    <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-black text-emerald-700">هوية معتمدة ومربوطة بالطلب</span>
                                @else
                                    <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-black text-amber-700">بانتظار اعتماد الهوية</span>
                                @endif
                                @if($order->childIdentityPromptOverride)
                                    <span class="inline-flex items-center rounded-full border border-fuchsia-200 bg-fuchsia-50 px-3 py-1.5 text-xs font-black text-fuchsia-700">نسخة خاصة بالطلب</span>
                                @else
                                    <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-black text-slate-600">القالب الافتراضي</span>
                                @endif
                                <button
                                    type="button"
                                    id="copy-child-identity-prompt"
                                    class="inline-flex items-center justify-center rounded-xl bg-fuchsia-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-fuchsia-700 focus:outline-none focus:ring-2 focus:ring-fuchsia-500 focus:ring-offset-2"
                                >
                                    نسخ برومبت الهوية
                                </button>
                            </div>
                        </div>

                        <div class="mb-4 rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-right text-sm text-indigo-900">
                            <p class="font-black">ترتيب العمل الصحيح</p>
                            <p class="mt-1 leading-6">١) استخدم هذا البرومبت لتوليد هوية الطفل فقط. ٢) أرسل الهوية للعميل واعتمدها. ٣) بعد ربط الهوية المعتمدة بالطلب، سيضيفها برومبت الإنتاج الحالي تلقائيًا كمرجع بصري عند توليد المشاهد.</p>
                        </div>

                        <textarea
                            id="child-identity-prompt"
                            rows="24"
                            dir="ltr"
                            data-regenerate-url="{{ route('admin.orders.child-identity-prompt.regenerate', $order) }}"
                            data-regenerate-confirm="سيتم استبدال تعديلات برومبت الهوية بالنسخة الافتراضية المحدثة من بيانات الطلب. هل تريد المتابعة؟"
                            spellcheck="false"
                            class="block w-full rounded-xl border-gray-300 bg-fuchsia-50/30 text-left font-mono text-sm leading-6 text-slate-800 shadow-sm focus:border-fuchsia-500 focus:ring-fuchsia-500"
                        >{{ $childIdentityPrompt ?? '' }}</textarea>

                        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-4">
                            <button type="button" id="regenerate-child-identity-prompt" class="rounded-xl border border-fuchsia-200 bg-fuchsia-50 px-4 py-2.5 text-sm font-bold text-fuchsia-700 hover:bg-fuchsia-100">
                                إعادة إنشاء من بيانات الطلب
                            </button>
                            <form action="{{ route('admin.orders.child-identity-prompt.override', $order) }}" method="POST" data-child-identity-prompt-form>
                                @csrf
                                <input type="hidden" name="prompt_text">
                                <button class="w-full rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-700 hover:bg-amber-100">
                                    حفظ كبرومبت خاص
                                </button>
                            </form>
                            <form action="{{ route('admin.orders.child-identity-prompt.override-reset', $order) }}" method="POST" onsubmit="return confirm('سيتم حذف برومبت الهوية الخاص والرجوع للقالب الافتراضي. هل تريد المتابعة؟')">
                                @csrf
                                @method('DELETE')
                                <button class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-bold text-gray-700 hover:bg-gray-50">
                                    الرجوع للقالب الافتراضي
                                </button>
                            </form>
                            <form action="{{ route('admin.orders.child-identity-prompt.snapshot', $order) }}" method="POST" data-child-identity-prompt-form>
                                @csrf
                                <input type="hidden" name="prompt_text">
                                <input type="hidden" name="snapshot_reason" value="manual">
                                <button class="w-full rounded-xl border border-green-200 bg-green-50 px-4 py-2.5 text-sm font-bold text-green-700 hover:bg-green-100">
                                    حفظ نسخة الهوية
                                </button>
                            </form>
                        </div>

                        <div id="child-identity-prompt-copy-message" class="mt-3 hidden rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-right text-sm font-bold text-green-700" role="status" aria-live="polite">
                            تم نسخ برومبت هوية الطفل بنجاح
                        </div>

                        @if($order->childIdentityPromptSnapshots->count())
                            <div class="mt-6 rounded-xl border border-fuchsia-100 bg-fuchsia-50/40 p-4 text-right">
                                <h4 class="mb-3 text-sm font-black text-slate-800">نسخ برومبت الهوية المحفوظة</h4>
                                <div class="space-y-3">
                                    @foreach($order->childIdentityPromptSnapshots as $snapshot)
                                        <details class="rounded-lg bg-white p-3">
                                            <summary class="cursor-pointer text-sm font-bold text-slate-700">
                                                {{ app_datetime($snapshot->created_at, 'Y-m-d H:i') }} — v{{ $snapshot->prompt_version }} — {{ $snapshot->snapshot_reason ?? 'manual' }}
                                                @if($snapshot->creator)
                                                    — {{ $snapshot->creator->name }}
                                                @endif
                                            </summary>
                                            <textarea rows="10" readonly dir="ltr" class="mt-3 block w-full rounded-lg border-gray-200 bg-slate-50 text-left font-mono text-xs">{{ $snapshot->prompt_text }}</textarea>
                                        </details>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                    @endcan
                    @endif

                    <!-- Story Production Prompt -->
                    @can('orders.production_prompt.manage')
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4 border-b pb-3">
                            <div class="text-right">
                                <h3 class="text-lg font-bold">Story Production Prompt</h3>
                                <p class="mt-1 text-xs font-bold text-gray-500">تم إنشاؤه من قالب الإنتاج العام / Generated from the global production template</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if($order->productionPromptOverride)
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-3 py-1.5 text-xs font-black text-amber-700 border border-amber-200">Using Order-Specific Override</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-black text-emerald-700 border border-emerald-200">Using Global Template</span>
                                @endif
                                <button
                                    type="button"
                                    id="copy-production-prompt"
                                    class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                >
                                    Copy Prompt
                                </button>
                            </div>
                        </div>
                        @if($productionPromptTemplateSetting)
                            <p class="mb-3 text-right text-xs text-gray-400">آخر تحديث للقالب العام: {{ app_datetime($productionPromptTemplateSetting->updated_at, 'Y-m-d H:i') }}</p>
                        @endif
                        <textarea
                            id="story-production-prompt"
                            rows="24"
                            dir="ltr"
                            data-regenerate-url="{{ route('admin.orders.production-prompt.regenerate', $order) }}"
                            data-regenerate-confirm="سيتم استبدال التعديلات اليدوية بالنسخة الجديدة من القالب. هل تريد المتابعة؟"
                            spellcheck="false"
                            class="block w-full rounded-xl border-gray-300 bg-slate-50 text-left font-mono text-sm leading-6 text-slate-800 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >{{ $storyProductionPrompt }}</textarea>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-3">
                            <button type="button" id="regenerate-production-prompt" class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-bold text-indigo-700 hover:bg-indigo-100">
                                إعادة إنشاء من القالب الحالي
                            </button>
                            <form action="{{ route('admin.orders.production-prompt.override', $order) }}" method="POST" data-production-prompt-form>
                                @csrf
                                <input type="hidden" name="prompt_text">
                                <button class="w-full rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-700 hover:bg-amber-100">
                                    Save as Order-Specific Prompt
                                </button>
                            </form>
                            <form action="{{ route('admin.orders.production-prompt.override-reset', $order) }}" method="POST" onsubmit="return confirm('سيتم حذف البرومبت الخاص والرجوع للقالب العام. هل تريد المتابعة؟')">
                                @csrf
                                @method('DELETE')
                                <button class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-bold text-gray-700 hover:bg-gray-50">
                                    Reset to Global Template
                                </button>
                            </form>
                            <form action="{{ route('admin.orders.production-prompt.snapshot', $order) }}" method="POST" data-production-prompt-form>
                                @csrf
                                <input type="hidden" name="prompt_text">
                                <input type="hidden" name="snapshot_reason" value="manual">
                                <button class="w-full rounded-xl border border-green-200 bg-green-50 px-4 py-2.5 text-sm font-bold text-green-700 hover:bg-green-100">
                                    حفظ نسخة إنتاج
                                </button>
                            </form>
                        </div>
                        <div
                            id="production-prompt-copy-message"
                            class="mt-3 hidden rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-right text-sm font-bold text-green-700"
                            role="status"
                            aria-live="polite"
                        >
                            تم نسخ برومبت الإنتاج بنجاح
                        </div>
                        @if($order->productionPromptSnapshots->count())
                            <div class="mt-6 rounded-xl border border-slate-100 bg-slate-50 p-4 text-right">
                                <h4 class="mb-3 text-sm font-black text-slate-800">نسخ الإنتاج المحفوظة</h4>
                                <div class="space-y-3">
                                    @foreach($order->productionPromptSnapshots as $snapshot)
                                        <details class="rounded-lg bg-white p-3">
                                            <summary class="cursor-pointer text-sm font-bold text-slate-700">
                                                {{ app_datetime($snapshot->created_at, 'Y-m-d H:i') }} — {{ $snapshot->snapshot_reason ?? 'manual' }}
                                                @if($snapshot->creator)
                                                    — {{ $snapshot->creator->name }}
                                                @endif
                                            </summary>
                                            <textarea rows="10" readonly dir="ltr" class="mt-3 block w-full rounded-lg border-gray-200 bg-slate-50 text-left font-mono text-xs">{{ $snapshot->prompt_text }}</textarea>
                                        </details>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                    @endcan

                    <!-- Status History -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold mb-4 text-right border-b pb-3">سجل تاريخ الحالات</h3>
                        @if($order->statusLogs->count())
                        <div class="space-y-3">
                            @foreach($order->statusLogs->sortByDesc('created_at') as $log)
                            @php
                                $logType = $log->status_type ?: 'order';
                                $logLabel = match ($logType) {
                                    'printing' => \App\Support\OrderWorkflowStatus::printingLabel($log->status),
                                    'shipping' => \App\Support\OrderWorkflowStatus::shippingLabel($log->status),
                                    default => \App\Support\OrderStatusRegistry::label(\App\Support\OrderStatusRegistry::TYPE_ORDER, $log->status),
                                };
                                $logTypeLabel = ['order' => 'الطلب', 'printing' => 'الطباعة', 'shipping' => 'الشحن'][$logType] ?? 'الطلب';
                            @endphp
                            <div class="flex items-start gap-3 text-right">
                                <div class="text-xs text-gray-400 flex-shrink-0 mt-1 w-24 text-left">{{ app_datetime($log->created_at, 'd/m/Y') }}</div>
                                <div class="flex-grow">
                                    <span class="text-sm font-bold text-gray-800">{{ $logLabel }}</span>
                                    <span class="mr-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-black text-slate-500">{{ $logTypeLabel }}</span>
                                    @if($log->notes) <p class="text-xs text-gray-500 mt-0.5">{{ $log->notes }}</p> @endif
                                </div>
                                <div class="w-3 h-3 bg-indigo-400 rounded-full mt-1.5 flex-shrink-0"></div>
                            </div>
                            @endforeach
                        </div>
                        @else
                        <p class="text-gray-400 text-sm text-right">لا يوجد سجل حتى الآن.</p>
                        @endif
                    </div>

                    <!-- Uploaded Previews -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold mb-4 text-right border-b pb-3">🎨 التصميمات المرفوعة (Previews)</h3>
                        @if($order->previews->count())
                        <div class="space-y-3">
                            @foreach($order->previews as $preview)
                            <div class="flex items-center justify-between bg-slate-50 rounded-xl px-4 py-3 text-right">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-gray-400">{{ app_datetime($preview->created_at, 'd/m/Y H:i') }}</span>
                                    @if($order->story_id && !$order->bookletPreview && strtolower(pathinfo($preview->file_path, PATHINFO_EXTENSION)) === 'pdf')
                                        @can('orders.preview.upload')
                                            @can('booklet_previews.create')
                                                <form action="{{ route('admin.orders.previews.promote', [$order, $preview]) }}" method="POST">
                                                    @csrf
                                                    <button class="rounded-lg bg-indigo-600 px-2.5 py-1.5 text-[11px] font-black text-white">إنشاء رابط قارئ</button>
                                                </form>
                                            @endcan
                                        @endcan
                                    @endif
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-gray-800">تصميم #{{ $loop->iteration }}</p>
                                    @if($preview->note) <p class="text-xs text-gray-500">{{ $preview->note }}</p> @endif
                                </div>
                            </div>
                            @endforeach
                        </div>
                        @else
                        <p class="text-gray-400 text-sm text-right">لم يتم رفع أي تصميم بعد.</p>
                        @endif
                    </div>
                </div>

                <!-- Right Column: Actions -->
                <div class="space-y-6">

                    <!-- Update Status -->
                    @can('orders.update')
                    @if(isset($checkoutGroup['total_cents'], $checkoutGroup['delivery_cents']))
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <p class="mb-3 text-right text-xs font-bold text-gray-500">التعديل هنا يحدّث الحالات المشتركة لكل عناصر عملية الشراء.</p>
                        @include('admin.orders._workflow-status-panel', ['group' => $checkoutGroup])
                    </div>
                    @endif
                    @endcan

                    @include('admin.orders._bosta-shipping-panel', ['group' => $checkoutGroup])

                    <!-- Booklet Preview -->
                    @if($order->story_id)
                    @can('orders.preview.upload')
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        @php
                            $bookletPreview = $order->bookletPreview;
                            $bookletPublicUrl = $bookletPreview?->publicUrl();
                            $bookletScenesUrl = $bookletPreview?->publicScenesUrl();
                        @endphp
                        <h3 class="text-base font-bold mb-2 text-right">📖 معاينة الكتاب للعميل</h3>
                        <p class="mb-4 text-xs font-bold leading-6 text-gray-500 text-right">ارفع ملف PDF مرتب الصفحات لتحصل على رابط قارئ خاص. سيظل الرابط نفسه عند رفع نسخة مصححة.</p>

                        @if($bookletPreview)
                            <div class="mb-4 rounded-2xl border p-4 {{ $bookletPreview->status === 'active' ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50' }}">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-black {{ $bookletPreview->status === 'active' ? 'bg-emerald-600 text-white' : 'bg-rose-600 text-white' }}">{{ $bookletPreview->status === 'active' ? 'الرابط فعال' : 'الرابط موقوف' }}</span>
                                    <p class="text-sm font-black text-gray-900">الإصدار {{ $bookletPreview->currentVersion?->version_number }} · {{ $bookletPreview->currentVersion?->page_count }} صفحة</p>
                                </div>
                                @if($bookletPreview->status === 'active' && $bookletPublicUrl)
                                    <p class="mt-3 break-all text-left text-[10px] font-bold text-slate-500" dir="ltr">{{ $bookletPublicUrl }}</p>
                                    <div class="mt-3 grid grid-cols-2 gap-2">
                                        <a href="{{ $bookletPublicUrl }}" target="_blank" rel="noopener" class="rounded-xl bg-white px-3 py-2 text-center text-xs font-black text-indigo-700">قارئ التقليب</a>
                                        <a href="{{ $bookletScenesUrl }}" target="_blank" rel="noopener" class="rounded-xl bg-white px-3 py-2 text-center text-xs font-black text-violet-700">قارئ المشاهد</a>
                                        <button type="button" data-order-preview-copy="{{ $bookletPublicUrl }}" class="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-black text-white">نسخ رابط التقليب</button>
                                        <button type="button" data-order-preview-copy="{{ $bookletScenesUrl }}" class="rounded-xl bg-violet-600 px-3 py-2 text-xs font-black text-white">نسخ رابط المشاهد</button>
                                        <a href="https://wa.me/{{ \App\Support\Phone::forWhatsApp($order->delivery_details['phone'] ?? '') }}?text={{ urlencode('مرحبًا، يمكنك مشاهدة معاينة قصة HeroKid من الرابط التالي: '.$bookletPublicUrl) }}" target="_blank" rel="noopener" class="col-span-2 rounded-xl bg-emerald-600 px-3 py-2 text-center text-xs font-black text-white">إرسال عبر واتساب</a>
                                    </div>
                                @endif
                                @can('booklet_previews.view')
                                    <a href="{{ route('admin.booklet-previews.show', $bookletPreview) }}" class="mt-3 block text-center text-xs font-black text-slate-600 underline">إدارة الرابط والإصدارات</a>
                                @endcan

                                <details class="mt-3 rounded-xl border border-white/80 bg-white/70 p-3 text-right">
                                    <summary class="cursor-pointer text-xs font-black text-slate-700">سجل الإصدارات ({{ $bookletPreview->versions->count() }})</summary>
                                    <div class="mt-3 space-y-2">
                                        @foreach($bookletPreview->versions as $version)
                                            <div class="flex items-center justify-between gap-3 rounded-lg bg-white px-3 py-2 text-[11px] font-bold text-slate-600">
                                                <span>{{ app_datetime($version->created_at, 'd/m/Y H:i') }}</span>
                                                <span>الإصدار {{ $version->version_number }} · {{ $version->page_count }} صفحة @if($bookletPreview->current_version_id === $version->id)· الحالي@endif</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </details>

                                @can('booklet_previews.revoke')
                                    @if($bookletPreview->status === 'active')
                                        <form action="{{ route('admin.booklet-previews.revoke', $bookletPreview) }}" method="POST" class="mt-3 flex gap-2">
                                            @csrf
                                            <input name="reason" required minlength="3" class="min-w-0 flex-1 rounded-xl border-amber-200 bg-white text-xs" placeholder="سبب إيقاف الرابط">
                                            <button class="rounded-xl bg-amber-600 px-3 py-2 text-xs font-black text-white">إيقاف</button>
                                        </form>
                                    @else
                                        <form action="{{ route('admin.booklet-previews.reenable', $bookletPreview) }}" method="POST" class="mt-3">
                                            @csrf
                                            <button class="w-full rounded-xl bg-emerald-600 px-3 py-2 text-xs font-black text-white">إعادة تفعيل الرابط</button>
                                        </form>
                                    @endif
                                @endcan
                            </div>
                        @endif

                        @if(auth()->user()->hasPermission($bookletPreview ? 'booklet_previews.update' : 'booklet_previews.create'))
                            <form action="{{ route('admin.orders.booklet-preview.store', $order) }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                                @csrf
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-1.5 text-right">{{ $bookletPreview ? 'ملف PDF المصحح' : 'ملف PDF للمعاينة' }}</label>
                                    <input type="file" name="pdf_file" accept="application/pdf,.pdf" required
                                        class="block w-full text-sm text-gray-500 file:ml-2 file:py-2 file:px-4 file:rounded-xl file:border-0 file:font-bold file:bg-indigo-600 file:text-white hover:file:bg-indigo-700 cursor-pointer">
                                    <x-input-error :messages="$errors->get('pdf_file')" class="mt-1"/>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-1.5 text-right">ملاحظة الإصدار (اختياري)</label>
                                    <textarea name="note" rows="2" class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-xl shadow-sm text-right text-sm" placeholder="مثال: تعديل المشهد الخامس"></textarea>
                                </div>
                                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition">
                                    {{ $bookletPreview ? 'رفع الإصدار المصحح' : 'رفع المعاينة وإنشاء الرابط' }}
                                </button>
                            </form>
                            <p class="text-xs text-gray-400 mt-2 text-right">PDF فقط · حتى {{ config('booklet_previews.max_upload_mb', 50) }} ميجا · سيتم تحديث الحالة إلى "تصميم تم رفعه"</p>
                        @endif
                    </div>
                    @endcan
                    @endif

                    <!-- WhatsApp Quick Link -->
                    @if(!empty($whatsappMessages))
                    <div class="bg-green-50 rounded-2xl border border-green-100 p-5">
                        <h3 class="font-bold text-green-800 text-sm mb-3 text-right">رسائل واتساب للعميل</h3>
                        @include('admin.orders._whatsapp-message-actions', ['whatsappMessages' => $whatsappMessages])
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

@can('orders.production_prompt.manage')
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var copyButton = document.getElementById('copy-child-identity-prompt');
    var promptTextarea = document.getElementById('child-identity-prompt');
    var message = document.getElementById('child-identity-prompt-copy-message');
    var regenerateButton = document.getElementById('regenerate-child-identity-prompt');
    var originalPrompt = promptTextarea ? promptTextarea.value : '';

    if (!copyButton || !promptTextarea || !message) {
        return;
    }

    function showCopiedMessage() {
        message.classList.remove('hidden');
        window.HeroKidOrderActivity?.recordPromptCopy('child_identity');
        window.clearTimeout(showCopiedMessage.timeout);
        showCopiedMessage.timeout = window.setTimeout(function () {
            message.classList.add('hidden');
        }, 3000);
    }

    function fallbackCopy() {
        promptTextarea.focus();
        promptTextarea.select();
        document.execCommand('copy');
        window.getSelection().removeAllRanges();
    }

    copyButton.addEventListener('click', function () {
        var promptText = promptTextarea.value;

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(promptText).then(showCopiedMessage).catch(function () {
                fallbackCopy();
                showCopiedMessage();
            });

            return;
        }

        fallbackCopy();
        showCopiedMessage();
    });

    document.querySelectorAll('[data-child-identity-prompt-form]').forEach(function (form) {
        form.addEventListener('submit', function () {
            form.querySelector('input[name="prompt_text"]').value = promptTextarea.value;
        });
    });

    if (regenerateButton) {
        regenerateButton.addEventListener('click', function () {
            var isDirty = promptTextarea.value !== originalPrompt;

            if (isDirty && !window.confirm(promptTextarea.dataset.regenerateConfirm)) {
                return;
            }

            fetch(promptTextarea.dataset.regenerateUrl, {
                headers: {'Accept': 'application/json'}
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    promptTextarea.value = data.prompt || '';
                    originalPrompt = promptTextarea.value;
                });
        });
    }
});
</script>
@endpush
@endcan

@can('orders.production_prompt.manage')
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var copyButton = document.getElementById('copy-production-prompt');
    var promptTextarea = document.getElementById('story-production-prompt');
    var message = document.getElementById('production-prompt-copy-message');
    var regenerateButton = document.getElementById('regenerate-production-prompt');
    var originalPrompt = promptTextarea ? promptTextarea.value : '';

    if (!copyButton || !promptTextarea || !message) {
        return;
    }

    function showCopiedMessage() {
        message.classList.remove('hidden');
        window.HeroKidOrderActivity?.recordPromptCopy('story_production');
        window.clearTimeout(showCopiedMessage.timeout);
        showCopiedMessage.timeout = window.setTimeout(function () {
            message.classList.add('hidden');
        }, 3000);
    }

    function fallbackCopy() {
        promptTextarea.focus();
        promptTextarea.select();
        document.execCommand('copy');
        window.getSelection().removeAllRanges();
    }

    copyButton.addEventListener('click', function () {
        var promptText = promptTextarea.value;

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(promptText).then(showCopiedMessage).catch(function () {
                fallbackCopy();
                showCopiedMessage();
            });

            return;
        }

        fallbackCopy();
        showCopiedMessage();
    });

    document.querySelectorAll('[data-production-prompt-form]').forEach(function (form) {
        form.addEventListener('submit', function () {
            form.querySelector('input[name="prompt_text"]').value = promptTextarea.value;
        });
    });

    if (regenerateButton) {
        regenerateButton.addEventListener('click', function () {
            var isDirty = promptTextarea.value !== originalPrompt;

            if (isDirty && !window.confirm(promptTextarea.dataset.regenerateConfirm)) {
                return;
            }

            fetch(promptTextarea.dataset.regenerateUrl, {
                headers: {'Accept': 'application/json'}
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    promptTextarea.value = data.prompt || '';
                    originalPrompt = promptTextarea.value;
                });
        });
    }
});
</script>
@endpush
@endcan

@push('scripts')
<script>
document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-order-preview-copy]');
    if (!button) return;
    try {
        await navigator.clipboard.writeText(button.dataset.orderPreviewCopy);
        const original = button.textContent;
        button.textContent = 'تم النسخ ✓';
        setTimeout(() => button.textContent = original, 1800);
    } catch (_) {
        window.prompt('انسخ الرابط:', button.dataset.orderPreviewCopy);
    }
});
</script>
@endpush
</x-admin-layout>
