<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Catalog\RoboDeskFeed;
use App\Services\Catalog\RoboDeskFeedCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RoboDeskFeedController extends Controller
{
    public function __invoke(Request $request, string $feed)
    {
        abort_if(app()->environment('production') && Setting::where('key', 'robodesk_content_revision')->value('value') !== '2026-10-28-v1', 503, 'Knowledge feeds are being prepared.');
        $validated = $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        $page = (int) ($validated['page'] ?? 1);
        $key = RoboDeskFeedCache::key($feed, $page);
        $result = Cache::remember($key, 900, fn () => app(RoboDeskFeed::class)->{$feed}($page));

        // Server-side caching only: invalidation must not be hidden by a browser/CDN cache.
        return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Cache-Control', 'no-store');
    }
}
