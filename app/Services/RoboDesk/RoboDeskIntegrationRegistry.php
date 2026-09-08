<?php

namespace App\Services\RoboDesk;

use App\Models\RoboDeskIntegrationSetting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The catalogue of RoboDesk integrations, built from config/robodesk.php.
 *
 * Adding the next one — identity confirmation, item confirmation, CSAT — is a
 * new config entry plus the trigger that calls it. No new class, no new screen.
 */
class RoboDeskIntegrationRegistry
{
    public const ORDER_CONFIRMATION = 'order.confirm';

    public function __construct(
        private readonly RoboDeskPayloadRenderer $renderer,
        private readonly RoboDeskSettings $settings,
    ) {}

    /** @return Collection<string, RoboDeskIntegration> */
    public function all(): Collection
    {
        return collect((array) config('robodesk.integrations', []))
            ->map(fn (array $definition, string $key): RoboDeskIntegration => new RoboDeskIntegration(
                $key,
                $definition,
                $this->renderer,
                $this->settings,
            ));
    }

    public function keys(): array
    {
        return $this->all()->keys()->all();
    }

    public function find(string $key): ?RoboDeskIntegration
    {
        return $this->all()->get($key);
    }

    public function get(string $key): RoboDeskIntegration
    {
        $integration = $this->find($key);

        abort_if($integration === null, 404, 'Unknown RoboDesk integration.');

        return $integration;
    }

    public function orderConfirmation(): RoboDeskIntegration
    {
        return $this->get(self::ORDER_CONFIRMATION);
    }

    /**
     * A blank token means "leave the saved one alone" — the form never renders
     * the real value back, so an empty field must not wipe it.
     */
    public function save(
        string $key,
        bool $isEnabled,
        string $apiUrl,
        ?string $token,
        string $payloadTemplate,
        ?User $user = null,
    ): RoboDeskIntegrationSetting {
        $integration = $this->get($key);
        $setting = $integration->setting();

        $setting->fill([
            'integration_key' => $key,
            'is_enabled' => $isEnabled,
            'api_url' => trim($apiUrl),
            'payload_template' => trim($payloadTemplate),
            'updated_by_user_id' => $user?->id,
        ]);

        if ($token !== null && trim($token) !== '') {
            $setting->encrypted_token = trim($token);
        }

        $setting->save();

        return $setting;
    }
}
