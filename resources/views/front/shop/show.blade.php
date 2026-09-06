<x-front-layout>
    <x-slot name="pageTitle">{{ $product->seo_title_ar ?: $product->name_ar }}</x-slot>
    <x-slot name="pageDescription">{{ $product->seo_description_ar ?: ($product->short_description_ar ?: 'منتج من متجر HeroKid للأطفال.') }}</x-slot>
    <x-slot name="canonical">/shop/product/{{ $product->slug }}</x-slot>
    <x-slot name="ogType">product</x-slot>
    <x-slot name="pageImageAlt">{{ $product->name_ar }} من HeroKid</x-slot>
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
        $initialVariantImages = $initialVariant?->all_image_urls ?: $productImageUrls;
        $initialDisplayImage = $initialVariantImages[0] ?? null;
        $initialDisplayPrice = $product->effectivePriceCents($initialVariant) / 100;
        $initialQuantity = max(1, min(10, (int) old('quantity', 1)));
        $productSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => \App\Support\Seo::url('/shop/product/'.$product->slug.'#product'),
            'name' => $product->name_ar,
            'description' => $product->seo_description_ar ?: ($product->short_description_ar ?: $product->description_ar),
            'url' => \App\Support\Seo::url('/shop/product/'.$product->slug),
            'image' => $productImageUrls,
            'brand' => ['@type' => 'Brand', 'name' => 'HeroKid'],
            'offers' => [
                '@type' => 'Offer',
                'url' => \App\Support\Seo::url('/shop/product/'.$product->slug),
                'priceCurrency' => 'EGP',
                'price' => number_format($initialDisplayPrice, 2, '.', ''),
                'availability' => $product->hasStock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'itemCondition' => 'https://schema.org/NewCondition',
            ],
        ];
        if ($product->sku) $productSchema['sku'] = $product->sku;
    @endphp

    @push('schema')
        <script type="application/ld+json">
        @json($productSchema, \App\Support\Seo::jsonFlags())
        </script>
    @endpush

    <main class="bg-slate-50/70 py-5 sm:py-8 lg:py-10">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <nav aria-label="مسار التنقل" class="mb-5 overflow-hidden text-xs font-bold text-slate-500 sm:text-sm">
                <ol class="flex items-center gap-2 whitespace-nowrap">
                    <li><a href="{{ route('home') }}" class="hover:text-indigo-700">الرئيسية</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('shop.index') }}" class="hover:text-indigo-700">المتجر</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="truncate text-slate-800" aria-current="page">{{ $product->name_ar }}</li>
                </ol>
            </nav>
            <a href="{{ route('shop.index') }}" class="mb-4 inline-flex text-xs font-black text-indigo-700 hover:text-indigo-900 sm:text-sm">العودة إلى متجر القصص والمنتجات</a>

            <form action="{{ route('cart.products.store', $product) }}" method="POST" data-product-order-form>
                @csrf
                <section class="grid items-start gap-5 lg:grid-cols-[minmax(0,1.08fr)_minmax(380px,0.92fr)] lg:gap-8">
                    <div class="min-w-0 space-y-3 lg:sticky lg:top-32">
                        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm sm:rounded-3xl">
                            @if($initialDisplayImage)
                                <img src="{{ $initialDisplayImage }}" alt="{{ $product->name_ar }}" width="900" height="675"
                                    class="aspect-[4/3] w-full object-cover" fetchpriority="high" data-product-main-image>
                            @else
                                <div class="aspect-[4/3]"><x-product-image-placeholder /></div>
                            @endif
                        </div>

                        @if(count($initialVariantImages) > 1 || $product->activeVariants->count())
                            <div class="flex snap-x gap-2 overflow-x-auto pb-1" data-product-gallery aria-label="صور المنتج">
                                @foreach($initialVariantImages as $imageIndex => $image)
                                    <button type="button" data-gallery-image data-image="{{ $image }}"
                                        class="w-20 shrink-0 snap-start overflow-hidden rounded-xl border-2 bg-white p-1 transition {{ $imageIndex === 0 ? 'border-indigo-500' : 'border-transparent hover:border-indigo-200' }} sm:w-24"
                                        aria-label="عرض صورة المنتج {{ arabic_number($imageIndex + 1) }}">
                                        <img src="{{ $image }}" alt="صورة {{ arabic_number($imageIndex + 1) }} من {{ $product->name_ar }}" width="160" height="160" class="aspect-square w-full rounded-lg object-cover" loading="lazy">
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="min-w-0 rounded-2xl border border-slate-100 bg-white p-4 text-right shadow-sm sm:rounded-3xl sm:p-6 lg:p-7">
                        <a href="{{ $product->category ? route('shop.category', $product->category) : route('shop.index') }}" class="inline-flex rounded-full bg-indigo-50 px-3 py-1.5 text-xs font-black text-indigo-700">{{ $product->category?->name_ar }}</a>
                        <h1 class="mt-3 text-2xl font-black leading-tight text-slate-950 sm:text-3xl lg:text-4xl">{{ $product->name_ar }}</h1>
                        @if($product->short_description_ar)
                            <p class="mt-3 text-sm font-medium leading-7 text-slate-600 sm:text-base">{{ $product->short_description_ar }}</p>
                        @endif

                        <div class="mt-5 flex items-end justify-between gap-4 border-y border-slate-100 py-4">
                            <div>
                                <p class="text-xs font-bold text-slate-500">السعر</p>
                                <p class="mt-1 text-3xl font-black text-indigo-700" data-product-price>{{ format_money($initialDisplayPrice) }}</p>
                            </div>
                            <div class="text-left">
                                <p class="text-xs font-bold text-slate-500">العمر المناسب</p>
                                <p class="mt-1 font-black text-slate-900">{{ $product->ageLabel() }}</p>
                            </div>
                        </div>

                        @if($product->activeVariants->count())
                            <fieldset class="mt-5" data-product-variants>
                                <legend class="mb-3 text-sm font-black text-slate-800">اختر النوع المطلوب</legend>
                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-2 xl:grid-cols-3">
                                    @foreach($product->activeVariants as $variant)
                                        @php
                                            $variantImages = $variant->all_image_urls ?: $productImageUrls;
                                            $variantPrice = format_money($product->effectivePriceCents($variant) / 100);
                                        @endphp
                                        <label class="cursor-pointer rounded-xl border-2 border-slate-200 bg-white p-2 transition has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-indigo-400">
                                            <input type="radio" name="variant_id" value="{{ $variant->id }}" required
                                                @checked((string) old('variant_id', $initialVariant?->id) === (string) $variant->id)
                                                class="sr-only" data-variant-option data-price="{{ $variantPrice }}" data-images='@json($variantImages)' data-name="{{ $variant->name_ar }}">
                                            @if($variantImages[0] ?? null)
                                                <img src="{{ $variantImages[0] }}" alt="{{ $variant->name_ar }}" width="180" height="180" class="aspect-square w-full rounded-lg object-cover" loading="lazy">
                                            @endif
                                            <span class="mt-2 block text-xs font-black text-slate-900">{{ $variant->name_ar }}</span>
                                            <span class="block text-xs font-black text-indigo-700">{{ $variantPrice }}</span>
                                            @if($variant->sku)
                                                <span class="mt-1 block text-[10px] font-bold text-slate-400" dir="ltr">{{ $variant->sku }}</span>
                                            @endif
                                        </label>
                                    @endforeach
                                </div>
                                <x-input-error :messages="$errors->get('variant_id')" class="mt-2" />
                            </fieldset>
                        @endif

                        @if($requiresStory && $storyItems->isEmpty())
                            <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                <p class="text-xs font-black text-amber-700">منتج مخصص مرتبط بقصة</p>
                                <p class="mt-1 font-black leading-6 text-amber-950">أضف قصة مخصصة أولًا لاستخدام صورة طفلك في هذا المنتج.</p>
                                <a href="{{ route('shop.index', ['type' => 'stories']) }}" class="mt-3 inline-flex rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white">اختيار قصة مخصصة</a>
                            </div>
                        @endif

                        @if($collectsChildDetails)
                            <div class="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50/70 p-4">
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-black text-indigo-950"><span class="ml-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-indigo-600 text-xs text-white">١</span>عدد الأطفال / النسخ</p>
                                        <p class="mt-1 text-xs font-bold text-indigo-700">سنفتح بيانات مستقلة لكل طفل.</p>
                                    </div>
                                    <input id="personalized-product-quantity" type="number" name="quantity" min="1" max="10" value="{{ $initialQuantity }}"
                                        aria-label="عدد الأطفال أو النسخ" class="w-20 rounded-xl border-indigo-200 text-center font-black" data-personalized-product-quantity>
                                </div>
                            </div>
                            <a href="#personalization-details" class="mt-4 flex min-h-12 w-full items-center justify-center rounded-xl bg-indigo-600 px-4 py-3 text-sm font-black text-white shadow-lg shadow-indigo-100 hover:bg-indigo-700">ابدأ إدخال بيانات الطفل</a>
                        @else
                            @if($requiresStory && $storyItems->count() > 1)
                                <label class="mt-5 block text-sm font-black text-slate-700">هذا المنتج مخصص لأي طفل؟
                                    <select name="linked_story_key" required class="mt-2 w-full rounded-xl border-slate-200 text-right">
                                        <option value="">اختر الطفل والقصة...</option>
                                        @foreach($storyItems as $storyItem)
                                            <option value="{{ $storyItem['key'] }}">{{ $storyItem['child_name'] ?? 'طفل' }} - {{ $storyItem['story_title'] ?? 'قصة' }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('linked_story_key')" class="mt-1" />
                                </label>
                            @elseif($requiresStory && $storyItems->count() === 1)
                                <input type="hidden" name="linked_story_key" value="{{ $singleStoryItem['key'] }}">
                                <div class="mt-5 rounded-xl border border-indigo-100 bg-indigo-50 p-3 text-sm font-bold">سيتم تخصيصه تلقائيًا لـ <strong>{{ $singleStoryItem['child_name'] ?? 'الطفل' }}</strong></div>
                            @endif
                            <label class="mt-5 block text-sm font-black text-slate-700">الكمية
                                <input type="number" name="quantity" min="1" value="1" class="mt-2 block w-24 rounded-xl border-slate-200 text-center">
                            </label>
                            <button type="submit" @disabled(! $canSubmit) class="mt-5 min-h-12 w-full rounded-xl bg-indigo-600 px-4 py-3 text-base font-black text-white shadow-lg shadow-indigo-100 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                                {{ $requiresStory ? 'إضافة الهدية للسلة' : 'إضافة للسلة' }}
                            </button>
                        @endif
                    </div>
                </section>

                @if($collectsChildDetails)
                    <section id="personalization-details" class="mt-6 scroll-mt-28 rounded-2xl border border-indigo-100 bg-white p-4 shadow-sm sm:rounded-3xl sm:p-6 lg:p-8">
                        <div class="mb-5 text-right">
                            <p class="text-sm font-black text-indigo-600"><span class="ml-2 inline-flex h-7 w-7 items-center justify-center rounded-full bg-indigo-600 text-white">٢</span>التخصيص</p>
                            <h2 class="mt-2 text-xl font-black text-slate-950 sm:text-2xl">بيانات وصور الأطفال</h2>
                            <p class="mt-1 text-sm font-bold leading-6 text-slate-500">أدخل بيانات كل طفل وارفع صوره، أو استخدم بيانات الطفل الأول للنسخ المتكررة.</p>
                        </div>
                        <div class="grid items-start gap-4 lg:grid-cols-2" data-personalization-units>
                            @for($unitIndex = 0; $unitIndex < 10; $unitIndex++)
                                @include('front.shop._personalization-unit', compact('unitIndex', 'initialQuantity'))
                            @endfor
                        </div>
                        <button type="submit" @disabled(! $canSubmit) class="mt-5 min-h-14 w-full rounded-2xl bg-indigo-600 px-5 py-4 text-base font-black text-white shadow-lg shadow-indigo-100 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                            <span class="ml-2 inline-flex h-7 w-7 items-center justify-center rounded-full bg-white/20 text-sm">٣</span>
                            إضافة للسلة — <span data-product-price>{{ format_money($initialDisplayPrice) }}</span>
                        </button>
                    </section>
                @endif
            </form>

            @if($relatedProducts->count())
                <section class="mt-8 overflow-hidden rounded-3xl bg-indigo-700 px-4 py-6 shadow-xl shadow-indigo-100 sm:px-6 lg:px-8" aria-labelledby="related-products-title">
                    <div class="mb-5 flex items-end justify-between gap-4 text-right text-white">
                        <div>
                            <p class="text-xs font-black text-indigo-200">اختيارات تكمل طلبك</p>
                            <h2 id="related-products-title" class="mt-1 text-2xl font-black sm:text-3xl">أكمل مجموعة طفلك</h2>
                        </div>
                        <a href="{{ route('shop.index') }}" class="shrink-0 text-xs font-black text-white underline decoration-indigo-300 underline-offset-4 sm:text-sm">عرض كل المنتجات</a>
                    </div>
                    <div class="-mx-1 flex snap-x snap-mandatory gap-3 overflow-x-auto px-1 pb-2 sm:grid sm:grid-cols-2 sm:overflow-visible lg:grid-cols-4">
                        @foreach($relatedProducts as $related)
                            <div class="w-[82%] shrink-0 snap-start sm:w-auto">@include('front.shop._product-card', ['product' => $related])</div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($product->description_ar || $product->features)
                <section class="mt-8 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm sm:rounded-3xl sm:p-6 lg:p-8" aria-labelledby="product-details-title">
                    <h2 id="product-details-title" class="text-xl font-black text-slate-950 sm:text-2xl">تفاصيل المنتج</h2>
                    @if($product->description_ar)
                        <details open class="group mt-4 border-b border-slate-100 pb-4">
                            <summary class="flex cursor-pointer list-none items-center justify-between py-2 font-black text-slate-900 [&::-webkit-details-marker]:hidden">
                                <span>وصف المنتج</span><span class="text-sm text-indigo-600"><span class="group-open:hidden">عرض</span><span class="hidden group-open:inline">إخفاء</span></span>
                            </summary>
                            <div class="max-w-4xl whitespace-pre-line text-sm font-medium leading-8 text-slate-600 sm:text-base">{{ $product->description_ar }}</div>
                        </details>
                    @endif
                    @if($product->features)
                        <details class="group border-b border-slate-100 py-4">
                            <summary class="flex cursor-pointer list-none items-center justify-between font-black text-slate-900 [&::-webkit-details-marker]:hidden">
                                <span>المميزات ومحتويات الباقة</span><span class="text-sm text-indigo-600"><span class="group-open:hidden">عرض</span><span class="hidden group-open:inline">إخفاء</span></span>
                            </summary>
                            <ul class="mt-4 grid gap-3 text-sm font-bold leading-7 text-slate-600 sm:grid-cols-2">
                                @foreach($product->features as $feature)<li class="rounded-xl bg-slate-50 px-4 py-3">{{ $feature }}</li>@endforeach
                            </ul>
                        </details>
                    @endif
                    @if($product->shipping_notes_ar)
                        <details class="group pt-4">
                            <summary class="flex cursor-pointer list-none items-center justify-between font-black text-slate-900 [&::-webkit-details-marker]:hidden">
                                <span>الشحن والتوصيل</span><span class="text-sm text-indigo-600"><span class="group-open:hidden">عرض</span><span class="hidden group-open:inline">إخفاء</span></span>
                            </summary>
                            <p class="mt-4 text-sm font-medium leading-7 text-slate-600">{{ $product->shipping_notes_ar }}</p>
                        </details>
                    @endif
                </section>
            @endif
        </div>
    </main>

    @push('scripts')
        <script>
            (() => {
                const options = Array.from(document.querySelectorAll('[data-variant-option]'));
                const mainImage = document.querySelector('[data-product-main-image]');
                const prices = Array.from(document.querySelectorAll('[data-product-price]'));
                const gallery = document.querySelector('[data-product-gallery]');

                const bindGallery = () => {
                    gallery?.querySelectorAll('[data-gallery-image]').forEach((button) => {
                        button.addEventListener('click', () => {
                            if (mainImage && button.dataset.image) mainImage.src = button.dataset.image;
                            gallery.querySelectorAll('[data-gallery-image]').forEach((item) => {
                                item.classList.toggle('border-indigo-500', item === button);
                                item.classList.toggle('border-transparent', item !== button);
                            });
                        });
                    });
                };

                const renderGallery = (images, name) => {
                    if (!gallery) return;
                    gallery.replaceChildren(...images.map((source, index) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.dataset.galleryImage = '';
                        button.dataset.image = source;
                        button.className = `w-20 shrink-0 snap-start overflow-hidden rounded-xl border-2 bg-white p-1 transition sm:w-24 ${index === 0 ? 'border-indigo-500' : 'border-transparent hover:border-indigo-200'}`;
                        button.setAttribute('aria-label', `عرض صورة ${index + 1} من ${name}`);
                        const image = document.createElement('img');
                        image.src = source;
                        image.alt = name;
                        image.loading = 'lazy';
                        image.className = 'aspect-square w-full rounded-lg object-cover';
                        button.appendChild(image);
                        return button;
                    }));
                    bindGallery();
                };

                const selectVariant = (option) => {
                    const images = JSON.parse(option.dataset.images || '[]');
                    prices.forEach((price) => price.textContent = option.dataset.price || '');
                    if (mainImage && images[0]) {
                        mainImage.src = images[0];
                        mainImage.alt = option.dataset.name || mainImage.alt;
                    }
                    renderGallery(images, option.dataset.name || 'المنتج');
                };

                options.forEach((option) => option.addEventListener('change', () => selectVariant(option)));
                if (options.length) selectVariant(options.find((option) => option.checked) || options[0]);
                else bindGallery();
            })();
        </script>
    @endpush

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
                            unit.querySelectorAll('input, select, textarea, button').forEach((field) => {
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
