<x-front-layout>
    <x-slot name="pageTitle">هوية {{ $identity->child_name }}</x-slot>
    <x-slot name="pageDescription">طلب هوية طفل خاص وآمن.</x-slot>
    <x-slot name="robots">noindex, nofollow</x-slot>

    @php
        $activePhotos = $identity->photos->where('upload_status', 'uploaded')->where('validation_status', 'valid');
        $latest = $identity->attempts->sortByDesc('attempt_number')->first();
        $isWorking = $identity->attempts->contains(fn($attempt) => in_array($attempt->status, ['pending', 'processing'], true));
        $customerLocked = in_array($identity->status, ['converted', 'cancelled'], true);
        $selectedCategoryStories = $identity->selected_story_category_id
            ? $stories->filter(fn($story) => $story->categories->contains('id', $identity->selected_story_category_id))
            : collect();
    @endphp

    <main class="min-h-screen bg-slate-50 py-8 sm:py-12" dir="rtl" data-identity-poll="{{ $isWorking ? route('child-identity.poll', $identity->uuid) : '' }}">
        <div class="mx-auto max-w-6xl space-y-6 px-4">
            <header class="rounded-3xl bg-gradient-to-l from-indigo-950 via-violet-900 to-indigo-800 p-6 text-white shadow-xl sm:p-9">
                <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-center">
                    <div>
                        <p class="text-sm font-bold text-indigo-200">طلب دائم وآمن • {{ $identity->uuid }}</p>
                        <h1 class="mt-2 text-3xl font-black sm:text-4xl">هوية {{ $identity->child_name }}</h1>
                        <p class="mt-3 text-indigo-100">العمر: {{ arabic_number($identity->child_age) }} سنوات • الفئة: {{ $identity->age_range }}</p>
                    </div>
                    <div class="rounded-2xl bg-white/10 px-5 py-3 text-center backdrop-blur">
                        <p class="text-xs text-indigo-200">الحالة</p>
                        <p class="mt-1 font-black">{{ [
                            'incomplete' => 'بانتظار الصور', 'photos_uploaded' => 'جاهز للتوليد', 'queued' => 'في قائمة الانتظار',
                            'processing' => 'جاري إنشاء الهوية', 'generated' => 'الهوية جاهزة', 'generation_failed' => 'تحتاج إعادة محاولة',
                            'approved' => 'تم اعتماد الهوية', 'story_selected' => 'تم اختيار القصة', 'in_cart' => 'في السلة',
                            'converted' => 'تحول إلى طلب', 'cancelled' => 'ملغي',
                        ][$identity->status] ?? $identity->status }}</p>
                    </div>
                </div>
            </header>

            @if(session('success'))
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 font-bold text-emerald-800">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-2xl border border-red-200 bg-red-50 p-4 font-bold text-red-700">{{ $errors->first() }}</div>
            @endif
            @if(session('resume_url'))
                <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4 text-sm leading-7 text-indigo-900">
                    <strong>احفظ رابط المتابعة الخاص بك:</strong>
                    <a href="{{ session('resume_url') }}" class="mt-2 block break-all font-bold underline" rel="noreferrer">{{ session('resume_url') }}</a>
                    لا تشارك هذا الرابط؛ فهو يمنح الوصول إلى طلب الهوية.
                </div>
            @endif
            @if($customerLocked)
                <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4 font-bold leading-7 text-indigo-900">
                    {{ $identity->status === 'converted' ? 'تم تحويل الهوية إلى طلب HeroKid عادي. يمكنك متابعة الطلب من قنوات المتابعة المعتادة.' : 'تم تثبيت هذا الطلب ولم تعد بيانات الهوية قابلة للتعديل من رابط العميل.' }}
                </div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                    <div>
                        <h2 class="text-xl font-black text-slate-900">١. صور الطفل الأصلية</h2>
                        <p class="mt-1 text-sm text-slate-500">ارفع من صورتين إلى ٥ صور واضحة من زوايا مختلفة. تُحفظ كل صورة بشكل مستقل.</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-4 py-2 text-sm font-black text-slate-700">{{ arabic_number($activePhotos->count()) }} / ٥</span>
                </div>

                <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-5">
                    @foreach($activePhotos as $photo)
                        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
                            <img src="{{ $media['photos'][$photo->id] }}" alt="صورة مرجعية للطفل" class="aspect-square w-full object-cover" referrerpolicy="no-referrer">
                            @if(!$photo->attempts()->exists())
                                <form method="POST" action="{{ route('child-identity.photos.destroy', [$identity->uuid, $photo]) }}" class="p-2">
                                    @csrf @method('DELETE')
                                    <button class="w-full rounded-lg bg-white py-2 text-xs font-bold text-red-600">إزالة</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if($activePhotos->count() < 5 && !$isWorking && !$customerLocked)
                    <form method="POST" enctype="multipart/form-data" action="{{ route('child-identity.photos.store', $identity->uuid) }}" class="mt-5 flex flex-col gap-3 rounded-2xl border-2 border-dashed border-indigo-200 bg-indigo-50/50 p-4 sm:flex-row sm:items-end">
                        @csrf
                        <label class="flex-1 text-sm font-bold text-slate-700">اختر صورة JPG أو PNG أو WebP
                            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required class="mt-2 block w-full text-sm">
                        </label>
                        <button class="rounded-xl bg-indigo-600 px-5 py-3 font-black text-white">رفع الصورة</button>
                    </form>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                    <div>
                        <h2 class="text-xl font-black text-slate-900">٢. إنشاء الهوية واعتمادها</h2>
                        <p class="mt-1 text-sm text-slate-500">لك نتيجتان ناجحتان كحد أقصى. المحاولات الفاشلة لا تُخصم.</p>
                    </div>
                    <span class="rounded-full bg-violet-100 px-4 py-2 text-sm font-black text-violet-700">{{ arabic_number($identity->successful_attempts) }} / ٢ نتائج ناجحة</span>
                </div>

                @if($isWorking)
                    <div class="mt-5 rounded-2xl bg-indigo-50 p-6 text-center">
                        <div class="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-indigo-200 border-t-indigo-600"></div>
                        <p class="mt-4 font-black text-indigo-900">جاري إعداد هوية {{ $identity->child_name }}…</p>
                        <p class="mt-1 text-sm text-indigo-600">يمكنك إبقاء الصفحة مفتوحة؛ ستتحدث تلقائيًا.</p>
                    </div>
                @elseif($activePhotos->count() >= 2 && $identity->successful_attempts < 2 && !$customerLocked)
                    <form method="POST" action="{{ route('child-identity.generate', $identity->uuid) }}" class="mt-5">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <button class="w-full rounded-2xl bg-gradient-to-l from-fuchsia-600 to-violet-600 px-6 py-4 font-black text-white">
                            {{ $identity->successful_attempts > 0 ? 'إنشاء المحاولة الثانية' : 'إنشاء هوية الطفل مجانًا' }}
                        </button>
                    </form>
                @elseif($activePhotos->count() < 2)
                    <p class="mt-5 rounded-2xl bg-amber-50 p-4 font-bold text-amber-800">ارفع صورتين واضحتين على الأقل أولًا.</p>
                @endif

                <div class="mt-6 grid gap-5 lg:grid-cols-2">
                    @foreach($identity->attempts->sortByDesc('attempt_number') as $attempt)
                        <article class="overflow-hidden rounded-2xl border {{ $identity->approved_attempt_id === $attempt->id ? 'border-emerald-400 ring-2 ring-emerald-100' : 'border-slate-200' }}">
                            @if($attempt->status === 'succeeded')
                                <img src="{{ $media['attempts'][$attempt->id] }}" alt="هوية الطفل الناتجة" class="aspect-[3/2] w-full bg-slate-100 object-contain" referrerpolicy="no-referrer">
                            @endif
                            <div class="p-4">
                                <div class="flex items-center justify-between">
                                    <h3 class="font-black text-slate-900">المحاولة {{ arabic_number($attempt->attempt_number) }}</h3>
                                    <span class="rounded-full px-3 py-1 text-xs font-black {{ $attempt->status === 'succeeded' ? 'bg-emerald-100 text-emerald-700' : ($attempt->status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600') }}">
                                        {{ ['succeeded' => 'ناجحة', 'failed' => 'فشلت', 'pending' => 'منتظرة', 'processing' => 'جارية', 'rejected' => 'مرفوضة', 'cancelled' => 'ملغاة'][$attempt->status] ?? $attempt->status }}
                                    </span>
                                </div>
                                @if($attempt->safe_error_message)
                                    <p class="mt-3 text-sm leading-6 text-red-600">{{ $attempt->safe_error_message }}</p>
                                @endif
                                @if(!$customerLocked && $attempt->status === 'succeeded' && $identity->approved_attempt_id !== $attempt->id)
                                    <form method="POST" action="{{ route('child-identity.approve', [$identity->uuid, $attempt]) }}" class="mt-4">
                                        @csrf
                                        <button class="w-full rounded-xl bg-emerald-600 px-4 py-3 font-black text-white">اعتماد هذه الهوية</button>
                                    </form>
                                @elseif($identity->approved_attempt_id === $attempt->id)
                                    <p class="mt-4 rounded-xl bg-emerald-50 p-3 text-center font-black text-emerald-700">✓ الهوية المعتمدة</p>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            @if($identity->approved_attempt_id)
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                    <h2 class="text-xl font-black text-slate-900">٣. اختر القصة</h2>
                    <p class="mt-1 text-sm text-slate-500">نعرض القصص المطابقة للفئة العمرية المحفوظة: {{ $identity->age_range }}</p>

                    @if(!$customerLocked)
                    <form method="POST" action="{{ route('child-identity.category', $identity->uuid) }}" class="mt-5 flex flex-col gap-3 sm:flex-row">
                        @csrf
                        <select name="story_category_id" required class="flex-1 rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">اختر تصنيف القصة</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected($identity->selected_story_category_id === $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                        <button class="rounded-xl bg-slate-900 px-6 py-3 font-black text-white">عرض القصص</button>
                    </form>
                    @endif

                    @if($identity->selected_story_category_id && !$customerLocked)
                        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            @forelse($selectedCategoryStories as $story)
                                <form method="POST" action="{{ route('child-identity.story', $identity->uuid) }}" class="overflow-hidden rounded-2xl border {{ $identity->selected_story_id === $story->id ? 'border-indigo-500 ring-2 ring-indigo-100' : 'border-slate-200' }}">
                                    @csrf
                                    <input type="hidden" name="story_id" value="{{ $story->id }}">
                                    @if($story->cover_url)
                                        <img src="{{ $story->cover_url }}" alt="{{ $story->title }}" class="aspect-[4/3] w-full object-cover">
                                    @endif
                                    <div class="p-4">
                                        <h3 class="font-black text-slate-900">{{ $story->title }}</h3>
                                        <p class="mt-1 text-sm text-slate-500">{{ $story->age_range }}</p>
                                        <button class="mt-4 w-full rounded-xl {{ $identity->selected_story_id === $story->id ? 'bg-indigo-100 text-indigo-800' : 'bg-indigo-600 text-white' }} px-4 py-3 font-black">
                                            {{ $identity->selected_story_id === $story->id ? '✓ القصة المختارة' : 'اختيار القصة' }}
                                        </button>
                                    </div>
                                </form>
                            @empty
                                <p class="col-span-full rounded-2xl bg-slate-50 p-5 text-center text-slate-500">لا توجد قصص مطابقة في هذا التصنيف حاليًا.</p>
                            @endforelse
                        </div>
                    @endif

                    @if($identity->selected_story_id && !$customerLocked)
                        <form method="POST" action="{{ route('child-identity.cart', $identity->uuid) }}" class="mt-6 rounded-2xl bg-emerald-50 p-5">
                            @csrf
                            <p class="font-bold leading-7 text-emerald-900">خدمة الهوية مجانية. ستدفع فقط سعر القصة العادي وأي منتجات أو شحن تختاره.</p>
                            <button class="mt-4 w-full rounded-xl bg-emerald-600 px-6 py-4 font-black text-white">إضافة القصة إلى السلة وإكمال الطلب</button>
                        </form>
                    @endif
                </section>
            @endif
        </div>
    </main>

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
