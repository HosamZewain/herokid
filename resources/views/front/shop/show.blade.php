<x-front-layout>
    <x-slot name="pageTitle">{{ $product->seo_title_ar ?: $product->name_ar }}</x-slot>
    <x-slot name="pageDescription">{{ $product->seo_description_ar ?: ($product->short_description_ar ?: 'منتج من متجر HeroKid للأطفال.') }}</x-slot>
    <x-slot name="canonical">/shop/product/{{ $product->slug }}</x-slot>
    @if($product->featured_image_url)
        <x-slot name="pageImage">{{ $product->featured_image_url }}</x-slot>
    @endif

    @php
        $requiresStory = $product->personalization_mode === 'inherit_from_linked_story' || $product->purchase_mode === 'add_on_only';
        $collectsChildDetails = $product->personalization_mode === 'collect_child_details';
        $canSubmit = ! $requiresStory || $storyItems->count() > 0;
        $singleStoryItem = $storyItems->count() === 1 ? $storyItems->first() : null;
        $photoField = $personalizationFields['photos'] ?? null;
        $hasPhotoField = $collectsChildDetails && $photoField;
    @endphp

    <div class="bg-slate-50 py-10 lg:py-14">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <nav aria-label="مسار التنقل" class="mb-4 text-sm font-bold text-slate-500">
                <ol class="flex flex-wrap items-center gap-2">
                    <li><a href="{{ route('home') }}" class="hover:text-indigo-700">الرئيسية</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('shop.index') }}" class="hover:text-indigo-700">متجر القصص والمنتجات</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="text-slate-800" aria-current="page">{{ $product->name_ar }}</li>
                </ol>
            </nav>
            <a href="{{ route('shop.index') }}" class="mb-8 inline-flex items-center gap-2 text-sm font-bold text-indigo-700 hover:text-indigo-900">
                <svg class="h-4 w-4 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                العودة إلى متجر القصص والمنتجات
            </a>
            <div class="grid grid-cols-1 gap-8 lg:grid-cols-[0.9fr_1.1fr]">
                <div class="space-y-4">
                    <div class="overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-sm">
                        @if($product->featured_image_url)
                            <img src="{{ $product->featured_image_url }}" alt="{{ $product->name_ar }}" class="aspect-[4/3] w-full object-cover">
                        @else
                            <div class="aspect-[4/3]">
                                <x-product-image-placeholder />
                            </div>
                        @endif
                    </div>
                    @if($product->gallery_images)
                        <div class="grid grid-cols-4 gap-3">
                            @foreach($product->gallery_images as $image)
                                <img src="{{ \App\Support\Seo::imageUrl(\Illuminate\Support\Facades\Storage::disk('public')->url($image)) }}" alt="{{ $product->name_ar }}" class="aspect-square rounded-2xl object-cover">
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="rounded-3xl border border-slate-100 bg-white p-6 text-right shadow-sm lg:p-8">
                    <a href="{{ $product->category ? route('shop.category', $product->category) : route('shop.index') }}" class="mb-4 inline-flex rounded-full bg-indigo-50 px-4 py-2 text-sm font-black text-indigo-700">{{ $product->category?->name_ar }}</a>
                    <h1 class="text-3xl font-black text-slate-950 sm:text-4xl">{{ $product->name_ar }}</h1>
                    @if($product->short_description_ar)
                        <p class="mt-4 text-lg leading-8 text-slate-500">{{ $product->short_description_ar }}</p>
                    @endif

                    <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-3xl bg-slate-50 p-5">
                        <div>
                            <p class="text-sm font-bold text-slate-500">السعر</p>
                            <p class="text-3xl font-black text-indigo-700">{{ format_money($product->effectivePrice()) }}</p>
                        </div>
                        <div class="text-left">
                            <p class="text-sm font-bold text-slate-500">العمر المناسب</p>
                            <p class="font-black text-slate-900">{{ $product->ageLabel() }}</p>
                        </div>
                    </div>

                    @if($requiresStory && $storyItems->isEmpty())
                        <div class="mt-6 rounded-3xl border border-amber-200 bg-amber-50 p-5 text-right">
                            <p class="text-xs font-black text-amber-600">منتج مخصص مرتبط بقصة</p>
                            <p class="mt-1 font-black text-amber-900">أضف قصة مخصصة أولًا لاستخدام صورة طفلك في هذا المنتج</p>
                            <p class="mt-2 text-sm leading-6 text-amber-800">بعد إضافة القصة للسلة سيظهر هذا المنتج كهدية إضافية، وسيستخدم نفس بيانات الطفل والصور بدون رفعها مرة أخرى.</p>
                            <a href="{{ route('shop.index', ['type' => 'stories']) }}" class="mt-4 inline-flex rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700">اختيار قصة مخصصة</a>
                        </div>
                    @endif

                    <form action="{{ route('cart.products.store', $product) }}" method="POST" class="mt-6 space-y-4" data-product-order-form>
                        @csrf
                        @if($collectsChildDetails)
                            @php($initialQuantity = max(1, min(10, (int) old('quantity', 1))))
                            <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-right">
                                <label for="personalized-product-quantity" class="block text-sm font-black text-indigo-950">عدد الأطفال / النسخ</label>
                                <input id="personalized-product-quantity" type="number" name="quantity" min="1" max="10" value="{{ $initialQuantity }}"
                                    class="mt-2 w-32 rounded-xl border-indigo-200 text-center" data-personalized-product-quantity>
                                <p class="mt-2 text-xs font-bold text-indigo-700">سيظهر نموذج مستقل لكل طفل، أو يمكنك استخدام بيانات الطفل الأول.</p>
                            </div>
                            <div class="space-y-4" data-personalization-units>
                                @for($unitIndex = 0; $unitIndex < 10; $unitIndex++)
                                    @include('front.shop._personalization-unit', compact('unitIndex', 'initialQuantity'))
                                @endfor
                            </div>
                        @endif

                        @if($product->activeVariants->count())
                            <div>
                                <label class="mb-2 block text-sm font-black text-slate-700">اختيار النوع</label>
                                <select name="variant_id" class="w-full rounded-2xl border-slate-200 text-right">
                                    @foreach($product->activeVariants as $variant)
                                        <option value="{{ $variant->id }}">{{ $variant->name_ar }} - {{ format_money($product->effectivePriceCents($variant) / 100) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        @if($requiresStory && $storyItems->count() > 1)
                            <div>
                                <label class="mb-2 block text-sm font-black text-slate-700">هذا المنتج سيكون مخصصًا لأي طفل؟</label>
                                <select name="linked_story_key" required class="w-full rounded-2xl border-slate-200 text-right">
                                    <option value="">اختر الطفل والقصة...</option>
                                    @foreach($storyItems as $storyItem)
                                        <option value="{{ $storyItem['key'] }}">{{ $storyItem['child_name'] ?? 'طفل' }} - {{ $storyItem['story_title'] ?? 'قصة' }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('linked_story_key')" class="mt-1" />
                            </div>
                        @elseif($requiresStory && $storyItems->count() === 1)
                            <input type="hidden" name="linked_story_key" value="{{ $singleStoryItem['key'] }}">
                            <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-right">
                                <p class="text-xs font-black text-indigo-500">سيتم تخصيصه تلقائيًا لـ</p>
                                <p class="mt-1 font-black text-slate-950">{{ $singleStoryItem['child_name'] ?? 'الطفل' }}</p>
                                <p class="text-sm text-slate-500">{{ $singleStoryItem['story_title'] ?? 'القصة المخصصة' }}</p>
                            </div>
                        @endif

                        @unless($collectsChildDetails)<div>
                            <label class="mb-2 block text-sm font-black text-slate-700">الكمية</label>
                            <input type="number" name="quantity" min="1" value="1" class="w-32 rounded-2xl border-slate-200 text-center">
                        </div>@endunless

                        <button type="submit" @disabled(! $canSubmit)
                            class="w-full rounded-2xl bg-indigo-600 py-4 text-base font-black text-white shadow-lg shadow-indigo-100 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                            {{ $requiresStory ? 'إضافة الهدية للسلة' : 'إضافة للسلة' }}
                        </button>
                    </form>

                    @if($product->description_ar)
                        <div class="mt-8 border-t border-slate-100 pt-6 text-slate-600 leading-8">{!! nl2br(e($product->description_ar)) !!}</div>
                    @endif

                    @if($product->features)
                        <div class="mt-6">
                            <h2 class="mb-3 font-black text-slate-950">المميزات</h2>
                            <ul class="space-y-2 text-slate-600">
                                @foreach($product->features as $feature)
                                    <li>• {{ $feature }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>

            @if($relatedProducts->count())
                <section class="mt-12">
                    <div class="mb-5 text-right">
                        <h2 class="text-2xl font-black text-slate-950">منتجات مقترحة</h2>
                    </div>
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach($relatedProducts as $related)
                            @include('front.shop._product-card', ['product' => $related])
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </div>
    @if($collectsChildDetails)
        @push('scripts')
            <script>
                (() => {
                    const form = document.querySelector('[data-product-order-form]');
                    const quantity = form?.querySelector('[data-personalized-product-quantity]');
                    const units = Array.from(form?.querySelectorAll('[data-personalization-unit]') || []);
                    if (!form || !quantity || !units.length) return;

                    const refresh = () => {
                        const count = Math.max(1, Math.min(10, Number(quantity.value || 1)));
                        quantity.value = count;
                        units.forEach((unit, index) => {
                            const active = index < count;
                            unit.hidden = !active;
                            unit.querySelectorAll('input, select, textarea, button').forEach(field => {
                                if (field.matches('[data-reuse-first-child]')) {
                                    field.disabled = !active;
                                    return;
                                }
                                if (!active) field.disabled = true;
                                else if (!unit.querySelector('[data-reuse-first-child]')?.checked) field.disabled = false;
                            });
                            unit.dispatchEvent(new Event('identity-unit-state'));
                        });
                    };

                    quantity.addEventListener('input', refresh);
                    form.addEventListener('change', (event) => {
                        if (event.target.matches('[data-reuse-first-child]')) refresh();
                    });
                    refresh();
                })();
            </script>
        @endpush
    @endif
</x-front-layout>
