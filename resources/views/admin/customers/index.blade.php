<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-gray-800">Customers</h2>
                <p class="text-xs text-gray-500 mt-1">كل العملاء المسجلين أو الذين أرسلوا طلبات بدون حساب.</p>
            </div>
            <span class="text-sm text-gray-500">إجمالي: {{ $totalCustomers }} عميل</span>
        </div>
    </x-slot>

    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <form method="GET" action="{{ route('admin.customers.index') }}" class="flex flex-col md:flex-row gap-3">
                <div class="flex-1">
                    <label for="customer-search" class="sr-only">بحث العملاء</label>
                    <input id="customer-search" type="text" name="q" value="{{ request('q') }}"
                        placeholder="ابحث بالاسم، الهاتف، البريد، أو العنوان..."
                        class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                </div>
                <div class="flex gap-2">
                    <button class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-2.5 rounded-lg transition">
                        بحث
                    </button>
                    <a href="{{ route('admin.customers.index') }}"
                        class="px-5 py-2.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 font-bold transition">
                        إعادة
                    </a>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-right">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">العميل</th>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">الهاتف</th>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">العنوان</th>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">الطلبات</th>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">الزيارات</th>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">آخر نشاط</th>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($customers as $customer)
                            @php
                                $lastActivity = $customer['last_activity_at']
                                    ? \Carbon\Carbon::parse($customer['last_activity_at'])->format('Y-m-d H:i')
                                    : 'Not available';
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-4">
                                    <div class="font-bold text-gray-900">{{ $customer['name'] }}</div>
                                    <div class="text-xs text-gray-400 dir-ltr">{{ $customer['email'] }}</div>
                                    <span class="inline-flex mt-2 px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700 text-xs font-bold">
                                        {{ $customer['type_label'] }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-sm text-gray-700 dir-ltr text-left">
                                    {{ $customer['phone'] }}
                                </td>
                                <td class="px-5 py-4 text-sm text-gray-600 max-w-sm">
                                    {{ $customer['address'] }}
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <div class="font-bold text-gray-900">{{ $customer['orders_count'] }}</div>
                                    <div class="text-xs text-gray-400">طلب سابق</div>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <div class="font-bold text-gray-900">{{ $customer['stories_viewed_count'] }}</div>
                                    <div class="text-xs text-gray-400">قصة مفتوحة</div>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-sm text-gray-600 dir-ltr">
                                    {{ $lastActivity }}
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-left">
                                    <a href="{{ route('admin.customers.show', $customer['key']) }}"
                                        class="text-indigo-600 hover:text-indigo-800 font-bold text-sm">
                                        عرض التفاصيل
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-12 text-center text-gray-500">
                                    لا توجد بيانات عملاء بعد.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4 border-t border-gray-100">
                {{ $customers->links() }}
            </div>
        </div>
    </div>
</x-admin-layout>
