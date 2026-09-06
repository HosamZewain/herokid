<?php

namespace App\Services\RoboDesk;

class RoboDeskSignature
{
    public function __construct(private readonly RoboDeskSettings $settings) {}

    public function sign(string $body, string $timestamp, string $eventId, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$eventId.'.'.hash('sha256', $body), $secret);
    }

    public function valid(string $body, ?string $timestamp, ?string $eventId, ?string $signature, string $secret): bool
    {
        if ($secret === '' || ! ctype_digit((string) $timestamp) || blank($eventId) || blank($signature)) {
            return false;
        }

        if (abs(now()->timestamp - (int) $timestamp) > $this->settings->signatureToleranceSeconds()) {
            return false;
        }

        return hash_equals($this->sign($body, (string) $timestamp, (string) $eventId, $secret), (string) $signature);
    }
}
