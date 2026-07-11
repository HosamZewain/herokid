<?php

namespace App\Services\ProductionStudio;

use App\Jobs\AdvanceProductionAutomationRun;
use App\Jobs\GenerateProductionLayoutJob;
use App\Models\ProductionAutomationRun;
use App\Models\ProductionAutomationStep;
use App\Models\ProductionPrintLayout;
use App\Models\ProductionProjectAsset;
use App\Support\ProductionAutomation;
use App\Support\ProductionStudio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;

class ProductionAutomationPhase4Service
{
    public function __construct(
        private readonly ProductionAutomationStateMachine $stateMachine,
        private readonly ProductionAutomationFingerprint $fingerprints,
        private readonly ProductionLayoutBuilder $builder,
        private readonly ProductionAutomationLayoutValidator $validator,
    ) {}

    public function advance(ProductionAutomationRun $run): bool
    {
        $run = $run->fresh([
            'project.order.story',
            'project.storyVersions',
            'project.scenes.approvedFinalImage',
            'project.assets',
            'project.printLayouts',
            'project.generationJobs',
            'steps',
            'attempts',
            'costEntries',
        ]);

        if (! $run || $run->status !== ProductionAutomation::STATUS_RUNNING) {
            return true;
        }

        $step = $run->steps
            ->whereNotIn('status', ProductionAutomation::progressCompleteStepStatuses())
            ->whereNotIn('status', [ProductionAutomation::STEP_FAILED, ProductionAutomation::STEP_CANCELLED])
            ->sortBy('sequence')
            ->first();

        if (! $step || $step->step_key !== 'layout_print') {
            return false;
        }

        return $this->advanceLayoutPrint($run, $step);
    }

    public function retryLayout(ProductionAutomationRun $run, ProductionPrintLayout $layout, $actor, string $reason): ProductionAutomationRun
    {
        return DB::transaction(function () use ($run, $layout, $actor, $reason): ProductionAutomationRun {
            $run = ProductionAutomationRun::query()->with(['project', 'steps'])->lockForUpdate()->findOrFail($run->id);
            $layout = ProductionPrintLayout::query()->whereKey($layout->id)->lockForUpdate()->firstOrFail();

            if ($layout->production_project_id !== $run->production_project_id) {
                throw new \RuntimeException('Layout does not belong to this automation run.');
            }

            $step = $run->steps->firstWhere('step_key', 'layout_print');
            if (! $step) {
                throw new \RuntimeException('Layout step is missing.');
            }

            $layout->update([
                'status' => 'draft',
                'error_message' => null,
            ]);

            $this->stateMachine->transitionStep($step, ProductionAutomation::STEP_QUEUED, [
                'manual_invalidation' => true,
                'safe_failure_code' => null,
                'safe_failure_summary' => null,
                'metadata_json' => [
                    'manual_phase4_retry_reason' => $reason,
                    'previous_layout_id' => $layout->id,
                ],
            ], $actor, 'phase4_manual_retry');

            if ($run->status !== ProductionAutomation::STATUS_RUNNING) {
                $run = $this->stateMachine->transitionRun($run, ProductionAutomation::STATUS_RUNNING, [
                    'current_stage' => 'layout_print',
                    'current_step_key' => 'layout_print',
                    'safe_failure_code' => null,
                    'safe_failure_summary' => null,
                    'blockers' => [],
                ], $actor, 'phase4_manual_retry');
            }

            ProductionStudio::log($run->project, 'automation.phase4_retry_requested', 'تم طلب إعادة توليد ملفات المرحلة الرابعة.', [
                'run_id' => $run->id,
                'layout_id' => $layout->id,
                'reason' => $reason,
            ], $actor);

            AdvanceProductionAutomationRun::dispatch($run->id)->afterCommit();

            return $run->fresh(['steps', 'project.printLayouts', 'costEntries']);
        });
    }

    private function advanceLayoutPrint(ProductionAutomationRun $run, ProductionAutomationStep $step): bool
    {
        $preconditions = $this->preconditions($run);
        if (! $preconditions['ok']) {
            $this->pauseForReview($run, $step, 'layout_preconditions_failed', 'Layout prerequisites are not satisfied.', $preconditions['blockers']);

            return true;
        }

        $inputFingerprint = $this->layoutInputFingerprint($run, $preconditions['inputs']);
        $compatible = $this->compatibleLayout($run, $inputFingerprint);

        if ($compatible) {
            $this->completePhase4($run, $step, $compatible, $inputFingerprint);

            return true;
        }

        $failed = $this->failedLayout($run, $inputFingerprint);
        if ($failed) {
            $this->pauseForReview($run, $step, 'layout_generation_failed', $failed->error_message ?: 'Layout generation or validation failed.', [[
                'code' => 'layout_generation_failed',
                'summary' => $failed->error_message ?: 'Layout generation or validation failed.',
                'layout_id' => $failed->id,
                'available_actions' => ['regenerate_reader_pdf', 'regenerate_imposed_pdf', 'retry_failed_validation', 'manual_layout_correction'],
            ]]);

            return true;
        }

        $active = $this->activeLayout($run, $inputFingerprint);
        if ($active) {
            if ($this->layoutIsStalled($active)) {
                $active->update(['status' => 'queued', 'error_message' => null]);
                GenerateProductionLayoutJob::dispatch($active->id)->afterCommit();
            }

            $this->markStepRunning($step, $inputFingerprint, $active);

            return true;
        }

        $layout = $this->queueLayout($run, $step, $inputFingerprint);
        $this->markStepRunning($step, $inputFingerprint, $layout);

        return true;
    }

    private function preconditions(ProductionAutomationRun $run): array
    {
        $project = $run->project;
        $blockers = [];
        $inputs = [
            'feature_flags' => [
                'production_studio' => ProductionStudio::enabled(),
                'automation' => ProductionAutomation::enabled(),
            ],
        ];

        if (! ProductionAutomation::enabled()) {
            $blockers[] = $this->blocker('automation_feature_disabled', 'Production Studio automation is disabled.');
        }

        $settings = $this->builder->normalizedSettings($project, $this->builder->defaults($project));
        $readiness = $this->builder->readiness($project, $settings);
        if (! $readiness['ready']) {
            foreach ($readiness['errors'] as $error) {
                $blockers[] = $this->blocker('layout_builder_not_ready', $error);
            }
        }

        $cover = $project->assets
            ->where('asset_type', 'cover_image')
            ->where('status', 'approved')
            ->where('is_final', true)
            ->sortByDesc('version_number')
            ->first();

        if (! $cover) {
            $blockers[] = $this->blocker('approved_cover_missing', 'One approved final cover is required before layout.');
        } elseif (! $this->assetReadable($cover)) {
            $blockers[] = $this->blocker('approved_cover_unreadable', 'The approved cover asset is not readable from private storage.');
        } elseif (blank($cover->output_fingerprint)) {
            $blockers[] = $this->blocker('approved_cover_not_fingerprinted', 'The approved cover does not have a compatible output fingerprint.');
        }

        $inputs['cover'] = $cover ? $this->assetFingerprintInput($cover) : null;

        $sceneNumbers = $project->scenes->pluck('scene_number')->map(fn ($number): int => (int) $number)->sort()->values()->all();
        if ($sceneNumbers !== range(1, ProductionLayoutBuilder::SCENE_COUNT)) {
            $blockers[] = $this->blocker('scene_numbers_invalid', 'Scenes must be exactly numbered 1 through 13 before layout.');
        }

        $sceneInputs = [];
        foreach ($project->scenes->sortBy('scene_number') as $scene) {
            if (blank($scene->story_text)) {
                $blockers[] = $this->blocker('scene_text_missing', "Scene {$scene->scene_number} has no approved story text.", ['scene_number' => $scene->scene_number]);
            }

            $approvedCount = $project->assets
                ->where('asset_type', 'scene_image')
                ->where('production_scene_id', $scene->id)
                ->where('status', 'approved')
                ->where('is_final', true)
                ->count();
            $asset = $scene->approvedFinalImage;

            if ($approvedCount !== 1 || ! $asset || $asset->status !== 'approved') {
                $blockers[] = $this->blocker('scene_primary_image_invalid', "Scene {$scene->scene_number} must have exactly one approved primary image.", ['scene_number' => $scene->scene_number]);
            } elseif (! $this->assetReadable($asset)) {
                $blockers[] = $this->blocker('scene_primary_image_unreadable', "Scene {$scene->scene_number} image is not readable from private storage.", ['scene_number' => $scene->scene_number]);
            } elseif (blank($asset->output_fingerprint)) {
                $blockers[] = $this->blocker('scene_primary_image_not_fingerprinted', "Scene {$scene->scene_number} image is not fingerprinted.", ['scene_number' => $scene->scene_number]);
            }

            $sceneInputs[] = [
                'scene_id' => $scene->id,
                'scene_number' => (int) $scene->scene_number,
                'story_text_hash' => hash('sha256', (string) $scene->story_text),
                'scene_hash' => $this->fingerprints->hash([
                    'story_text' => $scene->story_text,
                    'visual_direction' => $scene->visual_direction,
                    'child_action_pose' => $scene->child_action_pose,
                    'environment' => $scene->environment,
                    'safe_text_area_notes' => $scene->text_safe_area_notes,
                ]),
                'asset' => $asset ? $this->assetFingerprintInput($asset) : null,
            ];
        }
        $inputs['scenes'] = $sceneInputs;

        $activeProviderJobs = $project->generationJobs
            ->where('production_automation_run_id', $run->id)
            ->whereIn('job_type', ['cover_image', 'scene_image', 'scene_improvement', 'identity_review'])
            ->whereIn('status', ['queued', 'processing'])
            ->count();
        if ($activeProviderJobs > 0) {
            $blockers[] = $this->blocker('active_provider_attempts_exist', 'Cover or scene provider work is still active.');
        }

        $unreconciledCosts = $run->costEntries->whereIn('status', ['reserved', 'unknown'])->count();
        if ($unreconciledCosts > 0) {
            $blockers[] = $this->blocker('unreconciled_cost_entries_exist', 'Required cost reservations are not fully reconciled.');
        }

        $phase3Incomplete = $run->steps
            ->filter(fn (ProductionAutomationStep $candidate): bool => $candidate->step_key === 'cover' || str_starts_with($candidate->step_key, 'scene_'))
            ->reject(fn (ProductionAutomationStep $candidate): bool => $candidate->status === ProductionAutomation::STEP_COMPLETED)
            ->values();
        if ($phase3Incomplete->isNotEmpty()) {
            $blockers[] = $this->blocker('phase3_steps_incomplete', 'Cover and all 13 scene steps must be completed before layout.');
        }

        if (! class_exists(Mpdf::class)) {
            $blockers[] = $this->blocker('pdf_renderer_missing', 'The configured mPDF renderer is not installed.');
        }

        if (! $this->storageWritable($project->id)) {
            $blockers[] = $this->blocker('private_output_storage_unwritable', 'Private output storage is not writable.');
        }

        $manifest = $this->builder->buildManifest($settings);
        $inputs += [
            'story_version' => $project->storyVersions->sortByDesc('version_number')->first()?->only(['id', 'version_number', 'output_fingerprint']),
            'layout_template_version' => config('production_studio.automation.layout_template_version', 'layout-print-v1'),
            'page_map_version' => ProductionAutomationLayoutValidator::PAGE_MAP_VERSION,
            'reader_pages' => ProductionLayoutBuilder::PAGE_COUNT,
            'imposed_a3_sheets' => ProductionLayoutBuilder::SHEET_COUNT,
            'imposed_pdf_pages' => ProductionLayoutBuilder::SHEET_COUNT * 2,
            'pdf_page_representation' => data_get($manifest, 'pdf_page_representation'),
            'binding_direction' => $settings['binding_direction'] ?? 'rtl',
            'duplex_flip' => $settings['duplex_flip'] ?? 'short_edge',
            'font_package_version' => ProductionAutomationLayoutValidator::FONT_PACKAGE_VERSION,
            'renderer_version' => ProductionAutomationLayoutValidator::RENDERER_VERSION,
            'locale' => 'ar',
            'rtl' => true,
            'margins' => 'zero full-bleed canvas with fixed text safe panels',
            'dpi_policy' => [
                'minimum_effective_dpi' => config('production_studio.automation.phase4.min_effective_dpi', 180),
                'policy' => config('production_studio.automation.phase4.dpi_policy', 'warn'),
            ],
        ];

        return [
            'ok' => $blockers === [],
            'blockers' => $blockers,
            'inputs' => $inputs,
        ];
    }

    private function layoutInputFingerprint(ProductionAutomationRun $run, array $inputs): string
    {
        return $this->fingerprints->forArtifact($run, 'layout_print', [
            'phase' => 4,
            'layout_inputs' => $inputs,
        ]);
    }

    private function compatibleLayout(ProductionAutomationRun $run, string $inputFingerprint): ?ProductionPrintLayout
    {
        return $run->project->printLayouts()
            ->where('status', 'ready')
            ->where('input_fingerprint', $inputFingerprint)
            ->whereNotNull('output_fingerprint')
            ->latest('version_number')
            ->get()
            ->first(fn (ProductionPrintLayout $layout): bool => $layout->isValidatedAutomationReady());
    }

    private function failedLayout(ProductionAutomationRun $run, string $inputFingerprint): ?ProductionPrintLayout
    {
        return $run->project->printLayouts()
            ->where('production_automation_run_id', $run->id)
            ->where('input_fingerprint', $inputFingerprint)
            ->where('status', 'failed')
            ->latest('id')
            ->first();
    }

    private function activeLayout(ProductionAutomationRun $run, string $inputFingerprint): ?ProductionPrintLayout
    {
        return $run->project->printLayouts()
            ->where('production_automation_run_id', $run->id)
            ->where('input_fingerprint', $inputFingerprint)
            ->whereIn('status', ['queued', 'processing', 'validating'])
            ->latest('id')
            ->first();
    }

    private function queueLayout(ProductionAutomationRun $run, ProductionAutomationStep $step, string $inputFingerprint): ProductionPrintLayout
    {
        return DB::transaction(function () use ($run, $step, $inputFingerprint): ProductionPrintLayout {
            $lockedRun = ProductionAutomationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $lockedStep = ProductionAutomationStep::query()->whereKey($step->id)->lockForUpdate()->firstOrFail();
            $project = $lockedRun->project()->with(['scenes', 'assets'])->firstOrFail();

            $existing = $project->printLayouts()
                ->where('production_automation_run_id', $lockedRun->id)
                ->where('input_fingerprint', $inputFingerprint)
                ->whereIn('status', ['queued', 'processing', 'validating'])
                ->latest('id')
                ->first();
            if ($existing) {
                return $existing;
            }

            $attemptNumber = max(1, (int) $lockedStep->attempt_number);
            $highestAttempt = (int) $lockedStep->attempts()->max('attempt_number');
            if ($attemptNumber <= $highestAttempt) {
                $attemptNumber = $highestAttempt + 1;
            }

            $attempt = $lockedStep->attempts()->create([
                'automation_run_id' => $lockedRun->id,
                'attempt_uuid' => (string) Str::uuid(),
                'attempt_number' => $attemptNumber,
                'run_version' => $lockedRun->version,
                'orchestration_generation' => $lockedRun->orchestration_generation,
                'status' => 'queued',
                'provider' => 'local',
                'model' => 'mpdf-layout',
                'input_fingerprint' => $inputFingerprint,
                'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
                'input_summary_json' => [
                    'reader_pages' => ProductionLayoutBuilder::PAGE_COUNT,
                    'imposed_a3_sheets' => ProductionLayoutBuilder::SHEET_COUNT,
                    'imposed_pdf_pages' => ProductionLayoutBuilder::SHEET_COUNT * 2,
                ],
                'heartbeat_at' => now(),
                'started_by_user_id' => $lockedRun->started_by_user_id,
            ]);

            $latestVersion = (int) $project->printLayouts()->lockForUpdate()->max('version_number');
            $layout = $project->printLayouts()->create([
                'version_number' => $latestVersion + 1,
                'status' => 'queued',
                'settings_json' => $this->builder->defaults($project),
                'generated_by_user_id' => $lockedRun->started_by_user_id,
                'production_automation_run_id' => $lockedRun->id,
                'production_automation_step_id' => $lockedStep->id,
                'production_automation_attempt_id' => $attempt->id,
                'input_fingerprint' => $inputFingerprint,
                'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
            ]);

            GenerateProductionLayoutJob::dispatch($layout->id)->afterCommit();

            return $layout;
        });
    }

    private function completePhase4(ProductionAutomationRun $run, ProductionAutomationStep $step, ProductionPrintLayout $layout, string $inputFingerprint): void
    {
        $validation = $layout->manifest_json['validation'] ?? [];
        $outputFingerprint = (string) $layout->output_fingerprint;

        if ($step->status !== ProductionAutomation::STEP_COMPLETED) {
            $this->stateMachine->transitionStep($step, ProductionAutomation::STEP_COMPLETED, [
                'input_fingerprint' => $inputFingerprint,
                'output_fingerprint' => $outputFingerprint,
                'attempt_number' => $layout->automationAttempt?->attempt_number ?? $step->attempt_number,
                'approval_type' => 'automatic',
                'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
                'validation_summary_json' => [
                    'source' => 'phase4_layout_validation',
                    'layout_id' => $layout->id,
                    'reader_pdf' => data_get($layout->manifest_json, 'files.reader_pdf'),
                    'print_pdf' => data_get($layout->manifest_json, 'files.print_pdf'),
                    'manifest' => data_get($layout->manifest_json, 'files.manifest'),
                    'proof_checklist' => data_get($layout->manifest_json, 'files.proof_checklist'),
                    'warnings' => data_get($validation, 'warnings', []),
                ],
            ], null, 'phase4');
        }

        $finalProof = $run->steps()->where('step_key', 'final_proof')->first();
        if ($finalProof && $finalProof->status === ProductionAutomation::STEP_PENDING) {
            $this->stateMachine->transitionStep($finalProof, ProductionAutomation::STEP_WAITING_REVIEW, [
                'safe_failure_code' => 'final_human_proof_required',
                'safe_failure_summary' => 'Final human proof approval is required before completion.',
                'validation_summary_json' => [
                    'source' => 'phase4_boundary',
                    'proof_checklist_generated' => true,
                    'layout_id' => $layout->id,
                ],
            ], null, 'phase4');
        }

        $this->stateMachine->transitionRun($run, ProductionAutomation::STATUS_FILES_READY, [
            'current_stage' => 'quality_check',
            'current_step_key' => 'final_proof',
            'safe_failure_code' => 'phase4_files_ready_waiting_final_proof',
            'safe_failure_summary' => 'Reader PDF, imposed A3 PDF, manifest, and proof checklist are ready for final human proof.',
            'blockers' => [[
                'code' => 'final_human_proof_required',
                'summary' => 'Final human proof remains mandatory before completion.',
                'step_key' => 'final_proof',
            ]],
        ], null, 'phase4');

        ProductionStudio::log($run->project, 'automation.phase4_files_ready', 'تم تجهيز ملفات المرحلة الرابعة وتنتظر المراجعة النهائية البشرية.', [
            'run_id' => $run->id,
            'layout_id' => $layout->id,
            'output_fingerprint' => $outputFingerprint,
        ]);
    }

    private function markStepRunning(ProductionAutomationStep $step, string $inputFingerprint, ProductionPrintLayout $layout): void
    {
        $this->stateMachine->transitionStep($step, ProductionAutomation::STEP_RUNNING, [
            'input_fingerprint' => $inputFingerprint,
            'attempt_number' => $layout->automationAttempt?->attempt_number ?? max(1, (int) $step->attempt_number),
            'metadata_json' => [
                'layout_id' => $layout->id,
                'layout_version' => $layout->version_number,
                'layout_status' => $layout->status,
                'reader_pages' => ProductionLayoutBuilder::PAGE_COUNT,
                'imposed_a3_sheets' => ProductionLayoutBuilder::SHEET_COUNT,
                'imposed_pdf_pages' => ProductionLayoutBuilder::SHEET_COUNT * 2,
            ],
        ], null, 'phase4');
    }

    private function pauseForReview(ProductionAutomationRun $run, ProductionAutomationStep $step, string $code, string $summary, array $blockers): void
    {
        if ($step->status !== ProductionAutomation::STEP_WAITING_REVIEW) {
            $this->stateMachine->transitionStep($step, ProductionAutomation::STEP_WAITING_REVIEW, [
                'safe_failure_code' => $code,
                'safe_failure_summary' => $summary,
                'validation_summary_json' => [
                    'code' => $code,
                    'summary' => $summary,
                    'blockers' => $blockers,
                ],
            ], null, 'phase4');
        }

        $this->stateMachine->transitionRun($run, ProductionAutomation::STATUS_PAUSED_REVIEW, [
            'pause_reason' => 'phase4_review_required',
            'current_stage' => 'layout_print',
            'current_step_key' => 'layout_print',
            'safe_failure_code' => $code,
            'safe_failure_summary' => $summary,
            'blockers' => $blockers,
        ], null, 'phase4');
    }

    private function layoutIsStalled(ProductionPrintLayout $layout): bool
    {
        $timeout = (int) config('production_studio.automation.phase4.layout_job_stale_minutes', 15);

        return $layout->updated_at?->lt(now()->subMinutes($timeout)) ?? false;
    }

    private function storageWritable(int $projectId): bool
    {
        $path = "production-studio/projects/{$projectId}/layout/.automation-write-test";

        try {
            Storage::disk('local')->put($path, 'ok');
            Storage::disk('local')->delete($path);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assetReadable(ProductionProjectAsset $asset): bool
    {
        return is_string($asset->file_path)
            && ! str_contains($asset->file_path, '..')
            && Storage::disk('local')->exists($asset->file_path);
    }

    private function assetFingerprintInput(ProductionProjectAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'version_number' => $asset->version_number,
            'asset_type' => $asset->asset_type,
            'output_fingerprint' => $asset->output_fingerprint,
            'content_hash' => $this->assetReadable($asset) ? hash('sha256', Storage::disk('local')->get($asset->file_path)) : null,
        ];
    }

    private function blocker(string $code, string $summary, array $extra = []): array
    {
        return [
            'code' => $code,
            'summary' => $summary,
        ] + $extra;
    }
}
