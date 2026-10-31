<section aria-label="متابعة أعداد الطلبات" class="space-y-3" data-order-operational-statistics>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-black text-gray-800">متابعة الطلبات</h3>
        <p class="text-xs text-gray-500">اضغط على أي كارت لعرض طلباته · مع الاحتفاظ بباقي الفلاتر</p>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <a href="{{ route('admin.orders.index', array_merge(request()->except(['page', 'view', 'lifecycle']), ['lifecycle' => 'active'])) }}" class="block rounded-2xl border border-indigo-100 bg-indigo-50 p-4 transition hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
            <p class="text-xs font-black text-indigo-700">إجمالي الطلبات النشطة</p>
            <p class="mt-2 text-2xl font-black text-indigo-950">{{ number_format($operationalStats['active_checkouts']) }}</p>
        </a>
        <a href="{{ route('admin.orders.index', array_merge(request()->except(['page', 'view', 'lifecycle', 'assignment']), ['lifecycle' => 'active', 'assignment' => 'unassigned'])) }}" class="block rounded-2xl border border-amber-100 bg-amber-50 p-4 transition hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
            <p class="text-xs font-black text-amber-700">الطلبات غير المستلمة</p>
            <p class="mt-2 text-2xl font-black text-amber-950">{{ number_format($operationalStats['unassigned_checkouts']) }}</p>
            <p class="mt-1 text-[11px] text-amber-700">طلبات نشطة لم يستلمها أي موظف بعد</p>
        </a>
    </div>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
        @foreach($operationalStats['statuses'] as $statusCount)
            <a href="{{ route('admin.orders.index', array_merge(request()->except(['page', 'status']), ['status' => [$statusCount['key']]])) }}" class="block rounded-2xl border border-black/5 p-4 transition hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 {{ \App\Support\OrderStatusRegistry::color(\App\Support\OrderStatusRegistry::TYPE_ORDER, $statusCount['key']) }}" data-order-status="{{ $statusCount['key'] }}">
                <p class="text-xs font-black">{{ $statusCount['label'] }}</p>
                <p class="mt-2 text-2xl font-black">{{ number_format($statusCount['count']) }}</p>
            </a>
        @endforeach
    </div>
    <p class="text-[11px] text-gray-500">كل عملية شراء تُحسب مرة واحدة داخل كل حالة، وقد تظهر في أكثر من كارت لو أجزاؤها بحالات مختلفة. كروت الحالات تستبدل فلتر حالة الطلب فقط؛ وكارت غير المستلمة يعرض الطلبات النشطة بدون مسؤول. لعرض الملغي والمنتهي اختَر «كل الطلبات» أو التبويب المناسب.</p>
</section>
