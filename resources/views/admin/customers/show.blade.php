<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-gray-800">تفاصيل العميل</h2>
                <p class="text-xs text-gray-500 mt-1">{{ $customer['name'] }} — {{ $customer['type_label'] }}</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.customers.edit', $customer['key']) }}"
                    class="px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 text-sm font-bold transition">
                    تعديل العميل
                </a>
                <a href="{{ route('admin.customers.index') }}"
                    class="px-4 py-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 text-sm font-bold transition">
                    العودة للعملاء
                </a>
            </div>
        </div>
    </x-slot>

    @php
        $lastVisit = app_datetime($customer['last_visit_at'], 'Y-m-d H:i', 'Not available');
        $lastOrder = app_datetime($customer['last_order_at'], 'Y-m-d H:i', 'Not available');
    @endphp

    <div class="space-y-6">
        @if(session('success'))
            <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-right text-sm font-bold text-green-700">
                {{ session('success') }}
            </div>
        @endif

        @if(session('customer_account_message'))
            <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-5 text-right">
                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                    <div>
                        <h3 class="font-extrabold text-indigo-900">بيانات الحساب جاهزة للإرسال</h3>
                        <p class="text-sm text-indigo-700 mt-1">يمكنك إرسال هذه البيانات للعميل عبر واتساب لمتابعة طلبه.</p>
                    </div>
                    @if(session('customer_account_whatsapp_url'))
                        <a href="{{ session('customer_account_whatsapp_url') }}" target="_blank" rel="noopener"
                            class="inline-flex justify-center rounded-xl bg-green-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-green-700">
                            إرسال عبر واتساب
                        </a>
                    @endif
                </div>
                <textarea readonly dir="rtl"
                    class="mt-4 block w-full rounded-xl border-indigo-100 bg-white text-right text-sm leading-7 text-slate-800 shadow-sm">{{ session('customer_account_message') }}</textarea>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:col-span-2">
                <p class="text-xs font-bold text-indigo-600 mb-2">بيانات التواصل</p>
                <h3 class="text-2xl font-extrabold text-gray-900">{{ $customer['name'] }}</h3>
                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    <div class="rounded-lg bg-gray-50 p-3">
                        <div class="text-xs text-gray-400 mb-1">الهاتف</div>
                        <div class="font-bold text-gray-900 dir-ltr text-left">{{ $customer['phone'] }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3">
                        <div class="text-xs text-gray-400 mb-1">البريد الإلكتروني</div>
                        <div class="font-bold text-gray-900 dir-ltr text-left">{{ $customer['email'] }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 md:col-span-2">
                        <div class="text-xs text-gray-400 mb-1">آخر عنوان توصيل</div>
                        <div class="font-bold text-gray-900">{{ $customer['address'] }}</div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <p class="text-xs font-bold text-gray-400 mb-2">آخر زيارة</p>
                <div class="text-xl font-extrabold text-gray-900 dir-ltr text-left">{{ $lastVisit }}</div>
                <p class="text-xs text-gray-500 mt-2">من تسجيل الدخول أو فتح صفحة قصة.</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <p class="text-xs font-bold text-gray-400 mb-2">آخر طلب</p>
                <div class="text-xl font-extrabold text-gray-900 dir-ltr text-left">{{ $lastOrder }}</div>
                <p class="text-xs text-gray-500 mt-2">{{ $customer['orders_count'] }} طلبات محفوظة.</p>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-extrabold text-gray-900">القصص التي فتحها العميل</h3>
                    <p class="text-xs text-gray-500 mt-1">يتم التسجيل عند فتح صفحة قصة عامة.</p>
                </div>
                <span class="text-sm text-gray-500">{{ $storyViews->count() }} زيارة</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-right">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">القصة</th>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">وقت الفتح</th>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">الجلسة</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($storyViews as $view)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-4">
                                    <div class="font-bold text-gray-900">{{ $view->story?->title ?? 'قصة محذوفة' }}</div>
                                    @if($view->story)
                                        <a href="{{ route('stories.show', $view->story->slug) }}" target="_blank"
                                            class="text-xs text-indigo-600 hover:text-indigo-800">
                                            فتح القصة
                                        </a>
                                    @endif
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-sm text-gray-600 dir-ltr">
                                    {{ app_datetime($view->viewed_at, 'Y-m-d H:i', 'Not available') }}
                                </td>
                                <td class="px-5 py-4 text-xs text-gray-400 dir-ltr text-left">
                                    {{ $view->session_id ?: 'Not available' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-5 py-10 text-center text-gray-500">
                                    لا توجد زيارات قصص مسجلة لهذا العميل.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-extrabold text-gray-900">الطلبات السابقة وبيانات الأطفال</h3>
                    <p class="text-xs text-gray-500 mt-1">كل طلب يظهر بيانات الطفل والقصة والعنوان المحفوظ وقت الطلب.</p>
                </div>
                <span class="text-sm text-gray-500">{{ $orders->count() }} طلب</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-right">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">رقم الطلب</th>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">الطفل</th>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">القصة</th>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">الاهتمامات</th>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">الحالة</th>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase">التاريخ</th>
                            <th class="px-5 py-3 text-xs font-extrabold text-gray-500 uppercase"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($orders as $order)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <div class="font-bold text-gray-900">#{{ $order->order_number }}</div>
                                    <div class="text-xs text-gray-400">{{ data_get($order->delivery_details, 'governorate', 'Not available') }}</div>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <div class="font-bold text-gray-900">{{ $order->child_name }}</div>
                                    <div class="text-xs text-gray-400">{{ $order->child_age }} سنة، {{ $order->child_gender === 'boy' ? 'ولد' : 'بنت' }}</div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-gray-900">{{ $order->story?->title ?? 'Not available' }}</div>
                                    <div class="text-xs text-gray-400">{{ $order->language }}</div>
                                </td>
                                <td class="px-5 py-4 text-sm text-gray-600 max-w-sm">
                                    {{ $order->interests ?: 'Not available' }}
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700 text-xs font-bold">
                                        {{ \App\Support\OrderStatusRegistry::label(\App\Support\OrderStatusRegistry::TYPE_ORDER, $order->status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-sm text-gray-600 dir-ltr">
                                    {{ app_datetime($order->created_at, 'Y-m-d H:i') }}
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-left">
                                    <a href="{{ route('admin.orders.show', $order) }}"
                                        class="text-indigo-600 hover:text-indigo-800 font-bold text-sm">
                                        عرض الطلب
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-gray-500">
                                    لا توجد طلبات سابقة لهذا العميل.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-admin-layout>
