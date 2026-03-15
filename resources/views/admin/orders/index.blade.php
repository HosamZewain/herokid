<x-admin-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">إدارة الطلبات</h2>
            <span class="text-sm text-gray-500">إجمالي: {{ $orders->total() }} طلب</span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

            {{-- Flash message --}}
            @if(session('success'))
                <div class="bg-green-50 border border-green-300 text-green-800 px-5 py-3 rounded-xl flex items-center gap-2">
                    ✅ <span>{{ session('success') }}</span>
                </div>
            @endif

            {{-- Status filter tabs --}}
            @php
                $statuses = [
                    ''                  => 'الكل',
                    'new'               => 'جديد',
                    'under_review'      => 'مراجعة',
                    'generating'        => 'توليد',
                    'preview_uploaded'  => 'انتظار موافقة',
                    'approved_for_print'=> 'موافق للطباعة',
                    'printing'          => 'طباعة',
                    'shipped'           => 'شُحن',
                    'delivered'         => 'مُستلم',
                    'cancelled'         => 'ملغي',
                ];
                $currentStatus = request('status', '');
                $statusBadgeColors = [
                    'new'               => 'bg-blue-100 text-blue-700',
                    'under_review'      => 'bg-yellow-100 text-yellow-700',
                    'generating'        => 'bg-purple-100 text-purple-700',
                    'preview_uploaded'  => 'bg-orange-100 text-orange-700',
                    'approved_for_print'=> 'bg-teal-100 text-teal-700',
                    'printing'          => 'bg-indigo-100 text-indigo-700',
                    'shipped'           => 'bg-cyan-100 text-cyan-700',
                    'delivered'         => 'bg-green-100 text-green-700',
                    'cancelled'         => 'bg-red-100 text-red-700',
                ];
            @endphp

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <div class="flex flex-wrap gap-2">
                    @foreach($statuses as $val => $label)
                        <a href="{{ route('admin.orders.index', array_filter(['status' => $val ?: null])) }}"
                           class="px-3 py-1.5 rounded-lg text-sm font-semibold transition
                               {{ $currentStatus === $val
                                   ? 'bg-indigo-600 text-white shadow-sm'
                                   : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Orders table --}}
            <div class="bg-white overflow-hidden shadow-sm rounded-xl border border-gray-100">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-right" dir="rtl">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase">رقم الطلب</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase">الوالد</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase">الطفل</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase">القصة</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase">الحالة</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase">التاريخ</th>
                                <th class="px-5 py-3 text-xs font-bold text-gray-500 uppercase">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @forelse($orders as $order)
                                @php
                                    $rowColor = $order->status === 'preview_uploaded' ? 'bg-orange-50' : 'bg-white hover:bg-gray-50';
                                    $badgeColor = $statusBadgeColors[$order->status] ?? 'bg-gray-100 text-gray-700';
                                @endphp
                                <tr class="{{ $rowColor }} transition">
                                    <td class="px-5 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            @if($order->status === 'preview_uploaded')
                                                <span class="text-orange-500 text-sm">⚠️</span>
                                            @endif
                                            <span class="text-sm font-bold text-gray-900">#{{ $order->order_number }}</span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-800">{{ $order->parent_name ?? $order->customer_name ?? '—' }}</div>
                                        <div class="text-xs text-gray-400">{{ $order->customer_phone ?? '' }}</div>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap">
                                        <div class="text-sm font-semibold text-gray-900">{{ $order->child_name }}</div>
                                        <div class="text-xs text-gray-400">{{ $order->child_age }} سنة • {{ $order->child_gender === 'boy' ? '👦' : '👧' }}</div>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-700">{{ $order->story->title ?? '—' }}</div>
                                        <div class="text-xs text-gray-400">{{ $order->story->price ?? 0 }} ج.م</div>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap">
                                        <span class="px-2.5 py-1 inline-flex text-xs font-bold rounded-full {{ $badgeColor }}">
                                            {{ __('order_status.' . $order->status) }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $order->created_at->format('d/m/Y') }}
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap text-sm">
                                        <a href="{{ route('admin.orders.show', $order) }}"
                                           class="text-indigo-600 hover:text-indigo-900 font-semibold">
                                            عرض ←
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center">
                                        <div class="text-4xl mb-3">📭</div>
                                        <p class="text-gray-400 text-sm">لا توجد طلبات بهذه الحالة.</p>
                                        @if($currentStatus)
                                            <a href="{{ route('admin.orders.index') }}" class="text-indigo-500 text-sm mt-2 inline-block hover:underline">← عرض جميع الطلبات</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Pagination --}}
                @if($orders->hasPages())
                    <div class="px-5 py-4 border-t border-gray-100">
                        {{ $orders->links() }}
                    </div>
                @endif
            </div>

        </div>
    </div>
</x-admin-layout>
