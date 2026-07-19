<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([
            'unified_store_title' => 'متجر القصص والمنتجات',
            'unified_store_subtitle' => 'كل قصص HeroKid المخصصة وكتب الأنشطة والهدايا في مكان واحد.',
            'unified_store_default_sort' => 'featured',
        ] as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        DB::table('settings')
            ->where('key', 'seo_shop_title')
            ->where('value', 'متجر HeroKid للأطفال')
            ->update(['value' => 'متجر القصص والمنتجات', 'updated_at' => $now]);
        DB::table('settings')
            ->where('key', 'seo_shop_description')
            ->where('value', 'تسوق كتب أنشطة، قصص جاهزة، وهدايا مخصصة تكمل تجربة قصة طفلك من HeroKid.')
            ->update([
                'value' => 'Browse personalized children’s stories, activity books, coloring books, mazes, posters, and gifts from HeroKid.',
                'updated_at' => $now,
            ]);

        Cache::forget('site_settings');
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'unified_store_title',
            'unified_store_subtitle',
            'unified_store_default_sort',
        ])->delete();

        DB::table('settings')
            ->where('key', 'seo_shop_title')
            ->where('value', 'متجر القصص والمنتجات')
            ->update(['value' => 'متجر HeroKid للأطفال', 'updated_at' => now()]);
        DB::table('settings')
            ->where('key', 'seo_shop_description')
            ->where('value', 'Browse personalized children’s stories, activity books, coloring books, mazes, posters, and gifts from HeroKid.')
            ->update([
                'value' => 'تسوق كتب أنشطة، قصص جاهزة، وهدايا مخصصة تكمل تجربة قصة طفلك من HeroKid.',
                'updated_at' => now(),
            ]);

        Cache::forget('site_settings');
    }
};
