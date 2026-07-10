<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Ga4AnalyticsClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    public function isConfigured(): bool
    {
        return $this->propertyId() !== null && $this->credentials() !== null;
    }

    public function propertyId(): ?string
    {
        $propertyId = trim((string) config('analytics.ga4.property_id', ''));

        return $propertyId === '' ? null : $propertyId;
    }

    public function runReport(array $payload): array
    {
        return $this->post('runReport', $payload);
    }

    public function runRealtimeReport(array $payload): array
    {
        return $this->post('runRealtimeReport', $payload);
    }

    private function post(string $method, array $payload): array
    {
        $propertyId = $this->propertyId();
        if ($propertyId === null) {
            throw new Ga4ApiException('GA4 property is not configured.');
        }

        $baseUrl = rtrim((string) config('analytics.ga4.api_base_url'), '/');
        $url = $baseUrl.'/properties/'.$propertyId.':'.$method;

        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->timeout((int) config('analytics.ga4.request_timeout', 10))
                ->post($url, $payload);
        } catch (\Throwable $exception) {
            Log::warning('GA4 API request failed before response.', [
                'method' => $method,
                'property_id' => $propertyId,
                'api_base_url' => $baseUrl,
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);

            throw new Ga4ApiException('Google Analytics request failed.');
        }

        if (! $response->successful()) {
            Log::warning('GA4 API returned an error.', [
                'method' => $method,
                'property_id' => $propertyId,
                'api_base_url' => $baseUrl,
                'url' => $url,
                'status' => $response->status(),
                'error' => $response->json('error.message') ?: $response->reason(),
            ]);

            throw new Ga4ApiException('Google Analytics API returned an error.');
        }

        return $response->json() ?? [];
    }

    private function accessToken(): string
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            throw new Ga4ApiException('Google Analytics credentials are not configured.');
        }

        $cacheKey = 'ga4:access-token:'.sha1(($credentials['client_email'] ?? '').':'.$this->propertyId());

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($credentials): string {
            $jwt = $this->jwtAssertion($credentials);
            $tokenUrl = (string) config('analytics.ga4.token_url');

            $response = Http::asForm()
                ->timeout((int) config('analytics.ga4.request_timeout', 10))
                ->post($tokenUrl, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                Log::warning('GA4 OAuth token request failed.', [
                    'status' => $response->status(),
                    'error' => $response->json('error_description') ?: $response->json('error') ?: $response->reason(),
                ]);

                throw new Ga4ApiException('Google Analytics authentication failed.');
            }

            return (string) $response->json('access_token');
        });
    }

    private function jwtAssertion(array $credentials): string
    {
        $email = (string) ($credentials['client_email'] ?? '');
        $privateKey = (string) ($credentials['private_key'] ?? '');

        if ($email === '' || $privateKey === '') {
            throw new Ga4ApiException('Google Analytics service account credentials are incomplete.');
        }

        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $email,
            'scope' => self::SCOPE,
            'aud' => (string) config('analytics.ga4.token_url'),
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $signature = '';
        if (! openssl_sign($header.'.'.$claims, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new Ga4ApiException('Google Analytics credential signing failed.');
        }

        return $header.'.'.$claims.'.'.$this->base64UrlEncode($signature);
    }

    private function credentials(): ?array
    {
        $base64 = trim((string) config('analytics.ga4.credentials_base64', ''));
        if ($base64 !== '') {
            return $this->decodeCredentials(base64_decode($base64, true) ?: '');
        }

        $path = trim((string) config('analytics.ga4.credentials_path', ''));
        if ($path === '') {
            return null;
        }

        $realPath = realpath($path);
        if ($realPath === false || ! is_file($realPath)) {
            return null;
        }

        $publicPath = realpath(public_path());
        if ($publicPath !== false && str_starts_with($realPath, $publicPath)) {
            Log::warning('GA4 credentials path points inside public directory and was rejected.');

            return null;
        }

        return $this->decodeCredentials((string) file_get_contents($realPath));
    }

    private function decodeCredentials(string $json): ?array
    {
        if ($json === '') {
            return null;
        }

        try {
            $credentials = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($credentials) ? $credentials : null;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
