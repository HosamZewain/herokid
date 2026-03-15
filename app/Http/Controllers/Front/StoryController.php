<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Models\StoryCategory;
use Illuminate\Http\Request;

class StoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Story::where('active', true);

        // Eager-load categories if the pivot table exists
        try {
            $query->with('categories');
        } catch (\Exception $e) {
            // pivot table not migrated yet — skip
        }

        // Filter: gender
        if ($request->filled('gender') && in_array($request->gender, ['boy', 'girl', 'both'])) {
            if ($request->gender !== 'both') {
                $query->whereIn('gender', [$request->gender, 'both']);
            }
        }

        // Filter: age
        if ($request->filled('age')) {
            $query->where('age_range', 'like', '%' . $request->age . '%');
        }

        // Filter: language
        if ($request->filled('lang') && in_array($request->lang, ['ar', 'en'])) {
            $query->where('language', $request->lang);
        }

        // Filter: category
        if ($request->filled('category')) {
            try {
                $query->whereHas('categories', fn($q) => $q->where('slug', $request->category));
            } catch (\Exception $e) {
                // pivot table not migrated yet — skip filter
            }
        }

        $stories = $query->latest()->paginate(12)->withQueryString();

        // Sidebar: categories with story counts
        $categories = collect();
        try {
            $categories = StoryCategory::withCount(['stories' => fn($q) => $q->where('active', true)])
                ->having('stories_count', '>', 0)
                ->orderBy('name')
                ->get();
        } catch (\Exception $e) {
            //
        }

        // Sidebar: available age ranges
        $ageRanges = collect();
        try {
            $ageRanges = Story::where('active', true)
                ->whereNotNull('age_range')
                ->where('age_range', '!=', '')
                ->distinct()
                ->orderBy('age_range')
                ->pluck('age_range');
        } catch (\Exception $e) {
            //
        }

        return view('front.stories.index', compact('stories', 'categories', 'ageRanges'));
    }

    public function show($slug)
    {
        $story = Story::where('slug', $slug)->where('active', true)->firstOrFail();
        return view('front.stories.show', compact('story'));
    }
}
