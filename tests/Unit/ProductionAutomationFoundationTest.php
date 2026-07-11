<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\ProductionAutomationRun;
use App\Models\ProductionProject;
use App\Models\Story;
use App\Models\User;
use App\Services\ProductionStudio\ProductionAutomationCostLedger;
use App\Services\ProductionStudio\ProductionAutomationFingerprint;
use App\Services\ProductionStudio\ProductionAutomationIdentityValidator;
use App\Services\ProductionStudio\ProductionAutomationStateMachine;
use App\Services\ProductionStudio\ProductionAutomationVisualValidator;
use App\Support\ProductionAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProductionAutomationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_machine_validates_transitions_and_handles_duplicates_idempotently(): void
    {
        [$project, $admin] = $this->project();
        $run = $this->automationRun($project, $admin);
        $state = app(ProductionAutomationStateMachine::class);

        $running = $state->transitionRun($run, ProductionAutomation::STATUS_RUNNING, [], $admin, 'test');
        $this->assertSame(ProductionAutomation::STATUS_RUNNING, $running->status);

        $duplicate = $state->transitionRun($running, ProductionAutomation::STATUS_RUNNING, [], $admin, 'test');
        $this->assertSame($running->id, $duplicate->id);

        $this->expectException(RuntimeException::class);
        $state->transitionRun($duplicate, ProductionAutomation::STATUS_COMPLETED, [], $admin, 'test');
    }

    public function test_cost_ledger_reserves_under_lock_and_releases_unused_amounts(): void
    {
        [$project, $admin] = $this->project();
        $run = $this->automationRun($project, $admin, ['hard_budget' => '0.1000']);
        $step = $run->steps()->create([
            'production_project_id' => $project->id,
            'step_key' => 'child_reference',
            'name' => 'Child Reference',
            'sequence' => 1,
            'stage' => 'child_reference',
            'status' => ProductionAutomation::STEP_PENDING,
            'weight' => 10,
        ]);
        $ledger = app(ProductionAutomationCostLedger::class);

        $entry = $ledger->reserve($run, $step, null, 'fal', 'fal-ai/flux', '0.0800', ['unit' => 'test'], 'reserve-1');
        $this->assertSame('reserved', $entry->status);
        $this->assertSame('0.0800', $ledger->summary($run->fresh())['reserved_cost']);

        $same = $ledger->reserve($run, $step, null, 'fal', 'fal-ai/flux', '0.0800', ['unit' => 'test'], 'reserve-1');
        $this->assertSame($entry->id, $same->id);

        $incurred = $ledger->incur($entry, '0.0500', 'provider-1', actualProviderCostAvailable: true);
        $summary = $ledger->summary($run->fresh());
        $this->assertSame('incurred', $incurred->status);
        $this->assertSame('0.0500', $summary['incurred_cost']);
        $this->assertSame('0.0300', $summary['released_cost']);

        $this->expectException(RuntimeException::class);
        $ledger->reserve($run->fresh(), $step, null, 'fal', 'fal-ai/flux', '0.0600', ['unit' => 'test'], 'reserve-2');
    }

    public function test_fingerprint_changes_when_scene_inputs_change(): void
    {
        [$project, $admin] = $this->project();
        $run = $this->automationRun($project, $admin);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'First',
            'story_text' => 'First text.',
        ]);
        $fingerprints = app(ProductionAutomationFingerprint::class);

        $first = $fingerprints->forArtifact($run, 'scene_image', ['model' => 'm1'], $scene);
        $scene->update(['story_text' => 'Changed text.']);
        $second = $fingerprints->forArtifact($run->fresh(), 'scene_image', ['model' => 'm1'], $scene->fresh());

        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith('sha256:', $first);
    }

    public function test_fingerprint_is_stable_across_runs_when_inputs_are_unchanged(): void
    {
        [$project, $admin] = $this->project();
        $firstRun = $this->automationRun($project, $admin, ['active_project_id' => null]);
        $secondRun = $this->automationRun($project, $admin, ['active_project_id' => null]);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'First',
            'story_text' => 'First text.',
        ]);
        $fingerprints = app(ProductionAutomationFingerprint::class);

        $first = $fingerprints->forArtifact($firstRun, 'scene_image', ['model' => 'm1'], $scene);
        $second = $fingerprints->forArtifact($secondRun, 'scene_image', ['model' => 'm1'], $scene);

        $this->assertSame($first, $second);
    }

    public function test_identity_validation_blocking_flags_override_high_scores(): void
    {
        $validator = app(ProductionAutomationIdentityValidator::class);
        $criteria = collect($validator->requiredCriteria())
            ->mapWithKeys(fn (string $key): array => [$key => [
                'status' => 'pass',
                'evidence' => 'ok',
                'blocking' => false,
            ]])
            ->all();
        $criteria['correct_number_of_children']['status'] = 'fail';
        $criteria['correct_number_of_children']['blocking'] = true;

        $result = $validator->evaluate([
            'score' => 90,
            'summary' => 'Looks close but contains two children.',
            'criteria' => $criteria,
        ]);

        $this->assertSame('fail', $result['decision']);
        $this->assertSame('identity_blocking_flags', $result['safe_failure_code']);
        $this->assertContains('correct_number_of_children', $result['blocking_flags']);
    }

    public function test_phase_three_visual_validation_blocking_flags_override_high_scores(): void
    {
        $validator = app(ProductionAutomationVisualValidator::class);
        $criteria = collect($validator->criteriaFor('scene'))
            ->mapWithKeys(fn (string $key): array => [$key => [
                'status' => 'pass',
                'evidence' => 'ok',
                'blocking' => false,
            ]])
            ->all();
        $criteria['no_text_logos_watermarks']['status'] = 'fail';
        $criteria['no_text_logos_watermarks']['blocking'] = true;

        $result = $validator->evaluate([
            'identity_score' => 92,
            'scene_adherence_score' => 90,
            'summary' => 'Scores are high but generated text is visible.',
            'criteria' => $criteria,
        ], 'scene');

        $this->assertSame('fail', $result['decision']);
        $this->assertSame('visual_blocking_flags', $result['safe_failure_code']);
        $this->assertContains('no_text_logos_watermarks', $result['blocking_flags']);
    }

    private function project(): array
    {
        $admin = User::create([
            'name' => 'Automation Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'admin',
        ]);
        $story = Story::create([
            'title' => 'Automation Story',
            'slug' => 'automation-story-'.strtolower(fake()->bothify('????')),
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
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
        $project = ProductionProject::create([
            'order_id' => $order->id,
            'status' => 'draft',
            'current_stage' => 'intake',
            'created_by_user_id' => $admin->id,
        ]);

        return [$project, $admin];
    }

    private function automationRun(ProductionProject $project, User $admin, array $overrides = []): ProductionAutomationRun
    {
        return ProductionAutomationRun::create(array_merge([
            'production_project_id' => $project->id,
            'active_project_id' => $project->id,
            'status' => ProductionAutomation::STATUS_QUEUED,
            'current_stage' => 'preflight',
            'current_step_key' => 'preflight',
            'hard_budget' => '1.0000',
            'currency' => 'USD',
            'started_by_user_id' => $admin->id,
            'last_transition_at' => now(),
            'last_heartbeat_at' => now(),
        ], $overrides));
    }
}
