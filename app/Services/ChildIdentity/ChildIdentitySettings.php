<?php

namespace App\Services\ChildIdentity;

use App\Models\AiProvider;

class ChildIdentitySettings
{
    public const DEFAULT_PROMPT = <<<'PROMPT'
Create one professional landscape character sheet for a personalized children's book using the supplied child reference photos. Preserve the child's recognizable facial features, skin tone, hair, and age-appropriate proportions. Show one consistent character in front, three-quarter, and profile views plus a small set of natural expression references. Use a clean, simple neutral background. Do not include text, labels, watermark, logo, border, story scene, or unrelated people. The result must be warm, child-friendly, production-ready, and visually consistent across all views.
PROMPT;

    public function enabled(): bool
    {
        return setting('child_identity_enabled', '1') === '1';
    }

    public function size(): string
    {
        return (string) setting('child_identity_image_size', '1536x1024');
    }

    public function quality(): string
    {
        return (string) setting('child_identity_image_quality', 'medium');
    }

    public function promptTemplate(): string
    {
        return (string) setting('child_identity_prompt_template', self::DEFAULT_PROMPT);
    }

    public function promptVersion(): string
    {
        return (string) setting('child_identity_prompt_version', 'character-sheet-v1');
    }

    public function customerSuccessfulLimit(): int
    {
        return 2;
    }

    public function providerAndModel(): array
    {
        $provider = AiProvider::query()->where('driver', 'openai')->first();
        $configuredCode = data_get($provider?->settings_json, 'default_models.character_sheet');
        $model = $provider?->models()
            ->where('is_active', true)
            ->where('code', $configuredCode ?: 'gpt-image-2')
            ->first()
            ?? $provider?->models()->where('is_active', true)->where('code', 'gpt-image-2')->first();

        return [$provider, $model];
    }
}
