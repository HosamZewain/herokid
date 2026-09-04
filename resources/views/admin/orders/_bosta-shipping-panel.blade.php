@can('bosta.view')
    @php
        $latestPickup = $bostaShipment?->pickups?->sortByDesc('created_at')->first();
        $reference = $group['short_reference'] ?: $group['key'];
    @endphp

    <section id="order-bosta-shipping" class="rounded-3xl border border-sky-100 bg-white p-5 shadow-sm sm:p-6" data-order-page-section="shipping">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-lg font-black text-gray-900">شحن Bosta</h3>
                    @if($bostaShipment)
                        <span class="rounded-full px-3 py-1 text-xs font-black {{ $bostaShipment->creation_status === 'created' ? 'bg-emerald-100 text-emerald-800' : ($bostaShipment->creation_status === 'failed' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800') }}">
                            {{ $bostaShipment->creation_status === 'created' ? 'تم إنشاء الشحنة' : ($bostaShipment->creation_status === 'failed' ? 'فشل إنشاء الشحنة' : 'جارٍ إنشاء الشحنة') }}
                        </span>
                    @elseif($bostaEligible)
                        <span class="rounded-full bg-sky-100 px-3 py-1 text-xs font-black text-sky-800">جاهز لإنشاء الشحنة</span>
                    @else
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-black text-gray-600">غير جاهز للشحن</span>
                    @endif
                </div>
                <p class="mt-1 text-xs font-bold leading-6 text-gray-500">تُنشأ شحنة واحدة لكل عملية شراء كاملة. مبلغ COD تشغيلي لدى Bosta ولا يُسجل كدفعة داخل HeroKid.</p>
            </div>
            <a href="{{ route('admin.bosta.index') }}" class="inline-flex min-h-10 items-center justify-center rounded-xl border border-sky-200 bg-sky-50 px-4 py-2 text-xs font-black text-sky-800 hover:bg-sky-100">فتح إدارة Bosta</a>
        </div>

        @if($bostaShipment)
            <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl bg-slate-50 p-4">
                    <p class="text-xs font-bold text-gray-400">رقم الطلب</p>
                    <p class="mt-1 font-mono text-sm font-black text-gray-900" dir="ltr">{{ $reference }}</p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4">
                    <p class="text-xs font-bold text-gray-400">رقم التتبع</p>
                    <p class="mt-1 font-mono text-sm font-black text-gray-900" dir="ltr">{{ $bostaShipment->tracking_number ?: '—' }}</p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4">
                    <p class="text-xs font-bold text-gray-400">حالة Bosta</p>
                    <p class="mt-1 text-sm font-black text-gray-900">{{ $bostaShipment->shipping_status ?: ($bostaShipment->state_code !== null ? 'كود '.$bostaShipment->state_code : 'لم يصل تحديث بعد') }}</p>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4">
                    <p class="text-xs font-bold text-gray-400">COD تشغيلي</p>
                    <p class="mt-1 text-sm font-black text-gray-900">{{ format_money($bostaShipment->cod_amount_cents / 100) }}</p>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                @if($latestPickup)
                    <span class="rounded-xl bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-800">Pickup: {{ $latestPickup->scheduled_date?->format('d/m/Y') }} · {{ $latestPickup->status }}</span>
                @else
                    <span class="rounded-xl bg-gray-50 px-3 py-2 text-xs font-bold text-gray-500">لم تتم إضافتها إلى Pickup بعد.</span>
                @endif

                @can('bosta.print_awb')
                    @if($bostaShipment->tracking_number)
                        <form method="POST" action="{{ route('admin.bosta.awb') }}" target="_blank">
                            @csrf
                            <input type="hidden" name="shipments[]" value="{{ $bostaShipment->id }}">
                            <button class="rounded-xl bg-slate-900 px-4 py-2 text-xs font-black text-white hover:bg-black">فتح بوليصة AWB</button>
                        </form>
                    @endif
                @endcan
            </div>

            @if($bostaShipment->creation_status === 'failed' && $bostaShipment->last_error)
                <p class="mt-4 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-xs font-bold text-red-700">فشل آخر اتصال مع Bosta. يمكنك إعادة المحاولة بعد مراجعة الإعدادات.</p>
            @endif
        @elseif($bostaEligible)
            <div class="mt-5 rounded-2xl border border-sky-100 bg-sky-50 p-4">
                <p class="text-sm font-black text-sky-950">كل عناصر عملية الشراء حالتها «جاهز للشحن».</p>
                <p class="mt-1 text-xs font-bold text-sky-700">راجع البيانات التالية وعدّلها عند الحاجة قبل إرسالها إلى Bosta.</p>

                @can('bosta.create_shipment')
                    <details class="mt-4 rounded-2xl border border-sky-200 bg-white p-4" @if($errors->hasAny(['receiver_name', 'receiver_phone', 'governorate', 'district_name', 'first_line', 'second_line', 'cod_amount', 'order'])) open @endif>
                        <summary class="cursor-pointer select-none text-sm font-black text-indigo-700">مراجعة بيانات الشحنة وإنشاؤها</summary>
                        <form method="POST" action="{{ route('admin.bosta.shipments.store', $group['representative_id']) }}" class="mt-5 space-y-4">
                            @csrf
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="text-xs font-black text-gray-700">اسم المستلم
                                    <input name="receiver_name" value="{{ old('receiver_name', $group['customer_name']) }}" required maxlength="120" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                                </label>
                                <label class="text-xs font-black text-gray-700">رقم الهاتف
                                    <input name="receiver_phone" value="{{ old('receiver_phone', $group['phone']) }}" required maxlength="30" dir="ltr" class="mt-1 w-full rounded-xl border-gray-200 text-left text-sm">
                                </label>
                                <label class="text-xs font-black text-gray-700">المحافظة
                                    <input name="governorate" value="{{ old('governorate', data_get($group['delivery'], 'governorate')) }}" required maxlength="120" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                                </label>
                                <label class="text-xs font-black text-gray-700">المدينة / المنطقة
                                    <input name="district_name" value="{{ old('district_name', data_get($group['delivery'], 'city', data_get($group['delivery'], 'area'))) }}" required maxlength="160" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                                </label>
                                <label class="text-xs font-black text-gray-700 md:col-span-2">العنوان الرئيسي
                                    <input name="first_line" value="{{ old('first_line', data_get($group['delivery'], 'street', data_get($group['delivery'], 'address'))) }}" required maxlength="500" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                                </label>
                                <label class="text-xs font-black text-gray-700">تفاصيل إضافية للعنوان
                                    <input name="second_line" value="{{ old('second_line', data_get($group['delivery'], 'address_details')) }}" maxlength="500" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                                </label>
                                <label class="text-xs font-black text-gray-700">COD لدى Bosta (ج.م)
                                    <input type="number" name="cod_amount" value="{{ old('cod_amount', number_format($group['remaining_amount_cents'] / 100, 2, '.', '')) }}" min="0" max="9999999.99" step="0.01" required dir="ltr" class="mt-1 w-full rounded-xl border-gray-200 text-left text-sm">
                                    <span class="mt-1 block text-[10px] font-bold text-amber-700">معلومة تشغيلية للشحن فقط ولا تُضاف إلى مدفوعات HeroKid.</span>
                                </label>
                            </div>
                            <div class="rounded-xl bg-gray-50 px-4 py-3 text-xs font-bold text-gray-600">نوع الطرد: {{ config('bosta.default_package_type') }} · فتح الطرد: {{ config('bosta.allow_open_package') ? 'مسموح' : 'غير مسموح' }}</div>
                            <button class="min-h-11 w-full rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-black text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50" @disabled(!$bostaConfigured)>تأكيد وإنشاء شحنة Bosta</button>
                        </form>
                    </details>
                @endcan
            </div>
            @if(!$bostaConfigured)
                <p class="mt-3 text-xs font-black text-amber-700">تكامل Bosta يحتاج استكمال إعدادات البيئة قبل إنشاء الشحنة.</p>
            @endif
        @else
            <p class="mt-5 rounded-2xl bg-gray-50 px-4 py-3 text-sm font-bold text-gray-600">لن يظهر هذا الطلب ضمن قائمة Bosta ولن يمكن إنشاء شحنة له حتى تصبح حالة الشحن لكل عناصر عملية الشراء «جاهز للشحن».</p>
        @endif
    </section>
@endcan
