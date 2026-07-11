<?php

namespace App\Support\Ai;

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
                        'capabilities' => ['scene_generation', 'cover_generation', 'premium_retry', 'image_editing'],
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
                    'text_to_image',
                    'image_to_image',
                    'image_editing',
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
                    'character_sheet' => 'gpt-image-2',
                    'scene_generation' => 'gpt-image-2',
                    'cover_generation' => 'gpt-image-2',
                    'premium_retry' => 'gpt-image-2',
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
                    'gpt-image-2' => [
                        'code' => 'gpt-image-2',
                        'display_name' => 'GPT Image 2',
                        'capability' => 'scene_generation',
                        'capabilities' => ['character_sheet', 'scene_generation', 'cover_generation', 'premium_retry', 'image_editing'],
                        'estimated_cost_amount' => '0.0410',
                        'estimated_cost_currency' => 'USD',
                        'cost_unit' => 'per_image_medium',
                        'configuration' => [
                            'endpoint' => 'images',
                            'requires_image_url' => true,
                            'supports_multiple_references' => true,
                            'supports_text_to_image_only' => false,
                            'supports_image_editing' => true,
                            // GPT Image 2 supports high-fidelity inputs intrinsically, but the
                            // Images Edits endpoint does not accept input_fidelity for every account.
                            'supports_high_input_fidelity' => false,
                            'scene_size' => '1536x1024',
                            'portrait_size' => '1024x1536',
                            'quality' => 'medium',
                            'quality_costs' => [
                                'medium' => '0.0410',
                                'high' => '0.1650',
                            ],
                        ],
                        'notes' => 'OpenAI image generation/editing option for child reference, scene, and cover pilots. Medium quality estimated price is used for cost preview.',
                        'sort_order' => 30,
                    ],
                    'gpt-image-1' => [
                        'code' => 'gpt-image-1',
                        'display_name' => 'GPT Image 1',
                        'capability' => 'scene_generation',
                        'capabilities' => ['character_sheet', 'scene_generation', 'cover_generation', 'premium_retry', 'image_editing'],
                        'estimated_cost_amount' => '0.0630',
                        'estimated_cost_currency' => 'USD',
                        'cost_unit' => 'per_image',
                        'configuration' => [
                            'endpoint' => 'images',
                            'requires_image_url' => true,
                            'supports_multiple_references' => true,
                            'supports_text_to_image_only' => false,
                            'supports_image_editing' => true,
                            'supports_high_input_fidelity' => true,
                            'scene_size' => '1536x1024',
                            'portrait_size' => '1024x1536',
                            'quality' => 'medium',
                            'quality_costs' => [
                                'medium' => '0.0630',
                                'high' => '0.2500',
                            ],
                        ],
                        'notes' => 'Fallback OpenAI image model option when GPT Image 2 is unavailable on the account.',
                        'sort_order' => 40,
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
        return $this->providers()[$driver]['models'][$code] ?? null;
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
