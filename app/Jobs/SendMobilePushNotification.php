<?php

namespace App\Jobs;

use App\Models\DeviceInstallation;
use App\Models\MobileNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class SendMobilePushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $notificationId) {}

    public function handle(): void
    {
        $notification = MobileNotification::query()->find($this->notificationId);
        if (! $notification) {
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

            $response = Http::asJson()->timeout(15)->post('https://exp.host/--/api/v2/push/send', [
                'to' => $device->push_token,
                'title' => $notification->title,
                'body' => $notification->body,
                'sound' => 'default',
                'data' => $notification->data ?? [],
            ]);
            $ticket = $response->json('data');
            $ok = $response->successful() && data_get($ticket, 'status') === 'ok';
            $delivery->forceFill([
                'status' => $ok ? 'sent' : 'failed',
                'provider_ticket_id' => data_get($ticket, 'id'),
                'error_code' => data_get($ticket, 'details.error'),
                'safe_error_message' => $ok ? null : (string) str((string) (data_get($ticket, 'message') ?: 'Push provider rejected the notification.'))->limit(500),
                'attempts' => $delivery->attempts + 1,
                'sent_at' => $ok ? now() : null,
            ])->save();

            if (data_get($ticket, 'details.error') === 'DeviceNotRegistered') {
                $device->forceFill(['revoked_at' => now(), 'push_token' => null, 'push_token_hash' => null])->save();
            }
        }
    }
}
