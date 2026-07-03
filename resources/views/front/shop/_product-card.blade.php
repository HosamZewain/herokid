@php
    $image = $product->featured_image_url ?: '/images/logo-192.png';
    $price = $product->effectivePrice();
@endphp

<article class="group overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-sm transition hover:-translate-y-1 hover:shadow-xl">
    <a href="{{ route('shop.product.show', $product) }}" class="block">
        <div class="aspect-[4/3] bg-indigo-50 overflow-hidden">
            <img src="{{ $image }}" alt="{{ $product->name_ar }}" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" loading="lazy">
        </div>
        <div class="p-5 text-right">
            <div class="mb-3 flex flex-wrap justify-end gap-2">
                @if($product->personalization_mode === 'inherit_from_linked_story')
                    <span class="rounded-full bg-pink-50 px-3 py-1 text-xs font-black text-pink-700">يستخدم قصة طفلك</span>
                @elseif($product->purchase_mode === 'add_on_only')
                    <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-black text-amber-700">إضافة مع قصة</span>
                @else
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-black text-emerald-700">شراء مباشر</span>
                @endif
                <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-bold text-slate-500">{{ $product->ageLabel() }}</span>
            </div>
            <h3 class="text-lg font-black text-slate-950 group-hover:text-indigo-700">{{ $product->name_ar }}</h3>
            @if($product->short_description_ar)
                <p class="mt-2 line-clamp-2 text-sm leading-6 text-slate-500">{{ $product->short_description_ar }}</p>
            @endif
            <div class="mt-4 flex items-end justify-between gap-3">
                <span class="text-lg font-black text-indigo-700">{{ number_format($price, 0) }} ج.م</span>
                @if($product->sale_price_cents)
                    <span class="text-sm text-slate-400 line-through">{{ number_format($product->price_cents / 100, 0) }} ج.م</span>
                @endif
            </div>
        </div>
    </a>
</article>
