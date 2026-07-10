<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\CustomerStoryView;
use App\Models\Story;
use App\Models\StoryCategory;
use App\Services\Uploads\TemporaryPhotoUploadService;
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
            $query->where('age_range', 'like', '%'.$request->age.'%');
        }

        // Filter: language
        if ($request->filled('lang') && in_array($request->lang, ['ar', 'en'])) {
            $query->where('language', $request->lang);
        }

        // Filter: category
        if ($request->filled('category')) {
            try {
                $query->whereHas('categories', fn ($q) => $q->where('slug', $request->category));
            } catch (\Exception $e) {
                // pivot table not migrated yet — skip filter
            }
        }

        $perPage = in_array((int) $request->input('per_page'), [10, 12, 15, 20, 30]) ? (int) $request->input('per_page') : 20;
        $stories = $query->inRandomOrder()->paginate($perPage)->withQueryString();

        // Sidebar: categories with story counts
        $categories = collect();
        try {
            $categories = StoryCategory::withCount(['stories' => fn ($q) => $q->where('active', true)])
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

    public function show(Request $request, $slug, TemporaryPhotoUploadService $uploads)
    {
        $story = Story::where('slug', $slug)->where('active', true)->firstOrFail();
        $uploadSession = $uploads->ensureSession($request);

        CustomerStoryView::create([
            'user_id' => $request->user()?->id,
            'story_id' => $story->id,
            'session_id' => $request->session()->getId(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'viewed_at' => now(),
        ]);

        return view('front.stories.show', [
            'story' => $story,
            'photoUploadConfig' => [
                'sessionToken' => $uploadSession['token'],
                'sessionUrl' => route('photo-uploads.session'),
                'uploadUrl' => route('photo-uploads.store'),
                'deleteUrlTemplate' => route('photo-uploads.destroy', ['publicId' => '__ID__']),
                'maxFiles' => (int) config('photo_uploads.max_files', 5),
                'maxSizeMb' => (int) config('photo_uploads.max_size_mb', 15),
                'concurrency' => (int) config('photo_uploads.concurrency', 2),
                'maxLongEdge' => (int) config('photo_uploads.max_long_edge', 2560),
                'jpegQuality' => (int) config('photo_uploads.jpeg_quality', 90),
            ],
        ]);
    }
}
