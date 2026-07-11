<?php

namespace App\Services\ProductionStudio;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ProductionAutomationRun;
use App\Models\ProductionProject;
use App\Services\Ai\AiProviderAvailability;
use App\Support\ProductionAutomation;
use Illuminate\Support\Facades\Storage;

class ProductionAutomationPreflightService
{
    public function __construct(private readonly AiProviderAvailability $availability) {}

    public function inspect(ProductionProject $project, array $options = []): array
    {
        $project->loadMissing(['order.story', 'characterProfile', 'scenes']);
        $blockers = [];
        $warnings = [];

        if (! ProductionAutomation::enabled()) {
            $blockers[] = 'Production Studio automation is disabled.';
        }

        if (ProductionAutomationRun::query()->where('active_project_id', $project->id)->exists()) {
            $blockers[] = 'This Production Studio project already has an active automation run.';
        }

        if (config('queue.default') !== 'database') {
            $blockers[] = 'Automation requires the database queue connection for Hostinger shared hosting.';
        }

        if (! $this->privateStorageWritable()) {
            $blockers[] = 'Private local storage is not writable.';
        }

        if (! $project->order) {
            $blockers[] = 'Project is missing its source order.';
        }

        if (! $project->order?->story) {
            $blockers[] = 'Order does not have a selected story.';
        }

        if (blank($project->order?->story?->full_story) && blank($project->order?->story?->full_desc) && blank($project->order?->story?->short_desc)) {
            $blockers[] = 'Selected story does not contain enough story text.';
        }

        $photos = $this->availablePrivatePhotos($project);
        $photoCount = count($photos);
        if ($photoCount === 0) {
            $blockers[] = 'No private child photos are available for identity analysis.';
        }

        $sceneConcurrency = max(1, min(
            5,
            (int) ($options['scene_concurrency'] ?? config('production_studio.automation.scene_concurrency', 2))
        ));

        $generationModel = $this->modelFromOption($options['generation_model_code'] ?? null)
            ?: $this->availability->activeModelsForCapability('character_sheet')->first();
        $coverModel = $this->modelFromOption($options['cover_model_code'] ?? null)
            ?: $this->availability->activeModelsForCapability('cover_generation')->first()
            ?: $generationModel;
        $premiumModel = $this->modelFromOption($options['premium_model_code'] ?? null)
            ?: $this->availability->activeModelsForCapability('premium_retry')->first()
            ?: $coverModel
            ?: $generationModel;
        $validationModel = $this->modelFromOption($options['validation_model_code'] ?? null)
            ?: $this->openAiDefaultModel('vision_to_text');
        $sceneTextModel = $this->modelFromOption($options['scene_text_model_code'] ?? null)
            ?: $this->openAiDefaultModel('scene_extraction')
            ?: $validationModel;

        if (! $generationModel) {
            $blockers[] = 'No configured image generation model is available for child reference generation.';
        }

        if (! $validationModel) {
            $blockers[] = 'No configured OpenAI vision model is available for independent validation.';
        }

        if (! $sceneTextModel) {
            $blockers[] = 'No configured OpenAI text model is available for story scene preparation.';
        }

        if ($generationModel && $validationModel && $generationModel->provider?->driver === $validationModel->provider?->driver && $generationModel->code === $validationModel->code) {
            $warnings[] = 'Generation and validation are configured to the same provider/model; use a separate validator when available.';
        }

        $imageOutputs = 1;
        $textValidationRequests = 3;
        $generationCost = $generationModel ? (float) $generationModel->estimatedCost() : 0.0;
        $coverCost = $coverModel ? (float) $coverModel->estimatedCost() : $generationCost;
        $premiumCost = $premiumModel ? (float) $premiumModel->estimatedCost() : max($generationCost, $coverCost);
        $textCost = $validationModel ? (float) $validationModel->estimatedCost() : 0.0;
        $storyTextCost = $sceneTextModel ? (float) $sceneTextModel->estimatedCost() : (float) config('production_studio.automation.phase2.story_text_cost_fallback', '0.0100');
        $visionCost = $validationModel ? (float) $validationModel->estimatedCost() : (float) config('production_studio.automation.phase2.vision_analysis_cost_fallback', '0.0100');
        $validationCost = $validationModel ? (float) $validationModel->estimatedCost() : (float) config('production_studio.automation.phase2.identity_validation_cost_fallback', '0.0100');
        $baseEstimate = $storyTextCost + $visionCost + $generationCost + $validationCost;
        $retryExposure = ($generationCost + $premiumCost) * max(0, (int) config('production_studio.automation.phase2.child_reference_attempt_limit', 3) - 1);
        $referencePhotoIndices = array_values(array_map('intval', array_keys($photos)));
        $hardBudget = array_key_exists('hard_budget', $options) && $options['hard_budget'] !== null
            ? (float) $options['hard_budget']
            : null;

        if ($hardBudget !== null && $hardBudget + 0.00001 < $baseEstimate) {
            $blockers[] = 'Hard budget is below the Phase 2 base estimate.';
        }

        foreach ([$generationModel, $premiumModel, $validationModel, $sceneTextModel] as $model) {
            if ($model && in_array($model->estimated_cost_type, ['unknown', 'unpriced'], true)) {
                $warnings[] = "Pricing for {$model->code} is marked {$model->estimated_cost_type}; unknown billing exposure will be tracked.";
            }
        }

        return [
            'ok' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'photo_count' => $photoCount,
            'scene_concurrency' => $sceneConcurrency,
            'reference_photo_indices' => $referencePhotoIndices,
            'child_photo_fingerprints' => array_values($photos),
            'models' => [
                'generation' => $this->modelSnapshot($generationModel),
                'cover' => $this->modelSnapshot($coverModel),
                'premium_fallback' => $this->modelSnapshot($premiumModel),
                'validation' => $this->modelSnapshot($validationModel),
                'scene_text' => $this->modelSnapshot($sceneTextModel),
            ],
            'base_estimated_cost' => $this->money($baseEstimate),
            'retry_exposure_estimate' => $this->money($retryExposure),
            'required_minimum_budget' => $this->money($baseEstimate),
            'currency' => $generationModel?->estimated_cost_currency
                ?: $validationModel?->estimated_cost_currency
                ?: 'USD',
            'pricing_snapshot' => [
                'image_outputs' => $imageOutputs,
                'text_validation_requests' => $textValidationRequests,
                'generation_cost' => $this->money($generationCost),
                'cover_cost' => $this->money($coverCost),
                'premium_cost' => $this->money($premiumCost),
                'text_cost' => $this->money($textCost),
                'phase2_story_text_cost' => $this->money($storyTextCost),
                'phase2_vision_analysis_cost' => $this->money($visionCost),
                'phase2_reference_generation_cost' => $this->money($generationCost),
                'phase2_identity_validation_cost' => $this->money($validationCost),
                'max_retries' => (int) config('production_studio.automation.max_retries', 2),
            ],
            'options_snapshot' => [
                'generation_model_code' => $generationModel?->code,
                'cover_model_code' => $coverModel?->code,
                'premium_model_code' => $premiumModel?->code,
                'validation_model_code' => $validationModel?->code,
                'scene_text_model_code' => $sceneTextModel?->code,
                'style_preset' => $options['style_preset'] ?? config('production_studio.automation.default_style_preset', 'premium_storybook'),
                'generation_quality' => $options['generation_quality'] ?? config('production_studio.automation.default_generation_quality', 'high'),
                'scene_concurrency' => $sceneConcurrency,
                'max_retries' => (int) config('production_studio.automation.max_retries', 2),
                'phase2_child_reference_attempt_limit' => (int) config('production_studio.automation.phase2.child_reference_attempt_limit', 3),
                'identity_pass_threshold' => (int) config('production_studio.automation.identity_pass_threshold', 85),
                'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
                'fingerprint_version' => config('production_studio.automation.fingerprint_version', 'automation-fingerprint-v1'),
                'prompt_template_version' => config('production_studio.automation.prompt_template_version', 'production-prompt-v1'),
                'child_photo_fingerprints' => array_values($photos),
                'reference_photo_indices' => $referencePhotoIndices,
                'story_template' => [
                    'id' => $project->order?->story?->id,
                    'content_hash' => $this->storyTemplateHash($project),
                ],
            ],
        ];
    }

    private function availablePrivatePhotos(ProductionProject $project): array
    {
        return collect($project->order?->uploaded_photos ?? [])
            ->mapWithKeys(function ($path, int $index): array {
                if (! is_string($path) || str_contains($path, '..')) {
                    return [];
                }

                if (Storage::disk('local')->exists($path)) {
                    return [$index => [
                        'index' => $index,
                        'path_hash' => hash('sha256', $path),
                        'content_hash' => hash('sha256', Storage::disk('local')->get($path)),
                    ]];
                }

                $legacyPath = storage_path('app/'.ltrim($path, '/'));
                if (is_file($legacyPath)) {
                    return [$index => [
                        'index' => $index,
                        'path_hash' => hash('sha256', $path),
                        'content_hash' => hash_file('sha256', $legacyPath),
                    ]];
                }

                return [];
            })
            ->all();
    }

    private function privateStorageWritable(): bool
    {
        try {
            $path = 'production-studio/automation-preflight-'.uniqid('', true).'.tmp';
            Storage::disk('local')->put($path, 'ok');
            Storage::disk('local')->delete($path);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function modelFromOption(?string $code): ?AiModel
    {
        if (! $code) {
            return null;
        }

        $model = AiModel::query()->with('provider')->where('code', $code)->where('is_active', true)->first();

        return $model && $model->provider?->is_active ? $model : null;
    }

    private function openAiDefaultModel(string $capability): ?AiModel
    {
        $provider = AiProvider::query()->where('driver', 'openai')->where('is_active', true)->first();

        return $provider ? $this->availability->defaultModelFor($provider, $capability) : null;
    }

    private function modelSnapshot(?AiModel $model): ?array
    {
        if (! $model) {
            return null;
        }

        $model->loadMissing('provider');

        return [
            'provider' => $model->provider?->driver,
            'provider_name' => $model->provider?->display_name ?? $model->provider?->name,
            'model' => $model->code,
            'display_name' => $model->display_name,
            'estimated_cost' => $this->money($model->estimatedCost()),
            'cost_source' => $model->estimated_cost_type ?: 'estimated',
            'currency' => $model->estimated_cost_currency ?: 'USD',
        ];
    }

    private function storyTemplateHash(ProductionProject $project): ?string
    {
        $story = $project->order?->story;

        return $story ? hash('sha256', json_encode([
            'id' => $story->id,
            'title' => $story->title,
            'age_range' => $story->age_range,
            'full_story' => $story->full_story,
            'full_desc' => $story->full_desc,
            'short_desc' => $story->short_desc,
            'lesson_value' => $story->lesson_value,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : null;
    }

    private function money(float $amount): string
    {
        return number_format(max(0, $amount), 4, '.', '');
    }
}
