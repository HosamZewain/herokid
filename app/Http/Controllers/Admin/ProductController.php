<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\ProductPersonalizationSchema;
use App\Support\ProductProductionPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with('category')
            ->withCount('views')
            ->selectSub(
                DB::table('order_items')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereColumn('order_items.product_id', 'products.id')
                    ->whereIn('order_items.item_type', ['product', 'product_add_on'])
                    ->whereNull('orders.deleted_at')
                    ->selectRaw('COALESCE(SUM(order_items.quantity), 0)'),
                'sold_quantity'
            )
            ->when($request->filled('category'), fn ($query) => $query->where('product_category_id', $request->category))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->status === 'active'))
            ->latest()
            ->paginate(20)
            ->withQueryString();
        $categories = ProductCategory::orderBy('sort_order')->get();

        return view('admin.store.products.index', compact('products', 'categories'));
    }

    public function create()
    {
        return view('admin.store.products.form', [
            'product' => new Product([
                'fulfillment_type' => 'physical',
                'purchase_mode' => 'standalone',
                'personalization_mode' => 'none',
                'inventory_mode' => 'no_tracking',
            ]),
            'categories' => ProductCategory::orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $product = Product::create($this->validatedData($request));

        return redirect()->route('admin.products.edit', $product)->with('success', 'تم إنشاء المنتج. يمكنك إضافة المتغيرات من نفس الصفحة.');
    }

    public function edit(Product $product)
    {
        $product->load(['variants' => fn ($query) => $query->withSum('orderItems as sold_quantity', 'quantity')]);

        return view('admin.store.products.form', [
            'product' => $product,
            'categories' => ProductCategory::orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $product->update($this->validatedData($request, $product));

        return redirect()->route('admin.products.edit', $product)->with('success', 'تم تحديث المنتج.');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->route('admin.products.index')->with('success', 'تم حذف المنتج.');
    }

    private function validatedData(Request $request, ?Product $product = null): array
    {
        $validated = $request->validate([
            'product_category_id' => 'required|exists:product_categories,id',
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'slug' => 'nullable|string|max:255|unique:products,slug,'.($product?->id ?? 'NULL'),
            'short_description_ar' => 'nullable|string|max:1000',
            'short_description_en' => 'nullable|string|max:1000',
            'description_ar' => 'nullable|string',
            'description_en' => 'nullable|string',
            'featured_image' => 'nullable|image|max:4096',
            'gallery_images.*' => 'nullable|image|max:4096',
            'price' => 'required|numeric|min:0|max:999999',
            'sale_price' => 'nullable|numeric|min:0|max:999999',
            'sku' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0|max:999999',
            'age_groups' => 'nullable|array',
            'age_groups.*' => 'nullable|string|max:50',
            'features_text' => 'nullable|string',
            'fulfillment_type' => 'required|string|in:physical,digital',
            'purchase_mode' => 'required|string|in:standalone,add_on_only,standalone_or_add_on',
            'personalization_mode' => 'required|string|in:none,inherit_from_linked_story,collect_child_details',
            'personalization_fields' => 'nullable|array',
            'personalization_fields.*.enabled' => 'nullable|boolean',
            'personalization_fields.*.required' => 'nullable|boolean',
            'personalization_fields.*.label' => 'nullable|string|max:100',
            'personalization_fields.photos.min_files' => 'nullable|integer|min:1|max:3',
            'personalization_fields.photos.max_files' => 'nullable|integer|min:1|max:3|gte:personalization_fields.photos.min_files',
            'production_prompt_template' => 'nullable|string|max:'.ProductProductionPrompt::MAX_TEMPLATE_LENGTH,
            'inventory_mode' => 'required|string|in:no_tracking,track_stock,made_to_order',
            'stock_quantity' => 'nullable|integer|min:0|max:999999',
            'production_lead_time_days' => 'nullable|integer|min:0|max:365',
            'shipping_notes_ar' => 'nullable|string|max:1000',
            'shipping_notes_en' => 'nullable|string|max:1000',
            'seo_title_ar' => 'nullable|string|max:255',
            'seo_title_en' => 'nullable|string|max:255',
            'seo_description_ar' => 'nullable|string|max:1000',
            'seo_description_en' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
        ]);

        $validated['slug'] = $validated['slug'] ?: Str::slug($validated['name_en'] ?: $validated['name_ar']);
        $validated['price_cents'] = (int) round(((float) $validated['price']) * 100);
        $salePrice = $validated['sale_price'] ?? null;
        $validated['sale_price_cents'] = $salePrice !== null && $salePrice !== ''
            ? (int) round(((float) $salePrice) * 100)
            : null;
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        $validated['production_lead_time_days'] = (int) ($validated['production_lead_time_days'] ?? 0);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['is_featured'] = $request->boolean('is_featured');
        $validated['age_groups'] = collect($validated['age_groups'] ?? [])->filter()->values()->all();
        $validated['features'] = collect(preg_split('/\R/u', (string) ($validated['features_text'] ?? '')))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();
        $validated['personalization_fields'] = ProductPersonalizationSchema::fromAdminInput(
            $validated['personalization_fields'] ?? []
        );

        if ($validated['personalization_mode'] === 'collect_child_details'
            && ProductPersonalizationSchema::enabledFields($validated['personalization_fields']) === []) {
            throw ValidationException::withMessages([
                'personalization_fields' => 'اختر حقل تخصيص واحدًا على الأقل لهذا المنتج.',
            ]);
        }

        $unsupportedPromptVariables = ProductProductionPrompt::unsupportedVariables(
            (string) ($validated['production_prompt_template'] ?? '')
        );
        if ($unsupportedPromptVariables !== []) {
            throw ValidationException::withMessages([
                'production_prompt_template' => 'متغيرات غير مدعومة في برومبت المنتج: '.implode('، ', $unsupportedPromptVariables),
            ]);
        }

        unset($validated['price'], $validated['sale_price'], $validated['features_text']);

        if ($request->hasFile('featured_image')) {
            if ($product?->featured_image) {
                Storage::disk('public')->delete($product->featured_image);
            }

            $validated['featured_image'] = $request->file('featured_image')->store('store/products', 'public');
        } else {
            unset($validated['featured_image']);
        }

        if ($request->hasFile('gallery_images')) {
            $existing = $product?->gallery_images ?? [];
            $newImages = [];

            foreach ($request->file('gallery_images') as $image) {
                $newImages[] = $image->store('store/products/gallery', 'public');
            }

            $validated['gallery_images'] = array_values(array_merge($existing, $newImages));
        } else {
            unset($validated['gallery_images']);
        }

        return $validated;
    }
}
