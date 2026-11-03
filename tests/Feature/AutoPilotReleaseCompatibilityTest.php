<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agentic\CapabilityRegistry;
use App\Services\Agentic\ContextObserver;
use App\Services\Agentic\OrderContextBuilder;
use App\Services\Agentic\PolicyEngine;
use App\Support\RequestSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AgenticFixtures;
use Tests\TestCase;

class AutoPilotReleaseCompatibilityTest extends TestCase
{
    use AgenticFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['agentic.observe' => true, 'agentic.evaluate' => false, 'agentic.provider' => 'mock',
            'puzzle_automation.enabled' => true, 'puzzle_automation.after_event_id' => 999999, 'order_process.enabled' => false]);
    }

    public function test_current_pause_fence_invalidates_prior_context_and_never_admits_new_work(): void
    {
        $state = $this->observedCheckout('pause-fence', 2);
        $before = $state->fingerprint;
        foreach (['pause_requested' => 'AUTOMATION_PAUSE_REQUESTED', 'pause_uncertain' => 'AUTOMATION_CONTROL_UNCERTAIN', 'paused' => 'AUTOMATION_PAUSED'] as $status => $reason) {
            DB::table('order_automation_controls')->updateOrInsert(['checkout_key' => 'pause-fence'], ['state' => $status, 'version' => 2]);
            $ordersBefore = DB::table('orders')->get()->toJson();
            $controlBefore = DB::table('order_automation_controls')->get()->toJson();
            $context = app(ContextObserver::class)->observe('pause-fence')->snapshot;
            $targets = app(CapabilityRegistry::class)->targets($context);
            $this->assertNotSame($before, $context['fingerprint']);
            $this->assertCount(1, $targets);
            $this->assertSame($reason, $targets[0]['reason_code']);
            $this->assertSame($status === 'pause_uncertain' ? 'escalate' : 'human_required', $targets[0]['decision']);
            $attempt = ['decision' => 'execute', 'action' => 'generate_product_image', 'target_type' => 'checkout',
                'target_id' => $targets[0]['target_id'], 'context_version' => $context['context_version']];
            $this->assertFalse(app(PolicyEngine::class)->evaluate($attempt, $context, [$targets[0]['target_id']])['allowed_proposal']);
            $this->assertSame($ordersBefore, DB::table('orders')->get()->toJson());
            $this->assertSame($controlBefore, DB::table('order_automation_controls')->get()->toJson());
            $before = $context['fingerprint'];
        }
        DB::table('order_automation_controls')->where('checkout_key', 'pause-fence')->update(['state' => 'running', 'version' => 3]);
        $targets = app(CapabilityRegistry::class)->targets(app(OrderContextBuilder::class)->build('pause-fence'));
        $this->assertSame(['execute', 'execute'], array_column($targets, 'decision'));
        $this->assertDatabaseCount('ai_agent_runs', 0);
        $this->assertDatabaseCount('puzzle_generations', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_restricted_staff_cannot_bypass_all_order_scope_through_dashboard_activity_memory_or_detail(): void
    {
        $state = $this->observedCheckout('restricted');
        $order = Order::findOrFail($state->representative_id);
        Setting::updateOrCreate(['key' => 'orders_visibility'], ['value' => 'assigned']);
        RequestSettings::forget();
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->permissions()->sync(Permission::whereIn('key', ['orders.view', 'order_automation.view', 'order_automation.manage'])->pluck('id'));
        $this->actingAs($user->fresh());
        foreach (['admin.agentic.index', 'admin.agentic.monitor', 'admin.agentic.activity', 'admin.agentic.memory.index', 'admin.agentic.memory.create'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        // Existing assignment global scope hides the bound order before the
        // cross-order screen middleware; its 404 must remain intact.
        $this->get(route('admin.agentic.show', $order))->assertNotFound();
        $this->post(route('admin.agentic.memory.import'))->assertForbidden();
        $this->assertDatabaseCount('ai_memories', 0);
        $user->permissions()->syncWithoutDetaching(Permission::where('key', 'orders.view_all')->pluck('id'));
        $this->actingAs($user->fresh())->get(route('admin.agentic.index'))->assertOk();
    }
}
