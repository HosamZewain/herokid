<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductCartController extends Controller
{
    public function store(Request $request, Product $product)
    {
        abort_unless($product->is_active, 404);

        $validated = $request->validate([
            'variant_id' => 'nullable|integer|exists:product_variants,id',
            'quantity' => 'nullable|integer|min:1|max:50',
            'linked_story_key' => 'nullable|string',
        ]);

        $quantity = (int) ($validated['quantity'] ?? 1);
        $variant = null;

        if (! empty($validated['variant_id'])) {
            $variant = ProductVariant::where('product_id', $product->id)
                ->where('is_active', true)
                ->findOrFail($validated['variant_id']);
        }

        $cart = session('cart.items', []);
        $storyItems = collect($cart)->filter(fn (array $item) => ($item['item_type'] ?? 'story') === 'story');
        $requiresStory = $product->personalization_mode === 'inherit_from_linked_story' || $product->purchase_mode === 'add_on_only';
        $linkedStoryKey = $validated['linked_story_key'] ?? null;

        if ($requiresStory) {
            if ($storyItems->isEmpty()) {
                return back()->with('error', 'أضف قصة مخصصة أولًا لاستخدام صورة طفلك في هذا المنتج.');
            }

            if (! $linkedStoryKey && $storyItems->count() === 1) {
                $linkedStoryKey = (string) $storyItems->keys()->first();
            }

            if (! $linkedStoryKey || ! isset($cart[$linkedStoryKey]) || ($cart[$linkedStoryKey]['item_type'] ?? 'story') !== 'story') {
                return back()->withErrors(['linked_story_key' => 'اختر الطفل والقصة التي سيتم تخصيص المنتج لها.'])->withInput();
            }
        }

        if ($product->purchase_mode === 'add_on_only' && ! $linkedStoryKey) {
            return back()->with('error', 'هذا المنتج يضاف فقط مع قصة مخصصة.');
        }

        if (! $product->hasStock($quantity, $variant)) {
            return back()->with('error', 'الكمية المطلوبة غير متاحة حالياً.');
        }

        $unitPriceCents = $product->effectivePriceCents($variant);
        $itemKey = (string) Str::uuid();
        $linkedStory = $linkedStoryKey ? $cart[$linkedStoryKey] : null;

        $cart[$itemKey] = [
            'key' => $itemKey,
            'item_type' => $linkedStoryKey ? 'product_add_on' : 'product',
            'product_id' => $product->id,
            'product_title' => $product->name_ar,
            'product_slug' => $product->slug,
            'product_image' => $product->featured_image,
            'variant_id' => $variant?->id,
            'variant_name' => $variant?->name_ar,
            'sku' => $variant?->sku ?: $product->sku,
            'unit_price_cents' => $unitPriceCents,
            'unit_price' => $unitPriceCents / 100,
            'quantity' => $quantity,
            'line_total_cents' => $unitPriceCents * $quantity,
            'personalization_mode' => $product->personalization_mode,
            'linked_story_key' => $linkedStoryKey,
            'linked_story_snapshot' => $linkedStory ? [
                'story_id' => $linkedStory['story_id'] ?? null,
                'story_title' => $linkedStory['story_title'] ?? null,
                'child_name' => $linkedStory['child_name'] ?? null,
                'child_age' => $linkedStory['child_age'] ?? null,
                'child_gender' => $linkedStory['child_gender'] ?? null,
            ] : null,
        ];

        session(['cart.items' => $cart]);

        return redirect()->route('cart.index')->with('success', 'تمت إضافة المنتج إلى السلة.');
    }
}
