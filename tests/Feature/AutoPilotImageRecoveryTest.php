<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\PuzzleGeneration;
use App\Services\Agentic\CapabilityRegistry;
use App\Services\Agentic\ContextObserver;
use App\Services\Agentic\DecisionOrchestrator;
use App\Services\Agentic\OrderContextBuilder;
use App\Services\Agentic\ProductionExecutor;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\ImageFailureClassifier;
use App\Services\Ai\OpenAiImageInputNormalizer;
use App\Services\ProductAutomation\PuzzleAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AgenticFixtures;
use Tests\Support\AutoPilotExecutionFixtures;
use Tests\TestCase;

class AutoPilotImageRecoveryTest extends TestCase
{
    use AgenticFixtures, AutoPilotExecutionFixtures, RefreshDatabase;

    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        config(['media.private_disk' => 'local', 'order_process.enabled' => false,
            'puzzle_automation.enabled' => true, 'puzzle_automation.after_event_id' => 999999]);
        $image = imagecreatetruecolor(1536, 1024);
        ob_start();
        imagepng($image);
        $this->png = ob_get_clean();
        imagedestroy($image);
        Storage::disk('local')->put('synthetic/photo.png', $this->png);
        $provider = AiProvider::firstOrCreate(['driver' => 'openai'], ['name' => 'Synthetic', 'display_name' => 'Synthetic']);
        $provider->update(['is_active' => true]);
        $model = $provider->models()->firstOrCreate(['code' => 'gpt-image-2'], ['display_name' => 'Synthetic']);
        $model->update(['is_active' => true, 'generation_capabilities_json' => ['image_editing'], 'estimated_cost_amount' => '.1', 'estimated_cost_currency' => 'USD']);
        app(AiProviderCredentialService::class)->save($provider, 'synthetic-only');
        $this->enableSyntheticExecution();
    }

    private function firstFailure(int $status = 429, string $code = 'rate_limit_exceeded'): PuzzleGeneration
    {
        $state = $this->observedCheckout('recovery');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame(1, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => $code, 'message' => 'private customer message', 'param' => 'PRIVATE https://secret.invalid']], $status, ['x-request-id' => 'req_synthetic'])]);
        app(PuzzleAutomation::class)->tick();

        return PuzzleGeneration::sole();
    }

    private function proposeAgain(): array
    {
        $state = app(ContextObserver::class)->observe('recovery');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);

        return app(ProductionExecutor::class)->dispatchPass();
    }

    private function target(): array
    {
        return app(CapabilityRegistry::class)->targets(app(OrderContextBuilder::class)->build('recovery'))[0];
    }

    public function test_rate_limit_recovers_through_fresh_ai_decision_once_and_publishes_same_unit(): void
    {
        $parent = $this->firstFailure();
        $before = $parent->getRawOriginal();
        $this->assertSame('blocked', $parent->status);
        $this->assertSame('IMAGE_RECOVERY_BACKOFF', $this->target()['reason_code']);
        $this->assertStringNotContainsString('private', json_encode($parent->context['failure']));
        $this->assertNull($parent->context['failure']['provider_param']);
        $this->travel(61)->seconds();
        $this->assertSame('execute', $this->target()['decision']);
        $this->assertSame(1, $this->proposeAgain()['admitted']);
        $this->assertSame(0, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        $child = PuzzleGeneration::latest('id')->first();
        $this->assertSame($parent->id, $child->previous_run_id);
        $this->assertNull($child->requested_by);
        $this->assertSame($parent->context['fingerprint'], $child->context['fingerprint']);
        $this->assertSame($before, $parent->fresh()->getRawOriginal());
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode($this->png)]]])]);
        app(PuzzleAutomation::class)->tick();
        $this->assertSame('succeeded', $child->fresh()->status, $child->fresh()->error_code ?? '');
        $this->assertDatabaseCount('puzzle_generations', 2);
        $this->assertSame('unpaid', $child->order->payment_status);
        $this->assertSame(1, (int) DB::table('order_automation_controls')->value('version'));
        $this->assertDatabaseCount('order_attachments', 1);
        $this->assertDatabaseCount('order_previews', 1);
        Http::assertSentCount(1);
    }

    public function test_content_refusal_and_generic_rejection_never_become_automatic_retries(): void
    {
        $run = $this->firstFailure(400, 'moderation_blocked');
        $this->assertSame('content_refusal', $run->context['failure']['category']);
        $this->assertSame('IMAGE_CONTENT_REVIEW_REQUIRED', $this->target()['reason_code']);
        $this->travel(10)->minutes();
        $this->assertSame(0, $this->proposeAgain()['admitted']);
        app(PuzzleAutomation::class)->tick();
        $this->assertDatabaseCount('puzzle_generations', 1);
        Http::assertSentCount(1);
        $generic = ImageFailureClassifier::rejected(400, ['error' => ['message' => 'private unknown error']]);
        $this->assertSame('request_rejected', $generic['category']);
    }

    public function test_billing_quota_is_not_mistaken_for_temporary_rate_limit(): void
    {
        $run = $this->firstFailure(429, 'insufficient_quota');
        $this->assertSame('billing', $run->context['failure']['category']);
        $this->travel(10)->minutes();
        $this->assertSame('IMAGE_PROVIDER_CONFIGURATION_REQUIRED', $this->target()['reason_code']);
        $this->assertSame(0, $this->proposeAgain()['admitted']);
        $this->assertDatabaseCount('puzzle_generations', 1);
        Http::assertSentCount(1);
    }

    public function test_server_error_remains_uncertain(): void
    {
        $run = $this->firstFailure(520, 'server_error');
        $this->assertSame('uncertain', $run->status);
        $this->assertSame('IMAGE_RESULT_RECONCILIATION_REQUIRED', $this->target()['reason_code']);
        $this->travel(10)->minutes();
        $this->assertSame(0, $this->proposeAgain()['admitted']);
        Http::assertSentCount(1);
    }

    public function test_successful_request_with_failed_output_download_never_looks_like_rejected_generation(): void
    {
        $state = $this->observedCheckout('recovery');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame(1, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['url' => 'https://synthetic.invalid/image.png']]], 200, ['x-request-id' => 'req_accepted']),
            'synthetic.invalid/*' => Http::response(['error' => ['code' => 'invalid_image']], 404),
        ]);
        app(PuzzleAutomation::class)->tick();
        $run = PuzzleGeneration::sole();
        $this->assertSame('uncertain', $run->status);
        $this->assertFalse($run->context['failure']['definitively_rejected']);
        $this->assertSame('result_uncertain', $run->context['failure']['category']);
        $this->assertSame('IMAGE_RESULT_RECONCILIATION_REQUIRED', $this->target()['reason_code']);
        $this->assertDatabaseCount('puzzle_generations', 1);
        Http::assertSentCount(2);
    }

    public function test_backoff_and_two_retry_limit_prevent_infinite_paid_loop(): void
    {
        $this->firstFailure();
        foreach ([61, 301] as $seconds) {
            $this->travel($seconds)->seconds();
            $this->assertSame(1, $this->proposeAgain()['admitted']);
            app(PuzzleAutomation::class)->tick();
        }
        $this->travel(20)->minutes();
        $this->assertSame('IMAGE_RECOVERY_LIMIT_REACHED', $this->target()['reason_code']);
        $this->assertSame(0, $this->proposeAgain()['admitted']);
        $this->assertDatabaseCount('puzzle_generations', 3);
        Http::assertSentCount(3);
    }

    public function test_reference_format_uses_same_photos_with_opaque_compatible_normalization_once(): void
    {
        $run = $this->firstFailure(400, 'invalid_image');
        $this->travel(61)->seconds();
        $this->assertSame(1, $this->proposeAgain()['admitted']);
        $child = PuzzleGeneration::latest('id')->first();
        $this->assertTrue($child->context['automatic_recovery']['compatible_references']);
        $this->assertSame($run->context['photos'], $child->context['photos']);
        app(PuzzleAutomation::class)->tick();
        $this->assertSame('IMAGE_RECOVERY_LIMIT_REACHED', $this->target()['reason_code']);
        $normalized = app(OpenAiImageInputNormalizer::class)->normalizeDataUri('data:image/png;base64,'.base64_encode($this->png), true);
        $decoded = imagecreatefromstring($normalized['contents']);
        $this->assertSame(0, (imagecolorat($decoded, 0, 0) >> 24) & 127);
        imagedestroy($decoded);
    }

    public function test_pause_and_input_edit_and_unrecorded_storage_output_fence_retry_admission(): void
    {
        $run = $this->firstFailure();
        $this->travel(61)->seconds();
        DB::table('order_automation_controls')->update(['state' => 'paused', 'version' => 2]);
        $this->assertSame(0, $this->proposeAgain()['admitted']);
        DB::table('order_automation_controls')->update(['state' => 'running', 'version' => 3]);
        Storage::disk('local')->put('order-attachments/'.$run->order_id.'/puzzle-'.$run->id.'.png', $this->png);
        $this->assertSame(0, $this->proposeAgain()['admitted']);
        $this->assertDatabaseCount('puzzle_generations', 1);
        Http::assertSentCount(1);
    }

    public function test_changed_child_inputs_require_review_instead_of_reusing_failed_context(): void
    {
        $run = $this->firstFailure();
        $this->travel(61)->seconds();
        $run->order->update(['child_name' => 'Changed child']);
        $this->assertSame(0, $this->proposeAgain()['admitted']);
        $this->assertDatabaseCount('puzzle_generations', 1);
        Http::assertSentCount(1);
    }
}
