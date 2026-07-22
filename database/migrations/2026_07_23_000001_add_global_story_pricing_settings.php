<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasTable('stories') || ! DB::table('stories')->exists()) {
            return;
        }

        $now = now();
        $settings = [
            'story_global_price_enabled' => '1',
            'story_regular_price' => '399',
            'story_offer_enabled' => '1',
            'story_offer_price' => '349',
            'story_offer_label' => 'عرض خاص',
        ];

        foreach ($settings as $key => $value) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Preserve administrator-edited pricing values on rollback.
    }
};
