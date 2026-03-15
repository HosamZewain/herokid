<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">لوحة تحكم HeroKid</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

            <!-- Stats Cards Row 1 -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-3xl">📦</span>
                        <span class="text-xs font-bold text-orange-600 bg-orange-50 px-2 py-1 rounded-full">جديد</span>
                    </div>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $newOrders }}</p>
                    <p class="text-sm text-gray-500 mt-1">طلبات جديدة تنتظر المراجعة</p>
                    <a href="{{ route('admin.orders.index') }}?status=new" class="text-xs text-indigo-600 font-bold mt-2 block hover:underline">عرض الكل ←</a>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-3xl">👁️</span>
                        <span class="text-xs font-bold text-purple-600 bg-purple-50 px-2 py-1 rounded-full">للموافقة</span>
                    </div>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $pendingPreview }}</p>
                    <p class="text-sm text-gray-500 mt-1">تصميمات تنتظر موافقة العميل</p>
                    <a href="{{ route('admin.orders.index') }}?status=preview_uploaded" class="text-xs text-indigo-600 font-bold mt-2 block hover:underline">عرض الكل ←</a>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-3xl">🚚</span>
                        <span class="text-xs font-bold text-blue-600 bg-blue-50 px-2 py-1 rounded-full">شحن</span>
                    </div>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $shippedOrders }}</p>
                    <p class="text-sm text-gray-500 mt-1">طلبات في الشحن</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-3xl">✅</span>
                        <span class="text-xs font-bold text-green-600 bg-green-50 px-2 py-1 rounded-full">مكتمل</span>
                    </div>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $deliveredOrders }}</p>
                    <p class="text-sm text-gray-500 mt-1">طلبات تم تسليمها</p>
                </div>
            </div>

            <!-- Stats Cards Row 2 -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
                <div class="bg-indigo-600 rounded-2xl p-6 shadow-sm text-white">
                    <span class="text-3xl mb-3 block">📊</span>
                    <p class="text-3xl font-extrabold">{{ $totalOrders }}</p>
                    <p class="text-indigo-200 text-sm mt-1">إجمالي الطلبات</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <span class="text-3xl mb-3 block">📚</span>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $activeStories }}<span class="text-sm text-gray-400">/{{ $totalStories }}</span></p>
                    <p class="text-gray-500 text-sm mt-1">قصص نشطة</p>
                    <a href="{{ route('admin.stories.index') }}" class="text-xs text-indigo-600 font-bold mt-2 block hover:underline">إدارة القصص ←</a>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <span class="text-3xl mb-3 block">👥</span>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $totalUsers }}</p>
                    <p class="text-gray-500 text-sm mt-1">مستخدمون مسجلون</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 {{ $unreadMessages > 0 ? 'border-red-200' : '' }}">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-3xl">💬</span>
                        @if($unreadMessages > 0)
                        <span class="bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full">{{ $unreadMessages }} جديد</span>
                        @endif
                    </div>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $unreadMessages }}</p>
                    <p class="text-gray-500 text-sm mt-1">رسائل غير مقروءة</p>
                    <a href="{{ route('admin.messages.index') }}" class="text-xs text-indigo-600 font-bold mt-2 block hover:underline">قراءة الرسائل ←</a>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                <h3 class="font-bold text-gray-800 text-lg mb-5 text-right">إجراءات سريعة</h3>
                <div class="flex flex-wrap gap-3 justify-end">
                    <a href="{{ route('admin.orders.index') }}" class="flex items-center gap-2 bg-indigo-600 text-white font-bold px-5 py-2.5 rounded-xl hover:bg-indigo-700 transition text-sm">
                        <span>📦</span> إدارة الطلبات
                    </a>
                    <a href="{{ route('admin.stories.create') }}" class="flex items-center gap-2 bg-green-600 text-white font-bold px-5 py-2.5 rounded-xl hover:bg-green-700 transition text-sm">
                        <span>➕</span> إضافة قصة جديدة
                    </a>
                    <a href="{{ route('admin.faqs.index') }}" class="flex items-center gap-2 bg-amber-500 text-white font-bold px-5 py-2.5 rounded-xl hover:bg-amber-600 transition text-sm">
                        <span>❓</span> إدارة الأسئلة الشائعة
                    </a>
                    <a href="{{ route('admin.messages.index') }}" class="flex items-center gap-2 bg-slate-600 text-white font-bold px-5 py-2.5 rounded-xl hover:bg-slate-700 transition text-sm">
                        <span>💬</span> الرسائل
                    </a>
                </div>
            </div>

            <!-- Recent Orders -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-center">
                    <a href="{{ route('admin.orders.index') }}" class="text-sm text-indigo-600 font-bold hover:underline">عرض الكل ←</a>
                    <h3 class="font-bold text-gray-800 text-lg">آخر الطلبات</h3>
                </div>
                @if($recentOrders->count())
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">الإجراء</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">الحالة</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">القصة</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">الطفل</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">التاريخ</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">رقم الطلب</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach($recentOrders as $order)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.orders.show', $order) }}" class="text-indigo-600 font-bold text-xs hover:underline">تفاصيل</a>
                                </td>
                                <td class="px-4 py-3">
                                    @php
                                        $statusColors = [
                                            'new'                 => 'bg-orange-100 text-orange-700',
                                            'under_review'        => 'bg-blue-100 text-blue-700',
                                            'generating'          => 'bg-purple-100 text-purple-700',
                                            'preview_uploaded'    => 'bg-indigo-100 text-indigo-700',
                                            'approved_for_print'  => 'bg-teal-100 text-teal-700',
                                            'printing'            => 'bg-yellow-100 text-yellow-700',
                                            'shipped'             => 'bg-sky-100 text-sky-700',
                                            'delivered'           => 'bg-green-100 text-green-700',
                                            'cancelled'           => 'bg-red-100 text-red-700',
                                        ];
                                        $colorClass = $statusColors[$order->status] ?? 'bg-gray-100 text-gray-700';
                                    @endphp
                                    <span class="inline-block text-xs font-bold px-2 py-1 rounded-full {{ $colorClass }}">
                                        {{ __('order_status.' . $order->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700 text-right">{{ $order->story->title ?? '-' }}</td>
                                <td class="px-4 py-3 text-sm font-bold text-gray-900 text-right">{{ $order->child_name }}</td>
                                <td class="px-4 py-3 text-xs text-gray-400 text-right">{{ $order->created_at->format('d/m/Y') }}</td>
                                <td class="px-4 py-3 text-sm font-mono text-gray-600 text-right">{{ $order->order_number }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="text-center py-12 text-gray-400">
                    <p class="text-4xl mb-2">📭</p>
                    <p>لا توجد طلبات حتى الآن</p>
                </div>
                @endif
            </div>

        </div>
    </div>
</x-admin-layout>
