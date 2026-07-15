<?php

namespace App\Services\Notifications;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\NotificationEventLog;
use App\Models\NotificationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminNotificationDispatcher
{
    public function __construct(
        private readonly NotificationCredentialService $credentials,
        private readonly NotificationMessageBuilder $messages,
        private readonly NotificationSettings $settings,
    ) {}

    public function dispatchSafely(string $eventKey, ?Model $notifiable = null, array $context = [], ?string $severity = null): void
    {
        try {
            $this->dispatch($eventKey, $notifiable, $context, $severity);
        } catch (Throwable $exception) {
            Log::warning('Admin notification dispatch failed.', [
                'event_key' => $eventKey,
                'notifiable_type' => $notifiable ? $notifiable::class : null,
                'notifiable_id' => $notifiable?->getKey(),
                'error' => $this->safeError($exception->getMessage()),
            ]);
        }
    }

    public function dispatch(string $eventKey, ?Model $notifiable = null, array $context = [], ?string $severity = null): void
    {
        $this->settings->ensureDefaults();

        $rules = NotificationRule::query()
            ->where('event_key', $eventKey)
            ->where('is_enabled', true)
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        foreach ($rules as $rule) {
            $channel = NotificationChannel::query()
                ->where('type', $rule->channel_type)
                ->first();

            if (! $channel?->is_active) {
                continue;
            }

            $recipients = $this->credentials->recipients($rule, $channel);

            if ($recipients === []) {
                continue;
            }

            foreach ($recipients as $recipient) {
                $dedupeKey = $this->dedupeKey($eventKey, $notifiable, $context);

                if ($this->shouldSkip($eventKey, $channel->type, $recipient, $notifiable, $dedupeKey, $context)) {
                    continue;
                }

                $message = $this->messages->build($eventKey, $channel->type, $recipient, $notifiable, $context, $severity ?? $rule->severity);
                $payload = [
                    'subject' => $message->subject,
                    'body' => $message->body,
                    'severity' => $message->severity,
                    'parse_mode' => $message->parseMode,
                    'dedupe_key' => $dedupeKey,
                ];

                NotificationEventLog::query()->create([
                    'event_key' => $eventKey,
                    'severity' => $message->severity,
                    'dedupe_key' => $dedupeKey,
                    'notifiable_type' => $notifiable ? $notifiable::class : null,
                    'notifiable_id' => $notifiable?->getKey(),
                    'context_json' => $this->safeContext($context),
                ]);

                $delivery = NotificationDelivery::query()->create([
                    'event_key' => $eventKey,
                    'channel_type' => $channel->type,
                    'dedupe_key' => $dedupeKey,
                    'notifiable_type' => $notifiable ? $notifiable::class : null,
                    'notifiable_id' => $notifiable?->getKey(),
                    'recipient' => $recipient,
                    'status' => 'pending',
                    'payload_json' => $payload,
                    'attempts' => 0,
                ]);

                SendNotificationJob::dispatch($delivery->id)->afterCommit();
            }
        }
    }

    private function shouldSkip(string $eventKey, string $channelType, string $recipient, ?Model $notifiable, string $dedupeKey, array $context): bool
    {
        $query = NotificationDelivery::query()
            ->where('event_key', $eventKey)
            ->where('channel_type', $channelType)
            ->where('recipient', $recipient)
            ->where('dedupe_key', $dedupeKey)
            ->where('notifiable_type', $notifiable ? $notifiable::class : null)
            ->where('notifiable_id', $notifiable?->getKey());

        $repeatAfter = (int) ($context['allow_repeat_after_minutes'] ?? 0);

        if ($repeatAfter > 0) {
            return $query->where('created_at', '>=', now()->subMinutes($repeatAfter))->exists();
        }

        return $query->exists();
    }

    private function dedupeKey(string $eventKey, ?Model $notifiable, array $context): string
    {
        if (filled($context['dedupe_key'] ?? null)) {
            return (string) $context['dedupe_key'];
        }

        $status = $context['status'] ?? ($notifiable->status ?? null);
        $parts = array_filter([
            $eventKey,
            $notifiable ? str_replace('\\', '.', $notifiable::class) : null,
            $notifiable?->getKey(),
            $status,
        ], fn ($part): bool => $part !== null && $part !== '');

        return implode(':', $parts);
    }

    private function safeContext(array $context): array
    {
        unset($context['bot_token'], $context['api_key'], $context['token'], $context['secret']);

        return $context;
    }

    private function safeError(string $message): string
    {
        $message = preg_replace('/bot[0-9]+:[A-Za-z0-9_\-]+/', 'bot[redacted]', $message) ?: '';
        $message = preg_replace('/\b[0-9]{5,}:[A-Za-z0-9_\-]+\b/', '[redacted-token]', $message) ?: '';
        $message = preg_replace('/Bearer\s+[A-Za-z0-9_\-:.]+/', 'Bearer [redacted]', $message) ?: '';

        return preg_replace('/[A-Za-z0-9_\-:.]*(secret|token|api_key|apikey)[A-Za-z0-9_\-:.]*/i', '[redacted]', $message) ?: 'notification dispatch failed';
    }
}
