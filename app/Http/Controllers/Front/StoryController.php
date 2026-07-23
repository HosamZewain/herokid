<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\CustomerStoryView;
use App\Models\Story;
use App\Services\Catalog\UnifiedStorefrontService;
use App\Services\Uploads\TemporaryPhotoUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoryController extends Controller
{
    public function index(Request $request, UnifiedStorefrontService $storefront)
    {
        $request->merge(['type' => 'stories']);

        return view('front.shop.index', array_merge(
            $storefront->storefront(
                $request,
                productsEnabled: setting('shop_enabled', '1') === '1',
                defaultPerPage: 24,
            ),
            [
                'currentCategory' => null,
                'isStoriesAlias' => true,
            ],
        ));
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
                'batchToken' => Str::random(48),
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
