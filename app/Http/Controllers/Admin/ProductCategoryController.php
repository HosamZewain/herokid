<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductCategoryController extends Controller
{
    public function index()
    {
        $categories = ProductCategory::withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->paginate(20);

        return view('admin.store.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('admin.store.categories.form', ['category' => new ProductCategory]);
    }

    public function store(Request $request)
    {
        ProductCategory::create($this->validatedData($request));

        return redirect()->route('admin.product-categories.index')->with('success', 'تم إنشاء تصنيف المنتج.');
    }

    public function edit(ProductCategory $productCategory)
    {
        return view('admin.store.categories.form', ['category' => $productCategory]);
    }

    public function update(Request $request, ProductCategory $productCategory)
    {
        $productCategory->update($this->validatedData($request, $productCategory));

        return redirect()->route('admin.product-categories.index')->with('success', 'تم تحديث التصنيف.');
    }

    public function destroy(ProductCategory $productCategory)
    {
        if ($productCategory->products()->where('is_active', true)->exists()) {
            return back()->with('error', 'لا يمكن حذف تصنيف يحتوي على منتجات نشطة.');
        }

        $productCategory->delete();

        return back()->with('success', 'تم حذف التصنيف.');
    }

    private function validatedData(Request $request, ?ProductCategory $category = null): array
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'slug' => 'nullable|string|max:255|unique:product_categories,slug,'.($category?->id ?? 'NULL'),
            'short_description_ar' => 'nullable|string|max:1000',
            'short_description_en' => 'nullable|string|max:1000',
            'cover_image' => 'nullable|image|max:4096',
            'icon' => 'nullable|string|max:255',
            'visual_accent' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0|max:999999',
            'is_active' => 'nullable|boolean',
            'show_in_store' => 'nullable|boolean',
        ]);

        $validated['slug'] = $validated['slug'] ?: Str::slug($validated['name_en'] ?: $validated['name_ar']);
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['show_in_store'] = $request->boolean('show_in_store');

        if ($request->hasFile('cover_image')) {
            if ($category?->cover_image) {
                Storage::disk('public')->delete($category->cover_image);
            }

            $validated['cover_image'] = $request->file('cover_image')->store('store/categories', 'public');
        } else {
            unset($validated['cover_image']);
        }

        return $validated;
    }
}
