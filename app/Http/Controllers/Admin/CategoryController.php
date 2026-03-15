<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StoryCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = StoryCategory::withCount('stories')->orderBy('name')->get();
        return view('admin.categories.index', compact('categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100|unique:story_categories,name',
        ]);

        StoryCategory::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name, '-'),
        ]);

        return redirect()->route('admin.categories.index')->with('success', 'تم إضافة التصنيف بنجاح!');
    }

    public function destroy(StoryCategory $category)
    {
        $category->stories()->detach();
        $category->delete();
        return redirect()->route('admin.categories.index')->with('success', 'تم حذف التصنيف.');
    }
}
