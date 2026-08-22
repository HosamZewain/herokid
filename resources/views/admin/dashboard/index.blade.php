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
                    <p class="text-sm text-gray-500 mt-1">عمليات شراء جديدة تنتظر المراجعة</p>
                    <p class="mt-1 text-xs font-bold text-gray-400">تتضمن {{ $orderRecordCounts['new'] }} سجل طلب</p>
                    <a href="{{ route('admin.orders.index') }}?status=new" class="text-xs text-indigo-600 font-bold mt-2 block hover:underline">عرض الكل ←</a>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-3xl">👁️</span>
                        <span class="text-xs font-bold text-purple-600 bg-purple-50 px-2 py-1 rounded-full">للموافقة</span>
                    </div>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $pendingPreview }}</p>
                    <p class="text-sm text-gray-500 mt-1">عمليات شراء تنتظر موافقة العميل</p>
                    <p class="mt-1 text-xs font-bold text-gray-400">تتضمن {{ $orderRecordCounts['preview_uploaded'] }} سجل طلب</p>
                    <a href="{{ route('admin.orders.index') }}?status=preview_uploaded" class="text-xs text-indigo-600 font-bold mt-2 block hover:underline">عرض الكل ←</a>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-3xl">🚚</span>
                        <span class="text-xs font-bold text-blue-600 bg-blue-50 px-2 py-1 rounded-full">شحن</span>
                    </div>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $shippedOrders }}</p>
                    <p class="text-sm text-gray-500 mt-1">عمليات شراء في الشحن</p>
                    <p class="mt-1 text-xs font-bold text-gray-400">تتضمن {{ $orderRecordCounts['shipped'] }} سجل طلب</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-3xl">✅</span>
                        <span class="text-xs font-bold text-green-600 bg-green-50 px-2 py-1 rounded-full">مكتمل</span>
                    </div>
                    <p class="text-3xl font-extrabold text-gray-900">{{ $deliveredOrders }}</p>
                    <p class="text-sm text-gray-500 mt-1">عمليات شراء تم تسليمها</p>
                    <p class="mt-1 text-xs font-bold text-gray-400">تتضمن {{ $orderRecordCounts['delivered'] }} سجل طلب</p>
                </div>
            </div>

            <!-- Stats Cards Row 2 -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
                <div class="bg-indigo-600 rounded-2xl p-6 shadow-sm text-white">
                    <span class="text-3xl mb-3 block">📊</span>
                    <p class="text-3xl font-extrabold">{{ $totalOrders }}</p>
                    <p class="text-indigo-200 text-sm mt-1">إجمالي عمليات الشراء</p>
                    <p class="mt-1 text-xs font-bold text-indigo-200">تتضمن {{ $orderRecordCounts['total'] }} سجل طلب</p>
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

            @can('analytics.view')
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="text-right">
                            <p class="text-xs font-black text-indigo-600">تحليلات الموقع</p>
                            <h3 class="mt-1 text-xl font-black text-gray-900">ملخص Google Analytics</h3>
                            <p class="mt-1 text-sm text-gray-500">بيانات مختصرة من GA4. التفاصيل الكاملة داخل صفحة التحليلات.</p>
                        </div>
                        <div class="grid grid-cols-3 gap-3 text-center">
                            @if(($analyticsWidget['status'] ?? null) === 'ready')
                                <div class="rounded-2xl bg-indigo-50 px-4 py-3">
                                    <p class="text-xs font-bold text-indigo-500">نشطون الآن</p>
                                    <p class="text-2xl font-black text-indigo-800">{{ $analyticsWidget['active_users_30m'] ?? '—' }}</p>
                                </div>
                                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                                    <p class="text-xs font-bold text-slate-500">مستخدمون اليوم</p>
                                    <p class="text-2xl font-black text-slate-900">{{ $analyticsWidget['users_today'] ?? '—' }}</p>
                                </div>
                                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                                    <p class="text-xs font-bold text-slate-500">جلسات اليوم</p>
                                    <p class="text-2xl font-black text-slate-900">{{ $analyticsWidget['sessions_today'] ?? '—' }}</p>
                                </div>
                            @else
                                <div class="col-span-3 rounded-2xl bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                                    {{ ($analyticsWidget['status'] ?? null) === 'setup_required' ? 'تحليلات GA4 تحتاج إعداد credentials.' : 'تعذر تحميل ملخص التحليلات حالياً.' }}
                                </div>
                            @endif
                        </div>
                        <a href="{{ route('admin.analytics.index') }}" class="inline-flex justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700">فتح التحليلات</a>
                    </div>
                </div>
            @endcan

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
                    <h3 class="font-bold text-gray-800 text-lg">آخر عمليات الشراء</h3>
                </div>
                @if($recentOrders->count())
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">الإجراء</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">الحالة</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">المحتويات</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">العميل</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">التاريخ</th>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">عملية الشراء</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach($recentOrders as $group)
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.orders.groups.show', $group['representative_id']) }}" class="text-indigo-600 font-bold text-xs hover:underline">تفاصيل</a>
                                </td>
                                <td class="px-4 py-3">
                                    @php($colorClass = $group['status'] === 'mixed' ? 'bg-gray-100 text-gray-700' : \App\Support\OrderStatusRegistry::color(\App\Support\OrderStatusRegistry::TYPE_ORDER, $group['status']))
                                    <span class="inline-block text-xs font-bold px-2 py-1 rounded-full {{ $colorClass }}">
                                        {{ $group['status_label'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700 text-right">
                                    <div class="flex flex-wrap justify-end gap-1">
                                        @if($group['story_count'])<span class="rounded-full bg-violet-50 px-2 py-1 text-xs font-bold text-violet-700">{{ $group['story_count'] }} قصة</span>@endif
                                        @if($group['add_on_quantity'])<span class="rounded-full bg-amber-50 px-2 py-1 text-xs font-bold text-amber-700">{{ $group['add_on_quantity'] }} إضافة</span>@endif
                                        @if($group['product_quantity'])<span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700">{{ $group['product_quantity'] }} منتج</span>@endif
                                    </div>
                                    <p class="mt-1 max-w-64 truncate text-xs text-gray-400">{{ implode('، ', array_merge($group['story_titles'], $group['add_on_titles'], $group['product_titles'])) }}</p>
                                </td>
                                <td class="px-4 py-3 text-sm font-bold text-gray-900 text-right">
                                    {{ $group['customer_name'] }}
                                    @if($group['child_names'])<p class="mt-1 text-xs font-normal text-gray-400">الأطفال: {{ implode('، ', $group['child_names']) }}</p>@endif
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-400 text-right">{{ optional($group['latest_at'])->format('d/m/Y') }}</td>
                                <td class="px-4 py-3 text-sm font-mono text-gray-600 text-right" dir="ltr">{{ $group['key'] }}</td>
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
