<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AdvanceProductionAutomationRun;
use App\Models\ProductionAutomationProof;
use App\Models\ProductionAutomationRun;
use App\Models\ProductionAutomationStep;
use App\Models\ProductionPrintLayout;
use App\Models\ProductionProject;
use App\Models\ProductionProjectAsset;
use App\Models\ProductionScene;
use App\Services\ProductionStudio\ProductionAutomationCostLedger;
use App\Services\ProductionStudio\ProductionAutomationFinalProofService;
use App\Services\ProductionStudio\ProductionAutomationPhase2Service;
use App\Services\ProductionStudio\ProductionAutomationPhase3Service;
use App\Services\ProductionStudio\ProductionAutomationPhase4Service;
use App\Services\ProductionStudio\ProductionAutomationPreflightService;
use App\Services\ProductionStudio\ProductionAutomationRunService;
use App\Services\ProductionStudio\ProductionAutomationStateMachine;
use App\Services\ProductionStudio\ProductionAutomationStatusPresenter;
use App\Support\ProductionAutomation;
use App\Support\ProductionStudio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class ProductionAutomationController extends Controller
{
    public function preflight(Request $request, ProductionProject $project, ProductionAutomationPreflightService $preflight): JsonResponse
    {
        $this->ensureStudioEnabled();

        $validated = $this->optionsValidation($request, requireBudget: false);
        $result = $preflight->inspect($project, $validated);

        return response()->json([
            'ok' => $result['ok'],
            'preflight' => $result,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function start(Request $request, ProductionProject $project, ProductionAutomationRunService $runs, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $this->ensureAutomationEnabled();

        $validated = $this->optionsValidation($request, requireBudget: true);

        try {
            $run = $runs->start($project, $validated, $request->user());
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'تم إنشاء دورة الإنتاج التلقائي.',
            'status_url' => route('admin.production-studio.automation.status', $project),
            'automation' => $presenter->present($run->fresh(['project', 'steps', 'costEntries']), $request->user()),
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    public function status(Request $request, ProductionProject $project, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();

        $run = $project->automationRuns()
            ->with(['steps.scene', 'costEntries', 'project.printLayouts'])
            ->where(function ($query) use ($project) {
                $query->where('active_project_id', $project->id)
                    ->orWhereNull('active_project_id');
            })
            ->latest()
            ->first();

        if (! $run) {
            return response()->json([
                'ok' => true,
                'automation' => null,
            ])->header('Cache-Control', 'no-store, private');
        }

        $response = response()->json([
            'ok' => true,
            'automation' => $presenter->present($run, $request->user()),
        ])->setEtag((string) $run->version)
            ->header('Cache-Control', 'no-store, private');

        return $response->isNotModified($request) ? $response : $response;
    }

    public function pause(Request $request, ProductionProject $project, ProductionAutomationStateMachine $stateMachine, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $run = $this->activeRun($project);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $run = $stateMachine->transitionRun($run, ProductionAutomation::STATUS_PAUSED_RECOVERABLE, [
                'pause_reason' => $validated['reason'] ?? 'manual_pause',
                'safe_failure_code' => 'manual_pause',
                'safe_failure_summary' => 'Automation was manually paused by an administrator.',
            ], $request->user(), 'admin');
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json(['ok' => true, 'automation' => $presenter->present($run, $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function resume(Request $request, ProductionProject $project, ProductionAutomationStateMachine $stateMachine, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $this->ensureAutomationEnabled();
        $run = $this->activeRun($project);

        try {
            $run = $stateMachine->transitionRun($run, ProductionAutomation::STATUS_RUNNING, [
                'pause_reason' => null,
            ], $request->user(), 'admin');
            AdvanceProductionAutomationRun::dispatch($run->id)->afterCommit();
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json(['ok' => true, 'automation' => $presenter->present($run, $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function increaseBudget(Request $request, ProductionProject $project, ProductionAutomationStateMachine $stateMachine, ProductionAutomationStatusPresenter $presenter, ProductionAutomationCostLedger $ledger): JsonResponse
    {
        $this->ensureStudioEnabled();
        $this->ensureAutomationEnabled();
        $run = $this->activeRun($project);

        $validated = $request->validate([
            'hard_budget' => ['required', 'numeric', 'min:0.01'],
            'confirm_additional_budget_exposure' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $run = DB::transaction(function () use ($run, $project, $request, $validated, $ledger): ProductionAutomationRun {
                $locked = ProductionAutomationRun::query()
                    ->with(['project', 'costEntries'])
                    ->whereKey($run->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $newBudget = (float) $validated['hard_budget'];
                $oldBudget = (float) $locked->hard_budget;
                $summary = $ledger->summary($locked);
                $currentExposure = (float) $summary['reserved_cost']
                    + (float) $summary['incurred_cost']
                    + (float) $summary['unknown_billing_exposure'];

                if ($newBudget <= $oldBudget + 0.00001) {
                    throw new \RuntimeException('أدخل ميزانية أعلى من الميزانية الحالية قبل الاستئناف.');
                }

                if ($newBudget <= $currentExposure + 0.00001) {
                    throw new \RuntimeException('الميزانية الجديدة يجب أن تكون أعلى من التكلفة المحجوزة أو المنفقة حاليًا.');
                }

                $snapshot = $locked->options_snapshot_json ?? [];
                $snapshot['hard_budget'] = number_format($newBudget, 4, '.', '');
                $snapshot['manual_budget_updates'][] = [
                    'old_hard_budget' => number_format($oldBudget, 4, '.', ''),
                    'new_hard_budget' => number_format($newBudget, 4, '.', ''),
                    'actor_user_id' => $request->user()?->id,
                    'reason' => $validated['reason'],
                    'updated_at' => now()->toIso8601String(),
                ];

                $locked->update([
                    'hard_budget' => number_format($newBudget, 4, '.', ''),
                    'options_snapshot_json' => $snapshot,
                    'blockers_json' => collect($locked->blockers_json ?? [])
                        ->reject(fn ($blocker): bool => data_get($blocker, 'code') === 'hard_budget_exhausted')
                        ->values()
                        ->all(),
                ]);

                ProductionStudio::log($project, 'automation.budget_increased', 'تم رفع ميزانية دورة الإنتاج التلقائي.', [
                    'run_id' => $locked->id,
                    'old_hard_budget' => number_format($oldBudget, 4, '.', ''),
                    'new_hard_budget' => number_format($newBudget, 4, '.', ''),
                    'current_exposure' => number_format($currentExposure, 4, '.', ''),
                    'reason' => $validated['reason'],
                ], $request->user());

                return $locked->fresh(['steps', 'project.printLayouts', 'costEntries']);
            });

            if ($run->status === ProductionAutomation::STATUS_PAUSED_BUDGET) {
                $run = $stateMachine->transitionRun($run, ProductionAutomation::STATUS_RUNNING, [
                    'pause_reason' => null,
                    'safe_failure_code' => null,
                    'safe_failure_summary' => null,
                    'blockers' => [],
                    'current_stage' => $run->current_stage,
                    'current_step_key' => $run->current_step_key,
                ], $request->user(), 'admin_budget_increase');
            }

            AdvanceProductionAutomationRun::dispatch($run->id)->afterCommit();
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json(['ok' => true, 'automation' => $presenter->present($run->fresh(['steps', 'project.printLayouts', 'costEntries']), $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function cancel(Request $request, ProductionProject $project, ProductionAutomationStateMachine $stateMachine, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $run = $this->activeRun($project);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $run = DB::transaction(function () use ($run, $stateMachine, $request, $validated): ProductionAutomationRun {
                $run->steps()
                    ->whereNotIn('status', [
                        ProductionAutomation::STEP_COMPLETED,
                        ProductionAutomation::STEP_SKIPPED,
                        ProductionAutomation::STEP_FAILED,
                        ProductionAutomation::STEP_CANCELLED,
                    ])
                    ->get()
                    ->each(fn (ProductionAutomationStep $step) => $stateMachine->transitionStep(
                        $step,
                        ProductionAutomation::STEP_CANCELLED,
                        ['safe_failure_code' => 'run_cancelled', 'safe_failure_summary' => 'Automation run was cancelled.'],
                        $request->user(),
                        'admin'
                    ));

                return $stateMachine->transitionRun($run, ProductionAutomation::STATUS_CANCELLED, [
                    'pause_reason' => 'cancelled',
                    'safe_failure_code' => 'cancelled',
                    'safe_failure_summary' => $validated['reason'],
                ], $request->user(), 'admin');
            });
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json(['ok' => true, 'automation' => $presenter->present($run, $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function retryStep(Request $request, ProductionProject $project, ProductionAutomationStateMachine $stateMachine, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $this->ensureAutomationEnabled();
        $run = $this->activeRun($project);

        $validated = $request->validate([
            'step_key' => ['required', 'string', 'max:80'],
            'confirm_additional_budget_exposure' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $step = $run->steps()->where('step_key', $validated['step_key'])->firstOrFail();

        $isManualOverride = $step->attempt_number >= $step->attempt_limit;

        try {
            $step = $stateMachine->transitionStep($step, ProductionAutomation::STEP_QUEUED, [
                'attempt_number' => $step->attempt_number + 1,
                'safe_failure_code' => null,
                'safe_failure_summary' => null,
                'manual_override' => $isManualOverride,
                'metadata_json' => [
                    'retry_reason' => $validated['reason'],
                    'manual_override' => $isManualOverride,
                    'additional_budget_exposure_confirmed' => true,
                ],
            ], $request->user(), 'admin_retry');

            if ($run->status !== ProductionAutomation::STATUS_RUNNING) {
                $run = $stateMachine->transitionRun($run, ProductionAutomation::STATUS_RUNNING, [
                    'current_stage' => $step->stage,
                    'current_step_key' => $step->step_key,
                ], $request->user(), 'admin_retry');
            }

            ProductionStudio::log($project, 'automation.step_retry_requested', 'تم طلب إعادة محاولة لخطوة في الإنتاج التلقائي.', [
                'run_id' => $run->id,
                'step_key' => $step->step_key,
                'reason' => $validated['reason'],
                'manual_override' => $isManualOverride,
                'attempt_number' => $step->attempt_number,
            ], $request->user());

            AdvanceProductionAutomationRun::dispatch($run->id)->afterCommit();
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json(['ok' => true, 'automation' => $presenter->present($run->fresh(['steps', 'project.printLayouts', 'costEntries']), $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function createFinalProofDraft(Request $request, ProductionProject $project, ProductionAutomationFinalProofService $proofs, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $run = $this->activeRun($project);

        try {
            $proof = $proofs->createDraft($run, $request->user());
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json([
            'ok' => true,
            'proof' => $this->proofSummary($proof),
            'automation' => $presenter->present($run->fresh(['steps', 'project.printLayouts', 'currentProof', 'proofs', 'costEntries']), $request->user()),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function finalProof(Request $request, ProductionProject $project, ProductionAutomationFinalProofService $proofs, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $run = $this->activeRun($project)->loadMissing('currentProof');
        $validated = $this->validateFinalProofApproval($request);

        try {
            $proof = $run->currentProof ?: $proofs->createDraft($run, $request->user());
            $proof = $proofs->approve($run, $proof, $request->user(), $validated);
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json([
            'ok' => true,
            'proof' => $this->proofSummary($proof),
            'automation' => $presenter->present($proof->run->fresh(['steps', 'project.printLayouts', 'currentProof', 'proofs', 'costEntries']), $request->user()),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function approveFinalProof(Request $request, ProductionProject $project, ProductionAutomationProof $proof, ProductionAutomationFinalProofService $proofs, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $validated = $this->validateFinalProofApproval($request);
        $run = $proof->run()->firstOrFail();
        abort_unless((int) $run->production_project_id === (int) $project->id, 404);

        try {
            $proof = $proofs->approve($run, $proof, $request->user(), $validated);
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json([
            'ok' => true,
            'proof' => $this->proofSummary($proof),
            'automation' => $presenter->present($proof->run->fresh(['steps', 'project.printLayouts', 'currentProof', 'proofs', 'costEntries']), $request->user()),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function rejectFinalProof(Request $request, ProductionProject $project, ProductionAutomationProof $proof, ProductionAutomationFinalProofService $proofs, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $run = $proof->run()->firstOrFail();
        abort_unless((int) $run->production_project_id === (int) $project->id, 404);

        $validated = $request->validate([
            'checklist' => ['required', 'array'],
            'checklist.*.value' => ['required', Rule::in(['pass', 'fail', 'not_applicable'])],
            'checklist.*.reason' => ['nullable', 'string', 'max:1000'],
            'print_test_metadata' => ['nullable', 'array'],
            'failure_category' => ['required', 'string', 'max:80'],
            'affected_component' => ['required', Rule::in([
                'story_text',
                'cover',
                'specific_scene',
                'reader_layout',
                'imposition',
                'font_or_arabic_rendering',
                'image_quality',
                'color_output',
                'duplex_or_binding',
                'other',
            ])],
            'affected_scene_number' => ['nullable', 'integer', 'min:1', 'max:13'],
            'reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $proof = $proofs->reject($run, $proof, $request->user(), $validated);
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json([
            'ok' => true,
            'proof' => $this->proofSummary($proof),
            'automation' => $presenter->present($proof->run->fresh(['steps', 'project.printLayouts', 'currentProof', 'proofs', 'costEntries']), $request->user()),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function downloadProofReport(ProductionProject $project, ProductionAutomationRun $run, ProductionAutomationProof $proof)
    {
        $this->ensureStudioEnabled();
        abort_unless((int) $run->production_project_id === (int) $project->id, 404);
        abort_unless((int) $proof->automation_run_id === (int) $run->id, 404);
        abort_unless($proof->hasReport(), 404);

        $path = (string) $proof->report_path;
        abort_unless($path !== '' && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 404);

        ProductionStudio::log($project, 'automation.proof_report_downloaded', 'تم تنزيل تقرير المراجعة النهائية للإنتاج التلقائي.', [
            'run_id' => $run->id,
            'proof_id' => $proof->id,
            'proof_version' => $proof->proof_version,
            'report_checksum' => $proof->report_checksum,
        ], auth()->user());

        return Storage::disk('local')->download($path, $project->order?->order_number.'-final-proof-report-v'.$proof->proof_version.'.json', [
            'Content-Type' => 'application/json',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function validateFinalProofApproval(Request $request): array
    {
        return $request->validate([
            'checklist' => ['required', 'array'],
            'checklist.*.value' => ['required', Rule::in(['pass', 'fail', 'not_applicable'])],
            'checklist.*.reason' => ['nullable', 'string', 'max:1000'],
            'print_test_metadata' => ['required', 'array'],
            'print_test_metadata.proof_print_date' => ['required', 'string', 'max:100'],
            'print_test_metadata.printer_name' => ['required', 'string', 'max:255'],
            'print_test_metadata.printer_model' => ['nullable', 'string', 'max:255'],
            'print_test_metadata.paper_size' => ['required', 'string', 'max:100'],
            'print_test_metadata.cover_paper_type' => ['nullable', 'string', 'max:255'],
            'print_test_metadata.cover_paper_gsm' => ['nullable', 'string', 'max:50'],
            'print_test_metadata.inner_paper_type' => ['nullable', 'string', 'max:255'],
            'print_test_metadata.inner_paper_gsm' => ['nullable', 'string', 'max:50'],
            'print_test_metadata.duplex_setting' => ['required', 'string', 'max:100'],
            'print_test_metadata.flip_edge' => ['required', 'string', 'max:100'],
            'print_test_metadata.print_quality' => ['required', 'string', 'max:100'],
            'print_test_metadata.test_copies' => ['required', 'integer', 'min:1', 'max:20'],
            'print_test_metadata.reviewer_notes' => ['nullable', 'string', 'max:2000'],
            'print_test_metadata.observed_color_issues' => ['nullable', 'string', 'max:2000'],
            'print_test_metadata.observed_alignment_issues' => ['nullable', 'string', 'max:2000'],
            'reviewed_checksums' => ['required', 'array'],
            'reviewed_checksums.reader_pdf' => ['required', 'string', 'size:64'],
            'reviewed_checksums.imposed_pdf' => ['required', 'string', 'size:64'],
            'reviewed_checksums.manifest' => ['required', 'string', 'size:64'],
            'decision_reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function proofSummary(ProductionAutomationProof $proof): array
    {
        return [
            'id' => $proof->id,
            'version' => $proof->proof_version,
            'status' => $proof->status,
            'is_current' => (bool) $proof->current_run_id,
            'layout_id' => $proof->production_print_layout_id,
            'reader_pdf_checksum' => $proof->reader_pdf_checksum,
            'imposed_pdf_checksum' => $proof->imposed_pdf_checksum,
            'manifest_checksum' => $proof->manifest_checksum,
            'proof_checklist_checksum' => $proof->proof_checklist_checksum,
            'reviewer_id' => $proof->reviewer_id,
            'reviewed_at' => $proof->reviewed_at?->toIso8601String(),
            'failure_category' => $proof->failure_category,
            'affected_component' => $proof->affected_component,
            'affected_scene_number' => $proof->affected_scene_number,
            'report_status' => $proof->report_status,
            'report_checksum' => $proof->report_checksum,
            'report_generated_at' => $proof->report_generated_at?->toIso8601String(),
        ];
    }

    public function approveStoryPreparation(Request $request, ProductionProject $project, ProductionAutomationPhase2Service $phase2, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $run = $this->activeRun($project);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $run = $phase2->approveStoryPreparationManually($run, $request->user(), $validated['reason']);
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json(['ok' => true, 'automation' => $presenter->present($run, $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function correctCharacterProfile(Request $request, ProductionProject $project, ProductionAutomationPhase2Service $phase2, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $run = $this->activeRun($project);

        $validated = $request->validate([
            'appearance_summary' => ['required', 'string', 'max:3000'],
            'hair_details' => ['required', 'string', 'max:2000'],
            'skin_tone' => ['required', 'string', 'max:1000'],
            'eye_color_traits' => ['required', 'string', 'max:1500'],
            'typical_expression' => ['required', 'string', 'max:1500'],
            'face_shape_notes' => ['nullable', 'string', 'max:1500'],
            'body_proportion_notes' => ['nullable', 'string', 'max:1500'],
            'identity_rules' => ['required', 'string', 'max:3000'],
            'negative_instructions' => ['required', 'string', 'max:3000'],
            'confidence_notes' => ['nullable', 'string', 'max:2000'],
            'analysis_warnings' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $run = $phase2->applyProfileCorrection($run, $request->user(), $validated);
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json(['ok' => true, 'automation' => $presenter->present($run, $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function approveChildReference(Request $request, ProductionProject $project, ProductionProjectAsset $asset, ProductionAutomationPhase2Service $phase2, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $run = $this->activeRun($project);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $run = $phase2->approveChildReferenceManually($run, $asset, $request->user(), $validated['reason']);
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json(['ok' => true, 'automation' => $presenter->present($run, $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function rejectChildReference(Request $request, ProductionProject $project, ProductionProjectAsset $asset, ProductionAutomationPhase2Service $phase2, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $run = $this->activeRun($project);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $run = $phase2->rejectChildReferenceManually($run, $asset, $request->user(), $validated['reason']);
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json(['ok' => true, 'automation' => $presenter->present($run, $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function approvePhase3Asset(Request $request, ProductionProject $project, ProductionProjectAsset $asset, ProductionAutomationPhase3Service $phase3, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $run = $this->activeRun($project);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $run = $phase3->approveAssetManually($run, $asset, $request->user(), $validated['reason']);
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json(['ok' => true, 'automation' => $presenter->present($run, $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function rejectPhase3Asset(Request $request, ProductionProject $project, ProductionProjectAsset $asset, ProductionAutomationPhase3Service $phase3, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $run = $this->activeRun($project);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $run = $phase3->rejectAssetManually($run, $asset, $request->user(), $validated['reason']);
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json(['ok' => true, 'automation' => $presenter->present($run, $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function correctPhase3Scene(Request $request, ProductionProject $project, ProductionScene $scene, ProductionAutomationPhase3Service $phase3, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $run = $this->activeRun($project);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'story_text' => ['required', 'string', 'max:5000'],
            'visual_direction' => ['required', 'string', 'max:5000'],
            'child_action_pose' => ['required', 'string', 'max:3000'],
            'environment' => ['required', 'string', 'max:3000'],
            'mood_lighting' => ['required', 'string', 'max:2000'],
            'supporting_characters' => ['required', 'string', 'max:2000'],
            'key_objects' => ['required', 'string', 'max:2000'],
            'continuity_notes' => ['nullable', 'string', 'max:3000'],
            'text_safe_area_notes' => ['required', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $run = $phase3->correctSceneManually($run, $scene, $request->user(), $validated);
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json(['ok' => true, 'automation' => $presenter->present($run, $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function retryPhase4Layout(Request $request, ProductionProject $project, ProductionPrintLayout $layout, ProductionAutomationPhase4Service $phase4, ProductionAutomationStatusPresenter $presenter): JsonResponse
    {
        $this->ensureStudioEnabled();
        $this->ensureAutomationEnabled();
        $run = $this->activeRun($project);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $run = $phase4->retryLayout($run, $layout, $request->user(), $validated['reason']);
        } catch (Throwable $exception) {
            return $this->jsonError($exception, 422);
        }

        return response()->json(['ok' => true, 'automation' => $presenter->present($run, $request->user())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function download(ProductionProject $project, ProductionAutomationRun $run, ProductionPrintLayout $layout, string $file)
    {
        $this->ensureStudioEnabled();
        abort_unless($run->production_project_id === $project->id, 404);
        abort_unless($layout->production_project_id === $project->id && $layout->isValidatedAutomationReady(), 404);
        abort_unless(! $layout->production_automation_run_id || (int) $layout->production_automation_run_id === (int) $run->id, 404);
        abort_unless(in_array($run->status, [ProductionAutomation::STATUS_FILES_READY, ProductionAutomation::STATUS_COMPLETED], true), 404);

        $paths = [
            'reader' => [$layout->reader_pdf_path, 'reader-order.pdf'],
            'print' => [$layout->print_pdf_path, 'print-ready-a3-booklet.pdf'],
            'manifest' => [$layout->manifest_path, 'print-manifest.csv'],
            'proof' => [$layout->proof_checklist_path, 'proof-print-checklist.pdf'],
        ];

        abort_unless(isset($paths[$file]), 404);
        [$path, $name] = $paths[$file];
        abort_unless(is_string($path) && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 404);

        ProductionStudio::log($project, 'automation.file_downloaded', 'تم تنزيل ملف خاص من دورة الإنتاج التلقائي.', [
            'run_id' => $run->id,
            'layout_id' => $layout->id,
            'file' => $file,
        ], auth()->user());

        return Storage::disk('local')->download($path, $project->order?->order_number.'-'.$name, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function activeRun(ProductionProject $project): ProductionAutomationRun
    {
        return $project->automationRuns()
            ->with(['steps', 'project.printLayouts', 'costEntries'])
            ->where('active_project_id', $project->id)
            ->latest()
            ->firstOrFail();
    }

    private function optionsValidation(Request $request, bool $requireBudget): array
    {
        return $request->validate([
            'hard_budget' => [$requireBudget ? 'required' : 'nullable', 'numeric', 'min:0'],
            'generation_model_code' => ['nullable', 'string', 'exists:ai_models,code'],
            'cover_model_code' => ['nullable', 'string', 'exists:ai_models,code'],
            'premium_model_code' => ['nullable', 'string', 'exists:ai_models,code'],
            'validation_model_code' => ['nullable', 'string', 'exists:ai_models,code'],
            'scene_text_model_code' => ['nullable', 'string', 'exists:ai_models,code'],
            'style_preset' => ['nullable', Rule::in(array_keys(config('production_studio.ai.style_presets', [])))],
            'generation_quality' => ['nullable', Rule::in(['medium', 'high'])],
            'scene_concurrency' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);
    }

    private function ensureStudioEnabled(): void
    {
        abort_unless(ProductionStudio::enabled(), 404);
    }

    private function ensureAutomationEnabled(): void
    {
        abort_unless(ProductionAutomation::enabled(), 404);
    }

    private function jsonError(Throwable $exception, int $status): JsonResponse
    {
        $message = preg_replace('/(?:\/[^\s]+)+/', '[path redacted]', $exception->getMessage());
        $message = preg_replace('/Bearer\s+[A-Za-z0-9_\-:.]+/', 'Bearer [redacted]', $message ?: '');
        $message = preg_replace('/Key\s+[A-Za-z0-9_\-:.]+/', 'Key [redacted]', $message ?: '');

        return response()->json([
            'ok' => false,
            'message' => $message ?: 'Automation request failed.',
        ], $status)->header('Cache-Control', 'no-store, private');
    }
}
