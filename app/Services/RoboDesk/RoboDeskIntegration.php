<?php

namespace App\Services\RoboDesk;

use App\Models\RoboDeskIntegrationSetting;
use Illuminate\Support\Str;

/**
 * One configured RoboDesk integration.
 *
 * There is no subclass per integration: what an integration *is* comes from
 * config/robodesk.php (name, description, available variables) and what it
 * *does* comes from three admin-saved fields — API URL, token, JSON payload.
 */
class RoboDeskIntegration
{
    public function __construct(
        public readonly string $key,
        private readonly array $definition,
        private readonly RoboDeskPayloadRenderer $renderer,
        private readonly RoboDeskSettings $settings,
    ) {}

    public function nameAr(): string
    {
        return $this->definition['name_ar'] ?? $this->key;
    }

    public function nameEn(): string
    {
        return $this->definition['name_en'] ?? $this->key;
    }

    public function descriptionAr(): string
    {
        return $this->definition['description_ar'] ?? '';
    }

    public function triggerAr(): string
    {
        return $this->definition['trigger_ar'] ?? '';
    }

    /** @return array<string,string> placeholder => human description */
    public function variables(): array
    {
        return $this->definition['variables'] ?? [];
    }

    /**
     * Renders a variable the way it is written inside a payload. Built by
     * concatenation on purpose: a literal {{ in a Blade template is compiled
     * rather than printed, and escaping it hides the interpolation too.
     */
    public function placeholder(string $name): string
    {
        return '{'.'{ '.$name.' }'.'}';
    }

    public function setting(): RoboDeskIntegrationSetting
    {
        return RoboDeskIntegrationSetting::query()->firstOrNew(['integration_key' => $this->key]);
    }

    /** Fires only when the integration is on globally AND this one is on. */
    public function enabled(): bool
    {
        return $this->settings->enabled() && (bool) $this->setting()->is_enabled;
    }

    public function apiUrl(): string
    {
        return trim((string) $this->setting()->api_url);
    }

    public function token(): string
    {
        return trim((string) $this->setting()->encrypted_token);
    }

    public function maskedToken(): ?string
    {
        $token = $this->token();

        return $token === '' ? null : '••••••••'.Str::of($token)->substr(-4)->toString();
    }

    public function payloadTemplate(): string
    {
        return (string) $this->setting()->payload_template;
    }

    public function configured(): bool
    {
        return $this->apiUrl() !== '';
    }

    /**
     * The saved template is the entire request body. With none saved, every
     * available variable is sent as-is so the integration stays testable before
     * a payload has been agreed.
     */
    public function buildPayload(array $variables): array
    {
        $template = trim($this->payloadTemplate());

        if ($template === '') {
            return $variables;
        }

        return $this->renderer->render($template, $variables);
    }
}
