<?php

namespace App\Services\RoboDesk;

class RoboDeskSignature
{
    public function sign(string $body, string $timestamp, string $eventId, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$eventId.'.'.hash('sha256', $body), $secret);
    }

    public function valid(string $body, ?string $timestamp, ?string $eventId, ?string $signature, string $secret): bool
    {
        if ($secret === '' || ! ctype_digit((string) $timestamp) || blank($eventId) || blank($signature)) {
            return false;
        }

        if (abs(now()->timestamp - (int) $timestamp) > max(30, (int) config('robodesk.signature_tolerance_seconds', 300))) {
            return false;
        }

        return hash_equals($this->sign($body, (string) $timestamp, (string) $eventId, $secret), (string) $signature);
    }
}
