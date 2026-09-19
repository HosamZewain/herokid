<x-admin-layout>
    <x-slot name="header">
        <div class="text-right">
            <h1 class="text-2xl font-black text-slate-900">مكتبة الوسائط</h1>
            <p class="mt-1 text-sm font-bold text-slate-500">ملفات دائمة بروابط عامة قابلة للمشاركة</p>
        </div>
    </x-slot>

    @php
        $formatBytes = static function (int $bytes): string {
            if ($bytes < 1024) return $bytes.' بايت';
            if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1).' ك.ب';
            if ($bytes < 1024 * 1024 * 1024) return number_format($bytes / (1024 * 1024), 1).' م.ب';
            return number_format($bytes / (1024 * 1024 * 1024), 2).' ج.ب';
        };
    @endphp

    <div class="mx-auto max-w-7xl space-y-6 py-5 sm:py-8" dir="rtl">
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach([
                ['كل الملفات', $stats['count'], 'bg-indigo-50 text-indigo-700', '📚'],
                ['الصور', $stats['images'], 'bg-emerald-50 text-emerald-700', '🖼️'],
                ['PDF وTXT', $stats['documents'], 'bg-amber-50 text-amber-700', '📄'],
                ['المساحة المستخدمة', $formatBytes($stats['size']), 'bg-sky-50 text-sky-700', '💾'],
            ] as [$label, $value, $classes, $icon])
                <article class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm sm:p-5">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-black text-slate-500">{{ $label }}</p>
                            <p class="mt-2 text-2xl font-black {{ $classes }} inline-block rounded-xl px-3 py-1">{{ $value }}</p>
                        </div>
                        <span class="text-3xl" aria-hidden="true">{{ $icon }}</span>
                    </div>
                </article>
            @endforeach
        </section>

        @can('media_library.upload')
            <section class="overflow-hidden rounded-3xl border border-indigo-100 bg-white shadow-sm" data-media-uploader
                data-start-url="{{ route('admin.media-library.uploads.store') }}">
                <div class="bg-gradient-to-l from-indigo-700 to-violet-600 px-5 py-6 text-white sm:px-7">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-xl font-black">رفع ملفات جديدة</h2>
                            <p class="mt-1 text-sm font-bold text-indigo-100">PDF أو TXT أو صور JPG وPNG وGIF وWebP — حتى 300MB للملف</p>
                        </div>
                        <label class="inline-flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-xl bg-white px-5 py-3 font-black text-indigo-700 shadow-lg transition hover:bg-indigo-50">
                            <input type="file" multiple class="sr-only" accept=".pdf,.txt,.jpg,.jpeg,.png,.gif,.webp" data-media-file-input>
                            <span class="text-xl" aria-hidden="true">＋</span>
                            اختيار ملفات
                        </label>
                    </div>
                </div>

                <div class="m-5 rounded-2xl border-2 border-dashed border-indigo-200 bg-indigo-50/50 p-8 text-center transition sm:m-7"
                    data-media-dropzone>
                    <p class="text-3xl" aria-hidden="true">☁️</p>
                    <p class="mt-3 font-black text-slate-800">اسحب الملفات وأفلتها هنا</p>
                    <p class="mt-1 text-xs font-bold text-slate-500">الرفع يتم في أجزاء صغيرة ويمكنك متابعة تقدم كل ملف دون تعطيل الصفحة.</p>
                </div>

                <div class="hidden border-t border-slate-100 px-5 py-5 sm:px-7" data-media-queue-wrap>
                    <h3 class="mb-3 text-sm font-black text-slate-700">حالة الرفع</h3>
                    <div class="space-y-3" data-media-queue></div>
                </div>
            </section>
        @endcan

        <section class="rounded-3xl border border-slate-100 bg-white p-4 shadow-sm sm:p-5">
            <form method="GET" class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <label class="min-w-0 flex-1">
                    <span class="sr-only">بحث في مكتبة الوسائط</span>
                    <input type="search" name="q" value="{{ $search }}" placeholder="ابحث باسم الملف أو اسم من رفعه..."
                        class="w-full rounded-xl border-slate-200 bg-slate-50 text-sm focus:border-indigo-500 focus:bg-white focus:ring-indigo-500">
                </label>
                <select name="type" class="rounded-xl border-slate-200 bg-white text-sm font-bold text-slate-700">
                    <option value="all" @selected($type === 'all')>كل الأنواع</option>
                    <option value="image" @selected($type === 'image')>الصور</option>
                    <option value="pdf" @selected($type === 'pdf')>PDF</option>
                    <option value="text" @selected($type === 'text')>TXT</option>
                </select>
                <button class="rounded-xl bg-slate-900 px-6 py-2.5 text-sm font-black text-white hover:bg-slate-800">بحث</button>
                @if($search !== '' || $type !== 'all')
                    <a href="{{ route('admin.media-library.index') }}" class="rounded-xl bg-slate-100 px-4 py-2.5 text-center text-sm font-black text-slate-600">مسح</a>
                @endif
            </form>
        </section>

        <section>
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-black text-slate-900">الملفات</h2>
                <p class="text-xs font-bold text-slate-500">{{ $files->total() }} ملف</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-media-file-grid>
                @forelse($files as $media)
                    @php
                        $category = $media->category();
                        $publicUrl = $media->publicUrl();
                        $icon = match($category) { 'image' => '🖼️', 'pdf' => '📕', default => '📝' };
                        $tone = match($category) { 'image' => 'bg-emerald-50 text-emerald-700', 'pdf' => 'bg-rose-50 text-rose-700', default => 'bg-sky-50 text-sky-700' };
                    @endphp
                    <article class="group rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-md">
                        <div class="flex items-start gap-4">
                            <div class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl text-2xl {{ $tone }}" aria-hidden="true">{{ $icon }}</div>
                            <div class="min-w-0 flex-1 text-right">
                                <h3 class="truncate font-black text-slate-900" title="{{ $media->original_name }}">{{ $media->original_name }}</h3>
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] font-black text-slate-500">
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1">{{ strtoupper($media->extension) }}</span>
                                    <span>{{ $formatBytes($media->size) }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 rounded-xl bg-slate-50 px-3 py-2.5 text-xs font-bold text-slate-500">
                            <p>رفعه: <span class="text-slate-700">{{ $media->uploaded_by_name }}</span></p>
                            <p class="mt-1">{{ $media->created_at->timezone(config('app.timezone'))->translatedFormat('d F Y، h:i A') }}</p>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-2">
                            <button type="button" data-copy-url="{{ $publicUrl }}"
                                class="rounded-xl bg-indigo-600 px-3 py-2.5 text-xs font-black text-white transition hover:bg-indigo-700">نسخ الرابط</button>
                            <a href="{{ $publicUrl }}" target="_blank" rel="noopener noreferrer"
                                class="rounded-xl bg-slate-100 px-3 py-2.5 text-center text-xs font-black text-slate-700 transition hover:bg-slate-200">فتح الملف</a>
                        </div>
                    </article>
                @empty
                    <div class="rounded-3xl border-2 border-dashed border-slate-200 bg-white px-6 py-16 text-center sm:col-span-2 xl:col-span-3">
                        <p class="text-4xl" aria-hidden="true">📂</p>
                        <h3 class="mt-4 text-lg font-black text-slate-800">لا توجد ملفات مطابقة</h3>
                        <p class="mt-1 text-sm font-bold text-slate-500">ارفع أول ملف أو غيّر خيارات البحث.</p>
                    </div>
                @endforelse
            </div>

            @if($files->hasPages())
                <div class="mt-6">{{ $files->links() }}</div>
            @endif
        </section>
    </div>

    @push('scripts')
        <script>
            (() => {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const uploader = document.querySelector('[data-media-uploader]');
                const allowedExtensions = new Set(['pdf', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp']);
                const maxBytes = 300 * 1024 * 1024;

                const humanBytes = (bytes) => {
                    if (bytes < 1024) return `${bytes} B`;
                    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
                    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
                };

                const errorMessage = async (response) => {
                    let payload = {};
                    try { payload = await response.json(); } catch (_) {}
                    const errors = payload.errors ? Object.values(payload.errors).flat() : [];
                    return errors[0] || payload.message || 'تعذر إكمال الرفع. حاول مرة أخرى.';
                };

                const jsonRequest = async (url, options = {}) => {
                    const response = await fetch(url, {
                        ...options,
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            ...(options.headers || {}),
                        },
                    });
                    if (!response.ok) throw new Error(await errorMessage(response));
                    return response.status === 204 ? {} : response.json();
                };

                const copyUrl = async (button) => {
                    await navigator.clipboard.writeText(button.dataset.copyUrl);
                    const oldText = button.textContent;
                    button.textContent = 'تم النسخ ✓';
                    setTimeout(() => button.textContent = oldText, 1600);
                };

                document.addEventListener('click', (event) => {
                    const button = event.target.closest('[data-copy-url]');
                    if (button) copyUrl(button).catch(() => window.prompt('انسخ الرابط:', button.dataset.copyUrl));
                });

                if (!uploader) return;

                const input = uploader.querySelector('[data-media-file-input]');
                const dropzone = uploader.querySelector('[data-media-dropzone]');
                const queueWrap = uploader.querySelector('[data-media-queue-wrap]');
                const queueElement = uploader.querySelector('[data-media-queue]');
                const queue = [];
                let processing = false;

                const renderItem = (item) => {
                    const row = document.createElement('article');
                    row.className = 'rounded-2xl border border-slate-200 bg-white p-4';
                    row.innerHTML = `
                        <div class="flex items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="truncate text-sm font-black text-slate-800" data-name></p>
                                    <span class="shrink-0 text-xs font-black text-slate-500" data-percent>في الانتظار</span>
                                </div>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full w-0 rounded-full bg-indigo-600 transition-all" data-bar></div>
                                </div>
                                <p class="mt-2 text-xs font-bold text-slate-500" data-status></p>
                                <div class="mt-3 hidden flex-wrap gap-2" data-result></div>
                            </div>
                            <button type="button" class="rounded-lg bg-rose-50 px-3 py-2 text-xs font-black text-rose-700" data-cancel>إلغاء</button>
                        </div>`;
                    row.querySelector('[data-name]').textContent = `${item.file.name} · ${humanBytes(item.file.size)}`;
                    row.querySelector('[data-status]').textContent = 'سيبدأ الرفع تلقائيًا.';
                    item.row = row;
                    item.bar = row.querySelector('[data-bar]');
                    item.percent = row.querySelector('[data-percent]');
                    item.status = row.querySelector('[data-status]');
                    item.cancel = row.querySelector('[data-cancel]');
                    item.result = row.querySelector('[data-result]');
                    item.cancel.addEventListener('click', () => cancelItem(item));
                    queueElement.appendChild(row);
                };

                const setProgress = (item, loaded) => {
                    const percent = Math.min(100, Math.round((loaded / item.file.size) * 100));
                    item.bar.style.width = `${percent}%`;
                    item.percent.textContent = `${percent}%`;
                    item.status.textContent = `${humanBytes(Math.min(loaded, item.file.size))} من ${humanBytes(item.file.size)}`;
                };

                const failItem = (item, message) => {
                    item.failed = true;
                    item.bar.classList.remove('bg-indigo-600');
                    item.bar.classList.add('bg-rose-500');
                    item.percent.textContent = 'فشل';
                    item.percent.className = 'shrink-0 text-xs font-black text-rose-600';
                    item.status.textContent = message;
                    item.status.className = 'mt-2 text-xs font-bold text-rose-600';
                    item.cancel.textContent = 'إخفاء';
                };

                const cancelItem = async (item) => {
                    if (item.done || item.failed || item.cancelled) {
                        item.row.remove();
                        return;
                    }
                    item.cancelled = true;
                    item.xhr?.abort();
                    if (item.session?.cancel_url) {
                        try { await jsonRequest(item.session.cancel_url, { method: 'DELETE' }); } catch (_) {}
                    }
                    item.percent.textContent = 'ملغي';
                    item.status.textContent = 'تم إلغاء الرفع.';
                    item.bar.classList.add('bg-slate-400');
                    item.cancel.textContent = 'إخفاء';
                };

                const sendChunk = (item, blob, index, loadedBefore) => new Promise((resolve, reject) => {
                    const form = new FormData();
                    form.append('index', String(index));
                    form.append('chunk', blob, `${item.file.name}.part`);

                    const xhr = new XMLHttpRequest();
                    item.xhr = xhr;
                    xhr.open('POST', item.session.chunk_url);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
                    xhr.upload.addEventListener('progress', (event) => {
                        if (event.lengthComputable) setProgress(item, loadedBefore + event.loaded);
                    });
                    xhr.addEventListener('load', () => {
                        item.xhr = null;
                        if (xhr.status >= 200 && xhr.status < 300) return resolve();
                        try {
                            const payload = JSON.parse(xhr.responseText);
                            const errors = payload.errors ? Object.values(payload.errors).flat() : [];
                            reject(new Error(errors[0] || payload.message || 'تعذر رفع جزء من الملف.'));
                        } catch (_) { reject(new Error('تعذر رفع جزء من الملف.')); }
                    });
                    xhr.addEventListener('error', () => reject(new Error('انقطع الاتصال أثناء الرفع.')));
                    xhr.addEventListener('abort', () => reject(new Error('تم إلغاء الرفع.')));
                    xhr.send(form);
                });

                const uploadItem = async (item) => {
                    item.percent.textContent = '0%';
                    item.status.textContent = 'جاري بدء الرفع...';
                    const started = await jsonRequest(uploader.dataset.startUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ file_name: item.file.name, size: item.file.size, mime: item.file.type || null }),
                    });
                    item.session = started.data;

                    for (let index = 0; index < item.session.total_chunks; index++) {
                        if (item.cancelled) return;
                        const start = index * item.session.chunk_size;
                        const end = Math.min(start + item.session.chunk_size, item.file.size);
                        await sendChunk(item, item.file.slice(start, end), index, start);
                        setProgress(item, end);
                    }

                    if (item.cancelled) return;
                    item.status.textContent = 'جاري فحص وتجهيز الرابط العام...';
                    const completed = await jsonRequest(item.session.complete_url, { method: 'POST' });
                    item.done = true;
                    setProgress(item, item.file.size);
                    item.percent.textContent = 'تم ✓';
                    item.percent.className = 'shrink-0 text-xs font-black text-emerald-600';
                    item.bar.classList.remove('bg-indigo-600');
                    item.bar.classList.add('bg-emerald-500');
                    item.status.textContent = 'تم الحفظ في المكتبة وإنشاء الرابط العام.';
                    item.cancel.classList.add('hidden');
                    item.result.classList.remove('hidden');
                    item.result.classList.add('flex');

                    const copy = document.createElement('button');
                    copy.type = 'button';
                    copy.dataset.copyUrl = completed.data.public_url;
                    copy.className = 'rounded-lg bg-indigo-600 px-3 py-2 text-xs font-black text-white';
                    copy.textContent = 'نسخ الرابط';
                    const open = document.createElement('a');
                    open.href = completed.data.public_url;
                    open.target = '_blank';
                    open.rel = 'noopener noreferrer';
                    open.className = 'rounded-lg bg-slate-100 px-3 py-2 text-xs font-black text-slate-700';
                    open.textContent = 'فتح الملف';
                    item.result.append(copy, open);
                };

                const processQueue = async () => {
                    if (processing) return;
                    processing = true;
                    for (const item of queue) {
                        if (item.done || item.failed || item.cancelled) continue;
                        try { await uploadItem(item); }
                        catch (error) {
                            if (!item.cancelled) {
                                if (item.session?.cancel_url) {
                                    try { await jsonRequest(item.session.cancel_url, { method: 'DELETE' }); } catch (_) {}
                                }
                                failItem(item, error.message);
                            }
                        }
                    }
                    processing = false;
                };

                const addFiles = (files) => {
                    queueWrap.classList.remove('hidden');
                    [...files].forEach((file) => {
                        const extension = file.name.split('.').pop().toLowerCase();
                        const item = { file, done: false, failed: false, cancelled: false };
                        queue.push(item);
                        renderItem(item);
                        if (!allowedExtensions.has(extension)) failItem(item, 'نوع الملف غير مدعوم.');
                        else if (file.size < 1) failItem(item, 'الملف فارغ.');
                        else if (file.size > maxBytes) failItem(item, 'حجم الملف أكبر من 300MB.');
                    });
                    processQueue();
                };

                input.addEventListener('change', () => { addFiles(input.files); input.value = ''; });
                ['dragenter', 'dragover'].forEach((name) => dropzone.addEventListener(name, (event) => {
                    event.preventDefault();
                    dropzone.classList.add('border-indigo-500', 'bg-indigo-100');
                }));
                ['dragleave', 'drop'].forEach((name) => dropzone.addEventListener(name, (event) => {
                    event.preventDefault();
                    dropzone.classList.remove('border-indigo-500', 'bg-indigo-100');
                }));
                dropzone.addEventListener('drop', (event) => addFiles(event.dataTransfer.files));
            })();
        </script>
    @endpush
</x-admin-layout>
