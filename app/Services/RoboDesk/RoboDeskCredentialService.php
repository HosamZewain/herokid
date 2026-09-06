<?php

namespace App\Services\RoboDesk;

use App\Models\RoboDeskCredential;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Encrypted storage for the RoboDesk secrets. Values are never rendered back to
 * the admin panel; only a masked tail is shown.
 */
class RoboDeskCredentialService
{
    public function types(): array
    {
        return array_keys((array) config('robodesk.credentials', []));
    }

    public function save(string $type, string $value, ?User $user = null): RoboDeskCredential
    {
        $this->guardType($type);
        $value = trim($value);

        return RoboDeskCredential::query()->updateOrCreate(
            ['credential_type' => $type],
            [
                'encrypted_value' => $value,
                'last_four' => Str::of($value)->substr(-4)->toString(),
                'configured_at' => now(),
                'configured_by_user_id' => $user?->id,
            ],
        );
    }

    public function forget(string $type): void
    {
        $this->guardType($type);
        RoboDeskCredential::query()->where('credential_type', $type)->delete();
    }

    public function value(string $type): string
    {
        $this->guardType($type);

        $credential = RoboDeskCredential::query()->where('credential_type', $type)->first();

        if ($credential && filled($credential->encrypted_value)) {
            return (string) $credential->encrypted_value;
        }

        $legacyKey = config("robodesk.credentials.{$type}.legacy_config");

        return $legacyKey ? trim((string) config($legacyKey, '')) : '';
    }

    public function has(string $type): bool
    {
        return filled($this->value($type));
    }

    public function masked(string $type): ?string
    {
        $this->guardType($type);

        $credential = RoboDeskCredential::query()->where('credential_type', $type)->first();

        if ($credential?->last_four) {
            return '••••••••'.$credential->last_four;
        }

        $legacy = $this->value($type);

        return filled($legacy) ? 'env:••••••••'.Str::of($legacy)->substr(-4)->toString() : null;
    }

    private function guardType(string $type): void
    {
        abort_unless(in_array($type, $this->types(), true), 422, 'Unknown RoboDesk credential type.');
    }
}
