@forelse($jobs as $job)
    @include('admin.production-studio.partials.generation-job-row', ['job' => $job])
@empty
    <p class="rounded-xl bg-white p-4 text-center text-sm font-bold text-gray-500 ring-1 ring-gray-100" data-studio-empty-jobs>لا توجد مهام توليد بعد.</p>
@endforelse
