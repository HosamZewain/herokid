<x-admin-layout>
    <x-slot name="header">
        <div class="text-right">
            <h1 class="text-2xl font-black text-slate-900">Bosta للشحن</h1>
            <p class="mt-1 text-sm text-slate-500">إنشاء الشحنات وطلبات الاستلام ومتابعة حالة التوصيل.</p>
        </div>
    </x-slot>

    @php
        $statusLabels = [
            'ready' => 'جاهزة لدى Bosta',
            'shipment_created' => 'تم إنشاء الشحنة',
            'shipped' => 'تم الشحن',
            'delivered' => 'تم التسليم',
            'returned' => 'مرتجع',
            'cancelled' => 'ملغاة',
        ];
        $pickupLabels = [
            'awaiting' => 'بانتظار Pickup',
            'herokid' => 'Pickup من HeroKid',
            'bosta_dashboard' => 'Pickup من لوحة Bosta',
            'provider_progress' => 'استلمتها Bosta',
        ];
        $pickupColors = [
            'awaiting' => 'bg-amber-100 text-amber-800',
            'herokid' => 'bg-indigo-100 text-indigo-800',
            'bosta_dashboard' => 'bg-sky-100 text-sky-800',
            'provider_progress' => 'bg-emerald-100 text-emerald-800',
        ];
    @endphp

    <div class="space-y-6" dir="rtl">
        @if(session('success'))
            <div class="rounded-2xl bg-emerald-50 p-4 font-bold text-emerald-800">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl bg-red-50 p-4 text-red-800">
                <ul class="list-disc pr-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($pickupSyncWarning)
            <div class="rounded-2xl bg-amber-50 p-4 font-bold text-amber-900">{{ $pickupSyncWarning }}</div>
        @elseif(request()->boolean('refresh_pickups') && $pickupSyncResult && ! $pickupSyncResult['skipped'])
            <div class="rounded-2xl bg-emerald-50 p-4 font-bold text-emerald-800">
                تمت مزامنة {{ $pickupSyncResult['synced'] }} Pickup وربط {{ $pickupSyncResult['linked_shipments'] }} شحنة جديدة.
            </div>
        @endif

        <section class="rounded-3xl border bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-black">حالة الربط</h2>
                    <p class="mt-1 text-sm text-gray-500">COD معلومة تشغيلية للشحن فقط ولا يُسجل كدفعة أو تحصيل داخل HeroKid.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    @if($pickupSyncedAt)
                        <span class="text-xs font-bold text-gray-500">آخر مزامنة: {{ \Carbon\CarbonImmutable::createFromTimestamp((int) $pickupSyncedAt)->setTimezone(\App\Support\AppDateTime::timezone())->format('d/m/Y h:i A') }}</span>
                    @endif
                    @if($configured)
                        <a href="{{ route('admin.bosta.index', array_merge(request()->except('page', 'refresh_pickups'), ['refresh_pickups' => 1])) }}" class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-black text-indigo-700 hover:bg-indigo-100">مزامنة Pickups من Bosta</a>
                    @endif
                    <span class="rounded-full px-4 py-2 font-bold {{ $configured ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                        {{ $configured ? 'جاهز' : 'يحتاج إعداد .env' }}
                    </span>
                </div>
            </div>
        </section>

        @can('bosta.create_shipment')
            @if($tab === 'active')
                <section class="rounded-3xl border bg-white p-6 shadow-sm">
                    <h2 class="text-xl font-black">طلبات جاهزة لإنشاء شحنة</h2>
                    <p class="mt-1 text-xs font-bold text-gray-500">تظهر هنا للمراجعة فقط. افتح الطلب لمراجعة بيانات المستلم والعنوان وCOD ثم إنشاء الشحنة.</p>
                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @forelse($eligibleOrders as $order)
                            <div class="rounded-2xl border p-4">
                                <div class="font-black">{{ $order->checkoutReference?->short_reference ?: $order->order_number }}</div>
                                <div class="mt-1 text-sm text-gray-600">{{ $order->parent_name }} · {{ data_get($order->delivery_details, 'governorate') }}</div>
                                <a href="{{ route('admin.orders.groups.show', $order) }}#order-bosta-shipping" class="mt-3 block w-full rounded-xl bg-indigo-600 px-4 py-2 text-center font-bold text-white">فتح ومراجعة الطلب</a>
                            </div>
                        @empty
                            <p class="text-gray-500">لا توجد عمليات شراء حالتها جاهز للشحن.</p>
                        @endforelse
                    </div>
                </section>
            @endif
        @endcan

        <section class="overflow-hidden rounded-3xl border bg-white shadow-sm">
            <div class="border-b p-5 sm:p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-xl font-black">إدارة شحنات Bosta</h2>
                        <p class="mt-1 text-xs font-bold text-gray-500">النشطة لم يتم تسليمها بعد، والمنتهية أكد Webhook الخاص بـBosta تسليمها.</p>
                    </div>
                    <div class="inline-flex w-full rounded-2xl bg-slate-100 p-1 sm:w-auto" role="tablist" aria-label="حالة الشحنات">
                        <a href="{{ route('admin.bosta.index', ['tab' => 'active']) }}"
                           class="flex-1 rounded-xl px-5 py-2.5 text-center text-sm font-black transition sm:flex-none {{ $tab === 'active' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}"
                           @if($tab === 'active') aria-current="page" @endif>
                            نشط <span class="mr-1 text-xs">{{ $activeCount }}</span>
                        </a>
                        <a href="{{ route('admin.bosta.index', ['tab' => 'finished']) }}"
                           class="flex-1 rounded-xl px-5 py-2.5 text-center text-sm font-black transition sm:flex-none {{ $tab === 'finished' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}"
                           @if($tab === 'finished') aria-current="page" @endif>
                            منتهي <span class="mr-1 text-xs">{{ $finishedCount }}</span>
                        </a>
                    </div>
                </div>

                <form method="GET" action="{{ route('admin.bosta.index') }}" class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <label class="text-sm font-bold text-slate-700 xl:col-span-2">
                        بحث
                        <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="رقم الطلب، التتبع، العميل أو الموبايل" class="mt-1 w-full rounded-xl border-gray-300 text-sm">
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        المحافظة
                        <select name="governorate" class="mt-1 w-full rounded-xl border-gray-300 text-sm">
                            <option value="">كل المحافظات</option>
                            @foreach($governorates as $governorate)
                                <option value="{{ $governorate }}" @selected(($filters['governorate'] ?? '') === $governorate)>{{ $governorate }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        حالة Bosta
                        <select name="shipment_status" class="mt-1 w-full rounded-xl border-gray-300 text-sm">
                            <option value="">كل الحالات</option>
                            @foreach($shipmentStatuses as $shipmentStatus)
                                <option value="{{ $shipmentStatus }}" @selected(($filters['shipment_status'] ?? '') === $shipmentStatus)>{{ $statusLabels[$shipmentStatus] ?? $shipmentStatus }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        حالة Pickup
                        <select name="pickup_state" class="mt-1 w-full rounded-xl border-gray-300 text-sm">
                            <option value="all" @selected(($filters['pickup_state'] ?? 'all') === 'all')>الكل</option>
                            <option value="awaiting" @selected(($filters['pickup_state'] ?? '') === 'awaiting')>بانتظار Pickup</option>
                            <option value="scheduled" @selected(($filters['pickup_state'] ?? '') === 'scheduled')>تمت إضافتها إلى Pickup</option>
                            <option value="provider_progress" @selected(($filters['pickup_state'] ?? '') === 'provider_progress')>استلمتها Bosta بدون ربط Pickup محلي</option>
                        </select>
                    </label>
                    <label class="text-sm font-bold text-slate-700">
                        عدد النتائج
                        <select name="per_page" class="mt-1 w-full rounded-xl border-gray-300 text-sm">
                            <option value="50" @selected((string) ($filters['per_page'] ?? '50') === '50')>50</option>
                            <option value="100" @selected((string) ($filters['per_page'] ?? '') === '100')>100</option>
                        </select>
                    </label>
                    <div class="flex items-end gap-2 md:col-span-2 xl:col-span-4">
                        <button class="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-black text-white">تطبيق الفلاتر</button>
                        <a href="{{ route('admin.bosta.index', ['tab' => $tab]) }}" class="rounded-xl border border-gray-300 px-5 py-2.5 text-sm font-black text-slate-600">مسح</a>
                    </div>
                </form>
            </div>

            <div class="p-4 sm:p-6">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-sm text-gray-500">
                    <span>{{ $shipments->total() }} شحنة</span>
                    <span>عرض {{ $shipments->firstItem() ?? 0 }}–{{ $shipments->lastItem() ?? 0 }}</span>
                </div>

                @if($shipments->isNotEmpty())
                    @if($tab === 'active')
                        <form method="POST" action="{{ route('admin.bosta.pickups.store') }}" data-bosta-pickup-form>
                            @csrf
                    @endif

                    <div class="overflow-x-auto rounded-2xl border">
                        <table class="w-full min-w-[1280px] text-sm">
                            <thead class="bg-slate-50 text-gray-600">
                                <tr class="border-b">
                                    @if($tab === 'active')
                                        <th class="p-3 text-center">
                                            <input type="checkbox" class="rounded" data-select-all-pickup aria-label="تحديد كل الشحنات المؤهلة للـPickup">
                                        </th>
                                    @endif
                                    <th class="p-3 text-right">الطلب</th>
                                    <th class="p-3 text-right">اسم العميل</th>
                                    <th class="p-3 text-right">الموبايل</th>
                                    <th class="p-3 text-right">المحافظة</th>
                                    <th class="p-3 text-right">التتبع</th>
                                    <th class="p-3 text-right">حالة Bosta</th>
                                    <th class="p-3 text-right">Pickup</th>
                                    <th class="p-3 text-right">COD تشغيلي</th>
                                    <th class="p-3 text-right">آخر تحديث</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($shipments as $shipment)
                                    @php
                                        $customerName = $shipment->order?->parent_name ?: '—';
                                        $customerPhone = data_get($shipment->order?->delivery_details, 'phone', data_get($shipment->order?->delivery_details, 'mobile', '—'));
                                        $governorate = data_get($shipment->order?->delivery_details, 'governorate', '—');
                                        $pickupState = $shipment->pickupState();
                                        $awaitingPickup = $shipment->isAwaitingPickup();
                                    @endphp
                                    <tr class="border-b last:border-b-0 hover:bg-slate-50/70">
                                        @if($tab === 'active')
                                            <td class="p-3 text-center">
                                                @if($awaitingPickup)
                                                    <input type="checkbox" name="shipments[]" value="{{ $shipment->id }}" class="rounded" data-pickup-shipment aria-label="تحديد شحنة {{ $shipment->business_reference }}">
                                                @else
                                                    <span class="text-gray-300">—</span>
                                                @endif
                                            </td>
                                        @endif
                                        <td class="p-3 font-black">
                                            @if($shipment->order)
                                                <a href="{{ route('admin.orders.groups.show', $shipment->order) }}" class="text-indigo-700 hover:underline">{{ $shipment->business_reference }}</a>
                                            @else
                                                {{ $shipment->business_reference }}
                                            @endif
                                        </td>
                                        <td class="p-3 font-bold text-slate-800">{{ $customerName }}</td>
                                        <td class="p-3" dir="ltr">{{ $customerPhone }}</td>
                                        <td class="p-3">{{ $governorate }}</td>
                                        <td class="p-3" dir="ltr">{{ $shipment->tracking_number }}</td>
                                        <td class="p-3">{{ $statusLabels[$shipment->shipping_status] ?? ($shipment->shipping_status ?: ($shipment->state_code !== null ? 'كود '.$shipment->state_code : 'تم إنشاء الشحنة')) }}</td>
                                        <td class="p-3">
                                            <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $pickupColors[$pickupState] }}">
                                                {{ $pickupLabels[$pickupState] }}
                                            </span>
                                        </td>
                                        <td class="p-3">{{ format_money($shipment->cod_amount_cents / 100) }}</td>
                                        <td class="p-3 whitespace-nowrap">{{ $shipment->last_event_at?->format('d/m/Y h:i A') ?: $shipment->updated_at?->format('d/m/Y h:i A') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if($tab === 'active')
                        @can('bosta.create_pickup')
                            <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <label class="text-sm font-bold">تاريخ الاستلام<input type="date" name="scheduled_date" value="{{ now()->addDay()->toDateString() }}" class="mt-1 w-full rounded-xl border-gray-300" required></label>
                                <label class="text-sm font-bold">مسؤول التسليم<input name="contact_name" class="mt-1 w-full rounded-xl border-gray-300" required></label>
                                <label class="text-sm font-bold">الهاتف<input name="contact_phone" inputmode="tel" class="mt-1 w-full rounded-xl border-gray-300" required></label>
                                <label class="text-sm font-bold">ملاحظة<input name="notes" class="mt-1 w-full rounded-xl border-gray-300"></label>
                            </div>
                        @endcan

                        <div class="mt-4 flex flex-wrap gap-3">
                            @can('bosta.create_pickup')
                                <button class="rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white">إنشاء Pickup للشحنات المختارة</button>
                            @endcan
                            @can('bosta.print_awb')
                                <select name="awb_type" class="rounded-xl border-gray-300 text-sm font-bold">
                                    <option value="A6" selected>A6 — طابعة حرارية</option>
                                    <option value="A4">A4 — طابعة عادية</option>
                                </select>
                                <button type="submit" formaction="{{ route('admin.bosta.awb') }}" formtarget="_blank" formnovalidate class="rounded-xl bg-slate-900 px-5 py-3 font-bold text-white">فتح بوليصة الشحن</button>
                            @endcan
                        </div>
                        </form>
                    @endif
                @else
                    <div class="rounded-2xl bg-slate-50 px-5 py-10 text-center text-gray-500">
                        {{ $tab === 'finished' ? 'لا توجد شحنات تم تسليمها تطابق الفلاتر.' : 'لا توجد شحنات نشطة تطابق الفلاتر.' }}
                    </div>
                @endif

                <div class="mt-5">{{ $shipments->links() }}</div>
            </div>
        </section>

        @if($pickups->isNotEmpty())
            <section class="rounded-3xl border bg-white p-6 shadow-sm">
                <h2 class="text-xl font-black">آخر طلبات الاستلام</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @foreach($pickups as $pickup)
                        <div class="rounded-2xl bg-gray-50 p-4">
                            <b>{{ $pickup->scheduled_date->format('d/m/Y') }}</b> · {{ $pickup->number_of_parcels }} شحنة<br>
                            <span class="text-sm text-gray-500">{{ $pickup->contact_name }} · {{ $pickup->bosta_pickup_id ?: 'قيد التجهيز' }} · {{ $pickup->created_by_user_id ? 'من HeroKid' : 'من لوحة Bosta' }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>

    @if($tab === 'active')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const selectAll = document.querySelector('[data-select-all-pickup]');
                const shipmentCheckboxes = Array.from(document.querySelectorAll('[data-pickup-shipment]'));

                if (!selectAll) return;

                selectAll.addEventListener('change', () => {
                    shipmentCheckboxes.forEach((checkbox) => {
                        checkbox.checked = selectAll.checked;
                    });
                });

                shipmentCheckboxes.forEach((checkbox) => {
                    checkbox.addEventListener('change', () => {
                        selectAll.checked = shipmentCheckboxes.length > 0 && shipmentCheckboxes.every((item) => item.checked);
                        selectAll.indeterminate = shipmentCheckboxes.some((item) => item.checked) && !selectAll.checked;
                    });
                });
            });
        </script>
    @endif
</x-admin-layout>
