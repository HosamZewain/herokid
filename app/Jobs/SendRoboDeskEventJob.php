<?php

namespace App\Jobs;

use App\Models\CheckoutCustomerWorkflow;
use App\Models\RoboDeskIntegrationEvent;
use App\Services\RoboDesk\RoboDeskActionRegistry;
use App\Services\RoboDesk\RoboDeskCheckoutPayload;
use App\Services\RoboDesk\RoboDeskCredentialService;
use App\Services\RoboDesk\RoboDeskSettings;
use App\Services\RoboDesk\RoboDeskSignature;
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

    public function handle(
        RoboDeskSignature $signatures,
        RoboDeskCheckoutPayload $checkouts,
        RoboDeskSettings $settings,
        RoboDeskCredentialService $credentials,
        RoboDeskActionRegistry $actions,
    ): void {
        $event = RoboDeskIntegrationEvent::query()->find($this->eventId);
        if (! $event || $event->direction !== 'outbound' || $event->status === 'succeeded') {
            return;
        }

        if (! $settings->enabled() || ! $credentials->has('outbound_secret')) {
            $event->update(['status' => 'held', 'last_error' => 'Integration is disabled or missing its outbound secret.']);

            return;
        }

        $data = $event->payload ?? [];
        if ($event->checkout_group_key) {
            $data = array_merge($checkouts->build($event->checkout_group_key), $data);
        }

        $envelope = [
            'id' => $event->event_id,
            'type' => $event->event_type,
            'occurred_at' => $event->created_at?->toIso8601String(),
            'data' => $data,
        ];
        $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;

        $event->increment('attempts');
        $event->update(['status' => 'processing', 'payload' => $data]);

        // The action that produced this event owns its endpoint and HTTP verb,
        // so each flow can target a different RoboDesk API without a deploy.
        $action = $actions->find((string) $event->event_type);
        $path = $action?->endpointPath() ?: $settings->eventsPath();
        $method = $action?->httpMethod() ?: 'POST';

        $headers = [
            'Content-Type' => 'application/json',
            'X-RoboDesk-Timestamp' => $timestamp,
            'X-RoboDesk-Event-Id' => $event->event_id,
        ];

        if ($settings->signsOutbound()) {
            $headers['X-RoboDesk-Signature'] = $signatures->sign(
                $body,
                $timestamp,
                $event->event_id,
                $credentials->value('outbound_secret'),
            );
        }

        if ($credentials->has('auth_token')) {
            $scheme = $settings->authScheme();
            $headers[$settings->authHeader()] = trim($scheme.' '.$credentials->value('auth_token'));
        }

        try {
            $response = Http::timeout($settings->timeoutSeconds())
                ->acceptJson()
                ->withHeaders($headers)
                ->send($method, $settings->baseUrl().$path, ['body' => $body]);

            $response->throw();
            $event->update([
                'status' => 'succeeded',
                'processed_at' => now(),
                'last_error' => null,
                'response_payload' => $response->json() ?: ['status' => $response->status()],
            ]);
            if ($event->event_type === 'payment.requested' && $event->checkout_group_key) {
                CheckoutCustomerWorkflow::query()
                    ->where('checkout_group_key', $event->checkout_group_key)
                    ->update([
                        'payment_request_status' => 'sent',
                        'payment_requested_at' => now(),
                    ]);
            }
        } catch (Throwable $exception) {
            $event->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        }
    }
}
