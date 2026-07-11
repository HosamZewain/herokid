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
use App\Models\ProductionStoryVersion;
use App\Models\SceneGenerationJob;
use App\Models\User;
use App\Services\Ai\AiProviderAvailability;
use App\Support\ProductionAutomation;
use App\Support\ProductionStudio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ProductionAutomationPhase2Service
{
    public function __construct(
        private readonly ProductionAutomationStateMachine $stateMachine,
        private readonly ProductionAutomationCostLedger $ledger,
        private readonly ProductionAutomationFingerprint $fingerprints,
        private readonly ProductionAutomationIdentityValidator $identityValidator,
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
            'project.assets.automationRun',
            'steps',
            'attempts',
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

        return match ($step->step_key) {
            'story_preparation' => $this->advanceStoryPreparation($run, $step),
            'character_profile' => $this->advanceCharacterProfile($run, $step),
            'child_reference' => $this->advanceChildReference($run, $step),
            'cover' => false,
            default => false,
        };
    }

    public function advanceStoryPreparation(ProductionAutomationRun $run, ProductionAutomationStep $step): bool
    {
        $inputFingerprint = $this->fingerprints->forArtifact($run, 'story_preparation', $this->storyInputs($run));
        $compatible = $this->compatibleStoryVersion($run, $inputFingerprint);

        if ($compatible) {
            $this->completeStep($step, $inputFingerprint, (string) $compatible->output_fingerprint, [
                'source' => 'compatible_story_version',
                'story_version_id' => $compatible->id,
            ]);

            return true;
        }

        if ($this->hasActiveAutomationJob($run, $step)) {
            $this->markStepRunning($step, $inputFingerprint);

            return true;
        }

        $completed = $this->completedAutomationJob($run, $step);
        if ($completed) {
            return $this->applyCompletedStoryJob($run, $step, $completed, $inputFingerprint);
        }

        if ($this->latestFailedAttempt($step)) {
            $this->pauseStep($run, $step, 'story_preparation_review_required', 'Story preparation failed closed and requires human review.');

            return true;
        }

        $model = $this->modelFromSnapshot($run, 'scene_text', 'scene_extraction');
        $attempt = $this->createAttempt($run, $step, $inputFingerprint, 'openai', $model->code);
        $cost = $this->ledger->reserve(
            $run,
            $step,
            $attempt,
            'openai',
            $model->code,
            $model->estimatedCost() ?: config('production_studio.automation.phase2.story_text_cost_fallback', '0.0100'),
            $this->pricingSnapshot('story_preparation', $model),
            'automation:'.$attempt->attempt_uuid.':story_preparation'
        );

        $sourceVersion = $this->ensureStoryDraft($run, $attempt, $inputFingerprint);
        $job = $this->createStructuredJob($run, $step, $attempt, $model, 'scene_extraction', 'scene_extraction', [
            'source_version_id' => $sourceVersion->id,
        ], $cost->id, $inputFingerprint);

        $this->markStepRunning($step, $inputFingerprint, ['job_id' => $job->id, 'attempt_id' => $attempt->id]);
        ProcessStructuredAiJob::dispatch($job->id)->afterCommit();

        return true;
    }

    public function advanceCharacterProfile(ProductionAutomationRun $run, ProductionAutomationStep $step): bool
    {
        $indices = $this->referencePhotoIndices($run);
        $inputFingerprint = $this->fingerprints->forArtifact($run, 'character_profile', $this->profileInputs($run, $indices));
        $profile = $run->project->characterProfile;

        if ($profile && $profile->isReadyForAiGeneration() && hash_equals((string) $profile->input_fingerprint, $inputFingerprint)) {
            $this->completeStep($step, $inputFingerprint, (string) $profile->output_fingerprint, [
                'source' => 'compatible_character_profile',
            ]);

            return true;
        }

        if ($this->hasActiveAutomationJob($run, $step)) {
            $this->markStepRunning($step, $inputFingerprint);

            return true;
        }

        $completed = $this->completedAutomationJob($run, $step);
        if ($completed) {
            return $this->applyCompletedProfileJob($run, $step, $completed, $inputFingerprint, $indices);
        }

        if ($this->latestFailedAttempt($step)) {
            $this->pauseStep($run, $step, 'character_profile_review_required', 'Character profile analysis failed closed and requires human review.');

            return true;
        }

        $model = $this->modelFromSnapshot($run, 'validation', 'vision_to_text');
        $attempt = $this->createAttempt($run, $step, $inputFingerprint, 'openai', $model->code);
        $cost = $this->ledger->reserve(
            $run,
            $step,
            $attempt,
            'openai',
            $model->code,
            $model->estimatedCost() ?: config('production_studio.automation.phase2.vision_analysis_cost_fallback', '0.0100'),
            $this->pricingSnapshot('character_profile', $model),
            'automation:'.$attempt->attempt_uuid.':character_profile'
        );

        $job = $this->createStructuredJob($run, $step, $attempt, $model, 'character_analysis', 'vision_to_text', [
            'reference_photo_indices' => $indices,
            'contains_private_images' => true,
        ], $cost->id, $inputFingerprint);

        $this->markStepRunning($step, $inputFingerprint, ['job_id' => $job->id, 'attempt_id' => $attempt->id]);
        ProcessStructuredAiJob::dispatch($job->id)->afterCommit();

        return true;
    }

    public function advanceChildReference(ProductionAutomationRun $run, ProductionAutomationStep $step): bool
    {
        $inputFingerprint = $this->fingerprints->forArtifact($run, 'child_reference', $this->childReferenceInputs($run));
        $asset = $this->compatibleChildReference($run, $inputFingerprint);

        if ($asset) {
            $this->completeStep($step, $inputFingerprint, (string) $asset->output_fingerprint, [
                'source' => 'compatible_child_reference',
                'asset_id' => $asset->id,
            ]);

            return true;
        }

        if ($this->hasActiveAutomationJob($run, $step)) {
            $this->markStepRunning($step, $inputFingerprint);

            return true;
        }

        $pendingAsset = $this->latestAutomationAsset($run, $step);
        if ($pendingAsset) {
            return $this->reviewChildReferenceAsset($run, $step, $pendingAsset, $inputFingerprint);
        }

        $failed = $this->latestFailedAttempt($step);
        $nextAttemptNumber = $failed ? ((int) $failed->attempt_number + 1) : 1;

        if ($failed && $nextAttemptNumber > (int) $step->attempt_limit) {
            $this->pauseStep($run, $step, 'child_reference_attempts_exhausted', 'All automatic child reference attempts failed.');

            return true;
        }

        return $this->startChildReferenceAttempt($run, $step, $inputFingerprint, $nextAttemptNumber);
    }

    public function approveStoryPreparationManually(ProductionAutomationRun $run, User $actor, string $reason): ProductionAutomationRun
    {
        return DB::transaction(function () use ($run, $actor, $reason): ProductionAutomationRun {
            $run = ProductionAutomationRun::query()->with(['project.scenes', 'project.storyVersions', 'steps'])->lockForUpdate()->findOrFail($run->id);
            $step = $run->steps->firstWhere('step_key', 'story_preparation');

            if (! $step) {
                throw new RuntimeException('Story preparation step is not available.');
            }

            $scenes = $run->project->scenes()->orderBy('scene_number')->get();
            if ($scenes->count() !== 13) {
                throw new RuntimeException('Story preparation requires exactly 13 scenes before manual approval.');
            }

            foreach ($scenes as $scene) {
                foreach (['scene_number', 'story_text', 'visual_direction', 'child_action_pose', 'environment', 'text_safe_area_notes'] as $field) {
                    if (blank($scene->{$field})) {
                        throw new RuntimeException("Scene {$scene->scene_number} is missing {$field}.");
                    }
                }
            }

            $version = $run->project->storyVersions()->latest('version_number')->first();
            if (! $version) {
                throw new RuntimeException('A production story draft is required before manual approval.');
            }

            $inputFingerprint = $this->fingerprints->forArtifact($run, 'story_preparation', $this->storyInputs($run));
            $outputFingerprint = $this->fingerprints->hash([
                'type' => 'manual_production_story_draft',
                'input_fingerprint' => $inputFingerprint,
                'story_version_id' => $version->id,
                'scenes' => $scenes->map(fn (ProductionScene $scene): array => [
                    'scene_number' => $scene->scene_number,
                    'story_text' => $scene->story_text,
                    'visual_direction' => $scene->visual_direction,
                    'child_action_pose' => $scene->child_action_pose,
                    'environment' => $scene->environment,
                    'text_safe_area_notes' => $scene->text_safe_area_notes,
                ])->all(),
            ]);

            $version->update([
                'status' => 'approved',
                'approved_at' => now(),
                'reviewed_by_user_id' => $actor->id,
                'production_automation_run_id' => $run->id,
                'production_automation_step_id' => $step->id,
                'input_fingerprint' => $inputFingerprint,
                'output_fingerprint' => $outputFingerprint,
                'validation_summary_json' => [
                    'ok' => true,
                    'source' => 'manual_review',
                    'reason' => $reason,
                ],
            ]);

            $this->completeStep($step, $inputFingerprint, $outputFingerprint, [
                'source' => 'manual_story_review',
                'story_version_id' => $version->id,
                'reason' => $reason,
            ], $actor, 'manual');

            ProductionStudio::log($run->project, 'automation.story_preparation.manual_approved', 'تم اعتماد تحضير القصة يدويًا.', [
                'run_id' => $run->id,
                'story_version_id' => $version->id,
                'reason' => $reason,
            ], $actor);

            return $this->resumeRunAfterManualAction($run, $actor, $step);
        });
    }

    public function applyProfileCorrection(ProductionAutomationRun $run, User $actor, array $data): ProductionAutomationRun
    {
        return DB::transaction(function () use ($run, $actor, $data): ProductionAutomationRun {
            $run = ProductionAutomationRun::query()->with(['project.characterProfile', 'steps'])->lockForUpdate()->findOrFail($run->id);
            $step = $run->steps->firstWhere('step_key', 'character_profile');

            if (! $step) {
                throw new RuntimeException('Character profile step is not available.');
            }

            $indices = $this->referencePhotoIndices($run);
            $inputFingerprint = $this->fingerprints->forArtifact($run, 'character_profile', $this->profileInputs($run, $indices));
            $outputFingerprint = $this->fingerprints->hash([
                'type' => 'manual_character_profile',
                'input_fingerprint' => $inputFingerprint,
                'data' => $data,
            ]);

            $profile = $run->project->characterProfile()->updateOrCreate(
                ['production_project_id' => $run->production_project_id],
                [
                    'appearance_summary' => $data['appearance_summary'],
                    'hair_details' => $data['hair_details'],
                    'skin_tone' => $data['skin_tone'],
                    'eye_color_traits' => $data['eye_color_traits'],
                    'typical_expression' => $data['typical_expression'],
                    'face_shape_notes' => $data['face_shape_notes'] ?? null,
                    'body_proportion_notes' => $data['body_proportion_notes'] ?? null,
                    'identity_rules' => $data['identity_rules'],
                    'negative_instructions' => $data['negative_instructions'],
                    'confidence_notes' => $data['confidence_notes'] ?? 'Manual correction.',
                    'analysis_warnings' => $data['analysis_warnings'] ?? null,
                    'approved_reference_photos' => $indices,
                    'reference_photo_selection' => $indices,
                    'primary_face_reference_index' => $indices[0] ?? null,
                    'production_automation_run_id' => $run->id,
                    'production_automation_step_id' => $step->id,
                    'input_fingerprint' => $inputFingerprint,
                    'output_fingerprint' => $outputFingerprint,
                    'validation_summary_json' => [
                        'ok' => true,
                        'source' => 'manual_profile_correction',
                        'reason' => $data['reason'],
                    ],
                    'automation_metadata_json' => [
                        'source' => 'manual_profile_correction',
                        'corrected_by_user_id' => $actor->id,
                        'reason' => $data['reason'],
                    ],
                ]
            );

            $this->completeStep($step, $inputFingerprint, $outputFingerprint, [
                'source' => 'manual_profile_correction',
                'profile_id' => $profile->id,
                'reason' => $data['reason'],
            ], $actor, 'manual');

            ProductionStudio::log($run->project, 'automation.character_profile.manual_corrected', 'تم تصحيح ملف الشخصية يدويًا.', [
                'run_id' => $run->id,
                'profile_id' => $profile->id,
                'reason' => $data['reason'],
            ], $actor);

            return $this->resumeRunAfterManualAction($run, $actor, $step);
        });
    }

    public function approveChildReferenceManually(ProductionAutomationRun $run, ProductionProjectAsset $asset, User $actor, string $reason): ProductionAutomationRun
    {
        return DB::transaction(function () use ($run, $asset, $actor, $reason): ProductionAutomationRun {
            $run = ProductionAutomationRun::query()->with(['project.assets', 'steps'])->lockForUpdate()->findOrFail($run->id);
            $step = $run->steps->firstWhere('step_key', 'child_reference');
            $asset = ProductionProjectAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if (! $step || $asset->production_project_id !== $run->production_project_id || $asset->asset_type !== 'character_sheet') {
                throw new RuntimeException('Child reference asset does not belong to this automation run.');
            }

            $inputFingerprint = $this->fingerprints->forArtifact($run, 'child_reference', $this->childReferenceInputs($run));
            $outputFingerprint = $asset->output_fingerprint ?: $this->fingerprints->hash([
                'type' => 'manual_child_reference_asset',
                'input_fingerprint' => $inputFingerprint,
                'asset_id' => $asset->id,
                'version_number' => $asset->version_number,
            ]);

            $run->project->assets()
                ->where('asset_type', 'character_sheet')
                ->where('id', '!=', $asset->id)
                ->update(['is_primary' => false]);

            $asset->update([
                'status' => 'approved',
                'is_primary' => true,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'review_notes' => $reason,
                'input_fingerprint' => $inputFingerprint,
                'output_fingerprint' => $outputFingerprint,
                'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
            ]);

            $asset->automationAttempt?->update([
                'status' => 'approved',
                'approved_by_user_id' => $actor->id,
                'approval_type' => 'manual',
                'output_fingerprint' => $outputFingerprint,
                'completed_at' => now(),
            ]);

            $this->completeStep($step, $inputFingerprint, $outputFingerprint, [
                'source' => 'manual_child_reference_approval',
                'asset_id' => $asset->id,
                'reason' => $reason,
            ], $actor, 'manual');

            ProductionStudio::log($run->project, 'automation.child_reference.manual_approved', 'تم اعتماد الصورة المرجعية يدويًا.', [
                'run_id' => $run->id,
                'asset_id' => $asset->id,
                'reason' => $reason,
            ], $actor);

            return $this->resumeRunAfterManualAction($run, $actor, $step);
        });
    }

    public function rejectChildReferenceManually(ProductionAutomationRun $run, ProductionProjectAsset $asset, User $actor, string $reason): ProductionAutomationRun
    {
        return DB::transaction(function () use ($run, $asset, $actor, $reason): ProductionAutomationRun {
            $run = ProductionAutomationRun::query()->with(['steps'])->lockForUpdate()->findOrFail($run->id);
            $step = $run->steps->firstWhere('step_key', 'child_reference');
            $asset = ProductionProjectAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if (! $step || $asset->production_project_id !== $run->production_project_id || $asset->asset_type !== 'character_sheet') {
                throw new RuntimeException('Child reference asset does not belong to this automation run.');
            }

            $asset->update([
                'status' => 'rejected',
                'is_primary' => false,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);
            $this->failAttempt($asset->automationAttempt, 'manual_child_reference_rejected', $reason, ['source' => 'manual_review']);

            ProductionStudio::log($run->project, 'automation.child_reference.manual_rejected', 'تم رفض الصورة المرجعية يدويًا.', [
                'run_id' => $run->id,
                'asset_id' => $asset->id,
                'reason' => $reason,
            ], $actor);

            return $this->resumeRunAfterManualAction($run, $actor, $step);
        });
    }

    private function applyCompletedStoryJob(ProductionAutomationRun $run, ProductionAutomationStep $step, SceneGenerationJob $job, string $inputFingerprint): bool
    {
        if (! hash_equals((string) $job->input_fingerprint, $inputFingerprint)) {
            $this->pauseStep($run, $step, 'story_input_fingerprint_changed', 'Story preparation input changed while the provider request was running.');

            return true;
        }

        $data = data_get($job->provider_response_json, 'structured_result');
        $validation = $this->validateStoryPayload($run, is_array($data) ? $data : []);

        if (! $validation['ok']) {
            $this->failAttempt($job->automationAttempt, $validation['code'], $validation['summary'], $validation);
            $this->pauseStep($run, $step, $validation['code'], $validation['summary'], [$validation]);

            return true;
        }

        $version = DB::transaction(function () use ($run, $step, $job, $data, $validation, $inputFingerprint): ProductionStoryVersion {
            $version = $this->ensureStoryDraft($run, $job->automationAttempt, $inputFingerprint);
            $outputFingerprint = $this->fingerprints->hash([
                'type' => 'production_story_draft',
                'input_fingerprint' => $inputFingerprint,
                'data' => $data,
            ]);

            $version->update([
                'title' => $data['story_title'] ?? $version->title,
                'target_age_group' => $data['target_age_range'] ?? $version->target_age_group,
                'educational_values_json' => $data['educational_values'] ?? $version->educational_values_json,
                'status' => 'approved',
                'approved_at' => now(),
                'reviewed_by_user_id' => null,
                'input_fingerprint' => $inputFingerprint,
                'output_fingerprint' => $outputFingerprint,
                'validation_summary_json' => $validation,
                'automation_metadata_json' => [
                    'source_job_id' => $job->id,
                    'source' => 'openai_scene_extraction',
                    'template_hero_name' => $validation['personalization']['template_hero_name'] ?? null,
                    'personalized_hero_name' => $validation['personalization']['child_hero_name'] ?? null,
                ],
            ]);

            $this->replaceScenes($run, $version, $data, $validation['personalization']);
            $this->completeStep($step, $inputFingerprint, $outputFingerprint, [
                'source' => 'provider_scene_extraction',
                'job_id' => $job->id,
                'story_version_id' => $version->id,
                'personalization' => $validation['personalization'],
            ]);

            $job->automationAttempt?->update([
                'status' => 'approved',
                'output_fingerprint' => $outputFingerprint,
                'validation_result_json' => $validation,
                'approval_type' => 'automatic',
                'completed_at' => now(),
            ]);

            return $version;
        });

        ProductionStudio::log($run->project, 'automation.story_preparation.applied', 'تم تطبيق تحضير القصة تلقائيًا.', [
            'run_id' => $run->id,
            'story_version_id' => $version->id,
        ]);

        AdvanceProductionAutomationRun::dispatch($run->id)->afterCommit();

        return true;
    }

    private function applyCompletedProfileJob(ProductionAutomationRun $run, ProductionAutomationStep $step, SceneGenerationJob $job, string $inputFingerprint, array $indices): bool
    {
        if (! hash_equals((string) $job->input_fingerprint, $inputFingerprint)) {
            $this->pauseStep($run, $step, 'profile_input_fingerprint_changed', 'Character profile input changed while the provider request was running.');

            return true;
        }

        $data = data_get($job->provider_response_json, 'structured_result');
        $validation = $this->validateProfilePayload(is_array($data) ? $data : []);

        if (! $validation['ok']) {
            $this->failAttempt($job->automationAttempt, $validation['code'], $validation['summary'], $validation);
            $this->pauseStep($run, $step, $validation['code'], $validation['summary'], [$validation]);

            return true;
        }

        $outputFingerprint = $this->fingerprints->hash([
            'type' => 'character_profile',
            'input_fingerprint' => $inputFingerprint,
            'data' => $data,
        ]);

        $profile = $run->project->characterProfile()->updateOrCreate(
            ['production_project_id' => $run->production_project_id],
            [
                'appearance_summary' => $data['appearance_summary'] ?? null,
                'hair_details' => $data['hair_details'] ?? null,
                'skin_tone' => $data['skin_tone'] ?? null,
                'eye_color_traits' => $data['eyes_and_visible_traits'] ?? null,
                'typical_expression' => $data['usual_expression'] ?? null,
                'face_shape_notes' => $data['face_shape_notes'] ?? null,
                'body_proportion_notes' => $data['body_proportion_notes'] ?? null,
                'identity_rules' => $data['identity_rules'] ?? null,
                'negative_instructions' => $data['negative_instructions'] ?? null,
                'confidence_notes' => $data['confidence_notes'] ?? null,
                'reference_photo_recommendations' => $data['reference_photo_recommendations'] ?? null,
                'analysis_warnings' => $data['warnings'] ?? null,
                'approved_reference_photos' => $indices,
                'reference_photo_selection' => $indices,
                'primary_face_reference_index' => $indices[0] ?? null,
                'production_automation_run_id' => $run->id,
                'production_automation_step_id' => $step->id,
                'production_automation_attempt_id' => $job->production_automation_attempt_id,
                'input_fingerprint' => $inputFingerprint,
                'output_fingerprint' => $outputFingerprint,
                'validation_summary_json' => $validation,
                'automation_metadata_json' => [
                    'source_job_id' => $job->id,
                    'source' => 'openai_character_analysis',
                    'reference_photo_indices' => $indices,
                    'field_confidence' => $data['field_confidence'] ?? [],
                ],
            ]
        );

        $this->completeStep($step, $inputFingerprint, $outputFingerprint, [
            'source' => 'provider_character_analysis',
            'job_id' => $job->id,
            'profile_id' => $profile->id,
        ]);

        $job->automationAttempt?->update([
            'status' => 'approved',
            'output_fingerprint' => $outputFingerprint,
            'validation_result_json' => $validation,
            'approval_type' => 'automatic',
            'completed_at' => now(),
        ]);

        ProductionStudio::log($run->project, 'automation.character_profile.applied', 'تم تطبيق ملف الشخصية تلقائيًا.', [
            'run_id' => $run->id,
            'profile_id' => $profile->id,
        ]);

        AdvanceProductionAutomationRun::dispatch($run->id)->afterCommit();

        return true;
    }

    private function reviewChildReferenceAsset(ProductionAutomationRun $run, ProductionAutomationStep $step, ProductionProjectAsset $asset, string $inputFingerprint): bool
    {
        $identityReview = data_get($asset->metadata_json, 'identity_review');

        if (! is_array($identityReview) || in_array(data_get($identityReview, 'status'), ['queued', 'processing'], true)) {
            $this->markStepRunning($step, $inputFingerprint, ['asset_id' => $asset->id, 'validation' => data_get($identityReview, 'status', 'pending')]);

            return true;
        }

        if (data_get($identityReview, 'status') !== 'completed') {
            return $this->failChildReferenceAttempt($run, $step, $asset, $inputFingerprint, 'identity_validation_failed', 'Child reference validation did not complete successfully.');
        }

        $result = data_get($identityReview, 'result', []);
        $validation = $this->identityValidator->evaluate(is_array($result) ? $result : []);

        if ($validation['decision'] !== 'pass') {
            return $this->failChildReferenceAttempt($run, $step, $asset, $inputFingerprint, $validation['safe_failure_code'], $validation['summary'] ?? 'Child reference validation failed.', $validation);
        }

        $asset->project->assets()
            ->where('asset_type', 'character_sheet')
            ->where('id', '!=', $asset->id)
            ->update(['is_primary' => false]);
        $asset->update([
            'status' => 'approved',
            'is_primary' => true,
            'review_notes' => 'Automatically approved by Phase 2 identity validation.',
            'reviewed_at' => now(),
            'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
        ]);

        $this->completeStep($step, $inputFingerprint, (string) $asset->output_fingerprint, [
            'source' => 'validated_child_reference',
            'asset_id' => $asset->id,
            'validation_score' => $validation['score'] ?? null,
            'blocking_flags' => $validation['blocking_flags'] ?? [],
        ]);

        $asset->automationAttempt?->update([
            'status' => 'approved',
            'output_fingerprint' => $asset->output_fingerprint,
            'validation_result_json' => $validation,
            'approval_type' => 'automatic',
            'completed_at' => now(),
        ]);

        ProductionStudio::log($run->project, 'automation.child_reference.approved', 'تم اعتماد الصورة المرجعية للطفل تلقائيًا.', [
            'run_id' => $run->id,
            'asset_id' => $asset->id,
            'score' => $validation['score'] ?? null,
        ]);

        AdvanceProductionAutomationRun::dispatch($run->id)->afterCommit();

        return true;
    }

    private function startChildReferenceAttempt(ProductionAutomationRun $run, ProductionAutomationStep $step, string $inputFingerprint, int $attemptNumber): bool
    {
        if ($attemptNumber > (int) $step->attempt_limit) {
            $this->pauseStep($run, $step, 'child_reference_attempts_exhausted', 'All automatic child reference attempts failed.');

            return true;
        }

        $modelKey = $attemptNumber >= 3 ? 'premium_fallback' : 'generation';
        $model = $this->modelFromSnapshot($run, $modelKey, 'character_sheet');
        $attempt = $this->createAttempt($run, $step, $inputFingerprint, $model->provider->driver, $model->code, $attemptNumber);
        $outputFingerprint = $this->fingerprints->forArtifact($run, 'child_reference', $this->childReferenceInputs($run) + [
            'attempt_number' => $attemptNumber,
            'model' => $model->code,
        ]);
        $cost = $this->ledger->reserve(
            $run,
            $step,
            $attempt,
            $model->provider->driver,
            $model->code,
            $model->estimatedCost(),
            $this->pricingSnapshot('child_reference_generation', $model) + [
                'attempt_number' => $attemptNumber,
                'premium_fallback' => $attemptNumber >= 3,
            ],
            'automation:'.$attempt->attempt_uuid.':child_reference_generation'
        );

        $profile = $run->project->characterProfile;
        $job = $this->generationJobs->execute($run->project, [
            'model_code' => $model->code,
            'job_type' => 'character_sheet',
            'generation_mode' => 'character_sheet',
            'style_preset' => data_get($run->options_snapshot_json, 'style_preset', config('production_studio.automation.default_style_preset')),
            'generation_quality' => data_get($run->options_snapshot_json, 'generation_quality', config('production_studio.automation.default_generation_quality', 'high')),
            'reference_photo_indices' => $profile?->approvedReferenceIndices() ?? $this->referencePhotoIndices($run),
            'prompt_notes' => $attemptNumber === 2
                ? 'Automatic retry: improve identity fidelity, remove any text/logos/props, keep one child only.'
                : ($attemptNumber >= 3 ? 'Premium fallback attempt: maximize identity fidelity and fail if uncertain.' : null),
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
        ]);

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

    private function failChildReferenceAttempt(ProductionAutomationRun $run, ProductionAutomationStep $step, ProductionProjectAsset $asset, string $inputFingerprint, string $code, string $summary, array $validation = []): bool
    {
        $asset->update([
            'status' => 'rejected',
            'is_primary' => false,
            'rejection_reason' => $summary,
            'reviewed_at' => now(),
        ]);
        $this->failAttempt($asset->automationAttempt, $code, $summary, $validation);

        $nextAttempt = ((int) ($asset->automationAttempt?->attempt_number ?? $step->attempt_number)) + 1;
        if ($nextAttempt > (int) $step->attempt_limit) {
            $this->pauseStep($run, $step, 'child_reference_attempts_exhausted', 'All automatic child reference attempts failed.', [
                ['code' => $code, 'summary' => $summary, 'validation' => $validation],
            ]);

            return true;
        }

        return $this->startChildReferenceAttempt($run->fresh(['project.characterProfile', 'project.assets', 'steps']), $step->fresh(), $inputFingerprint, $nextAttempt);
    }

    private function pauseAtPhase3Boundary(ProductionAutomationRun $run, ProductionAutomationStep $step): bool
    {
        $this->stateMachine->transitionRun($run, ProductionAutomation::STATUS_PAUSED_REVIEW, [
            'pause_reason' => 'phase2_complete_ready_for_phase3',
            'current_stage' => 'cover',
            'current_step_key' => 'cover',
            'safe_failure_code' => 'phase2_complete_ready_for_phase3',
            'safe_failure_summary' => 'Phase 2 automation is complete. Phase 3 cover and scene generation has not been started.',
            'blockers' => [[
                'code' => 'phase2_complete_ready_for_phase3',
                'summary' => 'Phase 2 is complete. Start Phase 3 implementation before submitting cover or scene provider requests.',
            ]],
        ], null, 'phase2_boundary');

        ProductionStudio::log($run->project, 'automation.phase2_completed', 'اكتملت المرحلة الثانية من الإنتاج التلقائي وتوقفت قبل الغلاف والمشاهد.', [
            'run_id' => $run->id,
            'next_step' => $step->step_key,
        ]);

        return true;
    }

    private function createAttempt(ProductionAutomationRun $run, ProductionAutomationStep $step, string $inputFingerprint, ?string $provider, ?string $model, ?int $attemptNumber = null): ProductionAutomationAttempt
    {
        return DB::transaction(function () use ($run, $step, $inputFingerprint, $provider, $model, $attemptNumber): ProductionAutomationAttempt {
            $lockedStep = ProductionAutomationStep::query()->whereKey($step->id)->lockForUpdate()->firstOrFail();
            $number = $attemptNumber ?: (((int) $lockedStep->attempts()->max('attempt_number')) + 1);

            return $lockedStep->attempts()->firstOrCreate(
                ['attempt_number' => $number],
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
                    'submitted_at' => null,
                ]
            );
        });
    }

    private function ensureStoryDraft(ProductionAutomationRun $run, ?ProductionAutomationAttempt $attempt, string $inputFingerprint): ProductionStoryVersion
    {
        $project = $run->project;
        $story = $project->order?->story;
        $content = $story?->full_story ?? $story?->full_desc ?? $story?->short_desc;
        $existing = $project->storyVersions()
            ->where('input_fingerprint', $inputFingerprint)
            ->latest('version_number')
            ->first();

        if ($existing) {
            return $existing;
        }

        $versionNumber = ((int) $project->storyVersions()->max('version_number')) + 1;

        return $project->storyVersions()->create([
            'version_number' => $versionNumber,
            'title' => $story?->title,
            'target_age_group' => $story?->age_range,
            'educational_values_json' => array_values(array_filter([$project->order?->lesson, $story?->lesson_value])),
            'full_story_content' => $content,
            'status' => 'draft',
            'created_by_user_id' => $run->started_by_user_id,
            'production_automation_run_id' => $run->id,
            'production_automation_step_id' => $run->steps->firstWhere('step_key', 'story_preparation')?->id,
            'production_automation_attempt_id' => $attempt?->id,
            'input_fingerprint' => $inputFingerprint,
            'automation_metadata_json' => [
                'source_story_id' => $story?->id,
                'source_story_updated_at' => $story?->updated_at?->toIso8601String(),
                'automation_run_id' => $run->id,
            ],
        ]);
    }

    private function createStructuredJob(ProductionAutomationRun $run, ProductionAutomationStep $step, ProductionAutomationAttempt $attempt, AiModel $model, string $jobType, string $mode, array $inputAssets, int $costEntryId, string $inputFingerprint): SceneGenerationJob
    {
        return $run->project->generationJobs()->create([
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
                'contains_private_images' => (bool) ($inputAssets['contains_private_images'] ?? false),
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

    private function compatibleStoryVersion(ProductionAutomationRun $run, string $inputFingerprint): ?ProductionStoryVersion
    {
        $version = $run->project->storyVersions()
            ->where('input_fingerprint', $inputFingerprint)
            ->whereNotNull('output_fingerprint')
            ->where('status', 'approved')
            ->latest('version_number')
            ->first();

        if (! $version || $run->project->scenes()->count() !== 13) {
            return null;
        }

        return $run->project->scenes()
            ->where('personalization_status', 'personalized')
            ->count() === 13 ? $version : null;
    }

    private function compatibleChildReference(ProductionAutomationRun $run, string $inputFingerprint): ?ProductionProjectAsset
    {
        return $run->project->assets()
            ->where('asset_type', 'character_sheet')
            ->where('status', 'approved')
            ->where('is_primary', true)
            ->where('input_fingerprint', $inputFingerprint)
            ->whereNotNull('output_fingerprint')
            ->latest('version_number')
            ->first();
    }

    private function latestAutomationAsset(ProductionAutomationRun $run, ProductionAutomationStep $step): ?ProductionProjectAsset
    {
        return $run->project->assets()
            ->where('production_automation_run_id', $run->id)
            ->where('production_automation_step_id', $step->id)
            ->where('asset_type', 'character_sheet')
            ->whereIn('status', ['under_review', 'rejected'])
            ->latest('id')
            ->first();
    }

    private function hasActiveAutomationJob(ProductionAutomationRun $run, ProductionAutomationStep $step): bool
    {
        return $run->project->generationJobs()
            ->where('production_automation_run_id', $run->id)
            ->where('production_automation_step_id', $step->id)
            ->whereIn('status', ['queued', 'processing'])
            ->exists();
    }

    private function completedAutomationJob(ProductionAutomationRun $run, ProductionAutomationStep $step): ?SceneGenerationJob
    {
        return $run->project->generationJobs()
            ->with(['automationAttempt'])
            ->where('production_automation_run_id', $run->id)
            ->where('production_automation_step_id', $step->id)
            ->where('status', 'completed')
            ->latest('id')
            ->first();
    }

    private function latestFailedAttempt(ProductionAutomationStep $step): ?ProductionAutomationAttempt
    {
        return $step->attempts()
            ->where('status', 'failed')
            ->latest('attempt_number')
            ->first();
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
        ], $actor, 'phase2');
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
        ], fn ($value): bool => $value !== null), null, 'phase2');
    }

    private function pauseStep(ProductionAutomationRun $run, ProductionAutomationStep $step, string $code, string $summary, array $blockers = []): void
    {
        if (! in_array($step->status, [ProductionAutomation::STEP_WAITING_REVIEW, ProductionAutomation::STEP_FAILED], true)) {
            $this->stateMachine->transitionStep($step, ProductionAutomation::STEP_WAITING_REVIEW, [
                'safe_failure_code' => $code,
                'safe_failure_summary' => $summary,
                'validation_summary_json' => $blockers ?: [['code' => $code, 'summary' => $summary]],
            ], null, 'phase2');
        }

        $this->stateMachine->transitionRun($run, ProductionAutomation::STATUS_PAUSED_REVIEW, [
            'pause_reason' => 'human_review_required',
            'current_stage' => $step->stage,
            'current_step_key' => $step->step_key,
            'safe_failure_code' => $code,
            'safe_failure_summary' => $summary,
            'blockers' => $blockers ?: [['code' => $code, 'summary' => $summary]],
        ], null, 'phase2');
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

    private function resumeRunAfterManualAction(ProductionAutomationRun $run, User $actor, ProductionAutomationStep $step): ProductionAutomationRun
    {
        $run = $run->fresh(['steps', 'project']);

        if (in_array($run->status, ProductionAutomation::pausedStatuses(), true)) {
            $run = $this->stateMachine->transitionRun($run, ProductionAutomation::STATUS_RUNNING, [
                'pause_reason' => null,
                'current_stage' => $step->stage,
                'current_step_key' => $step->step_key,
                'safe_failure_code' => null,
                'safe_failure_summary' => null,
                'blockers' => [],
            ], $actor, 'phase2_manual_review');
        }

        AdvanceProductionAutomationRun::dispatch($run->id)->afterCommit();

        return $run->fresh(['steps', 'project.printLayouts', 'costEntries']);
    }

    private function validateStoryPayload(ProductionAutomationRun $run, array $data): array
    {
        $preview = $this->personalizer->decoratePreview($run->project, ['source' => 'automation', 'data' => $data]);
        $personalization = data_get($preview, 'personalization', []);
        $scenes = data_get($preview, 'personalized_data.scenes', []);

        if (! is_array($scenes) || count($scenes) !== 13) {
            return $this->validationFailure('story_scene_count_invalid', 'Story preparation must produce exactly 13 scenes.');
        }

        if (data_get($personalization, 'confidence') !== 'high' && data_get($personalization, 'confidence') !== 'confirmed') {
            return $this->validationFailure('story_hero_detection_ambiguous', 'Template hero detection is not high confidence.', ['personalization' => $personalization]);
        }

        if (data_get($personalization, 'requires_openai') || data_get($personalization, 'old_hero_name_remaining')) {
            return $this->validationFailure('story_hero_conflict_unresolved', 'Unresolved template hero references remain after personalization.', ['personalization' => $personalization]);
        }

        foreach ($scenes as $index => $scene) {
            foreach (['scene_number', 'written_text', 'visual_direction', 'child_action_pose', 'environment', 'safe_text_area_notes'] as $field) {
                if (blank($scene[$field] ?? null)) {
                    return $this->validationFailure('story_scene_field_missing', 'Scene '.($index + 1)." is missing {$field}.", ['field' => $field, 'scene_index' => $index]);
                }
            }
        }

        return ['ok' => true, 'personalization' => $personalization];
    }

    private function validateProfilePayload(array $data): array
    {
        foreach ([
            'appearance_summary',
            'hair_details',
            'skin_tone',
            'eyes_and_visible_traits',
            'usual_expression',
            'identity_rules',
            'negative_instructions',
        ] as $field) {
            if (blank($data[$field] ?? null)) {
                return $this->validationFailure('character_profile_field_missing', "Character profile is missing {$field}.", ['field' => $field]);
            }
        }

        $fieldConfidence = $data['field_confidence'] ?? null;
        if (! is_array($fieldConfidence)) {
            return $this->validationFailure('character_profile_field_confidence_missing', 'Character profile is missing field-level confidence evidence.');
        }

        foreach ([
            'approximate_age_group',
            'face_shape_notes',
            'skin_tone',
            'hair_details',
            'eyes_and_visible_traits',
            'glasses_or_accessories',
        ] as $field) {
            $confidence = $fieldConfidence[$field] ?? null;
            if (! in_array($confidence, ['high', 'medium'], true)) {
                return $this->validationFailure('character_profile_low_confidence', "Character profile has insufficient confidence for {$field}.", [
                    'field' => $field,
                    'confidence' => $confidence,
                ]);
            }
        }

        $warnings = trim((string) ($data['warnings'] ?? ''));
        if ($warnings !== '' && ! str_contains($warnings, 'لا توجد') && ! str_contains(Str::lower($warnings), 'none')) {
            return $this->validationFailure('character_profile_warning_requires_review', 'Character profile analysis returned warnings that require human review.', ['warnings' => $warnings]);
        }

        return ['ok' => true, 'warnings' => $warnings, 'field_confidence' => $fieldConfidence];
    }

    private function validationFailure(string $code, string $summary, array $extra = []): array
    {
        return ['ok' => false, 'code' => $code, 'summary' => $summary] + $extra;
    }

    private function replaceScenes(ProductionAutomationRun $run, ProductionStoryVersion $version, array $data, array $personalization): void
    {
        $preview = $this->personalizer->decoratePreview($run->project, ['source' => 'automation', 'data' => $data]);
        $scenes = data_get($preview, 'personalized_data.scenes', []);
        $originalScenes = data_get($preview, 'data.scenes', []);

        $run->project->scenes()->delete();

        foreach ($scenes as $index => $scene) {
            $created = $run->project->scenes()->create([
                'production_story_version_id' => $version->id,
                'scene_number' => (int) ($scene['scene_number'] ?? ($index + 1)),
                'title' => $scene['scene_title'] ?? null,
                'story_text' => $scene['written_text'] ?? null,
                'visual_direction' => $scene['visual_direction'] ?? null,
                'child_action_pose' => $scene['child_action_pose'] ?? null,
                'environment' => $scene['environment'] ?? null,
                'mood_lighting' => $scene['mood_lighting'] ?? null,
                'supporting_characters' => $scene['supporting_characters'] ?? null,
                'key_objects' => $scene['key_objects'] ?? null,
                'continuity_notes' => $scene['continuity_notes'] ?? null,
                'text_safe_area_notes' => $scene['safe_text_area_notes'] ?? null,
                'educational_value' => $scene['educational_value'] ?? null,
                'status' => 'draft',
                'ai_sync_status' => 'scenes_need_review',
                'original_template_data_json' => $originalScenes[$index] ?? null,
                'template_hero_name' => data_get($personalization, 'template_hero_name'),
                'personalized_hero_name' => data_get($personalization, 'child_hero_name'),
                'personalization_status' => 'personalized',
                'personalization_warnings' => data_get($personalization, 'warnings', []),
            ]);
            $this->personalizer->refreshSceneStatus($created);
        }

        $run->project->update([
            'template_hero_name' => data_get($personalization, 'template_hero_name'),
            'template_hero_gender' => data_get($personalization, 'template_hero_gender'),
            'personalized_hero_name' => data_get($personalization, 'child_hero_name'),
            'child_story_role' => data_get($personalization, 'child_story_role'),
            'personalization_status' => $run->project->scenes()->where('personalization_status', '!=', 'personalized')->exists() ? 'needs_review' : 'personalized',
            'personalization_warnings' => data_get($personalization, 'warnings', []),
        ]);
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

    private function referencePhotoIndices(ProductionAutomationRun $run): array
    {
        $snapshot = data_get($run->options_snapshot_json, 'reference_photo_indices');
        if (is_array($snapshot) && $snapshot !== []) {
            return array_values(array_unique(array_map('intval', $snapshot)));
        }

        $approved = $run->project->characterProfile?->approvedReferenceIndices() ?? [];
        if ($approved !== []) {
            return $approved;
        }

        return collect($run->project->order?->uploaded_photos ?? [])
            ->keys()
            ->map(fn ($index): int => (int) $index)
            ->values()
            ->all();
    }

    private function storyInputs(ProductionAutomationRun $run): array
    {
        return [
            'child_name' => $run->project->order?->child_name,
            'child_gender' => $run->project->order?->child_gender,
            'child_age' => $run->project->order?->child_age,
            'scene_text_model' => data_get($run->pricing_snapshot_json, 'models.scene_text.model'),
            'prompt_template_version' => config('production_studio.automation.prompt_template_version', 'production-prompt-v1'),
        ];
    }

    private function profileInputs(ProductionAutomationRun $run, array $indices): array
    {
        return [
            'reference_photo_indices' => $indices,
            'validation_model' => data_get($run->pricing_snapshot_json, 'models.validation.model'),
        ];
    }

    private function childReferenceInputs(ProductionAutomationRun $run): array
    {
        $storyVersion = $run->project->storyVersions()->where('status', 'approved')->latest('version_number')->first();

        return [
            'production_story_version_id' => $storyVersion?->id,
            'production_story_fingerprint' => $storyVersion?->output_fingerprint,
            'profile_fingerprint' => $run->project->characterProfile?->output_fingerprint,
            'style_preset' => data_get($run->options_snapshot_json, 'style_preset', config('production_studio.automation.default_style_preset')),
            'generation_quality' => data_get($run->options_snapshot_json, 'generation_quality', config('production_studio.automation.default_generation_quality', 'high')),
            'generation_model' => data_get($run->pricing_snapshot_json, 'models.generation.model'),
            'premium_model' => data_get($run->pricing_snapshot_json, 'models.premium_fallback.model'),
        ];
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
}
