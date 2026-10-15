<?php

namespace App\Jobs;

use App\Models\DeviceInstallation;
use App\Models\MobileNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;

class SendMobilePushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(public readonly int $notificationId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('mobile-push-'.$this->notificationId))->releaseAfter(30)->expireAfter(130)];
    }

    public function handle(): void
    {
        $notification = MobileNotification::query()->find($this->notificationId);
        if (! $notification || ! $notification->user?->is_active) {
            return;
        }

        $devices = DeviceInstallation::query()
            ->where('user_id', $notification->user_id)
            ->whereNull('revoked_at')
            ->whereNotNull('push_token_hash')
            ->when($notification->category === 'marketing', fn ($query) => $query->where('marketing_notifications', true))
            ->when($notification->category !== 'marketing', fn ($query) => $query->where('operational_notifications', true))
            ->get();

        foreach ($devices as $device) {
            $delivery = $notification->deliveries()->firstOrCreate(
                ['device_installation_id' => $device->id],
                ['status' => 'pending'],
            );
            if ($delivery->status === 'sent') {
                continue;
            }

            try {
                $client = Http::asJson()->timeout(15);
                if (filled(config('services.expo_push.access_token'))) {
                    $client->withToken(config('services.expo_push.access_token'));
                }
                $response = $client->post('https://exp.host/--/api/v2/push/send', [
                    'to' => $device->push_token,
                    'title' => $notification->title,
                    'body' => $notification->body,
                    'sound' => 'default',
                    // The owner-scoped read API expects this UUID, never a row ID
                    // or an identifier supplied by a campaign/custom payload.
                    'data' => [...($notification->data ?? []), 'notification_id' => $notification->uuid],
                ]);
            } catch (ConnectionException $exception) {
                $delivery->forceFill(['status' => 'failed', 'error_code' => 'TransportError', 'safe_error_message' => 'Push service connection failed.', 'attempts' => $delivery->attempts + 1])->save();
                throw new \RuntimeException('Push service connection failed.');
            }
            $ticket = $response->json('data');
            $ok = $response->successful() && data_get($ticket, 'status') === 'ok';
            $error = data_get($ticket, 'details.error') ?: $response->json('errors.0.code');
            $safeError = is_string($error) && preg_match('/^[a-zA-Z_]{1,60}$/', $error) ? $error : null;
            $delivery->forceFill([
                'status' => $ok ? 'sent' : 'failed',
                'provider_ticket_id' => data_get($ticket, 'id'),
                'error_code' => $safeError,
                'safe_error_message' => $ok ? null : 'Push provider rejected the notification.',
                'attempts' => $delivery->attempts + 1,
                'sent_at' => $ok ? now() : null,
                'receipt_status' => $ok ? 'pending' : null,
                'sent_token_hash' => $device->push_token_hash,
            ])->save();

            if (data_get($ticket, 'details.error') === 'DeviceNotRegistered') {
                $device->forceFill(['revoked_at' => now(), 'push_token' => null, 'push_token_hash' => null])->save();
            }
            if ($response->serverError() || $response->status() === 429) {
                throw new \RuntimeException('Push service temporarily unavailable.');
            }
        }
    }
}
