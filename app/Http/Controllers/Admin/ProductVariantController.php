<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MobileCartItem;
use App\Models\PricingPackageProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VisitorCartItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductVariantController extends Controller
{
    public function store(Request $request, Product $product)
    {
        [$data, $newFiles] = $this->preparedData($request);

        try {
            $product->variants()->create($data);
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($newFiles);

            throw $exception;
        }

        return back()->with('success', 'تم إضافة المتغير.');
    }

    public function update(Request $request, ProductVariant $variant)
    {
        [$data, $newFiles, $obsoleteFiles] = $this->preparedData($request, $variant);

        try {
            $variant->update($data);
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($newFiles);

            throw $exception;
        }

        Storage::disk('public')->delete($obsoleteFiles);

        return back()->with('success', 'تم تحديث المتغير.');
    }

    public function destroy(ProductVariant $variant)
    {
        $isReferenced = $variant->orderItems()->exists()
            || PricingPackageProduct::query()->where('product_variant_id', $variant->id)->exists()
            || MobileCartItem::query()->where('product_variant_id', $variant->id)->exists()
            || VisitorCartItem::query()->where('product_variant_id', $variant->id)->exists();

        if ($isReferenced) {
            return back()->with('error', 'لا يمكن حذف متغير مستخدم في طلب أو باقة أو سلة. عطّل المتغير للحفاظ على بيانات العملاء والطلبات السابقة.');
        }

        $files = array_values(array_filter([
            $variant->image,
            ...($variant->gallery_images ?? []),
        ]));
        $variant->delete();
        Storage::disk('public')->delete($files);

        return back()->with('success', 'تم حذف المتغير.');
    }

    /** @return array{0: array<string, mixed>, 1: array<int, string>, 2: array<int, string>} */
    private function preparedData(Request $request, ?ProductVariant $variant = null): array
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'sku' => 'nullable|string|max:255',
            'image' => 'nullable|image|max:4096',
            'gallery_images' => 'nullable|array|max:8',
            'gallery_images.*' => 'image|max:4096',
            'remove_gallery_images' => 'nullable|array',
            'remove_gallery_images.*' => 'string',
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

        $existingGallery = collect($variant?->gallery_images ?? []);
        $removals = collect($validated['remove_gallery_images'] ?? [])->filter();
        $keptGallery = $existingGallery->reject(fn (string $path) => $removals->contains($path));
        $newGalleryFiles = collect($request->file('gallery_images', []));
        $newFiles = [];
        $obsoleteFiles = $existingGallery->intersect($removals)->values()->all();

        if ($keptGallery->count() + $newGalleryFiles->count() > 8) {
            throw ValidationException::withMessages([
                'gallery_images' => 'يمكن حفظ ٨ صور إضافية كحد أقصى لكل متغير.',
            ]);
        }

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('store/products/variants', 'public');
            $newFiles[] = $validated['image'];
            if ($variant?->image) {
                $obsoleteFiles[] = $variant->image;
            }
        } else {
            unset($validated['image']);
        }

        $newGallery = $newGalleryFiles
            ->map(fn ($image) => $image->store('store/products/variants/gallery', 'public'));
        $newFiles = [...$newFiles, ...$newGallery->all()];
        $validated['gallery_images'] = $keptGallery->merge($newGallery)->values()->all();

        unset($validated['remove_gallery_images']);

        return [$validated, array_values(array_unique($newFiles)), array_values(array_unique($obsoleteFiles))];
    }
}
