<?php

namespace App\Services\ProductionStudio;

use App\Models\ProductionAutomationProof;
use App\Models\ProductionAutomationRun;
use App\Models\ProductionPrintLayout;
use App\Models\User;
use App\Support\ProductionAutomation;
use Illuminate\Support\Facades\URL;

class ProductionAutomationStatusPresenter
{
    public function __construct(
        private readonly ProductionAutomationProgress $progress,
        private readonly ProductionAutomationCostLedger $ledger,
        private readonly ProductionAutomationFinalProofService $finalProofs,
    ) {}

    public function present(ProductionAutomationRun $run, User $user): array
    {
        $run->loadMissing([
            'project.order',
            'project.storyVersions',
            'project.characterProfile',
            'project.scenes',
            'project.assets.automationAttempt',
            'steps.scene',
            'attempts.step',
            'costEntries',
            'project.printLayouts',
            'currentProof.layout',
            'currentProof.reviewer',
            'proofs.layout',
            'proofs.reviewer',
        ]);

        return [
            'schema_version' => 2,
            'run' => [
                'id' => $run->id,
                'project_id' => $run->production_project_id,
                'status' => $run->status,
                'current_stage' => $run->current_stage,
                'current_step_key' => $run->current_step_key,
                'progress' => $this->progress->percentage($run),
                'version' => $run->version,
                'orchestration_generation' => $run->orchestration_generation,
                'pause_reason' => $run->pause_reason,
                'safe_failure_code' => $run->safe_failure_code,
                'safe_failure_summary' => $run->safe_failure_summary,
                'updated_at' => $run->updated_at?->toIso8601String(),
            ],
            'blockers' => $run->blockers_json ?? [],
            'costs' => $user->hasPermission('production_studio.automation_view_costs') ? $this->ledger->summary($run) : null,
            'timing' => $this->progress->timing($run),
            'phase2' => $this->phase2($run, $user),
            'phase3' => $this->phase3($run, $user),
            'phase4' => $this->phase4($run, $user),
            'phase5' => $this->phase5($run, $user),
            'scenes' => $this->sceneSummaries($run),
            'steps' => $run->steps->map(fn ($step): array => [
                'key' => $step->step_key,
                'name' => $step->name,
                'stage' => $step->stage,
                'status' => $step->status,
                'scene_number' => $step->scene?->scene_number,
                'attempt_number' => $step->attempt_number,
                'safe_failure_code' => $step->safe_failure_code,
                'safe_failure_summary' => $step->safe_failure_summary,
                'approval_type' => $step->approval_type,
            ])->values()->all(),
            'downloads' => $this->downloads($run, $user),
            'actions' => $this->actions($run, $user),
        ];
    }

    private function phase2(ProductionAutomationRun $run, User $user): array
    {
        $steps = $run->steps->keyBy('step_key');
        $storyStep = $steps->get('story_preparation');
        $profileStep = $steps->get('character_profile');
        $referenceStep = $steps->get('child_reference');
        $storyVersion = $run->project->storyVersions
            ->sortByDesc('version_number')
            ->first(fn ($version): bool => $version->production_automation_run_id === $run->id || filled($version->input_fingerprint));
        $profile = $run->project->characterProfile;
        $referenceAssets = $run->project->assets
            ->where('asset_type', 'character_sheet')
            ->filter(fn ($asset): bool => $asset->production_automation_run_id === $run->id || $asset->is_primary)
            ->sortByDesc('version_number')
            ->values();
        $approvedReference = $referenceAssets
            ->first(fn ($asset): bool => $asset->status === 'approved' && $asset->is_primary);

        return [
            'current_step' => in_array($run->current_step_key, ['preflight', 'story_preparation', 'character_profile', 'child_reference'], true)
                ? $run->current_step_key
                : null,
            'preflight' => [
                'status' => $steps->get('preflight')?->status,
                'blockers' => $run->blockers_json ?? [],
                'base_estimated_cost' => $run->base_estimated_cost,
                'retry_exposure_estimate' => $run->retry_exposure_estimate,
            ],
            'story_preparation' => [
                'status' => $storyStep?->status,
                'safe_failure_code' => $storyStep?->safe_failure_code,
                'safe_failure_summary' => $storyStep?->safe_failure_summary,
                'story_version_id' => $storyVersion?->id,
                'story_version_number' => $storyVersion?->version_number,
                'story_version_status' => $storyVersion?->status,
                'scene_count' => $run->project->scenes()->count(),
                'validation' => $this->safeValidation($storyStep?->validation_summary_json),
                'available_actions' => $this->phase2Actions($run, $storyStep, $user),
            ],
            'character_profile' => [
                'status' => $profileStep?->status,
                'safe_failure_code' => $profileStep?->safe_failure_code,
                'safe_failure_summary' => $profileStep?->safe_failure_summary,
                'profile_id' => $profile?->id,
                'profile_ready' => (bool) ($profile?->isReadyForAiGeneration() ?? false),
                'warnings' => $profile?->analysis_warnings,
                'field_confidence' => data_get($profile?->automation_metadata_json, 'field_confidence', data_get($profileStep?->validation_summary_json, 'field_confidence', [])),
                'validation' => $this->safeValidation($profileStep?->validation_summary_json),
                'available_actions' => $this->phase2Actions($run, $profileStep, $user),
            ],
            'child_reference' => [
                'status' => $referenceStep?->status,
                'safe_failure_code' => $referenceStep?->safe_failure_code,
                'safe_failure_summary' => $referenceStep?->safe_failure_summary,
                'approved_asset_id' => $approvedReference?->id,
                'approved_version' => $approvedReference?->version_number,
                'remaining_permitted_retries' => $this->remainingRetries($referenceStep, $run),
                'attempts' => $this->attemptSummaries($run, $referenceStep?->step_key),
                'assets' => $referenceAssets->map(fn ($asset): array => [
                    'id' => $asset->id,
                    'version_number' => $asset->version_number,
                    'status' => $asset->status,
                    'is_primary' => (bool) $asset->is_primary,
                    'attempt_id' => $asset->production_automation_attempt_id,
                    'validation_status' => data_get($asset->metadata_json, 'identity_review.status'),
                    'validation_score' => data_get($asset->metadata_json, 'identity_review.result.score'),
                    'blocking_flags' => data_get($asset->metadata_json, 'identity_review.result.blocking_flags', []),
                ])->values()->all(),
                'available_actions' => $this->phase2Actions($run, $referenceStep, $user),
            ],
        ];
    }

    private function sceneSummaries(ProductionAutomationRun $run): array
    {
        return $run->steps
            ->filter(fn ($step): bool => str_starts_with($step->step_key, 'scene_'))
            ->map(fn ($step): array => [
                'scene_number' => $step->scene?->scene_number ?? (int) substr($step->step_key, -2),
                'step_key' => $step->step_key,
                'status' => $step->status,
                'attempt_number' => $step->attempt_number,
                'has_compatible_output' => filled($step->output_fingerprint),
                'safe_failure_code' => $step->safe_failure_code,
                'safe_failure_summary' => $step->safe_failure_summary,
            ])
            ->values()
            ->all();
    }

    private function phase3(ProductionAutomationRun $run, User $user): array
    {
        $steps = $run->steps->keyBy('step_key');
        $coverStep = $steps->get('cover');
        $coverAssets = $run->project->assets
            ->where('asset_type', 'cover_image')
            ->filter(fn ($asset): bool => $asset->production_automation_run_id === $run->id || $asset->is_final)
            ->sortByDesc('version_number')
            ->values();
        $approvedCover = $coverAssets->first(fn ($asset): bool => $asset->status === 'approved' && $asset->is_final);
        $sceneSteps = $run->steps
            ->filter(fn ($step): bool => str_starts_with($step->step_key, 'scene_'))
            ->sortBy('sequence')
            ->values();
        $activeSceneCount = $run->project->generationJobs()
            ->where('production_automation_run_id', $run->id)
            ->where('job_type', 'scene_image')
            ->whereIn('status', ['queued', 'processing'])
            ->where(fn ($query) => $this->notStalledGenerationJob($query))
            ->count();
        $queuedSceneCount = $run->project->generationJobs()
            ->where('production_automation_run_id', $run->id)
            ->where('job_type', 'scene_image')
            ->where('status', 'queued')
            ->where(fn ($query) => $this->notStalledGenerationJob($query))
            ->count();
        $processingSceneCount = $run->project->generationJobs()
            ->where('production_automation_run_id', $run->id)
            ->where('job_type', 'scene_image')
            ->where('status', 'processing')
            ->where(fn ($query) => $this->notStalledGenerationJob($query))
            ->count();
        $sceneSummaries = $sceneSteps
            ->map(function ($step) use ($run, $user): array {
                $sceneNumber = $step->scene?->scene_number ?? (int) substr($step->step_key, -2);
                $scene = $run->project->scenes->firstWhere('scene_number', $sceneNumber);
                $assets = $run->project->assets
                    ->where('asset_type', 'scene_image')
                    ->where('production_scene_id', $scene?->id)
                    ->filter(fn ($asset): bool => $asset->production_automation_run_id === $run->id || $asset->is_final)
                    ->sortByDesc('version_number')
                    ->values();
                $primary = $assets->first(fn ($asset): bool => $asset->status === 'approved' && $asset->is_final);
                $latest = $assets->first();

                return [
                    'scene_number' => $sceneNumber,
                    'scene_id' => $scene?->id,
                    'step_key' => $step->step_key,
                    'status' => $step->status,
                    'safe_failure_code' => $step->safe_failure_code,
                    'safe_failure_summary' => $step->safe_failure_summary,
                    'primary_asset_id' => $primary?->id,
                    'primary_version' => $primary?->version_number,
                    'latest_asset_id' => $latest?->id,
                    'attempts_used' => $this->attemptsUsed($run, $step),
                    'remaining_permitted_retries' => $this->remainingRetries($step, $run),
                    'validation' => $this->assetValidationSummary($latest),
                    'available_actions' => $this->phase3Actions($run, $step, $latest, $user),
                ];
            })
            ->values();

        return [
            'current_step' => in_array($run->current_step_key, ['cover'], true) || str_starts_with((string) $run->current_step_key, 'scene_')
                ? $run->current_step_key
                : null,
            'cover' => [
                'status' => $coverStep?->status,
                'safe_failure_code' => $coverStep?->safe_failure_code,
                'safe_failure_summary' => $coverStep?->safe_failure_summary,
                'approved_asset_id' => $approvedCover?->id,
                'approved_version' => $approvedCover?->version_number,
                'attempts_used' => $this->attemptsUsed($run, $coverStep),
                'remaining_permitted_retries' => $this->remainingRetries($coverStep, $run),
                'validation' => $this->assetValidationSummary($coverAssets->first()),
                'attempts' => $this->attemptSummaries($run, $coverStep?->step_key),
                'assets' => $coverAssets->map(fn ($asset): array => [
                    'id' => $asset->id,
                    'version_number' => $asset->version_number,
                    'status' => $asset->status,
                    'is_final' => (bool) $asset->is_final,
                    'attempt_id' => $asset->production_automation_attempt_id,
                    'validation' => $this->assetValidationSummary($asset),
                ])->values()->all(),
                'available_actions' => $this->phase3Actions($run, $coverStep, $coverAssets->first(), $user),
            ],
            'scene_preparation' => [
                'status' => $sceneSummaries->contains(fn (array $scene): bool => in_array($scene['status'], [ProductionAutomation::STEP_RUNNING, ProductionAutomation::STEP_QUEUED], true))
                    ? 'processing'
                    : ($sceneSummaries->contains(fn (array $scene): bool => $scene['status'] === ProductionAutomation::STEP_WAITING_REVIEW) ? 'review_required' : 'ready'),
                'failed_scene_numbers' => $sceneSummaries
                    ->filter(fn (array $scene): bool => filled($scene['safe_failure_code']))
                    ->pluck('scene_number')
                    ->values()
                    ->all(),
            ],
            'scene_generation' => [
                'concurrency_limit' => max(1, min(5, (int) data_get($run->options_snapshot_json, 'scene_concurrency', config('production_studio.automation.scene_concurrency', 2)))),
                'active_scene_requests' => $activeSceneCount,
                'queued_scene_requests' => $queuedSceneCount,
                'processing_scene_requests' => $processingSceneCount,
                'failed_scenes' => $sceneSummaries
                    ->filter(fn (array $scene): bool => filled($scene['safe_failure_code']))
                    ->values()
                    ->all(),
                'scenes' => $sceneSummaries->all(),
            ],
            'completion_ready' => [
                'approved_cover' => (bool) $approvedCover,
                'approved_scene_count' => $sceneSummaries->filter(fn (array $scene): bool => filled($scene['primary_asset_id']))->count(),
                'all_scenes_approved' => $sceneSummaries->every(fn (array $scene): bool => filled($scene['primary_asset_id']) && $scene['status'] === ProductionAutomation::STEP_COMPLETED),
                'active_provider_attempts' => $run->attempts->whereIn('status', ['queued', 'running'])->count(),
                'phase3_complete' => (bool) $approvedCover
                    && $sceneSummaries->every(fn (array $scene): bool => filled($scene['primary_asset_id']) && $scene['status'] === ProductionAutomation::STEP_COMPLETED)
                    && $activeSceneCount === 0,
            ],
        ];
    }

    private function downloads(ProductionAutomationRun $run, User $user): array
    {
        $downloads = [];

        if ($user->hasPermission('production_studio.layout_download')
            && in_array($run->status, [ProductionAutomation::STATUS_FILES_READY, ProductionAutomation::STATUS_COMPLETED], true)) {
            $layout = $run->project->printLayouts
                ->sortByDesc('version_number')
                ->first(fn (ProductionPrintLayout $layout): bool => $layout->isValidatedAutomationReady());

            if ($layout) {
                $downloads = collect(['reader', 'print', 'manifest', 'proof'])
                    ->mapWithKeys(fn (string $file): array => [$file => URL::temporarySignedRoute(
                        'admin.production-studio.automation.download',
                        now()->addMinutes(10),
                        [$run->project, $run, $layout, $file],
                    )])
                    ->all();
            }
        }

        $proof = $run->currentProof ?: $run->proofs
            ->sortByDesc('proof_version')
            ->first(fn (ProductionAutomationProof $proof): bool => $proof->status === 'passed' && $proof->hasReport());

        if ($user->hasPermission('production_studio.final_proof_review') && $proof?->hasReport()) {
            $downloads['proof_report'] = URL::temporarySignedRoute(
                'admin.production-studio.automation.proof-report',
                now()->addMinutes(10),
                [$run->project, $run, $proof],
            );
        }

        return $downloads;
    }

    private function actions(ProductionAutomationRun $run, User $user): array
    {
        $canManage = $user->hasPermission('production_studio.automation_manage');
        $canReviewProof = $user->hasPermission('production_studio.final_proof_review');
        $currentProof = $run->currentProof;

        return [
            'pause' => $canManage && $run->status === ProductionAutomation::STATUS_RUNNING,
            'resume' => $canManage && in_array($run->status, ProductionAutomation::pausedStatuses(), true),
            'cancel' => $canManage && ! $run->isTerminal(),
            'retry_failed_step' => $canManage && in_array($run->status, [ProductionAutomation::STATUS_PAUSED_REVIEW, ProductionAutomation::STATUS_PROVIDER_FAILED], true),
            'start_final_proof' => $canReviewProof && $run->status === ProductionAutomation::STATUS_FILES_READY && ! $currentProof,
            'confirm_final_proof' => $canReviewProof
                && $run->status === ProductionAutomation::STATUS_FILES_READY
                && $currentProof
                && in_array($currentProof->status, ['draft', 'in_review'], true),
            'reject_final_proof' => $canReviewProof
                && $run->status === ProductionAutomation::STATUS_FILES_READY
                && $currentProof
                && in_array($currentProof->status, ['draft', 'in_review'], true),
        ];
    }

    private function phase5(ProductionAutomationRun $run, User $user): array
    {
        $steps = $run->steps->keyBy('step_key');
        $finalProofStep = $steps->get('final_proof');
        $currentProof = $run->currentProof;
        $proofAttempts = $run->proofs->sortByDesc('proof_version')->values();
        $canReview = $user->hasPermission('production_studio.final_proof_review');
        $canDownloadReport = $canReview && (bool) ($currentProof?->hasReport() ?? false);
        $checklistDefinitions = $this->finalProofs->checklistItems();
        $checklist = $currentProof?->checklist_snapshot ?? [];
        $counts = $this->checklistCounts($checklist, $checklistDefinitions);

        return [
            'current_step' => in_array($run->current_step_key, ['final_proof', 'print_ready'], true) ? $run->current_step_key : null,
            'eligibility' => [
                'status_files_ready' => $run->status === ProductionAutomation::STATUS_FILES_READY,
                'progress_95' => $this->progress->percentage($run) === 95,
                'final_proof_step_waiting' => $finalProofStep?->status === ProductionAutomation::STEP_WAITING_REVIEW,
                'ready_for_review' => $run->status === ProductionAutomation::STATUS_FILES_READY
                    && $this->progress->percentage($run) === 95
                    && $finalProofStep?->status === ProductionAutomation::STEP_WAITING_REVIEW,
            ],
            'final_proof_step' => [
                'status' => $finalProofStep?->status,
                'safe_failure_code' => $finalProofStep?->safe_failure_code,
                'safe_failure_summary' => $finalProofStep?->safe_failure_summary,
            ],
            'current_proof' => $currentProof ? $this->proofSummary($currentProof, includeChecklist: true) : null,
            'previous_proofs' => $proofAttempts
                ->reject(fn (ProductionAutomationProof $proof): bool => $currentProof && (int) $proof->id === (int) $currentProof->id)
                ->map(fn (ProductionAutomationProof $proof): array => $this->proofSummary($proof))
                ->values()
                ->all(),
            'checklist' => [
                'schema_version' => 1,
                'items' => collect($checklistDefinitions)
                    ->map(fn (array $item, string $key): array => [
                        'key' => $key,
                        'group' => $item['group'],
                        'label' => $item['label'],
                        'mandatory' => (bool) $item['mandatory'],
                        'value' => $checklist[$key]['value'] ?? null,
                        'reason' => $checklist[$key]['reason'] ?? null,
                    ])
                    ->values()
                    ->all(),
                'counts' => $counts,
            ],
            'checksums' => $currentProof ? [
                'reader_pdf' => $currentProof->reader_pdf_checksum,
                'imposed_pdf' => $currentProof->imposed_pdf_checksum,
                'manifest' => $currentProof->manifest_checksum,
                'proof_checklist' => $currentProof->proof_checklist_checksum,
            ] : [],
            'report' => $currentProof ? [
                'status' => $currentProof->report_status,
                'checksum' => $currentProof->report_checksum,
                'generated_at' => $currentProof->report_generated_at?->toIso8601String(),
                'download_available' => $canDownloadReport,
            ] : ['status' => null, 'download_available' => false],
            'ready_for_print' => $run->status === ProductionAutomation::STATUS_COMPLETED
                && $run->current_stage === 'print_ready'
                && $run->safe_failure_code === 'final_proof_passed_ready_for_print',
            'available_actions' => [
                'create_draft' => $canReview && $run->status === ProductionAutomation::STATUS_FILES_READY && ! $currentProof,
                'approve' => $canReview
                    && $run->status === ProductionAutomation::STATUS_FILES_READY
                    && $currentProof
                    && in_array($currentProof->status, ['draft', 'in_review'], true),
                'reject' => $canReview
                    && $run->status === ProductionAutomation::STATUS_FILES_READY
                    && $currentProof
                    && in_array($currentProof->status, ['draft', 'in_review'], true),
                'download_report' => $canDownloadReport,
            ],
        ];
    }

    private function proofSummary(ProductionAutomationProof $proof, bool $includeChecklist = false): array
    {
        $summary = [
            'id' => $proof->id,
            'version' => $proof->proof_version,
            'status' => $proof->status,
            'is_current' => (bool) $proof->current_run_id,
            'layout_id' => $proof->production_print_layout_id,
            'layout_version' => $proof->layout?->version_number,
            'reviewer_id' => $proof->reviewer_id,
            'reviewer_name' => $proof->reviewer?->name,
            'reviewed_at' => $proof->reviewed_at?->toIso8601String(),
            'decision_reason' => $proof->decision_reason,
            'failure_category' => $proof->failure_category,
            'affected_component' => $proof->affected_component,
            'affected_scene_number' => $proof->affected_scene_number,
            'invalidated_at' => $proof->invalidated_at?->toIso8601String(),
            'invalidation_reason' => $proof->invalidation_reason,
            'report_status' => $proof->report_status,
            'report_checksum' => $proof->report_checksum,
            'report_generated_at' => $proof->report_generated_at?->toIso8601String(),
        ];

        if ($includeChecklist) {
            $summary['print_test_metadata'] = $proof->print_test_metadata ?? [];
            $summary['checklist_counts'] = $this->checklistCounts($proof->checklist_snapshot ?? [], $this->finalProofs->checklistItems());
        }

        return $summary;
    }

    private function checklistCounts(array $checklist, array $definitions): array
    {
        $total = count($definitions);
        $mandatory = collect($definitions)->filter(fn (array $item): bool => (bool) $item['mandatory'])->count();
        $answered = collect($definitions)->filter(fn (array $item, string $key): bool => filled($checklist[$key]['value'] ?? null))->count();
        $passed = collect($definitions)->filter(fn (array $item, string $key): bool => ($checklist[$key]['value'] ?? null) === 'pass')->count();
        $failed = collect($definitions)->filter(fn (array $item, string $key): bool => ($checklist[$key]['value'] ?? null) === 'fail')->count();

        return [
            'total' => $total,
            'mandatory' => $mandatory,
            'answered' => $answered,
            'passed' => $passed,
            'failed' => $failed,
            'complete' => $answered === $total,
            'all_mandatory_passed' => $passed === $mandatory && $failed === 0,
        ];
    }

    private function phase4(ProductionAutomationRun $run, User $user): array
    {
        $steps = $run->steps->keyBy('step_key');
        $layoutStep = $steps->get('layout_print');
        $finalProofStep = $steps->get('final_proof');
        $layout = $run->project->printLayouts
            ->sortByDesc('version_number')
            ->first(fn (ProductionPrintLayout $candidate): bool => $candidate->production_automation_run_id === $run->id || $candidate->isReady());
        $manifest = $layout?->manifest_json ?? [];
        $validation = data_get($manifest, 'validation', []);
        $files = data_get($manifest, 'files', []);
        $canManage = $user->hasPermission('production_studio.automation_manage');
        $canDownload = $user->hasPermission('production_studio.layout_download')
            && in_array($run->status, [ProductionAutomation::STATUS_FILES_READY, ProductionAutomation::STATUS_COMPLETED], true)
            && (bool) ($layout?->isValidatedAutomationReady() ?? false);

        return [
            'current_step' => in_array($run->current_step_key, ['layout_print', 'final_proof'], true) ? $run->current_step_key : null,
            'layout' => [
                'status' => $layoutStep?->status,
                'safe_failure_code' => $layoutStep?->safe_failure_code,
                'safe_failure_summary' => $layoutStep?->safe_failure_summary,
                'layout_id' => $layout?->id,
                'layout_version' => $layout?->version_number,
                'layout_status' => $layout?->status,
                'layout_template_version' => data_get($manifest, 'layout_template_version'),
                'page_map_version' => data_get($manifest, 'page_map_version'),
                'renderer_version' => data_get($validation, 'renderer_version'),
                'font_package_version' => data_get($validation, 'font_package_version'),
                'output_fingerprint' => $layout?->output_fingerprint,
            ],
            'reader_pdf' => $this->safeFileSummary(data_get($files, 'reader_pdf')),
            'imposed_pdf' => $this->safeFileSummary(data_get($files, 'print_pdf')),
            'manifest' => $this->safeFileSummary(data_get($files, 'manifest')),
            'proof_checklist' => $this->safeFileSummary(data_get($files, 'proof_checklist')),
            'validation' => [
                'ok' => (bool) data_get($validation, 'ok', false),
                'errors' => data_get($validation, 'errors', []),
                'warnings' => data_get($validation, 'warnings', []),
                'dpi_warnings' => data_get($validation, 'images.warnings', []),
                'typography' => data_get($validation, 'typography', []),
                'known_non_automatable_checks' => data_get($validation, 'known_non_automatable_checks', []),
            ],
            'page_count' => data_get($manifest, 'page_count'),
            'sheet_count' => data_get($manifest, 'sheet_count'),
            'imposed_pdf_pages' => data_get($manifest, 'printed_sides'),
            'pdf_page_representation' => data_get($manifest, 'pdf_page_representation'),
            'blockers' => $layoutStep?->validation_summary_json['blockers'] ?? [],
            'available_download_actions' => [
                'reader' => $canDownload,
                'print' => $canDownload,
                'manifest' => $canDownload,
                'proof' => $canDownload,
            ],
            'available_actions' => [
                'regenerate_reader_pdf' => $canManage && in_array($layoutStep?->status, [ProductionAutomation::STEP_WAITING_REVIEW, ProductionAutomation::STEP_FAILED_RECOVERABLE, ProductionAutomation::STEP_PROVIDER_FAILED], true),
                'regenerate_imposed_pdf' => $canManage && in_array($layoutStep?->status, [ProductionAutomation::STEP_WAITING_REVIEW, ProductionAutomation::STEP_FAILED_RECOVERABLE, ProductionAutomation::STEP_PROVIDER_FAILED], true),
                'retry_failed_validation' => $canManage && $layout?->status === 'failed',
                'manual_layout_correction' => $canManage && $layoutStep?->status === ProductionAutomation::STEP_WAITING_REVIEW,
                'select_compatible_historical_layout' => $canManage && $run->project->printLayouts->contains(fn (ProductionPrintLayout $candidate): bool => $candidate->isValidatedAutomationReady()),
            ],
            'phase4_ready' => $run->status === ProductionAutomation::STATUS_FILES_READY
                && (bool) ($layout?->isValidatedAutomationReady() ?? false)
                && $layoutStep?->status === ProductionAutomation::STEP_COMPLETED
                && $finalProofStep?->status === ProductionAutomation::STEP_WAITING_REVIEW,
            'final_proof_pending' => $finalProofStep?->status === ProductionAutomation::STEP_WAITING_REVIEW,
        ];
    }

    private function safeFileSummary(?array $file): array
    {
        if (! $file) {
            return [
                'status' => 'missing',
            ];
        }

        return collect($file)
            ->only(['ok', 'type', 'expected_size', 'sha256', 'bytes', 'page_count', 'dimensions', 'font_embedded'])
            ->put('status', data_get($file, 'ok') ? 'validated' : 'failed')
            ->all();
    }

    private function attemptSummaries(ProductionAutomationRun $run, ?string $stepKey): array
    {
        if (! $stepKey) {
            return [];
        }

        return $run->attempts
            ->filter(fn ($attempt): bool => $attempt->step?->step_key === $stepKey)
            ->sortBy('attempt_number')
            ->map(fn ($attempt): array => [
                'id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'status' => $attempt->status,
                'provider' => $attempt->provider,
                'model' => $attempt->model,
                'safe_failure_code' => $attempt->safe_failure_code,
                'safe_failure_summary' => $attempt->safe_failure_summary,
                'approval_type' => $attempt->approval_type,
            ])
            ->values()
            ->all();
    }

    private function phase2Actions(ProductionAutomationRun $run, $step, User $user): array
    {
        $canManage = $user->hasPermission('production_studio.automation_manage');

        if (! $canManage || ! $step) {
            return [];
        }

        return [
            'resume' => in_array($run->status, ProductionAutomation::pausedStatuses(), true),
            'retry' => in_array($step->status, [ProductionAutomation::STEP_WAITING_REVIEW, ProductionAutomation::STEP_FAILED_RECOVERABLE, ProductionAutomation::STEP_PROVIDER_FAILED], true),
            'manual_review' => $step->status === ProductionAutomation::STEP_WAITING_REVIEW,
        ];
    }

    private function remainingRetries($step, ?ProductionAutomationRun $run = null): int
    {
        if (! $step) {
            return 0;
        }

        $used = $run
            ? (int) $run->attempts
                ->where('automation_step_id', $step->id)
                ->where('attempt_number', '>', 0)
                ->max('attempt_number')
            : (int) $step->attempts()->where('attempt_number', '>', 0)->max('attempt_number');

        return max(0, (int) $step->attempt_limit - $used);
    }

    private function attemptsUsed(ProductionAutomationRun $run, $step): int
    {
        if (! $step) {
            return 0;
        }

        return (int) $run->attempts
            ->where('automation_step_id', $step->id)
            ->where('attempt_number', '>', 0)
            ->max('attempt_number');
    }

    private function assetValidationSummary($asset): array
    {
        if (! $asset) {
            return [];
        }

        $result = data_get($asset->metadata_json, 'identity_review.result', []);

        return [
            'status' => data_get($asset->metadata_json, 'identity_review.status'),
            'identity_score' => is_array($result) ? data_get($result, 'identity_score', data_get($result, 'score')) : null,
            'story_relevance_score' => is_array($result) ? data_get($result, 'story_relevance_score') : null,
            'scene_adherence_score' => is_array($result) ? data_get($result, 'scene_adherence_score') : null,
            'blocking_flags' => is_array($result) ? data_get($result, 'blocking_flags', []) : [],
            'safe_failure_code' => $asset->automationAttempt?->safe_failure_code,
            'safe_failure_summary' => $asset->automationAttempt?->safe_failure_summary,
        ];
    }

    private function phase3Actions(ProductionAutomationRun $run, $step, $asset, User $user): array
    {
        $canManage = $user->hasPermission('production_studio.automation_manage');

        if (! $canManage || ! $step) {
            return [];
        }

        return [
            'resume' => in_array($run->status, ProductionAutomation::pausedStatuses(), true),
            'retry' => in_array($step->status, [ProductionAutomation::STEP_WAITING_REVIEW, ProductionAutomation::STEP_FAILED_RECOVERABLE, ProductionAutomation::STEP_PROVIDER_FAILED], true),
            'approve_asset' => $asset && in_array($asset->asset_type, ['cover_image', 'scene_image'], true),
            'reject_asset' => $asset && in_array($asset->asset_type, ['cover_image', 'scene_image'], true) && $asset->status !== 'rejected',
            'manual_scene_correction' => str_starts_with((string) $step->step_key, 'scene_'),
        ];
    }

    private function safeValidation(?array $validation): array
    {
        if (! $validation) {
            return [];
        }

        return collect($validation)
            ->only([
                'ok',
                'code',
                'summary',
                'safe_failure_code',
                'safe_failure_summary',
                'score',
                'identity_score',
                'story_relevance_score',
                'scene_adherence_score',
                'blocking_flags',
                'field_confidence',
                'warnings',
                'source',
                'asset_id',
                'story_version_id',
                'profile_id',
            ])
            ->all();
    }

    private function notStalledGenerationJob($query): void
    {
        $cutoff = now()->subMinutes((int) config('production_studio.automation.queue.heartbeat_stale_minutes', 15));

        $query->where(function ($nested) use ($cutoff): void {
            $nested->where('updated_at', '>=', $cutoff)
                ->orWhere('heartbeat_at', '>=', $cutoff)
                ->orWhereNull('updated_at');
        });
    }
}
