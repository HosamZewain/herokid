<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Story;
use App\Models\StoryCategory;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Story::with('categories')
            ->withCount(['orders', 'views']);

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

        $sortableColumns = [
            'id' => 'stories.id',
            'title' => 'title',
            'age_range' => 'age_range',
            'gender' => 'gender',
            'language' => 'language',
            'price' => 'price',
            'orders' => 'orders_count',
            'views' => 'views_count',
            'status' => 'active',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];
        $sort = array_key_exists((string) $request->input('sort'), $sortableColumns)
            ? (string) $request->input('sort')
            : 'created_at';
        $direction = strtolower((string) $request->input('direction')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortableColumns[$sort], $direction)
            ->orderBy('stories.id', 'desc');

        $stories = $query->paginate(15)->withQueryString();
        $stats = [
            'total' => Story::count(),
            'active' => Story::where('active', true)->count(),
            'inactive' => Story::where('active', false)->count(),
            'orders' => Order::count(),
        ];

        return view('admin.stories.index', compact('stories', 'stats', 'sort', 'direction'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $categories = StoryCategory::orderBy('name')->get();

        return view('admin.stories.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:stories',
            'short_desc' => 'nullable|string',
            'full_desc' => 'nullable|string',
            'age_range' => 'nullable|string|max:255',
            'language' => 'required|string|in:ar,en',
            'lesson_value' => 'nullable|string|max:255',
            'gender' => 'required|in:both,boy,girl',
            'price' => 'required|numeric|min:0',
            'active' => 'boolean',
            'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'full_story' => 'nullable|string',
            'prompt' => 'nullable|string',
        ]);

        if ($request->hasFile('cover_image')) {
            $validated['cover_image'] = $request->file('cover_image')->store('stories', 'public');
        }

        $story = Story::create($validated);
        $story->categories()->sync($request->input('category_ids', []));

        AdminActivityLogger::log(
            action: 'story.created',
            description: 'إضافة قصة جديدة: '.$story->title,
            subject: $story,
            properties: [
                'story' => $story->only(['id', 'title', 'slug', 'language', 'age_range', 'gender', 'price', 'active']),
                'category_ids' => $request->input('category_ids', []),
            ],
            request: $request,
        );

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
    public function edit(Story $story)
    {
        $categories = StoryCategory::orderBy('name')->get();
        $story->load('attachments');

        return view('admin.stories.edit', compact('story', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Story $story)
    {
        $before = $story->only([
            'title', 'slug', 'short_desc', 'full_desc', 'age_range', 'language',
            'lesson_value', 'gender', 'price', 'active', 'cover_image', 'full_story', 'prompt',
        ]);
        $beforeCategories = $story->categories()->pluck('story_categories.id')->all();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:stories,slug,'.$story->id,
            'short_desc' => 'nullable|string',
            'full_desc' => 'nullable|string',
            'age_range' => 'nullable|string|max:255',
            'language' => 'required|string|in:ar,en',
            'lesson_value' => 'nullable|string|max:255',
            'gender' => 'required|in:both,boy,girl',
            'price' => 'required|numeric|min:0',
            'active' => 'boolean',
            'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'full_story' => 'nullable|string',
            'prompt' => 'nullable|string',
        ]);

        if ($request->hasFile('cover_image')) {
            // Delete old image if exists
            if ($story->cover_image && Storage::disk('public')->exists($story->cover_image)) {
                Storage::disk('public')->delete($story->cover_image);
            }
            $validated['cover_image'] = $request->file('cover_image')->store('stories', 'public');
        }

        // Handle checkbox since unchecked is not sent in payload
        $validated['active'] = $request->has('active');

        $story->update($validated);
        $story->categories()->sync($request->input('category_ids', []));
        $story->refresh();

        $after = $story->only(array_keys($before));
        $afterCategories = $story->categories()->pluck('story_categories.id')->all();

        AdminActivityLogger::log(
            action: 'story.updated',
            description: 'تحديث قصة: '.$story->title,
            subject: $story,
            properties: [
                'changes' => AdminActivityLogger::changedValues($before, $after),
                'categories' => [
                    'old' => $beforeCategories,
                    'new' => $afterCategories,
                ],
            ],
            request: $request,
        );

        return redirect()->route('admin.stories.index')->with('success', 'تم تحديث القصة بنجاح!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Story $story)
    {
        $storyDetails = $story->only(['id', 'title', 'slug', 'language', 'age_range', 'gender', 'price', 'active']);

        if ($story->cover_image && Storage::disk('public')->exists($story->cover_image)) {
            Storage::disk('public')->delete($story->cover_image);
        }
        $story->delete();

        AdminActivityLogger::log(
            action: 'story.deleted',
            description: 'حذف قصة: '.($storyDetails['title'] ?? '#'.$storyDetails['id']),
            subject: $story,
            properties: [
                'story' => $storyDetails,
            ],
            request: request(),
        );

        return redirect()->route('admin.stories.index')->with('success', 'تم حذف القصة بنجاح!');
    }
}
