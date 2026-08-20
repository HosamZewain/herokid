<?php

namespace App\Services\Mobile;

use App\Contracts\MobileSocialIdentityVerifier;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProviderTokenVerifier implements MobileSocialIdentityVerifier
{
    public function verify(string $provider, string $identityToken): array
    {
        try {
            return match ($provider) {
                'google' => $this->verifyGoogle($identityToken),
                'apple' => $this->verifyApple($identityToken),
                default => throw ValidationException::withMessages(['provider' => 'Unsupported sign-in provider.']),
            };
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['id_token' => 'The provider identity token is invalid or expired.']);
        }
    }

    private function verifyGoogle(string $identityToken): array
    {
        $allowedAudiences = config('services.mobile_oauth.google_client_ids', []);
        if ($allowedAudiences === []) {
            throw ValidationException::withMessages(['provider' => 'Google sign-in is not configured.']);
        }

        $claims = Http::acceptJson()
            ->timeout(8)
            ->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $identityToken])
            ->throw()
            ->json();

        $issuer = (string) ($claims['iss'] ?? '');
        $audience = (string) ($claims['aud'] ?? '');
        $expiresAt = (int) ($claims['exp'] ?? 0);
        $subject = (string) ($claims['sub'] ?? '');
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        if (! in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)
            || ! in_array($audience, $allowedAudiences, true)
            || $expiresAt <= now()->timestamp
            || $subject === '') {
            throw ValidationException::withMessages(['id_token' => 'The Google identity token could not be verified.']);
        }

        return [
            'subject' => $subject,
            'email' => $emailVerified ? $this->email($claims['email'] ?? null) : null,
            'email_verified' => $emailVerified,
            'name' => $this->nullableString($claims['name'] ?? null),
        ];
    }

    private function verifyApple(string $identityToken): array
    {
        $allowedAudiences = config('services.mobile_oauth.apple_client_ids', []);
        if ($allowedAudiences === []) {
            throw ValidationException::withMessages(['provider' => 'Apple sign-in is not configured.']);
        }

        $jwks = Cache::remember('mobile_oauth.apple_jwks', now()->addHours(6), function (): array {
            return Http::acceptJson()->timeout(8)->get('https://appleid.apple.com/auth/keys')->throw()->json();
        });
        $claims = (array) JWT::decode($identityToken, JWK::parseKeySet($jwks, 'RS256'));
        $audiences = array_map('strval', (array) ($claims['aud'] ?? []));
        $subject = (string) ($claims['sub'] ?? '');

        if (($claims['iss'] ?? null) !== 'https://appleid.apple.com'
            || array_intersect($audiences, $allowedAudiences) === []
            || (int) ($claims['exp'] ?? 0) <= now()->timestamp
            || $subject === '') {
            throw ValidationException::withMessages(['id_token' => 'The Apple identity token could not be verified.']);
        }

        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        return [
            'subject' => $subject,
            'email' => $emailVerified ? $this->email($claims['email'] ?? null) : null,
            'email_verified' => $emailVerified,
            'name' => null,
        ];
    }

    private function email(mixed $value): ?string
    {
        $email = mb_strtolower(trim((string) $value));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }
}
