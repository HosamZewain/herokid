<?php

namespace App\Services\Notifications;

use App\Models\Setting;

class NotificationSettings
{
    public function ensureDefaults(): void
    {
        foreach (config('admin_notifications.settings', []) as $key => $value) {
            Setting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return setting($key, config("admin_notifications.settings.{$key}", $default));
    }

    public function int(string $key, int $default): int
    {
        return max(0, (int) $this->get($key, $default));
    }

    public function float(string $key, float $default): float
    {
        return max(0.0, (float) $this->get($key, $default));
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->get($key, $default ? '1' : '0');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function save(array $settings): void
    {
        foreach ($settings as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        }
    }
}
