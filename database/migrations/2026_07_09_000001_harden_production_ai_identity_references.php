<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_character_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('production_character_profiles', 'primary_face_reference_index')) {
                $table->unsignedInteger('primary_face_reference_index')->nullable()->after('approved_reference_photos');
            }

            if (! Schema::hasColumn('production_character_profiles', 'body_reference_index')) {
                $table->unsignedInteger('body_reference_index')->nullable()->after('primary_face_reference_index');
            }

            if (! Schema::hasColumn('production_character_profiles', 'style_reference_index')) {
                $table->unsignedInteger('style_reference_index')->nullable()->after('body_reference_index');
            }
        });

        if (Schema::hasTable('ai_models')) {
            foreach ([
                'fal-ai/flux-kontext/dev' => [
                    'requires_image_url' => true,
                    'supports_multiple_references' => false,
                    'supports_text_to_image_only' => false,
                    'supports_image_editing' => true,
                ],
                'fal-ai/flux-pro/kontext' => [
                    'requires_image_url' => true,
                    'supports_multiple_references' => false,
                    'supports_text_to_image_only' => false,
                    'supports_image_editing' => true,
                ],
            ] as $code => $configuration) {
                DB::table('ai_models')
                    ->where('code', $code)
                    ->update([
                        'configuration_json' => json_encode($configuration),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['primary_face_reference_index', 'body_reference_index', 'style_reference_index'] as $column) {
            if (Schema::hasColumn('production_character_profiles', $column)) {
                Schema::table('production_character_profiles', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
