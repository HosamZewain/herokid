<?php

namespace App\Services\RoboDesk;

use App\Models\Setting;

/**
 * Connection-level configuration for the RoboDesk integration.
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
        $value = $this->get($key, $default ? '1' : '0');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
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

    public function baseUrl(): string
    {
        return rtrim($this->string('robodesk_base_url'), '/');
    }

    public function eventsPath(): string
    {
        $path = $this->string('robodesk_events_path');

        return $path === '' ? '' : '/'.ltrim($path, '/');
    }

    public function authHeader(): string
    {
        return $this->string('robodesk_auth_header', 'Authorization') ?: 'Authorization';
    }

    public function authScheme(): string
    {
        return $this->string('robodesk_auth_scheme');
    }

    public function defaultChannel(): string
    {
        return $this->string('robodesk_default_channel');
    }

    public function defaultLanguage(): string
    {
        return $this->string('robodesk_default_language', 'ar') ?: 'ar';
    }

    public function timeoutSeconds(): int
    {
        return max(5, $this->int('robodesk_timeout_seconds', 15));
    }

    public function signatureToleranceSeconds(): int
    {
        return max(30, $this->int('robodesk_signature_tolerance_seconds', 300));
    }

    public function signsOutbound(): bool
    {
        return $this->bool('robodesk_sign_outbound', false);
    }

    /**
     * How inbound RoboDesk calls are authenticated.
     *
     * `token` compares a static token in a header — the agreed contract.
     * `signature` is the original HMAC scheme. `none` is for local work only.
     *
     * @return 'token'|'signature'|'none'
     */
    public function inboundAuthMode(): string
    {
        $mode = $this->string('robodesk_inbound_auth_mode', 'token');

        return in_array($mode, ['token', 'signature', 'none'], true) ? $mode : 'token';
    }

    public function inboundAuthHeader(): string
    {
        return $this->string('robodesk_inbound_auth_header', 'X-RoboDesk-Token') ?: 'X-RoboDesk-Token';
    }

    /**
     * Simulation mode renders and records every outbound message without
     * sending it anywhere, so the whole journey can be walked in the admin
     * panel before RoboDesk is reachable.
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

    /**
     * Whether new web/mobile checkouts start at `pending_confirmation` instead
     * of `new`. Off by default: turning the integration off must never strand
     * orders in a state nothing can advance.
     */
    public function gatesOrderConfirmation(): bool
    {
        return $this->enabled() && $this->bool('robodesk_gate_order_confirmation', false);
    }

    /**
     * Whether a customer-generated child identity waits for approval instead of
     * being auto-approved on first success.
     */
    public function gatesIdentityConfirmation(): bool
    {
        return $this->enabled() && $this->bool('robodesk_gate_identity_confirmation', false);
    }
}
