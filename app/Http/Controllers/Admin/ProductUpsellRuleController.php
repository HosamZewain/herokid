<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductUpsellRule;
use App\Models\Story;
use App\Models\StoryCategory;
use Illuminate\Http\Request;

class ProductUpsellRuleController extends Controller
{
    public function index()
    {
        $rules = ProductUpsellRule::with(['targetProduct', 'sourceStory', 'sourceStoryCategory'])
            ->orderByDesc('priority')
            ->paginate(30);

        return view('admin.store.upsell-rules.index', compact('rules'));
    }

    public function create()
    {
        return view('admin.store.upsell-rules.form', $this->formData(new ProductUpsellRule(['trigger_scope' => 'story_added', 'is_active' => true])));
    }

    public function store(Request $request)
    {
        ProductUpsellRule::create($this->validatedData($request));

        return redirect()->route('admin.upsell-rules.index')->with('success', 'تم إنشاء قاعدة الترشيح.');
    }

    public function edit(ProductUpsellRule $upsellRule)
    {
        return view('admin.store.upsell-rules.form', $this->formData($upsellRule));
    }

    public function update(Request $request, ProductUpsellRule $upsellRule)
    {
        $upsellRule->update($this->validatedData($request));

        return redirect()->route('admin.upsell-rules.index')->with('success', 'تم تحديث قاعدة الترشيح.');
    }

    public function destroy(ProductUpsellRule $upsellRule)
    {
        $upsellRule->delete();

        return back()->with('success', 'تم حذف قاعدة الترشيح.');
    }

    private function formData(ProductUpsellRule $rule): array
    {
        return [
            'rule' => $rule,
            'products' => Product::orderBy('name_ar')->get(),
            'stories' => Story::orderBy('title')->get(),
            'storyCategories' => StoryCategory::orderBy('name')->get(),
        ];
    }

    private function validatedData(Request $request): array
    {
        $validated = $request->validate([
            'target_product_id' => 'required|exists:products,id',
            'source_story_id' => 'nullable|exists:stories,id',
            'source_story_category_id' => 'nullable|exists:story_categories,id',
            'age_group' => 'nullable|string|max:50',
            'gender' => 'nullable|in:boy,girl',
            'trigger_scope' => 'required|string|max:50',
            'priority' => 'nullable|integer|min:-999999|max:999999',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['priority'] = (int) ($validated['priority'] ?? 0);
        $validated['is_active'] = $request->boolean('is_active');

        return $validated;
    }
}
