<?php

namespace Tests\Feature;

use App\Jobs\AdvanceProductionAutomationRun;
use App\Jobs\GenerateProductionLayoutJob;
use App\Jobs\PollAiGenerationJob;
use App\Jobs\ProcessStructuredAiJob;
use App\Jobs\SubmitAiGenerationJob;
use App\Models\AiProvider;
use App\Models\Order;
use App\Models\Permission;
use App\Models\ProductionAutomationRun;
use App\Models\ProductionProject;
use App\Models\SceneGenerationJob;
use App\Models\Story;
use App\Models\User;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\AiProviderRegistrySyncer;
use App\Services\ProductionStudio\ProductionAutomationCostLedger;
use App\Services\ProductionStudio\ProductionAutomationFinalProofService;
use App\Services\ProductionStudio\ProductionAutomationLayoutValidator;
use App\Services\ProductionStudio\ProductionAutomationPreflightService;
use App\Services\ProductionStudio\ProductionAutomationRunService;
use App\Services\ProductionStudio\ProductionAutomationVisualValidator;
use App\Services\ProductionStudio\ProductionLayoutBuilder;
use App\Support\ProductionAutomation;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProductionAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Storage::fake('local');
        Config::set('production_studio.enabled', true);
        Config::set('production_studio.automation.enabled', true);
        Config::set('queue.default', 'database');
    }

    public function test_preflight_reports_disabled_automation_without_starting_a_run(): void
    {
        Config::set('production_studio.automation.enabled', false);
        [$admin, $project] = $this->projectWithPhoto();

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.preflight', $project), [])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('preflight.ok', false);

        $this->assertDatabaseCount('production_automation_runs', 0);
    }

    public function test_start_creates_one_active_run_with_steps_and_dispatches_advance_job(): void
    {
        Queue::fake();
        $this->enableProviders();
        [$admin, $project] = $this->projectWithPhoto();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.start', $project), [
                'hard_budget' => '2.00',
                'scene_concurrency' => 2,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('automation.run.status', ProductionAutomation::STATUS_QUEUED)
            ->assertJsonPath('automation.run.progress', 5);

        $run = ProductionAutomationRun::firstOrFail();
        $this->assertSame($project->id, $run->active_project_id);
        $this->assertSame(20, $run->steps()->count());
        $this->assertDatabaseHas('production_automation_steps', [
            'automation_run_id' => $run->id,
            'step_key' => 'preflight',
            'status' => ProductionAutomation::STEP_COMPLETED,
        ]);
        Queue::assertPushed(AdvanceProductionAutomationRun::class, fn ($job): bool => $job->automationRunId === $run->id);

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.start', $project), ['hard_budget' => '2.00'])
            ->assertUnprocessable();
    }

    public function test_status_endpoint_sanitizes_costs_for_users_without_cost_permission(): void
    {
        [$admin, $project] = $this->projectWithPhoto(['production_studio.view']);
        $run = ProductionAutomationRun::create([
            'production_project_id' => $project->id,
            'active_project_id' => $project->id,
            'status' => ProductionAutomation::STATUS_PAUSED_REVIEW,
            'current_stage' => 'story_preparation',
            'current_step_key' => 'story_preparation',
            'hard_budget' => '1.0000',
            'currency' => 'USD',
            'last_transition_at' => now(),
            'last_heartbeat_at' => now(),
            'blockers_json' => [['code' => 'review', 'summary' => 'Needs review']],
        ]);
        $run->steps()->create([
            'production_project_id' => $project->id,
            'step_key' => 'story_preparation',
            'name' => 'Story Preparation',
            'sequence' => 20,
            'stage' => 'story_preparation',
            'status' => ProductionAutomation::STEP_WAITING_REVIEW,
            'weight' => 15,
        ]);
        $run->costEntries()->create([
            'status' => 'reserved',
            'estimated_amount' => '0.5000',
            'currency' => 'USD',
            'provider' => 'fal',
            'model' => 'private-model',
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.production-studio.automation.status', $project))
            ->assertOk()
            ->assertJsonPath('automation.costs', null)
            ->assertJsonMissing(['private_path' => 'storage/app/private/secret.png']);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_final_proof_draft_requires_files_ready_run(): void
    {
        [$admin, $project] = $this->projectWithPhoto();
        $run = ProductionAutomationRun::create([
            'production_project_id' => $project->id,
            'active_project_id' => $project->id,
            'status' => ProductionAutomation::STATUS_RUNNING,
            'current_stage' => 'layout_print',
            'current_step_key' => 'layout_print',
            'hard_budget' => '1.0000',
            'currency' => 'USD',
            'last_transition_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
        app(ProductionAutomationRunService::class)->seedSteps($run);

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.final-proof.draft', $project))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->assertDatabaseCount('production_automation_proofs', 0);
    }

    public function test_full_mocked_phase_five_lifecycle_approves_report_and_keeps_order_status_unchanged(): void
    {
        [$admin, $project] = $this->projectWithPhoto();
        $orderStatus = $project->order->status;
        [$run, $layout] = $this->seedPhaseFourFilesReadyRun($project, $admin);

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.final-proof.draft', $project))
            ->assertOk()
            ->assertJsonPath('proof.status', 'draft')
            ->assertJsonPath('automation.run.progress', 95);

        $proof = $run->proofs()->firstOrFail();
        $approvalPayload = [
            'checklist' => $this->passingChecklist(),
            'print_test_metadata' => $this->printProofMetadata(),
            'reviewed_checksums' => $this->reviewedChecksums($proof),
            'decision_reason' => 'Physical proof passed and files match.',
            'notes' => 'Ready for manual print production.',
        ];

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.final-proof.approve', [$project, $proof]), $approvalPayload)
            ->assertOk()
            ->assertJsonPath('proof.status', 'passed')
            ->assertJsonPath('proof.report_status', 'ready')
            ->assertJsonPath('automation.run.status', ProductionAutomation::STATUS_COMPLETED)
            ->assertJsonPath('automation.run.progress', 100)
            ->assertJsonPath('automation.phase5.ready_for_print', true);

        $proof->refresh();
        $this->assertSame('passed', $proof->status);
        $this->assertSame($admin->id, $proof->reviewer_id);
        $this->assertTrue($proof->hasReport());
        $this->assertNotNull($proof->report_checksum);
        Storage::disk('local')->assertExists($proof->report_path);
        $this->assertStringNotContainsString('http', $proof->report_path);
        $this->assertSame('ready_for_print', $project->fresh()->status);
        $this->assertSame('print_ready', $project->fresh()->current_stage);
        $this->assertSame($orderStatus, $project->order->fresh()->status);
        $this->assertNull($run->fresh()->active_project_id);
        $this->assertDatabaseHas('production_automation_steps', [
            'automation_run_id' => $run->id,
            'step_key' => 'final_proof',
            'status' => ProductionAutomation::STEP_COMPLETED,
        ]);

        $status = $this->actingAs($admin)
            ->getJson(route('admin.production-studio.automation.status', $project))
            ->assertOk()
            ->assertJsonPath('automation.phase5.current_proof.status', 'passed')
            ->assertJsonStructure(['automation' => ['downloads' => ['reader', 'print', 'manifest', 'proof', 'proof_report']]])
            ->assertJsonMissing(['report_path' => $proof->report_path])
            ->json('automation');

        $this->actingAs($admin)
            ->get($status['downloads']['proof_report'])
            ->assertOk()
            ->assertHeader('content-disposition');

        $expiredReportUrl = URL::temporarySignedRoute(
            'admin.production-studio.automation.proof-report',
            now()->subMinute(),
            [$project, $run->fresh(), $proof],
        );
        $this->actingAs($admin)
            ->get($expiredReportUrl)
            ->assertForbidden();

        $limited = User::create([
            'name' => 'Limited Proof Viewer',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'admin',
        ]);
        $limited->permissions()->sync(Permission::where('key', 'production_studio.view')->pluck('id')->all());
        $this->actingAs($limited)
            ->get($status['downloads']['proof_report'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.final-proof.approve', [$project, $proof]), $approvalPayload)
            ->assertOk()
            ->assertJsonPath('proof.status', 'passed');

        $this->assertSame(1, $run->proofs()->count());
        $this->assertSame($layout->id, $proof->fresh()->production_print_layout_id);
    }

    public function test_final_proof_approval_requires_every_mandatory_checklist_item_to_pass(): void
    {
        [$admin, $project] = $this->projectWithPhoto();
        [$run] = $this->seedPhaseFourFilesReadyRun($project, $admin);
        $proof = app(ProductionAutomationFinalProofService::class)->createDraft($run, $admin);
        $checklist = $this->passingChecklist();
        unset($checklist['story.arabic_spelling']);

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.final-proof.approve', [$project, $proof]), [
                'checklist' => $checklist,
                'print_test_metadata' => $this->printProofMetadata(),
                'reviewed_checksums' => $this->reviewedChecksums($proof),
            ])
            ->assertUnprocessable();

        $checklist = $this->passingChecklist();
        $checklist['print.test_print_done']['value'] = 'fail';

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.final-proof.approve', [$project, $proof]), [
                'checklist' => $checklist,
                'print_test_metadata' => $this->printProofMetadata(),
                'reviewed_checksums' => $this->reviewedChecksums($proof),
            ])
            ->assertUnprocessable();

        $this->assertSame(ProductionAutomation::STATUS_FILES_READY, $run->fresh()->status);
        $this->assertSame('draft', $proof->fresh()->status);
    }

    public function test_final_proof_approval_detects_file_checksum_change_during_review(): void
    {
        [$admin, $project] = $this->projectWithPhoto();
        [$run, $layout] = $this->seedPhaseFourFilesReadyRun($project, $admin);
        $proof = app(ProductionAutomationFinalProofService::class)->createDraft($run, $admin);
        Storage::disk('local')->put($layout->reader_pdf_path, $this->fakePdf(28, 'reader-changed'));

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.final-proof.approve', [$project, $proof]), [
                'checklist' => $this->passingChecklist(),
                'print_test_metadata' => $this->printProofMetadata(),
                'reviewed_checksums' => $this->reviewedChecksums($proof),
            ])
            ->assertUnprocessable();

        $proof->refresh();
        $this->assertSame('invalidated', $proof->status);
        $this->assertNull($proof->current_run_id);
        $this->assertSame(ProductionAutomation::STATUS_FILES_READY, $run->fresh()->status);
    }

    public function test_final_proof_rejection_routes_specific_scene_back_to_scene_correction_without_paid_requests(): void
    {
        [$admin, $project] = $this->projectWithPhoto();
        [$run] = $this->seedPhaseFourFilesReadyRun($project, $admin);
        $proof = app(ProductionAutomationFinalProofService::class)->createDraft($run, $admin);
        $checklist = $this->passingChecklist();
        $checklist['identity.scenes_match_intent']['value'] = 'fail';
        $checklist['identity.scenes_match_intent']['reason'] = 'Scene 4 does not match the approved story moment.';

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.final-proof.reject', [$project, $proof]), [
                'checklist' => $checklist,
                'print_test_metadata' => $this->printProofMetadata(),
                'failure_category' => 'wrong_scene_image',
                'affected_component' => 'specific_scene',
                'affected_scene_number' => 4,
                'reason' => 'Scene 4 image must be corrected before printing.',
            ])
            ->assertOk()
            ->assertJsonPath('proof.status', 'failed')
            ->assertJsonPath('automation.run.status', ProductionAutomation::STATUS_PAUSED_REVIEW)
            ->assertJsonPath('automation.run.current_step_key', 'scene_04');

        $this->assertDatabaseHas('production_automation_steps', [
            'automation_run_id' => $run->id,
            'step_key' => 'scene_04',
            'status' => ProductionAutomation::STEP_QUEUED,
        ]);
        $this->assertSame(0, $project->scenes()->where('scene_number', 4)->firstOrFail()->assets()->where('is_final', true)->count());
        $this->assertSame(12, $project->assets()->where('asset_type', 'scene_image')->where('is_final', true)->count());
        $this->assertSame(0, SceneGenerationJob::where('production_automation_run_id', $run->id)->count());
    }

    public function test_printer_setting_rejection_preserves_valid_files_and_allows_new_proof_version(): void
    {
        [$admin, $project] = $this->projectWithPhoto();
        [$run, $layout] = $this->seedPhaseFourFilesReadyRun($project, $admin);
        $proof = app(ProductionAutomationFinalProofService::class)->createDraft($run, $admin);
        $checklist = $this->passingChecklist();
        $checklist['print.duplex_direction']['value'] = 'fail';
        $checklist['print.duplex_direction']['reason'] = 'Printer was configured with the wrong flip edge.';

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.final-proof.reject', [$project, $proof]), [
                'checklist' => $checklist,
                'print_test_metadata' => $this->printProofMetadata(),
                'failure_category' => 'printer_settings',
                'affected_component' => 'duplex_or_binding',
                'reason' => 'Repeat physical proof with corrected printer settings.',
            ])
            ->assertOk()
            ->assertJsonPath('proof.status', 'failed')
            ->assertJsonPath('automation.run.status', ProductionAutomation::STATUS_FILES_READY);

        $this->assertSame('ready', $layout->fresh()->status);
        $newDraft = app(ProductionAutomationFinalProofService::class)->createDraft($run->fresh(), $admin);
        $this->assertSame(2, $newDraft->proof_version);
        $this->assertSame($layout->id, $newDraft->production_print_layout_id);
    }

    public function test_phase_five_recovery_regenerates_missing_proof_report(): void
    {
        [$admin, $project] = $this->projectWithPhoto();
        [$run] = $this->seedPhaseFourFilesReadyRun($project, $admin);
        $service = app(ProductionAutomationFinalProofService::class);
        $proof = $service->createDraft($run, $admin);
        $proof = $service->approve($run, $proof, $admin, [
            'checklist' => $this->passingChecklist(),
            'print_test_metadata' => $this->printProofMetadata(),
            'reviewed_checksums' => $this->reviewedChecksums($proof),
        ]);
        Storage::disk('local')->delete($proof->report_path);
        $proof->update([
            'report_status' => 'failed',
            'report_path' => null,
            'report_checksum' => null,
            'report_generated_at' => null,
        ]);

        $this->assertSame(1, $service->recoverProofReports());

        $proof->refresh();
        $this->assertSame('ready', $proof->report_status);
        $this->assertTrue($proof->hasReport());
        Storage::disk('local')->assertExists($proof->report_path);
    }

    public function test_phase_five_recovery_invalidates_passed_proof_when_reviewed_file_changes(): void
    {
        [$admin, $project] = $this->projectWithPhoto();
        [$run, $layout] = $this->seedPhaseFourFilesReadyRun($project, $admin);
        $service = app(ProductionAutomationFinalProofService::class);
        $proof = $service->createDraft($run, $admin);
        $proof = $service->approve($run, $proof, $admin, [
            'checklist' => $this->passingChecklist(),
            'print_test_metadata' => $this->printProofMetadata(),
            'reviewed_checksums' => $this->reviewedChecksums($proof),
        ]);
        Storage::disk('local')->put($layout->print_pdf_path, $this->fakePdf(14, 'print-changed'));

        $this->assertSame(1, $service->invalidateChangedPassedProofs());

        $proof->refresh();
        $run->refresh();
        $this->assertSame('invalidated', $proof->status);
        $this->assertNull($proof->current_run_id);
        $this->assertSame(ProductionAutomation::STATUS_PAUSED_REVIEW, $run->status);
        $this->assertSame($project->id, $run->active_project_id);
        $this->assertSame('final_proof', $run->current_step_key);
        $this->assertSame('in_progress', $project->fresh()->status);
    }

    public function test_manual_retry_override_can_exceed_automatic_attempt_limit_with_budget_confirmation(): void
    {
        Queue::fake();
        [$admin, $project] = $this->projectWithPhoto();
        $run = ProductionAutomationRun::create([
            'production_project_id' => $project->id,
            'active_project_id' => $project->id,
            'status' => ProductionAutomation::STATUS_PAUSED_REVIEW,
            'current_stage' => 'scenes',
            'current_step_key' => 'scene_01',
            'hard_budget' => '5.0000',
            'currency' => 'USD',
            'last_transition_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
        $step = $run->steps()->create([
            'production_project_id' => $project->id,
            'step_key' => 'scene_01',
            'name' => 'Scene 1',
            'sequence' => 61,
            'stage' => 'scenes',
            'status' => ProductionAutomation::STEP_FAILED,
            'weight' => 2.6923,
            'attempt_number' => 3,
            'attempt_limit' => 3,
            'safe_failure_code' => 'attempt_limit_reached',
            'safe_failure_summary' => 'Automatic retries are exhausted.',
            'failed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.retry-step', $project), [
                'step_key' => 'scene_01',
                'confirm_additional_budget_exposure' => true,
                'reason' => 'Use premium model after manual review.',
            ])
            ->assertOk()
            ->assertJsonPath('automation.run.status', ProductionAutomation::STATUS_RUNNING)
            ->assertJsonPath('automation.steps.0.status', ProductionAutomation::STEP_QUEUED);

        $step->refresh();
        $this->assertSame(4, $step->attempt_number);
        $this->assertSame(ProductionAutomation::STEP_QUEUED, $step->status);
        $this->assertTrue($step->metadata_json['manual_override']);
        $this->assertTrue($step->metadata_json['additional_budget_exposure_confirmed']);
        $this->assertNull($step->failed_at);
        Queue::assertPushed(AdvanceProductionAutomationRun::class, fn ($job): bool => $job->automationRunId === $run->id);
    }

    public function test_preflight_reports_insufficient_phase_two_budget(): void
    {
        $this->enableProviders();
        [$admin, $project] = $this->projectWithPhoto();

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.preflight', $project), [
                'hard_budget' => '0.0001',
            ])
            ->assertOk()
            ->assertJsonPath('preflight.ok', false)
            ->assertJsonFragment(['Hard budget is below the Phase 2 base estimate.']);
    }

    public function test_full_mocked_phase_two_lifecycle_completes_child_reference_before_phase_three_advance(): void
    {
        $this->enableProviders();
        [$admin, $project] = $this->projectWithPhoto();
        $this->fakePhaseTwoProviderResponses([
            $this->openAiScenePayload(),
            $this->characterProfilePayload(),
            $this->identityPassPayload(),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.start', $project), ['hard_budget' => '5.00'])
            ->assertCreated();

        $run = ProductionAutomationRun::firstOrFail();

        $this->advance($run);
        $this->processStructured(SceneGenerationJob::where('job_type', 'scene_extraction')->firstOrFail());
        $this->advance($run);
        $this->advance($run);
        $this->processStructured(SceneGenerationJob::where('job_type', 'character_analysis')->firstOrFail());
        $this->advance($run);
        $this->advance($run);

        $imageJob = SceneGenerationJob::where('job_type', 'character_sheet')->firstOrFail();
        $this->submitImage($imageJob);
        $this->pollImage($imageJob->fresh());

        $identityJob = SceneGenerationJob::where('job_type', 'identity_review')->firstOrFail();
        $this->processStructured($identityJob);
        $this->advance($run);

        $run->refresh();
        $this->assertSame(ProductionAutomation::STATUS_RUNNING, $run->status);
        $this->assertNull($run->safe_failure_code);
        $this->assertDatabaseCount('production_story_versions', 1);
        $this->assertSame(13, $project->scenes()->count());
        $this->assertTrue($project->characterProfile()->firstOrFail()->isReadyForAiGeneration());
        $this->assertDatabaseHas('production_automation_steps', [
            'automation_run_id' => $run->id,
            'step_key' => 'child_reference',
            'status' => ProductionAutomation::STEP_COMPLETED,
        ]);
        $this->assertDatabaseHas('production_automation_steps', [
            'automation_run_id' => $run->id,
            'step_key' => 'cover',
            'status' => ProductionAutomation::STEP_PENDING,
        ]);

        $reference = $project->assets()->where('asset_type', 'character_sheet')->firstOrFail();
        $this->assertSame('approved', $reference->status);
        $this->assertTrue($reference->is_primary);
        $this->assertStringStartsWith('production-studio/projects/', $reference->file_path);
        $this->assertStringNotContainsString('http', $reference->file_path);

        $this->assertSame(1, SceneGenerationJob::where('job_type', 'character_sheet')->count());
        $this->assertSame(0, SceneGenerationJob::where('job_type', 'cover_image')->count());
        $this->assertSame(0, SceneGenerationJob::where('job_type', 'scene_image')->count());
        $this->assertSame(3, $run->attempts()->count());
        $this->assertSame(4, $run->costEntries()->where('status', 'incurred')->count());
        $this->assertSame(1, $run->costEntries()->where('status', 'released')->count());
        $this->assertSame(4, $run->costEntries()->whereNotNull('idempotency_key')->distinct('idempotency_key')->count('idempotency_key'));
        $this->assertSame(1, $project->assets()->where('asset_type', 'character_sheet')->where('status', 'approved')->count());

        $status = $this->actingAs($admin)
            ->getJson(route('admin.production-studio.automation.status', $project))
            ->assertOk()
            ->assertJsonPath('automation.phase2.child_reference.approved_asset_id', $reference->id)
            ->json('automation');

        $this->assertArrayNotHasKey('file_path', $status['phase2']['child_reference']['assets'][0]);
    }

    public function test_child_reference_blocking_validation_flag_retries_without_approving_asset(): void
    {
        $this->enableProviders();
        [$admin, $project] = $this->projectWithPhoto();
        $this->fakePhaseTwoProviderResponses([
            $this->openAiScenePayload(),
            $this->characterProfilePayload(),
            $this->identityFailPayload('correct_number_of_children'),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.start', $project), ['hard_budget' => '5.00'])
            ->assertCreated();

        $run = ProductionAutomationRun::firstOrFail();

        $this->advance($run);
        $this->processStructured(SceneGenerationJob::where('job_type', 'scene_extraction')->firstOrFail());
        $this->advance($run);
        $this->advance($run);
        $this->processStructured(SceneGenerationJob::where('job_type', 'character_analysis')->firstOrFail());
        $this->advance($run);
        $this->advance($run);
        $imageJob = SceneGenerationJob::where('job_type', 'character_sheet')->firstOrFail();
        $this->submitImage($imageJob);
        $this->pollImage($imageJob->fresh());
        $this->processStructured(SceneGenerationJob::where('job_type', 'identity_review')->firstOrFail());
        $this->advance($run);

        $this->assertSame('rejected', $project->assets()->where('asset_type', 'character_sheet')->firstOrFail()->status);
        $this->assertSame(2, SceneGenerationJob::where('job_type', 'character_sheet')->count());
        $this->assertSame('running', $run->steps()->where('step_key', 'child_reference')->firstOrFail()->fresh()->status);
        $this->assertDatabaseHas('production_automation_attempts', [
            'automation_run_id' => $run->id,
            'attempt_number' => 1,
            'status' => 'failed',
            'safe_failure_code' => 'identity_blocking_flags',
        ]);
    }

    public function test_phase_two_boundary_resume_enters_cover_generation_without_manual_approval(): void
    {
        $this->enableProviders();
        [$admin, $project] = $this->projectWithPhoto();
        $run = $this->seedPhaseTwoCompleteRun($project, $admin, [
            'status' => ProductionAutomation::STATUS_PAUSED_REVIEW,
            'safe_failure_code' => 'phase2_complete_ready_for_phase3',
            'safe_failure_summary' => 'Phase 2 complete.',
            'pause_reason' => 'phase2_complete_ready_for_phase3',
        ]);

        $this->advance($run);

        $run->refresh();
        $this->assertSame(ProductionAutomation::STATUS_RUNNING, $run->status);
        $this->assertNull($run->safe_failure_code);
        $this->assertDatabaseHas('production_automation_steps', [
            'automation_run_id' => $run->id,
            'step_key' => 'cover',
            'status' => ProductionAutomation::STEP_RUNNING,
            'attempt_number' => 1,
        ]);
        $this->assertSame(1, SceneGenerationJob::where('job_type', 'cover_image')->count());
        $this->assertSame(0, SceneGenerationJob::where('job_type', 'scene_image')->count());
    }

    public function test_full_mocked_phase_three_lifecycle_enters_phase_four_layout_without_manual_approval(): void
    {
        $this->enableProviders();
        [$admin, $project] = $this->projectWithPhoto();
        $orderStatus = $project->order->status;
        $run = $this->seedPhaseTwoCompleteRun($project, $admin, [
            'status' => ProductionAutomation::STATUS_RUNNING,
            'scene_concurrency' => 2,
            'hard_budget' => '10.0000',
        ]);
        $this->fakePhaseThreeProviderResponses(array_merge(
            [$this->visualPassPayload('cover')],
            collect(range(1, 13))->map(fn (): array => $this->visualPassPayload('scene'))->all(),
        ));

        $maxActive = $this->drainPhaseThree($run);

        $run->refresh();
        $this->assertSame(ProductionAutomation::STATUS_RUNNING, $run->status);
        $this->assertNull($run->safe_failure_code);
        $this->assertSame('layout_print', $run->current_step_key);
        $this->assertSame(1, $project->assets()->where('asset_type', 'cover_image')->where('status', 'approved')->where('is_final', true)->count());
        $this->assertSame(13, $project->assets()->where('asset_type', 'scene_image')->where('status', 'approved')->where('is_final', true)->count());
        $this->assertSame(13, $project->assets()->where('asset_type', 'scene_image')->where('status', 'approved')->where('is_final', true)->distinct('production_scene_id')->count('production_scene_id'));
        $this->assertSame(13, $project->scenes()->count());
        $this->assertLessThanOrEqual(2, $maxActive);
        $this->assertSame(1, SceneGenerationJob::where('job_type', 'cover_image')->count());
        $this->assertSame(13, SceneGenerationJob::where('job_type', 'scene_image')->count());
        $this->assertSame(1, $project->printLayouts()->count());
        $this->assertSame('queued', $project->printLayouts()->firstOrFail()->status);
        $this->assertNull($project->printLayouts()->firstOrFail()->reader_pdf_path);
        $this->assertSame($orderStatus, $project->order->fresh()->status);
        $this->assertSame(0, $project->assets()->where('file_path', 'like', 'http%')->count());
        $this->assertSame(
            $run->costEntries()->whereNotNull('idempotency_key')->count(),
            $run->costEntries()->whereNotNull('idempotency_key')->distinct('idempotency_key')->count('idempotency_key')
        );

        $status = $this->actingAs($admin)
            ->getJson(route('admin.production-studio.automation.status', $project))
            ->assertOk()
            ->assertJsonPath('automation.phase3.completion_ready.approved_scene_count', 13)
            ->assertJsonPath('automation.phase3.completion_ready.phase3_complete', true)
            ->assertJsonPath('automation.phase4.layout.status', ProductionAutomation::STEP_RUNNING)
            ->json('automation');

        $this->assertArrayNotHasKey('file_path', $status['phase3']['cover']['assets'][0]);
    }

    public function test_phase_four_preconditions_pause_when_cover_is_missing(): void
    {
        $this->enableProviders();
        [$admin, $project] = $this->projectWithPhoto();
        $run = $this->seedPhaseThreeCompleteRun($project, $admin, withCover: false);

        $this->advance($run);

        $run->refresh();
        $this->assertSame(ProductionAutomation::STATUS_PAUSED_REVIEW, $run->status);
        $this->assertSame('layout_preconditions_failed', $run->safe_failure_code);
        $this->assertSame(0, $project->printLayouts()->count());
    }

    public function test_historical_phase_three_boundary_resumes_into_phase_four_layout_queue(): void
    {
        Queue::fake();
        $this->enableProviders();
        [$admin, $project] = $this->projectWithPhoto();
        $run = $this->seedPhaseThreeCompleteRun($project, $admin);

        $this->advance($run);

        $run->refresh();
        $layout = $project->printLayouts()->firstOrFail();
        $this->assertSame(ProductionAutomation::STATUS_RUNNING, $run->status);
        $this->assertSame('layout_print', $run->current_step_key);
        $this->assertSame('queued', $layout->status);
        $this->assertSame($run->id, $layout->production_automation_run_id);
        $this->assertNotNull($layout->input_fingerprint);
        Queue::assertPushed(GenerateProductionLayoutJob::class, fn ($job): bool => $job->layoutId === $layout->id);
    }

    public function test_full_mocked_phase_four_lifecycle_generates_private_files_and_stops_at_ninety_five_percent(): void
    {
        $this->enableProviders();
        [$admin, $project] = $this->projectWithPhoto();
        $orderStatus = $project->order->status;
        $projectStatus = $project->status;
        $run = $this->seedPhaseThreeCompleteRun($project, $admin);

        $this->advance($run);
        $layout = $project->printLayouts()->firstOrFail();
        app()->call([new GenerateProductionLayoutJob($layout->id), 'handle']);
        $this->advance($run->fresh());

        $run->refresh();
        $layout->refresh();
        $this->assertSame(ProductionAutomation::STATUS_FILES_READY, $run->status, json_encode([
            'run_failure' => [$run->safe_failure_code, $run->safe_failure_summary],
            'layout' => [$layout->status, $layout->error_message],
            'layout_validation' => data_get($layout->manifest_json, 'validation.errors'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('phase4_files_ready_waiting_final_proof', $run->safe_failure_code);
        $this->assertSame('quality_check', $run->current_stage);
        $this->assertSame('final_proof', $run->current_step_key);
        $this->assertSame('ready', $layout->status, $layout->error_message ?? '');
        $this->assertTrue($layout->isValidatedAutomationReady());
        $this->assertSame(28, data_get($layout->manifest_json, 'page_count'));
        $this->assertSame(7, data_get($layout->manifest_json, 'sheet_count'));
        $this->assertSame(14, data_get($layout->manifest_json, 'printed_sides'));
        $this->assertSame('one PDF page per printed A3 side', data_get($layout->manifest_json, 'pdf_page_representation'));
        $this->assertSame(28, data_get($layout->manifest_json, 'files.reader_pdf.page_count'));
        $this->assertSame(14, data_get($layout->manifest_json, 'files.print_pdf.page_count'));
        $this->assertNotNull(data_get($layout->manifest_json, 'files.reader_pdf.sha256'));
        $this->assertNotNull(data_get($layout->manifest_json, 'files.print_pdf.sha256'));
        $this->assertNotNull(data_get($layout->manifest_json, 'files.manifest.sha256'));
        $this->assertNotNull(data_get($layout->manifest_json, 'files.proof_checklist.sha256'));
        Storage::disk('local')->assertExists($layout->reader_pdf_path);
        Storage::disk('local')->assertExists($layout->print_pdf_path);
        Storage::disk('local')->assertExists($layout->manifest_path);
        Storage::disk('local')->assertExists($layout->proof_checklist_path);
        $this->assertStringStartsWith('production-studio/projects/', $layout->reader_pdf_path);
        $this->assertStringNotContainsString('http', $layout->reader_pdf_path);
        $this->assertDatabaseHas('production_automation_steps', [
            'automation_run_id' => $run->id,
            'step_key' => 'layout_print',
            'status' => ProductionAutomation::STEP_COMPLETED,
        ]);
        $this->assertDatabaseHas('production_automation_steps', [
            'automation_run_id' => $run->id,
            'step_key' => 'final_proof',
            'status' => ProductionAutomation::STEP_WAITING_REVIEW,
        ]);
        $this->assertSame($orderStatus, $project->order->fresh()->status);
        $this->assertSame($projectStatus, $project->fresh()->status);

        $status = $this->actingAs($admin)
            ->getJson(route('admin.production-studio.automation.status', $project))
            ->assertOk()
            ->assertJsonPath('automation.run.progress', 95)
            ->assertJsonPath('automation.phase4.phase4_ready', true)
            ->assertJsonPath('automation.phase4.final_proof_pending', true)
            ->assertJsonPath('automation.phase4.page_count', 28)
            ->assertJsonPath('automation.phase4.sheet_count', 7)
            ->assertJsonStructure(['automation' => ['downloads' => ['reader', 'print', 'manifest', 'proof']]])
            ->json('automation');

        $this->assertArrayNotHasKey('reader_pdf_path', $status['phase4']['layout']);
        $this->assertArrayNotHasKey('file_path', $status['phase4']['reader_pdf']);
    }

    public function test_phase_four_signed_downloads_require_authorization_and_valid_signature(): void
    {
        $this->enableProviders();
        [$admin, $project] = $this->projectWithPhoto();
        $run = $this->seedPhaseThreeCompleteRun($project, $admin);

        $this->advance($run);
        $layout = $project->printLayouts()->firstOrFail();
        app()->call([new GenerateProductionLayoutJob($layout->id), 'handle']);
        $this->advance($run->fresh());
        $run->refresh();
        $layout->refresh();

        $status = $this->actingAs($admin)
            ->getJson(route('admin.production-studio.automation.status', $project))
            ->assertOk()
            ->assertJsonMissing(['reader_pdf_path' => $layout->reader_pdf_path])
            ->assertJsonMissing(['print_pdf_path' => $layout->print_pdf_path])
            ->json('automation');

        $this->assertStringContainsString('/automation/runs/', $status['downloads']['reader']);
        $this->assertStringNotContainsString($layout->reader_pdf_path, $status['downloads']['reader']);

        $this->actingAs($admin)
            ->get($status['downloads']['reader'])
            ->assertOk()
            ->assertHeader('content-disposition');

        $expired = URL::temporarySignedRoute(
            'admin.production-studio.automation.download',
            now()->subMinute(),
            [$project, $run, $layout, 'reader'],
        );
        $this->actingAs($admin)
            ->get($expired)
            ->assertForbidden();

        $limited = User::create([
            'name' => 'Limited Automation Viewer',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'admin',
        ]);
        $limited->permissions()->sync(Permission::where('key', 'production_studio.view')->pluck('id')->all());

        $this->actingAs($limited)
            ->get($status['downloads']['reader'])
            ->assertForbidden();
    }

    public function test_scene_generation_respects_configured_concurrency(): void
    {
        $this->enableProviders();
        [$admin, $project] = $this->projectWithPhoto();
        $run = $this->seedPhaseTwoCompleteRun($project, $admin, [
            'status' => ProductionAutomation::STATUS_RUNNING,
            'scene_concurrency' => 2,
            'with_cover' => true,
        ]);

        $this->advance($run);

        $this->assertSame(2, SceneGenerationJob::where('job_type', 'scene_image')->where('status', 'queued')->count());
        $this->assertSame(0, SceneGenerationJob::where('job_type', 'cover_image')->count());
    }

    public function test_manual_phase_three_asset_approval_records_actor_reason_and_completes_step(): void
    {
        $this->enableProviders();
        [$admin, $project] = $this->projectWithPhoto();
        $run = $this->seedPhaseTwoCompleteRun($project, $admin, [
            'status' => ProductionAutomation::STATUS_RUNNING,
        ]);
        $step = $run->steps()->where('step_key', 'cover')->firstOrFail();
        $asset = $project->assets()->create([
            'asset_type' => 'cover_image',
            'version_number' => 1,
            'label' => 'Manual cover v1',
            'status' => 'under_review',
            'is_final' => false,
            'file_path' => "production-studio/projects/{$project->id}/generated/manual-cover.png",
            'metadata_json' => ['identity_review' => ['status' => 'completed', 'result' => $this->visualPassPayload('cover')]],
            'production_automation_run_id' => $run->id,
            'production_automation_step_id' => $step->id,
            'input_fingerprint' => 'manual-cover-input',
            'output_fingerprint' => 'manual-cover-output',
            'uploaded_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.automation.phase3.assets.approve', [$project, $asset]), [
                'reason' => 'Reviewed cover manually.',
            ])
            ->assertOk()
            ->assertJsonPath('automation.phase3.cover.approved_asset_id', $asset->id);

        $asset->refresh();
        $this->assertSame('approved', $asset->status);
        $this->assertTrue($asset->is_final);
        $this->assertSame($admin->id, $asset->reviewed_by_user_id);
        $this->assertSame('Reviewed cover manually.', $asset->review_notes);
        $this->assertSame(ProductionAutomation::STEP_COMPLETED, $step->fresh()->status);
    }

    public function test_two_scene_reservations_cannot_both_consume_the_final_budget(): void
    {
        $this->enableProviders();
        [$admin, $project] = $this->projectWithPhoto();
        $run = $this->seedPhaseTwoCompleteRun($project, $admin, [
            'status' => ProductionAutomation::STATUS_RUNNING,
            'hard_budget' => '0.0300',
            'with_cover' => true,
        ]);
        $ledger = app(ProductionAutomationCostLedger::class);
        $sceneOneStep = $run->steps()->where('step_key', 'scene_01')->firstOrFail();
        $sceneTwoStep = $run->steps()->where('step_key', 'scene_02')->firstOrFail();
        $attemptOne = $sceneOneStep->attempts()->create([
            'automation_run_id' => $run->id,
            'attempt_uuid' => 'scene-budget-1',
            'attempt_number' => 1,
            'run_version' => $run->version,
            'orchestration_generation' => $run->orchestration_generation,
            'status' => 'queued',
            'input_fingerprint' => 'scene-budget-input-1',
        ]);
        $attemptTwo = $sceneTwoStep->attempts()->create([
            'automation_run_id' => $run->id,
            'attempt_uuid' => 'scene-budget-2',
            'attempt_number' => 1,
            'run_version' => $run->version,
            'orchestration_generation' => $run->orchestration_generation,
            'status' => 'queued',
            'input_fingerprint' => 'scene-budget-input-2',
        ]);

        $ledger->reserve($run, $sceneOneStep, $attemptOne, 'fal', 'fal-ai/flux-kontext/dev', '0.0300', ['type' => 'scene_generation'], 'scene-budget-one');

        $this->expectException(\RuntimeException::class);
        $ledger->reserve($run->fresh(), $sceneTwoStep, $attemptTwo, 'fal', 'fal-ai/flux-kontext/dev', '0.0300', ['type' => 'scene_generation'], 'scene-budget-two');
    }

    private function enableProviders(): void
    {
        app(AiProviderRegistrySyncer::class)->sync();

        foreach (['fal', 'openai'] as $driver) {
            $provider = AiProvider::where('driver', $driver)->firstOrFail();
            app(AiProviderCredentialService::class)->save($provider, 'test-'.$driver.'-key');
            $provider->update([
                'is_active' => true,
                'is_configured' => true,
                'is_available' => true,
                'last_health_check_status' => 'passed',
            ]);
            $provider->models()->update(['is_active' => true]);
        }
    }

    private function projectWithPhoto(?array $permissions = null): array
    {
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $admin = User::create([
            'name' => 'Automation Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'admin',
        ]);

        if ($permissions !== null) {
            $admin->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id')->all());
        } else {
            $admin->permissions()->sync(Permission::pluck('id')->all());
        }

        $story = Story::create([
            'title' => 'Automation Story',
            'slug' => 'automation-feature-'.strtolower(fake()->bothify('????')),
            'short_desc' => 'Story text.',
            'full_desc' => 'Story text.',
            'age_range' => '6-9',
            'language' => 'ar',
            'price' => 100,
            'active' => true,
        ]);
        $order = Order::create([
            'order_number' => 'HK-AUTO-'.strtoupper(fake()->bothify('????')),
            'story_id' => $story->id,
            'parent_name' => 'Parent',
            'child_name' => 'Rina',
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => ['phone' => '01111111111'],
            'uploaded_photos' => ['orders/photos/kid.png'],
            'status' => 'new',
        ]);
        $project = ProductionProject::create([
            'order_id' => $order->id,
            'status' => 'draft',
            'current_stage' => 'intake',
            'created_by_user_id' => $admin->id,
        ]);
        $project->characterProfile()->create([
            'reference_photo_selection' => [],
            'approved_reference_photos' => [],
        ]);

        return [$admin->refresh(), $project->fresh(['order'])];
    }

    private function seedPhaseTwoCompleteRun(ProductionProject $project, User $admin, array $overrides = []): ProductionAutomationRun
    {
        $hardBudget = (string) ($overrides['hard_budget'] ?? '5.0000');
        $sceneConcurrency = (int) ($overrides['scene_concurrency'] ?? 2);
        $preflight = app(ProductionAutomationPreflightService::class)->inspect($project->fresh(['order.story']), [
            'hard_budget' => $hardBudget,
            'scene_concurrency' => $sceneConcurrency,
        ]);
        $run = ProductionAutomationRun::create([
            'production_project_id' => $project->id,
            'active_project_id' => $project->id,
            'status' => $overrides['status'] ?? ProductionAutomation::STATUS_RUNNING,
            'current_stage' => 'cover',
            'current_step_key' => 'cover',
            'pause_reason' => $overrides['pause_reason'] ?? null,
            'safe_failure_code' => $overrides['safe_failure_code'] ?? null,
            'safe_failure_summary' => $overrides['safe_failure_summary'] ?? null,
            'hard_budget' => $hardBudget,
            'currency' => 'USD',
            'base_estimated_cost' => $preflight['base_estimated_cost'],
            'retry_exposure_estimate' => $preflight['retry_exposure_estimate'],
            'options_snapshot_json' => $preflight['options_snapshot'] + [
                'hard_budget' => $hardBudget,
                'scene_concurrency' => $sceneConcurrency,
            ],
            'pricing_snapshot_json' => $preflight['pricing_snapshot'] + ['models' => $preflight['models']],
            'started_by_user_id' => $admin->id,
            'last_transition_at' => now(),
            'last_heartbeat_at' => now(),
            'blockers_json' => [],
        ]);
        app(ProductionAutomationRunService::class)->seedSteps($run);
        $run = $run->fresh(['steps']);

        $storyStep = $run->steps->firstWhere('step_key', 'story_preparation');
        $profileStep = $run->steps->firstWhere('step_key', 'character_profile');
        $referenceStep = $run->steps->firstWhere('step_key', 'child_reference');
        $storyVersion = $project->storyVersions()->create([
            'version_number' => 1,
            'title' => 'Rina Adventure',
            'target_age_group' => '6-9',
            'educational_values_json' => ['confidence'],
            'full_story_content' => 'Production-specific approved story draft for Rina.',
            'status' => 'approved',
            'created_by_user_id' => $admin->id,
            'reviewed_by_user_id' => $admin->id,
            'approved_at' => now(),
            'production_automation_run_id' => $run->id,
            'production_automation_step_id' => $storyStep?->id,
            'input_fingerprint' => 'sha256:story-input',
            'output_fingerprint' => 'sha256:story-output',
            'automation_metadata_json' => ['source' => 'test_phase2_fixture'],
            'validation_summary_json' => ['ok' => true],
        ]);
        $project->update([
            'template_hero_name' => 'Jana',
            'template_hero_gender' => 'girl',
            'personalized_hero_name' => 'Rina',
            'child_story_role' => 'main_hero',
            'personalization_status' => 'personalized',
            'personalization_warnings' => [],
        ]);

        foreach (range(1, 13) as $sceneNumber) {
            $scene = $project->scenes()->create([
                'production_story_version_id' => $storyVersion->id,
                'scene_number' => $sceneNumber,
                'title' => 'Scene '.$sceneNumber,
                'story_text' => 'Rina explores story moment '.$sceneNumber.' with confidence and kindness.',
                'visual_direction' => 'Show Rina as the only child in a landscape illustration for scene '.$sceneNumber.' with no visible text.',
                'child_action_pose' => 'Rina performs the scene '.$sceneNumber.' child action with a clear face and childlike pose.',
                'environment' => 'Bright safe story environment '.$sceneNumber,
                'mood_lighting' => 'Warm natural lighting',
                'supporting_characters' => 'No extra children.',
                'key_objects' => 'Story object '.$sceneNumber,
                'continuity_notes' => 'Keep Rina consistent with the approved child reference.',
                'text_safe_area_notes' => 'Leave a quiet low-detail area for Arabic story text.',
                'educational_value' => 'Confidence and kindness.',
                'status' => 'draft',
                'ai_sync_status' => 'ready',
                'template_hero_name' => 'Jana',
                'personalized_hero_name' => 'Rina',
                'personalization_status' => 'personalized',
                'personalization_warnings' => [],
            ]);
            $run->steps()
                ->where('step_key', 'scene_'.str_pad((string) $sceneNumber, 2, '0', STR_PAD_LEFT))
                ->update(['production_scene_id' => $scene->id]);
        }

        $profile = $project->characterProfile()->firstOrCreate([]);
        $profile->update([
            'appearance_summary' => 'A child with a natural face, soft cheeks, and age-appropriate proportions.',
            'hair_details' => 'Dark brown wavy hair with natural volume.',
            'skin_tone' => 'Light warm skin tone.',
            'eye_color_traits' => 'Brown eyes and soft child facial features.',
            'typical_expression' => 'Calm friendly expression.',
            'identity_rules' => 'Preserve face shape, eyes, hair, skin tone, and apparent age.',
            'negative_instructions' => 'No changed face, no adult appearance, no text, no logos.',
            'reference_photo_selection' => [0],
            'approved_reference_photos' => [0],
            'primary_face_reference_index' => 0,
            'production_automation_run_id' => $run->id,
            'production_automation_step_id' => $profileStep?->id,
            'input_fingerprint' => 'sha256:profile-input',
            'output_fingerprint' => 'sha256:profile-output',
            'automation_metadata_json' => ['source' => 'test_phase2_fixture'],
            'validation_summary_json' => ['ok' => true],
        ]);

        Storage::disk('local')->put("production-studio/projects/{$project->id}/generated/reference.png", base64_decode($this->landscapePngBase64()));
        $project->assets()->create([
            'asset_type' => 'character_sheet',
            'version_number' => 1,
            'label' => 'Approved Child Reference Illustration v1',
            'status' => 'approved',
            'is_primary' => true,
            'is_final' => false,
            'file_path' => "production-studio/projects/{$project->id}/generated/reference.png",
            'metadata_json' => ['width' => 2, 'height' => 1],
            'production_automation_run_id' => $run->id,
            'production_automation_step_id' => $referenceStep?->id,
            'input_fingerprint' => 'sha256:reference-input',
            'output_fingerprint' => 'sha256:reference-output',
            'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
            'uploaded_by_user_id' => $admin->id,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
        ]);

        foreach (['preflight', 'story_preparation', 'character_profile', 'child_reference'] as $stepKey) {
            $run->steps()->where('step_key', $stepKey)->update([
                'status' => ProductionAutomation::STEP_COMPLETED,
                'approval_type' => 'automatic',
                'completed_at' => now(),
                'output_fingerprint' => 'sha256:'.$stepKey.'-output',
                'validation_summary_json' => ['source' => 'test_phase2_fixture'],
            ]);
        }

        if ($overrides['with_cover'] ?? false) {
            $coverStep = $run->steps()->where('step_key', 'cover')->firstOrFail();
            $project->assets()->create([
                'asset_type' => 'cover_image',
                'version_number' => 1,
                'label' => 'Cover Image v1',
                'status' => 'approved',
                'is_final' => true,
                'file_path' => "production-studio/projects/{$project->id}/generated/cover.png",
                'metadata_json' => ['identity_review' => ['status' => 'completed', 'result' => $this->visualPassPayload('cover')]],
                'production_automation_run_id' => $run->id,
                'production_automation_step_id' => $coverStep->id,
                'input_fingerprint' => 'sha256:cover-input',
                'output_fingerprint' => 'sha256:cover-output',
                'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
                'uploaded_by_user_id' => $admin->id,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
            ]);
            $coverStep->update([
                'status' => ProductionAutomation::STEP_COMPLETED,
                'approval_type' => 'automatic',
                'completed_at' => now(),
                'output_fingerprint' => 'sha256:cover-output',
                'validation_summary_json' => ['source' => 'test_phase2_fixture'],
            ]);
        }

        return $run->fresh(['steps', 'project.scenes', 'project.assets']);
    }

    private function seedPhaseThreeCompleteRun(ProductionProject $project, User $admin, bool $withCover = true): ProductionAutomationRun
    {
        $run = $this->seedPhaseTwoCompleteRun($project, $admin, [
            'status' => ProductionAutomation::STATUS_PAUSED_REVIEW,
            'safe_failure_code' => 'phase3_complete_ready_for_layout',
            'safe_failure_summary' => 'Phase 3 complete.',
            'pause_reason' => 'phase3_complete_ready_for_layout',
            'hard_budget' => '25.0000',
            'with_cover' => $withCover,
        ]);

        if ($withCover) {
            Storage::disk('local')->put("production-studio/projects/{$project->id}/generated/cover.png", base64_decode($this->landscapePngBase64()));
        } else {
            $project->assets()->where('asset_type', 'cover_image')->delete();
            $run->steps()->where('step_key', 'cover')->update([
                'status' => ProductionAutomation::STEP_COMPLETED,
                'approval_type' => 'automatic',
                'completed_at' => now(),
                'safe_failure_code' => 'approved_cover_missing',
                'safe_failure_summary' => 'Missing cover for test fixture.',
            ]);
        }

        foreach ($project->scenes()->orderBy('scene_number')->get() as $scene) {
            Storage::disk('local')->put("production-studio/projects/{$project->id}/generated/scene-{$scene->scene_number}.png", base64_decode($this->landscapePngBase64()));
            $step = $run->steps()->where('step_key', 'scene_'.str_pad((string) $scene->scene_number, 2, '0', STR_PAD_LEFT))->firstOrFail();
            $asset = $project->assets()->create([
                'asset_type' => 'scene_image',
                'production_scene_id' => $scene->id,
                'version_number' => (int) $scene->scene_number,
                'label' => 'Scene Image v'.$scene->scene_number,
                'status' => 'approved',
                'is_final' => true,
                'file_path' => "production-studio/projects/{$project->id}/generated/scene-{$scene->scene_number}.png",
                'metadata_json' => ['identity_review' => ['status' => 'completed', 'result' => $this->visualPassPayload('scene')]],
                'production_automation_run_id' => $run->id,
                'production_automation_step_id' => $step->id,
                'input_fingerprint' => 'sha256:scene-'.$scene->scene_number.'-input',
                'output_fingerprint' => 'sha256:scene-'.$scene->scene_number.'-output',
                'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
                'uploaded_by_user_id' => $admin->id,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
            ]);
            $scene->update(['approved_final_image_path' => $asset->file_path]);
            $step->update([
                'status' => ProductionAutomation::STEP_COMPLETED,
                'approval_type' => 'automatic',
                'completed_at' => now(),
                'input_fingerprint' => $asset->input_fingerprint,
                'output_fingerprint' => $asset->output_fingerprint,
                'validation_summary_json' => ['source' => 'test_phase3_fixture', 'asset_id' => $asset->id],
            ]);
        }

        return $run->fresh(['steps', 'project.scenes.approvedFinalImage', 'project.assets']);
    }

    private function seedPhaseFourFilesReadyRun(ProductionProject $project, User $admin): array
    {
        $run = $this->seedPhaseThreeCompleteRun($project, $admin);
        $run->update([
            'status' => ProductionAutomation::STATUS_FILES_READY,
            'current_stage' => 'quality_check',
            'current_step_key' => 'final_proof',
            'safe_failure_code' => 'phase4_files_ready_waiting_final_proof',
            'safe_failure_summary' => 'Print files are ready and waiting for final human proof.',
            'files_ready_at' => now(),
            'last_transition_at' => now(),
            'last_heartbeat_at' => now(),
            'blockers_json' => [],
        ]);

        $layoutStep = $run->steps()->where('step_key', 'layout_print')->firstOrFail();
        $finalProofStep = $run->steps()->where('step_key', 'final_proof')->firstOrFail();
        $layoutStep->update([
            'status' => ProductionAutomation::STEP_COMPLETED,
            'approval_type' => 'automatic',
            'completed_at' => now(),
            'output_fingerprint' => 'sha256:layout-output',
            'validation_summary_json' => ['source' => 'test_phase4_fixture'],
        ]);
        $finalProofStep->update([
            'status' => ProductionAutomation::STEP_WAITING_REVIEW,
            'safe_failure_code' => 'phase4_files_ready_waiting_final_proof',
            'safe_failure_summary' => 'Final proof is required.',
        ]);

        $builder = app(ProductionLayoutBuilder::class);
        $validator = app(ProductionAutomationLayoutValidator::class);
        $project = $project->fresh(['order.story', 'scenes.approvedFinalImage', 'assets']);
        $settings = $builder->normalizedSettings($project, []);
        $manifest = $builder->buildManifest($settings);
        $base = "production-studio/projects/{$project->id}/layout/v1";
        $readerPath = "{$base}/reader-order.pdf";
        $printPath = "{$base}/print-ready-a3-booklet.pdf";
        $manifestPath = "{$base}/print-manifest.csv";
        $proofPath = "{$base}/proof-print-checklist.pdf";

        Storage::disk('local')->put($readerPath, $this->fakePdf(28, 'reader'));
        Storage::disk('local')->put($printPath, $this->fakePdf(14, 'print'));
        Storage::disk('local')->put($manifestPath, $validator->manifestCsv($manifest));
        Storage::disk('local')->put($proofPath, $this->fakePdf(1, 'proof'));

        $layout = $project->printLayouts()->create([
            'version_number' => 1,
            'status' => 'validating',
            'settings_json' => $settings,
            'manifest_json' => $manifest,
            'reader_pdf_path' => $readerPath,
            'print_pdf_path' => $printPath,
            'manifest_path' => $manifestPath,
            'proof_checklist_path' => $proofPath,
            'production_automation_run_id' => $run->id,
            'production_automation_step_id' => $layoutStep->id,
            'generated_by_user_id' => $admin->id,
            'input_fingerprint' => 'sha256:layout-input',
            'validation_policy_version' => config('production_studio.automation.validation_policy_version', 'identity-v1'),
        ]);

        $validation = $validator->validate($layout->fresh(['project.order.story', 'project.scenes.approvedFinalImage', 'project.assets']));
        $this->assertTrue($validation['ok'], json_encode($validation['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $manifest = $validator->enrichManifest($layout, $manifest, $validation);
        Storage::disk('local')->put($manifestPath, $validator->manifestCsv($manifest));
        $manifestContents = Storage::disk('local')->get($manifestPath);
        $validation['files']['manifest']['sha256'] = hash('sha256', $manifestContents);
        $validation['files']['manifest']['bytes'] = strlen($manifestContents);
        $validation['output_fingerprint'] = $validator->outputFingerprint($layout, $validation);
        $manifest = $validator->enrichManifest($layout, $builder->buildManifest($settings), $validation);
        Storage::disk('local')->put($manifestPath, $validator->manifestCsv($manifest));

        $layout->update([
            'status' => 'ready',
            'manifest_json' => $manifest,
            'output_fingerprint' => $validation['output_fingerprint'],
            'generated_at' => now(),
        ]);

        return [$run->fresh(['steps', 'project.printLayouts']), $layout->fresh()];
    }

    private function passingChecklist(): array
    {
        return collect(app(ProductionAutomationFinalProofService::class)->checklistItems())
            ->map(fn (): array => ['value' => 'pass', 'reason' => null])
            ->all();
    }

    private function printProofMetadata(): array
    {
        return [
            'proof_print_date' => now()->toDateString(),
            'printer_name' => 'Studio proof printer',
            'printer_model' => 'Manual test fixture',
            'paper_size' => 'A3 landscape',
            'cover_paper_type' => 'Matte cover stock',
            'cover_paper_gsm' => '250',
            'inner_paper_type' => 'Satin inner stock',
            'inner_paper_gsm' => '170',
            'duplex_setting' => 'duplex enabled',
            'flip_edge' => 'short_edge',
            'print_quality' => 'high',
            'test_copies' => 1,
            'reviewer_notes' => 'Fixture proof metadata.',
        ];
    }

    private function reviewedChecksums($proof): array
    {
        return [
            'reader_pdf' => $proof->reader_pdf_checksum,
            'imposed_pdf' => $proof->imposed_pdf_checksum,
            'manifest' => $proof->manifest_checksum,
        ];
    }

    private function fakePdf(int $pages, string $label): string
    {
        $isPrint = $label === 'print';
        $width = $isPrint ? '1190.55' : '595.28';
        $height = '841.89';
        $page = "<< /Type /Page /MediaBox [0 0 {$width} {$height}] /Resources << /Font << /F1 << /Type /Font /FontDescriptor << /FontFile2 9 0 R >> >> >> >> >>\n";

        return "%PDF-1.4\n% {$label}\n".str_repeat($page, $pages).'%%EOF';
    }

    private function fakePhaseTwoProviderResponses(array $openAiPayloads): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=');

        Http::fake(function ($request) use (&$openAiPayloads, $png) {
            $url = (string) $request->url();

            if (str_contains($url, 'api.openai.com/v1/responses')) {
                $payload = array_shift($openAiPayloads);

                return Http::response($this->openAiJsonResponse($payload ?? []));
            }

            if (str_contains($url, 'queue.fal.run')) {
                return Http::response([
                    'request_id' => 'fal-reference-'.fake()->unique()->bothify('####'),
                    'status' => 'IN_QUEUE',
                    'status_url' => 'https://fal.test/status',
                    'response_url' => 'https://fal.test/result',
                ]);
            }

            if ($url === 'https://fal.test/status') {
                return Http::response(['status' => 'COMPLETED']);
            }

            if ($url === 'https://fal.test/result') {
                return Http::response([
                    'images' => [['url' => 'https://fal.test/output.png']],
                    'metrics' => ['cost' => '0.0200'],
                ]);
            }

            if ($url === 'https://fal.test/output.png') {
                return Http::response($png, 200, ['Content-Type' => 'image/png']);
            }

            return Http::response([], 404);
        });
    }

    private function fakePhaseThreeProviderResponses(array $openAiPayloads): void
    {
        $png = base64_decode($this->landscapePngBase64());
        $requestNumber = 0;

        Http::fake(function ($request) use (&$openAiPayloads, $png, &$requestNumber) {
            $url = (string) $request->url();

            if (str_contains($url, 'api.openai.com/v1/responses')) {
                $payload = array_shift($openAiPayloads);

                return Http::response($this->openAiJsonResponse($payload ?? []));
            }

            if (str_contains($url, 'queue.fal.run')) {
                $requestNumber++;

                return Http::response([
                    'request_id' => 'fal-phase3-'.$requestNumber,
                    'status' => 'IN_QUEUE',
                    'status_url' => 'https://fal.test/status/'.$requestNumber,
                    'response_url' => 'https://fal.test/result/'.$requestNumber,
                ]);
            }

            if (str_starts_with($url, 'https://fal.test/status/')) {
                return Http::response(['status' => 'COMPLETED']);
            }

            if (str_starts_with($url, 'https://fal.test/result/')) {
                return Http::response([
                    'images' => [['url' => 'https://fal.test/output.png']],
                    'metrics' => ['cost' => '0.0200'],
                ]);
            }

            if ($url === 'https://fal.test/output.png') {
                return Http::response($png, 200, ['Content-Type' => 'image/png']);
            }

            return Http::response([], 404);
        });
    }

    private function drainPhaseThree(ProductionAutomationRun $run, int $maxCycles = 80): int
    {
        $maxActive = 0;

        for ($cycle = 0; $cycle < $maxCycles; $cycle++) {
            $this->advance($run->fresh());
            $maxActive = max($maxActive, SceneGenerationJob::where('job_type', 'scene_image')->whereIn('status', ['queued', 'processing'])->count());

            SceneGenerationJob::whereIn('job_type', ['cover_image', 'scene_image'])
                ->where('status', 'queued')
                ->orderBy('id')
                ->get()
                ->each(fn (SceneGenerationJob $job) => $this->submitImage($job));

            SceneGenerationJob::whereIn('job_type', ['cover_image', 'scene_image'])
                ->where('status', 'processing')
                ->orderBy('id')
                ->get()
                ->each(fn (SceneGenerationJob $job) => $this->pollImage($job));

            SceneGenerationJob::whereIn('job_type', ['identity_review', 'scene_improvement'])
                ->where('status', 'queued')
                ->orderBy('id')
                ->get()
                ->each(fn (SceneGenerationJob $job) => $this->processStructured($job));

            $run->refresh();
            if ($run->status === ProductionAutomation::STATUS_RUNNING
                && $run->current_step_key === 'layout_print'
                && $run->project->printLayouts()->exists()) {
                return $maxActive;
            }
        }

        $this->fail('Phase 3 lifecycle did not reach Phase 4 layout queue.');
    }

    private function advance(ProductionAutomationRun $run): void
    {
        $job = new AdvanceProductionAutomationRun($run->id);
        app()->call([$job, 'handle']);
    }

    private function processStructured(SceneGenerationJob $job): void
    {
        app()->call([new ProcessStructuredAiJob($job->id), 'handle']);
    }

    private function submitImage(SceneGenerationJob $job): void
    {
        app()->call([new SubmitAiGenerationJob($job->id), 'handle']);
    }

    private function pollImage(SceneGenerationJob $job): void
    {
        app()->call([new PollAiGenerationJob($job->id), 'handle']);
    }

    private function openAiJsonResponse(array $payload): array
    {
        return [
            'id' => 'resp_test',
            'output_text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'usage' => [
                'input_tokens' => 120,
                'output_tokens' => 80,
                'total_tokens' => 200,
            ],
        ];
    }

    private function openAiScenePayload(): array
    {
        return [
            'template_hero_name' => 'Jana',
            'template_hero_gender' => 'girl',
            'hero_detection_confidence' => 'high',
            'supporting_character_names' => ['Dad'],
            'replacement_strategy' => 'replace_template_hero_with_child_name',
            'personalization_applied' => true,
            'gender_adaptation_applied' => false,
            'personalization_warnings' => [],
            'story_title' => 'Rina Adventure',
            'story_summary' => 'Rina learns confidence.',
            'target_age_range' => '6-9',
            'educational_values' => ['confidence'],
            'scenes' => collect(range(1, 13))->map(fn (int $i): array => [
                'scene_number' => $i,
                'scene_title' => 'Scene '.$i,
                'written_text' => 'Rina explores a safe story moment number '.$i.' with curiosity, courage, and a clear child-centered action.',
                'visual_direction' => 'Show Rina as the only child in scene '.$i.' with a clear environment and no text.',
                'child_action_pose' => 'Rina performs the main child action for scene '.$i.'.',
                'environment' => 'Bright story environment '.$i,
                'mood_lighting' => 'Warm natural lighting '.$i,
                'supporting_characters' => 'No extra children.',
                'key_objects' => 'Story object '.$i,
                'continuity_notes' => 'Keep Rina consistent from prior scenes.',
                'safe_text_area_notes' => 'Leave a calm low-detail safe area for Arabic text.',
                'educational_value' => 'Confidence and kindness.',
            ])->all(),
        ];
    }

    private function characterProfilePayload(): array
    {
        return [
            'appearance_summary' => 'A child with a natural face, soft cheeks, and age-appropriate proportions.',
            'hair_details' => 'Dark brown wavy hair with natural volume.',
            'skin_tone' => 'Light warm skin tone.',
            'eyes_and_visible_traits' => 'Brown eyes and soft child facial features.',
            'usual_expression' => 'Calm friendly expression.',
            'face_shape_notes' => 'Rounded child face shape.',
            'body_proportion_notes' => 'Childlike proportions appropriate for age.',
            'identity_rules' => 'Preserve face shape, eyes, hair, skin tone, and apparent age.',
            'negative_instructions' => 'No changed face, no adult appearance, no text, no logos.',
            'confidence_notes' => 'Selected photo is clear enough for illustration guidance.',
            'field_confidence' => [
                'approximate_age_group' => 'high',
                'face_shape_notes' => 'high',
                'skin_tone' => 'high',
                'hair_details' => 'high',
                'eyes_and_visible_traits' => 'high',
                'glasses_or_accessories' => 'medium',
                'body_proportion_notes' => 'medium',
                'gender_presentation_if_needed' => 'medium',
            ],
            'reference_photo_recommendations' => 'Use photo 1 as primary face reference.',
            'warnings' => 'none',
        ];
    }

    private function identityPassPayload(): array
    {
        return $this->identityPayload(blockingCriterion: null);
    }

    private function identityFailPayload(string $blockingCriterion): array
    {
        return $this->identityPayload(blockingCriterion: $blockingCriterion);
    }

    private function identityPayload(?string $blockingCriterion): array
    {
        $criteria = collect([
            'age_consistency',
            'face_structure',
            'hair_color_style',
            'skin_tone',
            'eye_characteristics',
            'glasses_or_accessories',
            'gender_presentation',
            'correct_number_of_children',
            'no_unrelated_characters',
            'no_adult_looking_child',
            'no_text_logos_watermarks',
            'safe_content',
        ])->mapWithKeys(fn (string $criterion): array => [$criterion => [
            'status' => $criterion === $blockingCriterion ? 'fail' : 'pass',
            'evidence' => $criterion === $blockingCriterion ? 'Blocking visual issue.' : 'Acceptable for automation.',
            'blocking' => $criterion === $blockingCriterion,
        ]])->all();

        return [
            'score' => 92,
            'summary' => $blockingCriterion ? 'Blocked despite high score.' : 'Reference matches identity.',
            'criteria' => $criteria,
        ];
    }

    private function visualPassPayload(string $type): array
    {
        $criteria = collect(app(ProductionAutomationVisualValidator::class)->criteriaFor($type))
            ->mapWithKeys(fn (string $criterion): array => [$criterion => [
                'status' => 'pass',
                'evidence' => 'Acceptable for automated Phase 3 validation.',
                'blocking' => false,
            ]])
            ->all();

        return [
            'identity_score' => 92,
            $type === 'cover' ? 'story_relevance_score' : 'scene_adherence_score' => 91,
            'summary' => ucfirst($type).' image passes validation.',
            'criteria' => $criteria,
        ];
    }

    private function landscapePngBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAD0lEQVQImWP8//8/AwMDAA7/Av86OipYAAAAAElFTkSuQmCC';
    }
}
