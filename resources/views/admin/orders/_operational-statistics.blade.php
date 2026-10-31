<section aria-label="متابعة أعداد الطلبات" class="space-y-3" data-order-operational-statistics>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-black text-gray-800">متابعة الطلبات</h3>
        <p class="text-xs text-gray-500">اضغط على الحالة لعرض طلباتها · اسحب أفقيًا لباقي الحالات</p>
    </div>
    <div class="flex flex-nowrap items-center gap-2 overflow-x-auto p-1 pb-2" tabindex="0" role="region" aria-label="فلاتر متابعة الطلبات — تمرير أفقي" data-order-operational-tags>
        <a href="{{ route('admin.orders.index', array_merge(request()->except(['page', 'view', 'lifecycle']), ['lifecycle' => 'active'])) }}" class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-700 transition hover:shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
            <span>إجمالي الطلبات النشطة</span>
            <span class="rounded-full bg-white/70 px-2 py-0.5 font-black tabular-nums">{{ number_format($operationalStats['active_checkouts']) }}</span>
        </a>
        <a href="{{ route('admin.orders.index', array_merge(request()->except(['page', 'view', 'lifecycle', 'assignment']), ['lifecycle' => 'active', 'assignment' => 'unassigned'])) }}" title="طلبات نشطة لم يستلمها أي موظف بعد" class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-700 transition hover:shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
            <span>الطلبات غير المستلمة</span>
            <span class="rounded-full bg-white/70 px-2 py-0.5 font-black tabular-nums">{{ number_format($operationalStats['unassigned_checkouts']) }}</span>
        </a>
        @foreach($operationalStats['statuses'] as $statusCount)
            <a href="{{ route('admin.orders.index', array_merge(request()->except(['page', 'status']), ['status' => [$statusCount['key']]])) }}" class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-black/5 px-3 py-2 text-xs font-bold transition hover:shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 {{ \App\Support\OrderStatusRegistry::color(\App\Support\OrderStatusRegistry::TYPE_ORDER, $statusCount['key']) }}" data-order-status="{{ $statusCount['key'] }}">
                <span>{{ $statusCount['label'] }}</span>
                <span class="rounded-full bg-white/70 px-2 py-0.5 font-black tabular-nums">{{ number_format($statusCount['count']) }}</span>
            </a>
        @endforeach
    </div>
    <details class="text-[11px] text-gray-500">
        <summary class="w-fit cursor-pointer">توضيح الأعداد والفلاتر</summary>
        <p class="mt-1">كل عملية شراء تُحسب مرة واحدة داخل كل حالة، وقد تظهر في أكثر من حالة لو أجزاؤها بحالات مختلفة. روابط الحالات تستبدل فلتر حالة الطلب فقط؛ وغير المستلمة يعرض الطلبات النشطة بدون مسؤول. لعرض الملغي والمنتهي اختَر «كل الطلبات» أو التبويب المناسب.</p>
    </details>
</section>
