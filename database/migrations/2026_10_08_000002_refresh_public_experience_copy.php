<?php

use App\Support\PublicExperienceCopy;
use App\Support\RequestSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PublicExperienceCopy::DEFAULTS as $key => $value) {
            $row = DB::table('settings')->where('key', $key)->first();
            if (! $row) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($row->value === PublicExperienceCopy::LEGACY[$key]
                || ($key === 'hiw_step1_bullet3' && $row->value === 'لغة كل قصة موضحة قبل الطلب')) {
                // Preserve any custom copy entered by administrators.
                DB::table('settings')->where('id', $row->id)->update(['value' => $value, 'updated_at' => now()]);
            }
        }
        RequestSettings::forget();
    }

    public function down(): void
    {
        // Forward-only content migration: never erase later administrator edits.
    }
};
