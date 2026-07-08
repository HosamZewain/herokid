@php
    $customerPhone = \App\Support\Phone::normalize($order->delivery_details['phone'] ?? null);
    $customerKey = $order->user && $order->user->role !== 'admin'
        ? 'user-' . $order->user->id
        : 'guest-' . sha1($customerPhone ?: 'order-' . $order->id);
@endphp

<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            تفاصيل الطلب
            <a href="{{ route('admin.orders.show', $order) }}"
               class="text-gray-800 hover:text-indigo-600 hover:underline transition">
                #{{ $order->order_number }}
            </a>
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-700 px-5 py-4 rounded-xl font-bold flex items-center gap-2">
                ✅ {{ session('success') }}
            </div>
            @endif

            <!-- Top Bar -->
            <div class="flex items-center justify-between">
                <a href="{{ route('admin.orders.index') }}" class="text-gray-600 border border-gray-200 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-bold flex items-center gap-1">
                    ← العودة للطلبات
                </a>
                @php
                    $statusColors = [
                        'new'                => 'bg-orange-100 text-orange-700 border-orange-200',
                        'under_review'       => 'bg-blue-100 text-blue-700 border-blue-200',
                        'generating'         => 'bg-purple-100 text-purple-700 border-purple-200',
                        'preview_uploaded'   => 'bg-indigo-100 text-indigo-700 border-indigo-200',
                        'approved_for_print' => 'bg-teal-100 text-teal-700 border-teal-200',
                        'printing'           => 'bg-yellow-100 text-yellow-700 border-yellow-200',
                        'shipped'            => 'bg-sky-100 text-sky-700 border-sky-200',
                        'delivered'          => 'bg-green-100 text-green-700 border-green-200',
                        'cancelled'          => 'bg-red-100 text-red-700 border-red-200',
                    ];
                    $statusColor = $statusColors[$order->status] ?? 'bg-gray-100 text-gray-700 border-gray-200';
                @endphp
                <span class="px-4 py-2 rounded-full text-sm font-extrabold border {{ $statusColor }}">
                    {{ __('order_status.' . $order->status) }}
                </span>
            </div>

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
                                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $order->delivery_details['phone'] ?? '') }}" target="_blank" class="text-green-600 font-bold hover:underline dir-ltr">{{ $order->delivery_details['phone'] ?? '-' }}</a>
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
                        @endif
                    </div>
                    @endif

                    <!-- Delivery Info -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-lg font-bold mb-4 text-right border-b pb-3">عنوان التوصيل</h3>
                        @php
                            $delivery = $order->delivery_details ?? [];
                        @endphp
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm text-right text-gray-700">
                            <div><span class="font-bold text-gray-600">الدولة:</span> {{ $delivery['country'] ?? 'Egypt' }}</div>
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
                                <div><span class="font-bold text-gray-600">إجمالي السلة:</span> {{ number_format((float) ($order->delivery_details['total'] ?? 0), 0) }} ج.م</div>
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
                                                <p class="font-black text-gray-900">{{ $orderItem->title }}</p>
                                                @if($orderItem->variant_snapshot)
                                                    <p class="text-xs text-gray-500">النوع: {{ $orderItem->variant_snapshot['name_ar'] ?? '-' }}</p>
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
                        <h3 class="text-lg font-bold mb-4 text-right border-b pb-3">📸 صور الطفل المرفقة</h3>
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
                    </div>
                    @endcan

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
                            <p class="mb-3 text-right text-xs text-gray-400">آخر تحديث للقالب العام: {{ $productionPromptTemplateSetting->updated_at?->format('Y-m-d H:i') }}</p>
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
                                                {{ $snapshot->created_at->format('Y-m-d H:i') }} — {{ $snapshot->snapshot_reason ?? 'manual' }}
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
                            <div class="flex items-start gap-3 text-right">
                                <div class="text-xs text-gray-400 flex-shrink-0 mt-1 w-24 text-left">{{ $log->created_at->format('d/m/Y') }}</div>
                                <div class="flex-grow">
                                    <span class="text-sm font-bold text-gray-800">{{ __('order_status.' . $log->status) }}</span>
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
                                <span class="text-xs text-gray-400">{{ $preview->created_at->format('d/m/Y H:i') }}</span>
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
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-base font-bold mb-4 text-right">تحديث حالة الطلب</h3>
                        <form action="{{ route('admin.orders.update', $order) }}" method="POST" class="space-y-4">
                            @csrf
                            @method('PATCH')
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5 text-right">الحالة</label>
                                <select name="status" class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-xl shadow-sm text-right">
                                    <option value="new" @selected($order->status == 'new')>📦 طلب جديد</option>
                                    <option value="under_review" @selected($order->status == 'under_review')>🔍 قيد المراجعة</option>
                                    <option value="generating" @selected($order->status == 'generating')>🤖 جاري التوليد</option>
                                    <option value="preview_uploaded" @selected($order->status == 'preview_uploaded')>👁️ تصميم تم رفعه</option>
                                    <option value="approved_for_print" @selected($order->status == 'approved_for_print')>✅ موافق للطباعة</option>
                                    <option value="printing" @selected($order->status == 'printing')>🖨️ جاري الطباعة</option>
                                    <option value="shipped" @selected($order->status == 'shipped')>🚚 تم الشحن</option>
                                    <option value="delivered" @selected($order->status == 'delivered')>🏠 تم التوصيل</option>
                                    <option value="cancelled" @selected($order->status == 'cancelled')>❌ ملغي</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5 text-right">ملاحظة داخلية (اختياري)</label>
                                <textarea name="admin_notes" rows="3" class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-xl shadow-sm text-right text-sm"
                                    placeholder="ملاحظة تضاف لسجل الحالة...">{{ old('admin_notes') }}</textarea>
                            </div>
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 rounded-xl transition">
                                تحديث الحالة
                            </button>
                        </form>
                    </div>
                    @endcan

                    <!-- Upload Preview -->
                    @can('orders.preview.upload')
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-base font-bold mb-4 text-right">رفع تصميم للعميل (Preview)</h3>
                        <form action="{{ route('admin.orders.upload-preview', $order) }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                            @csrf
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5 text-right">ملف التصميم (PDF أو صورة)</label>
                                <input type="file" name="preview_file" accept="image/*,.pdf" required
                                    class="block w-full text-sm text-gray-500 file:ml-2 file:py-2 file:px-4 file:rounded-xl file:border-0 file:font-bold file:bg-indigo-600 file:text-white hover:file:bg-indigo-700 cursor-pointer">
                                <x-input-error :messages="$errors->get('preview_file')" class="mt-1"/>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5 text-right">ملاحظة للعميل (اختياري)</label>
                                <textarea name="preview_note" rows="2" class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-xl shadow-sm text-right text-sm"
                                    placeholder="أي تعليمات للعميل..."></textarea>
                            </div>
                            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-xl transition">
                                رفع التصميم وتحديث الحالة
                            </button>
                        </form>
                        <p class="text-xs text-gray-400 mt-2 text-right">سيتم تحديث حالة الطلب تلقائياً إلى "تصميم تم رفعه"</p>
                    </div>
                    @endcan

                    <!-- WhatsApp Quick Link -->
                    @if(isset($order->delivery_details['phone']))
                    <div class="bg-green-50 rounded-2xl border border-green-100 p-5">
                        <h3 class="font-bold text-green-800 text-sm mb-3 text-right">التواصل مع العميل</h3>
                        <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $order->delivery_details['phone']) }}?text=مرحبا، بخصوص طلبك {{ $order->order_number }}"
                            target="_blank"
                            class="w-full flex items-center justify-center gap-2 bg-green-500 hover:bg-green-600 text-white font-bold py-2.5 rounded-xl transition text-sm">
                            <span aria-hidden="true">💬</span>
                            واتساب العميل
                        </a>
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
</x-admin-layout>
