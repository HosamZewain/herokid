<?php

namespace App\Services\ChildIdentity\Sharing;

use App\Models\ChildIdentityShare;

class ChildIdentityShareFingerprint
{
    public function __construct(
        private readonly ChildIdentityShareSettings $settings,
        private readonly ChildIdentityShareText $text,
    ) {}

    public function make(ChildIdentityShare $share): string
    {
        $share->loadMissing(['identityRequest', 'generationAttempt']);
        $logoPath = public_path('images/logo-320.png');

        return hash('sha256', json_encode([
            'request' => $share->identityRequest?->uuid,
            'attempt' => $share->generation_attempt_id,
            'output_checksum' => $share->generationAttempt?->output_checksum,
            'template' => $share->template_version,
            'headline' => $this->settings->cardHeadline(),
            'cta' => $this->settings->cardCta(),
            'logo' => is_file($logoPath) ? hash_file('sha256', $logoPath) : null,
            'first_name' => $share->display_child_first_name
                ? $this->text->firstName($share->identityRequest?->child_name)
                : null,
            'qr' => $this->settings->qrEnabled(),
            'qr_url' => route('child-identity-shares.show', $share->public_token),
            'quality' => collect(ChildIdentityShare::VARIANTS)
                ->mapWithKeys(fn (string $variant): array => [$variant => $this->settings->quality($variant)])
                ->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
