<?php

namespace Tests\Feature;

use App\Jobs\SendOrderConfirmationJob;
use App\Models\Order;
use App\Models\OrderAutomation;
use App\Models\OrderAutomationRevision;
use App\Models\OrderProcessEvent;
use App\Models\OrderProcessRun;
use App\Models\Permission;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\OrderAutomation\Process\ProcessDefinition;
use App\Services\OrderAutomation\Process\ProcessLivePolicy;
use App\Services\OrderAutomation\Process\ProcessRuntime;
use App\Services\Orders\AutomationAssignment;
use App\Services\Orders\AutomationHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomationHandoffTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Order $order;

    private OrderProcessRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actor->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update', 'orders.assign', 'orders.assignment.manage'])->pluck('id'));
        $this->actingAs($this->actor);
        $this->order = Order::create(['order_number' => 'SYNTHETIC-TAKE', 'parent_name' => 'Synthetic', 'status' => 'new_confirmed', 'delivery_details' => ['phone' => '01000000001']]);
        $d = ProcessDefinition::defaults('identity');
        $a = OrderAutomation::create(['name' => 'Synthetic', 'draft' => $d, 'enabled' => true, 'process_activated_by' => $this->actor->id]);
        $r = OrderAutomationRevision::create(['automation_id' => $a->id, 'definition' => $d, 'checksum' => hash('sha256', json_encode($d)), 'published_by' => $this->actor->id, 'created_at' => now()]);
        $a->update(['revision_id' => $r->id, 'process_after_event_id' => 0]);
        $e = OrderProcessEvent::create(['event_key' => 'synthetic', 'type' => 'status', 'order_id' => $this->order->id, 'checkout_key' => $this->order->checkoutGroupKey(), 'data' => ['status' => 'new_confirmed'], 'status' => 'pending']);
        $this->run = OrderProcessRun::create(['automation_id' => $a->id, 'revision_id' => $r->id, 'event_id' => $e->id, 'order_id' => $this->order->id, 'checkout_key' => $this->order->checkoutGroupKey(), 'account_key' => 'primary', 'phone_hash' => hash('sha256', 'synthetic'), 'enrollment_key' => hash('sha256', 'synthetic'), 'status' => 'waiting_response', 'node_id' => 'response', 'context' => [], 'wait_until' => now()->addDay()]);
    }

    private function action(string $action)
    {
        return $this->post(route('admin.orders.groups.assignment.automation', $this->order), ['action' => $action]);
    }

    public function test_takeover_pauses_before_assigning_and_records_staff_actor(): void
    {
        $this->assertTrue(app(AutomationAssignment::class)->owns($this->order));
        $this->action('take')->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('paused', $this->run->fresh()->status);
        $this->assertFalse(app(AutomationAssignment::class)->owns($this->order));
        $this->assertFalse(app(ProcessLivePolicy::class)->allowsCheckout($this->order->checkoutGroupKey()));
        $this->assertDatabaseHas('order_group_assignments', ['checkout_group_key' => $this->order->checkoutGroupKey(), 'assigned_to_user_id' => $this->actor->id]);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'order.automation_taken_over', 'user_id' => $this->actor->id]);
        $this->action('take')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('order_automation_holds', 1);
        app(ProcessRuntime::class)->advance($this->run->id);
        $this->assertSame('paused', $this->run->fresh()->status);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_resume_preserves_completed_work_and_payment_edits_and_extends_wait(): void
    {
        $this->action('take')->assertSessionHasNoErrors();
        $deadline = $this->run->fresh()->wait_until;
        $this->order->updateQuietly(['paid_amount_cents' => 100, 'payment_status' => 'partially_paid', 'delivery_details' => ['phone' => '01000000001', 'paid_amount' => 1, 'payment_method' => 'نقدي']]);
        $this->travel(10)->minutes();
        $this->action('resume')->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseCount('order_automation_holds', 0);
        $this->assertSame('waiting_response', $this->run->fresh()->status);
        $this->assertTrue($this->run->fresh()->wait_until->equalTo($deadline->addMinutes(10)));
        $this->assertSame(100, $this->order->fresh()->paid_amount_cents);
        Queue::assertNothingPushed();
    }

    public function test_changed_identity_or_checkout_blocks_stale_resume_without_losing_hold(): void
    {
        $this->action('take')->assertSessionHasNoErrors();
        $this->order->updateQuietly(['uploaded_photos' => ['synthetic/new-identity-source.jpg']]);
        $this->action('resume')->assertSessionHasErrors('assignment');
        $this->assertTrue(AutomationHandoff::held($this->order->checkoutGroupKey()));
        $this->assertSame('paused', $this->run->fresh()->status);
    }

    public function test_inflight_send_keeps_automation_ownership_and_assignment_unchanged(): void
    {
        foreach (['sending', 'uncertain', 'waiting_identity', 'retry_queued'] as $status) {
            $this->run->update(['status' => $status]);
            $this->action('take')->assertSessionHasErrors('assignment');
            $this->assertFalse(AutomationHandoff::held($this->order->checkoutGroupKey()));
            $this->assertDatabaseCount('order_group_assignments', 0);
            $this->assertSame($status, $this->run->fresh()->status);
        }
    }

    public function test_accepted_confirmation_is_paused_without_resending_on_resume(): void
    {
        $event = RoboDeskIntegrationEvent::create(['event_id' => (string) Str::uuid(), 'deduplication_key' => 'synthetic-confirm', 'direction' => 'outbound', 'event_type' => 'order.pending_confirmation', 'checkout_group_key' => $this->order->checkoutGroupKey(), 'order_id' => $this->order->id, 'status' => 'succeeded', 'attempts' => 1, 'payload' => ['confirmation' => ['eligible_at_creation' => true]]]);
        $this->action('take')->assertSessionHasNoErrors();
        $this->assertSame('held', $event->fresh()->status);
        $this->action('resume')->assertSessionHasNoErrors();
        $this->assertSame('succeeded', $event->fresh()->status);
        Queue::assertNotPushed(SendOrderConfirmationJob::class);
    }

    public function test_manual_hold_prevents_pending_enrollment_but_preserves_event(): void
    {
        $this->action('take')->assertSessionHasNoErrors();
        config(['order_process.enabled' => true, 'order_process.after_order_id' => 0, 'order_process.enrollment_mode' => 'all_new']);
        app(ProcessRuntime::class)->tick();
        $this->assertSame('pending', OrderProcessEvent::find($this->run->event_id)->status);
        $this->assertFalse(app(AutomationAssignment::class)->owns($this->order));
        Queue::assertNothingPushed();
    }

    public function test_agent_accounts_and_staff_without_takeover_permission_cannot_take(): void
    {
        $this->actor->update(['agent_api_enabled' => true]);
        $this->action('take')->assertForbidden();
        $this->actor->update(['agent_api_enabled' => false]);
        $this->actor->permissions()->detach();
        $this->actor->unsetRelation('permissions');
        $this->action('take')->assertForbidden();
        $this->assertDatabaseCount('order_automation_holds', 0);
    }
}
