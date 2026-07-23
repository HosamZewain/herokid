<?php

namespace App\Services\ChildIdentity\Sharing;

use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChildIdentityShareText
{
    public function __construct(private readonly ChildIdentityShareSettings $settings) {}

    public function caption(ChildIdentityRequest $identity, string $shareUrl, bool $showFirstName): string
    {
        $values = [
            'share_url' => $shareUrl,
            'child_first_name' => $showFirstName ? $this->firstName($identity->child_name) : '',
            'brand_name' => 'HeroKid',
        ];

        $caption = $this->render($this->settings->captionTemplate(), $values);

        return trim($caption);
    }

    public function completeCaption(ChildIdentityShare $share, string $channelUrl): string
    {
        $caption = preg_replace('/https?:\\/\\/\\S+/u', $channelUrl, $share->caption_snapshot, 1)
            ?: $share->caption_snapshot;

        if (! str_contains($caption, $channelUrl)) {
            $caption = rtrim($caption)."\n\n".$channelUrl;
        }

        return trim($caption)."\n\n".$share->hashtags_snapshot;
    }

    public function normalizeHashtags(string $hashtags): string
    {
        return collect(preg_split('/[\\s,]+/u', trim($hashtags)) ?: [])
            ->map(fn (string $tag): string => '#'.ltrim(trim($tag), '#'))
            ->filter(fn (string $tag): bool => mb_strlen($tag) > 1)
            ->unique()
            ->take(20)
            ->implode("\n");
    }

    public function validateTemplate(string $template): void
    {
        preg_match_all('/\\{([a-z_]+)\\}/', $template, $matches);
        $unsupported = collect($matches[1] ?? [])
            ->diff(ChildIdentityShareSettings::ALLOWED_PLACEHOLDERS)
            ->values();

        if ($unsupported->isNotEmpty()) {
            throw ValidationException::withMessages([
                'share_caption_ar' => 'قالب المشاركة يحتوي متغيرات غير مسموحة: '.$unsupported->implode(', '),
            ]);
        }
    }

    public function firstName(?string $name): string
    {
        return Str::of((string) $name)
            ->squish()
            ->explode(' ')
            ->filter()
            ->first() ?: '';
    }

    private function render(string $template, array $values): string
    {
        $this->validateTemplate($template);

        return str_replace(
            collect($values)->keys()->map(fn (string $key): string => '{'.$key.'}')->all(),
            collect($values)->values()->map(fn ($value): string => strip_tags((string) $value))->all(),
            $template,
        );
    }
}
