<?php

namespace App\Contracts;

interface MobileSocialIdentityVerifier
{
    /** @return array{subject: string, email: ?string, email_verified: bool, name: ?string} */
    public function verify(string $provider, string $identityToken): array;
}
