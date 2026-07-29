<?php

namespace App\Jobs;

use App\Models\MetaConversionEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SendMetaConversionEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $metaConversionEventId) {}

    public function handle(): void
    {
        $event = MetaConversionEvent::query()->find($this->metaConversionEventId);

        if (! $event || $event->status === 'sent') {
            return;
        }

        if (! config('services.meta_pixel.conversions_api_enabled', true)) {
            $event->forceFill([
                'status' => 'disabled',
                'safe_error_message' => 'Meta Conversions API is disabled.',
                'last_attempted_at' => now(),
            ])->save();

            return;
        }

        $pixelId = trim((string) config('services.meta_pixel.id', ''));
        $accessToken = trim((string) config('services.meta_pixel.access_token', ''));

        if ($pixelId === '' || $accessToken === '') {
            $event->forceFill([
                'status' => 'configuration_missing',
                'safe_error_message' => 'Meta Pixel ID or Conversions API access token is not configured.',
                'last_attempted_at' => now(),
            ])->save();

            return;
        }

        $event->forceFill([
            'status' => 'sending',
            'attempts' => ((int) $event->attempts) + 1,
            'safe_error_message' => null,
            'last_attempted_at' => now(),
        ])->save();

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->withToken($accessToken)
                ->timeout(15)
                ->retry(2, 250, throw: false)
                ->post($this->endpoint($pixelId), $this->payload($event));

            $this->handleResponse($event, $response);
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => 'failed',
                'safe_error_message' => 'Meta Conversions API request failed before a valid response.',
            ])->save();

            Log::warning('Meta Conversions API request failed.', [
                'meta_conversion_event_id' => $event->id,
                'attempts' => $event->attempts,
                'error_type' => $exception::class,
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        MetaConversionEvent::query()
            ->whereKey($this->metaConversionEventId)
            ->where('status', '!=', 'sent')
            ->update([
                'status' => 'failed',
                'safe_error_message' => 'Meta Conversions API delivery exhausted its retry attempts.',
                'updated_at' => now(),
            ]);
    }

    private function handleResponse(MetaConversionEvent $event, Response $response): void
    {
        $received = (int) $response->json('events_received', 0);
        $requestId = $response->header('x-fb-trace-id') ?: $response->json('fbtrace_id');

        if ($response->successful() && $received > 0) {
            $event->forceFill([
                'status' => 'sent',
                'provider_request_id' => $requestId ? Str::limit((string) $requestId, 255, '') : null,
                'response_status' => $response->status(),
                'safe_error_message' => null,
                'sent_at' => now(),
            ])->save();

            return;
        }

        $event->forceFill([
            'status' => 'failed',
            'provider_request_id' => $requestId ? Str::limit((string) $requestId, 255, '') : null,
            'response_status' => $response->status(),
            'safe_error_message' => 'Meta rejected the conversion event (HTTP '.$response->status().').',
        ])->save();

        throw new RuntimeException('Meta Conversions API rejected the event.');
    }

    private function endpoint(string $pixelId): string
    {
        $version = trim((string) config('services.meta_pixel.api_version', 'v23.0'));
        if (preg_match('/^v\d+\.\d+$/', $version) !== 1) {
            $version = 'v23.0';
        }

        return 'https://graph.facebook.com/'.$version.'/'.$pixelId.'/events';
    }

    private function payload(MetaConversionEvent $event): array
    {
        $payload = [
            'data' => [[
                'event_name' => $event->event_name,
                'event_time' => $event->event_time,
                'event_id' => $event->event_id,
                'event_source_url' => $event->event_source_url,
                'action_source' => 'website',
                'user_data' => $event->user_data_encrypted,
                'custom_data' => $event->custom_data_json,
            ]],
        ];
        $testEventCode = trim((string) config('services.meta_pixel.test_event_code', ''));

        if ($testEventCode !== '') {
            $payload['test_event_code'] = $testEventCode;
        }

        return $payload;
    }
}
