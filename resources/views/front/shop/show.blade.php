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
        $productImageUrls = collect([
            $product->featured_image_url,
            ...collect($product->gallery_images ?? [])->map(
                fn ($image) => \App\Support\Seo::imageUrl(\Illuminate\Support\Facades\Storage::disk('public')->url($image))
            ),
        ])->filter()->unique()->values()->all();
        $initialVariant = $product->activeVariants->first();
        $initialDisplayImage = $initialVariant?->all_image_urls[0] ?? ($productImageUrls[0] ?? null);
        $initialDisplayPrice = $product->effectivePriceCents($initialVariant) / 100;
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
                        @if($initialDisplayImage)
                            <img src="{{ $initialDisplayImage }}" alt="{{ $product->name_ar }}" class="aspect-[4/3] w-full object-cover" data-product-main-image>
                        @else
                            <div class="aspect-[4/3]">
                                <x-product-image-placeholder />
                            </div>
                        @endif
                    </div>
                    @if($product->gallery_images || $product->activeVariants->count())
                        <div class="grid grid-cols-4 gap-3" data-product-gallery>
                            @foreach(($product->gallery_images ?? []) as $image)
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
                            <p class="text-3xl font-black text-indigo-700" data-product-price>{{ format_money($initialDisplayPrice) }}</p>
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
                            @php
                                $initialQuantity = max(1, min(10, (int) old('quantity', 1)));
                            @endphp
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
                            <fieldset data-product-variants>
                                <legend class="mb-3 block text-sm font-black text-slate-700">اختر النوع المطلوب</legend>
                                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                    @foreach($product->activeVariants as $variant)
                                        @php
                                            $variantImages = $variant->all_image_urls ?: $productImageUrls;
                                            $variantPrice = format_money($product->effectivePriceCents($variant) / 100);
                                        @endphp
                                        <label class="group relative cursor-pointer rounded-2xl border-2 border-slate-200 bg-white p-2 transition has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-indigo-400">
                                            <input type="radio" name="variant_id" value="{{ $variant->id }}" required
                                                @checked((string) old('variant_id', $product->activeVariants->first()?->id) === (string) $variant->id)
                                                class="sr-only"
                                                data-variant-option
                                                data-price="{{ $variantPrice }}"
                                                data-images='@json($variantImages)'
                                                data-name="{{ $variant->name_ar }}">
                                            @if($variantImages[0] ?? null)
                                                <img src="{{ $variantImages[0] }}" alt="{{ $variant->name_ar }}" class="aspect-square w-full rounded-xl object-cover">
                                            @else
                                                <div class="aspect-square rounded-xl bg-slate-100"></div>
                                            @endif
                                            <span class="mt-2 block text-sm font-black text-slate-900">{{ $variant->name_ar }}</span>
                                            <span class="block text-sm font-black text-indigo-700">{{ $variantPrice }}</span>
                                            @if($variant->sku)
                                                <span class="block text-[11px] text-slate-400" dir="ltr">{{ $variant->sku }}</span>
                                            @endif
                                            @if($variant->attributes)
                                                <span class="mt-1 block text-xs text-slate-500">{{ implode(' · ', $variant->attributes) }}</span>
                                            @endif
                                        </label>
                                    @endforeach
                                </div>
                                <x-input-error :messages="$errors->get('variant_id')" class="mt-2" />
                            </fieldset>
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
    @if($product->activeVariants->count())
        @push('scripts')
            <script>
                (() => {
                    const options = Array.from(document.querySelectorAll('[data-variant-option]'));
                    const mainImage = document.querySelector('[data-product-main-image]');
                    const price = document.querySelector('[data-product-price]');
                    const gallery = document.querySelector('[data-product-gallery]');
                    if (!options.length) return;

                    const selectVariant = (option) => {
                        const images = JSON.parse(option.dataset.images || '[]');
                        if (price) price.textContent = option.dataset.price || '';
                        if (mainImage && images[0]) {
                            mainImage.src = images[0];
                            mainImage.alt = option.dataset.name || mainImage.alt;
                        }
                        if (gallery) {
                            gallery.replaceChildren(...images.slice(1).map((source) => {
                                const image = document.createElement('img');
                                image.src = source;
                                image.alt = option.dataset.name || '';
                                image.className = 'aspect-square rounded-2xl object-cover';
                                image.loading = 'lazy';
                                image.addEventListener('click', () => {
                                    if (mainImage) mainImage.src = source;
                                });
                                return image;
                            }));
                        }
                    };

                    options.forEach(option => option.addEventListener('change', () => selectVariant(option)));
                    selectVariant(options.find(option => option.checked) || options[0]);
                })();
            </script>
        @endpush
    @endif
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
