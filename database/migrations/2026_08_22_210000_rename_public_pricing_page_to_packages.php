<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'seo_pricing_title')
            ->whereIn('value', [
                'أسعار قصص الأطفال المخصصة',
                'أسعار قصص الأطفال المخصصة | HeroKid',
            ])
            ->update([
                'value' => 'باقات قصص الأطفال المخصصة | HeroKid',
                'updated_at' => now(),
            ]);

        Cache::forget('site_settings');
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'seo_pricing_title')
            ->where('value', 'باقات قصص الأطفال المخصصة | HeroKid')
            ->update([
                'value' => 'أسعار قصص الأطفال المخصصة',
                'updated_at' => now(),
            ]);

        Cache::forget('site_settings');
    }
};
