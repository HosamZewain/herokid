<x-admin-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-black text-gray-900">{{ $preview->title }}</h2>
                <p class="mt-1 text-xs font-bold text-gray-500">إصدار {{ $preview->currentVersion?->version_number }} · {{ $preview->currentVersion?->page_count }} صفحة · {{ $preview->view_count }} مشاهدة</p>
            </div>
            <a href="{{ route('admin.booklet-previews.index') }}" class="rounded-xl bg-slate-100 px-4 py-2.5 text-sm font-black text-slate-700">كل المعاينات</a>
        </div>
    </x-slot>

    @php
        $publicUrl = $preview->publicUrl();
        $publicScenesUrl = $preview->publicScenesUrl();
        $whatsAppText = 'مرحبًا، يمكنك مشاهدة معاينة قصة HeroKid من الرابط التالي: '.$publicUrl;
    @endphp
    <div class="mx-auto max-w-6xl space-y-6 py-5 sm:py-8" dir="rtl">
        @if(session('success'))<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 font-bold text-emerald-800">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-bold text-rose-700"><ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <section class="rounded-3xl border p-5 shadow-sm sm:p-7 {{ $preview->status === 'active' ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50' }}">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-sm font-black {{ $preview->status === 'active' ? 'text-emerald-800' : 'text-rose-800' }}">{{ $preview->status === 'active' ? 'الرابط فعال وجاهز للإرسال' : 'الرابط موقوف' }}</p>
                    @if($preview->status === 'active' && $publicUrl)<p class="mt-2 break-all text-xs font-bold text-slate-600" dir="ltr">{{ $publicUrl }}</p>@endif
                    @if($preview->revocation_reason)<p class="mt-2 text-xs font-bold text-rose-700">سبب الإيقاف: {{ $preview->revocation_reason }}</p>@endif
                </div>
                <div class="flex flex-wrap gap-2">
                    @if($preview->status === 'active' && $publicUrl)
                        <a href="{{ $publicUrl }}" target="_blank" rel="noopener" class="rounded-xl bg-white px-4 py-2.5 text-sm font-black text-indigo-700 shadow-sm">قارئ التقليب</a>
                        <a href="{{ $publicScenesUrl }}" target="_blank" rel="noopener" class="rounded-xl bg-white px-4 py-2.5 text-sm font-black text-violet-700 shadow-sm">قارئ المشاهد</a>
                        <button type="button" data-copy-value="{{ $publicUrl }}" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white">نسخ رابط التقليب</button>
                        <button type="button" data-copy-value="{{ $publicScenesUrl }}" class="rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-black text-white">نسخ رابط المشاهد</button>
                        <a href="https://wa.me/?text={{ urlencode($whatsAppText) }}" target="_blank" rel="noopener" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white">واتساب</a>
                    @endif
                </div>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            @can('booklet_previews.update')
                <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-6">
                    <h3 class="mb-4 text-base font-black text-gray-900">بيانات المعاينة</h3>
                    <form action="{{ route('admin.booklet-previews.update', $preview) }}" method="POST" class="space-y-4">
                        @csrf @method('PATCH')
                        <label class="block"><span class="mb-2 block text-xs font-black text-gray-600">العنوان</span><input name="title" value="{{ old('title', $preview->title) }}" required class="w-full rounded-xl border-gray-200"></label>
                        @if($preview->source_type !== 'order')
                            <label class="block"><span class="mb-2 block text-xs font-black text-gray-600">القصة المرتبطة</span><select name="story_id" class="w-full rounded-xl border-gray-200"><option value="">بدون قصة — مستقلة</option>@foreach($stories as $story)<option value="{{ $story->id }}" @selected((string) old('story_id', $preview->story_id) === (string) $story->id)>{{ $story->title }}</option>@endforeach</select></label>
                        @else
                            <input type="hidden" name="story_id" value="">
                        @endif
                        <label class="block"><span class="mb-2 block text-xs font-black text-gray-600">اتجاه القراءة</span><select name="reading_direction" class="w-full rounded-xl border-gray-200"><option value="rtl" @selected($preview->reading_direction === 'rtl')>يمين إلى يسار</option><option value="ltr" @selected($preview->reading_direction === 'ltr')>يسار إلى يمين</option></select></label>
                        <button class="w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-black text-white">حفظ البيانات</button>
                    </form>
                </section>

                <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-6">
                    <h3 class="mb-1 text-base font-black text-gray-900">رفع إصدار مصحح</h3>
                    <p class="mb-4 text-xs font-bold leading-6 text-gray-500">سيبقى نفس رابط العميل، وسيعرض الإصدار الجديد مباشرة.</p>
                    <form action="{{ route('admin.booklet-previews.versions.store', $preview) }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <input type="file" name="pdf_file" accept="application/pdf,.pdf" required class="block w-full rounded-xl border border-gray-200 p-2 text-sm file:ml-3 file:rounded-lg file:border-0 file:bg-indigo-600 file:px-4 file:py-2 file:font-black file:text-white">
                        <textarea name="note" rows="2" maxlength="1000" class="w-full rounded-xl border-gray-200 text-sm" placeholder="ما الذي تغير في هذا الإصدار؟"></textarea>
                        <button class="w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-black text-white">رفع إصدار جديد</button>
                    </form>
                </section>
            @endcan
        </div>

        @if($preview->story_id)
            @can('booklet_previews.publish')
                <section class="rounded-3xl border border-indigo-100 bg-indigo-50 p-5 sm:p-6">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><h3 class="font-black text-indigo-950">الظهور في صفحة القصة</h3><p class="mt-1 text-xs font-bold text-indigo-700">{{ $preview->show_on_story ? 'زر «معاينة القصة» ظاهر حاليًا.' : 'الرابط خاص ولن يظهر في صفحة القصة.' }}</p></div>
                        @if($preview->show_on_story)
                            <form action="{{ route('admin.booklet-previews.unpublish', $preview) }}" method="POST">@csrf @method('DELETE')<button class="rounded-xl bg-white px-4 py-2.5 text-sm font-black text-rose-700">إخفاء من القصة</button></form>
                        @else
                            <form action="{{ route('admin.booklet-previews.publish', $preview) }}" method="POST">@csrf<button class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white">نشر على صفحة القصة</button></form>
                        @endif
                    </div>
                </section>
            @endcan
        @endif

        <section class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-5 py-4"><h3 class="font-black text-gray-900">سجل الإصدارات</h3></div>
            @foreach($preview->versions as $version)
                <div class="flex flex-col gap-3 border-b border-gray-100 p-5 last:border-b-0 sm:flex-row sm:items-center sm:justify-between">
                    <div><p class="font-black text-gray-900">الإصدار {{ $version->version_number }} @if($preview->current_version_id === $version->id)<span class="mr-2 rounded-full bg-emerald-50 px-2 py-1 text-[10px] text-emerald-700">الحالي</span>@endif</p><p class="mt-1 text-xs font-bold text-gray-500">{{ $version->page_count }} صفحة · {{ number_format($version->file_size / 1048576, 2) }} MB · {{ $version->created_at->format('d/m/Y H:i') }} @if($version->uploader)· {{ $version->uploader->name }}@endif</p>@if($version->note)<p class="mt-2 text-sm text-gray-600">{{ $version->note }}</p>@endif</div>
                    @can('booklet_previews.download_source')<a href="{{ route('admin.booklet-previews.versions.download', [$preview, $version]) }}" class="rounded-xl bg-slate-100 px-3 py-2 text-center text-xs font-black text-slate-700">تنزيل الأصل للإدارة</a>@endcan
                </div>
            @endforeach
        </section>

        <section class="grid gap-4 sm:grid-cols-2">
            @can('booklet_previews.revoke')
                <div class="rounded-3xl border border-amber-200 bg-amber-50 p-5">
                    @if($preview->status === 'active')
                        <form action="{{ route('admin.booklet-previews.revoke', $preview) }}" method="POST" class="space-y-3">@csrf<label class="block text-sm font-black text-amber-900">سبب إيقاف الرابط<input name="reason" required minlength="3" class="mt-2 w-full rounded-xl border-amber-200 bg-white text-sm"></label><button class="w-full rounded-xl bg-amber-600 px-4 py-2.5 text-sm font-black text-white">إيقاف الرابط</button></form>
                    @else
                        <form action="{{ route('admin.booklet-previews.reenable', $preview) }}" method="POST">@csrf<button class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white">إعادة تفعيل الرابط</button></form>
                    @endif
                </div>
            @endcan
            @can('booklet_previews.delete')
                <div class="rounded-3xl border border-rose-200 bg-rose-50 p-5"><form action="{{ route('admin.booklet-previews.destroy', $preview) }}" method="POST" class="space-y-3">@csrf @method('DELETE')<label class="block text-sm font-black text-rose-900">سبب الحذف<input name="reason" required minlength="3" class="mt-2 w-full rounded-xl border-rose-200 bg-white text-sm"></label><button class="w-full rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-black text-white">نقل إلى سلة المحذوفات</button></form></div>
            @endcan
        </section>
    </div>

    @push('scripts')
    <script>
    document.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-copy-value]'); if (!button) return;
        try { await navigator.clipboard.writeText(button.dataset.copyValue); const original = button.textContent; button.textContent = 'تم النسخ ✓'; setTimeout(() => button.textContent = original, 1800); }
        catch (_) { window.prompt('انسخ الرابط:', button.dataset.copyValue); }
    });
    </script>
    @endpush
</x-admin-layout>
