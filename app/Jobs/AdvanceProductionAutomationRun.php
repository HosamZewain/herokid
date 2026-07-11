<?php

namespace App\Jobs;

use App\Models\ProductionAutomationRun;
use App\Services\ProductionStudio\ProductionAutomationFingerprint;
use App\Services\ProductionStudio\ProductionAutomationLayoutValidator;
use App\Services\ProductionStudio\ProductionAutomationPhase2Service;
use App\Services\ProductionStudio\ProductionAutomationPhase3Service;
use App\Services\ProductionStudio\ProductionAutomationPhase4Service;
use App\Services\ProductionStudio\ProductionAutomationRunService;
use App\Services\ProductionStudio\ProductionAutomationStateMachine;
use App\Support\ProductionAutomation;
use App\Support\ProductionStudio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdvanceProductionAutomationRun implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries;

    public int $maxExceptions;

    public function __construct(public int $automationRunId)
    {
        $this->timeout = (int) config('production_studio.automation.queue.job_timeout', 300);
        $this->tries = (int) config('production_studio.automation.queue.tries', 1);
        $this->maxExceptions = (int) config('production_studio.automation.queue.max_exceptions', 1);
    }

    public function backoff(): array
    {
        return config('production_studio.automation.queue.backoff', [30, 90, 180]);
    }

    public function handle(
        ProductionAutomationStateMachine $stateMachine,
        ProductionAutomationRunService $runs,
        ProductionAutomationFingerprint $fingerprints,
        ProductionAutomationPhase2Service $phase2,
        ProductionAutomationPhase3Service $phase3,
        ProductionAutomationPhase4Service $phase4,
    ): void {
        $run = ProductionAutomationRun::query()
            ->with(['project.storyVersions', 'project.characterProfile', 'project.scenes', 'steps'])
            ->findOrFail($this->automationRunId);

        if (! ProductionAutomation::enabled()) {
            return;
        }

        if ($run->isTerminal()) {
            return;
        }

        try {
            DB::transaction(function () use ($run): void {
                ProductionAutomationRun::query()
                    ->whereKey($run->id)
                    ->lockForUpdate()
                    ->update(['last_heartbeat_at' => now()]);
            });

            $runs->seedSteps($run);
            $run = $run->fresh(['project.storyVersions', 'project.characterProfile', 'project.scenes', 'steps']);

            if ($run->status === ProductionAutomation::STATUS_PAUSED_REVIEW
                && $run->safe_failure_code === 'phase2_complete_ready_for_phase3') {
                $run = $stateMachine->transitionRun($run, ProductionAutomation::STATUS_RUNNING, [
                    'pause_reason' => null,
                    'current_stage' => 'cover',
                    'current_step_key' => 'cover',
                    'safe_failure_code' => null,
                    'safe_failure_summary' => null,
                    'blockers' => [],
                ], null, 'phase3_boundary_resume');
            }

            if ($run->status === ProductionAutomation::STATUS_PAUSED_REVIEW
                && $run->safe_failure_code === 'phase3_complete_ready_for_layout') {
                $run = $stateMachine->transitionRun($run, ProductionAutomation::STATUS_RUNNING, [
                    'pause_reason' => null,
                    'current_stage' => 'layout_print',
                    'current_step_key' => 'layout_print',
                    'safe_failure_code' => null,
                    'safe_failure_summary' => null,
                    'blockers' => [],
                ], null, 'phase4_boundary_resume');
            }

            if ($run->status === ProductionAutomation::STATUS_QUEUED) {
                $run = $stateMachine->transitionRun($run, ProductionAutomation::STATUS_RUNNING, [
                    'current_stage' => 'story_preparation',
                    'current_step_key' => 'story_preparation',
                ], null, 'advance_job');
            }

            if ($phase2->advance($run)) {
                return;
            }

            $run = $run->fresh(['project.storyVersions', 'project.characterProfile', 'project.scenes', 'project.assets', 'steps']);
            if ($phase3->advance($run)) {
                return;
            }

            $run = $run->fresh(['project.storyVersions', 'project.characterProfile', 'project.scenes.approvedFinalImage', 'project.assets', 'project.printLayouts', 'steps', 'costEntries']);
            if ($phase4->advance($run)) {
                return;
            }

            $next = $run->steps
                ->whereNotIn('status', ProductionAutomation::progressCompleteStepStatuses())
                ->whereNotIn('status', [ProductionAutomation::STEP_FAILED, ProductionAutomation::STEP_CANCELLED])
                ->sortBy('sequence')
                ->first();

            if (! $next) {
                return;
            }

            $blocker = $this->evaluateExistingReadiness($run, $next->step_key, $fingerprints);

            if ($blocker === null) {
                $stateMachine->transitionStep($next, ProductionAutomation::STEP_COMPLETED, [
                    'approval_type' => 'automatic',
                    'validation_summary_json' => ['source' => 'existing_compatible_records'],
                ], null, 'advance_job');

                self::dispatch($run->id)->afterCommit();

                return;
            }

            $stateMachine->transitionStep($next, ProductionAutomation::STEP_WAITING_REVIEW, [
                'safe_failure_code' => $blocker['code'],
                'safe_failure_summary' => $blocker['summary'],
                'validation_summary_json' => $blocker,
            ], null, 'advance_job');

            $stateMachine->transitionRun($run, ProductionAutomation::STATUS_PAUSED_REVIEW, [
                'pause_reason' => 'human_review_required',
                'current_stage' => $next->stage,
                'current_step_key' => $next->step_key,
                'blockers' => [$blocker],
                'safe_failure_code' => $blocker['code'],
                'safe_failure_summary' => $blocker['summary'],
            ], null, 'advance_job');
        } catch (Throwable $exception) {
            $run = $run->fresh(['project']);
            ProductionStudio::log($run->project, 'automation.advance_failed', 'تعذر تقدم دورة الإنتاج التلقائي بأمان.', [
                'run_id' => $run->id,
                'error' => $this->safeMessage($exception),
            ]);

            $stateMachine->transitionRun($run, ProductionAutomation::STATUS_PAUSED_RECOVERABLE, [
                'pause_reason' => 'recoverable_orchestration_error',
                'safe_failure_code' => 'advance_job_error',
                'safe_failure_summary' => $this->safeMessage($exception),
            ], null, 'advance_job');
        }
    }

    private function evaluateExistingReadiness(ProductionAutomationRun $run, string $stepKey, ProductionAutomationFingerprint $fingerprints): ?array
    {
        $project = $run->project;

        return match (true) {
            $stepKey === 'preflight' => null,
            $stepKey === 'story_preparation' => $project->storyVersions->isNotEmpty() && $project->scenes->count() === 13
                ? null
                : $this->blocker('story_preparation_review_required', 'Create or review a production-specific story draft and exactly 13 scenes.'),
            $stepKey === 'character_profile' => $project->characterProfile?->isReadyForAiGeneration()
                ? null
                : $this->blocker('character_profile_review_required', 'Complete the child character profile and approve private reference photos.'),
            $stepKey === 'child_reference' => $project->assets()
                ->where('asset_type', 'character_sheet')
                ->where('status', 'approved')
                ->where('is_primary', true)
                ->where('output_fingerprint', $fingerprints->forArtifact($run, 'child_reference', $this->fingerprintInputs($run, 'child_reference')))
                ->exists()
                ? null
                : $this->blocker('compatible_child_reference_required', 'Approve a compatible fingerprinted child reference illustration.'),
            $stepKey === 'cover' => $project->assets()
                ->where('asset_type', 'cover_image')
                ->where('status', 'approved')
                ->where('is_final', true)
                ->where('output_fingerprint', $fingerprints->forArtifact($run, 'cover', $this->fingerprintInputs($run, 'cover')))
                ->exists()
                ? null
                : $this->blocker('compatible_cover_required', 'Approve a compatible fingerprinted cover image.'),
            str_starts_with($stepKey, 'scene_') => $this->sceneBlocker($run, $stepKey, $fingerprints),
            $stepKey === 'layout_print' => $project->printLayouts()
                ->where('status', 'ready')
                ->where('output_fingerprint', $fingerprints->forArtifact($run, 'layout_print', $this->fingerprintInputs($run, 'layout_print')))
                ->exists()
                ? null
                : $this->blocker('compatible_layout_files_required', 'Generate compatible private reader, imposed print, manifest, and proof files.'),
            $stepKey === 'final_proof' => $this->blocker('final_human_proof_required', 'Final human proof approval is mandatory before completion.'),
            default => $this->blocker('unknown_step', 'Automation step is not recognized.'),
        };
    }

    private function sceneBlocker(ProductionAutomationRun $run, string $stepKey, ProductionAutomationFingerprint $fingerprints): ?array
    {
        $sceneNumber = (int) substr($stepKey, -2);
        $scene = $run->project->scenes->firstWhere('scene_number', $sceneNumber);

        if (! $scene) {
            return $this->blocker('scene_missing', "Scene {$sceneNumber} is missing.");
        }

        $hasCompatibleFinal = $run->project->assets()
            ->where('asset_type', 'scene_image')
            ->where('production_scene_id', $scene->id)
            ->where('status', 'approved')
            ->where('is_final', true)
            ->where('output_fingerprint', $fingerprints->forArtifact($run, 'scene_image', $this->fingerprintInputs($run, 'scene_image'), $scene))
            ->exists();

        return $hasCompatibleFinal ? null : $this->blocker('compatible_scene_image_required', "Scene {$sceneNumber} requires a compatible fingerprinted approved image.");
    }

    private function fingerprintInputs(ProductionAutomationRun $run, string $artifactType): array
    {
        $options = $run->options_snapshot_json ?? [];

        return [
            'artifact_type' => $artifactType,
            'provider' => $options['provider'] ?? null,
            'generation_model_code' => $options['generation_model_code'] ?? null,
            'cover_model_code' => $options['cover_model_code'] ?? null,
            'premium_model_code' => $options['premium_model_code'] ?? null,
            'validation_model_code' => $options['validation_model_code'] ?? null,
            'scene_text_model_code' => $options['scene_text_model_code'] ?? null,
            'style_preset' => $options['style_preset'] ?? config('production_studio.ai.default_style_preset'),
            'generation_quality' => $options['generation_quality'] ?? config('production_studio.automation.default_generation_quality', 'high'),
            'orientation' => match ($artifactType) {
                'cover' => 'portrait',
                'scene_image' => 'landscape',
                default => null,
            },
            'reader_pages' => $artifactType === 'layout_print' ? 28 : null,
            'imposed_a3_sheets' => $artifactType === 'layout_print' ? 7 : null,
            'imposed_pdf_pages' => $artifactType === 'layout_print' ? 14 : null,
            'page_map_version' => $artifactType === 'layout_print' ? ProductionAutomationLayoutValidator::PAGE_MAP_VERSION : null,
        ];
    }

    private function blocker(string $code, string $summary): array
    {
        return [
            'code' => $code,
            'summary' => $summary,
        ];
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = preg_replace('/(?:\/[^\s]+)+/', '[path redacted]', $exception->getMessage());
        $message = preg_replace('/Bearer\s+[A-Za-z0-9_\-:.]+/', 'Bearer [redacted]', $message ?: '');
        $message = preg_replace('/Key\s+[A-Za-z0-9_\-:.]+/', 'Key [redacted]', $message ?: '');

        return $message ?: 'Automation advance failed.';
    }
}
