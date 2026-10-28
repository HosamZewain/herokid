<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoboDeskFeedCache
{
    public static function invalidate(): void
    {
        Cache::forever('robodesk:revision', (string) Str::uuid());
    }

    public static function key(string $feed, int $page): string
    {
        // sync()/detach() on these pivots do not dispatch model events.
        $relations = [
            DB::table('pricing_package_story')->orderBy('pricing_package_id')->orderBy('story_id')->get(['pricing_package_id', 'story_id'])->toArray(),
            DB::table('story_story_category')->orderBy('story_id')->orderBy('story_category_id')->get(['story_id', 'story_category_id'])->toArray(),
        ];

        return 'robodesk:'.$feed.':'.$page.':'.Cache::get('robodesk:revision', 'initial').':'.hash('sha256', json_encode($relations));
    }
}
