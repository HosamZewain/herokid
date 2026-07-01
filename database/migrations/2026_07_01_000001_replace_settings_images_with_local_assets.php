<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (\App\Support\SiteImages::settingsDefaults() as $key => $value) {
            $exists = DB::table('settings')->where('key', $key)->exists();

            if ($exists) {
                DB::table('settings')
                    ->where('key', $key)
                    ->update(['value' => $value, 'updated_at' => now()]);
            } else {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Cache::forget('site_settings');
    }

    public function down(): void
    {
        Cache::forget('site_settings');
    }
};
