<x-front-layout>
    <x-slot name="pageTitle">هوية {{ $identity->displayChildName() }}</x-slot>
    <x-slot name="pageDescription">متابعة إنشاء هوية الطفل واختيار القصة.</x-slot>
    <x-slot name="robots">noindex, nofollow</x-slot>

    @php
        $latest = $identity->attempts->sortByDesc('attempt_number')->first();
        $approvedAttempt = $identity->approvedAttempt;
        $approvedShareReady = $share?->status === 'ready'
            && $share?->generation_attempt_id === $approvedAttempt?->id;
        $isWorking = $identity->attempts->contains(
            fn($attempt) => in_array($attempt->status, ['pending', 'processing'], true)
                || ($attempt->status === 'succeeded'
                    && !$attempt->share_cards_generated_at
                    && !($approvedShareReady && $attempt->id === $approvedAttempt?->id)
                    && blank(data_get($attempt->response_metadata, 'share_card_error')))
        );
        $recoverableHeicPhotos = $identity->photos
            ->filter(fn($photo) => $photo->validation_status === 'valid'
                && !$photo->ai_input_path
                && (str_contains(strtolower($photo->mime_type), 'heic') || str_contains(strtolower($photo->mime_type), 'heif')));
        $heicRecoveryConfig = [
            'maxLongEdge' => (int) config('photo_uploads.max_long_edge', 2560),
            'jpegQuality' => (int) config('photo_uploads.jpeg_quality', 90) / 100,
            'photos' => $recoverableHeicPhotos->map(fn($photo) => [
                'sourceUrl' => $media['photos'][$photo->id] ?? null,
                'uploadUrl' => route('child-identity.photos.ai-input', [$identity->uuid, $photo]),
                'mimeType' => $photo->mime_type,
                'fileName' => $photo->original_filename,
            ])->values()->all(),
        ];
        $heicRecoveryJson = json_encode(
            $heicRecoveryConfig,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $failureMessage = $recoverableHeicPhotos->isNotEmpty()
            ? 'تعذر تجهيز إحدى الصور في المحاولة السابقة. صورك محفوظة ويمكنك إعادة المحاولة.'
            : ($latest?->safe_error_message ?: 'حدث خطأ مؤقت. الصور محفوظة ولن تحتاج إلى رفعها مرة أخرى.');
        $selectedCategoryStories = $identity->selected_story_category_id
            ? $stories->filter(fn($story) => $story->categories->contains('id', $identity->selected_story_category_id))
            : collect();
        $progressStep = in_array($wizardStep, ['processing', 'failed'], true) ? 2 : 3;
    @endphp

    <main class="min-h-screen bg-gradient-to-b from-slate-50 via-white to-indigo-50 py-8 sm:py-12"
          dir="rtl"
          data-identity-poll="{{ $isWorking ? route('child-identity.poll', $identity->uuid) : '' }}">
        <div class="mx-auto max-w-5xl space-y-6 px-4">
            <header class="rounded-3xl bg-gradient-to-l from-indigo-950 via-violet-900 to-indigo-800 p-6 text-white shadow-xl sm:p-8">
                <div class="flex flex-col gap-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-bold text-indigo-200">هوية {{ $identity->displayChildName() }} • {{ $identity->age_range }}</p>
                            <h1 class="mt-2 text-3xl font-black sm:text-4xl">
                                {{ $wizardStep === 'processing' ? 'جاري إنشاء هوية طفلك' : ($wizardStep === 'failed' ? 'نحتاج إعادة المحاولة' : 'هوية طفلك جاهزة') }}
                            </h1>
                        </div>
                        <span class="rounded-full bg-white/10 px-4 py-2 text-sm font-black backdrop-blur">{{ $identity->statusLabel() }}</span>
                    </div>

                    <ol class="grid grid-cols-3 gap-2" aria-label="خطوات إنشاء الهوية">
                        @foreach([
                            1 => ['البيانات والصور', 'تم الاستلام'],
                            2 => ['إنشاء الهوية', $progressStep > 2 ? 'تمت' : 'الحالية'],
                            3 => ['اختيار القصة', $progressStep === 3 ? 'الحالية' : 'التالي'],
                        ] as $number => [$label, $state])
                            <li class="rounded-2xl border px-3 py-3 text-center {{ $number <= $progressStep ? 'border-white/30 bg-white/15' : 'border-white/10 bg-white/5' }}">
                                <span class="mx-auto flex h-7 w-7 items-center justify-center rounded-full text-xs font-black {{ $number <= $progressStep ? 'bg-white text-indigo-800' : 'bg-white/10 text-white' }}">{{ arabic_number($number) }}</span>
                                <p class="mt-2 text-xs font-black sm:text-sm">{{ $label }}</p>
                                <p class="mt-1 text-[10px] text-indigo-200">{{ $state }}</p>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </header>

            @if(session('success'))
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 font-bold text-emerald-800">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="rounded-2xl border border-red-200 bg-red-50 p-4 font-bold text-red-700">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-2xl border border-red-200 bg-red-50 p-4 font-bold text-red-700" data-scroll-on-load tabindex="-1">{{ $errors->first() }}</div>
            @endif

            @if($wizardStep === 'processing')
                <section class="rounded-3xl border border-indigo-100 bg-white p-6 text-center shadow-xl shadow-indigo-100/50 sm:p-10">
                    <div class="mx-auto h-14 w-14 animate-spin rounded-full border-4 border-indigo-100 border-t-indigo-600"></div>
                    <h2 class="mt-6 text-2xl font-black text-slate-950">{{ strtr($processingCopy['heading'], [':child' => $identity->displayChildName()]) }}</h2>
                    <p class="mx-auto mt-2 max-w-xl text-sm leading-7 text-slate-500">{{ $processingCopy['description'] }}</p>

                    <ol class="mx-auto mt-8 max-w-xl space-y-3 text-right">
                        <li class="flex items-center gap-3 rounded-2xl bg-emerald-50 p-4">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-600 text-sm font-black text-white">✓</span>
                            <div><p class="font-black text-emerald-900">{{ $processingCopy['received_title'] }}</p><p class="text-xs text-emerald-700">{{ strtr($processingCopy['received_description'], [':count' => arabic_number($identity->photos->where('validation_status', 'valid')->count())]) }}</p></div>
                        </li>
                        <li class="flex items-center gap-3 rounded-2xl {{ $latest?->status === 'pending' ? 'bg-indigo-50 ring-2 ring-indigo-100' : 'bg-emerald-50' }} p-4">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full {{ $latest?->status === 'pending' ? 'bg-indigo-600' : 'bg-emerald-600' }} text-sm font-black text-white">{{ $latest?->status === 'pending' ? '٢' : '✓' }}</span>
                            <div><p class="font-black {{ $latest?->status === 'pending' ? 'text-indigo-900' : 'text-emerald-900' }}">{{ $processingCopy['queued_title'] }}</p><p class="text-xs {{ $latest?->status === 'pending' ? 'text-indigo-600' : 'text-emerald-700' }}">{{ $latest?->status === 'pending' ? $processingCopy['queued_waiting_description'] : $processingCopy['queued_completed_description'] }}</p></div>
                        </li>
                        <li class="flex items-center gap-3 rounded-2xl {{ $latest?->status === 'processing' ? 'bg-indigo-50 ring-2 ring-indigo-100' : 'bg-slate-50' }} p-4">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full {{ $latest?->status === 'processing' ? 'bg-indigo-600 text-white' : 'bg-slate-200 text-slate-500' }} text-sm font-black">٣</span>
                            <div><p class="font-black {{ $latest?->status === 'processing' ? 'text-indigo-900' : 'text-slate-600' }}">{{ $processingCopy['generating_title'] }}</p><p class="text-xs {{ $latest?->status === 'processing' ? 'text-indigo-600' : 'text-slate-400' }}">{{ $latest?->status === 'processing' ? $processingCopy['generating_active_description'] : $processingCopy['generating_waiting_description'] }}</p></div>
                        </li>
                        <li class="flex items-center gap-3 rounded-2xl bg-slate-50 p-4">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-sm font-black text-slate-500">٤</span>
                            <div><p class="font-black text-slate-600">{{ $processingCopy['result_title'] }}</p><p class="text-xs text-slate-400">{{ $processingCopy['result_description'] }}</p></div>
                        </li>
                    </ol>
                </section>
            @elseif($wizardStep === 'failed')
                <section class="rounded-3xl border border-red-200 bg-white p-6 text-center shadow-sm sm:p-10">
                    <span class="inline-flex rounded-full bg-red-100 px-4 py-2 text-sm font-black text-red-700">لم تكتمل المحاولة</span>
                    <h2 class="mt-4 text-2xl font-black text-slate-950">تعذر إنشاء الهوية هذه المرة</h2>
                    <p class="mx-auto mt-3 max-w-xl text-sm leading-7 text-slate-600">{{ $failureMessage }}</p>
                    <form method="POST" action="{{ route('child-identity.generate', $identity->uuid) }}"
                          class="mx-auto mt-6 max-w-md"
                          @if($recoverableHeicPhotos->isNotEmpty()) data-identity-heic-recovery @endif>
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        @if($recoverableHeicPhotos->isNotEmpty())
                            <script type="application/json" data-identity-heic-recovery-config>{!! $heicRecoveryJson !!}</script>
                        @endif
                        <button type="submit" class="w-full rounded-2xl bg-indigo-600 px-6 py-4 font-black text-white disabled:opacity-60">
                            {{ $recoverableHeicPhotos->isNotEmpty() ? 'تجهيز الصور وإعادة المحاولة' : 'إعادة المحاولة بنفس الصور' }}
                        </button>
                        <div class="mt-3 hidden rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-700"
                             data-identity-heic-recovery-error></div>
                    </form>
                </section>
            @elseif($wizardStep === 'identity')
                <section class="overflow-hidden rounded-3xl border border-emerald-200 bg-white shadow-xl shadow-emerald-100/60">
                    <div class="bg-emerald-50 px-5 py-5 text-center sm:px-8">
                        <span class="inline-flex rounded-full bg-emerald-600 px-4 py-2 text-sm font-black text-white">اكتملت الهوية بنجاح</span>
                        <h2 class="mt-3 text-2xl font-black text-slate-950">هوية {{ $identity->displayChildName() }} جاهزة</h2>
                    </div>
                    <div class="p-4 sm:p-6">
                        @if($approvedAttempt && isset($media['attempts'][$approvedAttempt->id]))
                            <img src="{{ $media['attempts'][$approvedAttempt->id] }}" alt="هوية الطفل المعتمدة"
                                 class="mx-auto aspect-[4/3] w-full max-w-4xl rounded-2xl bg-slate-100 object-contain shadow-sm" referrerpolicy="no-referrer">
                        @endif
                        @include('front.child-identity._share-section')
                        <a href="{{ route('child-identity.show', ['identity' => $identity->uuid, 'step' => 'category']) }}"
                           class="mx-auto mt-5 block w-full max-w-xl rounded-2xl bg-gradient-to-l from-indigo-600 to-violet-600 px-6 py-4 text-center text-base font-black text-white shadow-lg shadow-indigo-200">
                            اختر قصة بهذه الهوية
                        </a>
                    </div>
                </section>
            @elseif($wizardStep === 'category')
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-xl shadow-indigo-100/50 sm:p-8">
                    <div class="text-center">
                        <span class="inline-flex rounded-full bg-indigo-100 px-4 py-2 text-sm font-black text-indigo-700">اختيار التصنيف</span>
                        <h2 class="mt-3 text-2xl font-black text-slate-950">ما نوع القصة التي تحبها؟</h2>
                        <p class="mt-2 text-sm text-slate-500">نعرض فقط التصنيفات التي تحتوي قصصًا مناسبة للفئة {{ $identity->age_range }}.</p>
                    </div>
                    <div class="mt-7 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @forelse($categories as $category)
                            <form method="POST" action="{{ route('child-identity.category', $identity->uuid) }}">
                                @csrf
                                <input type="hidden" name="story_category_id" value="{{ $category->id }}">
                                <button class="min-h-28 w-full rounded-2xl border-2 border-indigo-100 bg-indigo-50/60 p-5 text-right transition hover:border-indigo-400 hover:bg-indigo-50">
                                    <span class="text-lg font-black text-indigo-950">{{ $category->name }}</span>
                                    <span class="mt-2 block text-xs font-bold text-indigo-600">{{ arabic_number($stories->filter(fn($story) => $story->categories->contains('id', $category->id))->count()) }} قصص مناسبة</span>
                                </button>
                            </form>
                        @empty
                            <p class="col-span-full rounded-2xl bg-slate-50 p-6 text-center font-bold text-slate-500">لا توجد تصنيفات مناسبة لهذه الفئة العمرية حاليًا.</p>
                        @endforelse
                    </div>
                </section>
            @elseif($wizardStep === 'stories')
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-xl shadow-indigo-100/50 sm:p-8">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <span class="inline-flex rounded-full bg-indigo-100 px-4 py-2 text-sm font-black text-indigo-700">{{ $identity->selectedCategory?->name }}</span>
                            <h2 class="mt-3 text-2xl font-black text-slate-950">اختر قصة {{ $identity->displayChildName() }}</h2>
                        </div>
                        <a href="{{ route('child-identity.show', ['identity' => $identity->uuid, 'step' => 'category']) }}"
                           class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-black text-slate-600">تغيير التصنيف</a>
                    </div>

                    <div class="mt-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @forelse($selectedCategoryStories as $story)
                            <form method="POST" action="{{ route('child-identity.story', $identity->uuid) }}"
                                  class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-1 hover:shadow-lg">
                                @csrf
                                <input type="hidden" name="story_id" value="{{ $story->id }}">
                                @if($story->cover_url)
                                    <img src="{{ $story->cover_url }}" alt="{{ $story->title }}" class="aspect-[4/3] w-full object-cover">
                                @else
                                    <div class="flex aspect-[4/3] items-center justify-center bg-gradient-to-br from-indigo-100 to-amber-100 font-black text-indigo-700">HeroKid</div>
                                @endif
                                <div class="p-4">
                                    <h3 class="font-black text-slate-950">{{ $story->title }}</h3>
                                    <p class="mt-1 line-clamp-2 text-sm leading-6 text-slate-500">{{ $story->short_desc }}</p>
                                    <button class="mt-4 w-full rounded-xl bg-indigo-600 px-4 py-3 font-black text-white">اختيار هذه القصة</button>
                                </div>
                            </form>
                        @empty
                            <p class="col-span-full rounded-2xl bg-slate-50 p-6 text-center font-bold text-slate-500">لا توجد قصص مناسبة في هذا التصنيف حاليًا.</p>
                        @endforelse
                    </div>
                </section>
            @elseif($wizardStep === 'confirm')
                <section class="rounded-3xl border border-emerald-200 bg-white p-5 shadow-xl shadow-emerald-100/60 sm:p-8">
                    <div class="text-center">
                        <span class="inline-flex rounded-full bg-emerald-100 px-4 py-2 text-sm font-black text-emerald-700">جاهز للإضافة إلى السلة</span>
                        <h2 class="mt-3 text-2xl font-black text-slate-950">{{ $identity->selectedStory?->title }}</h2>
                        <p class="mt-2 text-sm text-slate-500">سيتم استخدام هوية {{ $identity->displayChildName() }} وكل الصور الأصلية داخل الطلب العادي.</p>
                    </div>
                    <div class="mx-auto mt-6 grid max-w-3xl gap-4 sm:grid-cols-2">
                        @if($approvedAttempt && isset($media['attempts'][$approvedAttempt->id]))
                            <img src="{{ $media['attempts'][$approvedAttempt->id] }}" alt="هوية الطفل" class="aspect-[4/3] w-full rounded-2xl bg-slate-100 object-contain" referrerpolicy="no-referrer">
                        @endif
                        @if($identity->selectedStory?->cover_url)
                            <img src="{{ $identity->selectedStory->cover_url }}" alt="{{ $identity->selectedStory->title }}" class="aspect-[4/3] w-full rounded-2xl object-cover">
                        @endif
                    </div>
                    <form method="POST" action="{{ route('child-identity.cart', $identity->uuid) }}" class="mx-auto mt-6 max-w-2xl">
                        @csrf
                        <button class="w-full rounded-2xl bg-emerald-600 px-6 py-4 text-base font-black text-white">إضافة القصة إلى السلة وإكمال الطلب</button>
                    </form>
                    <div class="mt-4 flex justify-center gap-4 text-sm font-black">
                        <a href="{{ route('child-identity.show', ['identity' => $identity->uuid, 'step' => 'stories']) }}" class="text-indigo-700">تغيير القصة</a>
                        <a href="{{ route('child-identity.show', ['identity' => $identity->uuid, 'step' => 'category']) }}" class="text-slate-500">تغيير التصنيف</a>
                    </div>
                </section>
            @else
                <section class="rounded-3xl border border-indigo-200 bg-white p-8 text-center shadow-sm">
                    <h2 class="text-2xl font-black text-slate-950">{{ $wizardStep === 'complete' ? 'تم إنشاء الطلب بنجاح' : 'القصة موجودة في السلة' }}</h2>
                    <p class="mt-3 text-sm text-slate-500">{{ $wizardStep === 'complete' ? 'يمكنك متابعة الطلب بالطريقة المعتادة.' : 'يمكنك إكمال بيانات التوصيل والدفع من السلة.' }}</p>
                    <a href="{{ $wizardStep === 'complete' ? route('track.index') : route('cart.index') }}" class="mx-auto mt-6 block max-w-md rounded-2xl bg-indigo-600 px-6 py-4 font-black text-white">
                        {{ $wizardStep === 'complete' ? 'تتبع الطلب' : 'فتح السلة' }}
                    </a>
                </section>
            @endif
        </div>
    </main>

    @push('scripts')
        <script>
            sessionStorage.removeItem('herokid:child-identity:photo-upload-ids');
        </script>
    @endpush

    @if($isWorking)
        @push('scripts')
            <script>
                (() => {
                    const root = document.querySelector('[data-identity-poll]');
                    const url = root?.dataset.identityPoll;
                    if (!url) return;
                    const poll = async () => {
                        try {
                            const response = await fetch(url, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
                            const data = await response.json();
                            if (data.refresh) window.setTimeout(poll, 2500);
                            else window.location.reload();
                        } catch (_) {
                            window.setTimeout(poll, 5000);
                        }
                    };
                    window.setTimeout(poll, 2000);
                })();
            </script>
        @endpush
    @endif
</x-front-layout>
