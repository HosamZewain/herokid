<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $defaults = [
            'home_section_hero_enabled' => '1',
            'home_section_child_identity_enabled' => '1',
            'home_section_stories_enabled' => '1',
            'home_section_store_enabled' => '1',
            'home_section_how_it_works_enabled' => '1',
            'home_section_benefits_enabled' => '1',
            'home_section_testimonials_enabled' => '0',
            'home_section_pricing_enabled' => '1',
            'home_section_faq_enabled' => '1',
            'home_section_contact_enabled' => '1',
            'home_section_final_cta_enabled' => '1',
            'home_child_identity_title' => 'اصنع هوية طفلك قبل اختيار القصة',
            'home_child_identity_subtitle' => 'ارفع صور طفلك مرة واحدة، واحصل على هوية بصرية جاهزة لتختار بعدها القصة المناسبة له.',
            'home_child_identity_cta' => 'ابدأ مجانًا',
        ];

        foreach ($defaults as $key => $value) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Cache::forget('site_settings');
    }

    public function down(): void
    {
        $keys = collect(config('homepage.sections', []))
            ->pluck('setting')
            ->merge([
                'home_child_identity_title',
                'home_child_identity_subtitle',
                'home_child_identity_cta',
            ]);

        DB::table('settings')->whereIn('key', $keys)->delete();
        Cache::forget('site_settings');
    }
};
