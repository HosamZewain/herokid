<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = \App\Models\Story::with(['categories', 'attachments'])
            ->withCount('orders')
            ->latest();

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($builder) use ($q) {
                $builder->where('title', 'like', "%{$q}%")
                        ->orWhere('slug', 'like', "%{$q}%");
            });
        }

        if ($request->input('status') === 'active') {
            $query->where('active', true);
        } elseif ($request->input('status') === 'inactive') {
            $query->where('active', false);
        }

        if ($request->filled('language')) {
            $query->where('language', $request->input('language'));
        }

        $stories = $query->paginate(15)->withQueryString();
        return view('admin.stories.index', compact('stories'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $categories = \App\Models\StoryCategory::orderBy('name')->get();
        return view('admin.stories.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'slug'         => 'required|string|max:255|unique:stories',
            'short_desc'   => 'nullable|string',
            'full_desc'    => 'nullable|string',
            'age_range'    => 'nullable|string|max:255',
            'language'     => 'required|string|in:ar,en',
            'lesson_value' => 'nullable|string|max:255',
            'gender'       => 'required|in:both,boy,girl',
            'price'        => 'required|numeric|min:0',
            'active'       => 'boolean',
            'cover_image'  => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'full_story'   => 'nullable|string',
            'prompt'       => 'nullable|string',
        ]);

        if ($request->hasFile('cover_image')) {
            $validated['cover_image'] = $request->file('cover_image')->store('stories', 'public');
        }

        $story = \App\Models\Story::create($validated);
        $story->categories()->sync($request->input('category_ids', []));

        return redirect()->route('admin.stories.index')->with('success', 'تم إضافة القصة بنجاح!');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // View single story (Not directly used in basic admin layout but kept for resource compliance)
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(\App\Models\Story $story)
    {
        $categories = \App\Models\StoryCategory::orderBy('name')->get();
        $story->load('attachments');
        return view('admin.stories.edit', compact('story', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, \App\Models\Story $story)
    {
        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'slug'         => 'required|string|max:255|unique:stories,slug,'.$story->id,
            'short_desc'   => 'nullable|string',
            'full_desc'    => 'nullable|string',
            'age_range'    => 'nullable|string|max:255',
            'language'     => 'required|string|in:ar,en',
            'lesson_value' => 'nullable|string|max:255',
            'gender'       => 'required|in:both,boy,girl',
            'price'        => 'required|numeric|min:0',
            'active'       => 'boolean',
            'cover_image'  => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'full_story'   => 'nullable|string',
            'prompt'       => 'nullable|string',
        ]);

        if ($request->hasFile('cover_image')) {
            // Delete old image if exists
            if ($story->cover_image && \Illuminate\Support\Facades\Storage::disk('public')->exists($story->cover_image)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($story->cover_image);
            }
            $validated['cover_image'] = $request->file('cover_image')->store('stories', 'public');
        }

        // Handle checkbox since unchecked is not sent in payload
        $validated['active'] = $request->has('active');

        $story->update($validated);
        $story->categories()->sync($request->input('category_ids', []));

        return redirect()->route('admin.stories.index')->with('success', 'تم تحديث القصة بنجاح!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(\App\Models\Story $story)
    {
        if ($story->cover_image && \Illuminate\Support\Facades\Storage::disk('public')->exists($story->cover_image)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($story->cover_image);
        }
        $story->delete();

        return redirect()->route('admin.stories.index')->with('success', 'تم حذف القصة بنجاح!');
    }
}
