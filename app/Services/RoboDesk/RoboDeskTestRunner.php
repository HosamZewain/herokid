<?php

namespace App\Services\RoboDesk;

use App\Models\RoboDeskIntegrationEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one integration's real payload to its real URL with sample values, so
 * the RoboDesk team can verify the contract end to end from the admin panel.
 *
 * Deliberately not the simulator: the simulator fakes RoboDesk so the journey
 * can be walked without them; this calls them for real and waits for their
 * callback. It creates no order and touches no business data — everything hangs
 * off a reserved `TEST-…` reference that the inbound handler short-circuits.
 */
class RoboDeskTestRunner
{
    public const PREFIX = 'TEST-';

    public function __construct(private readonly RoboDeskSettings $settings) {}

    public static function isTestReference(?string $reference): bool
    {
        return is_string($reference) && str_starts_with($reference, self::PREFIX);
    }

    /**
     * Every variable the integration offers, filled with something recognisable.
     * The reference variables all carry the same test reference so whichever one
     * RoboDesk echoes back is matched.
     */
    public function sampleVariables(RoboDeskIntegration $integration, string $reference): array
    {
        $samples = [
            'checkout_reference' => $reference,
            'identity_uuid' => $reference,
            'short_reference' => 'HK-TEST',
            'order_number' => 'HK-TEST-0001',
            'order_numbers' => ['HK-TEST-0001'],
            'customer_name' => 'عميل تجريبي',
            'customer_phone' => $this->settings->whatsAppNumber() ?: '201000000000',
            'child_name' => 'طفل تجريبي',
            'children' => ['طفل تجريبي'],
            'items_summary' => 'قصة تجريبية',
            'items_total' => 250.0,
            'delivery_fee' => 50.0,
            'discount' => 0.0,
            'total' => 300.0,
            'currency' => 'EGP',
            'delivery_address' => 'مصر - القاهرة - مدينة نصر - شارع تجريبي',
            'delivery_country' => 'مصر',
            'delivery_governorate' => 'القاهرة',
            'delivery_city' => 'مدينة نصر',
            'delivery_street' => 'شارع تجريبي',
            'customer_notes' => 'طلب تجريبي لاختبار التكامل',
            'order_status' => 'pending_confirmation',
            'payment_status' => 'unpaid',
            'identity_url' => url('/images/logo-96.png'),
            'attempt_id' => 0,
            'attempt_number' => 1,
            'attempts_remaining' => 2,
            'revisions_used' => 0,
            'max_revisions' => (int) config('robodesk.journey.identity_max_revisions', 3),
        ];

        // Anything the integration declares but this list misses still gets a
        // value, so a payload can never render an empty placeholder in a test.
        foreach (array_keys($integration->variables()) as $name) {
            $samples[$name] ??= 'TEST-'.$name;
        }

        return $samples;
    }

    /**
     * Sends immediately rather than queueing, so the admin sees the response in
     * the same request, and ignores simulation mode — a test that does not leave
     * the server proves nothing.
     */
    public function run(RoboDeskIntegration $integration): array
    {
        $reference = self::PREFIX.strtoupper(Str::random(8));
        $body = $integration->buildPayload($this->sampleVariables($integration, $reference));

        $event = RoboDeskIntegrationEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'deduplication_key' => 'test:'.$reference,
            'direction' => 'outbound',
            'event_type' => $integration->key,
            'aggregate_type' => 'test',
            'aggregate_id' => $reference,
            'status' => 'processing',
            'attempts' => 1,
            'payload' => $body,
            'available_at' => now(),
        ]);

        $headers = ['Content-Type' => 'application/json'];

        if ($integration->token() !== '') {
            $headers['Authorization'] = $integration->token();
        }

        try {
            $response = Http::timeout($this->settings->timeoutSeconds())
                ->acceptJson()
                ->withHeaders($headers)
                ->post($integration->apiUrl(), $body);

            $event->update([
                'status' => $response->successful() ? 'succeeded' : 'failed',
                'processed_at' => now(),
                'last_error' => $response->successful() ? null : 'HTTP '.$response->status(),
                'response_payload' => [
                    'test' => true,
                    'http_status' => $response->status(),
                    'body' => $response->json() ?? ['raw' => Str::limit($response->body(), 2000)],
                    'sent_to' => $integration->apiUrl(),
                ],
            ]);
        } catch (Throwable $exception) {
            $event->update([
                'status' => 'failed',
                'processed_at' => now(),
                'last_error' => Str::limit($exception->getMessage(), 2000),
                'response_payload' => ['test' => true, 'sent_to' => $integration->apiUrl()],
            ]);
        }

        return ['reference' => $reference, 'event' => $event->refresh()];
    }

    /** Everything recorded for one test run, both directions, oldest first. */
    public function events(string $reference): Collection
    {
        return RoboDeskIntegrationEvent::query()
            ->where('aggregate_id', $reference)
            ->orderBy('id')
            ->get();
    }

    public function status(string $reference): array
    {
        $events = $this->events($reference);
        $outbound = $events->firstWhere('direction', 'outbound');
        $inbound = $events->where('direction', 'inbound')->values();

        return [
            'reference' => $reference,
            'sent' => $outbound ? [
                'status' => $outbound->status,
                'url' => data_get($outbound->response_payload, 'sent_to'),
                'http_status' => data_get($outbound->response_payload, 'http_status'),
                'error' => $outbound->last_error,
                'body' => $outbound->payload,
                'response' => data_get($outbound->response_payload, 'body'),
                'at' => $outbound->created_at?->toIso8601String(),
            ] : null,
            'received' => $inbound->map(fn (RoboDeskIntegrationEvent $event): array => [
                'type' => $event->event_type,
                'status' => $event->status,
                'body' => $event->payload,
                'at' => $event->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
