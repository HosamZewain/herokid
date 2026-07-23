<?php

namespace App\Services\ChildIdentity\Sharing;

use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
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

        return $this->makeFingerprint(
            $share->identityRequest,
            $share->generationAttempt,
            $share->template_version,
            $share->display_child_first_name,
        );
    }

    public function makeForAttempt(
        ChildIdentityGenerationAttempt $attempt,
        bool $displayFirstName = false,
    ): string {
        $attempt->loadMissing('identityRequest');

        return $this->makeFingerprint(
            $attempt->identityRequest,
            $attempt,
            $this->settings->templateVersion(),
            $displayFirstName,
        );
    }

    private function makeFingerprint(
        ?ChildIdentityRequest $identity,
        ?ChildIdentityGenerationAttempt $attempt,
        string $templateVersion,
        bool $displayFirstName,
    ): string {
        $logoPath = public_path('images/logo-320.png');
        $globePath = public_path('images/icons/globe-alt-indigo.svg');

        return hash('sha256', json_encode([
            'request' => $identity?->uuid,
            'attempt' => $attempt?->id,
            'output_checksum' => $attempt?->output_checksum,
            'template' => $templateVersion,
            'layout' => 'reference-template-v4-visible-identity',
            'headline' => $this->settings->cardHeadline(),
            'cta' => $this->settings->cardCta(),
            'footer' => $this->settings->cardFooter(),
            'logo' => is_file($logoPath) ? hash_file('sha256', $logoPath) : null,
            'globe' => is_file($globePath) ? hash_file('sha256', $globePath) : null,
            'first_name' => $displayFirstName
                ? $this->text->firstName($identity?->child_name)
                : null,
            'quality' => collect(ChildIdentityShare::VARIANTS)
                ->mapWithKeys(fn (string $variant): array => [$variant => $this->settings->quality($variant)])
                ->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
