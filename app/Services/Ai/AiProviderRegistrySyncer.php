<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Support\Ai\SupportedProviderRegistry;

class AiProviderRegistrySyncer
{
    public function __construct(private readonly SupportedProviderRegistry $registry) {}

    public function sync(): void
    {
        foreach ($this->registry->providers() as $definition) {
            $provider = AiProvider::query()->firstOrCreate(
                ['driver' => $definition['driver']],
                [
                    'name' => $definition['display_name'],
                    'is_active' => false,
                ]
            );

            $provider->fill([
                'name' => $provider->name ?: $definition['display_name'],
                'display_name' => $definition['display_name'],
                'configuration_reference' => 'Admin encrypted credential',
                'capabilities_json' => $definition['capabilities'],
                'settings_json' => array_replace_recursive([
                    'default_models' => $definition['default_models'],
                ], $provider->settings_json ?? []),
                'default_timeout_seconds' => $provider->default_timeout_seconds ?: $definition['default_timeout_seconds'],
                'default_max_retries' => $provider->default_max_retries ?: $definition['default_max_retries'],
                'supports_text_to_image' => in_array('text_to_image', $definition['capabilities'], true),
                'supports_image_to_image' => in_array('image_to_image', $definition['capabilities'], true),
                'supports_editing' => in_array('image_editing', $definition['capabilities'], true),
                'supports_upscaling' => in_array('upscaling', $definition['capabilities'], true),
            ])->save();

            foreach ($definition['models'] as $modelDefinition) {
                $model = AiModel::query()->firstOrNew([
                    'ai_provider_id' => $provider->id,
                    'code' => $modelDefinition['code'],
                ]);

                $model->fill([
                    'display_name' => $model->display_name ?: $modelDefinition['display_name'],
                    'capability' => $model->capability ?: $modelDefinition['capability'],
                    'generation_capabilities_json' => $modelDefinition['capabilities'],
                    'estimated_cost_per_output' => $model->estimated_cost_per_output ?: $modelDefinition['estimated_cost_amount'],
                    'estimated_cost_type' => $model->estimated_cost_type ?: 'estimated',
                    'estimated_cost_amount' => $model->estimated_cost_amount ?: $modelDefinition['estimated_cost_amount'],
                    'estimated_cost_currency' => $model->estimated_cost_currency ?: $modelDefinition['estimated_cost_currency'],
                    'cost_unit' => $model->cost_unit ?: $modelDefinition['cost_unit'],
                    'configuration_json' => array_replace_recursive($model->configuration_json ?? [], $modelDefinition['configuration'] ?? []),
                    'notes' => $model->notes ?: $modelDefinition['notes'],
                    'sort_order' => $model->sort_order ?: $modelDefinition['sort_order'],
                    'is_active' => $model->exists ? $model->is_active : true,
                ])->save();
            }
        }
    }
}
