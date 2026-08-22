<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\Story;
use App\Services\Pricing\StoryPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PricingPackageController extends Controller
{
    public function index()
    {
        $packages = PricingPackage::with(['items.product', 'items.variant'])->ordered()->get();

        return view('admin.pricing.index', compact('packages'));
    }

    public function create(StoryPricingService $storyPricing)
    {
        return view('admin.pricing.create', $this->formData($storyPricing));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['features'] = $this->parseFeatures($request->input('features_raw', ''));
        $data['image_path'] = $this->storeImage($request);
        DB::transaction(function () use ($request, $data): void {
            $package = PricingPackage::create($data);
            $this->syncProducts($package, $request->input('products', []));
        });

        return redirect()->route('admin.pricing.index')->with('success', 'تم إضافة الباقة بنجاح.');
    }

    public function edit(PricingPackage $pricing, StoryPricingService $storyPricing)
    {
        $pricing->load(['items.product', 'items.variant']);

        return view('admin.pricing.edit', array_merge(
            ['package' => $pricing],
            $this->formData($storyPricing),
        ));
    }

    public function update(Request $request, PricingPackage $pricing)
    {
        $data = $this->validated($request);
        $data['features'] = $this->parseFeatures($request->input('features_raw', ''));
        $oldImagePath = $pricing->image_path;
        if ($request->boolean('remove_image')) {
            $data['image_path'] = null;
        }
        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($request);
        }
        DB::transaction(function () use ($request, $pricing, $data): void {
            $pricing->update($data);
            $this->syncProducts($pricing, $request->input('products', []));
        });

        if (array_key_exists('image_path', $data) && $oldImagePath !== $data['image_path']) {
            $this->deleteUploadedImage($oldImagePath);
        }

        return redirect()->route('admin.pricing.index')->with('success', 'تم تحديث الباقة بنجاح.');
    }

    public function destroy(PricingPackage $pricing)
    {
        $pricing->delete();

        return redirect()->route('admin.pricing.index')->with('success', 'تم حذف الباقة.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|numeric|min:0',
            'regular_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:20',
            'is_featured' => 'boolean',
            'badge' => 'nullable|string|max:100',
            'button_text' => 'nullable|string|max:100',
            'sort_order' => 'integer|min:0',
            'active' => 'boolean',
            'story_count' => 'required|integer|min:0|max:10',
            'show_in_store' => 'boolean',
            'show_on_homepage' => 'boolean',
            'products' => 'nullable|array',
            'products.*.quantity' => 'nullable|integer|min:0|max:50',
            'products.*.variant_id' => 'nullable|integer|exists:product_variants,id',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'remove_image' => 'nullable|boolean',
        ]) + [
            'is_featured' => false,
            'active' => false,
            'show_in_store' => false,
            'show_on_homepage' => false,
        ];

        unset($data['products'], $data['image'], $data['remove_image']);

        $data['slug'] = $this->uniqueSlug($request->input('name'), $request->route('pricing'));

        $hasProduct = collect($request->input('products', []))->contains(fn ($item) => (int) ($item['quantity'] ?? 0) > 0);
        if ((int) $data['story_count'] === 0 && ! $hasProduct) {
            abort(422, 'يجب أن تحتوي الباقة على قصة أو منتج واحد على الأقل.');
        }

        return $data;
    }

    private function parseFeatures(?string $raw): array
    {
        if (blank($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $raw))
        ));
    }

    private function formData(StoryPricingService $storyPricing): array
    {
        $products = Product::query()->with(['category', 'activeVariants'])->where('is_active', true)->orderBy('name_ar')->get();
        $referenceStory = Story::query()->where('active', true)->first();

        return [
            'products' => $products,
            'storyPriceCents' => $referenceStory ? (int) round($storyPricing->effectivePrice($referenceStory) * 100) : 0,
        ];
    }

    private function syncProducts(PricingPackage $package, array $products): void
    {
        $package->items()->delete();
        $sort = 0;

        foreach ($products as $productId => $selection) {
            $quantity = (int) ($selection['quantity'] ?? 0);
            if ($quantity < 1) {
                continue;
            }

            $product = Product::with('activeVariants')->where('is_active', true)->findOrFail($productId);
            $variantId = ! empty($selection['variant_id']) ? (int) $selection['variant_id'] : null;
            if ($variantId && ! $product->activeVariants->contains('id', $variantId)) {
                abort(422, 'النوع المختار لا يتبع المنتج المحدد.');
            }

            if ($product->isPersonalizedAddon() && $package->story_count < 1) {
                abort(422, 'المنتج «'.$product->name_ar.'» يحتاج أن تحتوي الباقة على قصة واحدة على الأقل.');
            }

            $package->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'quantity' => $quantity,
                'sort_order' => $sort++,
            ]);
        }
    }

    private function uniqueSlug(string $name, ?PricingPackage $ignore = null): string
    {
        $base = Str::slug($name) ?: 'package';
        $slug = $base;
        $suffix = 2;
        while (PricingPackage::where('slug', $slug)->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function storeImage(Request $request): ?string
    {
        return $request->hasFile('image')
            ? $request->file('image')->store('packages', 'public')
            : null;
    }

    private function deleteUploadedImage(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'images/') && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }
}
