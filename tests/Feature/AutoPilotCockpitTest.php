<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\User;
use App\Services\Agentic\ContextObserver;
use App\Services\Agentic\DashboardMonitor;
use App\Services\Agentic\DecisionOrchestrator;
use App\Services\Agentic\Providers\DecisionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AgenticFixtures;
use Tests\TestCase;

class AutoPilotCockpitTest extends TestCase
{
    use AgenticFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['agentic.observe' => true, 'agentic.evaluate' => true, 'agentic.provider' => 'mock',
            'agentic.debounce_seconds' => 0, 'agentic.daily_limit' => 5000,
            'puzzle_automation.enabled' => true, 'puzzle_automation.after_event_id' => 999999, 'order_process.enabled' => false]);
    }

    public function test_latest_proposal_counts_checkout_once_and_excludes_changed_and_inactive_contexts(): void
    {
        $state = $this->observedCheckout('cockpit-wait', 3);
        Order::where('checkout_group_key', 'cockpit-wait')->update(['status' => 'new']);
        app(ContextObserver::class)->observe('cockpit-wait');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->mock(DecisionProvider::class)->shouldNotReceive('health')->shouldNotReceive('decide');
        $before = DB::table('ai_runtime_states')->first();
        $monitor = app(DashboardMonitor::class);
        $data = $monitor->snapshot();
        $this->assertSame(1, $data['active']);
        $this->assertSame(1, array_sum(array_column($data['groups']['wait'], 'count')));
        $this->assertSame(0, $data['covered']); // The bounded pass has not covered all targets yet.
        $state->refresh()->update(['dirty_at' => now()]);
        $this->assertSame([], $monitor->snapshot()['groups']['wait']);
        $this->assertSame(1, $monitor->snapshot()['uncovered']);
        $state->update(['dirty_at' => null, 'context_version' => $state->context_version + 1]);
        $this->assertSame([], $monitor->snapshot()['groups']['wait']);
        Order::where('checkout_group_key', 'cockpit-wait')->update(['status' => 'cancelled']);
        $this->assertSame(0, $monitor->snapshot()['active']);
        $this->assertEquals($before, DB::table('ai_runtime_states')->first());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_quota_uses_cairo_day_and_offline_dashboard_is_read_only_and_navigation_is_near_dashboard(): void
    {
        $state = $this->observedCheckout('cockpit-offline');
        $state->update(['status' => 'error']);
        DB::table('ai_runtime_states')->where('id', 1)->update(['quota_day' => now('Africa/Cairo')->subDay()->toDateString(), 'attempts' => 488, 'provider_error' => 'PROVIDER_NOT_READY']);
        $this->mock(DecisionProvider::class)->shouldNotReceive('health')->shouldNotReceive('decide');
        $this->assertSame(0, app(DashboardMonitor::class)->snapshot()['attempts']);
        DB::table('ai_runtime_states')->where('id', 1)->update(['quota_day' => now('Africa/Cairo')->toDateString()]);
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->permissions()->sync(Permission::whereIn('key', ['dashboard.view', 'orders.view', 'order_automation.view'])->pluck('id'));
        $response = $this->actingAs($user->fresh())->get(route('admin.agentic.index'))->assertOk()
            ->assertSee('5,000')->assertSee('488')->assertSee('الذاكرة والتصحيحات')->assertSee('تغطية التقييم');
        $html = $response->getContent();
        $this->assertLessThan(strpos($html, 'id="autopilot-activity"'), strpos($html, 'href="'.route('admin.agentic.index').'"'));
        $this->assertMatchesRegularExpression('~لوحة القيادة</a>\s*<a[^>]+href="'.preg_quote(route('admin.agentic.index'), '~').'"~u', $html);
        $before = DB::table('ai_runtime_states')->first();
        $this->getJson(route('admin.agentic.activity'))->assertOk()->assertSee('5,000')->assertSee('488');
        $this->assertEquals($before, DB::table('ai_runtime_states')->first());
        $this->assertDatabaseCount('ai_agent_runs', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_reason_drilldown_restricts_orders_and_keeps_filter_on_polling_url(): void
    {
        $state = $this->observedCheckout('cockpit-filter');
        Order::where('checkout_group_key', 'cockpit-filter')->update(['status' => 'new']);
        app(ContextObserver::class)->observe('cockpit-filter');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->observedCheckout('not-yet-evaluated');
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->permissions()->sync(Permission::whereIn('key', ['orders.view', 'order_automation.view'])->pluck('id'));
        $reason = app(DashboardMonitor::class)->snapshot()['groups']['wait'][0]['reason'];
        $response = $this->actingAs($user->fresh())->get(route('admin.agentic.index', ['reason' => $reason]))->assertOk();
        $this->assertCount(1, $response->viewData('rows'));
        $this->assertSame($state->representative_id, (int) $response->viewData('rows')->first()->representative_id);
        $response->assertSee('reason='.$reason, false);
        $this->get(route('admin.agentic.index', ['reason' => 'INVALID<CODE>']))->assertSessionHasErrors('reason');
    }
}
