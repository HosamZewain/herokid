<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The homepage "featured stories" grid becomes a unified catalogue section that
 * shows stories and products together behind tabs. Carries the old section
 * toggle over so an admin who had hidden the grid keeps it hidden.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $previous = DB::table('settings')->where('key', 'home_section_stories_enabled')->value('value');

        if (! DB::table('settings')->where('key', 'home_section_catalog_enabled')->exists()) {
            DB::table('settings')->insert([
                'key' => 'home_section_catalog_enabled',
                'value' => $previous ?? '1',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('settings')->where('key', 'home_section_stories_enabled')->delete();

        foreach ([
            'home_catalog_section_title' => 'كل ما يخص طفلك في مكان واحد',
            'home_catalog_section_subtitle' => 'قصص مخصصة، كتب أنشطة، وهدايا — كلها تحمل اسم طفلك ووجهه.',
        ] as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Cache::forget('site_settings');
    }

    public function down(): void
    {
        $now = now();

        $current = DB::table('settings')->where('key', 'home_section_catalog_enabled')->value('value');

        if (! DB::table('settings')->where('key', 'home_section_stories_enabled')->exists()) {
            DB::table('settings')->insert([
                'key' => 'home_section_stories_enabled',
                'value' => $current ?? '1',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('settings')->whereIn('key', [
            'home_section_catalog_enabled',
            'home_catalog_section_title',
            'home_catalog_section_subtitle',
        ])->delete();

        Cache::forget('site_settings');
    }
};
