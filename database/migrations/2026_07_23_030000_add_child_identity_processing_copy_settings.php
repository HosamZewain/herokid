<?php

use App\Services\ChildIdentity\ChildIdentitySettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (ChildIdentitySettings::PROCESSING_COPY_DEFAULTS as $key => $value) {
            DB::table('settings')->insertOrIgnore([
                'key' => "child_identity_processing_{$key}",
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget('site_settings');
    }

    public function down(): void
    {
        DB::table('settings')
            ->whereIn(
                'key',
                collect(ChildIdentitySettings::PROCESSING_COPY_DEFAULTS)
                    ->keys()
                    ->map(fn (string $key): string => "child_identity_processing_{$key}")
                    ->all(),
            )
            ->delete();

        Cache::forget('site_settings');
    }
};
