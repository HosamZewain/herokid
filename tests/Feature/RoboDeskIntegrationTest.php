<?php

namespace Tests\Feature;

use App\Jobs\SendRoboDeskEventJob;
use App\Models\Order;
use App\Models\RoboDeskConversationSetting;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\Story;
use App\Services\RoboDesk\PaymentProofService;
use App\Services\RoboDesk\RoboDeskCheckoutPayload;
use App\Services\RoboDesk\RoboDeskOutbox;
use App\Services\RoboDesk\RoboDeskSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RoboDeskIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_is_fail_closed_and_new_orders_are_held_without_credentials(): void
    {
        config()->set('robodesk.enabled', false);
        config()->set('robodesk.outbound_secret', '');

        $order = $this->order('CHK-ROBODESK-HELD');

        $this->assertDatabaseHas('robodesk_integration_events', [
            'direction' => 'outbound',
            'event_type' => 'order.pending_confirmation',
            'checkout_group_key' => $order->checkout_group_key,
            'status' => 'held',
        ]);

        $this->getJson('/api/integrations/robodesk/v1/health')->assertStatus(503);
    }

    public function test_signed_confirmation_updates_the_checkout_once_and_duplicate_delivery_is_idempotent(): void
    {
        config()->set('robodesk.enabled', true);
        config()->set('robodesk.inbound_secret', 'inbound-test-secret');
        config()->set('robodesk.outbound_secret', '');

        Queue::fake();
        config(['queue.default' => 'redis']);
        config(['robodesk.outbound_secret' => 'outbound-test-secret', 'robodesk.confirmation.enabled' => true,
            'robodesk.confirmation.language' => 'ar-EG', 'robodesk.confirmation.contract_ready' => true, 'robodesk.confirmation.after_order_id' => 0]);
        RoboDeskConversationSetting::create(['id' => 1, 'enabled' => true, 'email' => 'synthetic@example.test', 'password' => 'test-only']);
        Http::fake(['*' => Http::response(['channelId' => 'wamid.synthetic', '_id' => 'interaction-synthetic'], 200)]);
        $order = $this->order('CHK-ROBODESK-CONFIRM');
        $request = RoboDeskIntegrationEvent::where('checkout_group_key', $order->checkout_group_key)->firstOrFail();
        (new SendRoboDeskEventJob($request->id))->handle(app(RoboDeskSignature::class), app(RoboDeskCheckoutPayload::class));
        $eventId = (string) Str::uuid();
        $payload = [
            'id' => $eventId,
            'type' => 'order.confirmed',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'checkout_reference' => $order->checkout_group_key,
                'order_number' => $order->checkoutReference->short_reference,
                'template_message_id' => 'wamid.synthetic',
                'interaction_id' => 'interaction-synthetic', 'sender_phone' => '01501188884',
                'message_id' => 'wa-click-1',
                'contact_id' => 'contact-test',
                'conversation_id' => 'conversation-test',
                'comment' => 'تم التأكيد',
            ],
        ];

        $first = $this->signedJson('/api/integrations/robodesk/v1/events', $payload);
        $first->assertAccepted()->assertJson(['accepted' => true]);

        $this->assertSame('new_confirmed', $order->refresh()->status);
        $this->assertDatabaseHas('checkout_customer_workflows', [
            'checkout_group_key' => $order->checkout_group_key,
            'confirmation_status' => 'confirmed',
            'robodesk_contact_id' => 'contact-test',
        ]);
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'status_type' => 'order',
            'status' => 'new_confirmed',
        ]);

        $this->signedJson('/api/integrations/robodesk/v1/events', $payload)
            ->assertOk()
            ->assertJson(['accepted' => true, 'duplicate' => true]);

        $this->assertSame(1, RoboDeskIntegrationEvent::query()->where('event_id', $eventId)->count());
        $this->assertSame(1, $order->statusLogs()->where('status', 'new_confirmed')->count());
    }

    public function test_payment_proof_is_private_and_never_marks_the_checkout_paid_automatically(): void
    {
        Storage::fake('local');
        $order = $this->order('CHK-ROBODESK-PROOF');

        $proof = app(PaymentProofService::class)->store(
            $order->checkout_group_key,
            UploadedFile::fake()->image('instapay-proof.jpg'),
            ['message_id' => 'message-proof-1', 'conversation_id' => 'conversation-proof-1'],
        );

        $this->assertSame('pending', $proof->status);
        Storage::disk('local')->assertExists($proof->file_path);
        $this->assertStringStartsWith('robodesk/payment-proofs/', $proof->file_path);
        $this->assertSame('unpaid', $order->refresh()->payment_status);

        $duplicate = app(PaymentProofService::class)->store(
            $order->checkout_group_key,
            UploadedFile::fake()->image('duplicate.jpg'),
            ['message_id' => 'message-proof-1'],
        );
        $this->assertTrue($proof->is($duplicate));
        $this->assertDatabaseCount('order_payment_proofs', 1);
    }

    public function test_approved_preview_queues_payment_request_but_does_not_mark_payment_as_paid(): void
    {
        config()->set('robodesk.confirmation_only', false);
        config()->set('robodesk.enabled', true);
        config()->set('robodesk.inbound_secret', 'inbound-test-secret');
        config()->set('robodesk.outbound_secret', '');

        $story = Story::query()->create([
            'title' => 'قصة المعاينة',
            'slug' => 'robodesk-preview-story',
            'language' => 'ar',
            'gender' => 'both',
            'price' => 349,
            'active' => true,
        ]);
        $order = $this->order('CHK-ROBODESK-PREVIEW');
        $order->forceFill(['story_id' => $story->id])->saveQuietly();

        $payload = [
            'id' => (string) Str::uuid(),
            'type' => 'preview.approved',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'order_number' => $order->order_number,
                'version_reference' => 'booklet-preview:1:v1',
                'message_id' => 'preview-approved-message',
            ],
        ];

        $this->signedJson('/api/integrations/robodesk/v1/events', $payload)->assertAccepted();

        $this->assertDatabaseHas('order_customer_reviews', [
            'order_id' => $order->id,
            'review_type' => 'preview',
            'decision' => 'approved',
        ]);
        $this->assertDatabaseHas('checkout_customer_workflows', [
            'checkout_group_key' => $order->checkout_group_key,
            'payment_request_status' => 'pending',
        ]);
        $this->assertDatabaseHas('robodesk_integration_events', [
            'direction' => 'outbound',
            'event_type' => 'payment.requested',
            'checkout_group_key' => $order->checkout_group_key,
            'status' => 'held',
        ]);
        $this->assertSame('unpaid', $order->refresh()->payment_status);
    }

    public function test_historical_held_confirmation_cannot_be_released_after_activation(): void
    {
        config(['robodesk.enabled' => false]);
        $order = $this->order('CHK-OLD');
        $event = RoboDeskIntegrationEvent::where('checkout_group_key', $order->checkout_group_key)->firstOrFail();
        config(['robodesk.enabled' => true, 'robodesk.outbound_secret' => 'test', 'robodesk.inbound_secret' => 'test',
            'robodesk.confirmation.enabled' => true, 'robodesk.confirmation.language' => 'ar-EG', 'robodesk.confirmation.contract_ready' => true, 'robodesk.confirmation.after_order_id' => 0]);
        $this->expectException(HttpException::class);
        app(RoboDeskOutbox::class)->release($event);
    }

    private function order(string $checkout): Order
    {
        return Order::query()->create([
            'order_number' => 'HK-'.Str::upper(Str::random(10)),
            'checkout_group_key' => $checkout,
            'parent_name' => 'ولي الأمر',
            'delivery_details' => [
                'checkout_group' => $checkout,
                'phone' => '01501188884',
                'delivery_fee' => 50,
            ],
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
    }

    private function signedJson(string $uri, array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = app(RoboDeskSignature::class)->sign(
            $body,
            $timestamp,
            $payload['id'],
            (string) config('robodesk.inbound_secret'),
        );

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ROBODESK_TIMESTAMP' => $timestamp,
            'HTTP_X_ROBODESK_EVENT_ID' => $payload['id'],
            'HTTP_X_ROBODESK_SIGNATURE' => $signature,
        ], $body);
    }
}
