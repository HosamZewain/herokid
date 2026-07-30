@php
    $mobileItemType = $item['item_type'] ?? 'story';
    $mobileLinkedAddOns = $mobileItemType === 'story'
        ? ($addOnItems ?? collect())->filter(fn ($addOn) => ($addOn['linked_story_key'] ?? null) === $key)
        : collect();
    $mobileTitle = $mobileItemType === 'story'
        ? ($item['story_title'] ?? 'قصة')
        : ($item['product_title'] ?? 'منتج');
    $mobilePrice = $mobileItemType === 'story'
        ? (float) ($item['story_price'] ?? 0)
        : ((int) ($item['line_total_cents'] ?? 0) / 100);
@endphp

<article class="relative px-3 py-2.5 pl-12 transition duration-200" data-cart-mobile-item data-cart-item-key="{{ $key }}">
    <form action="{{ route('cart.destroy', $key) }}" method="POST" data-cart-remove-form
        @if($mobileLinkedAddOns->isNotEmpty()) data-confirm="سيتم حذف الإضافات المرتبطة بهذه القصة أيضاً. هل تريد المتابعة؟" @endif
        class="absolute left-2 top-2">
        @csrf
        @method('DELETE')
        <button type="submit"
            class="flex h-8 w-8 items-center justify-center rounded-full bg-red-50 text-base font-black text-red-600 transition hover:bg-red-100 disabled:cursor-wait disabled:opacity-50"
            aria-label="حذف {{ $mobileTitle }}">
            ×
        </button>
    </form>

    <div class="min-w-0 text-right">
        <div class="flex min-w-0 items-center gap-2">
            <span class="shrink-0 rounded-full px-2 py-0.5 text-[9px] font-black {{ $mobileItemType === 'story' ? 'bg-indigo-50 text-indigo-600' : ($mobileItemType === 'product_add_on' ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700') }}">
                {{ $mobileItemType === 'story' ? 'قصة' : ($mobileItemType === 'product_add_on' ? 'إضافة' : 'منتج') }}
            </span>
            <h3 class="line-clamp-1 min-w-0 text-xs font-black text-slate-950">{{ $mobileTitle }}</h3>
        </div>
        <div class="mt-1.5 flex items-center justify-between gap-2">
            <p class="min-w-0 truncate text-[10px] font-bold text-slate-500">
                @if($mobileItemType === 'story')
                    {{ $item['child_name'] ?? 'الطفل' }} · {{ $item['child_age_range'] ?? (($item['child_age'] ?? '-') . ' سنة') }} · {{ count($item['uploaded_photos'] ?? []) }} صورة
                    @if($mobileLinkedAddOns->isNotEmpty()) · {{ $mobileLinkedAddOns->count() }} إضافة @endif
                @elseif($mobileItemType === 'product_add_on')
                    إضافة مرتبطة · الكمية {{ $item['quantity'] ?? 1 }}
                @else
                    الكمية {{ $item['quantity'] ?? 1 }}@if(!empty($item['variant_name'])) · {{ $item['variant_name'] }}@endif
                @endif
            </p>
            <p class="shrink-0 text-xs font-black text-indigo-700">{{ format_money($mobilePrice) }}</p>
        </div>
    </div>
</article>
