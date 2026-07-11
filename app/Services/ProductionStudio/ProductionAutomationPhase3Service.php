<?php

namespace App\Services\ProductionStudio;

use App\Actions\ProductionStudio\CreateGenerationJobAction;
use App\Jobs\AdvanceProductionAutomationRun;
use App\Jobs\ProcessStructuredAiJob;
use App\Models\AiModel;
use App\Models\ProductionAutomationAttempt;
use App\Models\ProductionAutomationRun;
use App\Models\ProductionAutomationStep;
use App\Models\ProductionProjectAsset;
use App\Models\ProductionScene;
use App\Models\SceneGenerationJob;
use App\Models\User;
use App\Services\Ai\AiProviderAvailability;
use App\Support\ProductionAutomation;
use App\Support\ProductionStudio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ProductionAutomationPhase3Service
{
    public function __construct(
        private readonly ProductionAutomationStateMachine $stateMachine,
        private readonly ProductionAutomationCostLedger $ledger,
        private readonly ProductionAutomationFingerprint $fingerprints,
        private readonly ProductionAutomationVisualValidator $visualValidator,
        private readonly AiProviderAvailability $availability,
        private readonly ScenePersonalizationService $personalizer,
        private readonly CreateGenerationJobAction $generationJobs,
    ) {}

    public function advance(ProductionAutomationRun $run): bool
    {
        $run = $run->fresh([
            'project.order.story',
            'project.storyVersions',
            'project.characterProfile',
            'project.scenes',
            'project.assets.automationAttempt',
            'steps',
            'attempts.step',
        ]);

        if (! $run || $run->status !== ProductionAutomation::STATUS_RUNNING) {
            return true;
        }

        $step = $run->steps
            ->whereNotIn('status', ProductionAutomation::progressCompleteStepStatuses())
            ->whereNotIn('status', [ProductionAutomation::STEP_FAILED, ProductionAutomation::STEP_CANCELLED])
            ->sortBy('sequence')
            ->first();

        if (! $step) {
            return true;
        }

        return match (true) {
            $step->step_key === 'cover' => $this->advanceCover($run, $step),
            str_starts_with($step->step_key, 'scene_') => $this->advanceScenes($run),
            $step->step_key === 'layout_print' => false,
            default => false,
        };
    }

    public function approveAssetManually(ProductionAutomationRun $run, ProductionProjectAsset $asset, User $actor, string $reason): ProductionAutomationRun
    {
        return DB::transaction(function () use ($run, $asset, $actor, $reason): ProductionAutomationRun {
            $run = ProductionAutomationRun::query()->with(['project.scenes', 'project.assets', 'steps'])->lockForUpdate()->findOrFail($run->id);
            $asset = ProductionProjectAsset::query()->with('scene')->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $step = $this->stepForAsset($run, $asset);

            if (! $step || $asset->production_project_id !== $run->production_project_id || ! in_array($asset->asset_type, ['cover_image', 'scene_image'], true)) {
                throw new RuntimeException('Asset does not belong to this Phase 3 automation run.');
            }

            [$inputFingerprint, $outputFingerprint] = $this->fingerprintsForAsset($run, $asset);

            $this->markOnlyFinal($asset);
            $asset->update([
                'status' => 'approved',
                'is_final' => true,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $reason,
                'input_fingerprint' => $inputFingerprint,
                'output_fingerprint' => $asset->output_fingerprint ?: $outputFingerprint,
                'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
            ]);

            $asset->automationAttempt?->update([
                'status' => 'approved',
                'approved_by_user_id' => $actor->id,
                'approval_type' => 'manual',
                'output_fingerprint' => $asset->output_fingerprint ?: $outputFingerprint,
                'completed_at' => now(),
            ]);

            $this->completeStep($step, $inputFingerprint, $asset->output_fingerprint ?: $outputFingerprint, [
                'source' => 'manual_phase3_asset_approval',
                'asset_id' => $asset->id,
                'reason' => $reason,
            ], $actor, 'manual');

            ProductionStudio::log($run->project, 'automation.phase3_asset.manual_approved', 'تم اعتماد مخرج المرحلة الثالثة يدويًا.', [
                'run_id' => $run->id,
                'asset_id' => $asset->id,
                'asset_type' => $asset->asset_type,
                'scene_id' => $asset->production_scene_id,
                'reason' => $reason,
            ], $actor);

            return $this->resumeAfterManualAction($run, $actor, $step);
        });
    }

    public function rejectAssetManually(ProductionAutomationRun $run, ProductionProjectAsset $asset, User $actor, string $reason): ProductionAutomationRun
    {
        return DB::transaction(function () use ($run, $asset, $actor, $reason): ProductionAutomationRun {
            $run = ProductionAutomationRun::query()->with(['project.scenes', 'steps'])->lockForUpdate()->findOrFail($run->id);
            $asset = ProductionProjectAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $step = $this->stepForAsset($run, $asset);

            if (! $step || $asset->production_project_id !== $run->production_project_id || ! in_array($asset->asset_type, ['cover_image', 'scene_image'], true)) {
                throw new RuntimeException('Asset does not belong to this Phase 3 automation run.');
            }

            $asset->update([
                'status' => 'rejected',
                'is_final' => false,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);
            $this->failAttempt($asset->automationAttempt, 'manual_phase3_asset_rejected', $reason, ['source' => 'manual_review']);

            ProductionStudio::log($run->project, 'automation.phase3_asset.manual_rejected', 'تم رفض مخرج المرحلة الثالثة يدويًا.', [
                'run_id' => $run->id,
                'asset_id' => $asset->id,
                'asset_type' => $asset->asset_type,
                'scene_id' => $asset->production_scene_id,
                'reason' => $reason,
            ], $actor);

            return $this->resumeAfterManualAction($run, $actor, $step);
        });
    }

    public function correctSceneManually(ProductionAutomationRun $run, ProductionScene $scene, User $actor, array $data): ProductionAutomationRun
    {
        return DB::transaction(function () use ($run, $scene, $actor, $data): ProductionAutomationRun {
            $run = ProductionAutomationRun::query()->with(['project.scenes', 'steps'])->lockForUpdate()->findOrFail($run->id);
            $scene = ProductionScene::query()->whereKey($scene->id)->lockForUpdate()->firstOrFail();

            if ($scene->production_project_id !== $run->production_project_id) {
                throw new RuntimeException('Scene does not belong to this automation run.');
            }

            $scene->update(collect($data)->except('reason')->all() + [
                'ai_sync_status' => 'scenes_need_review',
            ]);
            $this->personalizer->refreshSceneStatus($scene->fresh());
            $step = $this->sceneStep($run, (int) $scene->scene_number);
            $inputFingerprint = $this->sceneInputFingerprint($run->fresh(['project.scenes', 'project.assets']), $scene->fresh());

            $scene->assets()
                ->where('asset_type', 'scene_image')
                ->where('production_automation_run_id', $run->id)
                ->where(function ($query) use ($inputFingerprint) {
                    $query->whereNull('input_fingerprint')->orWhere('input_fingerprint', '!=', $inputFingerprint);
                })
                ->update(['is_final' => false]);

            if ($step && in_array($step->status, [ProductionAutomation::STEP_COMPLETED, ProductionAutomation::STEP_WAITING_REVIEW, ProductionAutomation::STEP_FAILED_RECOVERABLE, ProductionAutomation::STEP_PROVIDER_FAILED], true)) {
                $this->queueStepForInvalidation($step, [
                    'manual_invalidation' => true,
                    'reason' => $data['reason'],
                    'input_fingerprint' => $inputFingerprint,
                ], $actor);
            }

            ProductionStudio::log($run->project, 'automation.scene.manual_corrected', 'تم تصحيح مشهد للمرحلة الثالثة يدويًا.', [
                'run_id' => $run->id,
                'scene_id' => $scene->id,
                'scene_number' => $scene->scene_number,
                'reason' => $data['reason'],
            ], $actor);

            return $this->resumeAfterManualAction($run, $actor, $step ?: $run->steps->firstWhere('step_key', 'scene_01'));
        });
    }

    private function advanceCover(ProductionAutomationRun $run, ProductionAutomationStep $step): bool
    {
        $inputFingerprint = $this->coverInputFingerprint($run);
        $compatible = $this->compatibleAsset($run, 'cover_image', $inputFingerprint);

        if ($compatible) {
            $this->completeStep($step, $inputFingerprint, (string) $compatible->output_fingerprint, [
                'source' => 'compatible_cover',
                'asset_id' => $compatible->id,
            ]);
            AdvanceProductionAutomationRun::dispatch($run->id)->afterCommit();

            return true;
        }

        if ($this->hasActiveJob($run, $step, 'cover_image')) {
            $this->markStepRunning($step, $inputFingerprint);

            return true;
        }

        $asset = $this->latestAutomationAsset($run, $step, 'cover_image');
        if ($asset) {
            return $this->reviewVisualAsset($run, $step, $asset, $inputFingerprint, 'cover');
        }

        $nextAttempt = $this->nextGenerationAttemptNumber($step);

        if ($nextAttempt > (int) $step->attempt_limit) {
            $this->pauseStep($run, $step, 'cover_attempts_exhausted', 'All automatic cover attempts failed.');

            return true;
        }

        return $this->startImageAttempt($run, $step, null, 'cover', $inputFingerprint, $nextAttempt);
    }

    private function advanceScenes(ProductionAutomationRun $run): bool
    {
        $sceneCheck = $this->validateSceneSet($run);
        if (! $sceneCheck['ok']) {
            $step = $run->steps->firstWhere('step_key', 'scene_01');
            $this->pauseStep($run, $step, $sceneCheck['code'], $sceneCheck['summary'], [$sceneCheck]);

            return true;
        }

        $limit = $this->sceneConcurrencyLimit($run);
        $started = 0;
        $sceneSteps = $run->steps
            ->filter(fn (ProductionAutomationStep $step): bool => str_starts_with($step->step_key, 'scene_'))
            ->sortBy('sequence')
            ->values();

        foreach ($sceneSteps as $step) {
            if (in_array($step->status, [ProductionAutomation::STEP_COMPLETED, ProductionAutomation::STEP_FAILED, ProductionAutomation::STEP_CANCELLED], true)) {
                continue;
            }

            $scene = $this->sceneForStep($run, $step);
            if (! $scene) {
                $this->markSceneReview($run, $step, 'scene_missing', 'Scene is missing.');

                continue;
            }

            $inputFingerprint = $this->sceneInputFingerprint($run, $scene);
            $compatible = $this->compatibleAsset($run, 'scene_image', $inputFingerprint, $scene);
            if ($compatible) {
                $this->completeStep($step, $inputFingerprint, (string) $compatible->output_fingerprint, [
                    'source' => 'compatible_scene_image',
                    'asset_id' => $compatible->id,
                    'scene_id' => $scene->id,
                ]);

                continue;
            }

            if ($this->hasActiveJob($run, $step, 'scene_image')) {
                $this->markStepRunning($step, $inputFingerprint);

                continue;
            }

            $asset = $this->latestAutomationAsset($run, $step, 'scene_image');
            if ($asset) {
                $this->reviewVisualAsset($run, $step, $asset, $inputFingerprint, 'scene');

                continue;
            }

            $preparation = $this->ensureScenePrepared($run, $step, $scene);
            if (! $preparation['ready']) {
                continue;
            }

            if ($this->activeSceneRequests($run) >= $limit) {
                continue;
            }

            $nextAttempt = $this->nextGenerationAttemptNumber($step);
            if ($nextAttempt > (int) $step->attempt_limit) {
                $this->markSceneReview($run, $step, 'scene_attempts_exhausted', 'All automatic scene attempts failed.');

                continue;
            }

            if ($this->startImageAttempt($run, $step, $scene, 'scene', $inputFingerprint, $nextAttempt)) {
                $started++;
            }
        }

        $run = $run->fresh(['steps', 'project.assets']);
        $active = $this->activeSceneRequests($run);
        $pendingReview = $run->steps
            ->filter(fn (ProductionAutomationStep $step): bool => str_starts_with($step->step_key, 'scene_'))
            ->where('status', ProductionAutomation::STEP_WAITING_REVIEW)
            ->values();

        if ($this->allSceneStepsCompleted($run)) {
            AdvanceProductionAutomationRun::dispatch($run->id)->afterCommit();

            return true;
        }

        if ($active === 0 && $started === 0 && $pendingReview->isNotEmpty()) {
            $this->stateMachine->transitionRun($run, ProductionAutomation::STATUS_PAUSED_REVIEW, [
                'pause_reason' => 'scene_review_required',
                'current_stage' => 'scenes',
                'current_step_key' => $pendingReview->first()->step_key,
                'safe_failure_code' => 'phase3_scene_failures_need_review',
                'safe_failure_summary' => 'One or more scenes require manual review after independent scene work completed.',
                'blockers' => $pendingReview->map(fn (ProductionAutomationStep $step): array => [
                    'code' => $step->safe_failure_code,
                    'summary' => $step->safe_failure_summary,
                    'step_key' => $step->step_key,
                ])->all(),
            ], null, 'phase3_scene_aggregation');
        }

        return true;
    }

    private function ensureScenePrepared(ProductionAutomationRun $run, ProductionAutomationStep $step, ProductionScene $scene): array
    {
        $readiness = $this->sceneReadiness($scene);
        if ($readiness['ready']) {
            return $readiness;
        }

        if (($readiness['code'] ?? null) === 'scene_template_hero_conflict') {
            $this->markSceneReview($run, $step, $readiness['code'], $readiness['summary']);

            return $readiness;
        }

        if ($this->hasActiveJob($run, $step, 'scene_improvement')) {
            $this->markStepRunning($step, $this->sceneInputFingerprint($run, $scene), ['preparation' => 'processing']);

            return ['ready' => false];
        }

        $completed = $this->completedJob($run, $step, 'scene_improvement');
        if ($completed) {
            return $this->applyCompletedScenePreparation($run, $step, $scene, $completed);
        }

        $this->startScenePreparationJob($run, $step, $scene);

        return ['ready' => false];
    }

    private function applyCompletedScenePreparation(ProductionAutomationRun $run, ProductionAutomationStep $step, ProductionScene $scene, SceneGenerationJob $job): array
    {
        $data = data_get($job->provider_response_json, 'structured_result');
        if (! is_array($data) || ! $this->validSceneImprovement($data)) {
            $this->failAttempt($job->automationAttempt, 'scene_preparation_malformed', 'Scene preparation returned malformed structured output.');
            $this->markSceneReview($run, $step, 'scene_preparation_malformed', 'Scene preparation returned malformed structured output.');

            return ['ready' => false];
        }

        $scene->update([
            'visual_direction' => $data['visual_direction'],
            'child_action_pose' => $data['child_action_pose'],
            'environment' => $data['environment'],
            'mood_lighting' => $data['mood_lighting'],
            'supporting_characters' => $data['supporting_characters'],
            'key_objects' => $data['key_objects'],
            'continuity_notes' => $data['continuity_notes'],
            'text_safe_area_notes' => $data['safe_text_area_notes'],
            'ai_sync_status' => 'scenes_need_review',
        ]);
        $this->personalizer->refreshSceneStatus($scene->fresh());

        $job->automationAttempt?->update([
            'status' => 'approved',
            'validation_result_json' => ['source' => 'scene_preparation', 'ok' => true],
            'approval_type' => 'automatic',
            'completed_at' => now(),
        ]);

        return $this->sceneReadiness($scene->fresh());
    }

    private function startScenePreparationJob(ProductionAutomationRun $run, ProductionAutomationStep $step, ProductionScene $scene): void
    {
        $model = $this->modelFromSnapshot($run, 'scene_text', 'prompt_enhancement');
        $inputFingerprint = $this->sceneInputFingerprint($run, $scene);
        $attempt = $this->createAttempt($run, $step, $inputFingerprint, 'openai', $model->code, 0);
        $existingJob = $run->project->generationJobs()
            ->where('production_automation_attempt_id', $attempt->id)
            ->where('job_type', 'scene_improvement')
            ->whereIn('status', ['queued', 'processing'])
            ->where(fn ($query) => $this->notStalled($query))
            ->latest('id')
            ->first();

        if ($existingJob) {
            $this->markStepRunning($step, $inputFingerprint, ['preparation_job_id' => $existingJob->id]);

            return;
        }

        $cost = $this->ledger->reserve(
            $run,
            $step,
            $attempt,
            'openai',
            $model->code,
            $model->estimatedCost() ?: config('production_studio.automation.phase3.scene_preparation_cost_fallback', '0.0100'),
            $this->pricingSnapshot('scene_preparation', $model),
            'automation:'.$attempt->attempt_uuid.':scene_preparation'
        );

        $job = $this->createStructuredJob($run, $step, $attempt, $model, 'scene_improvement', 'prompt_enhancement', [
            'scene_id' => $scene->id,
            'automation_cost_entry_id' => $cost->id,
        ], $cost->id, $inputFingerprint, $scene);

        $this->markStepRunning($step, $inputFingerprint, ['preparation_job_id' => $job->id]);
        ProcessStructuredAiJob::dispatch($job->id)->afterCommit();
    }

    private function startImageAttempt(ProductionAutomationRun $run, ProductionAutomationStep $step, ?ProductionScene $scene, string $type, string $inputFingerprint, int $attemptNumber): bool
    {
        $modelKey = $attemptNumber >= 3 ? 'premium_fallback' : ($type === 'cover' ? 'cover' : 'generation');
        $capability = $type === 'cover' ? 'cover_generation' : 'scene_generation';
        $model = $this->modelFromSnapshot($run, $modelKey, $capability);
        $attempt = $this->createAttempt($run, $step, $inputFingerprint, $model->provider->driver, $model->code, $attemptNumber);
        $existingJob = $run->project->generationJobs()
            ->where('production_automation_attempt_id', $attempt->id)
            ->whereIn('status', ['queued', 'processing'])
            ->where(fn ($query) => $this->notStalled($query))
            ->latest('id')
            ->first();

        if ($existingJob) {
            $this->markStepRunning($step, $inputFingerprint, [
                'job_id' => $existingJob->id,
                'attempt_id' => $attempt->id,
                'attempt_number' => $attemptNumber,
                'model' => $model->code,
                'premium_fallback' => $attemptNumber >= 3,
            ], $attemptNumber);

            return true;
        }

        $outputFingerprint = $this->fingerprints->forArtifact($run, $type === 'cover' ? 'cover' : 'scene_image', $this->imageInputs($run, $type) + [
            'attempt_number' => $attemptNumber,
            'model' => $model->code,
        ], $scene);

        try {
            $cost = $this->ledger->reserve(
                $run,
                $step,
                $attempt,
                $model->provider->driver,
                $model->code,
                $model->estimatedCost(),
                $this->pricingSnapshot($type === 'cover' ? 'cover_generation' : 'scene_generation', $model) + [
                    'attempt_number' => $attemptNumber,
                    'premium_fallback' => $attemptNumber >= 3,
                ],
                'automation:'.$attempt->attempt_uuid.':'.$type.'_generation'
            );
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'hard budget')) {
                $this->pauseBudget($run, $step, $exception->getMessage());

                return false;
            }

            throw $exception;
        }

        $reference = $this->approvedChildReference($run);
        $job = $this->generationJobs->execute($run->project, [
            'model_code' => $model->code,
            'job_type' => $type === 'cover' ? 'cover_image' : 'scene_image',
            'generation_mode' => $type === 'cover' ? 'cover_generation' : 'character_scene',
            'style_preset' => data_get($run->options_snapshot_json, 'style_preset', config('production_studio.automation.default_style_preset')),
            'generation_quality' => data_get($run->options_snapshot_json, 'generation_quality', config('production_studio.automation.default_generation_quality', 'high')),
            'character_sheet_id' => $reference?->id,
            'reference_photo_indices' => $run->project->characterProfile?->approvedReferenceIndices() ?? [],
            'identity_lock' => $type !== 'cover',
            'prompt_notes' => $this->retryPromptNotes($type, $attemptNumber),
            'production_automation_run_id' => $run->id,
            'production_automation_step_id' => $step->id,
            'production_automation_attempt_id' => $attempt->id,
            'automation_attempt_uuid' => $attempt->attempt_uuid,
            'automation_cost_entry_id' => $cost->id,
            'input_fingerprint' => $inputFingerprint,
            'output_fingerprint' => $outputFingerprint,
            'run_version' => $run->version,
            'orchestration_generation' => $run->orchestration_generation,
            'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
            'initiated_by_user_id' => $run->started_by_user_id,
        ], $scene);

        $attempt->update([
            'status' => 'running',
            'output_fingerprint' => $outputFingerprint,
            'heartbeat_at' => now(),
        ]);
        $this->markStepRunning($step, $inputFingerprint, [
            'job_id' => $job->id,
            'attempt_id' => $attempt->id,
            'attempt_number' => $attemptNumber,
            'model' => $model->code,
            'premium_fallback' => $attemptNumber >= 3,
        ], $attemptNumber);

        return true;
    }

    private function reviewVisualAsset(ProductionAutomationRun $run, ProductionAutomationStep $step, ProductionProjectAsset $asset, string $inputFingerprint, string $type): bool
    {
        $review = data_get($asset->metadata_json, 'identity_review');
        if (! is_array($review) || in_array(data_get($review, 'status'), ['queued', 'processing'], true)) {
            $this->markStepRunning($step, $inputFingerprint, ['asset_id' => $asset->id, 'validation' => data_get($review, 'status', 'pending')]);

            return true;
        }

        if (data_get($review, 'status') !== 'completed') {
            return $this->failVisualAttempt($run, $step, $asset, $inputFingerprint, $type, 'visual_validation_failed', 'Visual validation did not complete successfully.');
        }

        if (! hash_equals((string) $asset->input_fingerprint, $inputFingerprint)) {
            $this->failAttempt($asset->automationAttempt, 'visual_input_fingerprint_changed', 'Visual input fingerprint changed before validation could apply.');
            if ($type === 'cover') {
                $this->pauseStep($run, $step, 'visual_input_fingerprint_changed', 'Visual input fingerprint changed before validation could apply.');
            } else {
                $this->markSceneReview($run, $step, 'visual_input_fingerprint_changed', 'Visual input fingerprint changed before validation could apply.');
            }

            return true;
        }

        $result = data_get($review, 'result', []);
        $validation = $this->visualValidator->evaluate(
            is_array($result) ? $result : [],
            $type,
            (int) data_get($run->options_snapshot_json, 'identity_pass_threshold', config('production_studio.automation.identity_pass_threshold', 85)),
            $type === 'cover'
                ? (int) config('production_studio.automation.phase3.cover_story_relevance_threshold', 80)
                : (int) data_get($run->options_snapshot_json, 'scene_adherence_threshold', config('production_studio.automation.scene_adherence_threshold', 80))
        );

        if ($validation['decision'] !== 'pass') {
            return $this->failVisualAttempt($run, $step, $asset, $inputFingerprint, $type, $validation['safe_failure_code'], $validation['safe_failure_summary'] ?? 'Visual validation failed.', $validation);
        }

        $this->markOnlyFinal($asset);
        $asset->update([
            'status' => 'approved',
            'is_final' => true,
            'review_notes' => 'Automatically approved by Phase 3 visual validation.',
            'reviewed_at' => now(),
            'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
        ]);

        $this->completeStep($step, $inputFingerprint, (string) $asset->output_fingerprint, [
            'source' => 'validated_phase3_asset',
            'asset_id' => $asset->id,
            'identity_score' => $validation['identity_score'] ?? null,
            'story_relevance_score' => $validation['story_relevance_score'] ?? null,
            'scene_adherence_score' => $validation['scene_adherence_score'] ?? null,
            'blocking_flags' => $validation['blocking_flags'] ?? [],
        ]);

        $asset->automationAttempt?->update([
            'status' => 'approved',
            'output_fingerprint' => $asset->output_fingerprint,
            'validation_result_json' => $validation,
            'approval_type' => 'automatic',
            'completed_at' => now(),
        ]);

        ProductionStudio::log($run->project, 'automation.phase3_asset.approved', 'تم اعتماد مخرج المرحلة الثالثة تلقائيًا.', [
            'run_id' => $run->id,
            'asset_id' => $asset->id,
            'asset_type' => $asset->asset_type,
            'scene_id' => $asset->production_scene_id,
        ]);

        AdvanceProductionAutomationRun::dispatch($run->id)->afterCommit();

        return true;
    }

    private function failVisualAttempt(ProductionAutomationRun $run, ProductionAutomationStep $step, ProductionProjectAsset $asset, string $inputFingerprint, string $type, string $code, string $summary, array $validation = []): bool
    {
        $asset->update([
            'status' => 'rejected',
            'is_final' => false,
            'rejection_reason' => $summary,
            'reviewed_at' => now(),
        ]);
        $this->failAttempt($asset->automationAttempt, $code, $summary, $validation);

        $nextAttempt = ((int) ($asset->automationAttempt?->attempt_number ?? $step->attempt_number)) + 1;
        if ($nextAttempt > (int) $step->attempt_limit) {
            $type === 'cover'
                ? $this->pauseStep($run, $step, 'cover_attempts_exhausted', 'All automatic cover attempts failed.', [['code' => $code, 'summary' => $summary]])
                : $this->markSceneReview($run, $step, 'scene_attempts_exhausted', 'All automatic scene attempts failed.');

            return true;
        }

        $scene = $type === 'scene' ? $asset->scene : null;

        if ($type === 'scene' && $this->activeSceneRequests($run) >= $this->sceneConcurrencyLimit($run)) {
            return true;
        }

        return $this->startImageAttempt($run->fresh(['project.characterProfile', 'project.assets', 'project.scenes', 'steps']), $step->fresh(), $scene, $type, $inputFingerprint, $nextAttempt);
    }

    private function pauseAtPhase4Boundary(ProductionAutomationRun $run, ProductionAutomationStep $step): bool
    {
        $this->stateMachine->transitionRun($run, ProductionAutomation::STATUS_PAUSED_REVIEW, [
            'pause_reason' => 'phase3_complete_ready_for_layout',
            'current_stage' => 'layout_print',
            'current_step_key' => 'layout_print',
            'safe_failure_code' => 'phase3_complete_ready_for_layout',
            'safe_failure_summary' => 'Phase 3 automation is complete. Layout and print generation has not been started.',
            'blockers' => [[
                'code' => 'phase3_complete_ready_for_layout',
                'summary' => 'Phase 3 is complete. Phase 4 layout and print automation is intentionally not implemented in this phase.',
            ]],
        ], null, 'phase3_boundary');

        ProductionStudio::log($run->project, 'automation.phase3_completed', 'اكتملت المرحلة الثالثة من الإنتاج التلقائي وتوقفت قبل التصميم والطباعة.', [
            'run_id' => $run->id,
            'next_step' => $step->step_key,
        ]);

        return true;
    }

    private function validateSceneSet(ProductionAutomationRun $run): array
    {
        $numbers = $run->project->scenes->pluck('scene_number')->map(fn ($number): int => (int) $number)->sort()->values()->all();

        if ($numbers !== range(1, 13)) {
            return ['ok' => false, 'code' => 'scene_numbers_invalid', 'summary' => 'Scenes must be exactly numbered 1 through 13.'];
        }

        return ['ok' => true];
    }

    private function sceneReadiness(ProductionScene $scene): array
    {
        if ($scene->oldHeroConflicts() !== []) {
            return ['ready' => false, 'code' => 'scene_template_hero_conflict', 'summary' => 'Scene still contains old template hero references.'];
        }

        foreach (['story_text', 'visual_direction', 'child_action_pose', 'environment', 'mood_lighting', 'supporting_characters', 'key_objects', 'text_safe_area_notes'] as $field) {
            if (blank($scene->{$field})) {
                return ['ready' => false, 'code' => 'scene_required_field_missing', 'summary' => "Scene {$scene->scene_number} is missing {$field}.", 'field' => $field];
            }
        }

        if (! $scene->isPersonalizedForImageGeneration()) {
            return ['ready' => false, 'code' => 'scene_personalization_incomplete', 'summary' => 'Scene is not personalized for image generation.'];
        }

        return ['ready' => true];
    }

    private function validSceneImprovement(array $data): bool
    {
        foreach (['visual_direction', 'child_action_pose', 'environment', 'mood_lighting', 'supporting_characters', 'key_objects', 'continuity_notes', 'safe_text_area_notes'] as $field) {
            if (blank($data[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function compatibleAsset(ProductionAutomationRun $run, string $assetType, string $inputFingerprint, ?ProductionScene $scene = null): ?ProductionProjectAsset
    {
        return $run->project->assets()
            ->where('asset_type', $assetType)
            ->when($scene, fn ($query) => $query->where('production_scene_id', $scene->id))
            ->where('status', 'approved')
            ->where('is_final', true)
            ->where('input_fingerprint', $inputFingerprint)
            ->whereNotNull('output_fingerprint')
            ->latest('version_number')
            ->first();
    }

    private function latestAutomationAsset(ProductionAutomationRun $run, ProductionAutomationStep $step, string $assetType): ?ProductionProjectAsset
    {
        return $run->project->assets()
            ->with(['automationAttempt', 'scene'])
            ->where('production_automation_run_id', $run->id)
            ->where('production_automation_step_id', $step->id)
            ->where('asset_type', $assetType)
            ->whereIn('status', ['under_review', 'rejected'])
            ->latest('id')
            ->first();
    }

    private function hasActiveJob(ProductionAutomationRun $run, ProductionAutomationStep $step, string $jobType): bool
    {
        return $run->project->generationJobs()
            ->where('production_automation_run_id', $run->id)
            ->where('production_automation_step_id', $step->id)
            ->where('job_type', $jobType)
            ->whereIn('status', ['queued', 'processing'])
            ->where(fn ($query) => $this->notStalled($query))
            ->exists();
    }

    private function completedJob(ProductionAutomationRun $run, ProductionAutomationStep $step, string $jobType): ?SceneGenerationJob
    {
        return $run->project->generationJobs()
            ->with(['automationAttempt'])
            ->where('production_automation_run_id', $run->id)
            ->where('production_automation_step_id', $step->id)
            ->where('job_type', $jobType)
            ->where('status', 'completed')
            ->latest('id')
            ->first();
    }

    private function activeSceneRequests(ProductionAutomationRun $run): int
    {
        return $run->project->generationJobs()
            ->where('production_automation_run_id', $run->id)
            ->where('job_type', 'scene_image')
            ->whereIn('status', ['queued', 'processing'])
            ->where(fn ($query) => $this->notStalled($query))
            ->count();
    }

    private function notStalled($query): void
    {
        $cutoff = now()->subMinutes((int) config('production_studio.automation.queue.heartbeat_stale_minutes', 15));

        $query->where(function ($nested) use ($cutoff): void {
            $nested->where('updated_at', '>=', $cutoff)
                ->orWhere('heartbeat_at', '>=', $cutoff)
                ->orWhereNull('updated_at');
        });
    }

    private function sceneConcurrencyLimit(ProductionAutomationRun $run): int
    {
        return max(1, min(5, (int) data_get($run->options_snapshot_json, 'scene_concurrency', config('production_studio.automation.scene_concurrency', 2))));
    }

    private function nextGenerationAttemptNumber(ProductionAutomationStep $step): int
    {
        $inFlight = $step->attempts()
            ->where('attempt_number', '>', 0)
            ->whereIn('status', ['queued', 'running'])
            ->orderBy('attempt_number')
            ->first();

        if ($inFlight) {
            return (int) $inFlight->attempt_number;
        }

        $used = $step->attempts()
            ->where('attempt_number', '>', 0)
            ->max('attempt_number');

        return ((int) $used) + 1;
    }

    private function createAttempt(ProductionAutomationRun $run, ProductionAutomationStep $step, string $inputFingerprint, ?string $provider, ?string $model, int $attemptNumber): ProductionAutomationAttempt
    {
        return DB::transaction(function () use ($run, $step, $inputFingerprint, $provider, $model, $attemptNumber): ProductionAutomationAttempt {
            $lockedStep = ProductionAutomationStep::query()->whereKey($step->id)->lockForUpdate()->firstOrFail();

            return $lockedStep->attempts()->firstOrCreate(
                ['attempt_number' => $attemptNumber],
                [
                    'attempt_uuid' => (string) Str::uuid(),
                    'automation_run_id' => $run->id,
                    'run_version' => $run->version,
                    'orchestration_generation' => $run->orchestration_generation,
                    'status' => 'queued',
                    'provider' => $provider,
                    'model' => $model,
                    'input_fingerprint' => $inputFingerprint,
                    'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
                    'heartbeat_at' => now(),
                ]
            );
        });
    }

    private function createStructuredJob(ProductionAutomationRun $run, ProductionAutomationStep $step, ProductionAutomationAttempt $attempt, AiModel $model, string $jobType, string $mode, array $inputAssets, int $costEntryId, string $inputFingerprint, ?ProductionScene $scene = null): SceneGenerationJob
    {
        return $run->project->generationJobs()->create([
            'production_scene_id' => $scene?->id,
            'ai_provider_id' => $model->ai_provider_id,
            'ai_model_id' => $model->id,
            'job_type' => $jobType,
            'generation_mode' => $mode,
            'input_assets_json' => $inputAssets,
            'provider_request_json' => [
                'provider_driver' => $model->provider->driver,
                'provider_display_name' => $model->provider->public_name,
                'model_code' => $model->code,
                'model_display_name' => $model->display_name,
                'capability' => $mode,
                'automation_cost_entry_id' => $costEntryId,
            ],
            'estimated_cost' => $model->estimatedCost(),
            'cost_source' => 'estimated',
            'status' => 'queued',
            'initiated_by_user_id' => $run->started_by_user_id,
            'production_automation_run_id' => $run->id,
            'production_automation_step_id' => $step->id,
            'production_automation_attempt_id' => $attempt->id,
            'input_fingerprint' => $inputFingerprint,
            'run_version' => $run->version,
            'orchestration_generation' => $run->orchestration_generation,
            'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
        ]);
    }

    private function coverInputFingerprint(ProductionAutomationRun $run): string
    {
        return $this->fingerprints->forArtifact($run, 'cover', $this->imageInputs($run, 'cover'));
    }

    private function sceneInputFingerprint(ProductionAutomationRun $run, ProductionScene $scene): string
    {
        return $this->fingerprints->forArtifact($run, 'scene_image', $this->imageInputs($run, 'scene'), $scene);
    }

    private function imageInputs(ProductionAutomationRun $run, string $type): array
    {
        $storyVersion = $run->project->storyVersions()->where('status', 'approved')->latest('version_number')->first();
        $reference = $this->approvedChildReference($run);

        return [
            'production_story_version_id' => $storyVersion?->id,
            'production_story_fingerprint' => $storyVersion?->output_fingerprint,
            'story_title' => $storyVersion?->title ?: $run->project->order?->story?->title,
            'profile_fingerprint' => $run->project->characterProfile?->output_fingerprint,
            'child_reference_asset_id' => $reference?->id,
            'child_reference_fingerprint' => $reference?->output_fingerprint,
            'style_preset' => data_get($run->options_snapshot_json, 'style_preset', config('production_studio.automation.default_style_preset')),
            'generation_quality' => data_get($run->options_snapshot_json, 'generation_quality', config('production_studio.automation.default_generation_quality', 'high')),
            'model' => data_get($run->pricing_snapshot_json, $type === 'cover' ? 'models.cover.model' : 'models.generation.model'),
            'premium_model' => data_get($run->pricing_snapshot_json, 'models.premium_fallback.model'),
            'orientation' => $type === 'cover' ? 'portrait' : 'landscape',
            'prompt_template_version' => config('production_studio.automation.prompt_template_version', 'production-prompt-v1'),
        ];
    }

    private function approvedChildReference(ProductionAutomationRun $run): ?ProductionProjectAsset
    {
        return $run->project->assets()
            ->where('asset_type', 'character_sheet')
            ->where('status', 'approved')
            ->where('is_primary', true)
            ->latest('version_number')
            ->first();
    }

    private function sceneForStep(ProductionAutomationRun $run, ProductionAutomationStep $step): ?ProductionScene
    {
        $number = (int) substr($step->step_key, -2);
        $scene = $run->project->scenes->firstWhere('scene_number', $number);

        if ($scene && (int) $step->production_scene_id !== (int) $scene->id) {
            $step->update(['production_scene_id' => $scene->id]);
        }

        return $scene;
    }

    private function sceneStep(ProductionAutomationRun $run, int $sceneNumber): ?ProductionAutomationStep
    {
        return $run->steps->firstWhere('step_key', 'scene_'.str_pad((string) $sceneNumber, 2, '0', STR_PAD_LEFT));
    }

    private function stepForAsset(ProductionAutomationRun $run, ProductionProjectAsset $asset): ?ProductionAutomationStep
    {
        if ($asset->asset_type === 'cover_image') {
            return $run->steps->firstWhere('step_key', 'cover');
        }

        $sceneNumber = (int) ($asset->scene?->scene_number ?? 0);

        return $sceneNumber ? $this->sceneStep($run, $sceneNumber) : null;
    }

    private function fingerprintsForAsset(ProductionAutomationRun $run, ProductionProjectAsset $asset): array
    {
        if ($asset->asset_type === 'cover_image') {
            $input = $this->coverInputFingerprint($run);

            return [$input, $asset->output_fingerprint ?: $this->fingerprints->hash(['type' => 'manual_cover', 'input_fingerprint' => $input, 'asset_id' => $asset->id])];
        }

        $scene = $asset->scene ?: ProductionScene::find($asset->production_scene_id);
        if (! $scene) {
            throw new RuntimeException('Scene asset is missing its production scene.');
        }

        $input = $this->sceneInputFingerprint($run, $scene);

        return [$input, $asset->output_fingerprint ?: $this->fingerprints->hash(['type' => 'manual_scene_image', 'input_fingerprint' => $input, 'asset_id' => $asset->id])];
    }

    private function markOnlyFinal(ProductionProjectAsset $asset): void
    {
        $asset->project->assets()
            ->where('asset_type', $asset->asset_type)
            ->when($asset->asset_type === 'scene_image', fn ($query) => $query->where('production_scene_id', $asset->production_scene_id))
            ->where('id', '!=', $asset->id)
            ->update(['is_final' => false]);
    }

    private function allSceneStepsCompleted(ProductionAutomationRun $run): bool
    {
        return $run->steps
            ->filter(fn (ProductionAutomationStep $step): bool => str_starts_with($step->step_key, 'scene_'))
            ->every(fn (ProductionAutomationStep $step): bool => $step->status === ProductionAutomation::STEP_COMPLETED);
    }

    private function markStepRunning(ProductionAutomationStep $step, string $inputFingerprint, array $metadata = [], ?int $attemptNumber = null): void
    {
        if ($step->status === ProductionAutomation::STEP_RUNNING && $step->input_fingerprint === $inputFingerprint) {
            return;
        }

        $this->stateMachine->transitionStep($step, ProductionAutomation::STEP_RUNNING, array_filter([
            'input_fingerprint' => $inputFingerprint,
            'attempt_number' => $attemptNumber,
            'metadata_json' => $metadata,
        ], fn ($value): bool => $value !== null), null, 'phase3');
    }

    private function markSceneReview(ProductionAutomationRun $run, ProductionAutomationStep $step, string $code, string $summary): void
    {
        if ($step->status !== ProductionAutomation::STEP_WAITING_REVIEW) {
            $this->stateMachine->transitionStep($step, ProductionAutomation::STEP_WAITING_REVIEW, [
                'safe_failure_code' => $code,
                'safe_failure_summary' => $summary,
                'validation_summary_json' => [['code' => $code, 'summary' => $summary]],
            ], null, 'phase3');
        }
    }

    private function pauseStep(ProductionAutomationRun $run, ?ProductionAutomationStep $step, string $code, string $summary, array $blockers = []): void
    {
        if ($step && $step->status !== ProductionAutomation::STEP_WAITING_REVIEW) {
            $this->stateMachine->transitionStep($step, ProductionAutomation::STEP_WAITING_REVIEW, [
                'safe_failure_code' => $code,
                'safe_failure_summary' => $summary,
                'validation_summary_json' => $blockers ?: [['code' => $code, 'summary' => $summary]],
            ], null, 'phase3');
        }

        $this->stateMachine->transitionRun($run, ProductionAutomation::STATUS_PAUSED_REVIEW, [
            'pause_reason' => 'human_review_required',
            'current_stage' => $step?->stage ?? $run->current_stage,
            'current_step_key' => $step?->step_key ?? $run->current_step_key,
            'safe_failure_code' => $code,
            'safe_failure_summary' => $summary,
            'blockers' => $blockers ?: [['code' => $code, 'summary' => $summary]],
        ], null, 'phase3');
    }

    private function pauseBudget(ProductionAutomationRun $run, ProductionAutomationStep $step, string $summary): void
    {
        $this->stateMachine->transitionRun($run, ProductionAutomation::STATUS_PAUSED_BUDGET, [
            'pause_reason' => 'hard_budget_exhausted',
            'current_stage' => $step->stage,
            'current_step_key' => $step->step_key,
            'safe_failure_code' => 'hard_budget_exhausted',
            'safe_failure_summary' => $summary,
            'blockers' => [['code' => 'hard_budget_exhausted', 'summary' => $summary, 'step_key' => $step->step_key]],
        ], null, 'phase3_budget');
    }

    private function completeStep(ProductionAutomationStep $step, string $inputFingerprint, ?string $outputFingerprint, array $summary, ?User $actor = null, string $approvalType = 'automatic'): void
    {
        $this->stateMachine->transitionStep($step, ProductionAutomation::STEP_COMPLETED, [
            'input_fingerprint' => $inputFingerprint,
            'output_fingerprint' => $outputFingerprint,
            'approval_type' => $approvalType,
            'approved_by_user_id' => $actor?->id,
            'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
            'validation_summary_json' => $summary,
        ], $actor, 'phase3');
    }

    private function queueStepForInvalidation(ProductionAutomationStep $step, array $context, User $actor): void
    {
        $this->stateMachine->transitionStep($step, ProductionAutomation::STEP_QUEUED, [
            'safe_failure_code' => null,
            'safe_failure_summary' => null,
            'metadata_json' => $context,
            'input_fingerprint' => $context['input_fingerprint'] ?? null,
        ], $actor, 'phase3_manual_invalidation');
    }

    private function failAttempt(?ProductionAutomationAttempt $attempt, string $code, string $summary, array $validation = []): void
    {
        if (! $attempt || $attempt->status === 'failed') {
            return;
        }

        $attempt->update([
            'status' => 'failed',
            'safe_failure_code' => $code,
            'safe_failure_summary' => $summary,
            'validation_result_json' => $validation,
            'heartbeat_at' => now(),
            'failed_at' => now(),
        ]);
    }

    private function resumeAfterManualAction(ProductionAutomationRun $run, User $actor, ?ProductionAutomationStep $step): ProductionAutomationRun
    {
        $run = $run->fresh(['steps', 'project']);

        if (in_array($run->status, ProductionAutomation::pausedStatuses(), true)) {
            $run = $this->stateMachine->transitionRun($run, ProductionAutomation::STATUS_RUNNING, [
                'pause_reason' => null,
                'current_stage' => $step?->stage ?? $run->current_stage,
                'current_step_key' => $step?->step_key ?? $run->current_step_key,
                'safe_failure_code' => null,
                'safe_failure_summary' => null,
                'blockers' => [],
            ], $actor, 'phase3_manual_review');
        }

        AdvanceProductionAutomationRun::dispatch($run->id)->afterCommit();

        return $run->fresh(['steps', 'project.printLayouts', 'costEntries']);
    }

    private function modelFromSnapshot(ProductionAutomationRun $run, string $key, string $capability): AiModel
    {
        $code = data_get($run->pricing_snapshot_json, "models.{$key}.model")
            ?: data_get($run->options_snapshot_json, "{$key}_model_code");
        $model = $code
            ? AiModel::query()->with('provider')->where('code', $code)->where('is_active', true)->first()
            : $this->availability->activeModelsForCapability($capability)->first();

        if (! $model || ! $model->provider?->is_active || ! $this->availability->modelAvailable($model, $capability)) {
            throw new RuntimeException("No configured model is available for {$capability}.");
        }

        return $model;
    }

    private function pricingSnapshot(string $type, AiModel $model): array
    {
        return [
            'type' => $type,
            'provider' => $model->provider?->driver,
            'model' => $model->code,
            'estimated_cost' => $model->estimatedCost(),
            'cost_source' => $model->estimated_cost_type ?: 'estimated',
            'currency' => $model->estimated_cost_currency ?: 'USD',
        ];
    }

    private function retryPromptNotes(string $type, int $attemptNumber): ?string
    {
        if ($attemptNumber === 1) {
            return null;
        }

        if ($attemptNumber === 2) {
            return $type === 'cover'
                ? 'Automatic retry: improve child identity, story relevance, portrait cover composition, safe crop/trim, and remove all text/logos/watermarks.'
                : 'Automatic retry: improve child identity, scene action, environment, landscape composition, safe text area, and remove all text/logos/watermarks.';
        }

        return $type === 'cover'
            ? 'Premium fallback attempt: maximize identity fidelity and cover composition. Do not include generated text, logos, or watermarks.'
            : 'Premium fallback attempt: maximize identity fidelity and scene adherence. Preserve landscape spread and safe text area.';
    }
}
