<?php

namespace Tests\Feature;

use App\Models\AiOrderDecision;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use App\Services\Agentic\ContextObserver;
use App\Services\Agentic\DecisionOrchestrator;
use App\Services\Agentic\Providers\DecisionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AgenticFixtures;
use Tests\TestCase;

class AgenticDashboardTest extends TestCase
{
    use AgenticFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['agentic.observe' => false, 'agentic.evaluate' => false, 'agentic.provider' => 'mock', 'agentic.debounce_seconds' => 0,
            'puzzle_automation.enabled' => true, 'puzzle_automation.after_event_id' => 999999, 'order_process.enabled' => false]);
    }

    public function test_dashboard_and_monitor_require_admin_and_both_permissions(): void
    {
        $this->get(route('admin.agentic.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get(route('admin.agentic.index'))->assertForbidden();
        $this->actingAs($this->staff(['orders.view']))->get(route('admin.agentic.monitor'))->assertForbidden();
        $this->get(route('admin.agentic.activity'))->assertForbidden();
        $this->actingAs($this->staff(['order_automation.view']))->get(route('admin.agentic.index'))->assertForbidden();
    }

    public function test_provider_offline_or_disabled_does_not_hide_unobserved_and_changed_orders(): void
    {
        $state = $this->observedCheckout('dashboard');
        $other = Order::create(['checkout_group_key' => 'unseen', 'order_number' => 'SYN-UNSEEN', 'parent_name' => 'Synthetic', 'child_name' => 'Synthetic', 'status' => 'new']);
        Order::where('checkout_group_key', 'dashboard')->update(['status' => 'generating']);
        $this->mock(DecisionProvider::class)->shouldNotReceive('health')->shouldNotReceive('decide');
        $this->actingAs($this->staff())->get(route('admin.agentic.index'))->assertOk()->assertSee('Shadow Mode')->assertSee('generating')->assertSee('لم يصدر اقتراح');
        $this->get(route('admin.agentic.monitor'))->assertOk()->assertJsonStructure(['html']);
        $this->get(route('admin.agentic.activity'))->assertOk()->assertJsonStructure(['html'])->assertSee('Auto Pilot');
        $this->get(route('admin.agentic.show', $state->representative_id))->assertOk()->assertSee('لم تُرصد هذه النسخة بعد')->assertSee('generating');
        $this->get(route('admin.agentic.index', ['state' => 'unobserved']))->assertOk()->assertSee(route('admin.agentic.show', $other->id), false);
        $this->assertDatabaseCount('ai_agent_runs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_filters_history_and_financial_redaction_preserve_shadow_labels(): void
    {
        config(['agentic.observe' => true, 'agentic.evaluate' => true]);
        $state = $this->observedCheckout('history');
        Order::where('checkout_group_key', 'history')->update(['paid_amount_cents' => 123456, 'payment_status' => 'partially_paid']);
        app(ContextObserver::class)->observe('history');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $productId = Product::where('slug', 'personalized-child-adventure-hdf-puzzle')->value('id');
        $this->actingAs($this->staff())->get(route('admin.agentic.index', ['decision' => 'execute', 'product' => $productId, 'state' => 'covered']))
            ->assertOk()->assertSee('اقتراح مقبول للمحاكاة')->assertSee('تنفيذ بواسطة الـAgent: لم يحدث');
        $this->get(route('admin.agentic.show', $state->representative_id))->assertOk()->assertSee('would_request_executor_preflight')->assertDontSee('1,234.56');
        $this->actingAs($this->staff(['orders.view', 'order_automation.view', 'orders.statistics.view']))->get(route('admin.agentic.show', $state->representative_id))->assertOk()->assertSee('1,234.56');
        $this->get(route('admin.agentic.index', ['decision' => 'wait']))->assertOk()->assertSee('لا توجد طلبات مطابقة.');
        $this->get(route('admin.agentic.index', ['state' => 'invalid']))->assertSessionHasErrors('state');
        $this->assertDatabaseCount('puzzle_generations', 0);
    }

    public function test_latest_decision_and_filter_remain_ordered_with_identical_timestamps(): void
    {
        $this->freezeTime();
        config(['agentic.observe' => true, 'agentic.evaluate' => true]);
        $state = $this->observedCheckout('same-second', 2);
        Order::where('checkout_group_key', 'same-second')->orderBy('id')->first()->update(['uploaded_photos' => []]);
        app(ContextObserver::class)->observe('same-second');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $last = AiOrderDecision::orderByDesc('id')->first();
        $this->assertSame($last->agent_run_id, $state->fresh()->last_run_id);
        $response = $this->actingAs($this->staff())->get(route('admin.agentic.index', ['decision' => 'human_required']))->assertOk();
        $this->assertSame($last->agent_run_id, $response->viewData('rows')->first()->latest->id);
        $this->get(route('admin.agentic.index', ['decision' => 'execute']))->assertOk()->assertSee('لا توجد طلبات مطابقة.');
    }

    public function test_retry_schedule_renders_for_query_rows_without_calling_ai(): void
    {
        $state = $this->observedCheckout('retry-display');
        $state->update(['status' => 'pending', 'error_code' => 'DECISION_TRANSPORT_TIMEOUT', 'next_evaluation_at' => now()->addMinutes(5)]);
        $this->actingAs($this->staff())->get(route('admin.agentic.index'))->assertOk()
            ->assertSee('أقرب موعد لإعادة تقييم القرار')->assertSee('لا تُعاد خطوات الإنتاج أو الرسائل');
        $this->getJson(route('admin.orders.autopilot-monitor', $state->representative_id))->assertOk()
            ->assertJsonStructure(['html']);
        $state->update(['status' => 'error', 'next_evaluation_at' => null]);
        $this->get(route('admin.agentic.index'))->assertOk()->assertSee('توقفت المحاولات التلقائية');
        $this->assertDatabaseCount('ai_agent_runs', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function staff(array $keys = ['orders.view', 'order_automation.view']): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));

        return $user->fresh();
    }
}
