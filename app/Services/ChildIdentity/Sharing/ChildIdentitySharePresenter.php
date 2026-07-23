<?php

namespace App\Services\ChildIdentity\Sharing;

use App\Models\ChildIdentityShare;

class ChildIdentitySharePresenter
{
    public function __construct(
        private readonly ChildIdentityShareSettings $settings,
        private readonly ChildIdentityShareText $text,
    ) {}

    public function publicUrl(ChildIdentityShare $share, ?string $source = null): string
    {
        $url = route('child-identity-shares.show', $share->public_token);

        if (! $source) {
            return $url;
        }

        return $url.'?'.http_build_query([
            'utm_source' => $source,
            'utm_medium' => 'identity_share',
            'utm_campaign' => 'free_child_identity',
        ]);
    }

    public function cardUrl(ChildIdentityShare $share, string $variant): string
    {
        return route('child-identity-shares.card', [
            'share' => $share->public_token,
            'variant' => $variant,
            'v' => $share->generation_version,
        ]);
    }

    public function customerPayload(ChildIdentityShare $share): array
    {
        $neutral = $this->publicUrl($share);
        $whatsAppUrl = $this->publicUrl($share, 'whatsapp');
        $facebookUrl = $this->publicUrl($share, 'facebook');
        $copyUrl = $this->publicUrl($share, 'copy_link');

        return [
            'publicUrl' => $neutral,
            'copyUrl' => $copyUrl,
            'caption' => $this->text->completeCaption($share, $neutral),
            'whatsapp' => 'https://wa.me/?text='.rawurlencode($this->text->completeCaption($share, $whatsAppUrl)),
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?u='.rawurlencode($facebookUrl),
            'cards' => [
                'feed' => $this->cardUrl($share, 'feed'),
                'story' => $this->cardUrl($share, 'story'),
                'og' => $this->cardUrl($share, 'og'),
            ],
            'eventUrl' => route('child-identity.shares.events', [
                'identity' => $share->identityRequest->uuid,
                'share' => $share->id,
            ]),
            'anonymousShareId' => substr(hash_hmac('sha256', $share->public_token, (string) config('app.key')), 0, 16),
            'channels' => $this->settings->channels(),
        ];
    }

    public function safePublicPayload(ChildIdentityShare $share): array
    {
        return [
            'url' => $this->publicUrl($share),
            'og_image' => $this->cardUrl($share, 'og'),
            'feed_image' => $this->cardUrl($share, 'feed'),
            'story_image' => $this->cardUrl($share, 'story'),
            'title' => $this->settings->landingTitle(),
            'description' => $this->settings->landingDescription(),
            'cta' => $this->settings->landingCta(),
            'anonymous_share_id' => substr(hash_hmac('sha256', $share->public_token, (string) config('app.key')), 0, 16),
        ];
    }
}
