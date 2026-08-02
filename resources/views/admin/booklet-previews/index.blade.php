<x-admin-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-black text-gray-900">معاينات الكتب</h2>
                <p class="mt-1 text-xs font-bold text-gray-500">ملفات PDF خاصة بروابط ثابتة للعميل ومعاينات القصص</p>
            </div>
            @can('booklet_previews.create')
                <a href="{{ route('admin.booklet-previews.create') }}" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white hover:bg-indigo-700">+ إضافة معاينة</a>
            @endcan
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 py-5 sm:py-8" dir="rtl">
        @if(session('success'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 font-bold text-emerald-800">{{ session('success') }}</div>
        @endif

        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach([
                ['فعالة', $stats['active'], 'text-emerald-700'],
                ['موقوفة', $stats['revoked'], 'text-rose-700'],
                ['منشورة على القصص', $stats['published'], 'text-indigo-700'],
                ['سلة المحذوفات', $stats['trashed'], 'text-slate-700'],
            ] as [$label, $count, $class])
                <article class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                    <p class="text-xs font-black text-gray-500">{{ $label }}</p>
                    <p class="mt-2 text-2xl font-black {{ $class }}">{{ $count }}</p>
                </article>
            @endforeach
        </section>

        <section class="rounded-3xl border border-gray-100 bg-white p-4 shadow-sm sm:p-6">
            <div class="mb-5 flex flex-wrap gap-2">
                <a href="{{ route('admin.booklet-previews.index') }}" class="rounded-xl px-4 py-2 text-sm font-black {{ !$trash ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700' }}">المعاينات الحالية</a>
                <a href="{{ route('admin.booklet-previews.index', ['trash' => 1]) }}" class="rounded-xl px-4 py-2 text-sm font-black {{ $trash ? 'bg-rose-600 text-white' : 'bg-rose-50 text-rose-700' }}">سلة المحذوفات</a>
            </div>

            <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                @if($trash)<input type="hidden" name="trash" value="1">@endif
                <input type="search" name="q" value="{{ request('q') }}" placeholder="العنوان أو القصة أو رقم الطلب" class="rounded-xl border-gray-200 text-sm lg:col-span-2">
                <select name="source_type" class="rounded-xl border-gray-200 text-sm">
                    <option value="">كل المصادر</option>
                    <option value="order" @selected(request('source_type') === 'order')>طلب عميل</option>
                    <option value="story" @selected(request('source_type') === 'story')>قصة</option>
                    <option value="standalone" @selected(request('source_type') === 'standalone')>مستقلة</option>
                </select>
                <select name="story_id" class="rounded-xl border-gray-200 text-sm">
                    <option value="">كل القصص</option>
                    @foreach($stories as $story)
                        <option value="{{ $story->id }}" @selected((string) request('story_id') === (string) $story->id)>{{ $story->title }}</option>
                    @endforeach
                </select>
                <select name="status" class="rounded-xl border-gray-200 text-sm">
                    <option value="">كل الحالات</option>
                    <option value="active" @selected(request('status') === 'active')>فعال</option>
                    <option value="revoked" @selected(request('status') === 'revoked')>موقوف</option>
                </select>
                <button class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-black text-white">تطبيق</button>
            </form>
        </section>

        <section class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
            @forelse($previews as $preview)
                @php($publicUrl = $preview->publicUrl())
                <article class="border-b border-gray-100 p-4 last:border-b-0 sm:p-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="min-w-0 text-right">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="truncate text-base font-black text-gray-900">{{ $preview->title }}</h3>
                                <span class="rounded-full px-2.5 py-1 text-[11px] font-black {{ $preview->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">{{ $preview->status === 'active' ? 'فعال' : 'موقوف' }}</span>
                                @if($preview->show_on_story)<span class="rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] font-black text-indigo-700">منشورة</span>@endif
                            </div>
                            <p class="mt-2 text-xs font-bold text-gray-500">
                                @if($preview->order)طلب {{ $preview->order->order_number }}
                                @elseif($preview->story)قصة: {{ $preview->story->title }}
                                @else معاينة مستقلة
                                @endif
                                @if($preview->currentVersion) · إصدار {{ $preview->currentVersion->version_number }} · {{ $preview->currentVersion->page_count }} صفحة @endif
                                · {{ $preview->view_count }} مشاهدة
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if(!$trash)
                                <a href="{{ route('admin.booklet-previews.show', $preview) }}" class="rounded-xl bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-700">إدارة</a>
                                @if($preview->status === 'active' && $publicUrl)
                                    <a href="{{ $publicUrl }}" target="_blank" rel="noopener" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-black text-slate-700">فتح</a>
                                    <button type="button" data-copy-value="{{ $publicUrl }}" class="rounded-xl bg-emerald-50 px-3 py-2 text-xs font-black text-emerald-700">نسخ الرابط</button>
                                @endif
                            @else
                                @can('booklet_previews.delete')
                                    <form action="{{ route('admin.booklet-previews.restore', $preview->uuid) }}" method="POST">@csrf<button class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-black text-white">استعادة</button></form>
                                @endcan
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="px-6 py-16 text-center">
                    <p class="text-lg font-black text-gray-800">لا توجد معاينات كتب بعد</p>
                    <p class="mt-2 text-sm text-gray-500">ابدأ برفع ملف PDF لقصة أو معاينة مستقلة.</p>
                </div>
            @endforelse
        </section>

        {{ $previews->links() }}
    </div>

    @push('scripts')
    <script>
    document.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-copy-value]');
        if (!button) return;
        try {
            await navigator.clipboard.writeText(button.dataset.copyValue);
            const original = button.textContent;
            button.textContent = 'تم النسخ ✓';
            setTimeout(() => button.textContent = original, 1800);
        } catch (_) { window.prompt('انسخ الرابط:', button.dataset.copyValue); }
    });
    </script>
    @endpush
</x-admin-layout>
