<?php

namespace App\Services\ProductionStudio;

use App\Jobs\ProcessStructuredAiJob;
use App\Models\AiProvider;
use App\Models\ProductionProjectAsset;
use App\Models\SceneGenerationJob;
use App\Services\Ai\AiProviderAvailability;
use App\Support\ProductionStudio;

class IdentityReviewDispatcher
{
    public function __construct(private readonly AiProviderAvailability $availability) {}

    public function dispatchFor(ProductionProjectAsset $asset): ?SceneGenerationJob
    {
        if ($asset->asset_type !== 'scene_image') {
            return null;
        }

        $asset->loadMissing(['project.characterProfile', 'project.order']);
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

        $job = $asset->project->generationJobs()->create([
            'production_scene_id' => $asset->production_scene_id,
            'ai_provider_id' => $model->ai_provider_id,
            'ai_model_id' => $model->id,
            'job_type' => 'identity_review',
            'generation_mode' => 'vision_to_text',
            'input_assets_json' => [
                'asset_id' => $asset->id,
                'primary_face_reference_index' => $primaryIndex,
            ],
            'provider_request_json' => [
                'provider_driver' => 'openai',
                'model_code' => $model->code,
                'capability' => 'vision_to_text',
                'contains_private_images' => true,
            ],
            'estimated_cost' => $model->estimatedCost(),
            'cost_source' => 'estimated',
            'status' => 'queued',
            'initiated_by_user_id' => $asset->uploaded_by_user_id,
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
}
