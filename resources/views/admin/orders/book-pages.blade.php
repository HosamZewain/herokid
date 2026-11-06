<x-admin-layout>
    <x-slot name="header"><h2 class="text-xl font-bold">صفحات كتاب التلوين</h2></x-slot>
    <main class="mx-auto max-w-7xl space-y-5 p-4" dir="rtl">
        <a class="font-bold text-indigo-700 underline" href="{{ route('admin.orders.groups.show', $order) }}">العودة للطلب</a>
        <section class="space-y-3 rounded-2xl border bg-white p-4">
            <h3 class="text-lg font-black">{{ count($run->scenes ?? []) }} / {{ $run->scene_count }} صفحة محفوظة</h3>
            <p>{{ \App\Models\CodexSceneRun::LABELS[$run->status] ?? $run->status }}</p>
            <a class="inline-block font-bold text-indigo-700 underline" href="{{ route('admin.orders.codex-scenes.book-pages', [$order, $run, 'page' => request('page', 1)]) }}">تحديث تقدم الصفحات</a>
            @if(in_array($run->book_pdf['recovery']['status'] ?? '', ['queued', 'working']))<p class="font-bold text-indigo-800">طلب استكمال الصفحات الناقصة مسجّل؛ تابع التقدم حتى تجهيز الملف الكامل.</p>@endif
            @if($missing)<p class="font-bold text-amber-800">الصفحات الناقصة: {{ implode('، ', $missing) }}. ملف PDF الطباعة يظهر بعد اكتمال العدد.</p>@endif
            @if(count($run->scenes ?? []))
                <a class="inline-block rounded-lg bg-indigo-700 px-4 py-2 font-bold text-white" href="{{ route('admin.orders.codex-scenes.download', [$order, $run]) }}">تحميل الصفحات المحفوظة كلها — ZIP</a>
                <p class="text-sm text-gray-600">الصور بترقيمها الأصلي. التحميل يشمل المحفوظ حاليًا ويحتوي بيانًا بالصفحات الناقصة.</p>
            @endif
            @if(($run->book_pdf['complete'] ?? false) && ($run->book_pdf['status'] ?? '') === 'ready')
                <a class="inline-block rounded-lg bg-emerald-700 px-4 py-2 font-bold text-white" href="{{ route('admin.orders.attachments.download', $run->book_pdf['attachment_id']) }}">تحميل PDF الطباعة الكامل</a>
            @endif
        </section>
        @foreach($errors->all() as $message)<p role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ $message }}</p>@endforeach
        @if(session('success'))<p role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-800">{{ session('success') }}</p>@endif
        @if($run->book_pdf['recovery']['reason'] ?? null)<p role="status" class="rounded-xl bg-amber-50 p-4 text-amber-900">{{ $run->book_pdf['recovery']['reason'] }}</p>@endif
        @if($reviewError)<p class="rounded-xl bg-amber-50 p-4 text-amber-900">{{ $reviewError }}</p>@endif
        @if($review)
        <section class="space-y-3 rounded-2xl border border-amber-300 bg-amber-50 p-4">
            <h3 class="font-black">مراجعة الصفحات التي رفضها التوليد</h3>
            <p>راجع وصف كل صفحة ومحتوى الكتاب قبل طلب محاولة أخرى. ستُستخدم نفس الهوية والمحتوى الأصلي، وتخضع المحاولة لفحص المحتوى المعتاد. إذا تعثرت أيضًا ستظل نتيجتها محفوظة للمراجعة.</p>
            @foreach($review['pages'] as $number => $text)
                <div class="rounded-xl bg-white p-3"><h4 class="font-bold">الصفحة {{ $number }}</h4><p class="mt-2 whitespace-pre-wrap text-sm" dir="auto">{{ $text['text'] }}</p></div>
            @endforeach
            <details class="rounded-xl bg-white p-3"><summary class="cursor-pointer font-bold">تعليمات الكتاب الأصلية للمراجعة</summary><p class="mt-2 whitespace-pre-wrap text-sm" dir="auto">{{ $run->snapshot['prompt'] ?? '' }}</p></details>
            <form method="POST" action="{{ route('admin.orders.workspace.book-pages-retry', [$order, $run]) }}" class="space-y-3">
                @csrf
                <input type="hidden" name="preview_token" value="{{ app(\App\Services\CodexScenes\BookPageRecovery::class)->reviewToken($review) }}">
                <label class="flex gap-2 font-bold"><input type="checkbox" name="confirm_review" value="1" required> راجعت المحتوى المعروض وأوافق على محاولة واحدة للصفحات الناقصة ضمن ضوابط التوليد المعتادة.</label>
                <button class="rounded-lg bg-indigo-700 px-4 py-2 font-bold text-white">طلب محاولة واحدة بعد مراجعة المحتوى</button>
            </form>
        </section>
        @elseif($canReview && $run->status === 'needs_manual' && empty($run->book_pdf['recovery']))
            <form method="POST" action="{{ route('admin.orders.codex-scenes.recover-book', [$order, $run]) }}" class="rounded-xl border bg-white p-4">
                @csrf
                <input type="hidden" name="expected_updated_at" value="{{ $run->updated_at->toISOString() }}">
                <button name="confirm" value="1" class="rounded-lg bg-indigo-700 px-4 py-2 font-bold text-white">استكمال الصفحات الناقصة وتجهيز PDF كامل</button>
            </form>
        @endif
        {{ $pages->links() }}
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" aria-label="صور صفحات الكتاب">
        @foreach($pages as $number)
            @php($scene = ($run->scenes ?? [])[$number] ?? null)
            <article class="space-y-3 rounded-2xl border bg-white p-3">
                <h3 class="font-bold">الصفحة {{ $number }}</h3>
                @if($scene)
                    <a href="{{ route('admin.orders.codex-scenes.image', [$order, $run, $number]) }}" target="_blank" rel="noopener"><img class="mx-auto max-h-80 w-auto rounded-lg" src="{{ route('admin.orders.codex-scenes.image', [$order, $run, $number, 'thumbnail' => 1]) }}" loading="lazy" alt="الصفحة {{ $number }} من كتاب التلوين"></a>
                    <a class="inline-block font-bold text-indigo-700 underline" href="{{ route('admin.orders.codex-scenes.image', [$order, $run, $number, 'download' => 1]) }}">تحميل الصفحة {{ $number }}</a>
                @else
                    <p class="rounded-xl bg-amber-50 p-3 font-bold text-amber-800">لم تُحفظ هذه الصفحة بعد</p>
                    <p class="text-sm">{{ \App\Models\CodexSceneRun::ERRORS[$run->scene_progress[$number]['code'] ?? ''] ?? 'تحتاج الصفحة مراجعة نتيجة التوليد.' }}</p>
                @endif
            </article>
        @endforeach
        </section>
        {{ $pages->links() }}
    </main>
</x-admin-layout>
