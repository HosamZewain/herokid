@php($activityCount = (int) data_get($orderActivity ?? [], 'count', 0))
<button
    type="button"
    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-700 shadow-sm transition hover:border-indigo-300 hover:bg-indigo-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
    aria-controls="order-activity-drawer"
    aria-expanded="false"
    data-order-activity-toggle
>
    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
    <span class="hidden sm:inline">سجل الطلب</span>
    <span class="inline-flex min-w-5 items-center justify-center rounded-full bg-indigo-600 px-1.5 py-0.5 text-[10px] text-white">{{ $activityCount }}</span>
</button>
