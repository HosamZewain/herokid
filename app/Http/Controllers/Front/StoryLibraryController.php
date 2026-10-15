<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\LibraryStory;
use App\Services\Storage\PersistentMediaResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StoryLibraryController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'string', 'max:100'], 'language' => ['nullable', Rule::in(['ar', 'en'])], 'age' => ['nullable', 'integer', 'min:0', 'max:18']]);
        $stories = LibraryStory::published()
            ->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($q) => $q->where('title', 'like', '%'.$value.'%')->orWhere('description', 'like', '%'.$value.'%')))
            ->when($filters['category'] ?? null, fn ($q, $value) => $q->where('category', $value))
            ->when($filters['language'] ?? null, fn ($q, $value) => $q->where('language', $value))
            ->when(isset($filters['age']), fn ($q) => $q->where(fn ($q) => $q->whereNull('age_min')->orWhere('age_min', '<=', $filters['age']))->where(fn ($q) => $q->whereNull('age_max')->orWhere('age_max', '>=', $filters['age'])))
            ->orderBy('sort_order')->latest('id')->paginate(24)->withQueryString();
        $categories = LibraryStory::published()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category');
        if ($request->routeIs('api.story-library.*')) {
            return response()->json(['data' => collect($stories->items())->map(fn ($story) => $this->payload($story)), 'meta' => ['current_page' => $stories->currentPage(), 'last_page' => $stories->lastPage(), 'total' => $stories->total(), 'per_page' => 24], 'filters' => ['categories' => $categories, 'languages' => ['ar', 'en']]])->header('Cache-Control', 'private, no-store');
        }

        return view('front.story-library.index', compact('stories', 'filters', 'categories'));
    }

    private function available(LibraryStory $story): void
    {
        abort_unless($story->status === 'published' && $story->published_at && $story->published_at->lte(now()), 404);
    }

    public function show(Request $request, LibraryStory $story)
    {
        $this->available($story);
        if ($request->routeIs('api.story-library.*')) {
            return response()->json(['data' => $this->payload($story)])->header('Cache-Control', 'private, no-store');
        }

        return view('front.story-library.show', compact('story'));
    }

    public function read(LibraryStory $story)
    {
        $this->available($story);

        return response()->view('front.booklet-previews.show', ['preview' => $story, 'publicLibrary' => true,
            'documentUrl' => route('story-library.document', $story), 'pageCount' => $story->page_count,
            'publicTitle' => $story->title, 'scenesUrl' => route('story-library.show', $story),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function document(LibraryStory $story, PersistentMediaResponse $media)
    {
        $this->available($story);
        abort_unless(Storage::disk($story->disk)->exists($story->pdf_path), 404);

        return $media->inline($story->disk, $story->pdf_path, 'library-story.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }

    public function cover(LibraryStory $story, PersistentMediaResponse $media)
    {
        $this->available($story);
        abort_unless($story->cover_path && Storage::disk($story->disk)->exists($story->cover_path), 404);

        return $media->inline($story->disk, $story->cover_path, null, ['Content-Type' => $story->cover_mime, 'Cache-Control' => 'private, no-store']);
    }

    private function payload(LibraryStory $story): array
    {
        return ['id' => $story->uuid, 'slug' => $story->slug, 'title' => $story->title, 'description' => $story->description,
            'category' => $story->category, 'language' => $story->language, 'reading_direction' => $story->reading_direction,
            'age_min' => $story->age_min, 'age_max' => $story->age_max, 'page_count' => $story->page_count, 'file_size_bytes' => $story->file_size,
            'cover_url' => $story->cover_path ? route('story-library.cover', $story) : asset('images/logo-320.png'),
            'web_url' => route('story-library.show', $story), 'reader_url' => route('story-library.read', $story),
            'document_url' => route('api.story-library.document', $story), 'version' => $story->checksum, 'updated_at' => $story->updated_at->toISOString()];
    }
}
