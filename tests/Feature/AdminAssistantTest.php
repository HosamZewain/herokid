<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderPaymentEvent;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdminAssistant\AssistantProvider;
use App\Services\Payments\PaymentCollectionReportService;
use App\Support\RequestSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AgenticFixtures;
use Tests\TestCase;

class AdminAssistantTest extends TestCase
{
    use AgenticFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['admin_assistant.enabled' => true, 'admin_assistant.per_user_per_minute' => 100,
            'agentic.observe' => true, 'order_process.enabled' => false, 'puzzle_automation.enabled' => false]);
    }

    private function admin(array $permissions = ['orders.view']): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'agent_api_enabled' => false]);
        $user->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));
        $this->actingAs($user->fresh());

        return $user;
    }

    private function plan(string $tool = 'order', ?string $reference = null, array $extra = []): array
    {
        return array_replace(['tool' => $tool, 'reference' => $reference, 'range' => 'page', 'start_date' => null, 'end_date' => null, 'intent' => 'read'], $extra);
    }

    private function ask(array $input = [])
    {
        $signature = $this->getJson(route('admin.assistant.access'))->assertOk()->json('signature');

        return $this->postJson(route('admin.assistant.ask'), array_replace(['question' => 'اشرحلي', 'path' => '/admin/orders', 'query' => [], 'signature' => $signature], $input));
    }

    public function test_disabled_unprivileged_and_agent_accounts_never_reach_provider(): void
    {
        $this->mock(AssistantProvider::class)->shouldNotReceive('plan');
        $this->getJson(route('admin.assistant.access'))->assertUnauthorized();
        $user = $this->admin([]);
        $this->getJson(route('admin.assistant.access'))->assertForbidden();
        $user = $this->admin();
        config(['admin_assistant.enabled' => false]);
        $this->getJson(route('admin.assistant.access'))->assertForbidden();
        config(['admin_assistant.enabled' => true]);
        $user->update(['agent_api_enabled' => true]);
        $this->getJson(route('admin.assistant.access'))->assertForbidden();
    }

    public function test_order_diagnosis_is_read_only_and_financial_data_never_enters_model_or_reply(): void
    {
        $this->admin();
        $state = $this->observedCheckout('assistant-order', 2);
        Order::where('checkout_group_key', 'assistant-order')->update(['paid_amount_cents' => 987654]);
        $orders = Order::where('checkout_group_key', 'assistant-order')->get()->toArray();
        $this->mock(AssistantProvider::class)->shouldReceive('plan')->once()->withArgs(function ($input) {
            $json = json_encode($input);
            $this->assertStringNotContainsString('Synthetic child', $json);
            $this->assertStringNotContainsString('987654', $json);
            $this->assertNotContains('payments', $input['available_tools']);

            return true;
        })->andReturn($this->plan('order', (string) $state->representative_id));
        $r = $this->ask()->assertOk()->assertJsonPath('read_only', true);
        $this->assertStringNotContainsString('9,876.54', $r->getContent());
        $this->assertStringNotContainsString('paid_amount', $r->getContent());
        $this->assertEquals($orders, Order::where('checkout_group_key', 'assistant-order')->get()->toArray());
        $this->assertDatabaseCount('ai_agent_runs', 0);
        $this->assertDatabaseCount('ai_production_executions', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_assigned_only_orders_and_queue_cannot_leak_other_checkouts(): void
    {
        $user = $this->admin();
        Setting::updateOrCreate(['key' => 'orders_visibility'], ['value' => 'assigned']);
        RequestSettings::forget();
        $own = $this->observedCheckout('assistant-own');
        $other = $this->observedCheckout('assistant-other');
        DB::table('order_group_assignments')->insert(['checkout_group_key' => 'assistant-own', 'assigned_to_user_id' => $user->id, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->mock(AssistantProvider::class)->shouldReceive('plan')->once()->andReturn($this->plan('order', (string) $other->representative_id));
        $this->ask()->assertNotFound();
        $this->mock(AssistantProvider::class)->shouldReceive('plan')->once()->andReturn($this->plan('queue'));
        $r = $this->ask()->assertOk();
        $this->assertCount(1, $r->json('sources'));
        $this->assertStringContainsString('/'.$own->representative_id, $r->json('sources.0.url'));
        $this->get(route('admin.agentic.index'))->assertForbidden(); // Existing global-console gate retained.
    }

    public function test_forged_finance_tool_is_rejected_before_reading_report(): void
    {
        $this->admin();
        $this->mock(PaymentCollectionReportService::class)->shouldNotReceive('events');
        $this->mock(AssistantProvider::class)->shouldReceive('plan')->once()->andReturn($this->plan('payments'));
        $this->ask(['path' => '/admin/payment-report'])->assertUnprocessable();
    }

    public function test_permission_revocation_during_provider_call_denies_result(): void
    {
        $user = $this->admin(['orders.view', 'sales_reports.view']);
        $this->mock(PaymentCollectionReportService::class)->shouldNotReceive('events');
        $this->mock(AssistantProvider::class)->shouldReceive('plan')->once()->andReturnUsing(function () use ($user) {
            $user->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));

            return $this->plan('payments');
        });
        $this->ask()->assertForbidden();
    }

    public function test_report_matches_native_ledger_summary_and_date_filters(): void
    {
        $user = $this->admin(['sales_reports.view']);
        $this->travelTo(now()->setDate(2026, 11, 5)->startOfDay());
        $order = Order::create(['order_number' => 'SYN-report', 'checkout_group_key' => 'assistant-report', 'status' => 'new', 'parent_name' => 'Private Name']);
        foreach ([12345, -345] as $amount) {
            OrderPaymentEvent::create(['event_uuid' => Str::uuid(), 'checkout_group_key' => 'assistant-report', 'order_id' => $order->id, 'actor_user_id' => $user->id,
                'event_type' => 'payment_received', 'source' => 'synthetic', 'previous_status' => 'unpaid', 'new_status' => 'partially_paid',
                'previous_paid_amount_cents' => 0, 'new_paid_amount_cents' => max(0, $amount), 'amount_delta_cents' => $amount,
                'affects_collection_stats' => true, 'occurred_at' => now()]);
        }
        $this->mock(AssistantProvider::class)->shouldReceive('plan')->once()->andReturn($this->plan('payments'));
        $r = $this->ask(['path' => '/admin/payment-report', 'query' => ['range' => 'custom', 'start_date' => '2026-11-01', 'end_date' => '2026-11-05']])->assertOk();
        $values = collect($r->json('facts'))->pluck('value');
        $this->assertContains('120.00', $values);
        $this->assertContains('123.45', $values);
        $this->assertStringContainsString('start_date=2026-11-01', $r->json('sources.0.url'));
        $this->assertStringNotContainsString('Private Name', $r->getContent());
    }

    public function test_unknown_page_filter_does_not_silently_return_unfiltered_totals(): void
    {
        $this->admin(['sales_reports.view']);
        $this->mock(PaymentCollectionReportService::class)->shouldNotReceive('events');
        $this->mock(AssistantProvider::class)->shouldReceive('plan')->once()->andReturn($this->plan('payments'));
        $r = $this->ask(['path' => '/admin/payment-report', 'query' => ['secret_filter' => 'yes']])->assertOk()->assertJsonCount(0, 'facts');
        $this->assertStringContainsString('فلاتر', $r->json('text'));
    }

    public function test_execution_request_only_explains_and_links_without_writes(): void
    {
        $this->admin();
        $state = $this->observedCheckout('assistant-execute');
        $this->mock(AssistantProvider::class)->shouldReceive('plan')->once()->andReturn($this->plan('order', (string) $state->representative_id, ['intent' => 'execute']));
        $r = $this->ask(['question' => 'كمّل الأوردر وابعت المعاينة'])->assertOk();
        $this->assertStringStartsWith('لم يتم تنفيذ أي خطوة.', $r->json('text'));
        $this->assertDatabaseCount('ai_production_executions', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_context_token_is_bound_to_user_and_permissions_and_expires(): void
    {
        $first = $this->admin();
        $this->mock(AssistantProvider::class)->shouldReceive('plan')->once()->andReturn($this->plan('help'));
        $token = $this->ask()->assertOk()->json('previous');
        $this->admin();
        $this->mock(AssistantProvider::class)->shouldNotReceive('plan');
        $this->ask(['previous' => $token])->assertForbidden();
        $this->actingAs($first);
        $this->travel(16)->minutes();
        $this->ask(['previous' => $token])->assertForbidden();
    }

    public function test_unavailable_provider_fails_closed_and_logs_no_question_or_payload(): void
    {
        $this->admin();
        $this->mock(AssistantProvider::class)->shouldReceive('plan')->once()->andThrow(new \RuntimeException('SECRET_PROVIDER_TRANSCRIPT'));
        $r = $this->ask(['question' => 'PRIVATE_QUESTION <script>bad</script>'])->assertStatus(503);
        $this->assertStringNotContainsString('SECRET_PROVIDER', $r->getContent());
        $logs = json_encode(DB::table('admin_activity_logs')->get());
        $this->assertStringNotContainsString('PRIVATE_QUESTION', $logs);
        $this->assertStringNotContainsString('SECRET_PROVIDER', $logs);
        Queue::assertNothingPushed();
    }

    public function test_unsupported_and_injected_extra_fields_do_not_execute_arbitrary_tools(): void
    {
        $this->admin();
        $this->mock(AssistantProvider::class)->shouldReceive('plan')->once()->andReturn($this->plan('help') + ['sql' => 'select * from users']);
        $this->ask()->assertUnprocessable();
    }

    public function test_report_custom_period_is_bounded(): void
    {
        $this->admin(['sales_reports.view']);
        $this->mock(PaymentCollectionReportService::class)->shouldNotReceive('events');
        $this->mock(AssistantProvider::class)->shouldReceive('plan')->once()->andReturn($this->plan('payments', null, ['range' => 'custom', 'start_date' => '2020-01-01', 'end_date' => '2026-11-05']));
        $this->ask()->assertUnprocessable();
    }

    public function test_merge_alias_cannot_escape_assigned_scope(): void
    {
        $user = $this->admin();
        Setting::updateOrCreate(['key' => 'orders_visibility'], ['value' => 'assigned']);
        RequestSettings::forget();
        $own = $this->observedCheckout('alias-source');
        $this->observedCheckout('alias-target');
        DB::table('order_group_assignments')->insert(['checkout_group_key' => 'alias-source', 'assigned_to_user_id' => $user->id, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_group_merge_aliases')->insert(['source_checkout_group_key' => 'alias-source', 'target_checkout_group_key' => 'alias-target', 'reason' => 'synthetic test', 'merged_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->mock(AssistantProvider::class)->shouldReceive('plan')->once()->andReturn($this->plan('order', (string) $own->representative_id));
        $this->ask()->assertNotFound();
    }

    public function test_conflicting_payment_balances_are_unknown_not_zero(): void
    {
        $this->admin(['orders.view', 'orders.statistics.view']);
        $state = $this->observedCheckout('conflicting-balances', 2);
        Order::whereKey($state->representative_id)->update(['paid_amount_cents' => 999]);
        $this->mock(AssistantProvider::class)->shouldReceive('plan')->once()->andReturn($this->plan('order', (string) $state->representative_id));
        $result = $this->ask()->assertOk();
        $amount = collect($result->json('facts'))->firstWhere('label', 'الدفعات المسجلة بالجنيه');
        $this->assertSame('غير محسوم؛ راجع تعارض الأرصدة', $amount['value']);
    }
}
