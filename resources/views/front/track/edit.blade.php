<x-front-layout>
    <x-slot name="pageTitle">تعديل الطلب {{ $group['short_reference'] }}</x-slot>
    <x-slot name="robots">noindex, nofollow</x-slot>

    @php
        $delivery = $group['delivery'];
        $fullEdit = (bool) $group['can_edit'];
        $selectedCountry = (string) old('delivery_country_id', data_get($delivery, 'delivery_country_id'));
        $selectedGovernorate = (string) old('delivery_governorate_id', data_get($delivery, 'delivery_governorate_id'));
    @endphp

    <main class="min-h-[70vh] bg-slate-50 py-8 sm:py-12">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
            <div class="mb-5 flex items-center justify-between gap-4">
                <a href="{{ route('track.show', $group['short_reference']) }}" class="text-sm font-black text-indigo-700">العودة للطلب</a>
                <div class="text-right"><p class="text-xs font-bold text-slate-500">تعديل الطلب</p><h1 class="font-mono text-2xl font-black text-slate-950" dir="ltr">{{ $group['short_reference'] }}</h1></div>
            </div>

            <form method="POST" action="{{ route('track.update', $group['short_reference']) }}" class="space-y-6">
                @csrf
                @method('PUT')
                @if($errors->any())<div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-black text-red-700">{{ $errors->first() }}</div>@endif

                @if($fullEdit)<section class="rounded-3xl border border-slate-100 bg-white p-5 shadow-sm sm:p-7">
                    <h2 class="text-xl font-black text-slate-950">بيانات ولي الأمر والتوصيل</h2>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <label class="text-sm font-black text-slate-700">اسم ولي الأمر<input name="parent_name" value="{{ old('parent_name', $group['customer_name']) }}" required class="mt-2 w-full rounded-2xl border-slate-200"></label>
                        <label class="text-sm font-black text-slate-700">رقم الموبايل<input type="tel" inputmode="tel" name="phone" value="{{ old('phone', $group['phone']) }}" required dir="ltr" class="mt-2 w-full rounded-2xl border-slate-200 text-left"></label>
                        <label class="text-sm font-black text-slate-700">الدولة<select name="delivery_country_id" id="delivery_country_id" required class="mt-2 w-full rounded-2xl border-slate-200"><option value="">اختر الدولة</option>@foreach($deliveryCountries as $country)<option value="{{ $country->id }}" @selected($selectedCountry === (string) $country->id)>{{ $country->name }}</option>@endforeach</select></label>
                        <label class="text-sm font-black text-slate-700">المحافظة<select name="delivery_governorate_id" id="delivery_governorate_id" required class="mt-2 w-full rounded-2xl border-slate-200"><option value="">اختر المحافظة</option>@foreach($deliveryCountries as $country)@foreach($country->activeGovernorates as $governorate)<option value="{{ $governorate->id }}" data-country-id="{{ $country->id }}" @selected($selectedGovernorate === (string) $governorate->id)>{{ $governorate->name }}</option>@endforeach @endforeach</select></label>
                        <label class="text-sm font-black text-slate-700">المدينة<input name="city" value="{{ old('city', data_get($delivery, 'city')) }}" required class="mt-2 w-full rounded-2xl border-slate-200"></label>
                        <label class="text-sm font-black text-slate-700">الشارع<input name="street" value="{{ old('street', data_get($delivery, 'street')) }}" required class="mt-2 w-full rounded-2xl border-slate-200"></label>
                        <label class="text-sm font-black text-slate-700 sm:col-span-2">تفاصيل العنوان<textarea name="address_details" rows="3" required class="mt-2 w-full rounded-2xl border-slate-200">{{ old('address_details', data_get($delivery, 'address_details')) }}</textarea></label>
                    </div>
                </section>@endif

                @foreach($group['active_orders']->filter(fn($order) => $order->child_name || $order->story_id) as $order)
                    <section class="rounded-3xl border border-indigo-100 bg-white p-5 shadow-sm sm:p-7">
                        <h2 class="text-lg font-black text-slate-950">بيانات الطفل — {{ $order->items->first()?->title }}</h2>
                        <div class="mt-5 grid gap-4 sm:grid-cols-3">
                            @if($fullEdit)
                                <label class="text-sm font-black text-slate-700">اسم الطفل<input name="children[{{ $order->id }}][child_name]" value="{{ old('children.'.$order->id.'.child_name', $order->child_name) }}" required class="mt-2 w-full rounded-2xl border-slate-200"></label>
                                <label class="text-sm font-black text-slate-700">العمر<input type="number" min="2" max="16" name="children[{{ $order->id }}][child_age]" value="{{ old('children.'.$order->id.'.child_age', $order->child_age) }}" class="mt-2 w-full rounded-2xl border-slate-200"></label>
                                <label class="text-sm font-black text-slate-700">الجنس<select name="children[{{ $order->id }}][child_gender]" class="mt-2 w-full rounded-2xl border-slate-200"><option value="">غير محدد</option><option value="boy" @selected(old('children.'.$order->id.'.child_gender', $order->child_gender) === 'boy')>ولد</option><option value="girl" @selected(old('children.'.$order->id.'.child_gender', $order->child_gender) === 'girl')>بنت</option></select></label>
                            @endif
                            <label class="text-sm font-black text-slate-700 sm:col-span-3">ملاحظات ولي الأمر<textarea name="children[{{ $order->id }}][parent_notes]" rows="3" class="mt-2 w-full rounded-2xl border-slate-200">{{ old('children.'.$order->id.'.parent_notes', $order->parent_notes) }}</textarea></label>
                        </div>
                    </section>
                @endforeach

                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold leading-6 text-amber-900">{{ $fullEdit ? 'يمكن تعديل البيانات قبل بدء التنفيذ فقط. لتغيير المنتجات أو الصور تواصل مع فريق HeroKid حتى نتأكد من السعر والملفات.' : 'بدأ تنفيذ الطلب، لذلك تم قفل بياناته الأساسية. يمكنك تحديث ملاحظات ولي الأمر في أي وقت حتى انتهاء الطلب، وسيظهر التحديث لفريق الإنتاج.' }}</div>
                <button class="w-full rounded-2xl bg-indigo-600 px-6 py-4 text-base font-black text-white shadow-lg shadow-indigo-100">حفظ التعديلات</button>
            </form>
        </div>
    </main>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const country = document.getElementById('delivery_country_id');
                const governorate = document.getElementById('delivery_governorate_id');
                const filter = () => Array.from(governorate.options).forEach((option) => {
                    option.hidden = option.value !== '' && option.dataset.countryId !== country.value;
                    if (option.hidden && option.selected) governorate.value = '';
                });
                country?.addEventListener('change', filter);
                filter();
            });
        </script>
    @endpush
</x-front-layout>
