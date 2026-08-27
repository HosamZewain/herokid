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
        $productUploadConfig = $collectsChildDetails ? array_merge($photoUploadConfig, [
            'serverRejectedUploads' => $errors->has('photo_upload_ids') || $errors->has('photo_upload_ids.*'),
            'restoredUploadIds' => $errors->has('photo_upload_ids') || $errors->has('photo_upload_ids.*')
                ? []
                : old('photo_upload_ids', []),
        ]) : null;
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

                    <form action="{{ route('cart.products.store', $product) }}" method="POST" class="mt-6 space-y-4"
                        @if($collectsChildDetails) data-identity-intake @endif>
                        @csrf
                        @if($collectsChildDetails)
                            <input type="hidden" name="upload_session_token" value="{{ $photoUploadConfig['sessionToken'] }}">
                            <script type="application/json" data-identity-upload-config>@json($productUploadConfig)</script>

                            <section class="rounded-3xl border border-indigo-200 bg-indigo-50/60 p-4 sm:p-5">
                                <div class="mb-4 text-right">
                                    <span class="inline-flex rounded-full bg-pink-500 px-3 py-1 text-xs font-black text-white">مطلوب للتخصيص</span>
                                    <h2 class="mt-2 text-xl font-black text-slate-950">بيانات الطفل والصور</h2>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">تُحفظ هذه البيانات مع المنتج داخل طلبك.</p>
                                </div>

                                @if($errors->any())
                                    <div class="mb-4 rounded-2xl border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-700" data-scroll-on-load tabindex="-1">
                                        {{ $errors->first() }}
                                    </div>
                                @endif

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label for="product-child-name" class="block text-sm font-black text-slate-700">
                                        اسم الطفل
                                        <input id="product-child-name" name="child_name" value="{{ old('child_name') }}" required autocomplete="off"
                                            class="mt-2 w-full rounded-xl border-slate-300 px-4 py-3 text-right focus:border-indigo-500 focus:ring-indigo-500">
                                        <x-input-error :messages="$errors->get('child_name')" class="mt-1" />
                                    </label>
                                    <label for="product-child-age" class="block text-sm font-black text-slate-700">
                                        عمر الطفل
                                        <select id="product-child-age" name="child_age" required class="mt-2 w-full rounded-xl border-slate-300 px-4 py-3 focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="">اختر العمر</option>
                                            @foreach($ageOptions as $age)
                                                <option value="{{ $age }}" @selected((string) old('child_age') === (string) $age)>{{ arabic_number($age) }} سنوات</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('child_age')" class="mt-1" />
                                    </label>
                                    <label for="product-child-gender" class="block text-sm font-black text-slate-700 sm:col-span-2">
                                        جنس الطفل
                                        <select id="product-child-gender" name="child_gender" required class="mt-2 w-full rounded-xl border-slate-300 px-4 py-3 focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="">اختر الجنس</option>
                                            <option value="boy" @selected(old('child_gender') === 'boy')>ولد 👦</option>
                                            <option value="girl" @selected(old('child_gender') === 'girl')>بنت 👧</option>
                                        </select>
                                        <x-input-error :messages="$errors->get('child_gender')" class="mt-1" />
                                    </label>
                                    <label for="product-interests" class="block text-sm font-black text-slate-700 sm:col-span-2">
                                        اهتمامات أو ملاحظات عن الطفل <span class="font-bold text-slate-400">(اختياري)</span>
                                        <textarea id="product-interests" name="interests" rows="2" class="mt-2 w-full rounded-xl border-slate-300 px-4 py-3 text-right focus:border-indigo-500 focus:ring-indigo-500">{{ old('interests') }}</textarea>
                                        <x-input-error :messages="$errors->get('interests')" class="mt-1" />
                                    </label>
                                </div>

                                <div class="mt-4 rounded-2xl border-2 border-dashed border-indigo-200 bg-white p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="text-right">
                                            <h3 class="font-black text-indigo-950">صور الطفل</h3>
                                            <p class="mt-1 text-xs font-bold text-indigo-700">اختر صورتين أو ٣ صور واضحة وسيبدأ رفعها تلقائيًا.</p>
                                        </div>
                                        <span class="rounded-full bg-indigo-50 px-3 py-1.5 text-xs font-black text-indigo-700" data-identity-photo-count>تم رفع ٠ من ٢ المطلوبة</span>
                                    </div>
                                    <input type="file" id="product-child-photos" multiple accept="image/*,.jpg,.jpeg,.png,.webp,.heic,.heif" class="sr-only" data-identity-photo-input>
                                    <div data-identity-photo-ids></div>
                                    <label for="product-child-photos" data-identity-photo-picker
                                        class="mt-4 flex min-h-24 cursor-pointer flex-col items-center justify-center rounded-xl border border-indigo-200 bg-indigo-50/50 px-4 py-4 text-center transition hover:border-indigo-400">
                                        <span class="font-black text-indigo-700" data-identity-photo-picker-title>اختيار صور الطفل</span>
                                        <span class="mt-1 text-xs font-bold text-slate-500" data-identity-photo-picker-help>اختر صورتين أو ٣ صور مرة واحدة وسيبدأ رفعها تلقائيًا.</span>
                                    </label>
                                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3" data-identity-photo-queue aria-live="polite"></div>
                                    <div class="mt-3 rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2" role="status" aria-live="polite" data-identity-photo-requirement>
                                        <p class="text-sm font-black text-indigo-950" data-identity-photo-requirement-title>اختر صورتين للمتابعة</p>
                                        <p class="mt-1 text-xs font-bold leading-5 text-indigo-700" data-identity-photo-requirement-description>نحتاج صورتين واضحتين على الأقل، ويمكنك إضافة صورة ثالثة اختيارية.</p>
                                    </div>
                                    <div class="mt-3 hidden rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-black text-red-700" data-identity-photo-error></div>
                                    <x-input-error :messages="$errors->get('photo_upload_ids')" class="mt-2" />
                                    <x-input-error :messages="$errors->get('photo_upload_ids.*')" class="mt-2" />
                                </div>
                            </section>
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

                        <div>
                            <label class="mb-2 block text-sm font-black text-slate-700">الكمية</label>
                            <input type="number" name="quantity" min="1" value="1" class="w-32 rounded-2xl border-slate-200 text-center">
                        </div>

                        <button type="submit" @disabled(! $canSubmit || $collectsChildDetails)
                            @if($collectsChildDetails) data-identity-submit @endif
                            class="w-full rounded-2xl bg-indigo-600 py-4 text-base font-black text-white shadow-lg shadow-indigo-100 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                            @if($collectsChildDetails)
                                <span data-submit-label>اختر صورتين للمتابعة</span>
                            @else
                                {{ $requiresStory ? 'إضافة الهدية للسلة' : 'إضافة للسلة' }}
                            @endif
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
</x-front-layout>
