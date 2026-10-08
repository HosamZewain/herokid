<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

class AdminProductVariantSelection
{
    /**
     * A new line with exactly one active option has no ambiguous choice.
     * Never guess between options or reinterpret an existing purchase.
     */
    public static function forNewLine(Product $product, bool $existingPurchase = false): ?ProductVariant
    {
        $variants = $product->activeVariants()->lockForUpdate()->limit(2)->get();

        if ($variants->isEmpty()) {
            return null;
        }

        if ($existingPurchase || $variants->count() > 1) {
            throw ValidationException::withMessages([
                'products.'.$product->id.'.variant_id' => 'اختر خيار المنتج '.$product->name_ar.' من قائمة «الخيار» داخل كارت المنتج.',
            ]);
        }

        return $variants->sole();
    }
}
