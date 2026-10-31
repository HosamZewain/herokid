@can('bosta.view')
    @php
        $latestPickup = $bostaShipment?->pickups?->sortByDesc('created_at')->first();
        $reference = $group['short_reference'] ?: $group['key'];
        $bostaStatusLabels = ['shipment_created' => 'تم إنشاء الشحنة', 'ready' => 'جاهزة', 'shipped' => 'تم الشحن', 'delivered' => 'تم التسليم', 'returned' => 'مرتجع', 'cancelled' => 'ملغاة', 'lost' => 'مفقودة', 'damaged' => 'تالفة'];
    @endphp

    <section id="order-bosta-shipping" class="rounded-3xl border border-sky-100 bg-white p-5 shadow-sm sm:p-6" data-order-page-section="shipping">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-lg font-black text-gray-900">شحن Bosta</h3>
                    @if($bostaShipment)
                        <span class="rounded-full px-3 py-1 text-xs font-black {{ $bostaShipment->creation_status === 'created' ? 'bg-emerald-100 text-emerald-800' : ($bostaShipment->creation_status === 'failed' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800') }}">
                            {{ ['created' => 'تم إنشاء الشحنة', 'failed' => 'رفض إنشاء الشحنة', 'uncertain' => 'نتيجة الإنشاء غير مؤكدة', 'pending' => 'جارٍ إنشاء الشحنة'][$bostaShipment->creation_status] ?? 'تحتاج مراجعة' }}
                        </span>
                    @elseif($bostaEligible)
                        <span class="rounded-full bg-sky-100 px-3 py-1 text-xs font-black text-sky-800">جاهز لإنشاء الشحنة</span>
                    @else
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-black text-gray-600">غير جاهز للشحن</span>
                    @endif
                </div>
                <p class="mt-1 text-xs font-bold leading-6 text-gray-500">توجد بوليصة حالية واحدة لكل عملية شراء، مع حفظ البوالص السابقة عند إعادة الشحن. مبلغ COD تشغيلي لدى Bosta ولا يُسجل كدفعة داخل HeroKid.</p>
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
                    <p class="mt-1 text-sm font-black text-gray-900">{{ $bostaStatusLabels[$bostaShipment->shipping_status] ?? ($bostaShipment->shipping_status ?: ($bostaShipment->state_code !== null ? 'كود '.$bostaShipment->state_code : 'لم يصل تحديث بعد')) }}</p>
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
                    @if($bostaShipment->tracking_number && ! $bostaShipment->cancellation_status)
                        <form method="POST" action="{{ route('admin.bosta.awb') }}" target="_blank">
                            @csrf
                            <input type="hidden" name="shipments[]" value="{{ $bostaShipment->id }}">
                            <input type="hidden" name="awb_type" value="A6">
                            <button class="rounded-xl bg-slate-900 px-4 py-2 text-xs font-black text-white hover:bg-black">فتح بوليصة A6</button>
                        </form>
                    @endif
                @endcan
            </div>

            @if($bostaShipment->tracking_number)
                @can('bosta.create_shipment')
                    <details class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <summary class="cursor-pointer text-sm font-black text-amber-900">إلغاء البوليصة أو تجهيز إعادة الشحن</summary>
                        <p class="mt-2 text-xs leading-6">عدّل بيانات الأوردر أولًا. الإلغاء متاح قبل استلام بوسطة فقط. المرتجع للمخزن أو المفقود أو التالف يجب أن يكون مؤكدًا لدى بوسطة. سيتم الاحتفاظ بتاريخ الشحنة السابقة ثم مراجعة بيانات البوليصة الجديدة قبل إنشائها.</p>
                        <form method="POST" action="{{ route('admin.bosta.shipments.release', $bostaShipment) }}" class="mt-3 space-y-3">
                            @csrf
                            <label class="block text-xs font-bold">الإجراء
                                <select name="action" class="mt-1 w-full rounded-xl border-gray-300">
                                    <option value="cancel">إلغاء قبل الاستلام / التحقق من إلغاء سابق</option>
                                    <option value="reship">تجهيز شحنة بديلة بعد المرتجع أو الفقد أو التلف</option>
                                </select>
                            </label>
                            <label class="block text-xs font-bold">السبب
                                <input name="reason" required minlength="5" maxlength="500" class="mt-1 w-full rounded-xl border-gray-300" placeholder="مثال: تعديل المنتجات أو رقم المستلم">
                            </label>
                            <button class="rounded-xl bg-amber-100 px-4 py-2 text-sm font-black text-amber-900">التحقق من Bosta وإتاحة بوليصة بديلة</button>
                        </form>
                    </details>
                @endcan
            @endif
            @if($bostaShipment->creation_status === 'uncertain' || $bostaShipment->cancellation_status)
                <p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm font-bold text-amber-900">نتيجة الاتصال تحتاج مراجعة لدى Bosta. لا تعِد إرسال طلب إنشاء شحنة؛ قد تكون الشحنة أُنشئت بالفعل.</p>
            @endif

            @if($bostaShipment->creation_status === 'failed' && $bostaShipment->last_error)
                <p class="mt-4 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-xs font-bold text-red-700">فشل آخر اتصال مع Bosta. راجع بيانات الشحنة بالأسفل وعدّلها قبل إعادة المحاولة.</p>
            @endif

            @if($bostaShipment->creation_status === 'failed' && $bostaEligible)
                @include('admin.orders._bosta-shipment-form', ['isRetry' => true])
            @endif
        @elseif($bostaEligible)
            @include('admin.orders._bosta-shipment-form', ['isRetry' => false])
        @else
            <p class="mt-5 rounded-2xl bg-gray-50 px-4 py-3 text-sm font-bold text-gray-600">لن يظهر هذا الطلب ضمن قائمة Bosta ولن يمكن إنشاء شحنة له حتى تصبح حالة الشحن لكل عناصر عملية الشراء «جاهز للشحن».</p>
        @endif
        @if(($bostaShipmentHistory ?? collect())->isNotEmpty())
            <details class="mt-5 rounded-xl border border-gray-200 p-4">
                <summary class="cursor-pointer text-sm font-black">البوالص السابقة ({{ $bostaShipmentHistory->count() }})</summary>
                @foreach($bostaShipmentHistory as $previousShipment)
                    <p class="mt-2 text-xs leading-6"><span dir="ltr">{{ $previousShipment->tracking_number }}</span> · {{ $bostaStatusLabels[$previousShipment->shipping_status] ?? ($previousShipment->shipping_status ?: 'سابقة') }} · {{ $previousShipment->replacement_reason }}</p>
                @endforeach
            </details>
        @endif
    </section>
@endcan
