<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUG = 'football-stories';

    public function up(): void
    {
        $categoryId = DB::table('story_categories')->where('slug', self::SLUG)->value('id');

        if ($categoryId) {
            DB::table('story_categories')->where('id', $categoryId)->update([
                'name' => 'قصص كرة القدم',
                'updated_at' => now(),
            ]);
        } else {
            $categoryId = DB::table('story_categories')->insertGetId([
                'name' => 'قصص كرة القدم',
                'slug' => self::SLUG,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! $categoryId) {
            return;
        }

        DB::table('stories')
            ->select(['id', 'slug', 'title'])
            ->where(function ($query): void {
                $query->whereRaw('LOWER(slug) LIKE ?', ['%football%'])
                    ->orWhere('title', 'like', '%كرة القدم%')
                    ->orWhere('title', 'like', '%محمد صلاح%')
                    ->orWhere('title', 'like', '%هالاند%');
            })
            ->orderBy('id')
            ->get()
            ->each(function (object $story) use ($categoryId): void {
                DB::table('story_story_category')->insertOrIgnore([
                    'story_id' => $story->id,
                    'story_category_id' => $categoryId,
                ]);
            });
    }

    public function down(): void
    {
        $categoryId = DB::table('story_categories')->where('slug', self::SLUG)->value('id');

        if (! $categoryId) {
            return;
        }

        DB::table('story_story_category')->where('story_category_id', $categoryId)->delete();
        DB::table('story_categories')->where('id', $categoryId)->delete();
    }
};
