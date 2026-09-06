<?php

namespace App\Services\RoboDesk\Actions;

use App\Models\RoboDeskActionSetting;
use App\Services\RoboDesk\RoboDeskPayloadRenderer;
use App\Services\RoboDesk\RoboDeskSettings;

/**
 * One configurable RoboDesk action.
 *
 * Every RoboDesk-specific value an action needs — endpoint, HTTP method,
 * template name, channel, language, and the payload body itself — is a param
 * edited from Admin > التكاملات > RoboDesk. Nothing about the RoboDesk contract
 * is hardcoded here, so the contract can be plugged in without a deployment.
 */
abstract class RoboDeskAction
{
    public function __construct(
        protected readonly RoboDeskSettings $settings,
        protected readonly RoboDeskPayloadRenderer $renderer,
    ) {}

    abstract public function key(): string;

    abstract public function labelAr(): string;

    abstract public function labelEn(): string;

    abstract public function descriptionAr(): string;

    /** @return array<string,string> placeholder => human description */
    abstract public function variables(): array;

    /** Params unique to this action, appended after the shared connection params. */
    public function specificParams(): array
    {
        return [];
    }

    /**
     * Shared params every action exposes. `payload_template` is the important
     * one: an admin pastes the exact JSON body RoboDesk expects and references
     * this action's variables as {{placeholders}}.
     */
    final public function paramSchema(): array
    {
        return array_merge([
            [
                'key' => 'endpoint_path',
                'label_ar' => 'مسار الـ Endpoint',
                'type' => 'text',
                'default' => '',
                'help_ar' => 'يُضاف بعد الرابط الأساسي. اتركه فارغًا لاستخدام المسار العام.',
                'placeholder' => '/conversation/start/sendMsg',
            ],
            [
                'key' => 'http_method',
                'label_ar' => 'طريقة الإرسال',
                'type' => 'select',
                'options' => ['POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH'],
                'default' => 'POST',
                'help_ar' => null,
            ],
            [
                'key' => 'template_name',
                'label_ar' => 'اسم القالب في RoboDesk',
                'type' => 'text',
                'default' => '',
                'help_ar' => 'اسم قالب واتساب المعتمد.',
            ],
            [
                'key' => 'channel',
                'label_ar' => 'القناة',
                'type' => 'text',
                'default' => '',
                'help_ar' => 'اتركه فارغًا لاستخدام القناة الافتراضية.',
            ],
            [
                'key' => 'language',
                'label_ar' => 'اللغة',
                'type' => 'text',
                'default' => '',
                'help_ar' => 'اتركه فارغًا لاستخدام اللغة الافتراضية.',
            ],
            [
                'key' => 'payload_template',
                'label_ar' => 'قالب البيانات المرسلة (JSON)',
                'type' => 'json',
                'default' => '',
                'help_ar' => 'الصق هنا شكل الـ payload الذي يتوقعه RoboDesk، مستخدمًا المتغيرات المتاحة بين {{ }}. اتركه فارغًا لإرسال الحقول الافتراضية.',
            ],
        ], $this->specificParams());
    }

    public function defaults(): array
    {
        return collect($this->paramSchema())
            ->mapWithKeys(fn (array $field): array => [$field['key'] => $field['default'] ?? null])
            ->all();
    }

    public function setting(): RoboDeskActionSetting
    {
        return RoboDeskActionSetting::query()->firstOrNew(['action_key' => $this->key()]);
    }

    /**
     * Whether this action produces events at all.
     *
     * Deliberately independent of the integration toggle: an enabled action
     * still queues while the integration is off, and the outbox parks those as
     * `held` so nothing is lost mid-setup and an admin can release them later.
     */
    public function enabled(): bool
    {
        return (bool) $this->setting()->is_enabled;
    }

    /**
     * Whether this action may change customer-visible product behaviour — the
     * order and identity gates. That needs the integration itself to be on,
     * otherwise turning RoboDesk off would strand orders and identities waiting
     * on a reply nothing is left to deliver.
     */
    public function isLive(): bool
    {
        return $this->settings->enabled() && $this->enabled();
    }

    public function params(): array
    {
        return array_merge($this->defaults(), (array) ($this->setting()->params ?? []));
    }

    public function param(string $key, mixed $default = null): mixed
    {
        $value = $this->params()[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /** Effective endpoint, falling back to the connection-level events path. */
    public function endpointPath(): string
    {
        $path = trim((string) $this->param('endpoint_path', ''));

        return $path === '' ? $this->settings->eventsPath() : '/'.ltrim($path, '/');
    }

    public function channel(): string
    {
        return (string) $this->param('channel', $this->settings->defaultChannel());
    }

    public function language(): string
    {
        return (string) $this->param('language', $this->settings->defaultLanguage());
    }

    public function httpMethod(): string
    {
        return strtoupper((string) $this->param('http_method', 'POST'));
    }

    /**
     * Builds the body for this action.
     *
     * With no template saved, HeroKid sends a descriptive default: an envelope
     * naming the action, template, channel and language, plus every variable —
     * enough for RoboDesk to work against before a contract is agreed.
     *
     * Once a template is saved it becomes the *entire* body. Nothing is merged
     * around it, because the whole point of the field is to reproduce exactly
     * what RoboDesk expects. `_rendered` is an internal marker stripped before
     * the request leaves.
     */
    public function buildPayload(array $variables): array
    {
        $template = (string) $this->param('payload_template', '');

        if (trim($template) === '') {
            return array_merge([
                'action' => $this->key(),
                'template_name' => (string) $this->param('template_name', ''),
                'channel' => $this->channel(),
                'language' => $this->language(),
            ], $variables);
        }

        return array_merge(
            ['_rendered' => true],
            $this->renderer->render($template, $variables),
        );
    }
}
