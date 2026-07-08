<?php

namespace App\Services\Ai;

use App\Models\AiProvider;
use App\Models\AiProviderCredential;
use App\Models\User;
use Illuminate\Support\Str;

class AiProviderCredentialService
{
    public function save(AiProvider $provider, string $secret, ?User $user = null, string $type = 'api_key'): AiProviderCredential
    {
        $secret = trim($secret);

        $credential = AiProviderCredential::query()->updateOrCreate(
            ['ai_provider_id' => $provider->id, 'credential_type' => $type],
            [
                'encrypted_value' => $secret,
                'last_four' => Str::of($secret)->substr(-4)->toString(),
                'configured_at' => now(),
                'configured_by_user_id' => $user?->id,
                'last_test_status' => null,
                'last_test_message' => null,
            ]
        );

        $provider->forceFill([
            'is_configured' => true,
            'is_available' => $provider->is_active,
            'last_health_check_status' => null,
            'last_health_check_message' => null,
            'last_health_check_at' => null,
        ])->save();

        return $credential;
    }

    public function remove(AiProvider $provider, string $type = 'api_key'): void
    {
        AiProviderCredential::query()
            ->where('ai_provider_id', $provider->id)
            ->where('credential_type', $type)
            ->delete();

        $provider->forceFill([
            'is_configured' => false,
            'is_available' => false,
            'is_active' => false,
        ])->save();
    }

    public function credential(AiProvider $provider, string $type = 'api_key'): ?AiProviderCredential
    {
        return $provider->credential()
            ->where('credential_type', $type)
            ->first();
    }

    public function secret(AiProvider $provider, string $type = 'api_key'): ?string
    {
        $credential = $this->credential($provider, $type);

        if ($credential) {
            return $credential->encrypted_value;
        }

        if ($provider->driver === 'fal' && filled(config('production_studio.ai.fal.key'))) {
            return (string) config('production_studio.ai.fal.key');
        }

        return null;
    }

    public function hasCredential(AiProvider $provider, string $type = 'api_key'): bool
    {
        return filled($this->secret($provider, $type));
    }

    public function masked(AiProvider $provider): ?string
    {
        $credential = $this->credential($provider);

        return $credential?->last_four ? '••••••••'.$credential->last_four : null;
    }
}
