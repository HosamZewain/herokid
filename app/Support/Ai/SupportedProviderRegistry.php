<?php

namespace App\Support\Ai;

use Illuminate\Support\Arr;

class SupportedProviderRegistry
{
    public const DEFAULT_CAPABILITIES = [
        'character_sheet',
        'scene_generation',
        'cover_generation',
        'premium_retry',
        'image_editing',
        'text_to_json',
        'vision_to_text',
        'image_analysis',
        'structured_json_generation',
        'scene_extraction',
        'prompt_enhancement',
    ];

    public function providers(): array
    {
        return [
            'fal' => [
                'driver' => 'fal',
                'display_name' => 'fal.ai',
                'documentation_label' => 'fal.ai API',
                'credential_type' => 'api_key',
                'credential_label' => 'FAL API Key',
                'capabilities' => ['text_to_image', 'image_to_image', 'image_editing', 'upscaling'],
                'default_timeout_seconds' => 180,
                'default_max_retries' => 2,
                'default_models' => [
                    'character_sheet' => 'fal-ai/flux-kontext/dev',
                    'scene_generation' => 'fal-ai/flux-kontext/dev',
                    'cover_generation' => 'fal-ai/flux-pro/kontext',
                    'premium_retry' => 'fal-ai/flux-pro/kontext',
                ],
                'models' => [
                    'fal-ai/flux-kontext/dev' => [
                        'code' => 'fal-ai/flux-kontext/dev',
                        'display_name' => 'FLUX Kontext Dev',
                        'capability' => 'scene_generation',
                        'capabilities' => ['character_sheet', 'scene_generation', 'image_editing'],
                        'estimated_cost_amount' => '0.0300',
                        'estimated_cost_currency' => 'USD',
                        'cost_unit' => 'per_image',
                        'configuration' => [
                            'requires_image_url' => true,
                            'supports_multiple_references' => false,
                            'supports_text_to_image_only' => false,
                            'supports_image_editing' => true,
                        ],
                        'notes' => 'Normal scene generation, character reference sheets, and retries.',
                        'sort_order' => 10,
                    ],
                    'fal-ai/flux-pro/kontext' => [
                        'code' => 'fal-ai/flux-pro/kontext',
                        'display_name' => 'FLUX Kontext Pro',
                        'capability' => 'cover_generation',
                        'capabilities' => ['cover_generation', 'premium_retry', 'image_editing'],
                        'estimated_cost_amount' => '0.0800',
                        'estimated_cost_currency' => 'USD',
                        'cost_unit' => 'per_image',
                        'configuration' => [
                            'requires_image_url' => true,
                            'supports_multiple_references' => false,
                            'supports_text_to_image_only' => false,
                            'supports_image_editing' => true,
                        ],
                        'notes' => 'Premium cover generation, difficult retries, and high-priority scenes.',
                        'sort_order' => 20,
                    ],
                ],
            ],
            'openai' => [
                'driver' => 'openai',
                'display_name' => 'OpenAI',
                'documentation_label' => 'OpenAI API',
                'credential_type' => 'api_key',
                'credential_label' => 'OpenAI API Key',
                'capabilities' => [
                    'text_to_json',
                    'vision_to_text',
                    'image_analysis',
                    'structured_json_generation',
                    'scene_extraction',
                    'prompt_enhancement',
                ],
                'default_timeout_seconds' => 60,
                'default_max_retries' => 1,
                'default_models' => [
                    'vision_to_text' => 'gpt-4.1-mini',
                    'text_to_json' => 'gpt-4.1-mini',
                    'prompt_enhancement' => 'gpt-4.1-mini',
                    'scene_extraction' => 'gpt-4.1-mini',
                    'image_analysis' => 'gpt-4.1-mini',
                    'structured_json_generation' => 'gpt-4.1-mini',
                ],
                'models' => [
                    'gpt-4.1-mini' => [
                        'code' => 'gpt-4.1-mini',
                        'display_name' => 'GPT-4.1 mini',
                        'capability' => 'vision_to_text',
                        'capabilities' => [
                            'vision_to_text',
                            'text_to_json',
                            'image_analysis',
                            'structured_json_generation',
                            'scene_extraction',
                            'prompt_enhancement',
                        ],
                        'estimated_cost_amount' => '0.0100',
                        'estimated_cost_currency' => 'USD',
                        'cost_unit' => 'per_request',
                        'configuration' => [
                            'endpoint' => 'responses',
                            'structured_json' => true,
                            'supports_image_input' => true,
                            'supports_final_image_generation' => false,
                        ],
                        'notes' => 'Text and vision analysis only. Not used for final image generation.',
                        'sort_order' => 10,
                    ],
                ],
            ],
        ];
    }

    public function provider(string $driver): ?array
    {
        return $this->providers()[$driver] ?? null;
    }

    public function supportsProvider(string $driver): bool
    {
        return isset($this->providers()[$driver]);
    }

    public function model(string $driver, string $code): ?array
    {
        return Arr::get($this->providers(), "{$driver}.models.{$code}");
    }

    public function supportsModel(string $driver, string $code): bool
    {
        return $this->model($driver, $code) !== null;
    }

    public function modelSupportsCapability(string $driver, string $code, string $capability): bool
    {
        $model = $this->model($driver, $code);

        return $model && in_array($capability, $model['capabilities'] ?? [], true);
    }
}
