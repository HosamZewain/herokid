<?php

namespace App\Jobs;

use App\Models\RoboDeskIntegrationEvent;
use App\Services\RoboDesk\RoboDeskIntegrationRegistry;
use App\Services\RoboDesk\RoboDeskSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

class SendRoboDeskEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $eventId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(RoboDeskSettings $settings, RoboDeskIntegrationRegistry $integrations): void
    {
        $event = RoboDeskIntegrationEvent::query()->find($this->eventId);

        if (! $event || $event->direction !== 'outbound' || $event->status === 'succeeded') {
            return;
        }

        $integration = $integrations->find((string) $event->event_type);

        if (! $settings->enabled() || ! $integration?->enabled()) {
            $event->update(['status' => 'held', 'last_error' => 'RoboDesk integration is disabled.']);

            return;
        }

        if (! $integration->configured()) {
            $event->update(['status' => 'held', 'last_error' => 'No API URL is configured for this integration.']);

            return;
        }

        $body = $event->payload ?? [];

        $event->increment('attempts');
        $event->update(['status' => 'processing']);

        // Simulation mode stops here. The body recorded is byte-for-byte what a
        // live send would have used, so the simulator shows the real message
        // rather than a mock-up of one.
        if ($settings->simulating()) {
            $event->update([
                'status' => 'succeeded',
                'processed_at' => now(),
                'last_error' => null,
                'response_payload' => [
                    'simulated' => true,
                    'would_have_sent' => [
                        'method' => 'POST',
                        'url' => $integration->apiUrl(),
                        'body' => $body,
                    ],
                ],
            ]);

            return;
        }

        $headers = ['Content-Type' => 'application/json'];

        if ($integration->token() !== '') {
            $headers['Authorization'] = $integration->token();
        }

        try {
            $response = Http::timeout($settings->timeoutSeconds())
                ->acceptJson()
                ->withHeaders($headers)
                ->post($integration->apiUrl(), $body);

            $response->throw();

            $event->update([
                'status' => 'succeeded',
                'processed_at' => now(),
                'last_error' => null,
                'response_payload' => $response->json() ?: ['status' => $response->status()],
            ]);
        } catch (Throwable $exception) {
            $event->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 2000)]);

            throw $exception;
        }
    }
}
