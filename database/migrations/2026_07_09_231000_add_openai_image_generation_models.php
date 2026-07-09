<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_providers') || ! Schema::hasTable('ai_models')) {
            return;
        }

        $now = now();
        $provider = DB::table('ai_providers')->where('driver', 'openai')->first();

        if (! $provider) {
            return;
        }

        $capabilities = collect(json_decode($provider->capabilities_json ?: '[]', true))
            ->merge(['text_to_image', 'image_to_image', 'image_editing'])
            ->unique()
            ->values()
            ->all();

        $settings = json_decode($provider->settings_json ?: '{}', true) ?: [];
        $settings['default_models'] = array_merge($settings['default_models'] ?? [], [
            'character_sheet' => $settings['default_models']['character_sheet'] ?? 'gpt-image-2',
            'scene_generation' => $settings['default_models']['scene_generation'] ?? 'gpt-image-2',
            'cover_generation' => $settings['default_models']['cover_generation'] ?? 'gpt-image-2',
            'premium_retry' => $settings['default_models']['premium_retry'] ?? 'gpt-image-2',
        ]);

        DB::table('ai_providers')
            ->where('id', $provider->id)
            ->update([
                'capabilities_json' => json_encode($capabilities),
                'settings_json' => json_encode($settings),
                'supports_text_to_image' => true,
                'supports_image_to_image' => true,
                'supports_editing' => true,
                'updated_at' => $now,
            ]);

        foreach ($this->models() as $model) {
            DB::table('ai_models')->updateOrInsert(
                ['ai_provider_id' => $provider->id, 'code' => $model['code']],
                [
                    'display_name' => $model['display_name'],
                    'capability' => 'scene_generation',
                    'generation_capabilities_json' => json_encode([
                        'character_sheet',
                        'scene_generation',
                        'cover_generation',
                        'premium_retry',
                        'image_editing',
                    ]),
                    'estimated_cost_per_output' => $model['cost'],
                    'estimated_cost_type' => 'estimated',
                    'estimated_cost_amount' => $model['cost'],
                    'estimated_cost_currency' => 'USD',
                    'cost_unit' => $model['cost_unit'],
                    'configuration_json' => json_encode([
                        'endpoint' => 'images',
                        'requires_image_url' => true,
                        'supports_multiple_references' => true,
                        'supports_text_to_image_only' => false,
                        'supports_image_editing' => true,
                        'scene_size' => '1536x1024',
                        'portrait_size' => '1024x1536',
                        'quality' => 'medium',
                    ]),
                    'notes' => $model['notes'],
                    'sort_order' => $model['sort_order'],
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_providers') || ! Schema::hasTable('ai_models')) {
            return;
        }

        $provider = DB::table('ai_providers')->where('driver', 'openai')->first();

        if (! $provider) {
            return;
        }

        DB::table('ai_models')
            ->where('ai_provider_id', $provider->id)
            ->whereIn('code', ['gpt-image-2', 'gpt-image-1'])
            ->delete();

        $settings = json_decode($provider->settings_json ?: '{}', true) ?: [];
        foreach (['character_sheet', 'scene_generation', 'cover_generation', 'premium_retry'] as $capability) {
            unset($settings['default_models'][$capability]);
        }

        DB::table('ai_providers')
            ->where('id', $provider->id)
            ->update([
                'settings_json' => json_encode($settings),
                'updated_at' => now(),
            ]);
    }

    private function models(): array
    {
        return [
            [
                'code' => 'gpt-image-2',
                'display_name' => 'GPT Image 2',
                'cost' => '0.0410',
                'cost_unit' => 'per_medium_image',
                'notes' => 'OpenAI image generation/editing option. Uses medium-quality estimate for 1536x1024 scenes and 1024x1536 portraits.',
                'sort_order' => 30,
            ],
            [
                'code' => 'gpt-image-1',
                'display_name' => 'GPT Image 1',
                'cost' => '0.0630',
                'cost_unit' => 'per_image',
                'notes' => 'Fallback OpenAI image model option when GPT Image 2 is not available on the account.',
                'sort_order' => 40,
            ],
        ];
    }
};
