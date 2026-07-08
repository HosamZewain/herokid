<?php

namespace App\Actions\ProductionStudio;

use App\DTOs\Ai\GenerationRequest;
use App\Jobs\SubmitAiGenerationJob;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ProductionProject;
use App\Models\ProductionProjectAsset;
use App\Models\ProductionScene;
use App\Models\SceneGenerationJob;
use App\Services\Ai\AiProviderAvailability;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\GenerationInputAssetResolver;
use App\Services\Ai\ProductionPromptCompiler;
use App\Support\ProductionStudio;
use RuntimeException;

class CreateGenerationJobAction
{
    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly AiProviderAvailability $availability,
        private readonly ProductionPromptCompiler $compiler,
        private readonly GenerationInputAssetResolver $inputAssets,
    ) {}

    public function execute(ProductionProject $project, array $data, ?ProductionScene $scene = null): SceneGenerationJob
    {
        $project->loadMissing(['order', 'characterProfile']);

        $capability = $this->capabilityFor($data['generation_mode']);
        $providerModel = $this->resolveModel($data['model_code'] ?? null, $capability);
        $provider = $this->providers->imageProvider($providerModel->provider->driver);

        if (! $this->availability->modelAvailable($providerModel, $capability)) {
            throw new RuntimeException('AI generation is not configured yet.');
        }

        $characterSheet = isset($data['character_sheet_id'])
            ? $this->resolveAsset($project, (int) $data['character_sheet_id'])
            : null;
        $referencePhotoIndices = $this->approvedReferencePhotoIndices($project, $data['reference_photo_indices'] ?? []);

        $compiled = $this->compiler->compile(
            project: $project,
            scene: $scene,
            jobType: $data['job_type'],
            stylePreset: $data['style_preset'] ?? 'premium_storybook',
            manualNotes: $data['prompt_notes'] ?? null,
            characterSheet: $characterSheet,
        );

        $inputAssets = $this->inputAssets->resolve($project, $referencePhotoIndices, $characterSheet);
        $request = new GenerationRequest(
            project: $project,
            scene: $scene,
            model: $providerModel,
            jobType: $data['job_type'],
            generationMode: $data['generation_mode'],
            prompt: $compiled['prompt'],
            negativePrompt: trim(($compiled['negative_prompt'] ?? '')."\n".($data['negative_prompt'] ?? '')),
            inputAssets: $inputAssets,
            options: [
                'style_preset' => $data['style_preset'] ?? 'premium_storybook',
            ],
        );

        $estimate = $provider->estimateCost($request);

        $job = $project->generationJobs()->create([
            'production_scene_id' => $scene?->id,
            'ai_provider_id' => $providerModel->ai_provider_id,
            'ai_model_id' => $providerModel->id,
            'job_type' => $data['job_type'],
            'generation_mode' => $data['generation_mode'],
            'prompt_snapshot' => $request->prompt,
            'negative_prompt_snapshot' => $request->negativePrompt,
            'input_assets_json' => [
                'reference_photo_indices' => $referencePhotoIndices,
                'character_sheet_id' => $characterSheet?->id,
                'input_count' => count($inputAssets),
            ],
            'provider_request_json' => [
                'provider_driver' => $providerModel->provider->driver,
                'provider_display_name' => $providerModel->provider->public_name,
                'model_code' => $providerModel->code,
                'model_display_name' => $providerModel->display_name,
                'model_settings' => [
                    'capabilities' => $providerModel->generation_capabilities_json,
                    'cost_type' => $providerModel->estimated_cost_type,
                    'cost_amount' => $providerModel->estimatedCost(),
                    'cost_currency' => $providerModel->estimated_cost_currency,
                    'cost_unit' => $providerModel->cost_unit,
                ],
                'provider_settings' => [
                    'timeout' => $providerModel->provider->default_timeout_seconds,
                    'max_retries' => $providerModel->provider->default_max_retries,
                ],
                'style_preset' => $request->options['style_preset'],
            ],
            'estimated_cost' => $estimate->amount,
            'cost_source' => $estimate->source,
            'status' => 'queued',
            'initiated_by_user_id' => auth()->id(),
        ]);

        ProductionStudio::log($project, 'ai_generation.queued', 'تم إنشاء مهمة توليد صورة بالذكاء الاصطناعي.', [
            'job_id' => $job->id,
            'job_type' => $job->job_type,
            'generation_mode' => $job->generation_mode,
            'model' => $providerModel->code,
        ], auth()->user());

        SubmitAiGenerationJob::dispatch($job->id);

        return $job;
    }

    private function resolveModel(?string $modelCode, string $capability): AiModel
    {
        if (! $modelCode) {
            $model = AiProvider::query()
                ->where('is_active', true)
                ->with('models')
                ->get()
                ->map(fn ($provider) => $this->availability->defaultModelFor($provider, $capability))
                ->filter()
                ->first();

            if ($model) {
                return $model->load('provider');
            }
        }

        $model = AiModel::query()
            ->with('provider')
            ->where('code', $modelCode)
            ->where('is_active', true)
            ->firstOrFail();

        if (! $this->availability->modelAvailable($model, $capability)) {
            throw new RuntimeException('Selected AI model is not available for this generation type.');
        }

        return $model;
    }

    private function resolveAsset(ProductionProject $project, int $assetId): ProductionProjectAsset
    {
        return $project->assets()
            ->whereKey($assetId)
            ->whereIn('asset_type', ['character_sheet', 'scene_image', 'cover_image'])
            ->firstOrFail();
    }

    private function approvedReferencePhotoIndices(ProductionProject $project, array $requestedIndices): array
    {
        $requestedIndices = array_values(array_unique(array_map('intval', $requestedIndices)));

        if ($requestedIndices === []) {
            return [];
        }

        $approvedIndices = array_values(array_unique(array_map(
            'intval',
            $project->characterProfile?->approved_reference_photos ?? []
        )));

        if (array_diff($requestedIndices, $approvedIndices) !== []) {
            throw new RuntimeException('Selected child reference photo is not approved for Studio generation.');
        }

        return $requestedIndices;
    }

    private function capabilityFor(string $generationMode): string
    {
        return match ($generationMode) {
            'character_sheet' => 'character_sheet',
            'cover_generation' => 'cover_generation',
            'scene_edit' => 'image_editing',
            default => 'scene_generation',
        };
    }
}
