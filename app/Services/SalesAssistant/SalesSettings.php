<?php

namespace App\Services\SalesAssistant;

use App\Models\AiProvider;
use App\Models\Setting;
use App\Services\Ai\AiProviderCredentialService;
use Illuminate\Support\Facades\Cache;

class SalesSettings
{
    // Read critical switches directly: long-lived workers must observe a disable immediately.
    public function value(string $key, string $default = ''): string
    {
        return (string) (Setting::where('key', 'sales_assistant_'.$key)->value('value') ?? $default);
    }

    public function enabled(): bool
    {
        return $this->value('enabled', config('sales_assistant.enabled') ? '1' : '0') === '1';
    }

    public function budget(): int
    {
        return max(0, min(100000000, (int) $this->value('daily_budget_micros', (string) config('sales_assistant.daily_budget_micros', 5000000))));
    }

    public function credential(): ?string
    {
        $provider = AiProvider::where('driver', 'openai')->where('is_active', true)->first();

        return $provider ? app(AiProviderCredentialService::class)->secret($provider) : null;
    }

    public function workerReady(): bool
    {
        return (int) Cache::get('sales-assistant:worker-heartbeat', 0) > now()->subSeconds(90)->timestamp;
    }

    public function verified(): bool
    {
        $key = $this->credential();
        $receipt = json_decode($this->value('model_verified'), true);

        return $key && is_array($receipt) && ($receipt['model'] ?? '') === 'gpt-6-luna'
            && hash_equals(hash('sha256', $key), $receipt['credential_hash'] ?? '')
            && ($receipt['at'] ?? 0) > now()->subDays(7)->timestamp;
    }
}
