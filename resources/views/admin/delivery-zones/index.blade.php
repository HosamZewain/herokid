<x-admin-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">مناطق ورسوم التوصيل</h2>
            <span class="text-sm text-gray-500">{{ $countries->count() }} دولة</span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(session('success'))
                <div class="bg-green-50 border border-green-300 text-green-800 px-5 py-3 rounded-xl flex items-center gap-2">
                    ✅ <span>{{ session('success') }}</span>
                </div>
            @endif

            @if($errors->any())
                <div class="bg-red-50 border border-red-200 text-red-700 px-5 py-4 rounded-xl text-sm">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4">إضافة دولة</h3>
                <form action="{{ route('admin.delivery-zones.countries.store') }}" method="POST" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                    @csrf
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-gray-700 mb-1">اسم الدولة</label>
                        <input type="text" name="name" value="{{ old('name') }}" placeholder="Egypt"
                            class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">الكود</label>
                        <input type="text" name="code" value="{{ old('code') }}" placeholder="EG" maxlength="3" dir="ltr"
                            class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">رسوم التوصيل</label>
                        <input type="number" name="delivery_fee" value="{{ old('delivery_fee', 0) }}" min="0" step="0.01"
                            class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm" required>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="inline-flex items-center gap-2 text-sm font-bold text-gray-700">
                            <input type="checkbox" name="active" value="1" checked class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            نشطة
                        </label>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-2.5 rounded-lg transition">
                            إضافة
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4">إضافة محافظة / منطقة</h3>
                <form action="{{ route('admin.delivery-zones.governorates.store') }}" method="POST" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                    @csrf
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">الدولة</label>
                        <select name="delivery_country_id" class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm" required>
                            <option value="">اختر الدولة...</option>
                            @foreach($countries as $country)
                                <option value="{{ $country->id }}" @selected((string) old('delivery_country_id') === (string) $country->id)>{{ $country->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-gray-700 mb-1">اسم المحافظة / المنطقة</label>
                        <input type="text" name="name" value="{{ old('name') }}" placeholder="القاهرة"
                            class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">رسوم خاصة اختيارية</label>
                        <input type="number" name="delivery_fee" value="{{ old('delivery_fee') }}" min="0" step="0.01" placeholder="فارغ = رسوم الدولة"
                            class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="inline-flex items-center gap-2 text-sm font-bold text-gray-700">
                            <input type="checkbox" name="active" value="1" checked class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            نشطة
                        </label>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-2.5 rounded-lg transition">
                            إضافة
                        </button>
                    </div>
                </form>
            </div>

            <div class="space-y-6">
                @forelse($countries as $country)
                    <section class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="p-5 border-b border-gray-100">
                            <form action="{{ route('admin.delivery-zones.countries.update', $country) }}" method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-4 items-end">
                                @csrf
                                @method('PUT')
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-bold text-gray-500 mb-1">الدولة</label>
                                    <input type="text" name="name" value="{{ $country->name }}"
                                        class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm" required>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1">الكود</label>
                                    <input type="text" name="code" value="{{ $country->code }}" maxlength="3" dir="ltr"
                                        class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1">رسوم الدولة</label>
                                    <input type="number" name="delivery_fee" value="{{ $country->delivery_fee }}" min="0" step="0.01"
                                        class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm" required>
                                </div>
                                <label class="inline-flex items-center gap-2 text-sm font-bold text-gray-700">
                                    <input type="checkbox" name="active" value="1" @checked($country->active) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    نشطة
                                </label>
                                <div class="flex gap-2">
                                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2 rounded-lg transition">
                                        حفظ
                                    </button>
                                </div>
                            </form>
                            <form action="{{ route('admin.delivery-zones.countries.destroy', $country) }}" method="POST" class="mt-3"
                                onsubmit="return confirm('حذف الدولة سيحذف كل المحافظات التابعة لها. هل أنت متأكد؟')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:text-red-800 text-sm font-bold">حذف الدولة</button>
                            </form>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-100 text-right">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-5 py-3 text-xs font-bold text-gray-500">المحافظة / المنطقة</th>
                                        <th class="px-5 py-3 text-xs font-bold text-gray-500">رسوم خاصة</th>
                                        <th class="px-5 py-3 text-xs font-bold text-gray-500">رسوم فعلية</th>
                                        <th class="px-5 py-3 text-xs font-bold text-gray-500">الحالة</th>
                                        <th class="px-5 py-3 text-xs font-bold text-gray-500">إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    @forelse($country->governorates as $governorate)
                                        <tr>
                                            <td class="px-5 py-3">
                                                <form id="governorate-{{ $governorate->id }}" action="{{ route('admin.delivery-zones.governorates.update', $governorate) }}" method="POST">
                                                    @csrf
                                                    @method('PUT')
                                                    <input type="hidden" name="delivery_country_id" value="{{ $country->id }}">
                                                    <input type="text" name="name" value="{{ $governorate->name }}"
                                                        class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm" required>
                                                </form>
                                            </td>
                                            <td class="px-5 py-3">
                                                <input form="governorate-{{ $governorate->id }}" type="number" name="delivery_fee" value="{{ $governorate->delivery_fee }}" min="0" step="0.01" placeholder="رسوم الدولة"
                                                    class="w-36 rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                            </td>
                                            <td class="px-5 py-3 font-bold text-gray-900">
                                                {{ number_format((float) ($governorate->delivery_fee ?? $country->delivery_fee), 0) }} ج.م
                                            </td>
                                            <td class="px-5 py-3">
                                                <label class="inline-flex items-center gap-2 text-sm font-bold text-gray-700">
                                                    <input form="governorate-{{ $governorate->id }}" type="checkbox" name="active" value="1" @checked($governorate->active) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                                    نشطة
                                                </label>
                                            </td>
                                            <td class="px-5 py-3">
                                                <div class="flex gap-3">
                                                    <button form="governorate-{{ $governorate->id }}" type="submit" class="text-indigo-600 hover:text-indigo-800 text-sm font-bold">
                                                        حفظ
                                                    </button>
                                                    <form action="{{ route('admin.delivery-zones.governorates.destroy', $governorate) }}" method="POST"
                                                        onsubmit="return confirm('هل أنت متأكد من حذف هذه المحافظة؟')">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm font-bold">حذف</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="px-6 py-8 text-center text-gray-400">لا توجد محافظات لهذه الدولة بعد.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                @empty
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-10 text-center text-gray-400">
                        لا توجد دول توصيل بعد.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-admin-layout>
