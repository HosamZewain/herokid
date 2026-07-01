<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\SiteImages;
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
            ['key' => 'delivery_fee',        'value' => '0'],

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

        ];

        foreach (SiteImages::settingsDefaults() as $key => $value) {
            $settings[] = ['key' => $key, 'value' => $value];
        }

        foreach ($settings as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], ['value' => $setting['value']]);
        }
    }
}
