<div data-order-list-panel data-url="{{ route('admin.orders.groups.list-panel', [$group['representative_id'], $panel]) }}">
    <p class="p-3 text-xs text-gray-500" role="status">تُحمّل التفاصيل عند الفتح.</p>
    <a href="{{ route('admin.orders.groups.show', $group['representative_id']) }}" class="text-xs font-bold text-indigo-700 underline">فتح الطلب</a>
</div>
