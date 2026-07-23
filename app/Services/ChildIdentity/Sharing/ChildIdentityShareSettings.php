<?php

namespace App\Services\ChildIdentity\Sharing;

class ChildIdentityShareSettings
{
    public const ALLOWED_PLACEHOLDERS = ['share_url', 'child_first_name', 'brand_name'];

    public const DEFAULT_CAPTION = <<<'TEXT'
شوفوا هوية طفلي اللي عملناها على HeroKid ✨💜

تقدروا تعملوا هوية لطفلكم مجانًا خلال دقائق، وبعدها تختاروا القصة اللي يكون بطلها 📖🌟

{share_url}
TEXT;

    public const DEFAULT_HASHTAGS = <<<'TEXT'
#HeroKid
#طفلك_بطل_القصة
#هوية_طفلك
#قصص_أطفال
#قصص_مخصصة
TEXT;

    public function enabled(): bool
    {
        return setting('child_identity_sharing_enabled', '1') === '1';
    }

    public function channelEnabled(string $channel): bool
    {
        $defaults = [
            'native' => '1',
            'whatsapp' => '1',
            'facebook' => '1',
            'instagram' => '1',
            'copy_link' => '1',
            'copy_caption' => '1',
            'download' => '1',
        ];

        return isset($defaults[$channel])
            && setting("child_identity_share_channel_{$channel}", $defaults[$channel]) === '1';
    }

    public function channels(): array
    {
        return collect(['native', 'whatsapp', 'facebook', 'instagram', 'copy_link', 'copy_caption', 'download'])
            ->mapWithKeys(fn (string $channel): array => [$channel => $this->channelEnabled($channel)])
            ->all();
    }

    public function captionTemplate(): string
    {
        return (string) setting('child_identity_share_caption_ar', self::DEFAULT_CAPTION);
    }

    public function englishCaptionTemplate(): string
    {
        return (string) setting('child_identity_share_caption_en', '');
    }

    public function hashtags(): string
    {
        return (string) setting('child_identity_share_hashtags', self::DEFAULT_HASHTAGS);
    }

    public function cardHeadline(): string
    {
        return (string) setting('child_identity_share_card_headline', 'شوفوا هوية طفلي من HeroKid ✨');
    }

    public function cardCta(): string
    {
        return (string) setting('child_identity_share_card_cta', 'اصنع هوية طفلك مجانًا');
    }

    public function landingTitle(): string
    {
        return (string) setting('child_identity_share_landing_title', 'شوفوا هوية طفلي من HeroKid ✨');
    }

    public function landingDescription(): string
    {
        return (string) setting('child_identity_share_landing_description', 'اصنع هوية طفلك مجانًا، وشوفه بطلًا في قصة مخصصة له.');
    }

    public function landingCta(): string
    {
        return (string) setting('child_identity_share_landing_cta', 'اصنع هوية طفلك مجانًا');
    }

    public function attributionDays(): int
    {
        return max(1, min(365, (int) setting('child_identity_share_attribution_days', '30')));
    }

    public function allowFirstName(): bool
    {
        return setting('child_identity_share_allow_first_name', '1') === '1';
    }

    public function quality(string $variant): int
    {
        $default = $variant === 'story' ? 88 : 90;

        return max(70, min(96, (int) setting("child_identity_share_{$variant}_quality", (string) $default)));
    }

    public function templateVersion(): string
    {
        return (string) setting('child_identity_share_template_version', 'identity-share-v1');
    }
}
