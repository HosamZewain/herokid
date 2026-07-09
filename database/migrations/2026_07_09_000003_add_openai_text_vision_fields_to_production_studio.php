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
            foreach ([
                'face_shape_notes',
                'body_proportion_notes',
                'confidence_notes',
                'reference_photo_recommendations',
                'analysis_warnings',
            ] as $column) {
                if (! Schema::hasColumn('production_character_profiles', $column)) {
                    $table->text($column)->nullable()->after('reviewer_notes');
                }
            }
        });

        Schema::table('production_scenes', function (Blueprint $table) {
            foreach ([
                'environment',
                'mood_lighting',
                'supporting_characters',
                'key_objects',
                'continuity_notes',
            ] as $column) {
                if (! Schema::hasColumn('production_scenes', $column)) {
                    $table->text($column)->nullable()->after('text_safe_area_notes');
                }
            }

            if (! Schema::hasColumn('production_scenes', 'ai_sync_status')) {
                $table->string('ai_sync_status')->nullable()->after('status')->index();
            }
        });

        $this->registerOpenAiProvider();
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_models')) {
            $providerId = DB::table('ai_providers')->where('driver', 'openai')->value('id');

            if ($providerId) {
                DB::table('ai_models')->where('ai_provider_id', $providerId)->where('code', 'gpt-4.1-mini')->delete();
            }
        }

        if (Schema::hasTable('ai_providers')) {
            DB::table('ai_providers')->where('driver', 'openai')->delete();
        }

        Schema::table('production_scenes', function (Blueprint $table) {
            foreach (['environment', 'mood_lighting', 'supporting_characters', 'key_objects', 'continuity_notes', 'ai_sync_status'] as $column) {
                if (Schema::hasColumn('production_scenes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('production_character_profiles', function (Blueprint $table) {
            foreach (['face_shape_notes', 'body_proportion_notes', 'confidence_notes', 'reference_photo_recommendations', 'analysis_warnings'] as $column) {
                if (Schema::hasColumn('production_character_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function registerOpenAiProvider(): void
    {
        if (! Schema::hasTable('ai_providers') || ! Schema::hasTable('ai_models')) {
            return;
        }

        $now = now();

        DB::table('ai_providers')->updateOrInsert(
            ['driver' => 'openai'],
            [
                'name' => 'OpenAI',
                'display_name' => 'OpenAI',
                'configuration_reference' => 'Admin encrypted credential',
                'capabilities_json' => json_encode([
                    'text_to_json',
                    'vision_to_text',
                    'image_analysis',
                    'structured_json_generation',
                    'scene_extraction',
                    'prompt_enhancement',
                ]),
                'settings_json' => json_encode([
                    'default_models' => [
                        'vision_to_text' => 'gpt-4.1-mini',
                        'text_to_json' => 'gpt-4.1-mini',
                        'prompt_enhancement' => 'gpt-4.1-mini',
                        'scene_extraction' => 'gpt-4.1-mini',
                        'image_analysis' => 'gpt-4.1-mini',
                        'structured_json_generation' => 'gpt-4.1-mini',
                    ],
                ]),
                'default_timeout_seconds' => 60,
                'default_max_retries' => 1,
                'supports_text_to_image' => false,
                'supports_image_to_image' => false,
                'supports_editing' => false,
                'supports_upscaling' => false,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $providerId = DB::table('ai_providers')->where('driver', 'openai')->value('id');

        DB::table('ai_models')->updateOrInsert(
            ['ai_provider_id' => $providerId, 'code' => 'gpt-4.1-mini'],
            [
                'display_name' => 'GPT-4.1 mini',
                'capability' => 'vision_to_text',
                'generation_capabilities_json' => json_encode([
                    'vision_to_text',
                    'text_to_json',
                    'image_analysis',
                    'structured_json_generation',
                    'scene_extraction',
                    'prompt_enhancement',
                ]),
                'estimated_cost_per_output' => '0.0100',
                'estimated_cost_type' => 'estimated',
                'estimated_cost_amount' => '0.0100',
                'estimated_cost_currency' => 'USD',
                'cost_unit' => 'per_request',
                'configuration_json' => json_encode([
                    'endpoint' => 'responses',
                    'structured_json' => true,
                    'supports_image_input' => true,
                    'supports_final_image_generation' => false,
                ]),
                'notes' => 'Text and vision analysis only. Not used for final image generation.',
                'sort_order' => 10,
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }
};
