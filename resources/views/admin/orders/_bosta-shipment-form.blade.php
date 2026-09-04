@php
    $isRetry = (bool) ($isRetry ?? false);
@endphp

<div class="mt-5 rounded-2xl border border-sky-100 bg-sky-50 p-4">
    <p class="text-sm font-black text-sky-950">
        {{ $isRetry ? 'راجع بيانات الشحنة المصححة قبل إعادة المحاولة.' : 'كل عناصر عملية الشراء حالتها «جاهز للشحن».' }}
    </p>
    <p class="mt-1 text-xs font-bold text-sky-700">يمكنك تعديل بيانات المستلم والعنوان وCOD قبل إرسالها إلى Bosta.</p>

    @can('bosta.create_shipment')
        <details class="mt-4 rounded-2xl border border-sky-200 bg-white p-4" @if($isRetry || $errors->hasAny(['receiver_name', 'receiver_phone', 'bosta_city_id', 'bosta_district_id', 'governorate', 'district_name', 'first_line', 'second_line', 'cod_amount', 'order'])) open @endif>
            <summary class="cursor-pointer select-none text-sm font-black text-indigo-700">
                {{ $isRetry ? 'مراجعة البيانات وإعادة محاولة إنشاء الشحنة' : 'مراجعة بيانات الشحنة وإنشاؤها' }}
            </summary>
            <form method="POST" action="{{ route('admin.bosta.shipments.store', $group['representative_id']) }}" class="mt-5 space-y-4">
                @csrf
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-xs font-bold text-amber-950">
                    <p class="font-black">عنوان العميل الأصلي للمطابقة اليدوية</p>
                    <p class="mt-2 leading-6">
                        المحافظة: {{ data_get($group['delivery'], 'governorate') ?: '—' }} ·
                        المدينة/المنطقة: {{ data_get($group['delivery'], 'city', data_get($group['delivery'], 'area')) ?: '—' }}<br>
                        {{ data_get($group['delivery'], 'street', data_get($group['delivery'], 'address')) ?: '—' }}
                        @if(data_get($group['delivery'], 'address_details')) — {{ data_get($group['delivery'], 'address_details') }} @endif
                    </p>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="text-xs font-black text-gray-700">اسم المستلم
                        <input name="receiver_name" value="{{ old('receiver_name', $group['customer_name']) }}" required maxlength="120" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                    </label>
                    <label class="text-xs font-black text-gray-700">رقم الهاتف
                        <input name="receiver_phone" value="{{ old('receiver_phone', $group['phone']) }}" required maxlength="30" dir="ltr" class="mt-1 w-full rounded-xl border-gray-200 text-left text-sm">
                    </label>
                    @if($bostaAddressCatalogAvailable)
                        <label class="text-xs font-black text-gray-700">محافظة Bosta
                            <select name="bosta_city_id" required data-bosta-city data-districts-url="{{ route('admin.bosta.districts') }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                                <option value="">اختر المحافظة المعتمدة</option>
                                @foreach($bostaCities as $city)
                                    <option value="{{ $city['id'] }}" @selected(old('bosta_city_id', $bostaSelectedCityId) === $city['id'])>{{ $city['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-xs font-black text-gray-700">منطقة Bosta
                            <select name="bosta_district_id" required data-bosta-district data-selected="{{ old('bosta_district_id', $bostaSelectedDistrictId) }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                                <option value="">اختر المنطقة المعتمدة</option>
                                @foreach($bostaDistricts as $district)
                                    <option value="{{ $district['id'] }}" @selected(old('bosta_district_id', $bostaSelectedDistrictId) === $district['id'])>{{ $district['label'] }}</option>
                                @endforeach
                            </select>
                            <span class="mt-1 block text-[10px] font-bold text-gray-500">القائمة تتحدث تلقائيًا حسب المحافظة من بيانات Bosta.</span>
                        </label>
                    @else
                        <label class="text-xs font-black text-gray-700">المحافظة
                            <input name="governorate" value="{{ old('governorate', data_get($group['delivery'], 'governorate')) }}" required maxlength="120" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                        </label>
                        <label class="text-xs font-black text-gray-700">المدينة / المنطقة لدى Bosta
                            <input name="district_name" value="{{ old('district_name', data_get($group['delivery'], 'city', data_get($group['delivery'], 'area'))) }}" required maxlength="160" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                            <span class="mt-1 block text-[10px] font-bold text-amber-700">تعذر تحميل دليل مناطق Bosta الآن؛ يمكن الإدخال اليدوي وإعادة المحاولة.</span>
                        </label>
                    @endif
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
                <button class="min-h-11 w-full rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-black text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50" @disabled(!$bostaConfigured)>
                    {{ $isRetry ? 'حفظ التعديلات وإعادة المحاولة' : 'تأكيد وإنشاء شحنة Bosta' }}
                </button>
            </form>
        </details>
    @endcan
</div>

@once
    <script>
        document.addEventListener('change', async (event) => {
            const city = event.target.closest('[data-bosta-city]');
            if (!city) return;
            const form = city.closest('form');
            const district = form?.querySelector('[data-bosta-district]');
            if (!district) return;

            district.disabled = true;
            district.innerHTML = '<option value="">جارٍ تحميل المناطق…</option>';
            try {
                const url = new URL(city.dataset.districtsUrl, window.location.origin);
                url.searchParams.set('city_id', city.value);
                const response = await fetch(url, {headers: {'Accept': 'application/json'}});
                if (!response.ok) throw new Error('Bosta districts request failed');
                const data = await response.json();
                district.innerHTML = '<option value="">اختر المنطقة المعتمدة</option>';
                (data.districts || []).forEach((item) => district.add(new Option(item.label, item.id)));
            } catch (_) {
                district.innerHTML = '<option value="">تعذر تحميل المناطق — أعد المحاولة</option>';
            } finally {
                district.disabled = false;
            }
        });
    </script>
@endonce

@if(!$bostaConfigured)
    <p class="mt-3 text-xs font-black text-amber-700">تكامل Bosta يحتاج استكمال إعدادات البيئة قبل إنشاء الشحنة.</p>
@endif
