<?php

namespace Tests\Feature;

use App\Jobs\SendOrderConfirmationJob;
use App\Models\CheckoutCustomerWorkflow;
use App\Models\Order;
use App\Models\OrderCheckoutReference;
use App\Models\Permission;
use App\Models\RoboDeskConversationSetting;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\RoboDesk\RoboDeskOutbox;
use App\Services\RoboDesk\RoboDeskSignature;
use App\Support\OrderStatusRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private int $deliveryFailures = 0;

    private int $receiptSequence = 1;

    private mixed $responseOverride = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['queue.default' => 'redis']);
        Http::preventStrayRequests();
        Http::fake(['https://hero-kid.robodesk.ai/*' => fn () => $this->responseOverride ?? ($this->deliveryFailures-- > 0
            ? Http::response(['private' => 'do-not-log'], 500)
            : Http::response(['channelId' => 'wamid.synthetic.'.$this->receiptSequence++, '_id' => 'interaction-synthetic'], 200))]);
        RoboDeskConversationSetting::create(['id' => 1, 'enabled' => true, 'email' => 'synthetic@example.test', 'password' => 'test-only']);
        config(['robodesk.enabled' => true, 'robodesk.confirmation.enabled' => true,
            'robodesk.confirmation.language' => 'ar-EG', 'robodesk.confirmation.contract_ready' => true, 'robodesk.confirmation.after_order_id' => 0,
            'robodesk.inbound_secret' => 'synthetic-inbound', 'robodesk.outbound_secret' => 'synthetic-outbound',
            'robodesk.base_url' => 'https://robodesk.test', 'robodesk.confirmation_only' => true]);
    }

    private function order(string $key = 'CHK-SYNTHETIC', string $status = 'new'): Order
    {
        return Order::create(['order_number' => 'HK-'.Str::random(8), 'checkout_group_key' => $key,
            'parent_name' => 'عميل تجريبي', 'status' => $status, 'uploaded_photos' => [],
            'delivery_details' => ['checkout_group' => $key, 'phone' => '01000000001', 'delivery_fee' => 50]]);
    }

    private function request(Order $order): RoboDeskIntegrationEvent
    {
        return RoboDeskIntegrationEvent::where('event_type', 'order.pending_confirmation')
            ->where('checkout_group_key', $order->checkout_group_key)->firstOrFail();
    }

    private function send(RoboDeskIntegrationEvent $event): void
    {
        app()->call([new SendOrderConfirmationJob($event->id), 'handle']);
    }

    private function payload(RoboDeskIntegrationEvent $event, array $overrides = []): array
    {
        return ['id' => (string) Str::uuid(), 'type' => 'order.confirmed', 'data' => array_merge([
            'order_number' => OrderCheckoutReference::where('checkout_group_key', $event->checkout_group_key)->value('short_reference'),
            'template_message_id' => $event->fresh()->response_payload['message_id'] ?? 'wamid.not-sent',
            'interaction_id' => 'interaction-synthetic', 'sender_phone' => '201000000001', 'message_id' => 'synthetic-button-click',
        ], $overrides)];
    }

    private function postConfirmation(array $payload, ?string $signature = null, ?string $timestamp = null, string $uri = '/api/integrations/robodesk/v1/events')
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp ??= (string) now()->timestamp;
        $signature ??= app(RoboDeskSignature::class)->sign($body, $timestamp, $payload['id'], 'synthetic-inbound');

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ROBODESK_TIMESTAMP' => $timestamp, 'HTTP_X_ROBODESK_EVENT_ID' => $payload['id'],
            'HTTP_X_ROBODESK_SIGNATURE' => $signature,
        ], $body);
    }

    public function test_group_queues_once_and_sends_exact_template_with_short_reference(): void
    {
        $one = $this->order();
        $two = $this->order();
        $event = $this->request($one);
        Queue::assertPushed(SendOrderConfirmationJob::class, 1);
        $this->send($event);
        $this->send($event->fresh());
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($one): bool {
            return $request->url() === 'https://hero-kid.robodesk.ai/api/conversation/start/sendMsg'
                && $request['channel'] === 'WhatsApp' && $request['to'] === '201000000001'
                && $request['language'] === 'ar-EG'
                && $request['template']['templateName'] === 'herokid_order_confirmation'
                && $request['template']['text'] === '$1 $2 $3 $4'
                && $request['template']['data'][1] === $one->checkoutReference->short_reference
                && count($request['template']['data']) === 4;
        });
        $this->assertCount(2, $event->fresh()->payload['order_numbers']);
        $this->assertSame('طلب جديد غير مؤكد', OrderStatusRegistry::label('order', 'new'));
        $this->assertSame('طلب جديد مؤكد', OrderStatusRegistry::label('order', 'new_confirmed'));
    }

    public function test_confirmation_changes_all_parts_once_without_payments_or_automation(): void
    {
        $one = $this->order();
        $two = $this->order();
        $event = $this->request($one);
        $this->send($event);
        $payload = $this->payload($event);
        $this->postConfirmation($payload)->assertAccepted();
        $confirmedAt = CheckoutCustomerWorkflow::firstOrFail()->confirmed_at->toISOString();
        $this->postConfirmation($payload)->assertOk()->assertJson(['duplicate' => true]);
        $this->travel(5)->minutes();
        $this->postConfirmation($this->payload($event))->assertAccepted();
        $this->assertSame($confirmedAt, CheckoutCustomerWorkflow::firstOrFail()->confirmed_at->toISOString());
        foreach ([$one, $two] as $order) {
            $this->assertSame('new_confirmed', $order->refresh()->status);
            $this->assertSame('unpaid', $order->payment_status);
            $this->assertSame(1, $order->statusLogs()->where('status', 'new_confirmed')->count());
        }
        $this->assertDatabaseCount('order_automation_runs', 0);
        Queue::assertPushed(SendOrderConfirmationJob::class, 1);
        $one->updateQuietly(['status' => 'generating']);
        $this->postConfirmation($this->payload($event))->assertAccepted();
        $this->assertSame('generating', $one->refresh()->status);
    }

    public function test_missing_or_invalid_signature_and_expired_signature_are_rejected(): void
    {
        $event = $this->request($this->order());
        $this->send($event);
        $payload = $this->payload($event);
        $this->postJson('/api/integrations/robodesk/v1/events', $payload)->assertUnauthorized();
        $this->postConfirmation($payload, 'bad')->assertUnauthorized();
        $this->postConfirmation($payload, null, (string) now()->subMinutes(10)->timestamp)->assertUnauthorized();
        $this->assertDatabaseCount('checkout_customer_workflows', 0);
    }

    public function test_callback_requires_sent_request_correct_phone_checkout_and_button_message(): void
    {
        $event = $this->request($this->order());
        $this->postConfirmation($this->payload($event))->assertStatus(409);
        $this->send($event);
        $this->postConfirmation($this->payload($event, ['interaction_id' => 'interaction-synthetic', 'sender_phone' => '201000000002']))->assertForbidden();
        $this->postConfirmation($this->payload($event, ['order_number' => 'HK-OTHER']))->assertStatus(409);
        $this->postConfirmation($this->payload($event, ['message_id' => '']))->assertUnprocessable();
        $this->postConfirmation($this->payload($event, ['template_message_id' => 'wamid.other']))->assertStatus(409);
        $this->assertDatabaseCount('checkout_customer_workflows', 0);
    }

    public function test_late_confirmations_never_reopen_or_regress_modified_or_deleted_orders(): void
    {
        foreach (['cancelled', 'generating', 'shipped', 'deleted'] as $status) {
            $order = $this->order('CHK-'.$status);
            $event = $this->request($order);
            $this->send($event);
            $status === 'deleted' ? $order->delete() : $order->updateQuietly(['status' => $status]);
            $this->postConfirmation($this->payload($event))->assertStatus(409);
            $this->assertSame($status === 'deleted' ? 'new' : $status, $order->fresh()->status);
        }
        $this->assertDatabaseCount('checkout_customer_workflows', 0);
    }

    public function test_changed_contact_prevents_confirmation(): void
    {
        $order = $this->order();
        $event = $this->request($order);
        $this->send($event);
        $order->updateQuietly(['delivery_details' => ['phone' => '01000000002']]);
        $this->postConfirmation($this->payload($event))->assertStatus(409);
        $this->assertSame('new', $order->fresh()->status);
    }

    public function test_duplicate_callback_id_with_different_content_is_rejected(): void
    {
        $event = $this->request($this->order());
        $this->send($event);
        $payload = $this->payload($event);
        $this->postConfirmation($payload)->assertAccepted();
        $payload['data']['order_number'] = 'another-checkout';
        $this->postConfirmation($payload)->assertStatus(409);
        $payload['id'] = $event->event_id;
        $this->postConfirmation($payload)->assertStatus(409);
    }

    public function test_activation_is_future_only_and_kill_switch_stops_queued_sends(): void
    {
        config(['robodesk.confirmation.enabled' => false]);
        $old = $this->order('CHK-OLD');
        config(['robodesk.confirmation.enabled' => true, 'robodesk.confirmation.after_order_id' => $old->id]);
        $this->order('CHK-OLD'); // Adding a child to an old checkout must not send confirmation.
        $this->send($this->request($old));
        $new = $this->order('CHK-NEW');
        config(['robodesk.confirmation.enabled' => false]);
        $this->send($this->request($new));
        Http::assertNothingSent();
    }

    public function test_no_sends_without_explicit_boundary_and_language_or_after_status_change(): void
    {
        config(['robodesk.confirmation.after_order_id' => null]);
        $this->send($this->request($this->order('CHK-NO-BOUNDARY')));
        config(['robodesk.confirmation.after_order_id' => 0, 'robodesk.confirmation.language' => '']);
        $this->send($this->request($this->order('CHK-NO-LANGUAGE')));
        config(['robodesk.confirmation.language' => 'ar']);
        $order = $this->order('CHK-LATE');
        $order->updateQuietly(['status' => 'cancelled']);
        $this->send($this->request($order));
        Http::assertNothingSent();
    }

    public function test_confirmation_only_mode_does_not_activate_other_event_types(): void
    {
        $order = $this->order();
        $order->update(['status' => 'under_review']);
        $event = RoboDeskIntegrationEvent::where('event_type', 'order.workflow_updated')->firstOrFail();
        $this->send($event);
        Http::assertNothingSent();
        $payload = $this->payload($this->request($order));
        $payload['type'] = 'order.rejected';
        $this->postConfirmation($payload)->assertUnprocessable();
        $this->assertSame('under_review', $order->fresh()->status);
    }

    public function test_uncertain_send_is_never_retried_and_errors_are_sanitized(): void
    {
        $this->deliveryFailures = 1;
        $order = $this->order();
        $event = $this->request($order);
        $this->send($event);
        $this->assertSame('unknown', $event->fresh()->status);
        $this->assertStringNotContainsString('do-not-log', $event->fresh()->last_error);
        $this->send($event->fresh());
        Http::assertSentCount(1);
        $this->assertSame(1, $event->fresh()->attempts);
        $this->expectException(HttpException::class);
        app(RoboDeskOutbox::class)->release($event->fresh());
    }

    public function test_flat_confirmation_endpoint_fixes_target_status_and_checks_signed_original_body(): void
    {
        $order = $this->order();
        $event = $this->request($order);
        $this->send($event);
        $envelope = $this->payload($event);
        $flat = ['id' => $envelope['id'], ...$envelope['data'], 'target_status' => 'delivered'];
        $this->postConfirmation($flat, null, null, '/api/integrations/robodesk/v1/order-confirmations')->assertAccepted();
        $this->assertSame('new_confirmed', $order->fresh()->status);
    }

    public function test_template_uses_quantity_and_total_after_discount_including_delivery(): void
    {
        $order = $this->order();
        $order->items()->create(['item_type' => 'product', 'title' => 'كتاب تلوين', 'quantity' => 2,
            'unit_price_cents' => 10000, 'total_price_cents' => 20000]);
        $order->updateQuietly(['discount_cents' => 3000]);
        $this->send($this->request($order));
        Http::assertSent(fn ($request) => $request['template']['data'][2] === 'كتاب تلوين × 2'
            && $request['template']['data'][3] === '220.00');
    }

    public function test_contract_readiness_and_async_queue_are_required(): void
    {
        config(['robodesk.confirmation.contract_ready' => false]);
        $this->send($this->request($this->order('CHK-NO-CONTRACT')));
        config(['robodesk.confirmation.contract_ready' => true, 'queue.default' => 'sync']);
        $this->send($this->request($this->order('CHK-SYNC')));
        Http::assertNothingSent();
    }

    public function test_open_conversation_and_disabled_template_are_blocked_without_retries(): void
    {
        foreach ([501 => 'Customer already have another interaction', 409 => 'Template disabled'] as $status => $body) {
            $this->responseOverride = Http::response($body, $status);
            $event = $this->request($this->order('CHK-HTTP-'.$status));
            $this->send($event);
            $this->send($event->fresh());
            $this->assertSame('blocked', $event->fresh()->status);
            $this->assertSame(1, $event->fresh()->attempts);
        }
        Http::assertSentCount(2);
    }

    public function test_timeout_or_receipt_without_wamid_is_unknown_and_never_resent(): void
    {
        foreach ([Http::failedConnection(), Http::response(['_id' => 'interaction-synthetic'], 200)] as $i => $response) {
            $this->responseOverride = $response;
            $event = $this->request($this->order('CHK-UNKNOWN-'.$i));
            $this->send($event);
            $this->send($event->fresh());
            $this->assertSame('unknown', $event->fresh()->status);
            $this->assertSame(1, $event->fresh()->attempts);
        }
    }

    public function test_interaction_id_must_match_original_template_receipt(): void
    {
        $event = $this->request($this->order());
        $this->send($event);
        $this->postConfirmation($this->payload($event, ['interaction_id' => 'another-interaction']))->assertStatus(409);
        $this->assertDatabaseCount('checkout_customer_workflows', 0);
    }

    public function test_automation_page_explains_confirmation_and_retains_existing_permissions(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin->permissions()->sync(Permission::whereIn('key', ['orders.view', 'order_automation.view'])->pluck('id'));
        $this->actingAs($admin)->get('/admin/order-automation')->assertOk()->assertSee('تأكيد الطلب عبر واتساب')
            ->assertSee('طلب جديد غير مؤكد')->assertSee('طلب جديد مؤكد')->assertSee($order->order_number);
        $admin->permissions()->sync(Permission::where('key', 'order_automation.view')->pluck('id'));
        $admin->unsetRelation('permissions');
        $this->get('/admin/order-automation')->assertOk()->assertDontSee($order->order_number);
        $admin->permissions()->detach();
        $admin->unsetRelation('permissions');
        $this->get('/admin/order-automation')->assertForbidden();
    }
}
