<div class="pointer-events-none fixed inset-0 z-50" data-studio-job-drawer data-job-log-url="{{ route('admin.production-studio.ai.jobs.log', $project) }}">
    <button type="button"
            class="absolute inset-0 bg-slate-950/50 opacity-0 backdrop-blur-[1px] transition-opacity duration-200"
            aria-label="إغلاق سجل التوليد"
            data-studio-job-drawer-overlay></button>

    <aside id="production-job-log-drawer"
           class="absolute inset-y-0 left-0 flex w-full max-w-xl -translate-x-full flex-col bg-gray-50 shadow-2xl transition-transform duration-200 ease-out sm:w-[36rem]"
           role="dialog"
           aria-modal="true"
           aria-hidden="true"
           aria-labelledby="production-job-log-title">
        <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-white p-4 sm:p-5">
            <div class="min-w-0 text-right">
                <h2 id="production-job-log-title" class="text-lg font-black text-gray-950">سجل مهام التوليد</h2>
                <p class="mt-1 text-xs font-bold text-gray-500">أحدث 100 مهمة في مشروع الاستوديو #{{ $project->id }}</p>
            </div>
            <div class="flex flex-shrink-0 items-center gap-2">
                <button type="button"
                        class="inline-flex h-10 items-center gap-2 rounded-lg bg-indigo-600 px-3 text-xs font-black text-white transition hover:bg-indigo-700 disabled:cursor-wait disabled:bg-indigo-300"
                        data-studio-job-log-refresh>
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" data-refresh-icon>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    <span data-refresh-label>تحديث</span>
                </button>
                <button type="button"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 transition hover:bg-gray-100 hover:text-gray-950 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        aria-label="إغلاق سجل التوليد"
                        data-studio-job-drawer-close>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        <div class="border-b border-gray-200 bg-white px-4 py-3 text-right text-xs font-bold text-gray-500" data-studio-job-log-status>
            افتح السجل أو اضغط تحديث لتحميل أحدث البيانات.
        </div>

        <div class="flex-1 overflow-y-auto p-3 sm:p-4">
            <div class="space-y-3" data-studio-job-list>
                <p class="rounded-xl bg-white p-4 text-center text-sm font-bold text-gray-500 ring-1 ring-gray-100" data-studio-empty-jobs>جارٍ انتظار تحميل السجل.</p>
            </div>
        </div>
    </aside>
</div>
