<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use App\Models\NotificationCredential;
use App\Models\NotificationRule;
use App\Models\User;
use Illuminate\Support\Str;

class NotificationCredentialService
{
    public function channel(string $type = 'telegram'): NotificationChannel
    {
        $definition = config("admin_notifications.channels.{$type}", [
            'type' => $type,
            'display_name' => Str::headline($type),
        ]);

        return NotificationChannel::query()->firstOrCreate(
            ['type' => $type],
            [
                'display_name' => $definition['display_name'] ?? Str::headline($type),
                'is_active' => false,
                'settings_json' => [
                    'default_chat_id' => config('admin_notifications.telegram.legacy_default_chat_id'),
                    'additional_chat_ids' => [],
                    'last_test_status' => null,
                    'last_test_message' => null,
                    'last_test_at' => null,
                ],
            ]
        );
    }

    public function saveToken(NotificationChannel $channel, string $token, ?User $user = null): NotificationCredential
    {
        $token = trim($token);

        return NotificationCredential::query()->updateOrCreate(
            ['notification_channel_id' => $channel->id, 'credential_type' => $this->credentialType($channel)],
            [
                'encrypted_value' => $token,
                'last_four' => Str::of($token)->substr(-4)->toString(),
                'configured_at' => now(),
                'configured_by_user_id' => $user?->id,
            ]
        );
    }

    public function removeToken(NotificationChannel $channel): void
    {
        $channel->credentials()->where('credential_type', $this->credentialType($channel))->delete();
        $settings = $channel->settings_json ?? [];
        $settings['last_test_status'] = null;
        $settings['last_test_message'] = null;

        $channel->forceFill([
            'is_active' => false,
            'settings_json' => $settings,
        ])->save();
    }

    public function token(?NotificationChannel $channel = null): ?string
    {
        $channel ??= $this->channel('telegram');
        $credential = $channel->credentials()
            ->where('credential_type', $this->credentialType($channel))
            ->first();

        if ($credential) {
            return $credential->encrypted_value;
        }

        return filled(config('admin_notifications.telegram.legacy_token'))
            ? (string) config('admin_notifications.telegram.legacy_token')
            : null;
    }

    public function hasToken(?NotificationChannel $channel = null): bool
    {
        return filled($this->token($channel));
    }

    public function masked(?NotificationChannel $channel = null): ?string
    {
        $channel ??= $this->channel('telegram');
        $credential = $channel->credentials()
            ->where('credential_type', $this->credentialType($channel))
            ->first();

        if ($credential?->last_four) {
            return '••••••••'.$credential->last_four;
        }

        $legacyToken = (string) config('admin_notifications.telegram.legacy_token');

        return filled($legacyToken) ? 'env:••••••••'.Str::of($legacyToken)->substr(-4)->toString() : null;
    }

    public function recipients(NotificationRule $rule, NotificationChannel $channel): array
    {
        $settings = $channel->settings_json ?? [];
        $recipients = [];

        if (filled($settings['default_chat_id'] ?? null)) {
            $recipients[] = (string) $settings['default_chat_id'];
        }

        foreach (($settings['additional_chat_ids'] ?? []) as $chatId) {
            if (filled($chatId)) {
                $recipients[] = (string) $chatId;
            }
        }

        foreach (($rule->recipients_json ?? []) as $chatId) {
            if (filled($chatId)) {
                $recipients[] = (string) $chatId;
            }
        }

        return collect($recipients)
            ->map(fn (string $recipient): string => trim($recipient))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function credentialType(NotificationChannel $channel): string
    {
        return config("admin_notifications.channels.{$channel->type}.credential_type", 'bot_token');
    }
}
