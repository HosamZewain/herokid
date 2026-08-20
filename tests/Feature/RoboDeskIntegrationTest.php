<?php

namespace Tests\Feature;

use App\Jobs\SendRoboDeskEventJob;
use App\Models\Order;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\Story;
use App\Services\RoboDesk\PaymentProofService;
use App\Services\RoboDesk\RoboDeskCheckoutPayload;
use App\Services\RoboDesk\RoboDeskOutbox;
use App\Services\RoboDesk\RoboDeskSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        $order = $this->order('CHK-ROBODESK-CONFIRM');
        $eventId = (string) Str::uuid();
        $payload = [
            'id' => $eventId,
            'type' => 'order.confirmed',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'checkout_reference' => $order->checkout_group_key,
                'contact_id' => 'contact-test',
                'conversation_id' => 'conversation-test',
                'comment' => 'تم التأكيد',
            ],
        ];

        $first = $this->signedJson('/api/integrations/robodesk/v1/events', $payload);
        $first->assertAccepted()->assertJson(['accepted' => true]);

        $this->assertSame('under_review', $order->refresh()->status);
        $this->assertDatabaseHas('checkout_customer_workflows', [
            'checkout_group_key' => $order->checkout_group_key,
            'confirmation_status' => 'confirmed',
            'robodesk_contact_id' => 'contact-test',
        ]);
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'status_type' => 'order',
            'status' => 'under_review',
        ]);

        $this->signedJson('/api/integrations/robodesk/v1/events', $payload)
            ->assertOk()
            ->assertJson(['accepted' => true, 'duplicate' => true]);

        $this->assertSame(1, RoboDeskIntegrationEvent::query()->where('event_id', $eventId)->count());
        $this->assertSame(1, $order->statusLogs()->where('status', 'under_review')->count());
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

    public function test_held_outbound_event_can_be_released_with_the_exact_signed_json_body(): void
    {
        config()->set('robodesk.enabled', false);
        config()->set('robodesk.outbound_secret', 'outbound-test-secret');
        config()->set('robodesk.base_url', 'https://herokid.robodesk.ai');
        Http::fake(['https://herokid.robodesk.ai/*' => Http::response(['accepted' => true], 202)]);

        $order = $this->order('CHK-ROBODESK-OUTBOUND');
        $event = RoboDeskIntegrationEvent::query()
            ->where('event_type', 'order.pending_confirmation')
            ->where('checkout_group_key', $order->checkout_group_key)
            ->firstOrFail();
        $this->assertSame('held', $event->status);

        config()->set('robodesk.enabled', true);
        app(RoboDeskOutbox::class)->release($event);
        (new SendRoboDeskEventJob($event->id))->handle(
            app(RoboDeskSignature::class),
            app(RoboDeskCheckoutPayload::class),
        );

        $this->assertSame('succeeded', $event->refresh()->status);
        Http::assertSent(function ($request) use ($event): bool {
            $timestamp = $request->header('X-RoboDesk-Timestamp')[0] ?? '';
            $eventId = $request->header('X-RoboDesk-Event-Id')[0] ?? '';
            $signature = $request->header('X-RoboDesk-Signature')[0] ?? '';

            return $request->url() === 'https://herokid.robodesk.ai/api/integrations/herokid/v1/events'
                && $eventId === $event->event_id
                && app(RoboDeskSignature::class)->valid(
                    $request->body(),
                    $timestamp,
                    $eventId,
                    $signature,
                    'outbound-test-secret',
                );
        });
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
