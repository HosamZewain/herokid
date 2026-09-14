<?php

namespace App\Services\RoboDesk;

use App\Models\Setting;

/**
 * General RoboDesk settings — the handful of switches that are not per
 * integration.
 *
 * Resolution order for every key:
 *   1. a `settings` row saved from the admin panel
 *   2. the legacy config/env key it maps to, when one exists
 *   3. the default declared in config/robodesk.php
 *
 * Nothing is seeded on boot, so an env-driven deployment keeps behaving exactly
 * as it does today until someone deliberately saves a value.
 */
class RoboDeskSettings
{
    public function get(string $key, mixed $default = null): mixed
    {
        $stored = setting($key);

        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        $fallbackKey = config("robodesk.setting_fallbacks.{$key}");

        if ($fallbackKey !== null) {
            $legacy = config($fallbackKey);

            if ($legacy !== null && $legacy !== '') {
                return $legacy;
            }
        }

        return config("robodesk.settings.{$key}", $default);
    }

    public function string(string $key, string $default = ''): string
    {
        return trim((string) $this->get($key, $default));
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);
    }

    public function save(array $settings): void
    {
        foreach ($settings as $key => $value) {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value],
            );
        }
    }

    public function enabled(): bool
    {
        return $this->bool('robodesk_enabled', (bool) config('robodesk.enabled', false));
    }

    public function timeoutSeconds(): int
    {
        return max(5, $this->int('robodesk_timeout_seconds', 15));
    }

    public function inboundAuthHeader(): string
    {
        return $this->string('robodesk_inbound_auth_header', 'X-RoboDesk-Token') ?: 'X-RoboDesk-Token';
    }

    /**
     * Simulation mode records every outbound call without sending it, so the
     * journey can be walked before RoboDesk is reachable.
     */
    public function simulating(): bool
    {
        return $this->bool('robodesk_simulation_mode', false);
    }

    public function whatsAppNumber(): string
    {
        return $this->string('robodesk_whatsapp_number');
    }

    public function instaPayUrl(): string
    {
        return $this->string('robodesk_instapay_url');
    }

    public function paymentProofMaxMb(): int
    {
        return max(1, $this->int('robodesk_payment_proof_max_mb', 10));
    }

    /** Journey switch: new checkouts wait at `pending_confirmation`. */
    public function gatesOrderConfirmation(): bool
    {
        return $this->enabled() && $this->bool('robodesk_gate_order_confirmation', false);
    }

    /** Journey switch: a generated identity waits for the parent to approve. */
    public function gatesIdentityConfirmation(): bool
    {
        return $this->enabled() && $this->bool('robodesk_gate_identity_confirmation', false);
    }
}
