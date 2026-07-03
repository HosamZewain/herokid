<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductVariantController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $product->variants()->create($this->validatedData($request));

        return back()->with('success', 'تم إضافة المتغير.');
    }

    public function update(Request $request, ProductVariant $variant)
    {
        $variant->update($this->validatedData($request, $variant));

        return back()->with('success', 'تم تحديث المتغير.');
    }

    public function destroy(ProductVariant $variant)
    {
        $variant->delete();

        return back()->with('success', 'تم حذف المتغير.');
    }

    private function validatedData(Request $request, ?ProductVariant $variant = null): array
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'sku' => 'nullable|string|max:255',
            'image' => 'nullable|image|max:4096',
            'price_adjustment' => 'nullable|numeric|min:-999999|max:999999',
            'price_override' => 'nullable|numeric|min:0|max:999999',
            'stock_quantity' => 'nullable|integer|min:0|max:999999',
            'sort_order' => 'nullable|integer|min:0|max:999999',
            'attributes_text' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['price_adjustment_cents'] = (int) round(((float) ($validated['price_adjustment'] ?? 0)) * 100);
        $priceOverride = $validated['price_override'] ?? null;
        $validated['price_override_cents'] = $priceOverride !== null && $priceOverride !== ''
            ? (int) round(((float) $priceOverride) * 100)
            : null;
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['attributes'] = collect(preg_split('/\R/u', (string) ($validated['attributes_text'] ?? '')))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();

        unset($validated['price_adjustment'], $validated['price_override'], $validated['attributes_text']);

        if ($request->hasFile('image')) {
            if ($variant?->image) {
                Storage::disk('public')->delete($variant->image);
            }

            $validated['image'] = $request->file('image')->store('store/products/variants', 'public');
        } else {
            unset($validated['image']);
        }

        return $validated;
    }
}
