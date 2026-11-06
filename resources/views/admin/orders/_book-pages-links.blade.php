@can('orders.photos.view')
@if(($bookPageRuns ?? collect())->isNotEmpty() && \Illuminate\Support\Facades\Route::has('admin.orders.codex-scenes.book-pages'))
<section class="my-4 space-y-3 rounded-2xl border border-indigo-200 bg-white p-4" aria-label="صفحات كتب التلوين المحفوظة">
    <h3 class="font-black text-indigo-900">صفحات كتب التلوين — العرض والتحميل</h3>
    @foreach($bookPageRuns as $bookRun)
    <div class="rounded-xl border p-3">
        <p class="font-bold">{{ $bookPageTitles[$bookRun->order_item_id] ?? 'كتاب تلوين' }}</p>
        <p class="my-2 text-sm">{{ count($bookRun->scenes ?? []) }} / {{ $bookRun->scene_count }} صفحة محفوظة · {{ \App\Models\CodexSceneRun::LABELS[$bookRun->status] ?? $bookRun->status }}</p>
        <a class="inline-block rounded-lg bg-indigo-700 px-3 py-2 text-sm font-bold text-white" href="{{ route('admin.orders.codex-scenes.book-pages', [$bookRun->order_id, $bookRun]) }}">عرض الصفحات المحفوظة وتحميلها</a>
    </div>
    @endforeach
</section>
@endif
@endcan
