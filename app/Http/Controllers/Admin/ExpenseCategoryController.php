<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExpenseCategoryController extends Controller
{
    public function index(): View
    {
        return view('admin.expenses.categories', [
            'categories' => ExpenseCategory::query()
                ->withCount('transactions')
                ->orderBy('type')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ]);
        $baseSlug = Str::slug($data['name']) ?: 'category-'.Str::lower(Str::random(8));
        $slug = $baseSlug;
        $suffix = 2;
        while (ExpenseCategory::query()->where('type', $data['type'])->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        $category = ExpenseCategory::create($data + ['slug' => $slug, 'is_active' => true]);
        AdminActivityLogger::log(
            action: 'expenses.category.created',
            description: 'تم إنشاء تصنيف مالي: '.$category->name,
            subject: $category,
            properties: ['type' => $category->type],
            admin: $request->user(),
        );

        return back()->with('success', 'تمت إضافة التصنيف.');
    }

    public function update(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $before = $category->only(['name', 'description', 'sort_order', 'is_active']);
        $category->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $request->boolean('is_active'),
        ]);
        AdminActivityLogger::log(
            action: 'expenses.category.updated',
            description: 'تم تحديث التصنيف المالي: '.$category->name,
            subject: $category,
            properties: ['changes' => AdminActivityLogger::changedValues($before, $category->only(array_keys($before)))],
            admin: $request->user(),
        );

        return back()->with('success', 'تم تحديث التصنيف.');
    }
}
