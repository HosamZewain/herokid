<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_models')) {
            return;
        }

        foreach (['fal-ai/flux-kontext/dev', 'fal-ai/flux-pro/kontext'] as $code) {
            $model = DB::table('ai_models')->where('code', $code)->first(['id', 'configuration_json']);

            if (! $model) {
                continue;
            }

            $configuration = json_decode($model->configuration_json ?: '[]', true) ?: [];
            $configuration['requires_image_url'] = true;
            $configuration['supports_multiple_references'] = false;
            $configuration['supports_text_to_image_only'] = false;
            $configuration['supports_image_editing'] = true;

            DB::table('ai_models')
                ->where('id', $model->id)
                ->update([
                    'configuration_json' => json_encode($configuration),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        //
    }
};
