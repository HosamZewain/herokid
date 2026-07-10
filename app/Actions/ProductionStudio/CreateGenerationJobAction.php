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
        $project->loadMissing(['order', 'characterProfile', 'assets']);

        $capability = $this->capabilityFor($data['generation_mode']);
        $providerModel = $this->resolveModel($data['model_code'] ?? null, $capability);
        $provider = $this->providers->imageProvider($providerModel->provider->driver);

        if (! $this->availability->modelAvailable($providerModel, $capability)) {
            throw new RuntimeException('AI generation is not configured yet.');
        }

        $this->validateProfileReady($project);
        $this->validateSceneReady($scene, $data);

        $characterSheet = $this->resolveCharacterReference($project, $data);
        $referencePhotoIndices = $this->referencePhotoIndicesForJob($project, $data, $characterSheet);
        $characterSheetFirst = ($data['generation_mode'] ?? null) !== 'character_scene';
        $resolvedInputs = $this->inputAssets->resolveWithMetadata($project, $referencePhotoIndices, $characterSheet, $characterSheetFirst);
        $inputAssets = $resolvedInputs['assets'];

        if ($providerModel->requiresImageUrl() && $inputAssets === []) {
            throw new RuntimeException('هذا الموديل يحتاج صورة مرجعية. اختر صورة مرجعية أو صورة شخصية معتمدة أولًا.');
        }

        $compiled = $this->compiler->compile(
            project: $project,
            scene: $scene,
            jobType: $data['job_type'],
            stylePreset: $data['style_preset'] ?? 'premium_storybook',
            manualNotes: $data['prompt_notes'] ?? null,
            characterSheet: $characterSheet,
        );

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
                'reference_assets' => $resolvedInputs['metadata'],
                'character_sheet_first' => $characterSheetFirst,
                'primary_face_reference_index' => $project->characterProfile?->primaryFaceReferenceIndex(),
                'body_reference_index' => $project->characterProfile?->bodyReferenceIndex(),
                'style_reference_index' => $project->characterProfile?->styleReferenceIndex(),
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
                    'requires_image_url' => $providerModel->requiresImageUrl(),
                    'supports_multiple_references' => $providerModel->supportsMultipleReferences(),
                    'supports_text_to_image_only' => $providerModel->supportsTextToImageOnly(),
                    'supports_image_editing' => $providerModel->supportsImageEditing(),
                    'quality' => data_get($providerModel->configuration_json, 'quality'),
                    'scene_size' => data_get($providerModel->configuration_json, 'scene_size'),
                    'portrait_size' => data_get($providerModel->configuration_json, 'portrait_size'),
                ],
                'provider_settings' => [
                    'timeout' => $providerModel->provider->default_timeout_seconds,
                    'max_retries' => $providerModel->provider->default_max_retries,
                ],
                'style_preset' => $request->options['style_preset'],
                'personalization_debug' => $compiled['personalization_debug'] ?? null,
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
            ->where('asset_type', 'character_sheet')
            ->where('status', 'approved')
            ->firstOrFail();
    }

    private function validateProfileReady(ProductionProject $project): void
    {
        $profile = $project->characterProfile;

        if (! $profile || ! $profile->isReadyForAiGeneration()) {
            $missing = $profile?->missingAiGenerationFields() ?? ['character_profile' => 'ملف الشخصية'];

            throw new RuntimeException('أكمل ملف الشخصية واختر صور مرجعية واضحة قبل التوليد. ناقص: '.implode('، ', $missing));
        }
    }

    private function validateSceneReady(?ProductionScene $scene, array $data): void
    {
        if (! $scene || ($data['generation_mode'] ?? null) !== 'character_scene') {
            return;
        }

        $scene->loadMissing('project.order');

        if (! $scene->isPersonalizedForImageGeneration()) {
            $templateHero = $scene->template_hero_name ?: $scene->project?->template_hero_name;
            $conflicts = $scene->oldHeroConflicts($templateHero);
            $details = $conflicts !== []
                ? ' اسم بطل القالب ما زال موجودًا في: '.implode('، ', $conflicts).'.'
                : ' يجب أن يشير نص المشهد والتوجيه البصري أو وضع الطفل إلى طفل الطلب بصفته البطل.';

            throw new RuntimeException('خصّص المشهد باسم الطفل قبل توليد الصورة.'.$details);
        }

        if (blank($scene->story_text)) {
            throw new RuntimeException('أضف نص المشهد قبل توليد الصورة.');
        }

        if (blank($scene->visual_direction) && ! (bool) ($data['confirm_missing_visual_direction'] ?? false)) {
            throw new RuntimeException('أضف التوجيه البصري للمشهد قبل التوليد.');
        }

        if (blank($scene->child_action_pose) && ! (bool) ($data['confirm_missing_child_action_pose'] ?? false)) {
            throw new RuntimeException('أضف حركة أو وضع الطفل قبل توليد الصورة.');
        }

        if (blank($scene->project?->order?->story?->title) && blank($scene->project?->order?->story?->short_desc)) {
            throw new RuntimeException('أضف سياق القصة قبل توليد صورة المشهد.');
        }
    }

    private function resolveCharacterReference(ProductionProject $project, array $data): ?ProductionProjectAsset
    {
        if (isset($data['character_sheet_id']) && filled($data['character_sheet_id'])) {
            return $this->resolveAsset($project, (int) $data['character_sheet_id']);
        }

        if (($data['generation_mode'] ?? null) === 'cover_generation') {
            $asset = $project->assets()
                ->where('asset_type', 'character_sheet')
                ->where('status', 'approved')
                ->where('is_primary', true)
                ->first();

            if ($asset) {
                return $asset;
            }

            if (! (bool) ($data['confirm_primary_face_cover_fallback'] ?? false)) {
                throw new RuntimeException('يفضل اعتماد صورة مرجعية للطفل قبل توليد الغلاف.');
            }

            return null;
        }

        if (($data['generation_mode'] ?? null) === 'character_scene') {
            $asset = $project->assets()
                ->where('asset_type', 'character_sheet')
                ->where('status', 'approved')
                ->where('is_primary', true)
                ->first();

            if (! $asset) {
                throw new RuntimeException('اعتمد الصورة المرجعية للطفل قبل توليد المشاهد.');
            }

            return $asset;
        }

        return null;
    }

    private function referencePhotoIndicesForJob(ProductionProject $project, array $data, ?ProductionProjectAsset $characterSheet): array
    {
        $profile = $project->characterProfile;
        $requested = $data['reference_photo_indices'] ?? [];
        $indices = array_values(array_unique(array_map('intval', is_array($requested) ? $requested : [])));
        $primary = $profile?->primaryFaceReferenceIndex();

        if ($primary !== null && ! in_array($primary, $indices, true)) {
            array_unshift($indices, $primary);
        }

        foreach ([$profile?->bodyReferenceIndex(), $profile?->styleReferenceIndex()] as $optionalIndex) {
            if ($optionalIndex !== null && in_array($optionalIndex, $profile->approvedReferenceIndices(), true) && ! in_array($optionalIndex, $indices, true)) {
                $indices[] = $optionalIndex;
            }
        }

        if (($data['generation_mode'] ?? null) === 'cover_generation' && $characterSheet && $primary !== null && ! in_array($primary, $indices, true)) {
            $indices[] = $primary;
        }

        return $this->approvedReferencePhotoIndices($project, $indices);
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
