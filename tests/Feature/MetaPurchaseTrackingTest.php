<?php

namespace Tests\Feature;

use App\Jobs\SendMetaConversionEventJob;
use App\Models\MetaConversionEvent;
use App\Models\Order;
use App\Services\Analytics\MetaPurchaseTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaPurchaseTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_is_persisted_once_per_checkout_and_browser_and_server_share_event_id(): void
    {
        Queue::fake();
        $order = $this->order();
        $request = Request::create(
            '/checkout',
            'POST',
            cookies: ['_fbp' => 'fb.1.1234567890.abc'],
            server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'HeroKid Test Browser'],
        );
        $tracking = app(MetaPurchaseTrackingService::class);

        $browserEvent = $tracking->record($request, [$order->id], 'CHK-TRACK-001');
        $tracking->record($request, [$order->id], 'CHK-TRACK-001');

        $event = MetaConversionEvent::query()->sole();
        $this->assertSame($event->event_id, $browserEvent['event_id']);
        $this->assertSame('EGP', $browserEvent['currency']);
        $this->assertSame(349.0, $browserEvent['value']);
        $this->assertSame('EGP', $event->custom_data_json['currency']);
        $this->assertSame(349, $event->custom_data_json['value']);
        $this->assertSame(1, $event->custom_data_json['num_items']);
        $this->assertSame(hash('sha256', '201000000000'), $event->user_data_encrypted['ph'][0]);
        $this->assertSame('fb.1.1234567890.abc', $event->user_data_encrypted['fbp']);
        $this->assertStringNotContainsString('201000000000', (string) $event->getRawOriginal('user_data_encrypted'));
        Queue::assertPushed(SendMetaConversionEventJob::class, 1);
    }

    public function test_server_job_sends_purchase_with_egp_and_browser_deduplication_id(): void
    {
        config([
            'services.meta_pixel.id' => '1011553001490691',
            'services.meta_pixel.conversions_api_enabled' => true,
            'services.meta_pixel.access_token' => 'secret-test-token',
            'services.meta_pixel.api_version' => 'v23.0',
            'services.meta_pixel.test_event_code' => 'TEST123',
        ]);
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'events_received' => 1,
                'fbtrace_id' => 'trace-123',
            ], 200),
        ]);
        $event = $this->event();

        (new SendMetaConversionEventJob($event->id))->handle();

        $event->refresh();
        $this->assertSame('sent', $event->status);
        $this->assertSame('trace-123', $event->provider_request_id);
        $this->assertNotNull($event->sent_at);

        Http::assertSent(function ($request) use ($event): bool {
            $payload = $request->data();
            $serverEvent = $payload['data'][0];

            return $request->url() === 'https://graph.facebook.com/v23.0/1011553001490691/events'
                && $request->hasHeader('Authorization', 'Bearer secret-test-token')
                && $serverEvent['event_name'] === 'Purchase'
                && $serverEvent['event_id'] === $event->event_id
                && $serverEvent['action_source'] === 'website'
                && $serverEvent['custom_data']['currency'] === 'EGP'
                && (float) $serverEvent['custom_data']['value'] === 349.0
                && $payload['test_event_code'] === 'TEST123';
        });
    }

    public function test_missing_server_token_never_blocks_or_calls_meta(): void
    {
        config([
            'services.meta_pixel.id' => '1011553001490691',
            'services.meta_pixel.conversions_api_enabled' => true,
            'services.meta_pixel.access_token' => null,
        ]);
        Http::fake();
        $event = $this->event();

        (new SendMetaConversionEventJob($event->id))->handle();

        $this->assertSame('configuration_missing', $event->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_multi_order_checkout_counts_delivery_once_and_uses_saved_checkout_total(): void
    {
        Queue::fake();
        $first = $this->order();
        $second = Order::create([
            'order_number' => 'HK-META-002',
            'checkout_group_key' => 'CHK-TRACK-001',
            'parent_name' => 'Parent',
            'delivery_details' => [
                'phone' => '201000000000',
                'subtotal' => 598,
                'delivery_fee' => 50,
                'total' => 648,
            ],
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
        $second->items()->create([
            'item_type' => 'story',
            'title' => 'قصة ثانية',
            'unit_price_cents' => 29900,
            'quantity' => 1,
            'total_price_cents' => 29900,
        ]);
        $first->update(['delivery_details' => $second->delivery_details]);
        $request = Request::create('/checkout', 'POST');

        $browser = app(MetaPurchaseTrackingService::class)->record(
            $request,
            [$first->id, $second->id],
            'CHK-TRACK-001',
        );

        $this->assertSame(648.0, $browser['value']);
        $this->assertSame(2, $browser['num_items']);
        $event = MetaConversionEvent::query()->sole();
        $this->assertSame(648, $event->custom_data_json['value']);
        $this->assertCount(2, $event->custom_data_json['contents']);
    }

    public function test_sent_event_is_idempotent_and_is_not_submitted_twice(): void
    {
        config([
            'services.meta_pixel.id' => '1011553001490691',
            'services.meta_pixel.access_token' => 'secret-test-token',
        ]);
        Http::fake();
        $event = $this->event(['status' => 'sent', 'sent_at' => now()]);

        (new SendMetaConversionEventJob($event->id))->handle();

        Http::assertNothingSent();
    }

    private function order(): Order
    {
        $order = Order::create([
            'order_number' => 'HK-META-001',
            'checkout_group_key' => 'CHK-TRACK-001',
            'parent_name' => 'Parent',
            'delivery_details' => [
                'phone' => '201000000000',
                'subtotal' => 299,
                'delivery_fee' => 50,
                'total' => 349,
            ],
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
        $order->items()->create([
            'item_type' => 'story',
            'title' => 'قصة مخصصة',
            'unit_price_cents' => 29900,
            'quantity' => 1,
            'total_price_cents' => 29900,
        ]);

        return $order;
    }

    private function event(array $overrides = []): MetaConversionEvent
    {
        $order = $this->order();

        return MetaConversionEvent::create(array_merge([
            'event_id' => 'purchase-chk-track-001',
            'event_name' => 'Purchase',
            'checkout_group_key' => 'CHK-TRACK-001',
            'representative_order_id' => $order->id,
            'status' => 'pending',
            'attempts' => 0,
            'event_time' => now()->timestamp,
            'event_source_url' => route('checkout.success'),
            'user_data_encrypted' => [
                'ph' => [hash('sha256', '201000000000')],
                'client_ip_address' => '127.0.0.1',
                'client_user_agent' => 'HeroKid Test Browser',
            ],
            'custom_data_json' => [
                'currency' => 'EGP',
                'value' => 349.0,
                'order_id' => 'CHK-TRACK-001',
                'content_type' => 'product',
                'content_ids' => ['story-1'],
                'contents' => [['id' => 'story-1', 'quantity' => 1, 'item_price' => 299.0]],
                'num_items' => 1,
            ],
        ], $overrides));
    }
}
