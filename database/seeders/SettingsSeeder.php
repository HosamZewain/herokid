<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // Business Info
            ['key' => 'site_name',          'value' => 'HeroKid'],
            ['key' => 'site_email',         'value' => 'hello@herokid.eg'],
            ['key' => 'support_email',      'value' => 'support@herokid.eg'],
            ['key' => 'privacy_email',      'value' => 'privacy@herokid.eg'],
            ['key' => 'whatsapp_number',    'value' => '201000000000'],
            ['key' => 'whatsapp_url',       'value' => 'https://wa.me/201000000000'],

            // Pricing
            ['key' => 'price_soft_cover',   'value' => '99'],
            ['key' => 'price_hard_cover',   'value' => '149'],
            ['key' => 'currency_symbol',    'value' => 'ج.م'],

            // Delivery
            ['key' => 'delivery_days_min',  'value' => '7'],
            ['key' => 'delivery_days_max',  'value' => '10'],
            ['key' => 'production_days',    'value' => '3'],

            // Social Media
            ['key' => 'instagram_url',      'value' => 'https://instagram.com/herokid.eg'],
            ['key' => 'facebook_url',       'value' => 'https://facebook.com/herokid.eg'],
            ['key' => 'tiktok_url',         'value' => 'https://tiktok.com/@herokid.eg'],

            // Operational
            ['key' => 'orders_open',        'value' => '1'],
            ['key' => 'maintenance_mode',   'value' => '0'],
            ['key' => 'photo_delete_days',  'value' => '90'],

            // ─── Homepage Hero Images ───────────────────────────────
            ['key' => 'img_hero_main',  'value' => 'https://images.unsplash.com/photo-1446776811953-b23d57bd21aa?w=500&auto=format&fit=crop&q=80'],
            ['key' => 'img_hero_mini1', 'value' => 'https://images.unsplash.com/photo-1448375240586-882707db888b?w=300&auto=format&fit=crop&q=80'],
            ['key' => 'img_hero_mini2', 'value' => 'https://images.unsplash.com/photo-1490750967868-88df5691cc4a?w=300&auto=format&fit=crop&q=80'],

            // ─── Homepage How It Works Steps ────────────────────────
            ['key' => 'img_home_step1', 'value' => 'https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?w=600&auto=format&fit=crop&q=80'],
            ['key' => 'img_home_step2', 'value' => 'https://images.unsplash.com/photo-1503454537195-1dcabb73ffb9?w=600&auto=format&fit=crop&q=80'],
            ['key' => 'img_home_step3', 'value' => 'https://images.unsplash.com/photo-1513885535751-8b9238bd345a?w=600&auto=format&fit=crop&q=80'],

            // ─── Homepage Stats (Why Parents Love It) ───────────────
            ['key' => 'img_stat_books',    'value' => 'https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?w=500&auto=format&fit=crop&q=80'],
            ['key' => 'img_stat_rating',   'value' => 'https://images.unsplash.com/photo-1543269865-cbf427effbad?w=500&auto=format&fit=crop&q=80'],
            ['key' => 'img_stat_family',   'value' => 'https://images.unsplash.com/photo-1511895426328-dc8714191011?w=500&auto=format&fit=crop&q=80'],
            ['key' => 'img_stat_delivery', 'value' => 'https://images.unsplash.com/photo-1619454016518-697bc231e7cb?w=500&auto=format&fit=crop&q=80'],

            // ─── How-It-Works Page Steps ────────────────────────────
            ['key' => 'img_hiw_step1', 'value' => 'https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?w=700&auto=format&fit=crop&q=80'],
            ['key' => 'img_hiw_step2', 'value' => 'https://images.unsplash.com/photo-1503454537195-1dcabb73ffb9?w=700&auto=format&fit=crop&q=80'],
            ['key' => 'img_hiw_step3', 'value' => 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=700&auto=format&fit=crop&q=80'],
            ['key' => 'img_hiw_step4', 'value' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=700&auto=format&fit=crop&q=80'],
            ['key' => 'img_hiw_step5', 'value' => 'https://images.unsplash.com/photo-1513885535751-8b9238bd345a?w=700&auto=format&fit=crop&q=80'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], ['value' => $setting['value']]);
        }
    }
}
