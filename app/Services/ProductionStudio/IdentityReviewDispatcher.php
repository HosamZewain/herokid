<?php

namespace App\Services\ProductionStudio;

use App\Jobs\ProcessStructuredAiJob;
use App\Models\AiProvider;
use App\Models\ProductionAutomationCostEntry;
use App\Models\ProductionProjectAsset;
use App\Models\SceneGenerationJob;
use App\Services\Ai\AiProviderAvailability;
use App\Support\ProductionAutomation;
use App\Support\ProductionStudio;
use RuntimeException;

class IdentityReviewDispatcher
{
    public function __construct(
        private readonly AiProviderAvailability $availability,
        private readonly ProductionAutomationCostLedger $ledger,
    ) {}

    public function dispatchFor(ProductionProjectAsset $asset): ?SceneGenerationJob
    {
        if (! in_array($asset->asset_type, ['scene_image', 'cover_image', 'character_sheet'], true)) {
            return null;
        }

        $asset->loadMissing(['project.characterProfile', 'project.order', 'automationRun', 'automationStep', 'automationAttempt']);
        $primaryIndex = $asset->project->characterProfile?->primaryFaceReferenceIndex();
        $provider = AiProvider::query()->where('driver', 'openai')->where('is_active', true)->first();
        $model = $provider ? $this->availability->defaultModelFor($provider, 'vision_to_text') : null;

        if ($primaryIndex === null || ! $model || ! $this->availability->modelAvailable($model, 'vision_to_text')) {
            return null;
        }

        $existing = $asset->project->generationJobs()
            ->where('job_type', 'identity_review')
            ->latest('id')
            ->get()
            ->first(fn (SceneGenerationJob $job): bool => (int) data_get($job->input_assets_json, 'asset_id') === $asset->id);

        if ($existing && in_array($existing->status, ['queued', 'processing', 'completed'], true)) {
            return $existing;
        }

        $costEntry = $this->reserveAutomationValidationCost($asset, $model);

        $job = $asset->project->generationJobs()->create([
            'production_scene_id' => $asset->production_scene_id,
            'ai_provider_id' => $model->ai_provider_id,
            'ai_model_id' => $model->id,
            'job_type' => 'identity_review',
            'generation_mode' => 'vision_to_text',
            'input_assets_json' => [
                'asset_id' => $asset->id,
                'asset_type' => $asset->asset_type,
                'review_type' => match ($asset->asset_type) {
                    'cover_image' => 'cover',
                    'scene_image' => 'scene',
                    default => 'identity',
                },
                'primary_face_reference_index' => $primaryIndex,
            ],
            'provider_request_json' => [
                'provider_driver' => 'openai',
                'model_code' => $model->code,
                'capability' => 'vision_to_text',
                'contains_private_images' => true,
                'automation_cost_entry_id' => $costEntry?->id,
            ],
            'estimated_cost' => $model->estimatedCost(),
            'cost_source' => 'estimated',
            'status' => 'queued',
            'initiated_by_user_id' => $asset->uploaded_by_user_id,
            'production_automation_run_id' => $asset->production_automation_run_id,
            'production_automation_step_id' => $asset->production_automation_step_id,
            'production_automation_attempt_id' => $asset->production_automation_attempt_id,
            'input_fingerprint' => $asset->input_fingerprint,
            'output_fingerprint' => $asset->output_fingerprint,
            'run_version' => $asset->automationRun?->version,
            'orchestration_generation' => $asset->automationRun?->orchestration_generation,
            'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
        ]);

        $metadata = $asset->metadata_json ?? [];
        $metadata['identity_review'] = [
            'status' => 'queued',
            'job_id' => $job->id,
        ];
        $asset->update(['metadata_json' => $metadata]);

        ProductionStudio::log($asset->project, 'ai_identity_review.queued', 'تمت إضافة مراجعة اتساق هوية للصورة المولدة.', [
            'asset_id' => $asset->id,
            'job_id' => $job->id,
        ], $job->initiator);

        $dispatch = ProcessStructuredAiJob::dispatch($job->id);
        if (! app()->environment('testing')) {
            $dispatch->onConnection('database');
        }

        return $job;
    }

    private function reserveAutomationValidationCost(ProductionProjectAsset $asset, $model): ?ProductionAutomationCostEntry
    {
        if (! $asset->production_automation_run_id || ! $asset->automationRun || ! $asset->automationStep || ! $asset->automationAttempt) {
            return null;
        }

        if ($asset->automationRun->status !== ProductionAutomation::STATUS_RUNNING) {
            throw new RuntimeException('Automation run is not active for identity validation.');
        }

        $validationType = match ($asset->asset_type) {
            'cover_image' => 'cover_validation',
            'scene_image' => 'scene_validation',
            default => 'identity_validation',
        };
        $fallbackCost = match ($asset->asset_type) {
            'cover_image' => config('production_studio.automation.phase3.cover_validation_cost_fallback', '0.0100'),
            'scene_image' => config('production_studio.automation.phase3.scene_validation_cost_fallback', '0.0100'),
            default => config('production_studio.automation.phase2.identity_validation_cost_fallback', '0.0100'),
        };

        return $this->ledger->reserve(
            $asset->automationRun,
            $asset->automationStep,
            $asset->automationAttempt,
            'openai',
            $model->code,
            $model->estimatedCost() ?: $fallbackCost,
            [
                'type' => $validationType,
                'model' => $model->code,
                'source' => $model->estimated_cost_type ?: 'estimated',
            ],
            'automation:'.$asset->production_automation_attempt_id.':'.$validationType
        );
    }
}
