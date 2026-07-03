<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HomepageStoreSection;
use App\Models\ProductCategory;
use Illuminate\Http\Request;

class HomepageStoreSectionController extends Controller
{
    public function index()
    {
        $sections = HomepageStoreSection::with('category')->orderBy('sort_order')->paginate(20);

        return view('admin.store.homepage-sections.index', compact('sections'));
    }

    public function create()
    {
        return view('admin.store.homepage-sections.form', [
            'section' => new HomepageStoreSection(['max_products' => 4, 'is_active' => true]),
            'categories' => ProductCategory::orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request)
    {
        HomepageStoreSection::create($this->validatedData($request));

        return redirect()->route('admin.homepage-store-sections.index')->with('success', 'تم إنشاء قسم الصفحة الرئيسية.');
    }

    public function edit(HomepageStoreSection $homepageStoreSection)
    {
        return view('admin.store.homepage-sections.form', [
            'section' => $homepageStoreSection,
            'categories' => ProductCategory::orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, HomepageStoreSection $homepageStoreSection)
    {
        $homepageStoreSection->update($this->validatedData($request));

        return redirect()->route('admin.homepage-store-sections.index')->with('success', 'تم تحديث القسم.');
    }

    public function destroy(HomepageStoreSection $homepageStoreSection)
    {
        $homepageStoreSection->delete();

        return back()->with('success', 'تم حذف القسم.');
    }

    private function validatedData(Request $request): array
    {
        $validated = $request->validate([
            'product_category_id' => 'nullable|exists:product_categories,id',
            'title_ar' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'subtitle_ar' => 'nullable|string|max:1000',
            'subtitle_en' => 'nullable|string|max:1000',
            'max_products' => 'required|integer|min:1|max:24',
            'sort_order' => 'nullable|integer|min:0|max:999999',
            'cta_text_ar' => 'nullable|string|max:255',
            'cta_text_en' => 'nullable|string|max:255',
            'cta_url' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        $validated['is_active'] = $request->boolean('is_active');

        return $validated;
    }
}
