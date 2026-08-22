<x-front-layout>
    <x-slot name="pageTitle">{{ $pricingPackage->name }} | باقات HeroKid</x-slot>
    <x-slot name="pageDescription">{{ $pricingPackage->description ?: 'اختر قصص ومنتجات باقة '.$pricingPackage->name.' بسعر موفر من HeroKid.' }}</x-slot>
    <x-slot name="canonical">/shop/package/{{ $pricingPackage->slug }}</x-slot>
    @if($pricingPackage->image_url)
        <x-slot name="pageImage">{{ $pricingPackage->image_url }}</x-slot>
        <x-slot name="pageImageAlt">{{ $pricingPackage->name }}</x-slot>
        <x-slot name="ogImageWidth">900</x-slot>
        <x-slot name="ogImageHeight">900</x-slot>
    @endif

    <div class="min-h-screen bg-gradient-to-b from-indigo-50 to-white py-8 sm:py-12" dir="rtl">
        <div class="mx-auto max-w-5xl px-4 sm:px-6">
            <nav class="mb-5 text-sm font-bold text-slate-500"><a href="{{ route('shop.index') }}" class="hover:text-indigo-700">متجر القصص والمنتجات</a> / {{ $pricingPackage->name }}</nav>

            <header class="rounded-3xl bg-gradient-to-bl from-indigo-950 via-violet-900 to-fuchsia-800 p-6 text-right text-white shadow-xl sm:p-9">
                <div class="grid gap-6 sm:grid-cols-[minmax(0,1fr)_240px] sm:items-center">
                    <div>
                        @if($pricingPackage->badge)<span class="rounded-full bg-amber-300 px-3 py-1 text-xs font-black text-amber-950">{{ $pricingPackage->badge }}</span>@endif
                        <h1 class="mt-3 text-3xl font-black sm:text-4xl">{{ $pricingPackage->name }}</h1>
                        <p class="mt-3 max-w-2xl leading-8 text-indigo-100">{{ $pricingPackage->description ?: $pricingPackage->componentSummary() }}</p>
                    </div>
                    @if($pricingPackage->image_url)
                        <img src="{{ $pricingPackage->image_url }}" alt="{{ $pricingPackage->name }}" width="480" height="480" class="order-first aspect-square w-full rounded-2xl object-cover shadow-lg sm:order-last">
                    @endif
                    <div class="rounded-2xl bg-white/10 px-5 py-4 text-left backdrop-blur sm:col-span-2">
                        @if($regularTotal > (int) round((float) $pricingPackage->price * 100))
                            <p class="text-sm text-indigo-200 line-through">{{ format_money($regularTotal / 100) }}</p>
                        @endif
                        <p class="text-3xl font-black">{{ format_money((float) $pricingPackage->price) }}</p>
                        <p class="mt-1 text-xs text-indigo-200">هذا هو السعر النهائي للمكونات</p>
                    </div>
                </div>
            </header>

            @if($errors->any())
                <div class="mt-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700" role="alert">
                    <p class="mb-2 font-black">أكمل البيانات التالية:</p>
                    <ul class="list-inside list-disc space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <form action="{{ route('cart.packages.store', $pricingPackage) }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-5" data-package-order-form>
                @csrf
                <input type="hidden" name="upload_session_token" value="{{ $photoUploadConfig['sessionToken'] ?? '' }}">

                @if($pricingPackage->story_count > 0)
                    <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                        <h2 class="text-xl font-black text-slate-950">اختر {{ $pricingPackage->story_count === 1 ? 'القصة وبيانات الطفل' : 'القصص وبيانات الأطفال' }}</h2>
                        <p class="mt-1 text-sm text-slate-500">يمكن اختيار قصص لنفس الطفل أو لأطفال مختلفين. كل قصة تحتاج صورتين أو ٣ صور.</p>
                        <div class="mt-5 space-y-4">
                            @for($slot = 0; $slot < $pricingPackage->story_count; $slot++)
                                <fieldset class="rounded-2xl border border-indigo-100 bg-indigo-50/40 p-4">
                                    <legend class="px-2 text-base font-black text-indigo-900">القصة {{ $slot + 1 }}</legend>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        @php $selectedStory = $stories->firstWhere('id', (int) old("stories.$slot.story_id")); @endphp
                                        <div class="sm:col-span-2" data-package-story-slot data-slot="{{ $slot }}">
                                            <span class="mb-1 block text-sm font-bold text-slate-700">اختر القصة</span>
                                            <select name="stories[{{ $slot }}][story_id]" required class="w-full rounded-xl border-slate-300" data-package-story-select>
                                                <option value="">اختر قصة</option>
                                                @foreach($stories as $story)<option value="{{ $story->id }}" data-title="{{ $story->title }}" data-image="{{ $story->cover_url }}" @selected((string) old("stories.$slot.story_id") === (string) $story->id)>{{ $story->title }}</option>@endforeach
                                            </select>
                                            <button type="button" class="mt-2 hidden w-full items-center gap-3 rounded-2xl border-2 border-indigo-200 bg-white p-3 text-right transition hover:border-indigo-500" data-open-story-picker>
                                                <span class="h-20 w-16 shrink-0 overflow-hidden rounded-xl bg-slate-100"><x-story-cover-image :src="$selectedStory?->cover_url" alt="" class="{{ $selectedStory ? '' : 'hidden' }} h-full w-full object-cover" data-selected-story-image /></span>
                                                <span class="min-w-0 flex-1"><strong class="block truncate text-base text-slate-950" data-selected-story-title>{{ $selectedStory?->title ?: 'اضغط لاختيار القصة' }}</strong><span class="mt-1 block text-xs font-bold text-indigo-600">ستشاهد صورة كل قصة قبل اختيارها</span></span>
                                                <span class="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-black text-white">اختيار</span>
                                            </button>
                                            <p class="mt-1 hidden text-xs font-bold text-red-600" data-story-picker-error>اختر قصة لهذه الخانة.</p>
                                        </div>
                                        <label><span class="mb-1 block text-sm font-bold text-slate-700">اسم الطفل</span><input name="stories[{{ $slot }}][child_name]" value="{{ old("stories.$slot.child_name") }}" required autocomplete="off" class="w-full rounded-xl border-slate-300"></label>
                                        <label><span class="mb-1 block text-sm font-bold text-slate-700">العمر</span><select name="stories[{{ $slot }}][child_age]" required class="w-full rounded-xl border-slate-300"><option value="">اختر العمر</option>@foreach(\App\Support\StoryAgeOptions::forPersonalization() as $age)<option value="{{ $age }}" @selected((string) old("stories.$slot.child_age") === (string) $age)>{{ $age }} سنوات</option>@endforeach</select></label>
                                        <label><span class="mb-1 block text-sm font-bold text-slate-700">الجنس</span><select name="stories[{{ $slot }}][child_gender]" required class="w-full rounded-xl border-slate-300"><option value="">اختر</option><option value="boy" @selected(old("stories.$slot.child_gender") === 'boy')>ولد</option><option value="girl" @selected(old("stories.$slot.child_gender") === 'girl')>بنت</option></select></label>
                                        <div data-package-uploader data-slot="{{ $slot }}"><label><span class="mb-1 block text-sm font-bold text-slate-700">صور الطفل (صورتان أو ٣)</span><input type="file" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" class="block w-full rounded-xl border border-slate-300 bg-white p-2 text-sm" data-package-photo-input><span class="mt-1 hidden text-xs font-bold text-red-600" data-package-photo-error></span></label><div class="mt-2 grid grid-cols-3 gap-2" data-package-photo-previews></div><div data-package-photo-hidden></div></div>
                                    </div>
                                </fieldset>
                            @endfor
                        </div>
                    </section>
                @endif

                @if($pricingPackage->items->isNotEmpty())
                    <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                        <h2 class="text-xl font-black text-slate-950">المنتجات الموجودة في الباقة</h2>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            @foreach($pricingPackage->items as $item)
                                <div class="flex items-center gap-3 rounded-2xl bg-slate-50 p-3">
                                    <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white">@if($item->product?->featured_image_url)<img src="{{ $item->product->featured_image_url }}" alt="" class="h-full w-full object-cover">@else<span>🎁</span>@endif</div>
                                    <div><p class="font-black text-slate-900">{{ $item->product?->name_ar }}</p><p class="mt-1 text-xs text-slate-500">الكمية: {{ $item->quantity }} @if($item->variant) · {{ $item->variant->name_ar }} @endif</p></div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                <div class="sticky bottom-3 z-20 rounded-2xl border border-indigo-200 bg-white/95 p-3 shadow-2xl backdrop-blur sm:static sm:flex sm:items-center sm:justify-between">
                    <div class="mb-2 text-right sm:mb-0"><p class="text-xs text-slate-500">سعر الباقة النهائي</p><p class="text-2xl font-black text-indigo-700">{{ format_money((float) $pricingPackage->price) }}</p></div>
                    <button type="submit" class="w-full rounded-xl bg-gradient-to-l from-indigo-600 to-fuchsia-500 px-7 py-3.5 font-black text-white sm:w-auto">إضافة الباقة إلى السلة</button>
                </div>
            </form>
        </div>
    </div>

    <dialog data-package-story-dialog class="m-auto max-h-[88vh] w-[calc(100%-2rem)] max-w-4xl rounded-3xl p-0 shadow-2xl backdrop:bg-slate-950/70">
        <div class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 sm:px-6">
            <div><h2 class="text-xl font-black text-slate-950">اختر القصة</h2><p class="text-xs text-slate-500">اضغط على صورة القصة لإضافتها إلى الباقة.</p></div>
            <button type="button" class="flex h-11 w-11 items-center justify-center rounded-full bg-slate-100 text-xl font-black text-slate-700" data-close-story-picker aria-label="إغلاق">×</button>
        </div>
        <div class="grid gap-3 bg-slate-50 p-4 sm:grid-cols-2 sm:p-6 lg:grid-cols-3">
            @foreach($stories as $story)
                <button type="button" class="group flex items-center gap-3 rounded-2xl border-2 border-transparent bg-white p-2 text-right shadow-sm transition hover:border-indigo-500" data-choose-story data-id="{{ $story->id }}" data-title="{{ $story->title }}" data-image="{{ $story->cover_url }}">
                    <span class="h-24 w-20 shrink-0 overflow-hidden rounded-xl bg-slate-100"><x-story-cover-image :src="$story->cover_url" :alt="$story->title" loading="lazy" class="h-full w-full object-cover" /></span>
                    <span class="min-w-0"><strong class="line-clamp-2 text-sm text-slate-950">{{ $story->title }}</strong><span class="mt-2 block text-xs font-bold text-indigo-600">اختر هذه القصة</span></span>
                </button>
            @endforeach
        </div>
    </dialog>

    @push('scripts')
    <script>
    (() => {
        const config = @json($photoUploadConfig ?? []);
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const form = document.querySelector('[data-package-order-form]');
        const states = new Map();
        const escape = value => { const span = document.createElement('span'); span.textContent = value; return span.innerHTML; };
        const dialog = document.querySelector('[data-package-story-dialog]');
        let activeStorySlot = null;

        document.querySelectorAll('[data-package-story-slot]').forEach(slot => {
            const select = slot.querySelector('[data-package-story-select]');
            const trigger = slot.querySelector('[data-open-story-picker]');
            const title = slot.querySelector('[data-selected-story-title]');
            const image = slot.querySelector('[data-selected-story-image]');
            const error = slot.querySelector('[data-story-picker-error]');
            select.required = false;
            select.classList.add('hidden');
            trigger.classList.remove('hidden');
            trigger.classList.add('flex');
            trigger.addEventListener('click', () => { activeStorySlot = slot; dialog?.showModal(); });
            select.addEventListener('change', () => {
                const option = select.options[select.selectedIndex];
                title.textContent = option?.dataset.title || 'اضغط لاختيار القصة';
                if (option?.dataset.image) {
                    image.dataset.originalSrc = option.dataset.image;
                    image.dataset.coverRetryState = 'original';
                    image.src = option.dataset.image;
                    image.classList.remove('hidden');
                } else {
                    image.removeAttribute('data-original-src');
                    image.classList.add('hidden');
                }
                error.classList.toggle('hidden', Boolean(select.value));
            });
        });
        dialog?.querySelector('[data-close-story-picker]')?.addEventListener('click', () => dialog.close());
        dialog?.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
        dialog?.querySelectorAll('[data-choose-story]').forEach(button => button.addEventListener('click', () => {
            const select = activeStorySlot?.querySelector('[data-package-story-select]');
            if (!select) return;
            select.value = button.dataset.id;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            dialog.close();
        }));

        document.querySelectorAll('[data-package-uploader]').forEach(uploader => {
            const slot = Number(uploader.dataset.slot);
            const input = uploader.querySelector('[data-package-photo-input]');
            const previews = uploader.querySelector('[data-package-photo-previews]');
            const hidden = uploader.querySelector('[data-package-photo-hidden]');
            const error = uploader.querySelector('[data-package-photo-error]');
            const items = [];
            states.set(slot, items);

            const render = () => {
                previews.innerHTML = items.map((item, index) => `<div class="relative overflow-hidden rounded-xl border border-indigo-100 bg-white p-1"><div class="aspect-square overflow-hidden rounded-lg bg-slate-100">${item.preview ? `<img src="${item.preview}" class="h-full w-full object-cover" alt="معاينة الصورة">` : ''}</div><p class="mt-1 truncate text-center text-[10px] font-bold text-slate-600">${escape(item.name)}</p><p class="text-center text-[10px] font-black ${item.status === 'done' ? 'text-emerald-600' : item.status === 'failed' ? 'text-red-600' : 'text-indigo-600'}">${item.status === 'done' ? 'تم الرفع' : item.status === 'failed' ? 'فشل الرفع' : 'جاري الرفع'}</p><button type="button" data-remove="${index}" class="mt-1 min-h-11 w-full rounded-lg bg-red-50 text-xs font-black text-red-600">حذف</button></div>`).join('');
                hidden.innerHTML = items.filter(item => item.status === 'done').map(item => `<input type="hidden" name="stories[${slot}][photo_upload_ids][]" value="${item.id}">`).join('');
                previews.querySelectorAll('[data-remove]').forEach(button => button.addEventListener('click', () => {
                    const [removed] = items.splice(Number(button.dataset.remove), 1);
                    if (removed?.id) fetch(config.deleteUrlTemplate.replace('__ID__', removed.id), { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } }).catch(() => {});
                    render();
                }));
            };

            const upload = async file => {
                const item = { name: file.name, preview: URL.createObjectURL(file), status: 'uploading', id: null };
                items.push(item); render();
                try {
                    const prepared = window.HeroKidImageUpload?.prepare ? await window.HeroKidImageUpload.prepare(file, { maxLongEdge: config.maxLongEdge, jpegQuality: Number(config.jpegQuality || 90) / 100 }) : file;
                    const body = new FormData(); body.append('photo', prepared); body.append('upload_session_token', config.sessionToken); body.append('upload_batch_token', config.batchTokens[slot]);
                    const response = await fetch(config.uploadUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body });
                    const result = await response.json();
                    if (!response.ok || !result.id) throw new Error(result.message || 'فشل رفع الصورة.');
                    item.id = result.id; item.preview = result.preview_url || item.preview; item.status = 'done'; error.classList.add('hidden');
                } catch (exception) { item.status = 'failed'; error.textContent = exception.message || 'فشل رفع الصورة.'; error.classList.remove('hidden'); }
                render();
            };

            input.addEventListener('change', () => {
                const available = Math.max(0, 3 - items.length);
                const selected = Array.from(input.files);
                selected.slice(0, available).forEach(upload);
                input.value = '';
                if (selected.length > available) { error.textContent = 'الحد الأقصى ٣ صور.'; error.classList.remove('hidden'); }
            });
        });

        form?.addEventListener('submit', event => {
            const missingStory = [...document.querySelectorAll('[data-package-story-slot]')].find(slot => !slot.querySelector('[data-package-story-select]')?.value);
            if (missingStory) {
                event.preventDefault();
                missingStory.querySelector('[data-story-picker-error]')?.classList.remove('hidden');
                missingStory.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            const incomplete = [...states.values()].some(items => items.filter(item => item.status === 'done').length < 2 || items.some(item => item.status === 'uploading'));
            if (!incomplete) return;
            event.preventDefault();
            const first = [...document.querySelectorAll('[data-package-uploader]')].find(element => states.get(Number(element.dataset.slot)).filter(item => item.status === 'done').length < 2 || states.get(Number(element.dataset.slot)).some(item => item.status === 'uploading'));
            const error = first?.querySelector('[data-package-photo-error]'); if (error) { error.textContent = 'انتظر اكتمال الرفع وتأكد من وجود صورتين على الأقل.'; error.classList.remove('hidden'); }
            first?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    })();
    </script>
    @endpush
</x-front-layout>
