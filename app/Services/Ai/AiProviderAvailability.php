<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Support\Ai\SupportedProviderRegistry;

class AiProviderAvailability
{
    public function __construct(
        private readonly SupportedProviderRegistry $registry,
        private readonly AiProviderCredentialService $credentials,
    ) {}

    public function providerAvailable(AiProvider $provider): bool
    {
        return (bool) config('production_studio.enabled', true)
            && $this->registry->supportsProvider($provider->driver)
            && $provider->is_active
            && $this->credentials->hasCredential($provider)
            && $provider->last_health_check_status !== 'failed'
            && $provider->models()
                ->where('is_active', true)
                ->exists();
    }

    public function modelAvailable(AiModel $model, string $capability): bool
    {
        $model->loadMissing('provider');

        return $this->providerAvailable($model->provider)
            && $model->is_active
            && $this->registry->modelSupportsCapability($model->provider->driver, $model->code, $capability)
            && $model->supportsCapability($capability);
    }

    public function activeModelsForCapability(string $capability)
    {
        return AiModel::query()
            ->with('provider.credential')
            ->where('is_active', true)
            ->whereJsonContains('generation_capabilities_json', $capability)
            ->whereHas('provider', function ($query) {
                $query->where('is_active', true);
            })
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->get()
            ->filter(fn (AiModel $model): bool => $this->modelAvailable($model, $capability))
            ->values();
    }

    public function defaultModelFor(AiProvider $provider, string $capability): ?AiModel
    {
        $code = data_get($provider->settings_json, "default_models.{$capability}");

        if (! $code) {
            return null;
        }

        $model = $provider->models()
            ->where('code', $code)
            ->first();

        return $model && $this->modelAvailable($model, $capability) ? $model : null;
    }

    public function anyProviderAvailable(): bool
    {
        return AiProvider::query()
            ->with('models')
            ->get()
            ->contains(fn (AiProvider $provider): bool => $this->providerAvailable($provider));
    }
}
