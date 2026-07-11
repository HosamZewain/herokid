<?php

namespace App\Services\ProductionStudio;

use App\Models\ProductionAutomationProof;
use App\Models\ProductionAutomationRun;
use App\Models\ProductionPrintLayout;
use App\Models\User;
use App\Support\ProductionAutomation;
use App\Support\ProductionStudio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProductionAutomationFinalProofService
{
    public function __construct(
        private readonly ProductionAutomationStateMachine $stateMachine,
        private readonly ProductionAutomationProgress $progress,
        private readonly ProductionAutomationFingerprint $fingerprints,
        private readonly ProductionAutomationLayoutValidator $layoutValidator,
    ) {}

    public function checklistItems(): array
    {
        return [
            'identity.child_identity_consistent' => ['group' => 'Identity and Artwork', 'label' => 'Child identity is consistent across cover and all scenes.', 'mandatory' => true],
            'identity.child_age_appropriate' => ['group' => 'Identity and Artwork', 'label' => 'Child age appearance is appropriate.', 'mandatory' => true],
            'identity.no_unrelated_child' => ['group' => 'Identity and Artwork', 'label' => 'No incorrect or unrelated child appears.', 'mandatory' => true],
            'identity.no_text_logos_watermarks' => ['group' => 'Identity and Artwork', 'label' => 'No unwanted text, logos, or watermarks appear.', 'mandatory' => true],
            'identity.cover_relevant' => ['group' => 'Identity and Artwork', 'label' => 'Cover artwork is relevant to the story.', 'mandatory' => true],
            'identity.scenes_match_intent' => ['group' => 'Identity and Artwork', 'label' => 'All scene artwork matches the intended scene.', 'mandatory' => true],
            'identity.no_generation_defects' => ['group' => 'Identity and Artwork', 'label' => 'No image contains visible generation defects requiring replacement.', 'mandatory' => true],
            'story.child_name_correct' => ['group' => 'Story and Arabic Content', 'label' => 'Child name is correct everywhere.', 'mandatory' => true],
            'story.title_correct' => ['group' => 'Story and Arabic Content', 'label' => 'Story title is correct.', 'mandatory' => true],
            'story.no_old_hero' => ['group' => 'Story and Arabic Content', 'label' => 'No old template hero name remains.', 'mandatory' => true],
            'story.arabic_spelling' => ['group' => 'Story and Arabic Content', 'label' => 'Arabic spelling is correct.', 'mandatory' => true],
            'story.arabic_punctuation' => ['group' => 'Story and Arabic Content', 'label' => 'Arabic punctuation is correct.', 'mandatory' => true],
            'story.arabic_shaping' => ['group' => 'Story and Arabic Content', 'label' => 'Arabic shaping is visually correct.', 'mandatory' => true],
            'story.rtl_direction' => ['group' => 'Story and Arabic Content', 'label' => 'RTL direction is correct.', 'mandatory' => true],
            'story.text_readable' => ['group' => 'Story and Arabic Content', 'label' => 'Text is readable at print size.', 'mandatory' => true],
            'story.text_scene_pairing' => ['group' => 'Story and Arabic Content', 'label' => 'Text is paired with the correct scene.', 'mandatory' => true],
            'story.no_text_overflow' => ['group' => 'Story and Arabic Content', 'label' => 'No text is clipped or overflowing.', 'mandatory' => true],
            'layout.front_cover_position' => ['group' => 'Page and Layout', 'label' => 'Front cover is correctly positioned.', 'mandatory' => true],
            'layout.back_cover_position' => ['group' => 'Page and Layout', 'label' => 'Back cover is correctly positioned.', 'mandatory' => true],
            'layout.all_scenes_included' => ['group' => 'Page and Layout', 'label' => 'All 13 scenes are included.', 'mandatory' => true],
            'layout.no_missing_scene' => ['group' => 'Page and Layout', 'label' => 'No scene is missing.', 'mandatory' => true],
            'layout.no_duplicate_scene' => ['group' => 'Page and Layout', 'label' => 'No scene is duplicated.', 'mandatory' => true],
            'layout.reader_order' => ['group' => 'Page and Layout', 'label' => 'Reader PDF order is correct.', 'mandatory' => true],
            'layout.imposed_order' => ['group' => 'Page and Layout', 'label' => 'Imposed booklet order is correct.', 'mandatory' => true],
            'layout.no_unintended_blank' => ['group' => 'Page and Layout', 'label' => 'No unintended blank page exists.', 'mandatory' => true],
            'layout.margins' => ['group' => 'Page and Layout', 'label' => 'Margins are acceptable.', 'mandatory' => true],
            'layout.fold_areas' => ['group' => 'Page and Layout', 'label' => 'Fold areas are safe.', 'mandatory' => true],
            'layout.trim_areas' => ['group' => 'Page and Layout', 'label' => 'Trim areas are safe.', 'mandatory' => true],
            'layout.faces_not_cropped' => ['group' => 'Page and Layout', 'label' => 'Faces and important elements are not cropped.', 'mandatory' => true],
            'layout.page_orientation' => ['group' => 'Page and Layout', 'label' => 'Page orientation is correct.', 'mandatory' => true],
            'print.test_print_done' => ['group' => 'Physical Test Print', 'label' => 'Test print was produced from the imposed A3 PDF.', 'mandatory' => true],
            'print.a3_landscape' => ['group' => 'Physical Test Print', 'label' => 'A3 landscape paper orientation was correct.', 'mandatory' => true],
            'print.duplex_direction' => ['group' => 'Physical Test Print', 'label' => 'Duplex printing direction was correct.', 'mandatory' => true],
            'print.flip_edge' => ['group' => 'Physical Test Print', 'label' => 'Correct flip edge was used.', 'mandatory' => true],
            'print.fold_reading_order' => ['group' => 'Physical Test Print', 'label' => 'Folding produced correct A4 reading order.', 'mandatory' => true],
            'print.side_alignment' => ['group' => 'Physical Test Print', 'label' => 'Front and back sides align acceptably.', 'mandatory' => true],
            'print.colors' => ['group' => 'Physical Test Print', 'label' => 'Colors are acceptable.', 'mandatory' => true],
            'print.dark_areas' => ['group' => 'Physical Test Print', 'label' => 'Dark areas are not excessively muddy.', 'mandatory' => true],
            'print.skin_tones' => ['group' => 'Physical Test Print', 'label' => 'Skin tones are acceptable.', 'mandatory' => true],
            'print.text_sharp' => ['group' => 'Physical Test Print', 'label' => 'Text remains sharp and readable.', 'mandatory' => true],
            'print.image_resolution' => ['group' => 'Physical Test Print', 'label' => 'Images have acceptable print resolution.', 'mandatory' => true],
            'print.cover_paper' => ['group' => 'Physical Test Print', 'label' => 'Cover paper result is acceptable.', 'mandatory' => true],
            'print.inner_paper' => ['group' => 'Physical Test Print', 'label' => 'Inner paper result is acceptable.', 'mandatory' => true],
            'print.binding_result' => ['group' => 'Physical Test Print', 'label' => 'Binding/folding result is acceptable.', 'mandatory' => true],
            'final.reader_checksum' => ['group' => 'Final Confirmation', 'label' => 'Reader PDF checksum matches the reviewed file.', 'mandatory' => true],
            'final.imposed_checksum' => ['group' => 'Final Confirmation', 'label' => 'Imposed PDF checksum matches the printed file.', 'mandatory' => true],
            'final.manifest_checksum' => ['group' => 'Final Confirmation', 'label' => 'Manifest checksum matches the reviewed production package.', 'mandatory' => true],
            'final.no_file_replaced' => ['group' => 'Final Confirmation', 'label' => 'No file was replaced after the physical proof.', 'mandatory' => true],
            'final.ready_for_print' => ['group' => 'Final Confirmation', 'label' => 'The reviewer confirms the production package is ready for printing.', 'mandatory' => true],
        ];
    }

    public function createDraft(ProductionAutomationRun $run, User $actor): ProductionAutomationProof
    {
        $eligibility = $this->eligibility($run);
        if (! $eligibility['ok']) {
            if (in_array($eligibility['code'] ?? null, [
                'validated_layout_missing',
                'layout_validation_failed',
                'layout_checksum_mismatch',
                'layout_fingerprint_mismatch',
            ], true)) {
                $this->invalidateProof($proof, (string) ($eligibility['code'] ?? 'proof_files_changed'));
            }

            throw new RuntimeException($eligibility['summary']);
        }

        return DB::transaction(function () use ($run, $actor, $eligibility): ProductionAutomationProof {
            $lockedRun = ProductionAutomationRun::query()->with(['project', 'currentProof'])->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $layout = ProductionPrintLayout::query()->whereKey($eligibility['layout']->id)->lockForUpdate()->firstOrFail();
            $inputFingerprint = $eligibility['input_fingerprint'];
            $checksums = $eligibility['checksums'];

            $current = $lockedRun->currentProof;
            if ($current
                && in_array($current->status, ['draft', 'in_review'], true)
                && $current->production_print_layout_id === $layout->id
                && hash_equals((string) $current->input_fingerprint, $inputFingerprint)
                && $this->proofChecksumsMatch($current, $checksums)) {
                return $current->fresh(['layout', 'reviewer']);
            }

            if ($current && in_array($current->status, ['draft', 'in_review'], true)) {
                $current->update([
                    'status' => 'invalidated',
                    'current_run_id' => null,
                    'invalidated_at' => now(),
                    'invalidation_reason' => 'A newer proof draft was created for current files.',
                ]);
            }

            $version = ((int) $lockedRun->proofs()->max('proof_version')) + 1;
            $proof = $lockedRun->proofs()->create([
                'current_run_id' => $lockedRun->id,
                'production_print_layout_id' => $layout->id,
                'proof_version' => $version,
                'status' => 'draft',
                'input_fingerprint' => $inputFingerprint,
                'reader_pdf_checksum' => $checksums['reader_pdf'],
                'imposed_pdf_checksum' => $checksums['print_pdf'],
                'manifest_checksum' => $checksums['manifest'],
                'proof_checklist_checksum' => $checksums['proof_checklist'],
                'checklist_snapshot' => $this->blankChecklistSnapshot(),
                'report_status' => 'pending',
            ]);

            ProductionStudio::log($lockedRun->project, 'automation.proof_draft_created', 'تم إنشاء مسودة مراجعة نهائية للإنتاج التلقائي.', [
                'run_id' => $lockedRun->id,
                'proof_id' => $proof->id,
                'proof_version' => $proof->proof_version,
                'layout_id' => $layout->id,
                'layout_version' => $layout->version_number,
            ], $actor);

            return $proof->fresh(['layout', 'reviewer']);
        });
    }

    public function approve(ProductionAutomationRun $run, ProductionAutomationProof $proof, User $actor, array $payload): ProductionAutomationProof
    {
        $approvalError = null;

        $proof = DB::transaction(function () use ($run, $proof, $actor, $payload, &$approvalError): ProductionAutomationProof {
            $lockedRun = ProductionAutomationRun::query()->with(['project', 'steps', 'currentProof'])->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $lockedProof = ProductionAutomationProof::query()->with(['layout', 'reviewer'])->whereKey($proof->id)->lockForUpdate()->firstOrFail();

            if ($lockedProof->automation_run_id !== $lockedRun->id) {
                throw new RuntimeException('Proof does not belong to this automation run.');
            }

            if ($lockedProof->status === 'passed' && $lockedRun->status === ProductionAutomation::STATUS_COMPLETED) {
                return $lockedProof;
            }

            if (! in_array($lockedProof->status, ['draft', 'in_review'], true) || $lockedProof->current_run_id !== $lockedRun->id) {
                throw new RuntimeException('Only the current draft proof can be approved.');
            }

            try {
                $this->assertApprovalEligibility($lockedRun, $lockedProof);
            } catch (RuntimeException $exception) {
                if ($this->shouldInvalidateApprovalFailure($exception)) {
                    if ($lockedProof->fresh()->status !== 'invalidated') {
                        $this->invalidateProof($lockedProof, 'proof_file_or_fingerprint_changed');
                    }

                    $approvalError = $exception;

                    return $lockedProof->fresh(['layout', 'reviewer', 'run.project']);
                }

                throw $exception;
            }

            $checklist = $this->normalizeChecklist($payload['checklist'] ?? [], approval: true);
            $metadata = $this->normalizePrintMetadata($payload['print_test_metadata'] ?? []);
            $this->assertReviewedChecksums($lockedProof, $payload['reviewed_checksums'] ?? []);

            $lockedProof->update([
                'status' => 'passed',
                'checklist_snapshot' => $checklist,
                'print_test_metadata' => $metadata,
                'reviewer_id' => $actor->id,
                'reviewed_at' => now(),
                'decision_reason' => $this->safeText($payload['decision_reason'] ?? 'Final human proof passed.'),
                'notes' => $this->safeText($payload['notes'] ?? null),
            ]);

            $finalStep = $lockedRun->steps->firstWhere('step_key', 'final_proof');
            if ($finalStep && $finalStep->status !== ProductionAutomation::STEP_COMPLETED) {
                $this->stateMachine->transitionStep($finalStep, ProductionAutomation::STEP_COMPLETED, [
                    'approval_type' => 'manual',
                    'approved_by_user_id' => $actor->id,
                    'input_fingerprint' => $lockedProof->input_fingerprint,
                    'output_fingerprint' => $this->proofOutputFingerprint($lockedProof),
                    'validation_summary_json' => [
                        'source' => 'phase5_final_human_proof',
                        'proof_id' => $lockedProof->id,
                        'proof_version' => $lockedProof->proof_version,
                        'reader_pdf_checksum' => $lockedProof->reader_pdf_checksum,
                        'imposed_pdf_checksum' => $lockedProof->imposed_pdf_checksum,
                        'manifest_checksum' => $lockedProof->manifest_checksum,
                    ],
                ], $actor, 'phase5_final_proof');
            }

            $lockedRun->project->update([
                'status' => 'ready_for_print',
                'current_stage' => 'print_ready',
            ]);

            $completed = $this->stateMachine->transitionRun($lockedRun, ProductionAutomation::STATUS_COMPLETED, [
                'current_stage' => 'print_ready',
                'current_step_key' => 'print_ready',
                'safe_failure_code' => 'final_proof_passed_ready_for_print',
                'safe_failure_summary' => 'Final human proof passed. Production package is ready for manual printing.',
                'blockers' => [],
            ], $actor, 'phase5_final_proof');

            ProductionStudio::log($completed->project, 'automation.proof_approved', 'تم اعتماد المراجعة النهائية وأصبح المشروع جاهزًا للطباعة.', [
                'run_id' => $completed->id,
                'proof_id' => $lockedProof->id,
                'proof_version' => $lockedProof->proof_version,
                'layout_id' => $lockedProof->production_print_layout_id,
                'reason' => $this->safeText($payload['decision_reason'] ?? 'Final human proof passed.'),
            ], $actor);

            return $lockedProof->fresh(['layout', 'reviewer', 'run.project']);
        });

        if ($approvalError) {
            throw $approvalError;
        }

        try {
            $this->generateReport($proof->fresh(['layout', 'reviewer', 'run.project.order']));
        } catch (Throwable $exception) {
            $proof->update(['report_status' => 'failed']);
            ProductionStudio::log($proof->run->project, 'automation.proof_report_failed', 'تعذر توليد تقرير المراجعة النهائية.', [
                'run_id' => $proof->automation_run_id,
                'proof_id' => $proof->id,
                'proof_version' => $proof->proof_version,
                'error' => $this->safeText($exception->getMessage()),
            ], $actor);
        }

        return $proof->fresh(['layout', 'reviewer', 'run.project']);
    }

    public function reject(ProductionAutomationRun $run, ProductionAutomationProof $proof, User $actor, array $payload): ProductionAutomationProof
    {
        return DB::transaction(function () use ($run, $proof, $actor, $payload): ProductionAutomationProof {
            $lockedRun = ProductionAutomationRun::query()->with(['project', 'steps'])->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $lockedProof = ProductionAutomationProof::query()->with(['layout'])->whereKey($proof->id)->lockForUpdate()->firstOrFail();

            if ($lockedProof->automation_run_id !== $lockedRun->id || $lockedProof->current_run_id !== $lockedRun->id) {
                throw new RuntimeException('Only the current proof can be rejected.');
            }

            if (! in_array($lockedProof->status, ['draft', 'in_review'], true)) {
                throw new RuntimeException('Only a draft proof can be rejected.');
            }

            $checklist = $this->normalizeChecklist($payload['checklist'] ?? [], approval: false);
            if (! collect($checklist)->contains(fn (array $item): bool => $item['value'] === 'fail')) {
                throw new RuntimeException('Rejecting final proof requires at least one failed checklist item.');
            }

            $component = (string) ($payload['affected_component'] ?? '');
            $reason = $this->safeText($payload['reason'] ?? null);
            if ($component === '' || $reason === '') {
                throw new RuntimeException('Rejecting final proof requires an affected component and written reason.');
            }

            $mapping = $this->rejectionMapping($component, $payload['affected_scene_number'] ?? null, $payload['failure_category'] ?? null);

            $lockedProof->update([
                'status' => 'failed',
                'current_run_id' => null,
                'checklist_snapshot' => $checklist,
                'print_test_metadata' => $this->normalizePrintMetadata($payload['print_test_metadata'] ?? [], requireForApproval: false),
                'reviewer_id' => $actor->id,
                'reviewed_at' => now(),
                'decision_reason' => $reason,
                'notes' => $this->safeText($payload['notes'] ?? null),
                'failure_category' => $this->safeCode($payload['failure_category'] ?? 'proof_review_failed'),
                'affected_component' => $component,
                'affected_scene_number' => $mapping['scene_number'],
            ]);

            $this->applyRejectionMapping($lockedRun, $lockedProof, $mapping, $actor, $reason);

            ProductionStudio::log($lockedRun->project, 'automation.proof_rejected', 'تم رفض المراجعة النهائية للإنتاج التلقائي.', [
                'run_id' => $lockedRun->id,
                'proof_id' => $lockedProof->id,
                'proof_version' => $lockedProof->proof_version,
                'affected_component' => $component,
                'target_step_key' => $mapping['step_key'],
                'reason' => $reason,
            ], $actor);

            return $lockedProof->fresh(['layout', 'reviewer']);
        });
    }

    public function generateReport(ProductionAutomationProof $proof): ProductionAutomationProof
    {
        $proof->loadMissing(['run.project.order', 'layout', 'reviewer']);

        if ($proof->hasReport() && Storage::disk('local')->exists((string) $proof->report_path)) {
            return $proof;
        }

        if ($proof->status !== 'passed') {
            throw new RuntimeException('Only passed proofs can produce final proof reports.');
        }

        $report = [
            'schema_version' => 1,
            'project' => [
                'id' => $proof->run->production_project_id,
                'order_number' => $proof->run->project->order?->order_number,
            ],
            'automation_run' => [
                'id' => $proof->automation_run_id,
                'status' => $proof->run->status,
                'ready_for_print_at' => $proof->run->completed_at?->toIso8601String(),
            ],
            'proof' => [
                'id' => $proof->id,
                'version' => $proof->proof_version,
                'status' => $proof->status,
                'reviewer' => $proof->reviewer?->name,
                'reviewed_at' => $proof->reviewed_at?->toIso8601String(),
                'decision_reason' => $proof->decision_reason,
                'notes' => $proof->notes,
            ],
            'layout' => [
                'id' => $proof->production_print_layout_id,
                'version' => $proof->layout?->version_number,
                'page_count' => data_get($proof->layout?->manifest_json, 'page_count'),
                'sheet_count' => data_get($proof->layout?->manifest_json, 'sheet_count'),
                'printed_sides' => data_get($proof->layout?->manifest_json, 'printed_sides'),
            ],
            'checksums' => [
                'reader_pdf' => $proof->reader_pdf_checksum,
                'imposed_pdf' => $proof->imposed_pdf_checksum,
                'manifest' => $proof->manifest_checksum,
                'proof_checklist' => $proof->proof_checklist_checksum,
            ],
            'print_test_metadata' => $proof->print_test_metadata ?? [],
            'completed_checklist' => $proof->checklist_snapshot ?? [],
            'audit_reference' => [
                'proof_id' => $proof->id,
                'run_id' => $proof->automation_run_id,
            ],
        ];

        $path = "production-studio/projects/{$proof->run->production_project_id}/automation/proofs/run-{$proof->automation_run_id}/proof-v{$proof->proof_version}.json";
        $contents = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Storage::disk('local')->put($path, $contents);

        $proof->update([
            'report_status' => 'ready',
            'report_path' => $path,
            'report_checksum' => hash('sha256', $contents),
            'report_generated_at' => now(),
        ]);

        ProductionStudio::log($proof->run->project, 'automation.proof_report_generated', 'تم توليد تقرير المراجعة النهائية.', [
            'run_id' => $proof->automation_run_id,
            'proof_id' => $proof->id,
            'proof_version' => $proof->proof_version,
            'report_checksum' => $proof->report_checksum,
        ], $proof->reviewer);

        return $proof->fresh(['layout', 'reviewer', 'run.project']);
    }

    public function recoverProofReports(int $limit = 20): int
    {
        $proofs = ProductionAutomationProof::query()
            ->with(['run.project', 'layout', 'reviewer'])
            ->where('status', 'passed')
            ->where(function ($query) {
                $query->whereNull('report_path')
                    ->orWhere('report_status', 'failed');
            })
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($proofs as $proof) {
            try {
                $this->generateReport($proof);
                $count++;
            } catch (Throwable) {
                $proof->update(['report_status' => 'failed']);
            }
        }

        return $count;
    }

    public function invalidateChangedPassedProofs(int $limit = 20): int
    {
        $proofs = ProductionAutomationProof::query()
            ->with(['run.project', 'layout'])
            ->where('status', 'passed')
            ->whereNotNull('current_run_id')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($proofs as $proof) {
            try {
                $this->assertProofStillMatches($proof);
            } catch (Throwable $exception) {
                $this->invalidatePassedProof($proof, 'proof_inputs_changed', $this->safeText($exception->getMessage()));
                $count++;
            }
        }

        return $count;
    }

    public function eligibility(ProductionAutomationRun $run): array
    {
        $run = $run->fresh([
            'project.printLayouts',
            'project.generationJobs',
            'steps',
            'attempts',
        ]);

        if (! $run || $run->status !== ProductionAutomation::STATUS_FILES_READY) {
            return $this->notEligible('final_proof_requires_files_ready', 'Run must be files_ready before final proof can start.');
        }

        if ($this->progress->percentage($run) !== 95) {
            return $this->notEligible('final_proof_requires_95_percent', 'Run progress must be 95% before final proof.');
        }

        $layout = $run->project->printLayouts
            ->sortByDesc('version_number')
            ->first(fn (ProductionPrintLayout $candidate): bool => $candidate->isValidatedAutomationReady());

        if (! $layout) {
            return $this->notEligible('validated_layout_missing', 'Validated production files are missing.');
        }

        $validation = $this->layoutValidator->validate($layout);
        if (! $validation['ok']) {
            return $this->notEligible('layout_validation_failed', implode(' ', $validation['errors']));
        }

        $checksums = $this->checksumsFromValidation($validation);
        if (! $this->layoutStoredChecksumsMatch($layout, $checksums)) {
            return $this->notEligible('layout_checksum_mismatch', 'Current private files no longer match the validated layout manifest.');
        }

        if (! hash_equals((string) $layout->output_fingerprint, $this->layoutValidator->outputFingerprint($layout, $validation))) {
            return $this->notEligible('layout_fingerprint_mismatch', 'Current layout files no longer match the approved output fingerprint.');
        }

        if ($this->activePhaseJobs($run) > 0) {
            return $this->notEligible('active_phase_jobs_exist', 'Previous automation jobs are still active.');
        }

        return [
            'ok' => true,
            'layout' => $layout,
            'validation' => $validation,
            'checksums' => $checksums,
            'input_fingerprint' => $this->proofInputFingerprint($run, $layout, $checksums),
        ];
    }

    private function assertApprovalEligibility(ProductionAutomationRun $run, ProductionAutomationProof $proof): void
    {
        $eligibility = $this->eligibility($run);
        if (! $eligibility['ok']) {
            throw new RuntimeException($eligibility['summary']);
        }

        if ((int) $eligibility['layout']->id !== (int) $proof->production_print_layout_id) {
            $this->invalidateProof($proof, 'layout_changed_during_review');

            throw new RuntimeException('Current layout changed during final proof review.');
        }

        if (! hash_equals($proof->input_fingerprint, $eligibility['input_fingerprint']) || ! $this->proofChecksumsMatch($proof, $eligibility['checksums'])) {
            $this->invalidateProof($proof, 'proof_file_or_fingerprint_changed');

            throw new RuntimeException('Reviewed files changed during final proof review.');
        }
    }

    private function shouldInvalidateApprovalFailure(RuntimeException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'layout')
            || str_contains($message, 'files')
            || str_contains($message, 'fingerprint')
            || str_contains($message, 'checksum');
    }

    private function normalizeChecklist(array $submitted, bool $approval): array
    {
        $snapshot = [];
        foreach ($this->checklistItems() as $key => $definition) {
            $item = $submitted[$key] ?? null;
            if (! is_array($item) || ! in_array($item['value'] ?? null, ['pass', 'fail', 'not_applicable'], true)) {
                throw new RuntimeException("Checklist item {$key} must be answered.");
            }

            $value = $item['value'];
            $reason = $this->safeText($item['reason'] ?? null);
            if (($definition['mandatory'] ?? true) && $value === 'not_applicable') {
                throw new RuntimeException("Mandatory checklist item {$key} cannot be not applicable.");
            }

            if ($value === 'not_applicable' && $reason === '') {
                throw new RuntimeException("Checklist item {$key} requires a not-applicable reason.");
            }

            if ($approval && $value !== 'pass') {
                throw new RuntimeException("Checklist item {$key} must pass before final proof approval.");
            }

            $snapshot[$key] = $definition + [
                'value' => $value,
                'reason' => $reason ?: null,
            ];
        }

        return $snapshot;
    }

    private function normalizePrintMetadata(array $metadata, bool $requireForApproval = true): array
    {
        $required = ['proof_print_date', 'printer_name', 'paper_size', 'duplex_setting', 'flip_edge', 'print_quality', 'test_copies'];
        if ($requireForApproval) {
            foreach ($required as $field) {
                if (blank($metadata[$field] ?? null)) {
                    throw new RuntimeException("Print proof metadata {$field} is required.");
                }
            }
        }

        return collect($metadata)
            ->only([
                'proof_print_date',
                'printer_name',
                'printer_model',
                'paper_size',
                'cover_paper_type',
                'cover_paper_gsm',
                'inner_paper_type',
                'inner_paper_gsm',
                'duplex_setting',
                'flip_edge',
                'print_quality',
                'test_copies',
                'reviewer_notes',
                'observed_color_issues',
                'observed_alignment_issues',
            ])
            ->map(fn ($value) => is_string($value) ? $this->safeText($value) : $value)
            ->all();
    }

    private function assertReviewedChecksums(ProductionAutomationProof $proof, array $checksums): void
    {
        $expected = [
            'reader_pdf' => $proof->reader_pdf_checksum,
            'imposed_pdf' => $proof->imposed_pdf_checksum,
            'manifest' => $proof->manifest_checksum,
        ];

        foreach ($expected as $key => $value) {
            if (! hash_equals((string) $value, (string) ($checksums[$key] ?? ''))) {
                throw new RuntimeException("Reviewed {$key} checksum does not match the current proof file.");
            }
        }
    }

    private function applyRejectionMapping(ProductionAutomationRun $run, ProductionAutomationProof $proof, array $mapping, User $actor, string $reason): void
    {
        if ($mapping['preserve_files']) {
            ProductionStudio::log($run->project, 'automation.proof_rejected_files_preserved', 'تم رفض المراجعة مع الحفاظ على ملفات الطباعة الحالية.', [
                'run_id' => $run->id,
                'proof_id' => $proof->id,
                'component' => $mapping['component'],
                'reason' => $reason,
            ], $actor);

            return;
        }

        if ($mapping['invalidate_layout']) {
            $run->project->printLayouts()
                ->where('production_automation_run_id', $run->id)
                ->where('status', 'ready')
                ->update(['status' => 'invalidated']);
        }

        if ($mapping['component'] === 'cover') {
            $run->project->assets()
                ->where('asset_type', 'cover_image')
                ->where('status', 'approved')
                ->where('is_final', true)
                ->update(['is_final' => false]);
        }

        if ($mapping['component'] === 'specific_scene' && $mapping['scene_number']) {
            $scene = $run->project->scenes()->where('scene_number', $mapping['scene_number'])->first();
            if ($scene) {
                $scene->assets()
                    ->where('asset_type', 'scene_image')
                    ->where('status', 'approved')
                    ->where('is_final', true)
                    ->update(['is_final' => false]);
            }
        }

        $target = $run->steps()->where('step_key', $mapping['step_key'])->first();
        if ($target) {
            $this->stateMachine->transitionStep($target, ProductionAutomation::STEP_QUEUED, [
                'manual_invalidation' => true,
                'safe_failure_code' => 'final_proof_rejected',
                'safe_failure_summary' => $reason,
                'metadata_json' => [
                    'proof_id' => $proof->id,
                    'affected_component' => $mapping['component'],
                    'affected_scene_number' => $mapping['scene_number'],
                ],
            ], $actor, 'phase5_proof_rejection');
        }

        $run->project->update([
            'status' => 'in_progress',
            'current_stage' => $mapping['stage'],
        ]);

        $this->stateMachine->transitionRun($run, ProductionAutomation::STATUS_PAUSED_REVIEW, [
            'pause_reason' => 'final_proof_rejected',
            'current_stage' => $mapping['stage'],
            'current_step_key' => $mapping['step_key'],
            'safe_failure_code' => 'final_proof_rejected',
            'safe_failure_summary' => $reason,
            'blockers' => [[
                'code' => 'final_proof_rejected',
                'summary' => $reason,
                'affected_component' => $mapping['component'],
                'step_key' => $mapping['step_key'],
            ]],
        ], $actor, 'phase5_proof_rejection');
    }

    private function rejectionMapping(string $component, mixed $sceneNumber, ?string $category): array
    {
        $component = $this->safeCode($component);
        $category = $this->safeCode($category ?: 'proof_review_failed');
        $sceneNumber = $sceneNumber ? (int) $sceneNumber : null;

        if ($component === 'specific_scene' && (! $sceneNumber || $sceneNumber < 1 || $sceneNumber > 13)) {
            throw new RuntimeException('A specific scene rejection requires a scene number from 1 to 13.');
        }

        return match ($component) {
            'story_text', 'font_or_arabic_rendering' => [
                'component' => $component,
                'scene_number' => null,
                'step_key' => 'story_preparation',
                'stage' => 'story_preparation',
                'invalidate_layout' => true,
                'preserve_files' => false,
            ],
            'cover' => [
                'component' => 'cover',
                'scene_number' => null,
                'step_key' => 'cover',
                'stage' => 'cover',
                'invalidate_layout' => true,
                'preserve_files' => false,
            ],
            'specific_scene' => [
                'component' => 'specific_scene',
                'scene_number' => $sceneNumber,
                'step_key' => 'scene_'.str_pad((string) $sceneNumber, 2, '0', STR_PAD_LEFT),
                'stage' => 'scenes',
                'invalidate_layout' => true,
                'preserve_files' => false,
            ],
            'reader_layout', 'imposition' => [
                'component' => $component,
                'scene_number' => null,
                'step_key' => 'layout_print',
                'stage' => 'layout_print',
                'invalidate_layout' => true,
                'preserve_files' => false,
            ],
            'duplex_or_binding', 'color_output' => [
                'component' => $component,
                'scene_number' => null,
                'step_key' => 'final_proof',
                'stage' => 'quality_check',
                'invalidate_layout' => $category !== 'printer_settings',
                'preserve_files' => $category === 'printer_settings',
            ],
            'image_quality' => [
                'component' => $sceneNumber ? 'specific_scene' : 'cover',
                'scene_number' => $sceneNumber,
                'step_key' => $sceneNumber ? 'scene_'.str_pad((string) $sceneNumber, 2, '0', STR_PAD_LEFT) : 'cover',
                'stage' => $sceneNumber ? 'scenes' : 'cover',
                'invalidate_layout' => true,
                'preserve_files' => false,
            ],
            default => [
                'component' => 'other',
                'scene_number' => null,
                'step_key' => 'layout_print',
                'stage' => 'layout_print',
                'invalidate_layout' => true,
                'preserve_files' => false,
            ],
        };
    }

    private function invalidatePassedProof(ProductionAutomationProof $proof, string $code, string $reason): void
    {
        DB::transaction(function () use ($proof, $code, $reason): void {
            $locked = ProductionAutomationProof::query()->with(['run.project', 'run.steps'])->whereKey($proof->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'passed') {
                return;
            }

            $locked->update([
                'status' => 'invalidated',
                'current_run_id' => null,
                'invalidated_at' => now(),
                'invalidation_reason' => $reason,
            ]);

            $run = $locked->run;
            $finalStep = $run->steps->firstWhere('step_key', 'final_proof');
            if ($finalStep) {
                $this->stateMachine->transitionStep($finalStep, ProductionAutomation::STEP_QUEUED, [
                    'manual_invalidation' => true,
                    'safe_failure_code' => $code,
                    'safe_failure_summary' => $reason,
                    'metadata_json' => ['proof_id' => $locked->id],
                ], null, 'phase5_proof_invalidation');
            }

            $run->project->update(['status' => 'in_progress', 'current_stage' => 'quality_check']);
            $this->stateMachine->transitionRun($run, ProductionAutomation::STATUS_PAUSED_REVIEW, [
                'pause_reason' => 'passed_proof_invalidated',
                'current_stage' => 'quality_check',
                'current_step_key' => 'final_proof',
                'active_project_id' => $run->production_project_id,
                'safe_failure_code' => $code,
                'safe_failure_summary' => $reason,
                'blockers' => [['code' => $code, 'summary' => $reason]],
            ], null, 'phase5_proof_invalidation');

            ProductionStudio::log($run->project, 'automation.proof_invalidated', 'تم إبطال مراجعة نهائية سابقة بسبب تغير الملفات أو المدخلات.', [
                'run_id' => $run->id,
                'proof_id' => $locked->id,
                'proof_version' => $locked->proof_version,
                'reason' => $reason,
            ]);
        });
    }

    private function assertProofStillMatches(ProductionAutomationProof $proof): void
    {
        $run = $proof->run;
        $layout = $proof->layout;
        if (! $run || ! $layout || ! $layout->isValidatedAutomationReady()) {
            throw new RuntimeException('Passed proof layout is no longer validated.');
        }

        $validation = $this->layoutValidator->validate($layout);
        if (! $validation['ok']) {
            throw new RuntimeException('Passed proof files no longer validate.');
        }

        $checksums = $this->checksumsFromValidation($validation);
        if (! $this->proofChecksumsMatch($proof, $checksums)) {
            throw new RuntimeException('Passed proof file checksums changed.');
        }

        if (! hash_equals((string) $proof->input_fingerprint, $this->proofInputFingerprint($run, $layout, $checksums))) {
            throw new RuntimeException('Passed proof input fingerprint changed.');
        }
    }

    private function invalidateProof(ProductionAutomationProof $proof, string $reason): void
    {
        $proof->update([
            'status' => 'invalidated',
            'current_run_id' => null,
            'invalidated_at' => now(),
            'invalidation_reason' => $reason,
        ]);

        ProductionStudio::log($proof->run->project, 'automation.proof_invalidated', 'تم إبطال مسودة المراجعة النهائية.', [
            'run_id' => $proof->automation_run_id,
            'proof_id' => $proof->id,
            'proof_version' => $proof->proof_version,
            'reason' => $reason,
        ]);
    }

    private function blankChecklistSnapshot(): array
    {
        return collect($this->checklistItems())
            ->map(fn (array $definition): array => $definition + ['value' => null, 'reason' => null])
            ->all();
    }

    private function proofInputFingerprint(ProductionAutomationRun $run, ProductionPrintLayout $layout, array $checksums): string
    {
        return $this->fingerprints->hash([
            'type' => 'final_human_proof',
            'automation_run_id' => $run->id,
            'layout_id' => $layout->id,
            'layout_version' => $layout->version_number,
            'layout_input_fingerprint' => $layout->input_fingerprint,
            'layout_output_fingerprint' => $layout->output_fingerprint,
            'checksums' => $checksums,
            'page_map_version' => data_get($layout->manifest_json, 'page_map_version'),
            'layout_template_version' => data_get($layout->manifest_json, 'layout_template_version'),
            'renderer_version' => data_get($layout->manifest_json, 'validation.renderer_version'),
            'font_package_version' => data_get($layout->manifest_json, 'validation.font_package_version'),
        ]);
    }

    private function proofOutputFingerprint(ProductionAutomationProof $proof): string
    {
        return $this->fingerprints->hash([
            'type' => 'final_human_proof_decision',
            'proof_id' => $proof->id,
            'proof_version' => $proof->proof_version,
            'input_fingerprint' => $proof->input_fingerprint,
            'status' => 'passed',
            'reviewed_at' => $proof->reviewed_at?->toIso8601String(),
        ]);
    }

    private function checksumsFromValidation(array $validation): array
    {
        return [
            'reader_pdf' => (string) data_get($validation, 'files.reader_pdf.sha256'),
            'print_pdf' => (string) data_get($validation, 'files.print_pdf.sha256'),
            'manifest' => (string) data_get($validation, 'files.manifest.sha256'),
            'proof_checklist' => (string) data_get($validation, 'files.proof_checklist.sha256'),
        ];
    }

    private function layoutStoredChecksumsMatch(ProductionPrintLayout $layout, array $checksums): bool
    {
        return hash_equals((string) data_get($layout->manifest_json, 'files.reader_pdf.sha256'), $checksums['reader_pdf'])
            && hash_equals((string) data_get($layout->manifest_json, 'files.print_pdf.sha256'), $checksums['print_pdf'])
            && hash_equals((string) data_get($layout->manifest_json, 'files.manifest.sha256'), $checksums['manifest'])
            && hash_equals((string) data_get($layout->manifest_json, 'files.proof_checklist.sha256'), $checksums['proof_checklist']);
    }

    private function proofChecksumsMatch(ProductionAutomationProof $proof, array $checksums): bool
    {
        return hash_equals((string) $proof->reader_pdf_checksum, $checksums['reader_pdf'])
            && hash_equals((string) $proof->imposed_pdf_checksum, $checksums['print_pdf'])
            && hash_equals((string) $proof->manifest_checksum, $checksums['manifest'])
            && hash_equals((string) $proof->proof_checklist_checksum, $checksums['proof_checklist']);
    }

    private function activePhaseJobs(ProductionAutomationRun $run): int
    {
        return $run->project->generationJobs()
            ->where('production_automation_run_id', $run->id)
            ->whereIn('status', ['queued', 'processing'])
            ->count()
            + $run->project->printLayouts()
                ->where('production_automation_run_id', $run->id)
                ->whereIn('status', ['queued', 'processing', 'validating'])
                ->count()
            + $run->attempts()
                ->whereIn('status', ['queued', 'running'])
                ->count();
    }

    private function safeText(?string $text): string
    {
        $text = trim((string) $text);
        $text = preg_replace('/(?:\/[^\s]+)+/', '[path redacted]', $text ?: '');
        $text = preg_replace('/https?:\/\/\S+/', '[url redacted]', $text ?: '');
        $text = preg_replace('/Bearer\s+[A-Za-z0-9_\-:.]+/', 'Bearer [redacted]', $text ?: '');
        $text = preg_replace('/Key\s+[A-Za-z0-9_\-:.]+/', 'Key [redacted]', $text ?: '');

        return Str::limit($text ?: '', 2000);
    }

    private function safeCode(?string $code): string
    {
        return Str::of((string) $code)->lower()->replaceMatches('/[^a-z0-9_]+/', '_')->trim('_')->limit(80, '')->toString();
    }

    private function notEligible(string $code, string $summary): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'summary' => $summary,
        ];
    }
}
