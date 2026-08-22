<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'home_section_pricing_enabled'],
            [
                'value' => '1',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        Cache::forget('site_settings');
    }

    public function down(): void
    {
        // Keep the administrator's current visibility choice when rolling back.
    }
};
