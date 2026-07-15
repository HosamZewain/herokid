<?php

namespace App\Jobs;

use App\DTOs\Notifications\NotificationMessage;
use App\Models\NotificationDelivery;
use App\Services\Notifications\TelegramNotificationChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $notificationDeliveryId) {}

    public function handle(TelegramNotificationChannel $telegram): void
    {
        $delivery = NotificationDelivery::query()->find($this->notificationDeliveryId);

        if (! $delivery || $delivery->status === 'sent') {
            return;
        }

        $payload = $delivery->payload_json ?? [];
        $delivery->forceFill([
            'status' => 'sending',
            'attempts' => ((int) $delivery->attempts) + 1,
            'error_message' => null,
        ])->save();

        $message = new NotificationMessage(
            eventKey: $delivery->event_key,
            channelType: $delivery->channel_type,
            recipient: $delivery->recipient,
            body: (string) ($payload['body'] ?? ''),
            severity: (string) ($payload['severity'] ?? 'info'),
            subject: $payload['subject'] ?? null,
            notifiableType: $delivery->notifiable_type,
            notifiableId: $delivery->notifiable_id,
            payload: $payload,
            parseMode: $payload['parse_mode'] ?? null,
        );

        $result = match ($delivery->channel_type) {
            'telegram' => $telegram->send($message),
            default => null,
        };

        if (! $result) {
            $delivery->forceFill([
                'status' => 'failed',
                'error_message' => 'Unsupported notification channel.',
            ])->save();

            return;
        }

        if ($result->successful) {
            $delivery->forceFill([
                'status' => 'sent',
                'response_json' => $result->response,
                'error_message' => null,
                'sent_at' => now(),
            ])->save();

            return;
        }

        $delivery->forceFill([
            'status' => 'failed',
            'response_json' => $result->response,
            'error_message' => $result->errorMessage,
        ])->save();

        if (isset($this->job) && $this->job && $delivery->attempts < $this->tries) {
            $this->release($this->backoff[min($delivery->attempts - 1, count($this->backoff) - 1)] ?? 300);
        }
    }
}
